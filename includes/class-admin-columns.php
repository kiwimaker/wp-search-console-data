<?php
/**
 * Handles adding Search Console data columns to Posts and Pages list tables.
 *
 * @package    WP_Search_Console_Data
 * @subpackage WP_Search_Console_Data/includes
 * @author     Kiwimaker <info@nexir.es>
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPSCD_Admin_Columns {

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
	 * Whether to show CTR and Position columns.
	 * @var bool
	 */
	private $show_extra_columns = false;

	/**
	 * Whether to combine Clicks and Impressions columns.
	 * @var bool
	 */
	private $combine_clicks_impressions = false;

	/**
	 * Constructor.
	 *
	 * @param WPSCD_API $api_handler Instance of the API handler class.
	 */
	public function __construct( WPSCD_API $api_handler ) {
		$this->api_handler = $api_handler;
		$this->settings = get_option( $this->option_name, array() );
		$this->show_extra_columns = isset( $this->settings['show_extra_columns'] ) && '1' === $this->settings['show_extra_columns'];
		$this->combine_clicks_impressions = isset( $this->settings['combine_clicks_impressions'] ) && '1' === $this->settings['combine_clicks_impressions'];

		// Add columns to Posts and Pages
		add_filter( 'manage_post_posts_columns', array( $this, 'add_gsc_columns' ) );
		add_filter( 'manage_page_posts_columns', array( $this, 'add_gsc_columns' ) );

		// Populate columns for Posts and Pages
		add_action( 'manage_post_posts_custom_column', array( $this, 'render_gsc_columns' ), 10, 2 );
		add_action( 'manage_page_posts_custom_column', array( $this, 'render_gsc_columns' ), 10, 2 );

		// Make columns sortable (registers them, but actual sorting requires meta data)
		add_filter( 'manage_edit-post_sortable_columns', array( $this, 'make_gsc_columns_sortable' ) );
		add_filter( 'manage_edit-page_sortable_columns', array( $this, 'make_gsc_columns_sortable' ) );
		// add_action( 'pre_get_posts', array( $this, 'handle_gsc_column_orderby' ) ); // Keep commented - needs meta data
	}

	/**
	 * Add GSC data columns to the list table.
	 *
	 * @param array $columns Existing columns.
	 * @return array Modified columns.
	 */
	public function add_gsc_columns( $columns ) {
		// Add columns before the date column
		$new_columns = array();
		foreach ( $columns as $key => $title ) {
			if ( 'date' === $key ) {
				if ( $this->combine_clicks_impressions ) {
					$new_columns['gsc_clicks_impressions'] = __( 'Clicks / Impr.', 'wp-search-console-data' );
				} else {
					$new_columns['gsc_clicks'] = __( 'Clicks', 'wp-search-console-data' );
					$new_columns['gsc_impressions'] = __( 'Impressions', 'wp-search-console-data' );
				}
				if ( $this->show_extra_columns ) {
					$new_columns['gsc_ctr'] = __( 'CTR', 'wp-search-console-data' );
					$new_columns['gsc_position'] = __( 'Position', 'wp-search-console-data' );
				}
			}
			$new_columns[ $key ] = $title;
		}
		// If date column wasn't found, add them at the end (less ideal)
		if ( ! isset( $new_columns['gsc_clicks'] ) && ! isset( $new_columns['gsc_clicks_impressions'] ) ) {
			if ( $this->combine_clicks_impressions ) {
				$new_columns['gsc_clicks_impressions'] = __( 'Clicks / Impr.', 'wp-search-console-data' );
			} else {
				$new_columns['gsc_clicks'] = __( 'Clicks', 'wp-search-console-data' );
				$new_columns['gsc_impressions'] = __( 'Impressions', 'wp-search-console-data' );
			}
			if ( $this->show_extra_columns ) {
				$new_columns['gsc_ctr'] = __( 'CTR', 'wp-search-console-data' );
				$new_columns['gsc_position'] = __( 'Position', 'wp-search-console-data' );
			}
		}
		return $new_columns;
	}

	/**
	 * Render the content for the GSC data columns.
	 *
	 * @param string $column_name The name of the column being rendered.
	 * @param int    $post_id     The ID of the current post.
	 */
	public function render_gsc_columns( $column_name, $post_id ) {
		// Define base columns
		$gsc_columns = array( 'gsc_clicks', 'gsc_impressions', 'gsc_clicks_impressions' );
		// Add extra columns if enabled
		if ( $this->show_extra_columns ) {
			$gsc_columns = array_merge( $gsc_columns, array( 'gsc_ctr', 'gsc_position' ) );
		}

		// Skip if not one of our active columns
		if ( ! in_array( $column_name, $gsc_columns ) ) {
			return;
		}
		// Skip individual clicks/impressions if combined is active, and vice versa
		if ( $this->combine_clicks_impressions && in_array( $column_name, array( 'gsc_clicks', 'gsc_impressions' ) ) ) {
			return;
		}
		if ( ! $this->combine_clicks_impressions && $column_name === 'gsc_clicks_impressions' ) {
			return;
		}

		// Check if plugin is configured
		$selected_property = $this->settings['selected_property'] ?? null;
		// Authentication is checked implicitly within get_performance_data
		if ( ! $selected_property ) {
			echo '-';
			return;
		}

		// Get the permalink for the post
		$post_url = get_permalink( $post_id );
		if ( ! $post_url ) {
			echo '-';
			return;
		}

		// Define date range based on settings
		$days = intval( $this->settings['date_range'] ?? 30 );
		$end_date = date( 'Y-m-d', strtotime( '-3 days' ) ); // GSC data can have a delay
		$start_date = date( 'Y-m-d', strtotime( "-{$days} days", strtotime( $end_date ) ) );

		// Fetch data using the API handler (will use cache)
		$performance_data = $this->api_handler->get_performance_data(
			$selected_property,
			$start_date,
			$end_date,
			$post_url
		);

		if ( is_null( $performance_data ) ) {
			// Not authenticated or client init failed during fetch
			echo '<span title="' . esc_attr__( 'Not authenticated or client error', 'wp-search-console-data' ) . '">-</span>';
			return;
		}

		if ( isset( $performance_data['error'] ) ) {
			echo '<span title="' . esc_attr( sprintf( __( 'API Error: %s', 'wp-search-console-data' ), $performance_data['error'] ) ) . '" style="color:red;">Error</span>';
			return;
		}

		if ( empty( $performance_data ) ) {
			// No data found for this specific URL in the date range
			echo '0';
			return;
		}

		// Assuming the API returns at most one row when filtered by a specific page URL
		$row = $performance_data[0];

		switch ( $column_name ) {
			case 'gsc_clicks':
				echo esc_html( number_format_i18n( $row->getClicks(), 0 ) );
				break;
			case 'gsc_impressions':
				echo esc_html( number_format_i18n( $row->getImpressions(), 0 ) );
				break;
			case 'gsc_clicks_impressions':
				$clicks = number_format_i18n( $row->getClicks(), 0 );
				$impressions = number_format_i18n( $row->getImpressions(), 0 );
				echo esc_html( "{$clicks} / {$impressions}" );
				break;
			case 'gsc_ctr':
				// Format CTR as percentage
				$ctr = $row->getCtr() * 100;
				echo esc_html( number_format_i18n( $ctr, 2 ) . '%' );
				break;
			case 'gsc_position':
				// Format position
				echo esc_html( number_format_i18n( $row->getPosition(), 1 ) );
				break;
		}
	}

	/**
	 * Register GSC columns as sortable.
	 *
	 * Note: Actual sorting functionality requires storing data in post meta and modifying the query
	 * in handle_gsc_column_orderby, which is currently not implemented.
	 *
	 * @param array $sortable_columns Existing sortable columns.
	 * @return array Modified sortable columns.
	 */
	public function make_gsc_columns_sortable( $sortable_columns ) {
		if ( $this->combine_clicks_impressions ) {
			$sortable_columns['gsc_clicks_impressions'] = 'gsc_clicks_impressions';
		} else {
			$sortable_columns['gsc_clicks'] = 'gsc_clicks';
			$sortable_columns['gsc_impressions'] = 'gsc_impressions';
		}
		if ( $this->show_extra_columns ) {
			$sortable_columns['gsc_ctr'] = 'gsc_ctr';
			$sortable_columns['gsc_position'] = 'gsc_position';
		}
		return $sortable_columns;
	}

	/**
	 * Handles ordering by GSC columns.
	 * IMPORTANT: This is a placeholder and WILL NOT WORK correctly without storing
	 * GSC data in post meta fields and modifying the query accordingly.
	 *
	 * @param WP_Query $query The main query object.
	 */
	public function handle_gsc_column_orderby( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		$orderby = $query->get( 'orderby' );
		$gsc_columns = array( 'gsc_clicks', 'gsc_impressions', 'gsc_clicks_impressions', 'gsc_ctr', 'gsc_position' ); // Added combined

		if ( in_array( $orderby, $gsc_columns ) ) {
			// TODO: Implement sorting based on post meta field.
			// Example (if data was stored in meta keys like '_gsc_clicks'):
			// $query->set( 'meta_key', '_ ' . $orderby );
			// $query->set( 'orderby', 'meta_value_num' ); // Or 'meta_value' for non-numeric
		}
	}
} 