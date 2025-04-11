<?php
/**
 * Handles caching of API data using WordPress Transients API.
 *
 * @package    WP_Search_Console_Data
 * @subpackage WP_Search_Console_Data/includes
 * @author     Kiwimaker <info@nexir.es>
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPSCD_Cache {

	/**
	 * Default cache expiration time in seconds.
	 * 72 hours.
	 */
	const DEFAULT_EXPIRATION = 72 * HOUR_IN_SECONDS;

	/**
	 * Cache key prefix.
	 */
	private $prefix = 'wpscd_cache_';

	/**
	 * Get cached data.
	 *
	 * @param string $key Unique identifier for the cache.
	 * @return mixed Cached data or false if not found/expired.
	 */
	public function get( $key ) {
		return get_transient( $this->prefix . $key );
	}

	/**
	 * Set data in cache.
	 *
	 * @param string $key        Unique identifier for the cache.
	 * @param mixed  $value      Data to cache.
	 * @param int    $expiration Optional. Time until expiration in seconds. Default uses DEFAULT_EXPIRATION.
	 * @return bool True if the value was set, false otherwise.
	 */
	public function set( $key, $value, $expiration = null ) {
		if ( is_null( $expiration ) ) {
			$expiration = self::DEFAULT_EXPIRATION;
		}
		return set_transient( $this->prefix . $key, $value, $expiration );
	}

	/**
	 * Delete cached data.
	 *
	 * @param string $key Unique identifier for the cache.
	 * @return bool True if the transient was deleted, false otherwise.
	 */
	public function delete( $key ) {
		return delete_transient( $this->prefix . $key );
	}

	/**
	 * Generate a unique cache key from query parameters.
	 *
	 * @param array $params Parameters used for the API query.
	 * @return string A unique cache key hash.
	 */
	public function generate_key( $params ) {
		// Sort params to ensure consistent key regardless of order.
		ksort( $params );
		// Use md5 for a reasonably unique and short key.
		return md5( serialize( $params ) );
	}

	/**
	 * Get the count of active cache entries for this plugin.
	 *
	 * @return int Number of cached items.
	 */
	public function get_cache_count() {
		global $wpdb;
		// We only count the main transient, not the timeout entry
		$prefix = $wpdb->esc_like( '\_transient_' . $this->prefix );
		$sql = "SELECT COUNT(*) FROM {$wpdb->options} WHERE `option_name` LIKE '%s'";
		$count = $wpdb->get_var( $wpdb->prepare( $sql, $prefix . '%' ) );
		return intval( $count );
	}

	/**
	 * Clear all plugin cache (transients starting with the prefix).
	 */
	public function clear_all() {
		global $wpdb;
		$prefix = $wpdb->esc_like( '\_transient_' . $this->prefix );
		$sql = "SELECT `option_name` FROM {$wpdb->options} WHERE `option_name` LIKE '%s'";
		$transients = $wpdb->get_col( $wpdb->prepare( $sql, $prefix . '%' ) );

		$timeout_prefix = $wpdb->esc_like( '\_transient\_timeout_' . $this->prefix );
		$sql_timeout = "SELECT `option_name` FROM {$wpdb->options} WHERE `option_name` LIKE '%s'";
		$timeout_transients = $wpdb->get_col( $wpdb->prepare( $sql_timeout, $timeout_prefix . '%' ) );

		$all_transients = array_merge( $transients, $timeout_transients );

		if ( ! empty( $all_transients ) ) {
			foreach ( $all_transients as $transient_name ) {
				// Extract the base key from the option name
				if ( strpos( $transient_name, '_transient_timeout_' ) === 0 ) {
					$key = str_replace( '_transient_timeout_' . $this->prefix, '', $transient_name );
				} else {
					$key = str_replace( '_transient_' . $this->prefix, '', $transient_name );
				}
				$this->delete( $key );
			}
		}
	}
} 