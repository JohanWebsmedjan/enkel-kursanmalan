<?php
if (!defined('ABSPATH')) exit;

/** Administration: kursöversikt, kursens anmälningslista, utskick, maillista och inställningar. */
final class EKA_Admin {
    const PER_PAGE = 50;
    const AUDIENCES = [
        'registered' => 'Anmälda deltagare (ej avbokade)',
        'list' => 'Kursens maillista',
        'newsletter' => 'Maillistan – endast de som vill ha information om kommande kurser',
    ];

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'assets']);
        foreach (['payment_status', 'delete_registration', 'export', 'send_mail', 'delete_subscriber', 'export_subscribers'] as $a) {
            add_action('admin_post_eka_' . $a, [__CLASS__, 'handle_' . $a]);
        }
    }

    public static function course_url($course_id, $args = []) {
        return add_query_arg(array_merge(['post_type' => 'eka_course', 'page' => 'eka-registrations', 'course_id' => (int) $course_id], $args), admin_url('edit.php'));
    }

    public static function menu() {
        add_submenu_page('edit.php?post_type=eka_course', 'Anmälningar', 'Anmälningar', 'edit_posts', 'eka-registrations', [__CLASS__, 'registrations_page']);
        add_submenu_page('edit.php?post_type=eka_course', 'Inställningar', 'Inställningar', 'manage_options', 'eka-settings', [__CLASS__, 'settings_page']);
    }

    public static function assets($hook) {
        if (strpos((string) $hook, 'eka-') !== false) wp_enqueue_style('eka-admin', EKA_URL . 'assets/eka-admin.css', [], EKA_VERSION);
    }

    private static function require_course_access($course_id) {
        if (!$course_id || get_post_type($course_id) !== 'eka_course' || !current_user_can('edit_post', $course_id)) wp_die('Behörighet saknas.');
    }

    private static function back($fallback) {
        wp_safe_redirect(wp_get_referer() ?: $fallback);
        exit;
    }

    /* ---------------- Inställningar ---------------- */

    public static function register_settings() {
        register_setting('eka_settings', 'eka_swish_number', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('eka_settings', 'eka_deposit_percent', ['sanitize_callback' => function($v) { return min(100, max(0, (float) str_replace(',', '.', (string) $v))); }]);
        register_setting('eka_settings', 'eka_admin_email', ['sanitize_callback' => 'sanitize_email']);
        register_setting('eka_settings', 'eka_welcome_subject', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('eka_settings', 'eka_welcome_body', ['sanitize_callback' => 'wp_kses_post']);
        register_setting('eka_settings', 'eka_tnp_list', ['sanitize_callback' => 'absint']);
        register_setting('eka_settings', EKA_Display::OPTION, ['sanitize_callback' => 'absint']);
    }

    public static function settings_page() { ?>
        <div class="wrap"><h1>Inställningar för kursanmälan</h1><form method="post" action="options.php">
        <?php settings_fields('eka_settings'); ?>
        <h2>Kurssidor</h2>
        <table class="form-table">
        <tr><th><label for="<?php echo esc_attr(EKA_Display::OPTION); ?>">Visningssida</label></th><td>
            <?php echo EKA_Display::page_dropdown(EKA_Display::OPTION, get_option(EKA_Display::OPTION, 0), '– Ingen, använd temats mall för kurser –'); // phpcs:ignore WordPress.Security.EscapeOutput ?>
            <p class="description">En vanlig sida som används som mall när en kurs öppnas. Sätt <code>[enkel_kurs]</code> där kursen ska visas. Kurserna behåller sina egna adresser. En kategori kan ha en egen visningssida.</p></td></tr>
        </table>
        <h2>Betalning</h2>
        <table class="form-table">
        <tr><th><label for="eka_swish_number">Swish-nummer</label></th><td><input class="regular-text" id="eka_swish_number" name="eka_swish_number" value="<?php echo esc_attr(get_option('eka_swish_number')); ?>" required></td></tr>
        <tr><th><label for="eka_deposit_percent">Standard för föranmälningsavgift (%)</label></th><td><input type="number" min="0" max="100" step="0.1" id="eka_deposit_percent" name="eka_deposit_percent" value="<?php echo esc_attr(get_option('eka_deposit_percent', 20)); ?>">
            <p class="description">Gäller kurser utan kategori och kategorier utan egen regel. <a href="<?php echo esc_url(admin_url('edit-tags.php?taxonomy=eka_category&post_type=eka_course')); ?>">Hantera kategorier</a></p></td></tr>
        </table>
        <h2>E-post</h2>
        <table class="form-table">
        <tr><th><label for="eka_admin_email">Aviseringsadress</label></th><td><input type="email" class="regular-text" id="eka_admin_email" name="eka_admin_email" value="<?php echo esc_attr(get_option('eka_admin_email', get_option('admin_email'))); ?>">
            <p class="description">Får avisering om nya anmälningar till kurser som saknar kursansvarig.</p></td></tr>
        <tr><th><label for="eka_welcome_subject">Standardmall för välkomstmail</label></th><td>
            <input type="text" class="large-text" id="eka_welcome_subject" name="eka_welcome_subject" value="<?php echo esc_attr(EKA_Mailer::default_welcome_subject()); ?>">
            <?php wp_editor(EKA_Mailer::default_welcome_body(), 'eka_welcome_body', ['textarea_name' => 'eka_welcome_body', 'textarea_rows' => 10, 'media_buttons' => false]); ?>
            <p class="description">Används för kurser som inte har ett eget välkomstmail.</p>
            <?php EKA_Mailer::placeholder_help(); ?>
        </td></tr>
        </table>
        <?php EKA_Newsletter::settings_fields(); ?>
        <?php submit_button(); ?></form>
        <?php EKA_Newsletter::sync_form(); ?>
        <h2>Shortcodes</h2>
        <p><code>[enkel_kurslista]</code> visar en rad per kurs med nästa tillfälle och pris. <code>[enkel_kursdatumlista]</code> visar en rad per kommande kurstillfälle i datumordning. Båda har samma utseende.<br>
        Båda kan begränsas till en kategori: <code>[enkel_kurslista kategori="slug"]</code>.</p>
        <p><code>[enkel_kurs]</code> används på visningssidan och visar kursens beskrivning, samarbetspartner, informationssidor och anmälningsformulär.<br>
        Delarna kan placeras var för sig med <code>del</code>: <code>titel</code>, <code>bild</code>, <code>beskrivning</code>, <code>utdrag</code>, <code>pris</code>, <code>tillfallen</code>, <code>partners</code>, <code>information</code> och <code>formular</code>, till exempel <code>[enkel_kurs del="bild,pris"]</code>.<br>
        På en annan sida kan en viss kurs visas med <code>[enkel_kurs id="123"]</code>.</p>
        <h2>Personnummer</h2>
        <p>Personnummer sparas krypterat i databasen. Nyckeln hämtas från <code>EKA_ENCRYPTION_KEY</code> i <code>wp-config.php</code> om den finns, annars från webbplatsens säkerhetsnycklar (<code>AUTH_KEY</code>, <code>SECURE_AUTH_KEY</code>).
        Byts nyckeln går redan sparade personnummer inte längre att läsa. Status: <strong><?php echo defined('EKA_ENCRYPTION_KEY') ? 'egen nyckel används' : 'webbplatsens säkerhetsnycklar används'; ?></strong>.</p>
        </div>
    <?php }

    /* ---------------- Översikt och kurssida ---------------- */

    public static function registrations_page() {
        $course_id = absint($_GET['course_id'] ?? 0);
        if ($course_id) {
            self::require_course_access($course_id);
            self::course_page($course_id);
        } else {
            self::overview_page();
        }
    }

    private static function overview_page() {
        global $wpdb;
        $t = EKA_DB::table('registrations');
        $courses = get_posts(['post_type' => 'eka_course', 'post_status' => ['publish', 'draft', 'private', 'future'], 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC']);
        $stats = [];
        foreach ($wpdb->get_results("SELECT course_id, SUM(CASE WHEN payment_status<>'cancelled' THEN participants ELSE 0 END) booked, SUM(payment_status='pending') unpaid FROM $t GROUP BY course_id") as $r) $stats[(int) $r->course_id] = $r;
        ?>
        <div class="wrap"><h1>Anmälningar</h1><p>Välj en kurs för att se dess anmälningslista, skicka mail och hantera maillistan.</p>
        <table class="widefat striped"><thead><tr><th>Kurs</th><th>Kategori</th><th>Kursansvarig</th><th>Kommande tillfällen</th><th>Aktiva deltagare</th><th>Ej betalda anmälningar</th></tr></thead><tbody>
        <?php $shown = 0; foreach ($courses as $c): if (!current_user_can('edit_post', $c->ID)) continue; $shown++;
            $cat = EKA_Core::course_category($c->ID); $user = EKA_Core::responsible_user($c->ID); $s = $stats[$c->ID] ?? null; ?>
        <tr><td><strong><a href="<?php echo esc_url(self::course_url($c->ID)); ?>"><?php echo esc_html($c->post_title ?: '(utan rubrik)'); ?></a></strong><?php echo $c->post_status !== 'publish' ? ' – ' . esc_html(get_post_status_object($c->post_status)->label) : ''; ?></td>
        <td><?php echo $cat ? esc_html($cat->name) : '–'; ?></td><td><?php echo $user ? esc_html($user->display_name) : '–'; ?></td>
        <td><?php echo count(EKA_Core::upcoming_sessions($c->ID)); ?></td><td><?php echo (int) ($s->booked ?? 0); ?></td><td><?php echo (int) ($s->unpaid ?? 0); ?></td></tr>
        <?php endforeach; if (!$shown): ?><tr><td colspan="6">Inga kurser ännu.</td></tr><?php endif; ?>
        </tbody></table></div>
    <?php }

    private static function course_page($course_id) {
        $tab = sanitize_key($_GET['tab'] ?? 'registrations');
        $tabs = ['registrations' => 'Anmälningslista', 'mail' => 'Skicka mail', 'list' => 'Maillista'];
        if (!isset($tabs[$tab])) $tab = 'registrations';
        ?>
        <div class="wrap eka-course-admin">
        <h1 class="wp-heading-inline"><?php echo esc_html(get_the_title($course_id)); ?></h1>
        <a class="page-title-action" href="<?php echo esc_url(get_edit_post_link($course_id)); ?>">Redigera kurs</a>
        <a class="page-title-action" href="<?php echo esc_url(get_permalink($course_id)); ?>">Visa kurssida</a>
        <p><a href="<?php echo esc_url(admin_url('edit.php?post_type=eka_course&page=eka-registrations')); ?>">← Alla kurser</a></p>
        <nav class="nav-tab-wrapper"><?php foreach ($tabs as $k => $label): ?><a class="nav-tab <?php echo $k === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url(self::course_url($course_id, ['tab' => $k])); ?>"><?php echo esc_html($label); ?></a><?php endforeach; ?></nav>
        <?php
        if ($tab === 'mail') self::tab_mail($course_id);
        elseif ($tab === 'list') self::tab_list($course_id);
        else self::tab_registrations($course_id);
        echo '</div>';
    }

    /* ---------------- Flik: anmälningslista ---------------- */

    private static function filters() {
        $status = sanitize_key(wp_unslash($_GET['status'] ?? ''));
        return [
            'session' => sanitize_text_field(wp_unslash($_GET['session'] ?? '')),
            'status' => isset(EKA_Core::STATUSES[$status]) ? $status : '',
        ];
    }

    private static function filters_where($course_id, $f) {
        global $wpdb;
        $sql = $wpdb->prepare('course_id=%d', $course_id);
        if ($f['session'] !== '') $sql .= $wpdb->prepare(' AND session_key=%s', $f['session']);
        if ($f['status']) $sql .= $wpdb->prepare(' AND payment_status=%s', $f['status']);
        return $sql;
    }

    private static function tab_registrations($course_id) {
        global $wpdb;
        $t = EKA_DB::table('registrations');
        $f = self::filters();
        $where = self::filters_where($course_id, $f);
        $paged = max(1, absint($_GET['paged'] ?? 1));
        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM $t WHERE $where");
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $t WHERE $where ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d", self::PER_PAGE, ($paged - 1) * self::PER_PAGE));
        $participants = EKA_Core::participants_for(wp_list_pluck($rows, 'id'));
        $pnr = EKA_Core::requires_personal_number($course_id);

        // Sammanställning per kurstillfälle, inklusive tillfällen som bara finns kvar i gamla anmälningar.
        $summary = [];
        foreach (EKA_Core::sessions($course_id) as $s) $summary[EKA_Core::session_key($s)] = ['max' => (int) $s['max'], 'booked' => 0, 'unpaid' => 0];
        foreach ($wpdb->get_results($wpdb->prepare("SELECT session_key, SUM(CASE WHEN payment_status<>'cancelled' THEN participants ELSE 0 END) booked, SUM(payment_status='pending') unpaid FROM $t WHERE course_id=%d GROUP BY session_key", $course_id)) as $r) {
            $summary[$r->session_key] = ['max' => $summary[$r->session_key]['max'] ?? null, 'booked' => (int) $r->booked, 'unpaid' => (int) $r->unpaid];
        }
        uksort($summary, 'strcmp');
        $export_url = wp_nonce_url(add_query_arg(array_filter(['action' => 'eka_export', 'course_id' => $course_id] + $f), admin_url('admin-post.php')), 'eka_export_' . $course_id);
        ?>
        <h2>Kurstillfällen</h2>
        <table class="widefat striped eka-summary"><thead><tr><th>Tillfälle</th><th>Bokade platser</th><th>Ej betalda anmälningar</th><th></th></tr></thead><tbody>
        <?php if (!$summary): ?><tr><td colspan="4">Kursen har inga kurstillfällen.</td></tr><?php endif; ?>
        <?php foreach ($summary as $key => $s): ?>
        <tr><td><?php echo esc_html(EKA_Core::key_label($key)); ?><?php echo $s['max'] === null ? ' <em>(finns inte längre på kursen)</em>' : ''; ?></td>
        <td><?php echo (int) $s['booked'] . ($s['max'] !== null ? ' av ' . (int) $s['max'] : ''); ?></td><td><?php echo (int) $s['unpaid']; ?></td>
        <td><a href="<?php echo esc_url(self::course_url($course_id, ['session' => $key])); ?>">Visa anmälningar</a></td></tr>
        <?php endforeach; ?></tbody></table>

        <h2>Anmälningar <a class="page-title-action" href="<?php echo esc_url($export_url); ?>">Exportera CSV</a></h2>
        <form method="get" class="eka-filters"><input type="hidden" name="post_type" value="eka_course"><input type="hidden" name="page" value="eka-registrations"><input type="hidden" name="course_id" value="<?php echo (int) $course_id; ?>">
            <select name="session"><option value="">Alla tillfällen</option><?php foreach (array_keys($summary) as $k): ?><option value="<?php echo esc_attr($k); ?>" <?php selected($f['session'], $k); ?>><?php echo esc_html(EKA_Core::key_label($k)); ?></option><?php endforeach; ?></select>
            <select name="status"><option value="">Alla statusar</option><?php foreach (EKA_Core::STATUSES as $k => $label): ?><option value="<?php echo esc_attr($k); ?>" <?php selected($f['status'], $k); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select>
            <button class="button">Filtrera</button>
            <?php if (array_filter($f)): ?><a href="<?php echo esc_url(self::course_url($course_id)); ?>">Rensa filter</a><?php endif; ?>
            <span class="eka-count"><?php echo esc_html(sprintf('%d anmälningar', $total)); ?></span>
        </form>
        <table class="widefat striped"><thead><tr><th>Anmäld</th><th>Tillfälle</th><th>Kontaktperson</th><th>Deltagare</th><th>Belopp</th><th>Swish-referens</th><th>Status</th><th>Åtgärder</th></tr></thead><tbody>
        <?php if (!$rows): ?><tr><td colspan="8">Inga anmälningar hittades.</td></tr><?php endif; ?>
        <?php foreach ($rows as $r): $list = $participants[(int) $r->id] ?? []; ?>
        <tr class="<?php echo $r->payment_status === 'cancelled' ? 'eka-cancelled' : ''; ?>">
        <td><?php echo esc_html(mysql2date('Y-m-d H:i', $r->created_at)); ?></td>
        <td><?php echo esc_html(EKA_Core::key_label($r->session_key)); ?></td>
        <td><strong><?php echo esc_html($r->name); ?></strong><br><a href="mailto:<?php echo esc_attr($r->email); ?>"><?php echo esc_html($r->email); ?></a><br><?php echo esc_html($r->phone); ?></td>
        <td><?php echo (int) $r->participants; ?> st<?php if ($list): ?><ul class="eka-participant-list"><?php foreach ($list as $p): ?><li><?php echo esc_html($p['name']); ?><?php if ($p['pnr']): ?> <span class="eka-pnr"><?php echo esc_html(EKA_Core::mask_personal_number($p['pnr'])); ?></span><?php endif; ?></li><?php endforeach; ?></ul><?php elseif ($pnr): ?><br><em>Personnummer saknas</em><?php endif; ?></td>
        <td><?php echo esc_html(EKA_Core::money($r->deposit_amount)); ?> kr av <?php echo esc_html(EKA_Core::money($r->total_amount)); ?> kr</td>
        <td><code><?php echo esc_html($r->payment_reference); ?></code></td>
        <td><?php echo self::status_badge($r->payment_status); ?></td>
        <td><?php
            if ($r->payment_status === 'cancelled') {
                self::row_button($r->id, 'eka_payment_status', ['status' => 'pending'], 'Återaktivera');
            } else {
                self::row_button($r->id, 'eka_payment_status', ['status' => $r->payment_status === 'paid' ? 'pending' : 'paid'], $r->payment_status === 'paid' ? 'Ångra betald' : 'Markera betald');
                self::row_button($r->id, 'eka_payment_status', ['status' => 'cancelled'], 'Avboka', 'Avboka anmälan? Platserna frigörs för nya anmälningar.');
            }
            self::row_button($r->id, 'eka_delete_registration', [], 'Ta bort', 'Ta bort anmälan och dess deltagare permanent? Detta går inte att ångra.', 'button-link-delete');
        ?></td></tr>
        <?php endforeach; ?></tbody></table>
        <?php
        $pages = (int) ceil($total / self::PER_PAGE);
        if ($pages > 1) echo '<div class="tablenav"><div class="tablenav-pages">' . paginate_links(['base' => add_query_arg('paged', '%#%'), 'format' => '', 'current' => $paged, 'total' => $pages]) . '</div></div>';
    }

    private static function status_badge($status) {
        return '<span class="eka-status eka-status-' . esc_attr($status) . '">' . esc_html(EKA_Core::STATUSES[$status] ?? $status) . '</span>';
    }

    private static function row_button($id, $action, $fields, $label, $confirm = '', $class = 'button button-small') { ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="eka-inline-form"<?php if ($confirm): ?> onsubmit="return confirm(<?php echo esc_attr(wp_json_encode($confirm)); ?>)"<?php endif; ?>>
        <input type="hidden" name="action" value="<?php echo esc_attr($action); ?>"><input type="hidden" name="id" value="<?php echo (int) $id; ?>">
        <?php foreach ($fields as $k => $v): ?><input type="hidden" name="<?php echo esc_attr($k); ?>" value="<?php echo esc_attr($v); ?>"><?php endforeach; ?>
        <?php wp_nonce_field('eka_row_' . $action . '_' . $id); ?><button class="<?php echo esc_attr($class); ?>"><?php echo esc_html($label); ?></button></form>
    <?php }

    private static function registration_for_action($action) {
        global $wpdb;
        $id = absint($_POST['id'] ?? 0);
        check_admin_referer('eka_row_' . $action . '_' . $id);
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . EKA_DB::table('registrations') . ' WHERE id=%d', $id));
        if (!$row) wp_die('Anmälan hittades inte.');
        self::require_course_access((int) $row->course_id);
        return $row;
    }

    public static function handle_payment_status() {
        global $wpdb;
        $row = self::registration_for_action('eka_payment_status');
        $status = sanitize_key(wp_unslash($_POST['status'] ?? ''));
        if (!isset(EKA_Core::STATUSES[$status])) wp_die('Ogiltig status.');
        $t = EKA_DB::table('registrations');
        $back = self::course_url($row->course_id);

        // Återaktivering tar platser i anspråk igen och måste kontrolleras mot max antal.
        if ($row->payment_status === 'cancelled' && $status !== 'cancelled') {
            if (!EKA_Core::lock_session($row->course_id, $row->session_key)) { EKA_Core::add_notice('Kunde inte låsa kurstillfället. Försök igen.', 'error'); self::back($back); }
            $max = EKA_Core::session_max($row->course_id, $row->session_key);
            if ($max !== null && EKA_Core::booked_count($row->course_id, $row->session_key) + (int) $row->participants > $max) {
                EKA_Core::unlock_session($row->course_id, $row->session_key);
                EKA_Core::add_notice(sprintf('%s kunde inte återaktiveras: det finns inte tillräckligt många lediga platser. Höj max antal deltagare på kursen först.', $row->payment_reference), 'error');
                self::back($back);
            }
            $wpdb->update($t, ['payment_status' => $status], ['id' => $row->id], ['%s'], ['%d']);
            EKA_Core::unlock_session($row->course_id, $row->session_key);
        } else {
            $wpdb->update($t, ['payment_status' => $status], ['id' => $row->id], ['%s'], ['%d']);
        }
        EKA_Core::add_notice(sprintf('%s har nu status: %s.', $row->payment_reference, EKA_Core::STATUSES[$status]), 'success');
        self::back($back);
    }

    public static function handle_delete_registration() {
        $row = self::registration_for_action('eka_delete_registration');
        if (EKA_Core::delete_registration((int) $row->id)) EKA_Core::add_notice(sprintf('Anmälan %s togs bort.', $row->payment_reference), 'success');
        self::back(self::course_url($row->course_id));
    }

    public static function handle_export() {
        global $wpdb;
        $course_id = absint($_GET['course_id'] ?? 0);
        check_admin_referer('eka_export_' . $course_id);
        self::require_course_access($course_id);
        $where = self::filters_where($course_id, self::filters());
        $rows = $wpdb->get_results('SELECT * FROM ' . EKA_DB::table('registrations') . " WHERE $where ORDER BY session_key, created_at");
        $participants = EKA_Core::participants_for(wp_list_pluck($rows, 'id'));
        $pnr = EKA_Core::requires_personal_number($course_id);

        $header = ['Anmäld', 'Tillfälle', 'Kontaktperson', 'E-post', 'Telefon', 'Antal deltagare', 'Deltagare'];
        if ($pnr) $header[] = 'Personnummer';
        $header = array_merge($header, ['Totalbelopp', 'Att betala vid anmälan', 'Swish-referens', 'Status']);
        $out = [];
        foreach ($rows as $r) {
            $base = [$r->created_at, EKA_Core::key_label($r->session_key), $r->name, $r->email, $r->phone, (int) $r->participants];
            $tail = [number_format((float) $r->total_amount, 2, ',', ''), number_format((float) $r->deposit_amount, 2, ',', ''), $r->payment_reference, EKA_Core::STATUSES[$r->payment_status] ?? $r->payment_status];
            $list = $participants[(int) $r->id] ?? [];
            if ($pnr && $list) {
                // En rad per deltagare när personnummer samlas in, så att listan kan användas för intyg m.m.
                foreach ($list as $p) $out[] = array_merge($base, [$p['name'], EKA_Core::format_personal_number($p['pnr'])], $tail);
            } else {
                $row = array_merge($base, [implode(', ', wp_list_pluck($list, 'name'))]);
                if ($pnr) $row[] = '';
                $out[] = array_merge($row, $tail);
            }
        }
        EKA_Core::send_csv(sanitize_title(get_the_title($course_id)) . '-anmalningar-' . wp_date('Y-m-d') . '.csv', $header, $out);
    }

    /* ---------------- Flik: skicka mail ---------------- */

    private static function tab_mail($course_id) {
        global $wpdb;
        $sessions = $wpdb->get_col($wpdb->prepare('SELECT DISTINCT session_key FROM ' . EKA_DB::table('registrations') . " WHERE course_id=%d AND payment_status<>'cancelled' ORDER BY session_key", $course_id));
        $log = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . EKA_DB::table('mail_log') . ' WHERE course_id=%d ORDER BY sent_at DESC LIMIT 20', $course_id));
        $counts = [];
        foreach (array_keys(self::AUDIENCES) as $a) $counts[$a] = count(self::recipients($course_id, $a, ''));
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="eka-mail-form">
        <input type="hidden" name="action" value="eka_send_mail"><input type="hidden" name="course_id" value="<?php echo (int) $course_id; ?>">
        <?php wp_nonce_field('eka_send_mail_' . $course_id); ?>
        <table class="form-table">
        <tr><th>Mottagare</th><td>
            <?php $first = true; foreach (self::AUDIENCES as $k => $label): ?>
            <label class="eka-block"><input type="radio" name="audience" value="<?php echo esc_attr($k); ?>" <?php checked($first); $first = false; ?>> <?php echo esc_html($label); ?> (<?php echo (int) $counts[$k]; ?> st)</label>
            <?php endforeach; ?>
            <p><label>Begränsa anmälda till tillfälle: <select name="session"><option value="">Alla tillfällen</option><?php foreach ($sessions as $k): ?><option value="<?php echo esc_attr($k); ?>"><?php echo esc_html(EKA_Core::key_label($k)); ?></option><?php endforeach; ?></select></label></p>
            <p class="description">Utskick till maillistan får automatiskt en länk för att avsluta prenumerationen.</p>
        </td></tr>
        <tr><th><label for="eka_mail_subject">Ämne</label></th><td><input type="text" class="large-text" id="eka_mail_subject" name="subject" required></td></tr>
        <tr><th>Meddelande</th><td><?php wp_editor('', 'eka_mail_body', ['textarea_name' => 'body', 'textarea_rows' => 12, 'media_buttons' => true]); ?>
            <?php EKA_Mailer::placeholder_help(['{namn}', '{kurs}', '{kurslank}', '{informationssidor}']); ?></td></tr>
        <tr><th>Test</th><td><label><input type="checkbox" name="test" value="1" checked> Skicka bara ett testmail till mig (<?php echo esc_html(wp_get_current_user()->user_email); ?>)</label></td></tr>
        </table>
        <?php submit_button('Skicka', 'primary', 'submit', true, ['onclick' => "return this.form.test.checked || confirm('Skicka mailet till alla valda mottagare?')"]); ?>
        </form>

        <h2>Tidigare utskick</h2>
        <table class="widefat striped"><thead><tr><th>Skickat</th><th>Ämne</th><th>Mottagare</th><th>Antal</th><th>Misslyckade</th><th>Av</th></tr></thead><tbody>
        <?php if (!$log): ?><tr><td colspan="6">Inga utskick ännu.</td></tr><?php endif; ?>
        <?php foreach ($log as $l): $by = get_userdata((int) $l->sent_by); ?>
        <tr><td><?php echo esc_html(mysql2date('Y-m-d H:i', $l->sent_at)); ?></td><td><?php echo esc_html($l->subject); ?></td><td><?php echo esc_html(self::AUDIENCES[$l->audience] ?? $l->audience); ?></td>
        <td><?php echo (int) $l->recipients; ?></td><td><?php echo (int) $l->failed; ?></td><td><?php echo $by ? esc_html($by->display_name) : '–'; ?></td></tr>
        <?php endforeach; ?></tbody></table>
    <?php }

    /** Mottagare för ett utskick: [email => ['name' => ..., 'token' => ...|null]] */
    private static function recipients($course_id, $audience, $session) {
        global $wpdb;
        $out = [];
        if ($audience === 'registered') {
            $sql = $wpdb->prepare('SELECT name, email FROM ' . EKA_DB::table('registrations') . " WHERE course_id=%d AND payment_status<>'cancelled'", $course_id);
            if ($session !== '') $sql .= $wpdb->prepare(' AND session_key=%s', $session);
            foreach ($wpdb->get_results($sql . ' ORDER BY created_at') as $r) $out[strtolower($r->email)] = ['email' => $r->email, 'name' => $r->name, 'token' => null];
        } else {
            $sql = $wpdb->prepare('SELECT name, email, token FROM ' . EKA_DB::table('subscribers') . " WHERE course_id=%d AND status='subscribed'", $course_id);
            if ($audience === 'newsletter') $sql .= ' AND newsletter=1';
            foreach ($wpdb->get_results($sql) as $r) $out[strtolower($r->email)] = ['email' => $r->email, 'name' => $r->name, 'token' => $r->token];
        }
        return $out;
    }

    public static function handle_send_mail() {
        global $wpdb;
        $course_id = absint($_POST['course_id'] ?? 0);
        check_admin_referer('eka_send_mail_' . $course_id);
        self::require_course_access($course_id);
        $audience = sanitize_key($_POST['audience'] ?? '');
        if (!isset(self::AUDIENCES[$audience])) wp_die('Ogiltig mottagargrupp.');
        $session = sanitize_text_field(wp_unslash($_POST['session'] ?? ''));
        $subject = sanitize_text_field(wp_unslash($_POST['subject'] ?? ''));
        $body = wp_kses_post(wp_unslash($_POST['body'] ?? ''));
        $back = self::course_url($course_id, ['tab' => 'mail']);
        if ($subject === '' || trim(wp_strip_all_tags($body)) === '') { EKA_Core::add_notice('Ämne och meddelande måste fyllas i.', 'error'); self::back($back); }

        $base = ['{kurs}' => get_the_title($course_id), '{kurslank}' => get_permalink($course_id), '{informationssidor}' => EKA_Mailer::info_pages_html($course_id)];
        $list_mail = $audience !== 'registered';

        if (!empty($_POST['test'])) {
            $me = wp_get_current_user();
            $html = EKA_Mailer::render($body, $base + ['{namn}' => $me->display_name], ['{informationssidor}']);
            if ($list_mail) $html .= self::unsubscribe_footer('#');
            $ok = EKA_Mailer::send_html($me->user_email, '[TEST] ' . EKA_Mailer::render($subject, $base + ['{namn}' => $me->display_name]), $html);
            EKA_Core::add_notice($ok ? 'Testmail skickat till ' . $me->user_email . '. Bocka ur "Skicka bara ett testmail" för att skicka på riktigt.' : 'Testmailet kunde inte skickas. Kontrollera webbplatsens e-postinställningar.', $ok ? 'success' : 'error');
            self::back($back);
        }

        $recipients = self::recipients($course_id, $audience, $audience === 'registered' ? $session : '');
        if (!$recipients) { EKA_Core::add_notice('Det finns inga mottagare i den valda gruppen.', 'error'); self::back($back); }
        if (function_exists('set_time_limit')) @set_time_limit(0);
        $sent = 0; $failed = 0;
        foreach ($recipients as $r) {
            $values = $base + ['{namn}' => $r['name']];
            $html = EKA_Mailer::render($body, $values, ['{informationssidor}']);
            if ($list_mail && $r['token']) $html .= self::unsubscribe_footer(EKA_Mailer::unsubscribe_url($r['token']));
            EKA_Mailer::send_html($r['email'], EKA_Mailer::render($subject, $values), $html) ? $sent++ : $failed++;
        }
        $wpdb->insert(EKA_DB::table('mail_log'), ['course_id' => $course_id, 'subject' => $subject, 'audience' => $audience, 'recipients' => $sent, 'failed' => $failed, 'sent_by' => get_current_user_id(), 'sent_at' => current_time('mysql')], ['%d', '%s', '%s', '%d', '%d', '%d', '%s']);
        EKA_Core::add_notice(sprintf('Mailet skickades till %d mottagare.%s', $sent, $failed ? sprintf(' %d kunde inte skickas.', $failed) : ''), $failed ? 'warning' : 'success');
        self::back($back);
    }

    private static function unsubscribe_footer($url) {
        return '<hr style="border:0;border-top:1px solid #ddd;margin:24px 0 8px"><p style="font-size:12px;color:#666">Du får det här mailet eftersom du står på maillistan för kursen. <a href="' . esc_url($url) . '">Avsluta prenumerationen</a>.</p>';
    }

    /* ---------------- Flik: maillista ---------------- */

    private static function tab_list($course_id) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . EKA_DB::table('subscribers') . ' WHERE course_id=%d ORDER BY status, created_at DESC', $course_id));
        $export = wp_nonce_url(add_query_arg(['action' => 'eka_export_subscribers', 'course_id' => $course_id], admin_url('admin-post.php')), 'eka_export_subscribers_' . $course_id);
        ?>
        <p>Alla som anmäler sig läggs på kursens maillista. Kolumnen <em>Kommande kurser</em> visar vem som har samtyckt till information om framtida kurser.</p>
        <p><a class="button" href="<?php echo esc_url($export); ?>">Exportera CSV</a></p>
        <table class="widefat striped"><thead><tr><th>Namn</th><th>E-post</th><th>Kommande kurser</th><th>Status</th><th>Tillagd</th><th></th></tr></thead><tbody>
        <?php if (!$rows): ?><tr><td colspan="6">Maillistan är tom.</td></tr><?php endif; ?>
        <?php foreach ($rows as $r): ?>
        <tr class="<?php echo $r->status !== 'subscribed' ? 'eka-cancelled' : ''; ?>"><td><?php echo esc_html($r->name); ?></td><td><?php echo esc_html($r->email); ?></td>
        <td><?php echo $r->newsletter ? 'Ja' : 'Nej'; ?></td><td><?php echo $r->status === 'subscribed' ? 'Prenumererar' : 'Avslutad'; ?></td>
        <td><?php echo esc_html(mysql2date('Y-m-d', $r->created_at)); ?></td>
        <td><?php self::row_button($r->id, 'eka_delete_subscriber', [], 'Ta bort', 'Ta bort adressen från kursens maillista?', 'button-link-delete'); ?></td></tr>
        <?php endforeach; ?></tbody></table>
    <?php }

    public static function handle_delete_subscriber() {
        global $wpdb;
        $id = absint($_POST['id'] ?? 0);
        check_admin_referer('eka_row_eka_delete_subscriber_' . $id);
        $t = EKA_DB::table('subscribers');
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id=%d", $id));
        if (!$row) wp_die('Adressen hittades inte.');
        self::require_course_access((int) $row->course_id);
        $wpdb->delete($t, ['id' => $id], ['%d']);
        EKA_Core::add_notice(sprintf('%s togs bort från maillistan.', $row->email), 'success');
        self::back(self::course_url($row->course_id, ['tab' => 'list']));
    }

    public static function handle_export_subscribers() {
        global $wpdb;
        $course_id = absint($_GET['course_id'] ?? 0);
        check_admin_referer('eka_export_subscribers_' . $course_id);
        self::require_course_access($course_id);
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . EKA_DB::table('subscribers') . ' WHERE course_id=%d ORDER BY created_at', $course_id));
        $out = array_map(function($r) { return [$r->name, $r->email, $r->newsletter ? 'Ja' : 'Nej', $r->status === 'subscribed' ? 'Prenumererar' : 'Avslutad', $r->created_at]; }, $rows);
        EKA_Core::send_csv(sanitize_title(get_the_title($course_id)) . '-maillista-' . wp_date('Y-m-d') . '.csv', ['Namn', 'E-post', 'Information om kommande kurser', 'Status', 'Tillagd'], $out);
    }
}
