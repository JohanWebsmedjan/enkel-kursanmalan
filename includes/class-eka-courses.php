<?php
if (!defined('ABSPATH')) exit;

/** Kurser, kurskategorier med betalregler och kursens redigeringsrutor. */
final class EKA_Courses {

    public static function init() {
        add_action('init', [__CLASS__, 'register_types']);
        add_action('add_meta_boxes_eka_course', [__CLASS__, 'add_meta_boxes']);
        add_action('save_post_eka_course', [__CLASS__, 'save_course']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'admin_assets']);
        add_filter('post_row_actions', [__CLASS__, 'row_actions'], 10, 2);
        add_filter('manage_eka_course_posts_columns', [__CLASS__, 'columns']);
        add_action('manage_eka_course_posts_custom_column', [__CLASS__, 'column_content'], 10, 2);

        add_action('eka_category_add_form_fields', [__CLASS__, 'category_add_fields']);
        add_action('eka_category_edit_form_fields', [__CLASS__, 'category_edit_fields']);
        add_action('created_eka_category', [__CLASS__, 'save_category']);
        add_action('edited_eka_category', [__CLASS__, 'save_category']);
        add_filter('manage_edit-eka_category_columns', [__CLASS__, 'category_columns']);
        add_filter('manage_eka_category_custom_column', [__CLASS__, 'category_column_content'], 10, 3);
    }

    public static function register_types() {
        register_post_type('eka_course', [
            'labels' => ['name' => 'Kurser', 'singular_name' => 'Kurs', 'add_new' => 'Lägg till kurs', 'add_new_item' => 'Lägg till kurs', 'edit_item' => 'Redigera kurs', 'all_items' => 'Alla kurser'],
            'public' => true, 'show_in_rest' => true, 'menu_icon' => 'dashicons-welcome-learn-more',
            'supports' => ['title', 'editor', 'excerpt', 'thumbnail'], 'has_archive' => true,
            'rewrite' => ['slug' => 'kurser'],
        ]);
        // Kategorin väljs med en egen radioknapp i kursinställningarna (en kategori per kurs = en betalregel).
        register_taxonomy('eka_category', 'eka_course', [
            'labels' => ['name' => 'Kurskategorier', 'singular_name' => 'Kurskategori', 'add_new_item' => 'Lägg till kurskategori', 'edit_item' => 'Redigera kurskategori', 'menu_name' => 'Kategorier'],
            'hierarchical' => true, 'public' => true, 'show_in_rest' => false, 'show_admin_column' => true,
            'meta_box_cb' => false, 'rewrite' => ['slug' => 'kurskategori'],
        ]);
    }

    public static function admin_assets($hook) {
        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== 'eka_course' || !in_array($hook, ['post.php', 'post-new.php'], true)) return;
        wp_enqueue_media();
        wp_enqueue_script('eka-admin', EKA_URL . 'assets/eka-admin.js', [], EKA_VERSION, true);
        wp_enqueue_style('eka-admin', EKA_URL . 'assets/eka-admin.css', [], EKA_VERSION);
    }

    /* ---------------- Kategorier ---------------- */

    public static function category_add_fields() { ?>
        <div class="form-field"><label for="eka_deposit_percent">Föranmälningsavgift (%)</label>
        <input type="number" min="0" max="100" step="0.1" name="eka_deposit_percent" id="eka_deposit_percent" value="">
        <p>Andel av kurskostnaden som betalas vid anmälan. 100 = hela beloppet direkt. Lämna tomt för standardvärdet i inställningarna (<?php echo esc_html(get_option('eka_deposit_percent', 20)); ?> %).</p></div>
        <div class="form-field"><label for="eka_display_page">Visningssida</label>
        <?php echo EKA_Display::page_dropdown('eka_display_page', 0, '– Standard från inställningarna –'); // phpcs:ignore WordPress.Security.EscapeOutput ?>
        <p>Sidan som visar kurserna i kategorin. Sidan ska innehålla <code>[enkel_kurs]</code>.</p></div>
    <?php }

    public static function category_edit_fields($term) {
        $value = get_term_meta($term->term_id, 'eka_deposit_percent', true); ?>
        <tr class="form-field"><th><label for="eka_deposit_percent">Föranmälningsavgift (%)</label></th>
        <td><input type="number" min="0" max="100" step="0.1" name="eka_deposit_percent" id="eka_deposit_percent" value="<?php echo esc_attr($value); ?>">
        <p class="description">Andel av kurskostnaden som betalas vid anmälan. 100 = hela beloppet direkt. Lämna tomt för standardvärdet (<?php echo esc_html(get_option('eka_deposit_percent', 20)); ?> %).</p></td></tr>
        <tr class="form-field"><th><label for="eka_display_page">Visningssida</label></th>
        <td><?php echo EKA_Display::page_dropdown('eka_display_page', get_term_meta($term->term_id, EKA_Display::TERM_META, true), '– Standard från inställningarna –'); // phpcs:ignore WordPress.Security.EscapeOutput ?>
        <p class="description">Sidan som visar kurserna i kategorin. Sidan ska innehålla <code>[enkel_kurs]</code>.</p></td></tr>
    <?php }

    public static function save_category($term_id) {
        // WordPress har redan kontrollerat formulärets nonce innan dessa krokar körs.
        if (!current_user_can('manage_categories')) return;
        if (isset($_POST['eka_display_page'])) {
            $page = absint($_POST['eka_display_page']);
            if ($page) update_term_meta($term_id, EKA_Display::TERM_META, $page);
            else delete_term_meta($term_id, EKA_Display::TERM_META);
        }
        if (!isset($_POST['eka_deposit_percent'])) return;
        $raw = trim(sanitize_text_field(wp_unslash($_POST['eka_deposit_percent'])));
        if ($raw === '') delete_term_meta($term_id, 'eka_deposit_percent');
        else update_term_meta($term_id, 'eka_deposit_percent', min(100, max(0, (float) str_replace(',', '.', $raw))));
    }

    public static function category_columns($columns) {
        $columns['eka_deposit'] = 'Föranmälningsavgift';
        return $columns;
    }

    public static function category_column_content($content, $column, $term_id) {
        if ($column !== 'eka_deposit') return $content;
        $value = get_term_meta($term_id, 'eka_deposit_percent', true);
        return $value === '' ? 'Standard (' . esc_html(get_option('eka_deposit_percent', 20)) . ' %)' : esc_html($value . ' %');
    }

    /* ---------------- Kurslistan i admin ---------------- */

    public static function row_actions($actions, $post) {
        if ($post->post_type === 'eka_course' && current_user_can('edit_post', $post->ID)) {
            $actions['eka_manage'] = '<a href="' . esc_url(EKA_Admin::course_url($post->ID)) . '">Anmälningar</a>';
        }
        return $actions;
    }

    public static function columns($columns) {
        $columns['eka_booked'] = 'Anmälda';
        $columns['eka_responsible'] = 'Kursansvarig';
        return $columns;
    }

    public static function column_content($column, $post_id) {
        if ($column === 'eka_booked') {
            echo '<a href="' . esc_url(EKA_Admin::course_url($post_id)) . '">' . (int) EKA_Core::booked_count($post_id) . '</a>';
        } elseif ($column === 'eka_responsible') {
            $user = EKA_Core::responsible_user($post_id);
            echo $user ? esc_html($user->display_name) : '–';
        }
    }

    /* ---------------- Redigeringsrutor ---------------- */

    public static function add_meta_boxes($post) {
        add_meta_box('eka_settings', 'Kursinställningar', [__CLASS__, 'box_settings'], 'eka_course', 'normal', 'high');
        add_meta_box('eka_sessions', 'Kurstillfällen', [__CLASS__, 'box_sessions'], 'eka_course', 'normal', 'high');
        add_meta_box('eka_welcome', 'Välkomstmail', [__CLASS__, 'box_welcome'], 'eka_course', 'normal', 'default');
        add_meta_box('eka_pages', 'Informationssidor', [__CLASS__, 'box_pages'], 'eka_course', 'normal', 'default');
        add_meta_box('eka_partners', 'I samarbete med', [__CLASS__, 'box_partners'], 'eka_course', 'normal', 'default');
        if ($post->post_status !== 'auto-draft') add_meta_box('eka_manage', 'Anmälningar', [__CLASS__, 'box_manage'], 'eka_course', 'side', 'high');
    }

    public static function box_manage($post) {
        $url = EKA_Admin::course_url($post->ID);
        echo '<p><strong>' . (int) EKA_Core::booked_count($post->ID) . '</strong> aktiva deltagare.</p>';
        echo '<p><a class="button button-primary" href="' . esc_url($url) . '">Anmälningslista</a> ';
        echo '<a class="button" href="' . esc_url(add_query_arg('tab', 'mail', $url)) . '">Skicka mail</a></p>';
    }

    public static function box_settings($post) {
        wp_nonce_field('eka_save_course', 'eka_course_nonce');
        $price = get_post_meta($post->ID, '_eka_price', true);
        $current = EKA_Core::course_category($post->ID);
        $categories = get_terms(['taxonomy' => 'eka_category', 'hide_empty' => false]);
        $pnr_mode = get_post_meta($post->ID, '_eka_pnr_mode', true) ?: 'none';
        $responsible = (int) get_post_meta($post->ID, '_eka_responsible', true);
        $users = get_users(['capability' => 'edit_posts', 'orderby' => 'display_name', 'fields' => ['ID', 'display_name', 'user_email']]);
        ?>
        <table class="form-table eka-form-table">
        <tr><th><label for="eka_price">Kostnad per deltagare (kr)</label></th><td><input type="number" min="0" step="0.01" id="eka_price" name="eka_price" value="<?php echo esc_attr($price); ?>" class="small-text" style="width:140px"></td></tr>
        <tr><th>Kategori och betalregel</th><td>
            <label class="eka-block"><input type="radio" name="eka_category" value="0" <?php checked(!$current); ?>> Ingen kategori – standard (<?php echo esc_html(get_option('eka_deposit_percent', 20)); ?> % vid anmälan)</label>
            <?php foreach ((array) $categories as $term): $pct = get_term_meta($term->term_id, 'eka_deposit_percent', true); ?>
            <label class="eka-block"><input type="radio" name="eka_category" value="<?php echo (int) $term->term_id; ?>" <?php checked($current && $current->term_id === $term->term_id); ?>> <?php echo esc_html($term->name); ?> – <?php echo esc_html(($pct === '' ? get_option('eka_deposit_percent', 20) : $pct) . ' % vid anmälan'); ?></label>
            <?php endforeach; ?>
            <p class="description"><a href="<?php echo esc_url(admin_url('edit-tags.php?taxonomy=eka_category&post_type=eka_course')); ?>">Hantera kategorier och betalregler</a></p>
        </td></tr>
        <tr><th>Personnummer</th><td>
            <label class="eka-block"><input type="radio" name="eka_pnr_mode" value="none" <?php checked($pnr_mode, 'none'); ?>> Behövs inte</label>
            <label class="eka-block"><input type="radio" name="eka_pnr_mode" value="required" <?php checked($pnr_mode, 'required'); ?>> Krävs – namn och personnummer anges för varje deltagare</label>
            <p class="description">Personnummer sparas krypterat och visas maskerat i anmälningslistan.</p>
        </td></tr>
        <tr><th><label for="eka_responsible">Kursansvarig</label></th><td>
            <select id="eka_responsible" name="eka_responsible"><option value="0">– Ingen (avisering går till <?php echo esc_html(get_option('eka_admin_email', get_option('admin_email'))); ?>) –</option>
            <?php foreach ($users as $u): ?><option value="<?php echo (int) $u->ID; ?>" <?php selected($responsible, (int) $u->ID); ?>><?php echo esc_html($u->display_name . ' (' . $u->user_email . ')'); ?></option><?php endforeach; ?></select>
            <p class="description">Får ett mail vid varje ny anmälan.</p>
        </td></tr>
        </table>
        <?php
    }

    public static function box_sessions($post) {
        $sessions = EKA_Core::sessions($post->ID); ?>
        <div class="eka-repeater" data-template="eka-session-template">
        <table class="widefat"><thead><tr><th>Startdatum</th><th>Starttid</th><th>Sluttid</th><th>Max deltagare</th><th>Anmälda</th><th></th></tr></thead><tbody class="eka-repeater-items">
        <?php foreach ($sessions as $i => $s) self::session_row($i, $s, EKA_Core::booked_count($post->ID, EKA_Core::session_key($s))); ?>
        </tbody></table>
        <p><button type="button" class="button eka-repeater-add">+ Lägg till kurstillfälle</button></p>
        <script type="text/template" id="eka-session-template"><?php self::session_row('__INDEX__', ['date' => '', 'start' => '', 'end' => '', 'max' => 12], null); ?></script>
        </div>
    <?php }

    private static function session_row($i, $s, $booked) { $n = 'eka_sessions[' . $i . ']'; ?>
        <tr><td><input type="date" name="<?php echo esc_attr($n); ?>[date]" value="<?php echo esc_attr($s['date']); ?>" required></td>
        <td><input type="time" name="<?php echo esc_attr($n); ?>[start]" value="<?php echo esc_attr($s['start']); ?>" required></td>
        <td><input type="time" name="<?php echo esc_attr($n); ?>[end]" value="<?php echo esc_attr($s['end']); ?>"></td>
        <td><input type="number" min="1" name="<?php echo esc_attr($n); ?>[max]" value="<?php echo esc_attr($s['max']); ?>" required style="width:90px"></td>
        <td><?php echo $booked === null ? '–' : (int) $booked; ?></td>
        <td><button type="button" class="button-link-delete eka-repeater-remove">Ta bort</button></td></tr>
    <?php }

    public static function box_welcome($post) {
        $subject = get_post_meta($post->ID, '_eka_welcome_subject', true);
        $body = get_post_meta($post->ID, '_eka_welcome_body', true); ?>
        <p>Skickas till den som anmäler sig. Lämna tomt för att använda standardmallen i <a href="<?php echo esc_url(admin_url('edit.php?post_type=eka_course&page=eka-settings')); ?>">inställningarna</a>.</p>
        <p><label><strong>Ämne</strong><br><input type="text" class="large-text" name="eka_welcome_subject" value="<?php echo esc_attr($subject); ?>" placeholder="<?php echo esc_attr(EKA_Mailer::default_welcome_subject()); ?>"></label></p>
        <?php wp_editor($body, 'eka_welcome_body', ['textarea_name' => 'eka_welcome_body', 'textarea_rows' => 10, 'media_buttons' => false]); ?>
        <?php EKA_Mailer::placeholder_help(); ?>
    <?php }

    public static function box_pages($post) {
        $rows = (array) json_decode((string) get_post_meta($post->ID, '_eka_pages', true), true); ?>
        <p>Länkar som visas på kurssidan och kan infogas i välkomstmailet med <code>{informationssidor}</code>, t.ex. avanmälningsvillkor och betalningsinformation.</p>
        <div class="eka-repeater" data-template="eka-page-template">
        <table class="widefat"><thead><tr><th>Rubrik</th><th>Sida</th><th></th></tr></thead><tbody class="eka-repeater-items">
        <?php foreach ($rows as $i => $row) self::page_row($i, $row); ?>
        </tbody></table>
        <p><button type="button" class="button eka-repeater-add">+ Lägg till sida</button></p>
        <script type="text/template" id="eka-page-template"><?php self::page_row('__INDEX__', ['label' => '', 'page_id' => 0]); ?></script>
        </div>
    <?php }

    private static function page_row($i, $row) { $n = 'eka_pages[' . $i . ']'; ?>
        <tr><td><input type="text" class="regular-text" name="<?php echo esc_attr($n); ?>[label]" value="<?php echo esc_attr($row['label'] ?? ''); ?>" placeholder="T.ex. Avanmälningsvillkor"></td>
        <td><?php wp_dropdown_pages(['name' => $n . '[page_id]', 'selected' => (int) ($row['page_id'] ?? 0), 'show_option_none' => '– Välj sida –', 'option_none_value' => 0]); ?></td>
        <td><button type="button" class="button-link-delete eka-repeater-remove">Ta bort</button></td></tr>
    <?php }

    public static function box_partners($post) {
        $rows = EKA_Core::partners($post->ID); ?>
        <p>Visas under rubriken "I samarbete med" på kurssidan.</p>
        <div class="eka-repeater" data-template="eka-partner-template">
        <table class="widefat"><thead><tr><th>Logga</th><th>Namn</th><th>Webbadress</th><th></th></tr></thead><tbody class="eka-repeater-items">
        <?php foreach ($rows as $i => $row) self::partner_row($i, $row); ?>
        </tbody></table>
        <p><button type="button" class="button eka-repeater-add">+ Lägg till samarbetspartner</button></p>
        <script type="text/template" id="eka-partner-template"><?php self::partner_row('__INDEX__', ['name' => '', 'url' => '', 'logo_id' => 0]); ?></script>
        </div>
    <?php }

    private static function partner_row($i, $row) {
        $n = 'eka_partners[' . $i . ']';
        $logo_id = (int) ($row['logo_id'] ?? 0);
        $thumb = $logo_id ? wp_get_attachment_image_url($logo_id, 'thumbnail') : ''; ?>
        <tr><td class="eka-logo-cell"><input type="hidden" class="eka-logo-id" name="<?php echo esc_attr($n); ?>[logo_id]" value="<?php echo $logo_id ?: ''; ?>">
            <img class="eka-logo-preview" src="<?php echo esc_url($thumb); ?>" alt="" <?php echo $thumb ? '' : 'hidden'; ?>>
            <button type="button" class="button eka-pick-logo">Välj logga</button> <button type="button" class="button-link eka-clear-logo" <?php echo $thumb ? '' : 'hidden'; ?>>Ta bort logga</button></td>
        <td><input type="text" name="<?php echo esc_attr($n); ?>[name]" value="<?php echo esc_attr($row['name'] ?? ''); ?>"></td>
        <td><input type="url" name="<?php echo esc_attr($n); ?>[url]" value="<?php echo esc_attr($row['url'] ?? ''); ?>" placeholder="https://"></td>
        <td><button type="button" class="button-link-delete eka-repeater-remove">Ta bort</button></td></tr>
    <?php }

    /* ---------------- Spara ---------------- */

    public static function save_course($post_id) {
        if (!isset($_POST['eka_course_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['eka_course_nonce'])), 'eka_save_course')) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (wp_is_post_revision($post_id) || !current_user_can('edit_post', $post_id)) return;

        update_post_meta($post_id, '_eka_price', max(0, (float) str_replace(',', '.', sanitize_text_field(wp_unslash($_POST['eka_price'] ?? '0')))));

        $category = absint($_POST['eka_category'] ?? 0);
        $term = $category ? get_term($category, 'eka_category') : null;
        wp_set_object_terms($post_id, ($term && !is_wp_error($term)) ? [$term->term_id] : [], 'eka_category');

        update_post_meta($post_id, '_eka_pnr_mode', ($_POST['eka_pnr_mode'] ?? '') === 'required' ? 'required' : 'none');

        $responsible = absint($_POST['eka_responsible'] ?? 0);
        $user = $responsible ? get_userdata($responsible) : null;
        update_post_meta($post_id, '_eka_responsible', ($user && user_can($user, 'edit_posts')) ? $user->ID : 0);

        update_post_meta($post_id, '_eka_welcome_subject', sanitize_text_field(wp_unslash($_POST['eka_welcome_subject'] ?? '')));
        update_post_meta($post_id, '_eka_welcome_body', wp_kses_post(wp_unslash($_POST['eka_welcome_body'] ?? '')));

        $pages = [];
        foreach ((array) ($_POST['eka_pages'] ?? []) as $row) {
            $page_id = absint($row['page_id'] ?? 0);
            if ($page_id && get_post_type($page_id) === 'page') $pages[] = ['label' => sanitize_text_field(wp_unslash($row['label'] ?? '')), 'page_id' => $page_id];
        }
        update_post_meta($post_id, '_eka_pages', wp_json_encode($pages));

        $partners = [];
        foreach ((array) ($_POST['eka_partners'] ?? []) as $row) {
            $p = ['name' => sanitize_text_field(wp_unslash($row['name'] ?? '')), 'url' => esc_url_raw(wp_unslash($row['url'] ?? '')), 'logo_id' => absint($row['logo_id'] ?? 0)];
            if ($p['logo_id'] && !wp_attachment_is_image($p['logo_id'])) $p['logo_id'] = 0;
            if ($p['name'] !== '' || $p['logo_id']) $partners[] = $p;
        }
        update_post_meta($post_id, '_eka_partners', wp_json_encode($partners));

        self::save_sessions($post_id);
    }

    private static function save_sessions($post_id) {
        $old_keys = array_map(['EKA_Core', 'session_key'], EKA_Core::sessions($post_id));
        $sessions = []; $keys = [];
        foreach ((array) ($_POST['eka_sessions'] ?? []) as $row) {
            $date = sanitize_text_field(wp_unslash($row['date'] ?? ''));
            $start = sanitize_text_field(wp_unslash($row['start'] ?? ''));
            $end = sanitize_text_field(wp_unslash($row['end'] ?? ''));
            $max = max(1, absint($row['max'] ?? 1));
            if ($date === '' && $start === '') continue;
            if (!EKA_Core::valid_date($date) || !preg_match(EKA_Core::TIME_PATTERN, $start)) {
                EKA_Core::add_notice(sprintf('Kurstillfället "%s %s" sparades inte: datum eller starttid är ogiltigt.', $date, $start), 'error');
                continue;
            }
            if ($end !== '' && (!preg_match(EKA_Core::TIME_PATTERN, $end) || $end <= $start)) {
                EKA_Core::add_notice(sprintf('Sluttiden för %s kl. %s togs bort eftersom den måste vara senare än starttiden.', $date, $start));
                $end = '';
            }
            $s = compact('date', 'start', 'end', 'max');
            $key = EKA_Core::session_key($s);
            if (isset($keys[$key])) {
                EKA_Core::add_notice(sprintf('Kurstillfället %s fanns med två gånger och sparades bara en gång.', EKA_Core::session_label($s)));
                continue;
            }
            $keys[$key] = true;
            $sessions[] = $s;
        }
        usort($sessions, function($a, $b) { return strcmp($a['date'] . $a['start'], $b['date'] . $b['start']); });
        update_post_meta($post_id, '_eka_sessions', wp_json_encode($sessions));

        foreach (array_diff($old_keys, array_keys($keys)) as $removed) {
            $booked = EKA_Core::booked_count($post_id, $removed);
            if ($booked > 0) EKA_Core::add_notice(sprintf('Kurstillfället %s togs bort eller ändrades, men har %d aktiva deltagare. Anmälningarna finns kvar i kursens anmälningslista.', EKA_Core::key_label($removed), $booked));
        }
    }
}
