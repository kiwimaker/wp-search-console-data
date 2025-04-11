<?php
/**
 * Plugin Name:       WP Search Console Data
 * Plugin URI:        https://nexir.es/
 * Description:       Integrates Google Search Console data into the WordPress admin area.
 * Version:           1.0.0
 * Requires at least: 5.2
 * Requires PHP:      8.1
 * Author:            Nexir
 * Author URI:        https://nexir.es/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-search-console-data
 * Domain Path:       /languages
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define constants.
define( 'WPSCD_VERSION', '1.0.0' );
define( 'WPSCD_PATH', plugin_dir_path( __FILE__ ) );
define( 'WPSCD_URL', plugin_dir_url( __FILE__ ) );

// Include the Composer autoloader.
if ( file_exists( WPSCD_PATH . 'vendor/autoload.php' ) ) {
	require_once WPSCD_PATH . 'vendor/autoload.php';
} else {
	// Handle the case where Composer dependencies are missing.
	add_action( 'admin_notices', function() {
		?>
		<div class="notice notice-error is-dismissible">
			<p><?php esc_html_e( 'WP Search Console Data: Composer dependencies not found. Please run "composer install" in the plugin directory.', 'wp-search-console-data' ); ?></p>
		</div>
		<?php
		return; // Stop execution if dependencies are missing.
	} );
}

// Include plugin classes.
require_once WPSCD_PATH . 'includes/class-auth.php';
require_once WPSCD_PATH . 'includes/class-settings.php';
require_once WPSCD_PATH . 'includes/class-api.php';
require_once WPSCD_PATH . 'includes/class-cache.php';
require_once WPSCD_PATH . 'includes/class-admin-columns.php';
require_once WPSCD_PATH . 'includes/class-admin-bar.php';
require_once WPSCD_PATH . 'includes/class-logger.php';

// Instantiate classes.
$wpscd_auth = new WPSCD_Auth();
$wpscd_cache = new WPSCD_Cache();
$wpscd_logger = new WPSCD_Logger();
$wpscd_api = new WPSCD_API( $wpscd_auth, $wpscd_cache, $wpscd_logger );
$wpscd_settings = new WPSCD_Settings( $wpscd_auth, $wpscd_api, $wpscd_cache, $wpscd_logger );
$wpscd_admin_columns = new WPSCD_Admin_Columns( $wpscd_api );
$wpscd_admin_bar = new WPSCD_Admin_Bar( $wpscd_api );

/**
 * Initialize the plugin.
 */
function wpscd_init() {
	// Load plugin textdomain for translation.
	load_plugin_textdomain( 'wp-search-console-data', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

	// Initialization logic moved to class constructors or specific hooks.
}
add_action( 'plugins_loaded', 'wpscd_init' ); 