<?php
/**
 * Plugin Name: Enkel kursanmälan med Swish
 * Description: Kurser med egna anmälningslistor, kategorier med betalregler, välkomstmail, utskick och Swish-betalning.
 * Version: 2.0.0
 * Requires PHP: 7.4
 * Requires at least: 6.2
 * Author: Websmedjan
 * Author URI: https://github.com/JohanWebsmedjan
 * Plugin URI: https://github.com/JohanWebsmedjan/enkel-kursanmalan
 * Update URI: https://github.com/JohanWebsmedjan/enkel-kursanmalan
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: enkel-kursanmalan
 */

if (!defined('ABSPATH')) exit;

define('EKA_VERSION', '2.0.0');
define('EKA_FILE', __FILE__);
define('EKA_DIR', plugin_dir_path(__FILE__));
define('EKA_URL', plugin_dir_url(__FILE__));

require_once EKA_DIR . 'includes/class-eka-db.php';
require_once EKA_DIR . 'includes/class-eka-core.php';
require_once EKA_DIR . 'includes/class-eka-courses.php';
require_once EKA_DIR . 'includes/class-eka-mailer.php';
require_once EKA_DIR . 'includes/class-eka-admin.php';
require_once EKA_DIR . 'includes/class-eka-frontend.php';
require_once EKA_DIR . 'includes/class-eka-newsletter.php';
require_once EKA_DIR . 'includes/class-eka-updater.php';

register_activation_hook(__FILE__, function() {
    EKA_DB::install();
    EKA_Courses::register_types();
    flush_rewrite_rules();
});
register_deactivation_hook(__FILE__, 'flush_rewrite_rules');

EKA_DB::init();
EKA_Core::init();
EKA_Courses::init();
EKA_Admin::init();
EKA_Frontend::init();
EKA_Newsletter::init();
EKA_Updater::init();
