<?php
/**
 * Simple file logger for the plugin.
 *
 * @package    WP_Search_Console_Data
 * @subpackage WP_Search_Console_Data/includes
 * @author     Kiwimaker <info@nexir.es>
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPSCD_Logger {

	/**
	 * Log file path.
	 * @var string|false
	 */
	private $log_file = false;

	/**
	 * Is logging enabled?
	 * @var bool
	 */
	private $enabled = false;

	/**
	 * Option name for settings.
	 */
	private $option_name = 'wpscd_settings';

	/**
	 * Constructor.
	 */
	public function __construct() {
		$settings = get_option( $this->option_name, array() );
		$this->enabled = isset( $settings['enable_logging'] ) && '1' === $settings['enable_logging'];

		if ( $this->enabled ) {
			$this->setup_log_file();
		}
	}

	/**
	 * Sets up the log file path and directory.
	 */
	private function setup_log_file() {
		$upload_dir = wp_upload_dir();
		$log_dir = $upload_dir['basedir'] . '/wpscd-logs';

		// Create directory if it doesn't exist
		if ( ! file_exists( $log_dir ) ) {
			wp_mkdir_p( $log_dir );
		}

		// Add index.php and .htaccess file for security
		if ( file_exists( $log_dir ) ) {
			if ( ! file_exists( $log_dir . '/index.php' ) ) {
				@file_put_contents( $log_dir . '/index.php', '<?php // Silence is golden' );
			}
			if ( ! file_exists( $log_dir . '/.htaccess' ) ) {
				@file_put_contents( $log_dir . '/.htaccess', 'deny from all' );
			}
		}

		$this->log_file = $log_dir . '/wpscd-debug.log';
	}

	/**
	 * Log a message to the file.
	 *
	 * @param string $message The message to log.
	 */
	public function log( $message ) {
		if ( ! $this->enabled || ! $this->log_file ) {
			return;
		}

		$timestamp = current_time( 'mysql' );
		$log_entry = sprintf( "[%s] %s\n", $timestamp, $message );

		// Use LOCK_EX for atomic writes
		@file_put_contents( $this->log_file, $log_entry, FILE_APPEND | LOCK_EX );
	}

	/**
	 * Clear the log file.
	 *
	 * @return bool True on success, false on failure.
	 */
	public function clear_log() {
		if ( $this->log_file && file_exists( $this->log_file ) ) {
			return @unlink( $this->log_file );
		}
		return true; // No file to clear
	}

	/**
	 * Get the path to the log file.
	 *
	 * @return string|false Log file path or false if not set up.
	 */
	public function get_log_file_path() {
		return $this->log_file;
	}
} 