<?php
if (!defined('ABSPATH')) exit;

/**
 * Koppling till The Newsletter Plugin (TNP).
 * Den som kryssar i "information om kommande kurser" läggs i vald TNP-lista.
 * Kopplingen är valfri: saknas TNP eller vald lista händer ingenting, och fel här stoppar aldrig en anmälan.
 */
final class EKA_Newsletter {

    public static function init() {
        add_action('eka_after_registration', [__CLASS__, 'on_registration'], 10, 3);
        add_action('admin_post_eka_tnp_sync', [__CLASS__, 'handle_sync']);
    }

    public static function available() {
        return class_exists('TNP') && method_exists('TNP', 'add_subscriber') && class_exists('Newsletter') && method_exists('Newsletter', 'instance');
    }

    public static function list_id() {
        return absint(get_option('eka_tnp_list', 0));
    }

    public static function enabled() {
        return self::available() && self::list_id() > 0;
    }

    /** TNP-listor som [id => namn], eller tom array om de inte går att läsa ut. */
    public static function lists() {
        if (!self::available()) return [];
        try {
            $newsletter = Newsletter::instance();
            if (!method_exists($newsletter, 'get_lists')) return [];
            $out = [];
            foreach ((array) $newsletter->get_lists() as $list) {
                if (isset($list->id)) $out[(int) $list->id] = !empty($list->name) ? $list->name : 'Lista ' . (int) $list->id;
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    public static function on_registration($course_id, array $reg, $wants_newsletter) {
        if ($wants_newsletter) self::add($reg['email'], $reg['name']);
    }

    /**
     * Lägger till eller uppdaterar en prenumerant i vald lista.
     * Returnerar 'added', 'updated', 'unchanged' eller 'error'.
     */
    public static function add($email, $full_name) {
        if (!self::enabled() || !is_email($email)) return 'error';
        $list = self::list_id();
        $parts = preg_split('/\s+/', trim((string) $full_name), 2);
        try {
            $newsletter = Newsletter::instance();
            $existing = method_exists($newsletter, 'get_user') ? $newsletter->get_user($email) : null;

            if (!$existing) {
                $result = TNP::add_subscriber([
                    'email' => $email,
                    'name' => $parts[0] ?? '',
                    'surname' => $parts[1] ?? '',
                    'status' => 'C', // samtycket ges i anmälningsformuläret
                    'lists' => [$list],
                ]);
                if (is_wp_error($result)) {
                    error_log('Enkel kursanmälan: kunde inte lägga till ' . $email . ' i The Newsletter Plugin: ' . $result->get_error_message());
                    return 'error';
                }
                return 'added';
            }

            // Befintlig prenumerant: lägg till i listan. Har personen tidigare avslutat sin prenumeration
            // aktiveras den igen, eftersom hen nu aktivt har kryssat i att hen vill ha nyhetsbrev.
            $field = 'list_' . $list;
            if ((int) ($existing->$field ?? 0) === 1 && ($existing->status ?? '') === 'C') return 'unchanged';
            if (!method_exists($newsletter, 'save_user')) return 'error';
            $existing->$field = 1;
            $existing->status = 'C';
            $newsletter->save_user($existing);
            return 'updated';
        } catch (\Throwable $e) {
            error_log('Enkel kursanmälan: fel i kopplingen till The Newsletter Plugin: ' . $e->getMessage());
            return 'error';
        }
    }

    /** Inställningsfält, visas på pluginets inställningssida. */
    public static function settings_fields() {
        $lists = self::lists();
        $current = self::list_id(); ?>
        <h2>Nyhetsbrev (The Newsletter Plugin)</h2>
        <table class="form-table">
        <tr><th>Status</th><td>
            <?php if (!self::available()): ?>
                <strong>The Newsletter Plugin är inte installerat eller aktiverat.</strong> Installera det för att automatiskt lägga till dem som vill ha nyhetsbrev.
            <?php elseif (!$current): ?>
                <strong>Installerat, men ingen lista är vald.</strong>
            <?php else: ?>
                <strong style="color:#087b37">Aktiv.</strong> Den som kryssar i "information om kommande kurser" läggs i listan nedan som bekräftad prenumerant.
            <?php endif; ?>
        </td></tr>
        <?php if (self::available()): ?>
        <tr><th><label for="eka_tnp_list">Lista för nyhetsbrev</label></th><td>
            <?php if ($lists): ?>
            <select id="eka_tnp_list" name="eka_tnp_list"><option value="0">– Lägg inte till i någon lista –</option>
            <?php foreach ($lists as $id => $name): ?><option value="<?php echo (int) $id; ?>" <?php selected($current, $id); ?>><?php echo esc_html($id . ': ' . $name); ?></option><?php endforeach; ?></select>
            <?php else: ?>
            <input type="number" min="0" id="eka_tnp_list" name="eka_tnp_list" value="<?php echo (int) $current; ?>" class="small-text">
            <p class="description">Listans nummer under Newsletter → Listor. 0 = av.</p>
            <?php endif; ?>
        </td></tr>
        <?php endif; ?>
        </table>
    <?php }

    /** Formulär för att föra över befintliga prenumeranter. Visas utanför inställningsformuläret. */
    public static function sync_form() {
        if (!self::enabled()) return;
        global $wpdb;
        $count = (int) $wpdb->get_var('SELECT COUNT(DISTINCT email) FROM ' . EKA_DB::table('subscribers') . " WHERE newsletter=1 AND status='subscribed'"); ?>
        <h2>För över till nyhetsbrevet</h2>
        <p><?php echo esc_html(sprintf('%d adresser i kursernas maillistor har samtyckt till information om kommande kurser.', $count)); ?> Nya anmälningar läggs till automatiskt; knappen för över dem som redan finns.</p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="eka_tnp_sync"><?php wp_nonce_field('eka_tnp_sync'); ?>
            <?php submit_button('För över till The Newsletter Plugin', 'secondary', 'submit', false); ?>
        </form>
    <?php }

    public static function handle_sync() {
        if (!current_user_can('manage_options')) wp_die('Behörighet saknas.');
        check_admin_referer('eka_tnp_sync');
        global $wpdb;
        $rows = $wpdb->get_results('SELECT email, MAX(name) AS name FROM ' . EKA_DB::table('subscribers') . " WHERE newsletter=1 AND status='subscribed' GROUP BY email");
        $stats = ['added' => 0, 'updated' => 0, 'unchanged' => 0, 'error' => 0];
        foreach ($rows as $r) $stats[self::add($r->email, $r->name)]++;
        EKA_Core::add_notice(sprintf('Nyhetsbrev: %d tillagda, %d uppdaterade, %d fanns redan, %d fel.', $stats['added'], $stats['updated'], $stats['unchanged'], $stats['error']), $stats['error'] ? 'warning' : 'success');
        wp_safe_redirect(admin_url('edit.php?post_type=eka_course&page=eka-settings'));
        exit;
    }
}
