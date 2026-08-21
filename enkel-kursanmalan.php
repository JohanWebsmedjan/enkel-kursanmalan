<?php
/**
 * Plugin Name: Enkel kursanmälan med Swish
 * Description: Kurser, kurstillfällen och anmälningar med procentuell Swish-handpenning.
 * Version: 1.1.0
 * Author: Websmedjan
 * Author URI: https://github.com/JohanWebsmedjan
 * Plugin URI: https://github.com/JohanWebsmedjan/enkel-kursanmalan
 * Update URI: https://github.com/JohanWebsmedjan/enkel-kursanmalan
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: enkel-kursanmalan
 */

if (!defined('ABSPATH')) exit;

final class EKA_Plugin {
    const VERSION = '1.1.0';
    const TABLE = 'eka_registrations';

    public static function init() {
        add_action('init', [__CLASS__, 'register_course_type']);
        add_action('add_meta_boxes', [__CLASS__, 'add_meta_boxes']);
        add_action('save_post_eka_course', [__CLASS__, 'save_course']);
        add_action('admin_menu', [__CLASS__, 'admin_menu']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_action('admin_post_nopriv_eka_register', [__CLASS__, 'handle_registration']);
        add_action('admin_post_eka_register', [__CLASS__, 'handle_registration']);
        add_action('admin_post_eka_payment_status', [__CLASS__, 'handle_payment_status']);
        add_shortcode('enkel_kurslista', [__CLASS__, 'course_list_shortcode']);
        add_shortcode('enkel_kursdatumlista', [__CLASS__, 'course_date_list_shortcode']);
        add_filter('the_content', [__CLASS__, 'append_course_form']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'assets']);
    }

    public static function activate() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = $wpdb->prefix . self::TABLE;
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            course_id bigint(20) unsigned NOT NULL,
            session_key varchar(191) NOT NULL,
            name varchar(191) NOT NULL,
            email varchar(191) NOT NULL,
            phone varchar(50) NOT NULL,
            participants smallint unsigned NOT NULL DEFAULT 1,
            total_amount decimal(10,2) NOT NULL DEFAULT 0,
            deposit_amount decimal(10,2) NOT NULL DEFAULT 0,
            payment_reference varchar(50) NOT NULL,
            payment_status varchar(20) NOT NULL DEFAULT 'pending',
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY course_id (course_id),
            UNIQUE KEY payment_reference (payment_reference)
        ) $charset;");
        self::register_course_type();
        flush_rewrite_rules();
    }

    public static function deactivate() { flush_rewrite_rules(); }

    public static function register_course_type() {
        register_post_type('eka_course', [
            'labels' => ['name' => 'Kurser', 'singular_name' => 'Kurs', 'add_new_item' => 'Lägg till kurs', 'edit_item' => 'Redigera kurs'],
            'public' => true, 'show_in_rest' => true, 'menu_icon' => 'dashicons-welcome-learn-more',
            'supports' => ['title', 'editor', 'thumbnail'], 'has_archive' => true,
            'rewrite' => ['slug' => 'kurser'],
        ]);
    }

    public static function add_meta_boxes() {
        add_meta_box('eka_details', 'Kursinformation', [__CLASS__, 'course_meta_box'], 'eka_course', 'normal', 'high');
    }

    public static function course_meta_box($post) {
        wp_nonce_field('eka_save_course', 'eka_course_nonce');
        $price = get_post_meta($post->ID, '_eka_price', true);
        $sessions = self::sessions($post->ID);
        ?>
        <p><label><strong>Kostnad per deltagare (kr)</strong><br><input type="number" min="0" step="0.01" name="eka_price" value="<?php echo esc_attr($price); ?>" style="width:220px"></label></p>
        <p><strong>Kurstillfällen</strong></p>
        <table class="widefat" id="eka-session-table"><thead><tr><th>Startdatum</th><th>Starttid</th><th>Sluttid</th><th>Max deltagare</th><th></th></tr></thead><tbody>
        <?php foreach ($sessions as $i => $s): self::session_admin_row($i, $s); endforeach; ?>
        </tbody></table>
        <p><button type="button" class="button" id="eka-add-session">+ Lägg till kurstillfälle</button></p>
        <script type="text/template" id="eka-session-template"><?php self::session_admin_row('__INDEX__', ['date'=>'','start'=>'','end'=>'','max'=>12]); ?></script>
        <?php
    }

    private static function session_admin_row($i, $s) { ?>
        <tr><td><input type="date" name="eka_sessions[<?php echo esc_attr($i); ?>][date]" value="<?php echo esc_attr($s['date']); ?>" required></td>
        <td><input type="time" name="eka_sessions[<?php echo esc_attr($i); ?>][start]" value="<?php echo esc_attr($s['start']); ?>" required></td>
        <td><input type="time" name="eka_sessions[<?php echo esc_attr($i); ?>][end]" value="<?php echo esc_attr($s['end']); ?>"></td>
        <td><input type="number" min="1" name="eka_sessions[<?php echo esc_attr($i); ?>][max]" value="<?php echo esc_attr($s['max']); ?>" required style="width:100px"></td>
        <td><button type="button" class="button-link-delete eka-remove-session">Ta bort</button></td></tr>
    <?php }

    public static function save_course($post_id) {
        if (!isset($_POST['eka_course_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['eka_course_nonce'])), 'eka_save_course')) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;
        update_post_meta($post_id, '_eka_price', max(0, (float) ($_POST['eka_price'] ?? 0)));
        $sessions = [];
        foreach ((array) ($_POST['eka_sessions'] ?? []) as $row) {
            $date = sanitize_text_field(wp_unslash($row['date'] ?? ''));
            $start = sanitize_text_field(wp_unslash($row['start'] ?? ''));
            $end = sanitize_text_field(wp_unslash($row['end'] ?? ''));
            $max = max(1, absint($row['max'] ?? 1));
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && preg_match('/^\d{2}:\d{2}$/', $start)) $sessions[] = compact('date', 'start', 'end', 'max');
        }
        update_post_meta($post_id, '_eka_sessions', wp_json_encode($sessions));
    }

    public static function register_settings() {
        register_setting('eka_settings', 'eka_swish_number', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('eka_settings', 'eka_deposit_percent', ['sanitize_callback' => function($v) { return min(100, max(0, (float) $v)); }]);
        register_setting('eka_settings', 'eka_admin_email', ['sanitize_callback' => 'sanitize_email']);
    }

    public static function admin_menu() {
        add_submenu_page('edit.php?post_type=eka_course', 'Anmälningar', 'Anmälningar', 'manage_options', 'eka-registrations', [__CLASS__, 'registrations_page']);
        add_submenu_page('edit.php?post_type=eka_course', 'Inställningar', 'Inställningar', 'manage_options', 'eka-settings', [__CLASS__, 'settings_page']);
    }

    public static function settings_page() { ?>
        <div class="wrap"><h1>Inställningar för kursanmälan</h1><form method="post" action="options.php">
        <?php settings_fields('eka_settings'); ?>
        <table class="form-table">
        <tr><th><label for="eka_swish_number">Swish-nummer</label></th><td><input class="regular-text" id="eka_swish_number" name="eka_swish_number" value="<?php echo esc_attr(get_option('eka_swish_number')); ?>" required></td></tr>
        <tr><th><label for="eka_deposit_percent">Handpenning (%)</label></th><td><input type="number" min="0" max="100" step="0.1" id="eka_deposit_percent" name="eka_deposit_percent" value="<?php echo esc_attr(get_option('eka_deposit_percent', 20)); ?>"></td></tr>
        <tr><th><label for="eka_admin_email">E-post för avisering</label></th><td><input type="email" class="regular-text" id="eka_admin_email" name="eka_admin_email" value="<?php echo esc_attr(get_option('eka_admin_email', get_option('admin_email'))); ?>"></td></tr>
        </table><?php submit_button(); ?></form>
        <p><strong>Shortcodes:</strong><br><code>[enkel_kurslista]</code> visar kurskort.<br><code>[enkel_kursdatumlista]</code> visar en kompakt lista med kalenderikon, startdatum och starttid.</p></div>
    <?php }

    public static function registrations_page() {
        global $wpdb;
        $rows = $wpdb->get_results("SELECT r.*, p.post_title FROM {$wpdb->prefix}" . self::TABLE . " r LEFT JOIN {$wpdb->posts} p ON p.ID=r.course_id ORDER BY r.created_at DESC LIMIT 500");
        ?><div class="wrap"><h1>Anmälningar</h1><table class="widefat striped"><thead><tr><th>Datum</th><th>Kurs / tillfälle</th><th>Deltagare</th><th>Kontakt</th><th>Belopp</th><th>Swish-referens</th><th>Status</th></tr></thead><tbody>
        <?php if (!$rows): ?><tr><td colspan="7">Inga anmälningar ännu.</td></tr><?php endif; ?>
        <?php foreach ($rows as $r): ?><tr>
        <td><?php echo esc_html($r->created_at); ?></td><td><strong><?php echo esc_html($r->post_title); ?></strong><br><?php echo esc_html($r->session_key); ?></td>
        <td><?php echo esc_html($r->name); ?> (<?php echo (int) $r->participants; ?>)</td><td><a href="mailto:<?php echo esc_attr($r->email); ?>"><?php echo esc_html($r->email); ?></a><br><?php echo esc_html($r->phone); ?></td>
        <td><?php echo esc_html(number_format_i18n($r->deposit_amount, 2)); ?> kr av <?php echo esc_html(number_format_i18n($r->total_amount, 2)); ?> kr</td><td><code><?php echo esc_html($r->payment_reference); ?></code></td>
        <td><?php echo $r->payment_status === 'paid' ? '<strong style="color:#087b37">Betald</strong>' : '<strong>Väntar</strong>'; ?><br>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="eka_payment_status"><input type="hidden" name="id" value="<?php echo (int) $r->id; ?>"><input type="hidden" name="status" value="<?php echo $r->payment_status === 'paid' ? 'pending' : 'paid'; ?>"><?php wp_nonce_field('eka_status_' . $r->id); ?><button class="button button-small"><?php echo $r->payment_status === 'paid' ? 'Ångra' : 'Markera betald'; ?></button></form></td>
        </tr><?php endforeach; ?></tbody></table></div><?php
    }

    public static function handle_payment_status() {
        if (!current_user_can('manage_options')) wp_die('Behörighet saknas.');
        $id = absint($_POST['id'] ?? 0);
        check_admin_referer('eka_status_' . $id);
        $status = ($_POST['status'] ?? '') === 'paid' ? 'paid' : 'pending';
        global $wpdb;
        $wpdb->update($wpdb->prefix . self::TABLE, ['payment_status' => $status], ['id' => $id], ['%s'], ['%d']);
        wp_safe_redirect(admin_url('edit.php?post_type=eka_course&page=eka-registrations'));
        exit;
    }

    public static function assets() {
        wp_register_style('eka-style', plugins_url('assets/eka.css', __FILE__), [], self::VERSION);
        wp_enqueue_style('eka-style');
    }

    public static function admin_assets($hook) {
        if (($hook === 'post.php' || $hook === 'post-new.php') && get_current_screen() && get_current_screen()->post_type === 'eka_course') {
            wp_enqueue_script('eka-admin', plugins_url('assets/eka-admin.js', __FILE__), [], self::VERSION, true);
        }
    }

    private static function sessions($course_id) {
        $raw = (string) get_post_meta($course_id, '_eka_sessions', true);
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) return array_values(array_filter($decoded, function($s) { return !empty($s['date']) && !empty($s['start']); }));
        $legacy = array_values(array_filter(array_map('trim', preg_split('/\R/', $raw))));
        $sessions = [];
        foreach ($legacy as $line) {
            if (preg_match('/^(\d{4}-\d{2}-\d{2})\s+(\d{2}:\d{2})(?:[^0-9]+(\d{2}:\d{2}))?/', $line, $m)) $sessions[] = ['date'=>$m[1], 'start'=>$m[2], 'end'=>$m[3] ?? '', 'max'=>12];
        }
        return $sessions;
    }

    private static function session_key($s) { return $s['date'] . '|' . $s['start'] . '|' . ($s['end'] ?? ''); }
    private static function session_label($s) {
        $date = wp_date(get_option('date_format'), strtotime($s['date']));
        return $date . ' kl. ' . $s['start'] . (!empty($s['end']) ? '–' . $s['end'] : '');
    }
    private static function booked_count($course_id, $key) {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(participants),0) FROM {$wpdb->prefix}" . self::TABLE . " WHERE course_id=%d AND session_key=%s", $course_id, $key));
    }

    public static function course_list_shortcode() {
        $q = new WP_Query(['post_type' => 'eka_course', 'posts_per_page' => -1, 'post_status' => 'publish']);
        ob_start(); ?><div class="eka-grid"><?php while ($q->have_posts()): $q->the_post(); $price = (float) get_post_meta(get_the_ID(), '_eka_price', true); ?>
        <article class="eka-card"><?php if (has_post_thumbnail()) the_post_thumbnail('medium_large'); ?><div class="eka-card-body"><h2><?php the_title(); ?></h2><div><?php echo wp_kses_post(wp_trim_words(get_the_excerpt() ?: get_the_content(), 30)); ?></div><p><strong><?php echo esc_html(number_format_i18n($price, 2)); ?> kr</strong></p><a class="eka-button" href="<?php the_permalink(); ?>">Läs mer och anmäl dig</a></div></article>
        <?php endwhile; wp_reset_postdata(); ?></div><?php return ob_get_clean();
    }

    public static function course_date_list_shortcode() {
        $q = new WP_Query(['post_type'=>'eka_course','posts_per_page'=>-1,'post_status'=>'publish','orderby'=>'title','order'=>'ASC']);
        $items = [];
        while ($q->have_posts()) { $q->the_post(); foreach (self::sessions(get_the_ID()) as $s) {
            $items[] = ['sort'=>$s['date'].' '.$s['start'], 'date'=>$s['date'], 'start'=>$s['start'], 'title'=>get_the_title(), 'url'=>get_permalink()];
        }} wp_reset_postdata();
        usort($items, function($a,$b){ return strcmp($a['sort'],$b['sort']); });
        ob_start(); ?><div class="eka-date-list"><?php foreach ($items as $item): ?>
        <a class="eka-date-item" href="<?php echo esc_url($item['url']); ?>"><span class="eka-calendar-icon" aria-hidden="true"><span></span></span><span><strong><?php echo esc_html(wp_date(get_option('date_format'), strtotime($item['date']))); ?>, <?php echo esc_html($item['start']); ?></strong><small><?php echo esc_html($item['title']); ?></small></span></a>
        <?php endforeach; ?></div><?php return ob_get_clean();
    }

    public static function append_course_form($content) {
        if (!is_singular('eka_course') || !in_the_loop() || !is_main_query()) return $content;
        $course_id = get_the_ID(); $price = (float) get_post_meta($course_id, '_eka_price', true); $sessions = self::sessions($course_id);
        ob_start(); ?>
        <section class="eka-registration"><h2>Anmälan</h2><p><strong>Kostnad:</strong> <?php echo esc_html(number_format_i18n($price, 2)); ?> kr per deltagare</p>
        <?php if (!$sessions): ?><p>Det finns inga bokningsbara kurstillfällen just nu.</p><?php else: ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="eka-form">
        <input type="hidden" name="action" value="eka_register"><input type="hidden" name="course_id" value="<?php echo (int) $course_id; ?>"><?php wp_nonce_field('eka_register_' . $course_id, 'eka_nonce'); ?>
        <label>Kurstillfälle<select name="session" required><option value="">Välj ett tillfälle</option><?php foreach ($sessions as $s): $key=self::session_key($s); $left=max(0,(int)$s['max']-self::booked_count($course_id,$key)); ?><option value="<?php echo esc_attr($key); ?>" <?php disabled($left, 0); ?>><?php echo esc_html(self::session_label($s) . ($left ? ' – ' . $left . ' platser kvar' : ' – Fullbokad')); ?></option><?php endforeach; ?></select></label>
        <label>Namn<input name="name" autocomplete="name" required></label><label>E-post<input type="email" name="email" autocomplete="email" required></label><label>Telefon<input type="tel" name="phone" autocomplete="tel" required></label><label>Antal deltagare<input type="number" name="participants" value="1" min="1" max="20" required></label>
        <label class="eka-check"><input type="checkbox" name="privacy" value="1" required> Jag godkänner att uppgifterna sparas för att hantera anmälan.</label>
        <button class="eka-button" type="submit">Skicka anmälan</button></form><?php endif; ?></section>
        <?php return $content . ob_get_clean();
    }

    public static function handle_registration() {
        $course_id = absint($_POST['course_id'] ?? 0);
        if (!$course_id || get_post_type($course_id) !== 'eka_course' || !isset($_POST['eka_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['eka_nonce'])), 'eka_register_' . $course_id)) wp_die('Ogiltig förfrågan.');
        if (empty($_POST['privacy'])) wp_die('Du måste godkänna hanteringen av personuppgifter.');
        $session = sanitize_text_field(wp_unslash($_POST['session'] ?? ''));
        $selected = null;
        foreach (self::sessions($course_id) as $candidate) if (hash_equals(self::session_key($candidate), $session)) { $selected = $candidate; break; }
        if (!$selected) wp_die('Kurstillfället är inte giltigt.');
        $name = sanitize_text_field(wp_unslash($_POST['name'] ?? '')); $email = sanitize_email(wp_unslash($_POST['email'] ?? '')); $phone = sanitize_text_field(wp_unslash($_POST['phone'] ?? ''));
        $participants = min(20, max(1, absint($_POST['participants'] ?? 1)));
        if (!$name || !is_email($email) || !$phone) wp_die('Kontrollera kontaktuppgifterna.');
        $available = max(0, (int) $selected['max'] - self::booked_count($course_id, $session));
        if ($participants > $available) wp_die('Det finns inte tillräckligt många platser kvar för detta kurstillfälle.');
        $price = (float) get_post_meta($course_id, '_eka_price', true); $total = round($price * $participants, 2);
        $percent = min(100, max(0, (float) get_option('eka_deposit_percent', 20))); $deposit = round($total * $percent / 100, 2);
        $reference = 'KURS-' . strtoupper(wp_generate_password(8, false, false));
        global $wpdb;
        $ok = $wpdb->insert($wpdb->prefix . self::TABLE, ['course_id'=>$course_id,'session_key'=>$session,'name'=>$name,'email'=>$email,'phone'=>$phone,'participants'=>$participants,'total_amount'=>$total,'deposit_amount'=>$deposit,'payment_reference'=>$reference,'payment_status'=>'pending','created_at'=>current_time('mysql')], ['%d','%s','%s','%s','%s','%d','%f','%f','%s','%s','%s']);
        if (!$ok) wp_die('Anmälan kunde inte sparas. Försök igen.');
        $swish = get_option('eka_swish_number'); $title = get_the_title($course_id);
        $session_label = self::session_label($selected);
        $message = "Ny kursanmälan\n\nKurs: $title\nTillfälle: $session_label\nNamn: $name\nE-post: $email\nTelefon: $phone\nDeltagare: $participants\nHandpenning: " . number_format_i18n($deposit, 2) . " kr\nSwish-referens: $reference";
        wp_mail(get_option('eka_admin_email', get_option('admin_email')), 'Ny kursanmälan: ' . $title, $message);
        wp_mail($email, 'Bekräftelse på kursanmälan', "Hej $name!\n\nDin anmälan till $title ($session_label) är registrerad.\nSwisha " . number_format_i18n($deposit, 2) . " kr till $swish och ange $reference som meddelande.\n\nTotal kurskostnad: " . number_format_i18n($total, 2) . " kr.");
        $url = add_query_arg(['eka_thanks'=>1,'ref'=>$reference], get_permalink($course_id));
        wp_safe_redirect($url); exit;
    }
}

register_activation_hook(__FILE__, ['EKA_Plugin', 'activate']);
register_deactivation_hook(__FILE__, ['EKA_Plugin', 'deactivate']);
EKA_Plugin::init();
add_action('admin_enqueue_scripts', ['EKA_Plugin', 'admin_assets']);

add_filter('the_content', function($content) {
    if (!is_singular('eka_course') || empty($_GET['eka_thanks'])) return $content;
    $ref = sanitize_text_field(wp_unslash($_GET['ref'] ?? ''));
    global $wpdb;
    $registration = $wpdb->get_row($wpdb->prepare("SELECT deposit_amount FROM {$wpdb->prefix}" . EKA_Plugin::TABLE . " WHERE payment_reference=%s AND course_id=%d", $ref, get_the_ID()));
    if (!$registration) return $content;
    $amount = (float) $registration->deposit_amount; $swish = get_option('eka_swish_number');
    $box = '<div class="eka-success"><h2>Tack för din anmälan!</h2><p>Swisha <strong>' . esc_html(number_format_i18n($amount, 2)) . ' kr</strong> till <strong>' . esc_html($swish) . '</strong>.</p><p>Ange referensen <strong>' . esc_html($ref) . '</strong> som meddelande. En bekräftelse har skickats via e-post.</p></div>';
    return $box . $content;
}, 8);
