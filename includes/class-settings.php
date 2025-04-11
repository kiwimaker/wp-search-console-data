<?php
/**
 * Handles the admin settings page for the plugin.
 *
 * @package    WP_Search_Console_Data
 * @subpackage WP_Search_Console_Data/includes
 * @author     Kiwimaker <info@nexir.es>
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPSCD_Settings {

	/**
	 * Auth handler instance.
	 * @var WPSCD_Auth
	 */
	private $auth_handler;

	/**
	 * API handler instance.
	 * @var WPSCD_API
	 */
	private $api_handler;

	/**
	 * Cache handler instance.
	 * @var WPSCD_Cache
	 */
	private $cache_handler;

	/**
	 * Logger instance.
	 * @var WPSCD_Logger
	 */
	private $logger;

	/**
	 * Option group name.
	 */
	private $option_group = 'wpscd_options';

	/**
	 * Option name in wp_options table.
	 */
	private $option_name = 'wpscd_settings';

	/**
	 * Settings page slug.
	 */
	private $settings_page_slug = 'wp-search-console-data';

	/**
	 * Initialize the class and set its properties.
	 * @param WPSCD_Auth $auth_handler Instance of the Auth handler class.
	 * @param WPSCD_API  $api_handler  Instance of the API handler class.
	 * @param WPSCD_Cache $cache_handler Instance of the Cache handler class.
	 * @param WPSCD_Logger $logger Instance of the Logger class.
	 */
	public function __construct( WPSCD_Auth $auth_handler, WPSCD_API $api_handler, WPSCD_Cache $cache_handler, WPSCD_Logger $logger ) {
		$this->auth_handler = $auth_handler;
		$this->api_handler = $api_handler;
		$this->cache_handler = $cache_handler;
		$this->logger = $logger;
		add_action( 'admin_menu', array( $this, 'add_plugin_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_action_wpscd_clear_cache', array( $this, 'handle_clear_cache' ) );
		add_action( 'admin_action_wpscd_clear_log', array( $this, 'handle_clear_log' ) );
		add_action( 'admin_notices', array( $this, 'show_cache_cleared_notice' ) );
		add_action( 'admin_notices', array( $this, 'show_log_cleared_notice' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_styles' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
	}

	/**
	 * Enqueue styles for the admin settings page.
	 *
	 * @param string $hook_suffix The current admin page hook.
	 */
	public function enqueue_admin_styles( $hook_suffix ) {
		// Check if we are on our settings page
		// The hook suffix for add_options_page is settings_page_{menu_slug}
		if ( 'settings_page_' . $this->settings_page_slug !== $hook_suffix ) {
			return;
		}
		wp_enqueue_style(
			'wpscd-admin-styles',
			WPSCD_URL . 'assets/css/admin.css',
			array(),
			WPSCD_VERSION // Use plugin version for cache busting
		);
	}

	/**
	 * Enqueue styles and scripts for the admin settings page and list tables.
	 *
	 * @param string $hook_suffix The current admin page hook.
	 */
	public function enqueue_admin_scripts( $hook_suffix ) {
		// Enqueue settings page assets
		if ( 'settings_page_' . $this->settings_page_slug === $hook_suffix ) {
			wp_enqueue_style(
				'wpscd-admin-styles',
				WPSCD_URL . 'assets/css/admin.css',
				array(),
				WPSCD_VERSION
			);
			wp_enqueue_script(
				'wpscd-admin-scripts',
				WPSCD_URL . 'assets/js/admin.js',
				array( 'jquery' ),
				WPSCD_VERSION,
				true
			);
		}

		// Enqueue table sorting script on post list pages
		if ( 'edit.php' === $hook_suffix ) {
			// Check the current screen's post type
			$screen = get_current_screen();
			if ( $screen && in_array( $screen->post_type, array( 'post', 'page' ) ) ) {
				wp_enqueue_script(
					'wpscd-admin-table-sort',
					WPSCD_URL . 'assets/js/admin-table-sort.js',
					array( 'jquery' ),
					WPSCD_VERSION,
					true
				);
			}
		}
	}

	/**
	 * Add options page.
	 */
	public function add_plugin_page() {
		add_options_page(
			__( 'WP Search Console Data', 'wp-search-console-data' ), // Page title
			__( 'Search Console Data', 'wp-search-console-data' ),  // Menu title
			'manage_options',                             // Capability
			$this->settings_page_slug,                      // Menu slug
			array( $this, 'create_admin_page' )            // Function
		);
	}

	/**
	 * Register settings using the Settings API.
	 */
	public function register_settings() {
		register_setting(
			$this->option_group, // Option group
			$this->option_name, // Option name
			array( $this, 'sanitize_settings' ) // Sanitize callback
		);

		add_settings_section(
			'wpscd_setting_section_service_account', // ID CHANGED
			__( 'Google Service Account Credentials', 'wp-search-console-data' ), // Title CHANGED
			array( $this, 'print_service_account_section_info' ), // Callback CHANGED
			$this->settings_page_slug // Page
		);

		add_settings_field(
			'service_account_key', // ID CHANGED
			__( 'Service Account JSON Key', 'wp-search-console-data' ), // Title CHANGED
			array( $this, 'service_account_key_callback' ), // Callback CHANGED
			$this->settings_page_slug, // Page
			'wpscd_setting_section_service_account' // Section CHANGED
		);

		// Add section for Property Selection (only if authenticated via Service Account)
		if ( $this->auth_handler->is_authenticated() ) {
			add_settings_section(
				'wpscd_setting_section_property', // ID
				__( 'Search Console Property Selection', 'wp-search-console-data' ), // Title
				array( $this, 'print_property_section_info' ), // Callback
				$this->settings_page_slug // Page
			);

			add_settings_field(
				'selected_property', // ID
				__( 'Select Property', 'wp-search-console-data' ), // Title
				array( $this, 'property_select_callback' ), // Callback
				$this->settings_page_slug, // Page
				'wpscd_setting_section_property' // Section
			);
		}

		// Add section for Cache Management
		add_settings_section(
			'wpscd_setting_section_cache', // ID
			__( 'Cache Management', 'wp-search-console-data' ), // Title
			array( $this, 'print_cache_section_info' ), // Callback
			$this->settings_page_slug // Page
		);

		add_settings_field(
			'clear_cache_button', // ID
			__( 'Clear Cache', 'wp-search-console-data' ), // Title
			array( $this, 'clear_cache_button_callback' ), // Callback
			$this->settings_page_slug, // Page
			'wpscd_setting_section_cache' // Section
		);

		// Add section for Logging
		add_settings_section(
			'wpscd_setting_section_logging', // ID
			__( 'Debug Logging', 'wp-search-console-data' ), // Title
			array( $this, 'print_logging_section_info' ), // Callback
			$this->settings_page_slug // Page
		);

		add_settings_field(
			'enable_logging', // ID
			__( 'Enable Logging', 'wp-search-console-data' ), // Title
			array( $this, 'enable_logging_callback' ), // Callback
			$this->settings_page_slug, // Page
			'wpscd_setting_section_logging' // Section
		);

		add_settings_field(
			'clear_log_button', // ID
			__( 'Clear Log File', 'wp-search-console-data' ), // Title
			array( $this, 'clear_log_button_callback' ), // Callback
			$this->settings_page_slug, // Page
			'wpscd_setting_section_logging' // Section
		);

		// Add section for General Settings
		add_settings_section(
			'wpscd_setting_section_general', // ID
			__( 'General Settings', 'wp-search-console-data' ), // Title
			null, // No description callback needed
			$this->settings_page_slug // Page
		);

		add_settings_field(
			'date_range', // ID
			__( 'Default Date Range', 'wp-search-console-data' ), // Title
			array( $this, 'date_range_callback' ), // Callback
			$this->settings_page_slug, // Page
			'wpscd_setting_section_general' // Section
		);

		add_settings_field(
			'show_extra_columns', // ID
			__( 'Show CTR & Position', 'wp-search-console-data' ), // Title
			array( $this, 'show_extra_columns_callback' ), // Callback
			$this->settings_page_slug, // Page
			'wpscd_setting_section_general' // Section
		);

		add_settings_field(
			'combine_clicks_impressions', // ID
			__( 'Combine Clicks/Impressions', 'wp-search-console-data' ), // Title
			array( $this, 'combine_clicks_impressions_callback' ), // Callback
			$this->settings_page_slug, // Page
			'wpscd_setting_section_general' // Section
		);
	}

	/**
	 * Sanitize each setting field as needed.
	 *
	 * @param array $input Contains all settings fields as array keys
	 * @return array Sanitized settings.
	 */
	public function sanitize_settings( $input ) {
		$sanitized_input = array();

		// Sanitize Service Account Key (treat as potentially large text block)
		if ( isset( $input['service_account_key'] ) ) {
			// Basic sanitization, mainly ensuring it looks somewhat like JSON
			$maybe_json = trim( $input['service_account_key'] );
			if ( ! empty( $maybe_json ) && '{' === $maybe_json[0] && '}' === substr( $maybe_json, -1 ) ) {
				// Attempt to decode to validate JSON structure (but store the original string)
				json_decode( $maybe_json );
				if ( json_last_error() === JSON_ERROR_NONE ) {
					$sanitized_input['service_account_key'] = $maybe_json;
				} else {
					// Add error? For now, just don't save invalid JSON.
					add_settings_error( $this->option_name, 'invalid_json', __( 'Invalid JSON provided for Service Account Key.', 'wp-search-console-data' ) );
				}
			} elseif ( empty( $maybe_json ) ) {
				// Allow clearing the key
				$sanitized_input['service_account_key'] = '';
			}
		}

		// Sanitize selected property
		if ( isset( $input['selected_property'] ) ) {
			$sanitized_input['selected_property'] = sanitize_text_field( $input['selected_property'] );
		}

		// Sanitize date range
		if ( isset( $input['date_range'] ) ) {
			// Ensure it's one of the allowed values
			$allowed_ranges = array( '30', '90', '180', '365' );
			if ( in_array( $input['date_range'], $allowed_ranges, true ) ) {
				$sanitized_input['date_range'] = $input['date_range'];
			} else {
				$sanitized_input['date_range'] = '30'; // Default to 30 if invalid
			}
		}

		// Sanitize show extra columns checkbox
		$sanitized_input['show_extra_columns'] = isset( $input['show_extra_columns'] ) ? '1' : '0';

		// Sanitize combine clicks/impressions checkbox
		$sanitized_input['combine_clicks_impressions'] = isset( $input['combine_clicks_impressions'] ) ? '1' : '0';

		// Sanitize enable logging checkbox
		$sanitized_input['enable_logging'] = isset( $input['enable_logging'] ) ? '1' : '0';

		// Clear log file if logging is disabled
		if ( empty( $sanitized_input['enable_logging'] ) ) {
			$this->logger->clear_log();
		}

		// TODO: Add sanitization for other fields (token, etc.) later.

		return $sanitized_input;
	}

	/**
	 * Print the Service Account Section text.
	 */
	public function print_service_account_section_info() {
		print '<p>' . __( 'Enter the content of the JSON key file for your Google Service Account.', 'wp-search-console-data' ) . '</p>';
		print '<p>' . sprintf(
			__( 'You need to: 1) <a href="%s" target="_blank">Create a Service Account</a> in Google Cloud Console, 2) Enable the Google Search Console API, 3) Download the JSON key file, 4) <a href="%s" target="_blank">Add the Service Account email address</a> as a user with at least \'Restricted\' access to your property in Google Search Console settings, and 5) Paste the *entire content* of the downloaded JSON file below.', 'wp-search-console-data' ),
			'https://console.cloud.google.com/iam-admin/serviceaccounts',
			'https://search.google.com/search-console/settings' // Generic link, user needs to find their property
		) . '</p>';

		// Display Service Account email if configured
		$options = get_option( $this->option_name );
		$key_content = $options['service_account_key'] ?? '';
		if( defined( 'WPSCD_SERVICE_ACCOUNT_KEY_PATH' ) && file_exists( WPSCD_SERVICE_ACCOUNT_KEY_PATH ) ) {
			$key_content = file_get_contents( WPSCD_SERVICE_ACCOUNT_KEY_PATH );
		}

		if ( ! empty( $key_content ) ) {
			$key_data = json_decode( $key_content, true );
			if ( isset( $key_data['client_email'] ) ) {
				$email = $key_data['client_email'];
				print '<div class="wpscd-service-account-status">';
				print '<strong>' . __( 'Detected Service Account Email:', 'wp-search-console-data' ) . '</strong> ';
				print '<span id="wpscd-service-email">' . esc_html( $email ) . '</span>';
				// Add copy button
				printf(
					' <button id="wpscd-copy-service-email" class="button button-small wpscd-copy-button" title="%s"><span class="dashicons dashicons-admin-page"></span></button>',
					esc_attr__( 'Copy Email', 'wp-search-console-data' )
				);
				print '<span id="wpscd-copy-feedback" class="wpscd-copy-feedback"></span>';
				print '</div>';
			}
		}

		print '<p><strong>' . __( 'Security Note:', 'wp-search-console-data' ) . '</strong> ' . __( 'This key grants access to your Search Console data. While stored in the database, consider server security. Alternatively, advanced users can define the constant <code>WPSCD_SERVICE_ACCOUNT_KEY_PATH</code> in <code>wp-config.php</code> with the absolute path to the JSON file on the server (recommended for better security).', 'wp-search-console-data' ) . '</p>';
	}

	/**
	 * Callback for the Service Account JSON key textarea.
	 */
	public function service_account_key_callback() {
		$options = get_option( $this->option_name );
		$key_content = $options['service_account_key'] ?? '';
		$disabled = defined( 'WPSCD_SERVICE_ACCOUNT_KEY_PATH' );
		$placeholder = $disabled ? __( 'Key path defined in wp-config.php', 'wp-search-console-data' ) : __( 'Paste your Service Account JSON key content here...', 'wp-search-console-data' );

		printf(
			'<textarea id="service_account_key" name="%s[service_account_key]" rows="10" cols="80" class="large-text" placeholder="%s" %s>%s</textarea>',
			esc_attr( $this->option_name ),
			esc_attr( $placeholder ),
			$disabled ? 'disabled' : '',
			esc_textarea( $disabled ? '' : $key_content )
		);
		if($disabled) {
			printf('<p class="description">%s</p>', __( 'To manage the key via the database, remove the <code>WPSCD_SERVICE_ACCOUNT_KEY_PATH</code> constant from your <code>wp-config.php</code> file.', 'wp-search-console-data' ));
		}
	}

	/**
	 * Print the Property Section text.
	 */
	public function print_property_section_info() {
		print __( 'Select the Search Console property (that the Service Account has access to) which corresponds to this WordPress site.', 'wp-search-console-data' );
	}

	/**
	 * Callback for the property selection dropdown.
	 */
	public function property_select_callback() {
		$options = get_option( $this->option_name );
		$selected_property = $options['selected_property'] ?? '';

		// Ensure we are authenticated before trying to fetch sites
		if ( ! $this->auth_handler->is_authenticated() ) {
			print __( 'Please configure and save a valid Service Account Key first.', 'wp-search-console-data' );
			return;
		}

		$sites = $this->api_handler->get_filtered_sites();

		if ( is_null( $sites ) ) {
			// This case might occur if auth was true initially but client failed later?
			print __( 'Authentication failed. Please check your Service Account Key and permissions.', 'wp-search-console-data' );
			return;
		}

		if ( isset( $sites['error'] ) ) {
			printf( __( 'Error fetching properties: %s Ensure the Service Account has access to Search Console.', 'wp-search-console-data' ), esc_html( $sites['error'] ) );
			return;
		}

		if ( empty( $sites ) ) {
			print __( 'No matching Search Console properties found for this site that the Service Account has access to.', 'wp-search-console-data' );
			return;
		}

		// If only one site matches, select it automatically (though user can still change)
		if ( count( $sites ) === 1 && empty( $selected_property ) ) {
			$selected_property = $sites[0];
		}

		printf( '<select id="selected_property" name="%s[selected_property]">', esc_attr( $this->option_name ) );
		printf( '<option value="">%s</option>', esc_html__( '-- Select a Property --', 'wp-search-console-data' ) );

		foreach ( $sites as $site_url ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $site_url ),
				selected( $selected_property, $site_url, false ),
				esc_html( $site_url )
			);
		}

		print '</select>';
		print '<p class="description">' . esc_html__( 'Only properties matching your site domain and accessible by the Service Account are shown.', 'wp-search-console-data' ) . '</p>';
	}

	/**
	 * Print the Cache Section text.
	 */
	public function print_cache_section_info() {
		print __( 'API data is cached temporarily to improve performance and reduce API calls.', 'wp-search-console-data' );

		// Get cache count
		$cache_count = $this->cache_handler->get_cache_count();
		printf(
			'<p>' . __( 'Currently, there are <strong>%d</strong> items stored in the cache.', 'wp-search-console-data' ) . '</p>',
			esc_html( number_format_i18n( $cache_count ) )
		);
	}

	/**
	 * Callback for the clear cache button.
	 */
	public function clear_cache_button_callback() {
		$clear_cache_url = add_query_arg(
			array(
				'action' => 'wpscd_clear_cache',
				'_wpnonce' => wp_create_nonce( 'wpscd_clear_cache_nonce' )
			),
			admin_url( 'admin.php' ) // Use admin.php for admin actions
		);
		printf(
			'<a href="%s" class="button">%s</a>',
			esc_url( $clear_cache_url ),
			esc_html__( 'Clear Plugin Cache Now', 'wp-search-console-data' )
		);
		print '<p class="description">' . esc_html__( 'Click this button to clear all cached Search Console data.', 'wp-search-console-data' ) . '</p>';
	}

	/**
	 * Callback for the date range selection dropdown.
	 */
	public function date_range_callback() {
		$options = get_option( $this->option_name );
		// Default to 30 days if not set
		$selected_range = $options['date_range'] ?? '30';
		$ranges = array(
			'30' => __( 'Last 30 Days', 'wp-search-console-data' ),
			'90' => __( 'Last 90 Days', 'wp-search-console-data' ),
			'180' => __( 'Last 180 Days', 'wp-search-console-data' ),
			'365' => __( 'Last 365 Days', 'wp-search-console-data' ),
		);

		printf( '<select id="date_range" name="%s[date_range]">', esc_attr( $this->option_name ) );

		foreach ( $ranges as $value => $label ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $value ),
				selected( $selected_range, $value, false ),
				esc_html( $label )
			);
		}

		print '</select>';
		print '<p class="description">' . esc_html__( 'Select the default period for fetching Search Console data.', 'wp-search-console-data' ) . '</p>';
	}

	/**
	 * Callback for the show extra columns checkbox.
	 */
	public function show_extra_columns_callback() {
		$options = get_option( $this->option_name );
		$checked = isset( $options['show_extra_columns'] ) && '1' === $options['show_extra_columns'];

		printf(
			'<input type="checkbox" id="show_extra_columns" name="%s[show_extra_columns]" value="1" %s />',
			esc_attr( $this->option_name ),
			checked( $checked, true, false )
		);
		print '<label for="show_extra_columns"> ' . esc_html__( 'Check to display CTR and Position columns in post/page lists.', 'wp-search-console-data' ) . '</label>';
	}

	/**
	 * Callback for the combine clicks/impressions checkbox.
	 */
	public function combine_clicks_impressions_callback() {
		$options = get_option( $this->option_name );
		$checked = isset( $options['combine_clicks_impressions'] ) && '1' === $options['combine_clicks_impressions'];

		printf(
			'<input type="checkbox" id="combine_clicks_impressions" name="%s[combine_clicks_impressions]" value="1" %s />',
			esc_attr( $this->option_name ),
			checked( $checked, true, false )
		);
		print '<label for="combine_clicks_impressions"> ' . esc_html__( 'Check to display Clicks and Impressions in a single column (e.g., Clicks / Impr.).', 'wp-search-console-data' ) . '</label>';
	}

	/**
	 * Print the Logging Section text.
	 */
	public function print_logging_section_info() {
		print __( 'Enable logging for debugging purposes. Logs API calls, cache hits/misses, and errors.', 'wp-search-console-data' );
		$log_path = $this->logger->get_log_file_path();
		if ( $log_path && file_exists( $log_path ) ) {
			printf(
				'<p>' . __( 'Log file location: %s', 'wp-search-console-data' ) . '</p>',
				'<code>' . esc_html( str_replace( ABSPATH, '/', $log_path ) ) . '</code>' // Show relative path
			);
		} elseif ( $log_path ) {
            printf(
				'<p>' . __( 'Log file will be created at: %s', 'wp-search-console-data' ) . '</p>',
				'<code>' . esc_html( str_replace( ABSPATH, '/', $log_path ) ) . '</code>'
			);
        }
	}

	/**
	 * Callback for the enable logging checkbox.
	 */
	public function enable_logging_callback() {
		$options = get_option( $this->option_name );
		$checked = isset( $options['enable_logging'] ) && '1' === $options['enable_logging'];

		printf(
			'<input type="checkbox" id="enable_logging" name="%s[enable_logging]" value="1" %s />',
			esc_attr( $this->option_name ),
			checked( $checked, true, false )
		);
		print '<label for="enable_logging"> ' . esc_html__( 'Enable debug logging to the file specified above.', 'wp-search-console-data' ) . '</label>';
	}

	/**
	 * Callback for the clear log button.
	 */
	public function clear_log_button_callback() {
		$clear_log_url = add_query_arg(
			array(
				'action' => 'wpscd_clear_log',
				'_wpnonce' => wp_create_nonce( 'wpscd_clear_log_nonce' )
			),
			admin_url( 'admin.php' )
		);
		printf(
			'<a href="%s" class="button">%s</a>',
			esc_url( $clear_log_url ),
			esc_html__( 'Clear Log File Now', 'wp-search-console-data' )
		);
		print '<p class="description">' . esc_html__( 'Click this button to delete the current debug log file.', 'wp-search-console-data' ) . '</p>';
	}

	/**
	 * Handle the clear cache action request.
	 */
	public function handle_clear_cache() {
		// Verify nonce
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ), 'wpscd_clear_cache_nonce' ) ) {
			wp_die( __( 'Invalid nonce specified', 'wp-search-console-data' ) );
		}

		// Check user capabilities
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'You do not have sufficient permissions to perform this action.', 'wp-search-console-data' ) );
		}

		$this->cache_handler->clear_all();

		// Set a transient to show a success notice
		set_transient( 'wpscd_cache_cleared_notice', 'true', 5 );

		// Redirect back to the settings page
		wp_redirect( admin_url( 'options-general.php?page=' . $this->settings_page_slug ) );
		exit;
	}

	/**
	 * Show a notice if the cache was just cleared.
	 */
	public function show_cache_cleared_notice() {
		if ( get_transient( 'wpscd_cache_cleared_notice' ) ) {
			?>
			<div class="notice notice-success is-dismissible">
				<p><?php esc_html_e( 'WP Search Console Data cache cleared.', 'wp-search-console-data' ); ?></p>
			</div>
			<?php
			delete_transient( 'wpscd_cache_cleared_notice' );
		}
	}

	/**
	 * Handle the clear log action request.
	 */
	public function handle_clear_log() {
		// Verify nonce
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ), 'wpscd_clear_log_nonce' ) ) {
			wp_die( __( 'Invalid nonce specified', 'wp-search-console-data' ) );
		}

		// Check user capabilities
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'You do not have sufficient permissions to perform this action.', 'wp-search-console-data' ) );
		}

		if ( $this->logger->clear_log() ) {
			// Set a transient to show a success notice
			set_transient( 'wpscd_log_cleared_notice', 'true', 5 );
		} else {
			// Set a transient to show an error notice
			set_transient( 'wpscd_log_clear_error_notice', 'true', 5 );
		}

		// Redirect back to the settings page
		wp_redirect( admin_url( 'options-general.php?page=' . $this->settings_page_slug ) );
		exit;
	}

	/**
	 * Show a notice if the log was just cleared.
	 */
	public function show_log_cleared_notice() {
		if ( get_transient( 'wpscd_log_cleared_notice' ) ) {
			?>
			<div class="notice notice-success is-dismissible">
				<p><?php esc_html_e( 'WP Search Console Data debug log cleared.', 'wp-search-console-data' ); ?></p>
			</div>
			<?php
			delete_transient( 'wpscd_log_cleared_notice' );
		}
		if ( get_transient( 'wpscd_log_clear_error_notice' ) ) {
			?>
			<div class="notice notice-error is-dismissible">
				<p><?php esc_html_e( 'Could not clear WP Search Console Data debug log. Check file permissions.', 'wp-search-console-data' ); ?></p>
			</div>
			<?php
			delete_transient( 'wpscd_log_clear_error_notice' );
		}
	}

	/**
	 * Create the structure of the admin page.
	 */
	public function create_admin_page() {
		?>
		<div class="wrap wpscd-admin-wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<?php settings_errors(); // Display errors/messages (like invalid JSON) ?>
			<form method="post" action="options.php">
				<?php
				// This prints out all hidden setting fields
				settings_fields( $this->option_group );
				// This renders all sections and fields registered to the page slug
				do_settings_sections( $this->settings_page_slug );
				
				submit_button();
				?>
			</form>
		</div>
		<?php
	}
} 