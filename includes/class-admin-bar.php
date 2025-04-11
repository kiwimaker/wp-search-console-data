<?php
/**
 * Handles adding Search Console data to the WordPress Admin Bar.
 *
 * @package    WP_Search_Console_Data
 * @subpackage WP_Search_Console_Data/includes
 * @author     Kiwimaker <info@nexir.es>
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPSCD_Admin_Bar {

	/**
	 * API handler instance.
	 * @var WPSCD_API
	 */
	private $api_handler;

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
	 * Constructor.
	 *
	 * @param WPSCD_API $api_handler Instance of the API handler class.
	 */
	public function __construct( WPSCD_API $api_handler ) {
		$this->api_handler = $api_handler;
		$this->settings = get_option( $this->option_name, array() );

		add_action( 'admin_bar_menu', array( $this, 'add_gsc_admin_bar_node' ), 999 ); // High priority to appear later
	}

	/**
	 * Add the Search Console data node to the admin bar.
	 *
	 * @param WP_Admin_Bar $wp_admin_bar The admin bar instance.
	 */
	public function add_gsc_admin_bar_node( $wp_admin_bar ) {
		// Check if configured and viewing a singular post/page on frontend
		$selected_property = $this->settings['selected_property'] ?? null;
		// Authentication check is handled implicitly by api_handler methods
		if ( ! $selected_property || is_admin() || ! is_singular() || ! is_user_logged_in() ) {
			return;
		}

		// Check user capability (e.g., edit posts)
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		// Get current post ID and URL
		$post_id = get_queried_object_id();
		$post_url = get_permalink( $post_id );
		if ( ! $post_url ) {
			return;
		}

		// Define date range based on settings
		$days = intval( $this->settings['date_range'] ?? 30 );
		$end_date = date( 'Y-m-d', strtotime( '-3 days' ) );
		$start_date = date( 'Y-m-d', strtotime( "-{$days} days", strtotime( $end_date ) ) );

		// Fetch data (will use cache)
		$performance_data = $this->api_handler->get_performance_data(
			$selected_property,
			$start_date,
			$end_date,
			$post_url
		);

		// Prepare title - show loading/error or basic stats
		$node_title = '<span class="ab-icon dashicons-chart-bar" style="top: 2px; position: relative; margin-right: 2px;"></span>GSC: '; // Icon + Prefix

		if ( is_null( $performance_data ) ) {
			$node_title .= __( 'Auth Error', 'wp-search-console-data' );
		} elseif ( isset( $performance_data['error'] ) ) {
			$node_title .= __( 'API Error', 'wp-search-console-data' );
		} elseif ( empty( $performance_data ) ) {
			$node_title .= __( 'No Data', 'wp-search-console-data' );
		} else {
			// Display Clicks / Impressions
			$row = $performance_data[0];
			$clicks = $row->getClicks() ?? 0;
			$impressions = $row->getImpressions() ?? 0;
			$node_title .= sprintf(
				__( '%s Clicks / %s Impr.', 'wp-search-console-data' ),
				esc_html( number_format_i18n( $clicks, 0 ) ),
				esc_html( number_format_i18n( $impressions, 0 ) )
			);
		}

		// Add the main node
		$wp_admin_bar->add_node( array(
			'id'    => 'wpscd-admin-bar',
			'title' => $node_title,
			'href'  => '#', // Or link to settings page?
			'meta'  => array( 'class' => 'wpscd-admin-bar-node' )
		) );

		// Add sub-nodes if data exists
		if ( ! is_null( $performance_data ) && ! isset( $performance_data['error'] ) && ! empty( $performance_data ) ) {
			$row = $performance_data[0];
			$ctr = ( $row->getCtr() ?? 0 ) * 100;
			$position = $row->getPosition() ?? 0;

			$wp_admin_bar->add_node( array(
				'parent' => 'wpscd-admin-bar',
				'id'     => 'wpscd-admin-bar-clicks',
				'title'  => sprintf( __( 'Clicks: %s', 'wp-search-console-data' ), number_format_i18n( $row->getClicks(), 0 ) ),
				'href'   => false,
			) );
			$wp_admin_bar->add_node( array(
				'parent' => 'wpscd-admin-bar',
				'id'     => 'wpscd-admin-bar-impressions',
				'title'  => sprintf( __( 'Impressions: %s', 'wp-search-console-data' ), number_format_i18n( $row->getImpressions(), 0 ) ),
				'href'   => false,
			) );
			$wp_admin_bar->add_node( array(
				'parent' => 'wpscd-admin-bar',
				'id'     => 'wpscd-admin-bar-ctr',
				'title'  => sprintf( __( 'CTR: %s%%', 'wp-search-console-data' ), number_format_i18n( $ctr, 2 ) ),
				'href'   => false,
			) );
			$wp_admin_bar->add_node( array(
				'parent' => 'wpscd-admin-bar',
				'id'     => 'wpscd-admin-bar-position',
				'title'  => sprintf( __( 'Position: %s', 'wp-search-console-data' ), number_format_i18n( $position, 1 ) ),
				'href'   => false,
			) );
			$wp_admin_bar->add_node( array(
				'parent' => 'wpscd-admin-bar',
				'id'     => 'wpscd-admin-bar-daterange',
				'title'  => sprintf( __( 'Range: %s - %s', 'wp-search-console-data' ), $start_date, $end_date ),
				'href'   => false,
				'meta'   => array( 'style' => 'font-size: 0.9em; opacity: 0.8;' )
			) );
		}
	}
} 