<?php
/**
 * Handles interactions with the Google Search Console API.
 *
 * @package    WP_Search_Console_Data
 * @subpackage WP_Search_Console_Data/includes
 * @author     Kiwimaker <info@nexir.es>
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPSCD_API {

	/**
	 * Auth handler instance.
	 * @var WPSCD_Auth
	 */
	private $auth_handler;

	/**
	 * Google Search Console service instance.
	 * @var Google_Service_Webmasters
	 */
	private $gsc_service;

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
	 * Constructor.
	 *
	 * @param WPSCD_Auth  $auth_handler Instance of the Auth handler class.
	 * @param WPSCD_Cache $cache_handler Instance of the Cache handler class.
	 * @param WPSCD_Logger $logger Instance of the Logger class.
	 */
	public function __construct( WPSCD_Auth $auth_handler, WPSCD_Cache $cache_handler, WPSCD_Logger $logger ) {
		$this->auth_handler = $auth_handler;
		$this->cache_handler = $cache_handler;
		$this->logger = $logger;
	}

	/**
	 * Get the Google Search Console service object.
	 *
	 * Initializes the service if it hasn't been already.
	 *
	 * @return Google_Service_Webmasters|null Returns the service instance or null on error/unauthenticated.
	 */
	private function get_gsc_service() {
		if ( $this->gsc_service ) {
			return $this->gsc_service;
		}

		$client = $this->auth_handler->get_client();
		if ( ! $client || ! $this->auth_handler->is_authenticated() ) {
			// Not authenticated or client initialization failed.
			return null;
		}

		try {
			$this->gsc_service = new Google_Service_Webmasters( $client );
			return $this->gsc_service;
		} catch ( Exception $e ) {
			error_log( 'Error creating Google_Service_Webmasters: ' . $e->getMessage() );
			return null;
		}
	}

	/**
	 * Get the list of sites from Google Search Console and filter them.
	 *
	 * Filters the sites to include only those matching the current WP site domain.
	 *
	 * @return array An array of filtered site URLs (properties) or an empty array on failure/no match.
	 */
	public function get_filtered_sites() {
		$service = $this->get_gsc_service();
		if ( ! $service ) {
			return array(); // Not authenticated or service init failed.
		}

		$this->logger->log("Attempting to fetch site list from API (get_filtered_sites).");
		try {
			$sites_list = $service->sites->listSites();
			$all_sites = $sites_list->getSiteEntry(); // Array of Google_Service_Webmasters_WmxSite
			$filtered_sites = array();

			if ( empty( $all_sites ) ) {
				return array();
			}

			// Get the base domain of the current WordPress site
			$wp_site_url = site_url();
			$wp_host = wp_parse_url( $wp_site_url, PHP_URL_HOST );
			// Remove www. if present to get the base domain
			$wp_base_domain = preg_replace( '/^www\./i', '', $wp_host );

			foreach ( $all_sites as $site ) {
				$gsc_site_url = $site->getSiteUrl();
				$gsc_host = wp_parse_url( $gsc_site_url, PHP_URL_HOST );

				// Handle Domain Properties (e.g., sc-domain:example.com)
				if ( strpos( $gsc_site_url, 'sc-domain:' ) === 0 ) {
					$gsc_domain_part = str_replace( 'sc-domain:', '', $gsc_site_url );
					if ( strtolower( $gsc_domain_part ) === strtolower( $wp_base_domain ) ) {
						$filtered_sites[] = $gsc_site_url;
						continue; // Match found, proceed to next site
					}
				}

				// Handle URL-prefix properties (http/https)
				if ( $gsc_host ) {
					// Check if the GSC host contains the WP base domain
					// This covers domain.com, www.domain.com, sub.domain.com matching domain.com
					if ( stripos( $gsc_host, $wp_base_domain ) !== false ) {
						// More strict check: Ensure it ends with the base domain or is exactly the base domain
						// to avoid matching domain.com.other.com if base is domain.com
						$gsc_base_domain = preg_replace( '/^www\./i', '', $gsc_host );
						if ( strtolower( $gsc_base_domain ) === strtolower( $wp_base_domain ) ) {
							$filtered_sites[] = $gsc_site_url;
						}
					}
				}
			}

			$this->logger->log("Successfully fetched and filtered site list. Found: " . count($filtered_sites) . " matching sites.");
			return $filtered_sites;

		} catch ( Google_Service_Exception $e ) {
			// Handle API specific errors (e.g., permission denied, quota exceeded)
			$error_msg = 'Google API Error fetching sites: (' . $e->getCode() . ') ' . $e->getMessage();
			error_log( $error_msg );
			$this->logger->log( $error_msg );
			// Check if it's an authentication error
			if ( $e->getCode() == 401 || $e->getCode() == 403 ) {
				// Potentially trigger re-authentication or notify user
				// Service account has no disconnect method here, user needs to fix key/permissions.
				$this->logger->log("Authentication error (401/403) fetching sites. Check Service Account key and permissions.");
			}
			return array( 'error' => $e->getMessage() ); // Indicate error
		} catch ( Exception $e ) {
			// Handle other general errors
			$error_msg = 'General Error fetching sites: ' . $e->getMessage();
			error_log( $error_msg );
			$this->logger->log( $error_msg );
			return array( 'error' => $e->getMessage() ); // Indicate error
		}
	}

	/**
	 * Get performance data from the Search Console API for a specific URL or site.
	 *
	 * @param string $property   The Search Console property URL (e.g., https://example.com/ or sc-domain:example.com).
	 * @param string $start_date Start date in YYYY-MM-DD format.
	 * @param string $end_date   End date in YYYY-MM-DD format.
	 * @param string|null $page_url Optional. The specific page URL to filter by. If null, gets data for the whole property.
	 *
	 * @return object|array|null An object containing the performance data (rows), an array with an error message, or null if not authenticated.
	 */
	public function get_performance_data( $property, $start_date, $end_date, $page_url = null ) {
		$service = $this->get_gsc_service();
		if ( ! $service ) {
			return null; // Not authenticated or service init failed.
		}

		// --- Cache Check --- //
		$cache_params = array(
			'property'   => $property,
			'start_date' => $start_date,
			'end_date'   => $end_date,
			'page_url'   => $page_url,
			'action'     => 'performance_data'
		);
		$cache_key = $this->cache_handler->generate_key( $cache_params );
		$this->logger->log("Checking cache for key: {$cache_key} (Params: " . wp_json_encode($cache_params) . ")");
		$cached_data = $this->cache_handler->get( $cache_key );

		if ( false !== $cached_data ) {
			$this->logger->log("Cache HIT for key: {$cache_key}");
			return $cached_data;
		}
		$this->logger->log("Cache MISS for key: {$cache_key}");
		// --- End Cache Check --- //

		try {
			$this->logger->log("Making API call to searchanalytics.query for property '{$property}' page '{$page_url}' ({$start_date} to {$end_date}).");
			$request = new Google_Service_Webmasters_SearchAnalyticsQueryRequest();
			$request->setStartDate( $start_date );
			$request->setEndDate( $end_date );
			$request->setDimensions( array( 'page' ) ); // We always need page dimension, even if filtering by one.
			$request->setRowLimit( 10 ); // Limit rows for now, adjust if needed.
			//$request->setDataState( 'all' ); // Optional: include finalized and fresh data

			// Add dimension filter if a specific page URL is provided
			if ( $page_url ) {
				$dimension_filter_group = new Google_Service_Webmasters_ApiDimensionFilterGroup();
				$dimension_filter = new Google_Service_Webmasters_ApiDimensionFilter();
				$dimension_filter->setDimension( 'page' );
				$dimension_filter->setOperator( 'equals' );
				$dimension_filter->setExpression( $page_url );
				$dimension_filter_group->setFilters( array( $dimension_filter ) );
				$request->setDimensionFilterGroups( array( $dimension_filter_group ) );
			}

			$response = $service->searchanalytics->query( $property, $request );
			$rows = $response->getRows();

			// --- Cache Set --- //
			$this->cache_handler->set( $cache_key, $rows );
			$this->logger->log("API call successful. Storing result in cache key: {$cache_key}");
			// --- End Cache Set --- //

			return $rows; // Return the array of Google_Service_Webmasters_ApiDataRow

		} catch ( Google_Service_Exception $e ) {
			$error_msg = 'Google API Error fetching performance data: (' . $e->getCode() . ') ' . $e->getMessage();
			error_log( $error_msg );
			$this->logger->log( $error_msg );
			if ( $e->getCode() == 401 || $e->getCode() == 403 ) {
				// Service account has no disconnect method here, user needs to fix key/permissions.
				$this->logger->log("Authentication error (401/403) fetching performance data. Check Service Account key and permissions.");
			}
			// Cache the error briefly to avoid hammering API on persistent errors
			$error_result = array( 'error' => $e->getMessage() );
			$this->cache_handler->set( $cache_key, $error_result, MINUTE_IN_SECONDS * 5 );
			return $error_result;
		} catch ( Exception $e ) {
			$error_msg = 'General Error fetching performance data: ' . $e->getMessage();
			error_log( $error_msg );
			$this->logger->log( $error_msg );
			$error_result = array( 'error' => $e->getMessage() );
			$this->cache_handler->set( $cache_key, $error_result, MINUTE_IN_SECONDS * 5 );
			return $error_result;
		}
	}

} 