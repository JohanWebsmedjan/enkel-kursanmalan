<?php
if (!defined('ABSPATH')) exit;

/**
 * Uppdateringar från GitHub-releaser.
 *
 * Pluginets huvud har "Update URI: https://github.com/...", så WordPress (5.8+) frågar filtret
 * update_plugins_github.com efter nya versioner. Vi svarar med den senaste releasen på GitHub
 * och dess ZIP-fil. Då fungerar både "Uppdatera nu" och WordPress automatiska uppdateringar.
 */
final class EKA_Updater {
    const REPO = 'JohanWebsmedjan/enkel-kursanmalan';
    const SLUG = 'enkel-kursanmalan';
    const CACHE = 'eka_github_release';
    const CACHE_TTL = 6 * HOUR_IN_SECONDS;

    public static function init() {
        add_filter('update_plugins_github.com', [__CLASS__, 'check_update'], 10, 3);
        add_filter('plugins_api', [__CLASS__, 'plugin_info'], 20, 3);
        add_filter('upgrader_source_selection', [__CLASS__, 'fix_folder_name'], 10, 4);
        add_filter('plugin_row_meta', [__CLASS__, 'row_meta'], 10, 2);
        add_action('admin_post_eka_check_update', [__CLASS__, 'handle_check_now']);
        add_action('upgrader_process_complete', [__CLASS__, 'clear_cache'], 10, 0);
    }

    private static function basename() {
        return plugin_basename(EKA_FILE);
    }

    /** "v2.1.0" -> "2.1.0" */
    public static function normalize_version($tag) {
        return ltrim(trim((string) $tag), 'vV');
    }

    /** Senaste release som ['version', 'package', 'url', 'notes', 'published'], eller null. Cachas. */
    public static function latest_release($force = false) {
        $cached = $force ? false : get_transient(self::CACHE);
        if (is_array($cached)) return $cached ?: null;

        $res = wp_remote_get('https://api.github.com/repos/' . self::REPO . '/releases/latest', [
            'timeout' => 10,
            'headers' => ['Accept' => 'application/vnd.github+json', 'User-Agent' => 'enkel-kursanmalan-updater'],
        ]);
        if (is_wp_error($res) || (int) wp_remote_retrieve_response_code($res) !== 200) {
            set_transient(self::CACHE, [], HOUR_IN_SECONDS); // försök igen om en timme
            return null;
        }
        $release = self::parse_release(json_decode(wp_remote_retrieve_body($res), true));
        set_transient(self::CACHE, $release ?: [], self::CACHE_TTL);
        return $release;
    }

    /** Plockar ut version och ZIP-fil ur GitHubs svar. Utkast och förhandsversioner ignoreras. */
    public static function parse_release($data) {
        if (!is_array($data) || !empty($data['draft']) || !empty($data['prerelease']) || empty($data['tag_name'])) return null;
        $version = self::normalize_version($data['tag_name']);
        if (!preg_match('/^\d+(\.\d+)*$/', $version)) return null;
        $package = '';
        foreach ((array) ($data['assets'] ?? []) as $asset) {
            $name = (string) ($asset['name'] ?? '');
            if (strpos($name, self::SLUG) === 0 && substr($name, -4) === '.zip' && !empty($asset['browser_download_url'])) {
                $package = $asset['browser_download_url'];
                break;
            }
        }
        if ($package === '') return null; // utan byggd ZIP-fil erbjuds ingen uppdatering
        return [
            'version' => $version,
            'package' => $package,
            'url' => (string) ($data['html_url'] ?? 'https://github.com/' . self::REPO),
            'notes' => (string) ($data['body'] ?? ''),
            'published' => (string) ($data['published_at'] ?? ''),
        ];
    }

    public static function check_update($update, $plugin_data, $plugin_file) {
        if ($plugin_file !== self::basename()) return $update;
        $release = self::latest_release();
        if (!$release) return $update;
        return [
            'id' => 'github.com/' . self::REPO,
            'slug' => self::SLUG,
            'plugin' => $plugin_file,
            'version' => $release['version'],
            'url' => $release['url'],
            'package' => $release['package'],
            'requires_php' => $plugin_data['RequiresPHP'] ?? '7.4',
            'requires' => $plugin_data['RequiresWP'] ?? '',
        ];
    }

    /** Innehållet i "Visa detaljer"-rutan på tilläggssidan. */
    public static function plugin_info($result, $action, $args) {
        if ($action !== 'plugin_information' || ($args->slug ?? '') !== self::SLUG) return $result;
        $release = self::latest_release();
        if (!function_exists('get_plugin_data')) require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $data = get_plugin_data(EKA_FILE, false, false);
        return (object) [
            'name' => $data['Name'],
            'slug' => self::SLUG,
            'version' => $release['version'] ?? $data['Version'],
            'author' => '<a href="https://github.com/JohanWebsmedjan">Websmedjan</a>',
            'homepage' => 'https://github.com/' . self::REPO,
            'requires_php' => $data['RequiresPHP'],
            'requires' => $data['RequiresWP'],
            'last_updated' => $release['published'] ?? '',
            'download_link' => $release['package'] ?? '',
            'sections' => [
                'description' => esc_html($data['Description']),
                'changelog' => $release ? self::notes_html($release['notes']) : 'Ingen release hittades.',
            ],
        ];
    }

    /** Enkel omvandling av releaseanteckningar (Markdown) till HTML. */
    public static function notes_html($markdown) {
        $html = ''; $in_list = false;
        foreach (preg_split('/\R/', trim((string) $markdown)) as $line) {
            $line = rtrim($line);
            if (preg_match('/^\s*[-*]\s+(.*)$/', $line, $m)) {
                if (!$in_list) { $html .= '<ul>'; $in_list = true; }
                $html .= '<li>' . esc_html($m[1]) . '</li>';
                continue;
            }
            if ($in_list) { $html .= '</ul>'; $in_list = false; }
            if ($line === '') continue;
            $html .= preg_match('/^#+\s*(.*)$/', $line, $m) ? '<h4>' . esc_html($m[1]) . '</h4>' : '<p>' . esc_html($line) . '</p>';
        }
        return $html . ($in_list ? '</ul>' : '') ?: 'Inga anteckningar.';
    }

    /** Ser till att den uppackade mappen heter enkel-kursanmalan även om ZIP-filen har ett annat mappnamn. */
    public static function fix_folder_name($source, $remote_source, $upgrader, $hook_extra = []) {
        global $wp_filesystem;
        if (($hook_extra['plugin'] ?? '') !== self::basename() || !$wp_filesystem) return $source;
        $wanted = trailingslashit($remote_source) . self::SLUG . '/';
        if (untrailingslashit($source) === untrailingslashit($wanted)) return $source;
        if (!$wp_filesystem->exists(trailingslashit($source) . 'enkel-kursanmalan.php')) return new WP_Error('eka_bad_package', 'Uppdateringsfilen innehåller inte Enkel kursanmälan.');
        return $wp_filesystem->move($source, $wanted, true) ? $wanted : new WP_Error('eka_rename_failed', 'Kunde inte döpa om mappen i uppdateringen.');
    }

    public static function row_meta($links, $file) {
        if ($file !== self::basename() || !current_user_can('update_plugins')) return $links;
        $links[] = '<a href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=eka_check_update'), 'eka_check_update')) . '">Sök efter uppdatering</a>';
        return $links;
    }

    public static function handle_check_now() {
        if (!current_user_can('update_plugins')) wp_die('Behörighet saknas.');
        check_admin_referer('eka_check_update');
        $release = self::latest_release(true);
        delete_site_transient('update_plugins');
        wp_update_plugins();
        if (!function_exists('get_plugin_data')) require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $installed = get_plugin_data(EKA_FILE, false, false)['Version'];
        if (!$release) $msg = ['Kunde inte hämta senaste versionen från GitHub. Försök igen senare.', 'error'];
        elseif (version_compare($release['version'], $installed, '>')) $msg = [sprintf('Version %s finns. Uppdatera i listan nedan.', $release['version']), 'success'];
        else $msg = [sprintf('Du har senaste versionen (%s).', $installed), 'success'];
        EKA_Core::add_notice($msg[0], $msg[1]);
        wp_safe_redirect(admin_url('plugins.php'));
        exit;
    }

    public static function clear_cache() {
        delete_transient(self::CACHE);
    }
}
