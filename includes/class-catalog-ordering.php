<?php
/**
 * WooCommerce catalog ordering.
 *
 * @package    NHG
 * @subpackage NHG\Lookup
 * @author     Shakeeb Ahmed <me@shakeebahmed.com>
 */

namespace NHG\Lookup;

defined( 'ABSPATH' ) || exit;

/**
 * Catalog Sorting
 */
class Catalog_Ordering {

	public $orderby;

	/**
	 * Hooks.
	 */
	public function hooks() {
		add_filter( 'woocommerce_catalog_orderby', [ $this, 'add_in_dropdown' ] );
		add_filter( 'woocommerce_default_catalog_orderby_options', [ $this, 'add_in_dropdown' ] );
		add_filter( 'woocommerce_get_catalog_ordering_args', [ $this, 'set_catalog_ordering_args' ], 15, 2 );
	}

	/**
	 * Add sorting as option in dropdown.
	 *
	 * @param  array $sortby Sorting options.
	 * @return array
	 */
	public function add_in_dropdown( $sortby ) {
		$sortby['trending'] = esc_html__( 'Recommended', 'proto' );

		return $sortby;
	}

	/**
	 * Set catalog ordering args.
	 *
	 * @param  array $args     Ordering arguments.
	 * @param  string $orderby Order by param.
	 * @return array
	 */
	public function set_catalog_ordering_args( $args, $orderby = '' ) {
		if ( ! $orderby ) {
			$orderby = filter_has_var( INPUT_GET, 'orderby' )
				? wc_clean( filter_input( INPUT_GET, 'orderby' ) )
				: apply_filters( 'woocommerce_default_catalog_orderby', get_option( 'woocommerce_default_catalog_orderby' ) );
		}

		if ( ! $this->is_valid_order( $orderby ) ) {
			return $args;
		}

		if ( 'popularity' === $orderby ) {
			remove_filter( 'posts_clauses', [ WC()->query, 'order_by_popularity_post_clauses' ] );
		}

		$this->orderby = $orderby;
		add_filter( 'posts_clauses', [ $this, 'order_by_post_clauses' ] );

		return $args;
	}

	/**
	 * Order by post clauses.
	 *
	 * @param  array $args Query args.
	 * @return array
	 */
	public function order_by_post_clauses( $args ) {
		global $wpdb;

		$hash = [
			'trending'   => ' nhg_lookup_table.trending DESC',
			'popularity' => ' nhg_lookup_table.sales_30 DESC',
			'sales_7'    => ' nhg_lookup_table.sales_7 DESC',
			'sales_30'   => ' nhg_lookup_table.sales_30 DESC',
			'total_7'    => ' nhg_lookup_table.total_7 DESC',
			'total_30'   => ' nhg_lookup_table.total_30 DESC',
			'profit_7'   => ' nhg_lookup_table.profit_7 DESC',
			'profit_30'  => ' nhg_lookup_table.profit_30 DESC',
		];

		if ( isset( $hash[ $this->orderby ] )) {
			$args['join']    = $this->append_product_sorting_table_join( $args['join'] );
			$args['orderby'] = $hash[ $this->orderby ] . ', nhg_lookup_table.product_id DESC'; // Use an ID to prevent posts with the same meta from jumping around between page numbers
		}

		return $args;
	}

	/**
	 * Are we handling this orderby.
	 *
	 * @param  string $orderby Order by param.
	 * @return boolean
	 */
	private function is_valid_order( $orderby ) {
		return in_array(
			$orderby,
			[
				'trending',
				'popularity',
				'sales_7',
				'sales_30',
				'total_7',
				'total_30',
				'profit_7',
				'profit_30',
			],
			true
		);
	}

	/**
	 * Join wc_product_meta_lookup to posts if not already joined.
	 *
	 * @param string $sql SQL join.
	 * @return string
	 */
	private function append_product_sorting_table_join( $sql ) {
		global $wpdb;

		if ( ! strstr( $sql, 'nhg_lookup_table' ) ) {
			$sql .= " LEFT JOIN {$wpdb->prefix}nhg_lookup_table nhg_lookup_table ON $wpdb->posts.ID = nhg_lookup_table.product_id ";
		}

		return $sql;
	}
}
