<?php
if (!defined('ABSPATH')) exit;

/** E-post: välkomstmail, avisering till kursansvarig och utskick till kursens deltagare. */
final class EKA_Mailer {

    const PLACEHOLDERS = [
        '{namn}' => 'Kontaktpersonens namn',
        '{kurs}' => 'Kursens rubrik',
        '{tillfalle}' => 'Datum och tid för kurstillfället',
        '{deltagare}' => 'Antal deltagare',
        '{deltagarlista}' => 'Deltagarnas namn',
        '{totalbelopp}' => 'Total kurskostnad (kr)',
        '{att_betala}' => 'Belopp att betala nu (kr)',
        '{procent}' => 'Andel som betalas vid anmälan (%)',
        '{referens}' => 'Swish-referens',
        '{swishnummer}' => 'Swish-nummer',
        '{informationssidor}' => 'Länkar till kursens informationssidor',
        '{kursansvarig}' => 'Kursansvarigs namn',
        '{kurslank}' => 'Länk till kurssidan',
    ];

    public static function default_welcome_subject() {
        return get_option('eka_welcome_subject') ?: 'Välkommen till {kurs}';
    }

    public static function default_welcome_body() {
        $saved = get_option('eka_welcome_body');
        if ($saved) return $saved;
        return "<p>Hej {namn}!</p>\n<p>Tack för din anmälan till <strong>{kurs}</strong>, {tillfalle}.</p>\n"
            . "<p>Swisha <strong>{att_betala} kr</strong> till {swishnummer} och ange <strong>{referens}</strong> som meddelande.</p>\n"
            . "<p>Total kurskostnad: {totalbelopp} kr.</p>\n{informationssidor}\n<p>Välkommen!</p>";
    }

    public static function placeholder_help($keys = null) {
        $list = $keys ? array_intersect_key(self::PLACEHOLDERS, array_flip($keys)) : self::PLACEHOLDERS;
        echo '<details class="eka-placeholders"><summary>Kortkoder du kan använda</summary><ul>';
        foreach ($list as $code => $label) echo '<li><code>' . esc_html($code) . '</code> – ' . esc_html($label) . '</li>';
        echo '</ul></details>';
    }

    /** Ersätter kortkoder. Värdena escapas; nycklar i $raw_html infogas som färdig HTML. */
    public static function render($template, array $values, array $raw_html = []) {
        $map = [];
        foreach ($values as $k => $v) $map[$k] = in_array($k, $raw_html, true) ? (string) $v : esc_html((string) $v);
        return strtr($template, $map);
    }

    public static function send_html($to, $subject, $html) {
        $body = '<!doctype html><html><body style="font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.5;color:#222">' . wpautop($html) . '</body></html>';
        return wp_mail($to, wp_specialchars_decode($subject, ENT_QUOTES), $body, ['Content-Type: text/html; charset=UTF-8']);
    }

    public static function info_pages_html($course_id) {
        $links = EKA_Core::info_pages($course_id);
        if (!$links) return '';
        $html = '<p><strong>Bra att veta</strong></p><ul>';
        foreach ($links as $l) $html .= '<li><a href="' . esc_url($l['url']) . '">' . esc_html($l['label']) . '</a></li>';
        return $html . '</ul>';
    }

    /** Kortkodsvärden för en anmälan. */
    public static function registration_values($course_id, array $reg, array $participants) {
        $responsible = EKA_Core::responsible_user($course_id);
        return [
            '{namn}' => $reg['name'],
            '{kurs}' => get_the_title($course_id),
            '{tillfalle}' => EKA_Core::key_label($reg['session_key']),
            '{deltagare}' => (int) $reg['participants'],
            '{deltagarlista}' => $participants ? implode(', ', wp_list_pluck($participants, 'name')) : $reg['name'],
            '{totalbelopp}' => EKA_Core::money($reg['total_amount']),
            '{att_betala}' => EKA_Core::money($reg['deposit_amount']),
            '{procent}' => rtrim(rtrim(number_format(EKA_Core::deposit_percent($course_id), 1, ',', ''), '0'), ','),
            '{referens}' => $reg['payment_reference'],
            '{swishnummer}' => (string) get_option('eka_swish_number'),
            '{informationssidor}' => self::info_pages_html($course_id),
            '{kursansvarig}' => $responsible ? $responsible->display_name : '',
            '{kurslank}' => get_permalink($course_id),
        ];
    }

    public static function send_welcome($course_id, array $reg, array $participants) {
        $subject = get_post_meta($course_id, '_eka_welcome_subject', true) ?: self::default_welcome_subject();
        $body = trim((string) get_post_meta($course_id, '_eka_welcome_body', true)) ?: self::default_welcome_body();
        $values = self::registration_values($course_id, $reg, $participants);
        return self::send_html($reg['email'], wp_strip_all_tags(self::render($subject, $values)), self::render($body, $values, ['{informationssidor}']));
    }

    /** Avisering till kursansvarig (eller aviseringsadressen om ingen är vald). Personnummer skickas aldrig via mail. */
    public static function notify_responsible($course_id, array $reg, array $participants) {
        $user = EKA_Core::responsible_user($course_id);
        $to = $user ? $user->user_email : get_option('eka_admin_email', get_option('admin_email'));
        $title = get_the_title($course_id);
        $session = EKA_Core::key_label($reg['session_key']);
        $booked = EKA_Core::booked_count($course_id, $reg['session_key']);
        $max = EKA_Core::session_max($course_id, $reg['session_key']);
        $rows = [
            'Kurs' => $title, 'Tillfälle' => $session, 'Kontaktperson' => $reg['name'], 'E-post' => $reg['email'], 'Telefon' => $reg['phone'],
            'Antal deltagare' => (int) $reg['participants'],
            'Deltagare' => $participants ? implode(', ', wp_list_pluck($participants, 'name')) : '',
            'Att betala nu' => EKA_Core::money($reg['deposit_amount']) . ' kr av ' . EKA_Core::money($reg['total_amount']) . ' kr',
            'Swish-referens' => $reg['payment_reference'],
            'Platser bokade' => $booked . ($max ? ' av ' . $max : ''),
        ];
        $html = '<p>Ny anmälan till <strong>' . esc_html($title) . '</strong>.</p><table cellpadding="4" style="border-collapse:collapse">';
        foreach (array_filter($rows, 'strlen') as $k => $v) $html .= '<tr><th align="left" style="padding-right:12px">' . esc_html($k) . '</th><td>' . esc_html($v) . '</td></tr>';
        $html .= '</table><p><a href="' . esc_url(EKA_Admin::course_url($course_id)) . '">Öppna kursens anmälningslista</a></p>';
        return self::send_html($to, 'Ny anmälan: ' . $title . ' (' . $session . ')', $html);
    }

    public static function unsubscribe_url($token) {
        return add_query_arg(['action' => 'eka_unsubscribe', 'token' => $token], admin_url('admin-post.php'));
    }
}
