<?php
/**
 * Plugin Name: Enkel kursanmälan med Swish
 * Description: Kurser, kurstillfällen och anmälningar med procentuell Swish-handpenning.
 * Version: 1.2.0
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
    const VERSION = '1.2.0';
    const DB_VERSION = '1.2.0-participant-details';
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
        add_action('admin_post_eka_cancel_registration', [__CLASS__, 'handle_cancel_registration']);
        add_action('admin_post_eka_send_session_mail', [__CLASS__, 'handle_session_mail']);
        add_shortcode('enkel_kurslista', [__CLASS__, 'course_list_shortcode']);
        add_shortcode('enkel_kursdatumlista', [__CLASS__, 'course_date_list_shortcode']);
        add_filter('the_content', [__CLASS__, 'append_course_form']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'assets']);
    }

    public static function activate() {
        self::upgrade_database();
        self::register_course_type();
        flush_rewrite_rules();
    }

    public static function upgrade_database() {
        if (get_option('eka_db_version') === self::DB_VERSION) return;
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
            address varchar(191) NOT NULL DEFAULT '',
            postal_code varchar(20) NOT NULL DEFAULT '',
            city varchar(100) NOT NULL DEFAULT '',
            attendee_data longtext NULL,
            participants smallint unsigned NOT NULL DEFAULT 1,
            total_amount decimal(10,2) NOT NULL DEFAULT 0,
            deposit_amount decimal(10,2) NOT NULL DEFAULT 0,
            payment_reference varchar(50) NOT NULL,
            payment_status varchar(20) NOT NULL DEFAULT 'pending',
            registration_status varchar(20) NOT NULL DEFAULT 'active',
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY course_id (course_id),
            UNIQUE KEY payment_reference (payment_reference)
        ) $charset;");
        $course_ids=$wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_type='eka_course'");
        foreach ($course_ids as $course_id) {
            if (get_post_meta($course_id,'_eka_schedule_model',true)==='single-course') continue;
            $legacy=self::legacy_sessions($course_id);
            if (!$legacy) continue;
            $first=array_shift($legacy);
            update_post_meta($course_id,'_eka_start_date',$first['date']);
            update_post_meta($course_id,'_eka_start_time',$first['start']);
            update_post_meta($course_id,'_eka_end_time',$first['end'] ?? '');
            update_post_meta($course_id,'_eka_max_participants',max(1,(int)($first['max'] ?? 12)));
            update_post_meta($course_id,'_eka_additional_dates',wp_json_encode(array_map(function($s){ return ['date'=>$s['date'],'start'=>$s['start'],'end'=>$s['end'] ?? '']; },$legacy)));
            update_post_meta($course_id,'_eka_schedule_model','single-course');
            $wpdb->update($table,['session_key'=>self::session_key($first)],['course_id'=>$course_id],['%s'],['%d']);
        }
        update_option('eka_db_version', self::DB_VERSION);
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
        $start = self::course_start($post->ID);
        $additional_dates = self::additional_dates($post->ID);
        ?>
        <p><label><strong>Kostnad per deltagare (kr)</strong><br><input type="number" min="0" step="0.01" name="eka_price" value="<?php echo esc_attr($price); ?>" style="width:220px"></label></p>
        <h3>Kursstart och anmälan</h3><p class="description">Detta är kursens enda bokningsbara startdatum. Maxantalet gäller hela kursen.</p>
        <table class="widefat"><thead><tr><th>Startdatum</th><th>Starttid</th><th>Sluttid</th><th>Max deltagare</th></tr></thead><tbody><tr>
        <td><input type="date" name="eka_start_date" value="<?php echo esc_attr($start['date']); ?>" required></td>
        <td><input type="time" name="eka_start_time" value="<?php echo esc_attr($start['start']); ?>" required></td>
        <td><input type="time" name="eka_end_time" value="<?php echo esc_attr($start['end']); ?>"></td>
        <td><input type="number" min="1" name="eka_max_participants" value="<?php echo esc_attr($start['max']); ?>" required style="width:100px"></td></tr></tbody></table>
        <h3>Övriga datum som ingår i kursen</h3><p class="description">Deltagaren anmäls automatiskt till samtliga datum. Raderna är inte separata bokningsalternativ.</p>
        <table class="widefat" id="eka-session-table"><thead><tr><th>Datum</th><th>Starttid</th><th>Sluttid</th><th></th></tr></thead><tbody>
        <?php foreach ($additional_dates as $i => $s): self::additional_date_admin_row($i, $s); endforeach; ?>
        </tbody></table>
        <p><button type="button" class="button" id="eka-add-session">+ Lägg till datum</button></p>
        <script type="text/template" id="eka-session-template"><?php self::additional_date_admin_row('__INDEX__', ['date'=>'','start'=>'','end'=>'']); ?></script>
        <?php
    }

    private static function additional_date_admin_row($i, $s) { ?>
        <tr><td><input type="date" name="eka_additional_dates[<?php echo esc_attr($i); ?>][date]" value="<?php echo esc_attr($s['date']); ?>" required></td>
        <td><input type="time" name="eka_additional_dates[<?php echo esc_attr($i); ?>][start]" value="<?php echo esc_attr($s['start']); ?>" required></td>
        <td><input type="time" name="eka_additional_dates[<?php echo esc_attr($i); ?>][end]" value="<?php echo esc_attr($s['end']); ?>"></td>
        <td><button type="button" class="button-link-delete eka-remove-session">Ta bort</button></td></tr>
    <?php }

    public static function save_course($post_id) {
        if (!isset($_POST['eka_course_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['eka_course_nonce'])), 'eka_save_course')) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;
        update_post_meta($post_id, '_eka_price', max(0, (float) ($_POST['eka_price'] ?? 0)));
        $start_date = sanitize_text_field(wp_unslash($_POST['eka_start_date'] ?? ''));
        $start_time = sanitize_text_field(wp_unslash($_POST['eka_start_time'] ?? ''));
        $end_time = sanitize_text_field(wp_unslash($_POST['eka_end_time'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/',$start_date)) update_post_meta($post_id,'_eka_start_date',$start_date);
        if (preg_match('/^\d{2}:\d{2}$/',$start_time)) update_post_meta($post_id,'_eka_start_time',$start_time);
        update_post_meta($post_id,'_eka_end_time',preg_match('/^\d{2}:\d{2}$/',$end_time) ? $end_time : '');
        update_post_meta($post_id,'_eka_max_participants',max(1,absint($_POST['eka_max_participants'] ?? 1)));
        $additional_dates = [];
        foreach ((array) ($_POST['eka_additional_dates'] ?? []) as $row) {
            $date = sanitize_text_field(wp_unslash($row['date'] ?? ''));
            $start = sanitize_text_field(wp_unslash($row['start'] ?? ''));
            $end = sanitize_text_field(wp_unslash($row['end'] ?? ''));
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && preg_match('/^\d{2}:\d{2}$/', $start)) $additional_dates[] = compact('date', 'start', 'end');
        }
        update_post_meta($post_id, '_eka_additional_dates', wp_json_encode($additional_dates));
        update_post_meta($post_id, '_eka_schedule_model', 'single-course');
        if ($start_date && $start_time) {
            global $wpdb;
            $wpdb->update($wpdb->prefix.self::TABLE,['session_key'=>$start_date.'|'.$start_time.'|'.(preg_match('/^\d{2}:\d{2}$/',$end_time)?$end_time:'')],['course_id'=>$post_id],['%s'],['%d']);
        }
    }

    public static function register_settings() {
        register_setting('eka_settings', 'eka_swish_number', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('eka_settings', 'eka_deposit_percent', ['sanitize_callback' => function($v) { return min(100, max(0, (float) $v)); }]);
        register_setting('eka_settings', 'eka_admin_email', ['sanitize_callback' => 'sanitize_email']);
        register_setting('eka_settings', 'eka_welcome_subject', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('eka_settings', 'eka_welcome_message', ['sanitize_callback' => 'sanitize_textarea_field']);
        register_setting('eka_settings', 'eka_cancel_subject', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('eka_settings', 'eka_cancel_message', ['sanitize_callback' => 'sanitize_textarea_field']);
    }

    public static function admin_menu() {
        add_submenu_page('edit.php?post_type=eka_course', 'Anmälningar', 'Anmälningar', 'manage_options', 'eka-registrations', [__CLASS__, 'registrations_page']);
        add_submenu_page('edit.php?post_type=eka_course', 'Kursmail', 'Kursmail', 'manage_options', 'eka-course-mail', [__CLASS__, 'course_mail_page']);
        add_submenu_page('edit.php?post_type=eka_course', 'Inställningar', 'Inställningar', 'manage_options', 'eka-settings', [__CLASS__, 'settings_page']);
    }

    public static function settings_page() { ?>
        <div class="wrap"><h1>Inställningar för kursanmälan</h1><form method="post" action="options.php">
        <?php settings_fields('eka_settings'); ?>
        <table class="form-table">
        <tr><th><label for="eka_swish_number">Swish-nummer</label></th><td><input class="regular-text" id="eka_swish_number" name="eka_swish_number" value="<?php echo esc_attr(get_option('eka_swish_number')); ?>" required></td></tr>
        <tr><th><label for="eka_deposit_percent">Handpenning (%)</label></th><td><input type="number" min="0" max="100" step="0.1" id="eka_deposit_percent" name="eka_deposit_percent" value="<?php echo esc_attr(get_option('eka_deposit_percent', 20)); ?>"></td></tr>
        <tr><th><label for="eka_admin_email">E-post för avisering</label></th><td><input type="email" class="regular-text" id="eka_admin_email" name="eka_admin_email" value="<?php echo esc_attr(get_option('eka_admin_email', get_option('admin_email'))); ?>"></td></tr>
        <tr><th><label for="eka_welcome_subject">Ämne – välkomstmail</label></th><td><input class="large-text" id="eka_welcome_subject" name="eka_welcome_subject" value="<?php echo esc_attr(get_option('eka_welcome_subject', 'Välkommen till {course}')); ?>"></td></tr>
        <tr><th><label for="eka_welcome_message">Välkomstmail</label></th><td><textarea class="large-text" rows="8" id="eka_welcome_message" name="eka_welcome_message"><?php echo esc_textarea(get_option('eka_welcome_message', self::default_welcome_message())); ?></textarea></td></tr>
        <tr><th><label for="eka_cancel_subject">Ämne – avbokningsmail</label></th><td><input class="large-text" id="eka_cancel_subject" name="eka_cancel_subject" value="<?php echo esc_attr(get_option('eka_cancel_subject', 'Avbokning: {course}')); ?>"></td></tr>
        <tr><th><label for="eka_cancel_message">Avbokningsmail</label></th><td><textarea class="large-text" rows="7" id="eka_cancel_message" name="eka_cancel_message"><?php echo esc_textarea(get_option('eka_cancel_message', self::default_cancel_message())); ?></textarea><p class="description">Platshållare: <code>{name}</code>, <code>{course}</code>, <code>{session}</code>, <code>{all_dates}</code>, <code>{participants}</code>, <code>{total}</code>, <code>{deposit}</code>, <code>{swish_number}</code>, <code>{reference}</code>.</p></td></tr>
        </table><?php submit_button(); ?></form>
        <p><strong>Shortcodes:</strong><br><code>[enkel_kurslista]</code> visar kurskort.<br><code>[enkel_kursdatumlista]</code> visar en kompakt lista med kalenderikon, startdatum och starttid.</p></div>
    <?php }

    public static function registrations_page() {
        global $wpdb;
        $rows = $wpdb->get_results("SELECT r.*, p.post_title FROM {$wpdb->prefix}" . self::TABLE . " r LEFT JOIN {$wpdb->posts} p ON p.ID=r.course_id ORDER BY r.created_at DESC LIMIT 500");
        ?><div class="wrap"><h1>Anmälningar</h1><?php if (!empty($_GET['eka_notice'])): ?><div class="notice notice-success"><p><?php echo esc_html(sanitize_text_field(wp_unslash($_GET['eka_notice']))); ?></p></div><?php endif; ?><table class="widefat striped"><thead><tr><th>Datum</th><th>Kurs / kursstart</th><th>Deltagare och kontakt</th><th>Adress</th><th>Belopp</th><th>Swish-referens</th><th>Betalning</th><th>Anmälan</th></tr></thead><tbody>
        <?php if (!$rows): ?><tr><td colspan="8">Inga anmälningar ännu.</td></tr><?php endif; ?>
        <?php foreach ($rows as $r): ?><tr>
        <td><?php echo esc_html($r->created_at); ?></td><td><strong><?php echo esc_html($r->post_title); ?></strong><br><?php echo esc_html(self::label_from_key($r->session_key)); ?></td>
        <td><?php foreach(self::attendee_list($r) as $i=>$person): ?><strong><?php echo esc_html(($i+1).'. '.$person['name']); ?></strong><br><a href="mailto:<?php echo esc_attr($person['email']); ?>"><?php echo esc_html($person['email']); ?></a><br><?php echo esc_html($person['phone']); ?><br><?php endforeach; ?></td><td><?php echo esc_html($r->address); ?><br><?php echo esc_html(trim($r->postal_code.' '.$r->city)); ?></td>
        <td><?php echo esc_html(number_format_i18n($r->deposit_amount, 2)); ?> kr av <?php echo esc_html(number_format_i18n($r->total_amount, 2)); ?> kr</td><td><code><?php echo esc_html($r->payment_reference); ?></code></td>
        <td><?php echo $r->payment_status === 'paid' ? '<strong style="color:#087b37">Betald</strong>' : '<strong>Väntar</strong>'; ?><br>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="eka_payment_status"><input type="hidden" name="id" value="<?php echo (int) $r->id; ?>"><input type="hidden" name="status" value="<?php echo $r->payment_status === 'paid' ? 'pending' : 'paid'; ?>"><?php wp_nonce_field('eka_status_' . $r->id); ?><button class="button button-small"><?php echo $r->payment_status === 'paid' ? 'Ångra' : 'Markera betald'; ?></button></form></td>
        <td><?php if ($r->registration_status === 'cancelled'): ?><strong style="color:#a00">Avbokad</strong><?php else: ?><strong style="color:#087b37">Aktiv</strong><br><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('Avboka deltagaren och skicka avbokningsmail?');"><input type="hidden" name="action" value="eka_cancel_registration"><input type="hidden" name="id" value="<?php echo (int) $r->id; ?>"><?php wp_nonce_field('eka_cancel_' . $r->id); ?><button class="button button-small">Avboka</button></form><?php endif; ?></td>
        </tr><?php endforeach; ?></tbody></table></div><?php
    }

    public static function course_mail_page() {
        $courses = get_posts(['post_type'=>'eka_course','post_status'=>'publish','numberposts'=>-1,'orderby'=>'title','order'=>'ASC']);
        ?><div class="wrap"><h1>Skicka kursmail</h1><p>Utskicket går till alla aktiva anmälningar på kursen. Varje mottagare får ett separat mail och avbokade anmälningar inkluderas inte.</p>
        <?php if (isset($_GET['sent'])): ?><div class="notice notice-success"><p>Utskicket är klart. <?php echo absint($_GET['sent']); ?> mail skickades.</p></div><?php endif; ?>
        <?php foreach ($courses as $course): foreach (self::sessions($course->ID) as $session): $key=self::session_key($session); $count=self::recipient_count($course->ID,$key); ?>
        <div class="card" style="max-width:850px"><h2><?php echo esc_html($course->post_title); ?></h2><p><strong>Kursstart: <?php echo esc_html(self::session_label($session)); ?></strong> · <?php echo (int)$count; ?> mottagare</p><details><summary>Visa alla datum som ingår</summary><ul><?php foreach(self::course_schedule_labels($course->ID) as $label): ?><li><?php echo esc_html($label); ?></li><?php endforeach; ?></ul></details>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="eka_send_session_mail"><input type="hidden" name="course_id" value="<?php echo (int)$course->ID; ?>"><input type="hidden" name="session" value="<?php echo esc_attr($key); ?>"><?php wp_nonce_field('eka_session_mail_' . $course->ID . '_' . md5($key)); ?>
        <p><label><strong>Ämne</strong><br><input class="large-text" name="subject" required></label></p><p><label><strong>Meddelande</strong><br><textarea class="large-text" name="message" rows="7" required></textarea></label></p><p class="description">Platshållare: <code>{name}</code>, <code>{course}</code>, <code>{session}</code>, <code>{all_dates}</code>, <code>{participants}</code>.</p>
        <p><button class="button button-primary" <?php disabled($count,0); ?>>Skicka till <?php echo (int)$count; ?> mottagare</button></p></form></div>
        <?php endforeach; endforeach; ?></div><?php
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

    public static function handle_cancel_registration() {
        if (!current_user_can('manage_options')) wp_die('Behörighet saknas.');
        $id = absint($_POST['id'] ?? 0);
        check_admin_referer('eka_cancel_' . $id);
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d", $id));
        if (!$row || $row->registration_status === 'cancelled') wp_die('Anmälan hittades inte eller är redan avbokad.');
        $wpdb->update($table, ['registration_status'=>'cancelled'], ['id'=>$id], ['%s'], ['%d']);
        $mailed=true; $sent_to=[];
        foreach(self::attendee_list($row) as $person) { $key=strtolower($person['email']); if(isset($sent_to[$key])) continue; $sent_to[$key]=true; $data=self::mail_data($row,$person); if(!wp_mail($person['email'],self::render_template(get_option('eka_cancel_subject','Avbokning: {course}'),$data),self::render_template(get_option('eka_cancel_message',self::default_cancel_message()),$data))) $mailed=false; }
        $notice = $mailed ? 'Anmälan avbokades och avbokningsmail skickades till deltagarna.' : 'Anmälan avbokades, men minst ett mail kunde inte skickas.';
        wp_safe_redirect(add_query_arg('eka_notice', $notice, admin_url('edit.php?post_type=eka_course&page=eka-registrations')));
        exit;
    }

    public static function handle_session_mail() {
        if (!current_user_can('manage_options')) wp_die('Behörighet saknas.');
        $course_id = absint($_POST['course_id'] ?? 0);
        $session = sanitize_text_field(wp_unslash($_POST['session'] ?? ''));
        check_admin_referer('eka_session_mail_' . $course_id . '_' . md5($session));
        $subject = sanitize_text_field(wp_unslash($_POST['subject'] ?? ''));
        $message = sanitize_textarea_field(wp_unslash($_POST['message'] ?? ''));
        if (!$subject || !$message || get_post_type($course_id) !== 'eka_course') wp_die('Utskicket saknar giltigt innehåll.');
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}" . self::TABLE . " WHERE course_id=%d AND session_key=%s AND registration_status='active' ORDER BY id",$course_id,$session));
        $sent = 0; $sent_to=[];
        foreach ($rows as $row) foreach(self::attendee_list($row) as $person) { $key=strtolower($person['email']); if(isset($sent_to[$key])) continue; $sent_to[$key]=true; $data=self::mail_data($row,$person); if (wp_mail($person['email'],self::render_template($subject,$data),self::render_template($message,$data))) $sent++; }
        wp_safe_redirect(add_query_arg('sent',$sent,admin_url('edit.php?post_type=eka_course&page=eka-course-mail')));
        exit;
    }

    private static function default_welcome_message() {
        return "Hej {name}!\n\nVälkommen till {course}.\nKursstart: {session}\n\nAlla datum som ingår:\n{all_dates}\n\nAntal deltagare: {participants}\nSwisha {deposit} kr till {swish_number} och ange {reference} som meddelande.\nTotal kurskostnad: {total} kr.";
    }

    private static function default_cancel_message() {
        return "Hej {name}!\n\nDin anmälan till {course}, med kursstart {session}, har avbokats.\n\nKontakta oss om du har frågor om avbokningen eller eventuell återbetalning.";
    }

    private static function render_template($template, $data) { return strtr($template, $data); }

    private static function attendee_list($row) {
        $people=json_decode((string)($row->attendee_data ?? ''),true);
        if(!is_array($people) || !$people) $people=[['name'=>$row->name,'email'=>$row->email,'phone'=>$row->phone]];
        return array_values(array_filter($people,function($person){ return !empty($person['name']) && !empty($person['email']) && !empty($person['phone']); }));
    }

    private static function mail_data($row,$person=null) {
        $person=$person ?: self::attendee_list($row)[0];
        return ['{name}'=>$person['name'],'{course}'=>get_the_title($row->course_id),'{session}'=>self::label_from_key($row->session_key),'{all_dates}'=>implode("\n",self::course_schedule_labels($row->course_id)),'{participants}'=>(string)$row->participants,'{total}'=>number_format_i18n($row->total_amount,2),'{deposit}'=>number_format_i18n($row->deposit_amount,2),'{swish_number}'=>(string)get_option('eka_swish_number'),'{reference}'=>$row->payment_reference];
    }

    public static function assets() {
        wp_register_style('eka-style', plugins_url('assets/eka.css', __FILE__), [], self::VERSION);
        wp_enqueue_style('eka-style');
        if (is_singular('eka_course')) wp_enqueue_script('eka-front',plugins_url('assets/eka-front.js',__FILE__),[],self::VERSION,true);
    }

    public static function admin_assets($hook) {
        if (($hook === 'post.php' || $hook === 'post-new.php') && get_current_screen() && get_current_screen()->post_type === 'eka_course') {
            wp_enqueue_script('eka-admin', plugins_url('assets/eka-admin.js', __FILE__), [], self::VERSION, true);
        }
    }

    private static function legacy_sessions($course_id) {
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

    private static function course_start($course_id) {
        if (get_post_meta($course_id,'_eka_schedule_model',true)==='single-course') return ['date'=>(string)get_post_meta($course_id,'_eka_start_date',true),'start'=>(string)get_post_meta($course_id,'_eka_start_time',true),'end'=>(string)get_post_meta($course_id,'_eka_end_time',true),'max'=>max(1,(int)get_post_meta($course_id,'_eka_max_participants',true))];
        $legacy=self::legacy_sessions($course_id);
        return $legacy ? $legacy[0] : ['date'=>'','start'=>'','end'=>'','max'=>12];
    }

    private static function additional_dates($course_id) {
        if (get_post_meta($course_id,'_eka_schedule_model',true)==='single-course') {
            $dates=json_decode((string)get_post_meta($course_id,'_eka_additional_dates',true),true);
            return is_array($dates) ? $dates : [];
        }
        return array_slice(self::legacy_sessions($course_id),1);
    }

    private static function sessions($course_id) {
        $start=self::course_start($course_id);
        return !empty($start['date']) && !empty($start['start']) ? [$start] : [];
    }

    private static function course_schedule_labels($course_id) {
        $dates=[];
        foreach (array_merge(self::sessions($course_id),self::additional_dates($course_id)) as $date) $dates[]=self::session_label($date);
        return $dates;
    }

    private static function session_key($s) { return $s['date'] . '|' . $s['start'] . '|' . ($s['end'] ?? ''); }
    private static function session_label($s) {
        $date = wp_date(get_option('date_format'), strtotime($s['date']));
        return $date . ' kl. ' . $s['start'] . (!empty($s['end']) ? '–' . $s['end'] : '');
    }
    private static function label_from_key($key) {
        $parts = explode('|', $key);
        if (count($parts) < 2 || !preg_match('/^\d{4}-\d{2}-\d{2}$/',$parts[0])) return $key;
        return self::session_label(['date'=>$parts[0],'start'=>$parts[1],'end'=>$parts[2] ?? '']);
    }
    private static function booked_count($course_id, $key) {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(participants),0) FROM {$wpdb->prefix}" . self::TABLE . " WHERE course_id=%d AND session_key=%s AND registration_status='active'", $course_id, $key));
    }
    private static function recipient_count($course_id,$key) {
        global $wpdb;
        $rows=$wpdb->get_results($wpdb->prepare("SELECT name,email,phone,attendee_data FROM {$wpdb->prefix}" . self::TABLE . " WHERE course_id=%d AND session_key=%s AND registration_status='active'",$course_id,$key));
        $emails=[]; foreach($rows as $row) foreach(self::attendee_list($row) as $person) $emails[strtolower($person['email'])]=true;
        return count($emails);
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
        $start = $sessions ? $sessions[0] : null;
        ob_start(); ?>
        <section class="eka-registration"><h2>Anmälan</h2><p><strong>Kostnad:</strong> <?php echo esc_html(number_format_i18n($price, 2)); ?> kr per deltagare</p>
        <?php if (!$sessions): ?><p>Kursen saknar bokningsbart startdatum just nu.</p><?php else: ?>
        <div class="eka-course-dates"><h3>Datum som ingår i kursen</h3><ul><?php foreach (self::course_schedule_labels($course_id) as $i=>$label): ?><li><?php if ($i===0): ?><strong>Kursstart: <?php echo esc_html($label); ?></strong><?php else: ?><?php echo esc_html($label); ?><?php endif; ?></li><?php endforeach; ?></ul></div>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="eka-form">
        <?php $key=self::session_key($start); $left=max(0,(int)$start['max']-self::booked_count($course_id,$key)); ?>
        <input type="hidden" name="action" value="eka_register"><input type="hidden" name="course_id" value="<?php echo (int) $course_id; ?>"><input type="hidden" name="session" value="<?php echo esc_attr($key); ?>"><?php wp_nonce_field('eka_register_' . $course_id, 'eka_nonce'); ?>
        <p><strong><?php echo $left ? esc_html($left . ' platser kvar') : 'Fullbokad'; ?></strong></p>
        <fieldset class="eka-person"><legend>Person 1 – den som anmäler</legend><label>Namn<input name="name" autocomplete="name" required></label><label>E-post<input type="email" name="email" autocomplete="email" required></label><label>Telefon<input type="tel" name="phone" autocomplete="tel" required></label><label>Adress<input name="address" autocomplete="street-address" required></label><label>Postnummer<input name="postal_code" autocomplete="postal-code" required></label><label>Ort<input name="city" autocomplete="address-level2" required></label></fieldset>
        <label>Antal deltagare<input type="number" id="eka-participant-count" name="participants" value="1" min="1" max="20" required></label><div id="eka-additional-participants"></div><script type="text/template" id="eka-participant-template"><fieldset class="eka-person"><legend>Person __NUMBER__</legend><label>Namn<input name="additional[__INDEX__][name]" required></label><label>E-post<input type="email" name="additional[__INDEX__][email]" required></label><label>Telefon<input type="tel" name="additional[__INDEX__][phone]" required></label></fieldset></script><noscript><p>JavaScript behöver vara aktiverat för anmälan av fler än en deltagare.</p></noscript>
        <label class="eka-check"><input type="checkbox" name="privacy" value="1" required> Jag godkänner att uppgifterna sparas för att hantera anmälan och intygar att jag får lämna övriga deltagares kontaktuppgifter.</label>
        <button class="eka-button" type="submit" <?php disabled($left,0); ?>><?php echo $left ? 'Anmäl till hela kursen' : 'Kursen är fullbokad'; ?></button></form><?php endif; ?></section>
        <?php return $content . ob_get_clean();
    }

    public static function handle_registration() {
        $course_id = absint($_POST['course_id'] ?? 0);
        if (!$course_id || get_post_type($course_id) !== 'eka_course' || !isset($_POST['eka_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['eka_nonce'])), 'eka_register_' . $course_id)) wp_die('Ogiltig förfrågan.');
        if (empty($_POST['privacy'])) wp_die('Du måste godkänna hanteringen av personuppgifter.');
        $session = sanitize_text_field(wp_unslash($_POST['session'] ?? ''));
        $selected = null;
        foreach (self::sessions($course_id) as $candidate) if (hash_equals(self::session_key($candidate), $session)) { $selected = $candidate; break; }
        if (!$selected) wp_die('Kursstarten är inte giltig.');
        $name = sanitize_text_field(wp_unslash($_POST['name'] ?? '')); $email = sanitize_email(wp_unslash($_POST['email'] ?? '')); $phone = sanitize_text_field(wp_unslash($_POST['phone'] ?? ''));
        $address=sanitize_text_field(wp_unslash($_POST['address'] ?? '')); $postal_code=sanitize_text_field(wp_unslash($_POST['postal_code'] ?? '')); $city=sanitize_text_field(wp_unslash($_POST['city'] ?? ''));
        $participants = min(20, max(1, absint($_POST['participants'] ?? 1)));
        if (!$name || !is_email($email) || !$phone || !$address || !$postal_code || !$city) wp_die('Kontrollera kontakt- och adressuppgifterna. Alla fält är obligatoriska.');
        $attendees=[['name'=>$name,'email'=>$email,'phone'=>$phone]];
        $additional=array_values((array)($_POST['additional'] ?? []));
        for($i=0;$i<$participants-1;$i++) {
            $person=(array)($additional[$i] ?? []);
            $person_name=sanitize_text_field(wp_unslash($person['name'] ?? ''));
            $person_email=sanitize_email(wp_unslash($person['email'] ?? ''));
            $person_phone=sanitize_text_field(wp_unslash($person['phone'] ?? ''));
            if(!$person_name || !is_email($person_email) || !$person_phone) wp_die('Namn, e-post och telefon måste anges för samtliga deltagare.');
            $attendees[]=['name'=>$person_name,'email'=>$person_email,'phone'=>$person_phone];
        }
        $available = max(0, (int) $selected['max'] - self::booked_count($course_id, $session));
        if ($participants > $available) wp_die('Det finns inte tillräckligt många platser kvar på kursen.');
        $price = (float) get_post_meta($course_id, '_eka_price', true); $total = round($price * $participants, 2);
        $percent = min(100, max(0, (float) get_option('eka_deposit_percent', 20))); $deposit = round($total * $percent / 100, 2);
        $reference = 'KURS-' . strtoupper(wp_generate_password(8, false, false));
        global $wpdb;
        $ok = $wpdb->insert($wpdb->prefix . self::TABLE, ['course_id'=>$course_id,'session_key'=>$session,'name'=>$name,'email'=>$email,'phone'=>$phone,'address'=>$address,'postal_code'=>$postal_code,'city'=>$city,'attendee_data'=>wp_json_encode($attendees),'participants'=>$participants,'total_amount'=>$total,'deposit_amount'=>$deposit,'payment_reference'=>$reference,'payment_status'=>'pending','registration_status'=>'active','created_at'=>current_time('mysql')], ['%d','%s','%s','%s','%s','%s','%s','%s','%s','%d','%f','%f','%s','%s','%s','%s']);
        if (!$ok) wp_die('Anmälan kunde inte sparas. Försök igen.');
        $title = get_the_title($course_id);
        $session_label = self::session_label($selected);
        $all_dates=implode("\n",self::course_schedule_labels($course_id));
        $attendee_lines=[]; foreach($attendees as $i=>$person) $attendee_lines[]='Person '.($i+1).': '.$person['name'].', '.$person['email'].', '.$person['phone'];
        $message = "Ny kursanmälan\n\nKurs: $title\nKursstart: $session_label\nAlla datum:\n$all_dates\n\n".implode("\n",$attendee_lines)."\nAdress för den som anmäler: $address, $postal_code $city\nDeltagare: $participants\nHandpenning: " . number_format_i18n($deposit, 2) . " kr\nSwish-referens: $reference";
        wp_mail(get_option('eka_admin_email', get_option('admin_email')), 'Ny kursanmälan: ' . $title, $message);
        $mail_row=(object)['name'=>$name,'email'=>$email,'phone'=>$phone,'course_id'=>$course_id,'session_key'=>$session,'participants'=>$participants,'total_amount'=>$total,'deposit_amount'=>$deposit,'payment_reference'=>$reference,'attendee_data'=>wp_json_encode($attendees)];
        $welcome_sent=[]; foreach($attendees as $person) { $email_key=strtolower($person['email']); if(isset($welcome_sent[$email_key])) continue; $welcome_sent[$email_key]=true; $mail_data=self::mail_data($mail_row,$person); wp_mail($person['email'],self::render_template(get_option('eka_welcome_subject','Välkommen till {course}'),$mail_data),self::render_template(get_option('eka_welcome_message',self::default_welcome_message()),$mail_data)); }
        $url = add_query_arg(['eka_thanks'=>1,'ref'=>$reference], get_permalink($course_id));
        wp_safe_redirect($url); exit;
    }
}

register_activation_hook(__FILE__, ['EKA_Plugin', 'activate']);
register_deactivation_hook(__FILE__, ['EKA_Plugin', 'deactivate']);
add_action('plugins_loaded', ['EKA_Plugin', 'upgrade_database']);
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
