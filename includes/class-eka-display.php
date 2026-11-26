<?php
if (!defined('ABSPATH')) exit;

/**
 * Visningssida: en vanlig WordPress-sida som används som mall för kurserna.
 *
 * Kursen behåller sin adress (/kurser/...), men i stället för kursens egen text visas visningssidans
 * innehåll. Shortcoden [enkel_kurs] på sidan byts mot kursens delar (beskrivning, formulär m.m.).
 * Sidan väljs per kategori eller i inställningarna. Utan vald sida visas kursen som tidigare.
 */
final class EKA_Display {
    const OPTION = 'eka_display_page';
    const TERM_META = 'eka_display_page';
    const PARTS = ['titel', 'bild', 'beskrivning', 'utdrag', 'pris', 'tillfallen', 'partners', 'information', 'formular'];
    const DEFAULT_PARTS = ['beskrivning', 'partners', 'information', 'formular'];

    /** Kursen som visas just nu, och skydd mot att sidan eller beskrivningen ritas inuti sig själv. */
    private static $course_id = 0;
    private static $depth = 0;
    private static $thanks_shown = false;

    public static function init() {
        add_shortcode('enkel_kurs', [__CLASS__, 'shortcode']);
        // Prioritet 1: sidans råa innehåll går sedan igenom WordPress vanliga behandling (block, stycken, shortcodes).
        add_filter('the_content', [__CLASS__, 'swap_content'], 1);
        add_filter('single_template_hierarchy', [__CLASS__, 'template_hierarchy']);
    }

    /** Visningssidan för en kurs: kategorins sida, annars sidan i inställningarna. 0 = ingen. */
    public static function page_for($course_id) {
        $category = EKA_Core::course_category($course_id);
        $candidates = [$category ? (int) get_term_meta($category->term_id, self::TERM_META, true) : 0, (int) get_option(self::OPTION, 0)];
        foreach ($candidates as $page_id) {
            if ($page_id && get_post_type($page_id) === 'page' && get_post_status($page_id) === 'publish') return $page_id;
        }
        return 0;
    }

    public static function swap_content($content) {
        if (self::$depth > 0 || !is_singular('eka_course') || !in_the_loop() || !is_main_query()) return $content;
        $course_id = get_the_ID();
        $page_id = self::page_for($course_id);
        if (!$page_id) return $content;
        self::$course_id = $course_id;
        return (string) get_post_field('post_content', $page_id);
    }

    /** Använd visningssidans sidmall (eller temats page.php) i stället för mallen för enskilda inlägg. */
    public static function template_hierarchy($templates) {
        if (!is_singular('eka_course')) return $templates;
        $page_id = self::page_for(get_queried_object_id());
        if (!$page_id) return $templates;
        $page = get_post($page_id);
        $own = [];
        $slug = get_page_template_slug($page_id);
        if ($slug) $own[] = $slug;
        $own[] = 'page-' . $page->post_name . '.php';
        $own[] = 'page-' . $page_id . '.php';
        $own[] = 'page.php';
        return array_values(array_unique(array_merge($own, $templates)));
    }

    /** [enkel_kurs], [enkel_kurs del="formular"], [enkel_kurs del="bild,beskrivning"] och [enkel_kurs id="123"]. */
    public static function shortcode($atts) {
        $atts = shortcode_atts(['del' => '', 'id' => 0], $atts, 'enkel_kurs');
        $course_id = (int) $atts['id'] ?: self::$course_id ?: (is_singular('eka_course') ? get_queried_object_id() : 0);
        if (!$course_id || get_post_type($course_id) !== 'eka_course' || get_post_status($course_id) !== 'publish') {
            return current_user_can('edit_posts') ? '<p class="eka-hint">[enkel_kurs] visar kursen här när en kurs öppnas med den här sidan som visningssida.</p>' : '';
        }
        if (self::$depth > 0) return ''; // en kursbeskrivning som själv innehåller [enkel_kurs]

        $parts = self::parse_parts($atts['del']);
        if (in_array('formular', $parts, true)) wp_enqueue_script('eka-front', EKA_URL . 'assets/eka-front.js', [], EKA_VERSION, true);

        self::$depth++;
        $html = '';
        if (!self::$thanks_shown) { $html .= EKA_Frontend::thanks_html($course_id); self::$thanks_shown = true; }
        foreach ($parts as $part) $html .= self::part($part, $course_id);
        self::$depth--;
        return '<div class="eka-course">' . $html . '</div>';
    }

    public static function parse_parts($value) {
        $value = strtolower(trim((string) $value));
        if ($value === '' || $value === 'alla') return self::DEFAULT_PARTS;
        $aliases = ['formulär' => 'formular', 'tillfällen' => 'tillfallen', 'info' => 'information', 'informationssidor' => 'information', 'rubrik' => 'titel'];
        $parts = [];
        foreach (explode(',', $value) as $p) {
            $p = trim($p);
            $p = $aliases[$p] ?? $p;
            if (in_array($p, self::PARTS, true) && !in_array($p, $parts, true)) $parts[] = $p;
        }
        return $parts;
    }

    private static function part($part, $course_id) {
        switch ($part) {
            case 'titel':
                return '<h1 class="eka-course-title">' . esc_html(get_the_title($course_id)) . '</h1>';
            case 'bild':
                return has_post_thumbnail($course_id) ? '<figure class="eka-course-image">' . get_the_post_thumbnail($course_id, 'large') . '</figure>' : '';
            case 'beskrivning':
                // Kursens egen text med WordPress vanliga behandling. $depth > 0 hindrar att visningssidan byts in igen.
                return '<div class="eka-course-description">' . apply_filters('the_content', (string) get_post_field('post_content', $course_id)) . '</div>';
            case 'utdrag':
                return has_excerpt($course_id) ? '<p class="eka-course-excerpt">' . esc_html(get_the_excerpt($course_id)) . '</p>' : '';
            case 'pris':
                return '<p class="eka-course-price"><strong>' . esc_html(EKA_Core::money((float) get_post_meta($course_id, '_eka_price', true))) . ' kr</strong> per deltagare</p>';
            case 'tillfallen':
                return self::sessions_html($course_id);
            case 'partners':
                return EKA_Frontend::partners_html($course_id);
            case 'information':
                return EKA_Frontend::info_pages_html($course_id);
            case 'formular':
                return EKA_Frontend::form_html($course_id);
        }
        return '';
    }

    private static function sessions_html($course_id) {
        $sessions = EKA_Core::upcoming_sessions($course_id);
        if (!$sessions) return '<p class="eka-course-sessions">Det finns inga kommande kurstillfällen just nu.</p>';
        $html = '<ul class="eka-course-sessions">';
        foreach ($sessions as $s) {
            $left = max(0, (int) $s['max'] - EKA_Core::booked_count($course_id, EKA_Core::session_key($s)));
            $html .= '<li>' . esc_html(EKA_Core::session_label($s)) . ' <span>' . esc_html($left ? $left . ' platser kvar' : 'Fullbokad') . '</span></li>';
        }
        return $html . '</ul>';
    }

    /** Rullgardin med publicerade sidor, för inställningarna och kategorierna. */
    public static function page_dropdown($name, $selected, $none_label) {
        return wp_dropdown_pages(['name' => $name, 'id' => $name, 'selected' => (int) $selected, 'show_option_none' => $none_label, 'option_none_value' => '0', 'post_status' => 'publish', 'echo' => 0])
            ?: '<select name="' . esc_attr($name) . '" id="' . esc_attr($name) . '"><option value="0">' . esc_html($none_label) . '</option></select>';
    }
}
