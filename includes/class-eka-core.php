<?php
if (!defined('ABSPATH')) exit;

/** Gemensamma hjälpfunktioner: kurstillfällen, platser, lås, betalregler, personnummer och meddelanden. */
final class EKA_Core {
    const STATUSES = ['pending' => 'Väntar', 'paid' => 'Betald', 'cancelled' => 'Avbokad'];
    const TIME_PATTERN = '/^([01]\d|2[0-3]):[0-5]\d$/';
    const MAX_PARTICIPANTS = 20;

    public static function init() {
        add_action('admin_notices', [__CLASS__, 'show_notices']);
    }

    /* ---------------- Admin-meddelanden ---------------- */

    public static function add_notice($message, $type = 'warning') {
        $key = 'eka_notices_' . get_current_user_id();
        $notices = array_filter((array) get_transient($key));
        $notices[] = ['message' => $message, 'type' => $type];
        set_transient($key, $notices, 5 * MINUTE_IN_SECONDS);
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

    /* ---------------- Kurstillfällen ---------------- */

    public static function valid_date($date) {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string) $date, $m)) return false;
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    public static function sessions($course_id) {
        $raw = (string) get_post_meta($course_id, '_eka_sessions', true);
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) return array_values(array_filter($decoded, function($s) { return !empty($s['date']) && !empty($s['start']); }));
        // Äldre format: en rad per tillfälle, t.ex. "2026-03-14 9.00–12:00".
        $sessions = [];
        foreach (array_filter(array_map('trim', preg_split('/\R/', $raw))) as $line) {
            if (!preg_match('/^(\d{4}-\d{2}-\d{2})\s+(\d{1,2})[:.](\d{2})(?:\D+(\d{1,2})[:.](\d{2}))?/', $line, $m) || !self::valid_date($m[1])) continue;
            $start = sprintf('%02d:%s', $m[2], $m[3]);
            $end = isset($m[4]) && $m[4] !== '' ? sprintf('%02d:%s', $m[4], $m[5]) : '';
            if (!preg_match(self::TIME_PATTERN, $start)) continue;
            if ($end !== '' && (!preg_match(self::TIME_PATTERN, $end) || $end <= $start)) $end = '';
            $sessions[] = ['date' => $m[1], 'start' => $start, 'end' => $end, 'max' => 12];
        }
        return $sessions;
    }

    public static function session_start($s) {
        return DateTimeImmutable::createFromFormat('!Y-m-d H:i', $s['date'] . ' ' . $s['start'], wp_timezone()) ?: null;
    }

    /** Kurstillfällen som ännu inte har startat, enligt webbplatsens tidszon. */
    public static function upcoming_sessions($course_id) {
        $now = new DateTimeImmutable('now', wp_timezone());
        return array_values(array_filter(self::sessions($course_id), function($s) use ($now) {
            $start = self::session_start($s);
            return $start && $start > $now;
        }));
    }

    public static function session_key($s) { return $s['date'] . '|' . $s['start'] . '|' . ($s['end'] ?? ''); }

    public static function session_label($s) {
        $date = wp_date(get_option('date_format'), strtotime($s['date']));
        return $date . ' kl. ' . $s['start'] . (!empty($s['end']) ? '–' . $s['end'] : '');
    }

    public static function key_label($key) {
        $p = explode('|', (string) $key);
        if (count($p) < 2 || !self::valid_date($p[0])) return (string) $key;
        return self::session_label(['date' => $p[0], 'start' => $p[1], 'end' => $p[2] ?? '']);
    }

    public static function session_max($course_id, $key) {
        foreach (self::sessions($course_id) as $s) if (self::session_key($s) === $key) return (int) $s['max'];
        return null;
    }

    /** Antal upptagna platser (avbokade anmälningar räknas inte). */
    public static function booked_count($course_id, $key = null) {
        global $wpdb;
        $t = EKA_DB::table('registrations');
        if ($key === null) return (int) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(participants),0) FROM $t WHERE course_id=%d AND payment_status<>'cancelled'", $course_id));
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(participants),0) FROM $t WHERE course_id=%d AND session_key=%s AND payment_status<>'cancelled'", $course_id, $key));
    }

    /** Lås per kurstillfälle så att platskontroll och sparande sker utan att andra anmälningar hinner emellan. */
    private static function lock_name($course_id, $key) { return 'eka_' . md5($course_id . '|' . $key); }

    public static function lock_session($course_id, $key) {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 10)', self::lock_name($course_id, $key))) === 1;
    }

    public static function unlock_session($course_id, $key) {
        global $wpdb;
        $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', self::lock_name($course_id, $key)));
    }

    /* ---------------- Kursinställningar ---------------- */

    public static function course_category($course_id) {
        $terms = get_the_terms($course_id, 'eka_category');
        return ($terms && !is_wp_error($terms)) ? $terms[0] : null;
    }

    /** Andel av kurskostnaden som betalas vid anmälan: kategorins regel, annars standardvärdet i inställningarna. */
    public static function deposit_percent($course_id) {
        $term = self::course_category($course_id);
        if ($term) {
            $value = get_term_meta($term->term_id, 'eka_deposit_percent', true);
            if ($value !== '' && $value !== false) return min(100, max(0, (float) $value));
        }
        return min(100, max(0, (float) get_option('eka_deposit_percent', 20)));
    }

    public static function requires_personal_number($course_id) {
        return get_post_meta($course_id, '_eka_pnr_mode', true) === 'required';
    }

    public static function responsible_user($course_id) {
        $id = (int) get_post_meta($course_id, '_eka_responsible', true);
        return $id ? get_userdata($id) : null;
    }

    /** Länkar till informationssidor: [['label' => ..., 'url' => ...], ...] */
    public static function info_pages($course_id) {
        $links = [];
        foreach ((array) json_decode((string) get_post_meta($course_id, '_eka_pages', true), true) as $row) {
            $page = !empty($row['page_id']) ? get_post((int) $row['page_id']) : null;
            if (!$page || $page->post_status !== 'publish') continue;
            $links[] = ['label' => $row['label'] !== '' ? $row['label'] : get_the_title($page), 'url' => get_permalink($page)];
        }
        return $links;
    }

    /** Samarbetspartner: [['name' => ..., 'url' => ..., 'logo_id' => ...], ...] */
    public static function partners($course_id) {
        return array_values(array_filter((array) json_decode((string) get_post_meta($course_id, '_eka_partners', true), true), function($p) {
            return !empty($p['name']) || !empty($p['logo_id']);
        }));
    }

    public static function money($amount) { return number_format_i18n((float) $amount, 2); }

    /* ---------------- Personnummer ---------------- */

    /**
     * Normaliserar ett svenskt personnummer (även samordningsnummer) till ÅÅÅÅMMDDNNNN.
     * Godtar 10 eller 12 siffror, med eller utan bindestreck/plus. Returnerar false om det är ogiltigt.
     */
    public static function normalize_personal_number($input) {
        $value = preg_replace('/\s+/', '', (string) $input);
        if (!preg_match('/^(\d{2})?(\d{2})(\d{2})(\d{2})([-+]?)(\d{4})$/', $value, $m)) return false;
        [, $century, $yy, $mm, $dd, $sep, $last] = $m;
        if ($century === '') {
            $now = (int) wp_date('Y');
            $year = (int) (floor($now / 100) * 100) + (int) $yy;
            if ($year > $now) $year -= 100;
            if ($sep === '+') $year -= 100;
        } else {
            $year = (int) ($century . $yy);
        }
        $day = (int) $dd > 60 ? (int) $dd - 60 : (int) $dd; // samordningsnummer
        if (!checkdate((int) $mm, $day, $year)) return false;
        if (!self::luhn_valid($yy . $mm . $dd . $last)) return false;
        return sprintf('%04d', $year) . $mm . $dd . $last;
    }

    private static function luhn_valid($digits) {
        $sum = 0;
        foreach (str_split($digits) as $i => $d) {
            $n = (int) $d * ($i % 2 === 0 ? 2 : 1);
            $sum += $n > 9 ? $n - 9 : $n;
        }
        return $sum % 10 === 0;
    }

    public static function format_personal_number($pnr12) {
        return strlen($pnr12) === 12 ? substr($pnr12, 0, 8) . '-' . substr($pnr12, 8) : $pnr12;
    }

    public static function mask_personal_number($pnr12) {
        return strlen($pnr12) === 12 ? substr($pnr12, 0, 8) . '-****' : '****';
    }

    /*
     * Personnummer sparas krypterade. Nyckeln tas från konstanten EKA_ENCRYPTION_KEY om den finns i wp-config.php,
     * annars från webbplatsens AUTH_KEY/SECURE_AUTH_KEY. Byts dessa går redan sparade personnummer inte att läsa.
     */
    private static function crypto_key() {
        // WordPress levererar sodium_compat för servrar som saknar PHP-tillägget sodium.
        if (!function_exists('sodium_crypto_secretbox') && defined('WPINC') && file_exists(ABSPATH . WPINC . '/sodium_compat/autoload.php')) {
            require_once ABSPATH . WPINC . '/sodium_compat/autoload.php';
        }
        $secret = defined('EKA_ENCRYPTION_KEY') ? EKA_ENCRYPTION_KEY : (AUTH_KEY . SECURE_AUTH_KEY);
        return sodium_crypto_generichash('eka-personnummer|' . $secret, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    public static function encrypt($plain) {
        $key = self::crypto_key();
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return 'v1:' . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $key));
    }

    public static function decrypt($stored) {
        if (strpos((string) $stored, 'v1:') !== 0) return '';
        $key = self::crypto_key();
        $raw = base64_decode(substr($stored, 3), true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) return '';
        $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $key);
        return $plain === false ? '' : $plain;
    }

    /* ---------------- Anmälningar ---------------- */

    /** Deltagare per anmälan: [registration_id => [['name' => ..., 'pnr' => ÅÅÅÅMMDDNNNN|''], ...]] */
    public static function participants_for(array $registration_ids) {
        global $wpdb;
        $ids = array_filter(array_map('absint', $registration_ids));
        if (!$ids) return [];
        $rows = $wpdb->get_results('SELECT registration_id, name, personal_number FROM ' . EKA_DB::table('participants') . ' WHERE registration_id IN (' . implode(',', $ids) . ') ORDER BY id');
        $out = [];
        foreach ($rows as $r) $out[(int) $r->registration_id][] = ['name' => $r->name, 'pnr' => $r->personal_number ? self::decrypt($r->personal_number) : ''];
        return $out;
    }

    public static function delete_registration($id) {
        global $wpdb;
        $wpdb->delete(EKA_DB::table('participants'), ['registration_id' => $id], ['%d']);
        return (bool) $wpdb->delete(EKA_DB::table('registrations'), ['id' => $id], ['%d']);
    }

    /** Lägger till eller uppdaterar en adress på kursens maillista. Samtycke till nyhetsutskick tas aldrig bort här. */
    public static function subscribe($course_id, $email, $name, $newsletter) {
        global $wpdb;
        $t = EKA_DB::table('subscribers');
        $now = current_time('mysql');
        $existing = $wpdb->get_row($wpdb->prepare("SELECT id, newsletter FROM $t WHERE course_id=%d AND email=%s", $course_id, $email));
        if ($existing) {
            $wpdb->update($t, ['name' => $name, 'status' => 'subscribed', 'newsletter' => ($newsletter || $existing->newsletter) ? 1 : 0, 'updated_at' => $now], ['id' => $existing->id], ['%s', '%s', '%d', '%s'], ['%d']);
            return;
        }
        $wpdb->insert($t, ['course_id' => $course_id, 'email' => $email, 'name' => $name, 'newsletter' => $newsletter ? 1 : 0, 'status' => 'subscribed', 'token' => bin2hex(random_bytes(24)), 'created_at' => $now, 'updated_at' => $now], ['%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s']);
    }

    /** Hindrar att kalkylprogram tolkar inskickad text som formler. */
    public static function csv_safe($value) {
        $value = (string) $value;
        return preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
    }

    public static function send_csv($filename, array $header, array $rows) {
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . sanitize_file_name($filename) . '"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // BOM så att Excel läser åäö rätt
        fputcsv($out, $header, ';');
        foreach ($rows as $row) fputcsv($out, array_map([__CLASS__, 'csv_safe'], $row), ';');
        fclose($out);
        exit;
    }
}
