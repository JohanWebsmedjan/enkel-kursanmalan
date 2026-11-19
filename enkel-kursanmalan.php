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
    const PER_PAGE = 50;
    const STATUSES = ['pending' => 'Väntar', 'paid' => 'Betald', 'cancelled' => 'Avbokad'];
    const TIME_PATTERN = '/^([01]\d|2[0-3]):[0-5]\d$/';

    public static function init() {
        add_action('init', [__CLASS__, 'register_course_type']);
        add_action('add_meta_boxes', [__CLASS__, 'add_meta_boxes']);
        add_action('save_post_eka_course', [__CLASS__, 'save_course']);
        add_action('admin_menu', [__CLASS__, 'admin_menu']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_action('admin_notices', [__CLASS__, 'show_notices']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'admin_assets']);
        add_action('admin_post_nopriv_eka_register', [__CLASS__, 'handle_registration']);
        add_action('admin_post_eka_register', [__CLASS__, 'handle_registration']);
        add_action('admin_post_eka_payment_status', [__CLASS__, 'handle_payment_status']);
        add_action('admin_post_eka_delete_registration', [__CLASS__, 'handle_delete_registration']);
        add_action('admin_post_eka_export', [__CLASS__, 'handle_export']);
        add_shortcode('enkel_kurslista', [__CLASS__, 'course_list_shortcode']);
        add_shortcode('enkel_kursdatumlista', [__CLASS__, 'course_date_list_shortcode']);
        add_filter('the_content', [__CLASS__, 'thanks_box'], 8);
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

    /* ------------------------------------------------------------------ */
    /* Admin-meddelanden                                                   */
    /* ------------------------------------------------------------------ */

    private static function add_notice($message, $type = 'warning') {
        $key = 'eka_notices_' . get_current_user_id();
        $notices = (array) get_transient($key);
        $notices[] = ['message' => $message, 'type' => $type];
        set_transient($key, array_filter($notices), 5 * MINUTE_IN_SECONDS);
    }

    public static function show_notices() {
        $key = 'eka_notices_' . get_current_user_id();
        $notices = get_transient($key);
        if (!$notices) return;
        delete_transient($key);
        foreach ((array) $notices as $n) {
            if (empty($n['message'])) continue;
            printf('<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr($n['type']), esc_html($n['message']));
        }
    }

    /* ------------------------------------------------------------------ */
    /* Kursredigering                                                      */
    /* ------------------------------------------------------------------ */

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
        <table class="widefat" id="eka-session-table"><thead><tr><th>Startdatum</th><th>Starttid</th><th>Sluttid</th><th>Max deltagare</th><th>Anmälda</th><th></th></tr></thead><tbody>
        <?php foreach ($sessions as $i => $s): self::session_admin_row($i, $s, self::booked_count($post->ID, self::session_key($s))); endforeach; ?>
        </tbody></table>
        <p><button type="button" class="button" id="eka-add-session">+ Lägg till kurstillfälle</button></p>
        <script type="text/template" id="eka-session-template"><?php self::session_admin_row('__INDEX__', ['date'=>'','start'=>'','end'=>'','max'=>12], null); ?></script>
        <?php
    }

    private static function session_admin_row($i, $s, $booked) { ?>
        <tr><td><input type="date" name="eka_sessions[<?php echo esc_attr($i); ?>][date]" value="<?php echo esc_attr($s['date']); ?>" required></td>
        <td><input type="time" name="eka_sessions[<?php echo esc_attr($i); ?>][start]" value="<?php echo esc_attr($s['start']); ?>" required></td>
        <td><input type="time" name="eka_sessions[<?php echo esc_attr($i); ?>][end]" value="<?php echo esc_attr($s['end']); ?>"></td>
        <td><input type="number" min="1" name="eka_sessions[<?php echo esc_attr($i); ?>][max]" value="<?php echo esc_attr($s['max']); ?>" required style="width:100px"></td>
        <td><?php echo $booked === null ? '–' : (int) $booked; ?></td>
        <td><button type="button" class="button-link-delete eka-remove-session">Ta bort</button></td></tr>
    <?php }

    public static function save_course($post_id) {
        if (!isset($_POST['eka_course_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['eka_course_nonce'])), 'eka_save_course')) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;
        update_post_meta($post_id, '_eka_price', max(0, (float) ($_POST['eka_price'] ?? 0)));

        $old_keys = array_map([__CLASS__, 'session_key'], self::sessions($post_id));
        $sessions = []; $keys = [];
        foreach ((array) ($_POST['eka_sessions'] ?? []) as $row) {
            $date = sanitize_text_field(wp_unslash($row['date'] ?? ''));
            $start = sanitize_text_field(wp_unslash($row['start'] ?? ''));
            $end = sanitize_text_field(wp_unslash($row['end'] ?? ''));
            $max = max(1, absint($row['max'] ?? 1));
            if ($date === '' && $start === '') continue;

            if (!self::valid_date($date) || !preg_match(self::TIME_PATTERN, $start)) {
                self::add_notice(sprintf('Kurstillfället "%s %s" sparades inte: datum eller starttid är ogiltigt.', $date, $start), 'error');
                continue;
            }
            if ($end !== '' && (!preg_match(self::TIME_PATTERN, $end) || $end <= $start)) {
                self::add_notice(sprintf('Sluttiden för %s kl. %s togs bort eftersom den måste vara senare än starttiden.', $date, $start));
                $end = '';
            }
            $s = compact('date', 'start', 'end', 'max');
            $key = self::session_key($s);
            if (isset($keys[$key])) {
                self::add_notice(sprintf('Kurstillfället %s fanns med två gånger och sparades bara en gång.', self::session_label($s)));
                continue;
            }
            $keys[$key] = true;
            $sessions[] = $s;
        }
        usort($sessions, function($a, $b) { return strcmp($a['date'] . $a['start'], $b['date'] . $b['start']); });
        update_post_meta($post_id, '_eka_sessions', wp_json_encode($sessions));

        foreach (array_diff($old_keys, array_keys($keys)) as $removed) {
            $booked = self::booked_count($post_id, $removed);
            if ($booked > 0) self::add_notice(sprintf('Kurstillfället %s togs bort eller ändrades, men har %d aktiva anmälda. Anmälningarna finns kvar under Anmälningar.', self::key_label($removed), $booked));
        }
    }

    private static function valid_date($date) {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m)) return false;
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /* ------------------------------------------------------------------ */
    /* Inställningar och menyer                                            */
    /* ------------------------------------------------------------------ */

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

    /* ------------------------------------------------------------------ */
    /* Anmälningar i admin                                                 */
    /* ------------------------------------------------------------------ */

    private static function registration_filters() {
        $status = sanitize_key(wp_unslash($_GET['status'] ?? ''));
        return [
            'course_id' => absint($_GET['course_id'] ?? 0),
            'session' => sanitize_text_field(wp_unslash($_GET['session'] ?? '')),
            'status' => isset(self::STATUSES[$status]) ? $status : '',
        ];
    }

    private static function filters_where($f) {
        global $wpdb;
        $where = ['1=1']; $params = [];
        if ($f['course_id']) { $where[] = 'r.course_id=%d'; $params[] = $f['course_id']; }
        if ($f['session'] !== '') { $where[] = 'r.session_key=%s'; $params[] = $f['session']; }
        if ($f['status']) { $where[] = 'r.payment_status=%s'; $params[] = $f['status']; }
        $sql = implode(' AND ', $where);
        return $params ? $wpdb->prepare($sql, $params) : $sql;
    }

    public static function registrations_page() {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $f = self::registration_filters();
        $where = self::filters_where($f);
        $paged = max(1, absint($_GET['paged'] ?? 1));
        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table r WHERE $where");
        $offset = ($paged - 1) * self::PER_PAGE;
        $rows = $wpdb->get_results($wpdb->prepare("SELECT r.*, p.post_title FROM $table r LEFT JOIN {$wpdb->posts} p ON p.ID=r.course_id WHERE $where ORDER BY r.created_at DESC, r.id DESC LIMIT %d OFFSET %d", self::PER_PAGE, $offset));
        $courses = get_posts(['post_type' => 'eka_course', 'post_status' => 'any', 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC']);
        $session_keys = $f['course_id'] ? $wpdb->get_col($wpdb->prepare("SELECT DISTINCT session_key FROM $table WHERE course_id=%d ORDER BY session_key", $f['course_id'])) : [];
        $export_url = wp_nonce_url(add_query_arg(array_merge(['action' => 'eka_export'], array_filter($f)), admin_url('admin-post.php')), 'eka_export');
        ?>
        <div class="wrap"><h1 class="wp-heading-inline">Anmälningar</h1> <a class="page-title-action" href="<?php echo esc_url($export_url); ?>">Exportera CSV</a>
        <form method="get" style="margin:12px 0"><input type="hidden" name="post_type" value="eka_course"><input type="hidden" name="page" value="eka-registrations">
            <select name="course_id" onchange="this.form.session && (this.form.session.value='');this.form.submit()"><option value="">Alla kurser</option>
            <?php foreach ($courses as $c): ?><option value="<?php echo (int) $c->ID; ?>" <?php selected($f['course_id'], $c->ID); ?>><?php echo esc_html($c->post_title); ?></option><?php endforeach; ?></select>
            <?php if ($f['course_id']): ?><select name="session"><option value="">Alla tillfällen</option>
            <?php foreach ($session_keys as $k): ?><option value="<?php echo esc_attr($k); ?>" <?php selected($f['session'], $k); ?>><?php echo esc_html(self::key_label($k)); ?></option><?php endforeach; ?></select><?php endif; ?>
            <select name="status"><option value="">Alla statusar</option>
            <?php foreach (self::STATUSES as $k => $label): ?><option value="<?php echo esc_attr($k); ?>" <?php selected($f['status'], $k); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select>
            <button class="button">Filtrera</button>
            <?php if (array_filter($f)): ?><a class="button-link" href="<?php echo esc_url(admin_url('edit.php?post_type=eka_course&page=eka-registrations')); ?>">Rensa filter</a><?php endif; ?>
            <span style="margin-left:8px"><?php echo esc_html(sprintf('%d anmälningar', $total)); ?></span>
        </form>
        <table class="widefat striped"><thead><tr><th>Datum</th><th>Kurs / tillfälle</th><th>Deltagare</th><th>Kontakt</th><th>Belopp</th><th>Swish-referens</th><th>Status</th><th>Åtgärder</th></tr></thead><tbody>
        <?php if (!$rows): ?><tr><td colspan="8">Inga anmälningar hittades.</td></tr><?php endif; ?>
        <?php foreach ($rows as $r): ?><tr<?php echo $r->payment_status === 'cancelled' ? ' style="opacity:.6"' : ''; ?>>
        <td><?php echo esc_html($r->created_at); ?></td><td><strong><?php echo esc_html($r->post_title); ?></strong><br><?php echo esc_html(self::key_label($r->session_key)); ?></td>
        <td><?php echo esc_html($r->name); ?> (<?php echo (int) $r->participants; ?>)</td><td><a href="mailto:<?php echo esc_attr($r->email); ?>"><?php echo esc_html($r->email); ?></a><br><?php echo esc_html($r->phone); ?></td>
        <td><?php echo esc_html(number_format_i18n($r->deposit_amount, 2)); ?> kr av <?php echo esc_html(number_format_i18n($r->total_amount, 2)); ?> kr</td><td><code><?php echo esc_html($r->payment_reference); ?></code></td>
        <td><?php echo self::status_badge($r->payment_status); ?></td>
        <td><?php
            if ($r->payment_status === 'cancelled') {
                self::row_button($r->id, 'eka_payment_status', ['status' => 'pending'], 'Återaktivera');
            } else {
                self::row_button($r->id, 'eka_payment_status', ['status' => $r->payment_status === 'paid' ? 'pending' : 'paid'], $r->payment_status === 'paid' ? 'Ångra betald' : 'Markera betald');
                self::row_button($r->id, 'eka_payment_status', ['status' => 'cancelled'], 'Avboka', 'Avboka anmälan? Platserna frigörs för nya anmälningar.');
            }
            self::row_button($r->id, 'eka_delete_registration', [], 'Ta bort', 'Ta bort anmälan permanent? Detta går inte att ångra.', 'button-link-delete');
        ?></td>
        </tr><?php endforeach; ?></tbody></table>
        <?php
        $pages = (int) ceil($total / self::PER_PAGE);
        if ($pages > 1) echo '<div class="tablenav"><div class="tablenav-pages">' . paginate_links(['base' => add_query_arg('paged', '%#%'), 'format' => '', 'current' => $paged, 'total' => $pages]) . '</div></div>';
        ?></div><?php
    }

    private static function status_badge($status) {
        $colors = ['paid' => '#087b37', 'cancelled' => '#b32d2e', 'pending' => 'inherit'];
        $label = self::STATUSES[$status] ?? $status;
        return '<strong style="color:' . esc_attr($colors[$status] ?? 'inherit') . '">' . esc_html($label) . '</strong>';
    }

    private static function row_button($id, $action, $fields, $label, $confirm = '', $class = 'button button-small') { ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin:0 4px 4px 0"<?php if ($confirm): ?> onsubmit="return confirm(<?php echo esc_attr(wp_json_encode($confirm)); ?>)"<?php endif; ?>>
        <input type="hidden" name="action" value="<?php echo esc_attr($action); ?>"><input type="hidden" name="id" value="<?php echo (int) $id; ?>">
        <?php foreach ($fields as $k => $v): ?><input type="hidden" name="<?php echo esc_attr($k); ?>" value="<?php echo esc_attr($v); ?>"><?php endforeach; ?>
        <?php wp_nonce_field('eka_row_' . $id); ?><button class="<?php echo esc_attr($class); ?>"><?php echo esc_html($label); ?></button></form>
    <?php }

    private static function back_to_list() {
        $fallback = admin_url('edit.php?post_type=eka_course&page=eka-registrations');
        wp_safe_redirect(wp_get_referer() ?: $fallback);
        exit;
    }

    public static function handle_payment_status() {
        if (!current_user_can('manage_options')) wp_die('Behörighet saknas.');
        $id = absint($_POST['id'] ?? 0);
        check_admin_referer('eka_row_' . $id);
        $status = sanitize_key(wp_unslash($_POST['status'] ?? ''));
        if (!isset(self::STATUSES[$status])) wp_die('Ogiltig status.');
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d", $id));
        if (!$row) wp_die('Anmälan hittades inte.');

        // Återaktivering tar platser i anspråk igen och måste kontrolleras mot max antal.
        if ($row->payment_status === 'cancelled' && $status !== 'cancelled') {
            if (!self::lock_session($row->course_id, $row->session_key)) { self::add_notice('Kunde inte låsa kurstillfället. Försök igen.', 'error'); self::back_to_list(); }
            $max = self::session_max($row->course_id, $row->session_key);
            if ($max !== null && self::booked_count($row->course_id, $row->session_key) + (int) $row->participants > $max) {
                self::unlock_session($row->course_id, $row->session_key);
                self::add_notice(sprintf('%s kunde inte återaktiveras: det finns inte tillräckligt många lediga platser. Höj max antal deltagare på kursen först.', $row->payment_reference), 'error');
                self::back_to_list();
            }
            $wpdb->update($table, ['payment_status' => $status], ['id' => $id], ['%s'], ['%d']);
            self::unlock_session($row->course_id, $row->session_key);
        } else {
            $wpdb->update($table, ['payment_status' => $status], ['id' => $id], ['%s'], ['%d']);
        }
        self::add_notice(sprintf('%s har nu status: %s.', $row->payment_reference, self::STATUSES[$status]), 'success');
        self::back_to_list();
    }

    public static function handle_delete_registration() {
        if (!current_user_can('manage_options')) wp_die('Behörighet saknas.');
        $id = absint($_POST['id'] ?? 0);
        check_admin_referer('eka_row_' . $id);
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $ref = $wpdb->get_var($wpdb->prepare("SELECT payment_reference FROM $table WHERE id=%d", $id));
        if ($ref && $wpdb->delete($table, ['id' => $id], ['%d'])) self::add_notice(sprintf('Anmälan %s togs bort.', $ref), 'success');
        self::back_to_list();
    }

    public static function handle_export() {
        if (!current_user_can('manage_options')) wp_die('Behörighet saknas.');
        check_admin_referer('eka_export');
        global $wpdb;
        $where = self::filters_where(self::registration_filters());
        $rows = $wpdb->get_results("SELECT r.*, p.post_title FROM {$wpdb->prefix}" . self::TABLE . " r LEFT JOIN {$wpdb->posts} p ON p.ID=r.course_id WHERE $where ORDER BY r.created_at DESC, r.id DESC");

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="kursanmalningar-' . wp_date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // BOM så att Excel läser åäö rätt
        fputcsv($out, ['Anmäld', 'Kurs', 'Tillfälle', 'Namn', 'E-post', 'Telefon', 'Deltagare', 'Totalbelopp', 'Handpenning', 'Swish-referens', 'Status'], ';');
        foreach ($rows as $r) {
            fputcsv($out, array_map([__CLASS__, 'csv_safe'], [
                $r->created_at, $r->post_title, self::key_label($r->session_key), $r->name, $r->email, $r->phone,
                (int) $r->participants, number_format((float) $r->total_amount, 2, ',', ''), number_format((float) $r->deposit_amount, 2, ',', ''),
                $r->payment_reference, self::STATUSES[$r->payment_status] ?? $r->payment_status,
            ]), ';');
        }
        fclose($out);
        exit;
    }

    /** Hindrar att kalkylprogram tolkar inskickad text som formler. */
    private static function csv_safe($value) {
        $value = (string) $value;
        return preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
    }

    /* ------------------------------------------------------------------ */
    /* Resurser                                                            */
    /* ------------------------------------------------------------------ */

    public static function assets() {
        wp_register_style('eka-style', plugins_url('assets/eka.css', __FILE__), [], self::VERSION);
        wp_enqueue_style('eka-style');
    }

    public static function admin_assets($hook) {
        if (($hook === 'post.php' || $hook === 'post-new.php') && get_current_screen() && get_current_screen()->post_type === 'eka_course') {
            wp_enqueue_script('eka-admin', plugins_url('assets/eka-admin.js', __FILE__), [], self::VERSION, true);
        }
    }

    /* ------------------------------------------------------------------ */
    /* Kurstillfällen                                                      */
    /* ------------------------------------------------------------------ */

    private static function sessions($course_id) {
        $raw = (string) get_post_meta($course_id, '_eka_sessions', true);
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) return array_values(array_filter($decoded, function($s) { return !empty($s['date']) && !empty($s['start']); }));
        // Äldre format: en rad per tillfälle, t.ex. "2026-03-14 9.00–12:00".
        $legacy = array_values(array_filter(array_map('trim', preg_split('/\R/', $raw))));
        $sessions = [];
        foreach ($legacy as $line) {
            if (!preg_match('/^(\d{4}-\d{2}-\d{2})\s+(\d{1,2})[:.](\d{2})(?:\D+(\d{1,2})[:.](\d{2}))?/', $line, $m) || !self::valid_date($m[1])) continue;
            $start = sprintf('%02d:%s', $m[2], $m[3]);
            $end = isset($m[4]) && $m[4] !== '' ? sprintf('%02d:%s', $m[4], $m[5]) : '';
            if (!preg_match(self::TIME_PATTERN, $start)) continue;
            if ($end !== '' && (!preg_match(self::TIME_PATTERN, $end) || $end <= $start)) $end = '';
            $sessions[] = ['date' => $m[1], 'start' => $start, 'end' => $end, 'max' => 12];
        }
        return $sessions;
    }

    private static function session_start($s) {
        return DateTimeImmutable::createFromFormat('!Y-m-d H:i', $s['date'] . ' ' . $s['start'], wp_timezone()) ?: null;
    }

    /** Kurstillfällen som ännu inte har startat, enligt webbplatsens tidszon. */
    private static function upcoming_sessions($course_id) {
        $now = new DateTimeImmutable('now', wp_timezone());
        return array_values(array_filter(self::sessions($course_id), function($s) use ($now) {
            $start = self::session_start($s);
            return $start && $start > $now;
        }));
    }

    private static function session_key($s) { return $s['date'] . '|' . $s['start'] . '|' . ($s['end'] ?? ''); }
    private static function session_label($s) {
        $date = wp_date(get_option('date_format'), strtotime($s['date']));
        return $date . ' kl. ' . $s['start'] . (!empty($s['end']) ? '–' . $s['end'] : '');
    }
    private static function key_label($key) {
        $p = explode('|', (string) $key);
        if (count($p) < 2 || !self::valid_date($p[0])) return (string) $key;
        return self::session_label(['date' => $p[0], 'start' => $p[1], 'end' => $p[2] ?? '']);
    }
    private static function session_max($course_id, $key) {
        foreach (self::sessions($course_id) as $s) if (self::session_key($s) === $key) return (int) $s['max'];
        return null;
    }

    /** Antal upptagna platser (avbokade anmälningar räknas inte). */
    private static function booked_count($course_id, $key) {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(participants),0) FROM {$wpdb->prefix}" . self::TABLE . " WHERE course_id=%d AND session_key=%s AND payment_status<>'cancelled'", $course_id, $key));
    }

    /** Låser ett kurstillfälle så att kontroll av lediga platser och sparande sker utan att andra anmälningar hinner emellan. */
    private static function lock_name($course_id, $key) { return 'eka_' . md5($course_id . '|' . $key); }
    private static function lock_session($course_id, $key) {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 10)', self::lock_name($course_id, $key))) === 1;
    }
    private static function unlock_session($course_id, $key) {
        global $wpdb;
        $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', self::lock_name($course_id, $key)));
    }

    /* ------------------------------------------------------------------ */
    /* Publika sidor                                                       */
    /* ------------------------------------------------------------------ */

    public static function course_list_shortcode() {
        $q = new WP_Query(['post_type' => 'eka_course', 'posts_per_page' => -1, 'post_status' => 'publish']);
        ob_start(); ?><div class="eka-grid"><?php while ($q->have_posts()): $q->the_post(); $price = (float) get_post_meta(get_the_ID(), '_eka_price', true); ?>
        <article class="eka-card"><?php if (has_post_thumbnail()) the_post_thumbnail('medium_large'); ?><div class="eka-card-body"><h2><?php the_title(); ?></h2><div><?php echo wp_kses_post(wp_trim_words(get_the_excerpt() ?: get_the_content(), 30)); ?></div><p><strong><?php echo esc_html(number_format_i18n($price, 2)); ?> kr</strong></p><a class="eka-button" href="<?php the_permalink(); ?>">Läs mer och anmäl dig</a></div></article>
        <?php endwhile; wp_reset_postdata(); ?></div><?php return ob_get_clean();
    }

    public static function course_date_list_shortcode() {
        $q = new WP_Query(['post_type'=>'eka_course','posts_per_page'=>-1,'post_status'=>'publish','orderby'=>'title','order'=>'ASC']);
        $items = [];
        while ($q->have_posts()) { $q->the_post(); foreach (self::upcoming_sessions(get_the_ID()) as $s) {
            $items[] = ['sort'=>self::session_start($s)->getTimestamp(), 'date'=>$s['date'], 'start'=>$s['start'], 'title'=>get_the_title(), 'url'=>get_permalink()];
        }} wp_reset_postdata();
        usort($items, function($a, $b) { return $a['sort'] <=> $b['sort'] ?: strcmp($a['title'], $b['title']); });
        ob_start(); ?><div class="eka-date-list"><?php if (!$items): ?><p>Det finns inga kommande kurstillfällen just nu.</p><?php endif; ?><?php foreach ($items as $item): ?>
        <a class="eka-date-item" href="<?php echo esc_url($item['url']); ?>"><span class="eka-calendar-icon" aria-hidden="true"><span></span></span><span><strong><?php echo esc_html(wp_date(get_option('date_format'), strtotime($item['date']))); ?>, <?php echo esc_html($item['start']); ?></strong><small><?php echo esc_html($item['title']); ?></small></span></a>
        <?php endforeach; ?></div><?php return ob_get_clean();
    }

    public static function append_course_form($content) {
        if (!is_singular('eka_course') || !in_the_loop() || !is_main_query()) return $content;
        $course_id = get_the_ID(); $price = (float) get_post_meta($course_id, '_eka_price', true); $sessions = self::upcoming_sessions($course_id);
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
        foreach (self::upcoming_sessions($course_id) as $candidate) if (hash_equals(self::session_key($candidate), $session)) { $selected = $candidate; break; }
        if (!$selected) wp_die('Kurstillfället är inte giltigt eller har redan startat.');
        $name = sanitize_text_field(wp_unslash($_POST['name'] ?? '')); $email = sanitize_email(wp_unslash($_POST['email'] ?? '')); $phone = sanitize_text_field(wp_unslash($_POST['phone'] ?? ''));
        $participants = min(20, max(1, absint($_POST['participants'] ?? 1)));
        if (!$name || !is_email($email) || !$phone) wp_die('Kontrollera kontaktuppgifterna.');
        $price = (float) get_post_meta($course_id, '_eka_price', true); $total = round($price * $participants, 2);
        $percent = min(100, max(0, (float) get_option('eka_deposit_percent', 20))); $deposit = round($total * $percent / 100, 2);
        $reference = 'KURS-' . strtoupper(wp_generate_password(8, false, false));

        // Kontroll av lediga platser och sparande sker under lås, så att två samtidiga anmälningar inte kan ta samma plats.
        if (!self::lock_session($course_id, $session)) wp_die('Det kommer in många anmälningar just nu. Gå tillbaka och försök igen om en stund.');
        $available = max(0, (int) $selected['max'] - self::booked_count($course_id, $session));
        if ($participants > $available) {
            self::unlock_session($course_id, $session);
            wp_die('Det finns inte tillräckligt många platser kvar för detta kurstillfälle.');
        }
        global $wpdb;
        $ok = $wpdb->insert($wpdb->prefix . self::TABLE, ['course_id'=>$course_id,'session_key'=>$session,'name'=>$name,'email'=>$email,'phone'=>$phone,'participants'=>$participants,'total_amount'=>$total,'deposit_amount'=>$deposit,'payment_reference'=>$reference,'payment_status'=>'pending','created_at'=>current_time('mysql')], ['%d','%s','%s','%s','%s','%d','%f','%f','%s','%s','%s']);
        self::unlock_session($course_id, $session);
        if (!$ok) wp_die('Anmälan kunde inte sparas. Försök igen.');

        $swish = get_option('eka_swish_number'); $title = get_the_title($course_id);
        $session_label = self::session_label($selected);
        $message = "Ny kursanmälan\n\nKurs: $title\nTillfälle: $session_label\nNamn: $name\nE-post: $email\nTelefon: $phone\nDeltagare: $participants\nHandpenning: " . number_format_i18n($deposit, 2) . " kr\nSwish-referens: $reference";
        wp_mail(get_option('eka_admin_email', get_option('admin_email')), 'Ny kursanmälan: ' . $title, $message);
        wp_mail($email, 'Bekräftelse på kursanmälan', "Hej $name!\n\nDin anmälan till $title ($session_label) är registrerad.\nSwisha " . number_format_i18n($deposit, 2) . " kr till $swish och ange $reference som meddelande.\n\nTotal kurskostnad: " . number_format_i18n($total, 2) . " kr.");
        $url = add_query_arg(['eka_thanks'=>1,'ref'=>$reference], get_permalink($course_id));
        wp_safe_redirect($url); exit;
    }

    /* ------------------------------------------------------------------ */
    /* Tacksida med Swish                                                  */
    /* ------------------------------------------------------------------ */

    public static function thanks_box($content) {
        if (!is_singular('eka_course') || empty($_GET['eka_thanks'])) return $content;
        $ref = sanitize_text_field(wp_unslash($_GET['ref'] ?? ''));
        global $wpdb;
        $registration = $wpdb->get_row($wpdb->prepare("SELECT deposit_amount FROM {$wpdb->prefix}" . self::TABLE . " WHERE payment_reference=%s AND course_id=%d", $ref, get_the_ID()));
        if (!$registration) return $content;
        $amount = (float) $registration->deposit_amount;
        $swish = (string) get_option('eka_swish_number');
        $swish_digits = preg_replace('/\D/', '', $swish);

        $box = '<div class="eka-success"><h2>Tack för din anmälan!</h2>';
        if ($amount > 0 && $swish_digits !== '') {
            $box .= '<p>Swisha <strong>' . esc_html(number_format_i18n($amount, 2)) . ' kr</strong> till <strong>' . esc_html($swish) . '</strong>.</p>';
            $box .= '<p>Ange referensen <strong>' . esc_html($ref) . '</strong> som meddelande.</p>';
            $box .= '<div class="eka-swish">';
            $box .= '<a class="eka-button eka-swish-link" href="' . esc_url(self::swish_link($swish_digits, $amount, $ref), ['swish']) . '">Öppna Swish</a>';
            $qr = self::swish_qr($swish_digits, $amount, $ref);
            if ($qr) $box .= '<figure class="eka-swish-qr"><img src="data:image/png;base64,' . esc_attr($qr) . '" width="220" height="220" alt="QR-kod för Swish-betalning"><figcaption>Skanna med Swish-appen</figcaption></figure>';
            $box .= '</div>';
        }
        $box .= '<p>En bekräftelse har skickats via e-post.</p></div>';
        return $box . $content;
    }

    /** Länk som öppnar Swish-appen med mottagare, belopp och meddelande ifyllda. */
    private static function swish_link($number, $amount, $ref) {
        $data = ['version' => 1, 'payee' => ['value' => $number], 'amount' => ['value' => round($amount, 2)], 'message' => ['value' => $ref, 'editable' => false]];
        return 'swish://payment?data=' . rawurlencode(wp_json_encode($data));
    }

    /** Hämtar en förifylld QR-kod från Swish (cachas). Returnerar base64-PNG eller tom sträng. */
    private static function swish_qr($number, $amount, $ref) {
        $payload = ['format' => 'png', 'size' => 300, 'payee' => ['value' => $number, 'editable' => false], 'amount' => ['value' => round($amount, 2), 'editable' => false], 'message' => ['value' => $ref, 'editable' => false]];
        $cache_key = 'eka_qr_' . md5(wp_json_encode($payload));
        $cached = get_transient($cache_key);
        if (is_string($cached) && $cached !== '') return $cached;
        $res = wp_remote_post('https://mpc.getswish.net/qrg-swish/api/v1/prefilled', ['timeout' => 5, 'headers' => ['Content-Type' => 'application/json'], 'body' => wp_json_encode($payload)]);
        if (is_wp_error($res) || (int) wp_remote_retrieve_response_code($res) !== 200) return '';
        $png = wp_remote_retrieve_body($res);
        if (strpos($png, "\x89PNG") !== 0) return '';
        $b64 = base64_encode($png);
        set_transient($cache_key, $b64, MONTH_IN_SECONDS);
        return $b64;
    }
}

register_activation_hook(__FILE__, ['EKA_Plugin', 'activate']);
register_deactivation_hook(__FILE__, ['EKA_Plugin', 'deactivate']);
EKA_Plugin::init();
