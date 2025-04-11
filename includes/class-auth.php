<?php
/**
 * Handles Google Service Account authentication.
 *
 * @package    WP_Search_Console_Data
 * @subpackage WP_Search_Console_Data/includes
 * @author     Kiwimaker <info@nexir.es>
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPSCD_Auth {

	/**
	 * Google API Client instance.
	 * @var Google_Client
	 */
	private $client;

	/**
	 * Plugin settings.
	 * @var array
	 */
	private $settings;

	/**
	 * Option name for settings.
	 */
	private $option_name = 'wpscd_settings';

	/**
	 * Holds the decoded service account key.
	 * @var array|null
	 */
	private $service_account_credentials = null;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->settings = get_option( $this->option_name, array() );
		$this->load_credentials();
		$this->init_client();
	}

	/**
	 * Load and decode service account credentials from wp-config.php or DB.
	 */
	private function load_credentials() {
		$key_content = null;
		// Prioritize constant defined in wp-config.php for security
		if ( defined( 'WPSCD_SERVICE_ACCOUNT_KEY_PATH' ) && file_exists( WPSCD_SERVICE_ACCOUNT_KEY_PATH ) ) {
			$key_content = file_get_contents( WPSCD_SERVICE_ACCOUNT_KEY_PATH );
		} elseif ( ! empty( $this->settings['service_account_key'] ) ) {
			$key_content = $this->settings['service_account_key'];
		}

		if ( $key_content ) {
			$decoded = json_decode( $key_content, true );
			if ( json_last_error() === JSON_ERROR_NONE && isset( $decoded['client_email'] ) && isset( $decoded['private_key'] ) ) {
				$this->service_account_credentials = $decoded;
			} else {
				$this->service_account_credentials = null;
				error_log('WP Search Console Data: Invalid Service Account JSON provided.');
			}
		} else {
			$this->service_account_credentials = null;
		}
	}

	/**
	 * Initialize the Google API Client using Service Account credentials.
	 *
	 * @return Google_Client|null Returns the client instance or null on error.
	 */
	private function init_client() {
		if ( ! $this->service_account_credentials ) {
			// Credentials not loaded or invalid.
			return null;
		}

		try {
			$this->client = new Google_Client();
			$this->client->setApplicationName( __( 'WP Search Console Data', 'wp-search-console-data' ) );
			$this->client->setAuthConfig( $this->service_account_credentials );
			$this->client->setScopes( Google_Service_Webmasters::WEBMASTERS_READONLY );
			// No need for Redirect URI, Access Type, Prompt, Refresh Tokens with service accounts.

			return $this->client;
		} catch ( Exception $e ) {
			// Log error or display a notice
			error_log( 'Error initializing Google Client with Service Account: ' . $e->getMessage() );
			$this->client = null; // Ensure client is null on failure
			return null;
		}
	}

	/**
	 * Get the Google API Client instance.
	 *
	 * @return Google_Client|null
	 */
	public function get_client() {
		// If client is null, try initializing again (in case settings were updated)
		if ( is_null( $this->client ) ) {
			$this->load_credentials(); // Reload credentials in case they changed
			$this->init_client();
		}
		return $this->client;
	}

	/**
	 * Check if the plugin is configured and authenticated via Service Account.
	 *
	 * @return bool True if authenticated, false otherwise.
	 */
	public function is_authenticated() {
		// Considered authenticated if the client could be initialized successfully.
		return ! is_null( $this->get_client() );
	}

	// REMOVED: get_redirect_uri()
	// REMOVED: get_auth_url()
	// REMOVED: handle_oauth_redirect()
	// REMOVED: disconnect()
	// REMOVED: handle_disconnect()
} 