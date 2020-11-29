<?php
/**
 * Sales updater.
 *
 * @package    NHG
 * @subpackage NHG\Lookup
 * @author     Shakeeb Ahmed <me@shakeebahmed.com>
 */

namespace NHG\Lookup;

defined( 'ABSPATH' ) || exit;

/**
 * Sales Updater.
 */
class Sales_Updater {

	/**
	 * Sales data.
	 *
	 * @var array
	 */
	private $sales_data;

	/**
	 * Product age.
	 *
	 * @var array
	 */
	private $product_age = [];

	/**
	 * Hooks.
	 */
	public function hooks() {
		add_action( 'init', [ $this, 'setup_cron' ] );
		add_filter( 'cron_schedules', [ $this, 'add_cron_schedules' ] );
		add_action( 'nhg_sales_data_update', [ $this, 'update_sales_data' ] );
		add_action( 'woocommerce_product_duplicate', [ $this, 'on_duplicate_product' ], 999 );
		add_action( 'woocommerce_process_product_meta', [ $this, 'set_product_default_value' ], 15 );
	}

	/**
	 * Update sales data.
	 *
	 * @param integer $product_id Product id.
	 */
	public function update_sales_data_into_db( $product_id ) {
		global $wpdb;

		$data = [
			'product_id' => absint( $product_id ),
			'trending'   => (int) get_post_meta( $product_id, '_trending', true ),
			'sales_7'    => (int) get_post_meta( $product_id, 'sales_7', true ),
			'sales_30'   => (int) get_post_meta( $product_id, 'sales_30', true ),
			'total_7'    => (int) get_post_meta( $product_id, 'total_7', true ),
			'total_30'   => (int) get_post_meta( $product_id, 'total_30', true ),
			'profit_7'   => (int) get_post_meta( $product_id, 'profit_7', true ),
			'profit_30'  => (int) get_post_meta( $product_id, 'profit_30', true ),
		];

		$wpdb->replace(
			"{$wpdb->prefix}nhg_lookup_table",
			$data,
			[ '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d' ]
		);
	}

	/**
	 * Setup cron job.
	 */
	public function setup_cron() {
		if ( ! wp_next_scheduled( 'nhg_sales_data_update' ) ) {
			wp_schedule_event( time(), 'every_minute', 'nhg_sales_data_update' );
		}
	}

	/**
	 * Add cron schedules.
	 *
	 * @param  array $schedules An array cron schedules.
	 * @return array
	 */
	public function add_cron_schedules( $schedules ) {
		$schedules['every_minute'] = [
			'interval' => MINUTE_IN_SECONDS,
			'display'  => __( 'Sixty Times Hourly', 'proto' ),
		];

		return $schedules;
	}

	/**
	 * On product duplicate set meta data with 0.
	 *
	 * @param WC_Product $duplicate The duplicate.
	 */
	public function on_duplicate_product( $duplicate ) {
		$metas      = [
			'_trending',
			'availability',
			'sales_7',
			'sales_30',
			'total_7',
			'total_30',
			'profit_7',
			'profit_30',
			'overstock',
			'stock_days',
			'last_customer_name',
		];
		$product_id = $duplicate->get_id();

		foreach ( $metas as $meta_key ) {
			update_post_meta( $product_id, $meta_key, 0 );
		}
	}

	/**
	 * Set product defaul values for metas.
	 *
	 * @param integer $product_id Product id.
	 */
	public function set_product_default_value( $product_id ) {
		if ( ! metadata_exists( 'post', $product_id, '_sales_data_updated' ) ) {
			update_post_meta( $product_id, '_sales_data_updated', time() - HOUR_IN_SECONDS * 6 );
		}

		if ( ! metadata_exists( 'post', $product_id, '_trending' ) ) {
			update_post_meta( $product_id, '_trending', '0' );
		}

		if ( ! metadata_exists( 'post', $product_id, 'sales_7' ) ) {
			update_post_meta( $product_id, 'sales_7', '0' );
		}

		if ( ! metadata_exists( 'post', $product_id, 'sales_30' ) ) {
			update_post_meta( $product_id, 'sales_30', '0' );
		}
	}

	/**
	 * Update sales data.
	 *
	 * @param integer $num Numbr of products.
	 */
	public function update_sales_data( $num = 500 ) {
		$ids = $this->get_products_to_update( $num );
		if ( ! $ids ) {
			return false;
		}

		if ( WP_DEBUG ) {
			ed( $ids );
		}

		foreach ( $ids as $id ) {
			$this->update_product_sales_data( $id );
			$this->update_product_data( $id );
			$this->update_sales_data_into_db( $id );
		}
	}

	/**
	 * Get product ids to update.
	 *
	 * @param  integer $num Numbr of products.
	 * @return array
	 */
	private function get_products_to_update( $num ) {
		// Get a limited number of products.
		// Get anything that we didn't update yet.
		// And anything older than 12h.
		$args = [
			'post_type'   => 'product',
			'post_status' => 'publish',
			'numberposts' => (int) $num,
			'fields'      => 'ids',
			'meta_query'  => [
				'last_updated' => [
					'key'     => '_sales_data_updated',
					'compare' => 'BETWEEN',
					'value'   => [ 0, time() - HOUR_IN_SECONDS * 2 ],
					'type'    => 'numeric',
				],
			],
			'orderby'     => [ 'last_updated' => 'ASC' ],
		];

		return (array) get_posts( $args );
	}

	/**
	 * Update product sales data.
	 *
	 * @param integer $product_id Product id.
	 */
	private function update_product_sales_data( $product_id ) {
		foreach ( [ 7, 30 ] as $days ) {
			$data = $this->get_sales_data( $days );
			if ( false === $data || ! isset( $data[ $product_id ] ) ) {
				continue;
			}

			$data = $data[ $product_id ];
			foreach ( [ 'sales', 'total', 'profit' ] as $key ) {
				$value = isset( $data[ $key ] ) ? (int) $data[ $key ] : 0;
				update_post_meta( $product_id, "{$key}_{$days}", $value );
			}
		}
	}

	/**
	 * Update product data.
	 *
	 * @param integer $product_id Product id.
	 */
	private function update_product_data( $product_id ) {
		$product = wc_get_product( $product_id );
		if ( empty( $product ) ) {
			return;
		}

		$stock = $product->is_type( 'variable' )
			? (int) $product->get_total_stock()
			: $product->get_stock_quantity();
		$sales = (int) get_post_meta( $product_id, 'sales_30', true );

		// Set overstock.
		$overstock = (int) round( $stock - $sales );
		update_post_meta( $product_id, 'overstock', $overstock );

		// Set stock_days.
		$min_days   = min( $this->get_product_age( $product_id ), 30 );
		$stock_days = $sales ? round( $stock / $sales ) * $min_days : 1000;
		update_post_meta( $product_id, 'stock_days', (int) $stock_days );

		// Set availability_score.
		$availability_score = $this->get_availability_score( $product );
		$availability_score = number_format( round( $availability_score, 2 ), 2 );
		update_post_meta( $product_id, 'availability_score', $availability_score );

		// Set trending.
		$trending = (int) $this->calculate_trending_value( $product_id );
		update_post_meta( $product_id, '_trending', (int) $trending );

		// Set sales_7 if it is missing.
		if ( ! metadata_exists( 'post', $product_id, 'sales_7' ) ) {
			update_post_meta( $product_id, 'sales_7', '0' );
		}

		// Store updated.
		update_post_meta( $product_id, '_sales_data_updated', time() );
	}

	/**
	 * Get sales data.
	 *
	 * @param  integer $days Number of days.
	 * @return array|boolean
	 */
	private function get_sales_data( $days ) {
		global $wpdb;

		// Early Bail!!
		if ( isset( $this->sales_data[ $days ] ) ) {
			return $this->sales_data[ $days ];
		}

		$rows = $wpdb->get_results(
			"SELECT	oim.meta_value			AS product_id,
			        p.post_title		    AS product_name,
			        SUM(oim2.meta_value) 	AS sales,
			        FLOOR(SUM(oim3.meta_value)) AS total,
			        FLOOR((SUM(oim3.meta_value) - SUM(oim4.meta_value))) AS profit
			FROM {$wpdb->prefix}posts as o
			INNER JOIN {$wpdb->prefix}woocommerce_order_items as oi
			       ON o.ID=oi.order_id
			LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta	AS oim
			       ON oi.order_item_id=oim.order_item_id AND oim.meta_key = '_product_id'
			LEFT JOIN {$wpdb->prefix}posts	AS p
			       ON oim.meta_value=p.ID
			LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta	as oim2
			       ON oi.order_item_id=oim2.order_item_id AND oim2.meta_key = '_qty'
			LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta	AS oim3
			       ON oi.order_item_id=oim3.order_item_id AND oim3.meta_key = '_line_subtotal'
			LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta	AS oim4
			       ON oi.order_item_id=oim4.order_item_id AND oim4.meta_key = '_wc_cog_item_total_cost'
			# Orders
			WHERE o.post_status	IN ('wc-processing', 'wc-on-hold', 'wc-packable', 'wc-packing', 'wc-completed', 'wc-awaiting-stock')
			  AND o.post_type = 'shop_order'
			  AND o.post_date >= (curdate() - INTERVAL {$days} DAY)
			# Order Items
			  AND oi.order_item_type	= 'line_item'
			  AND oim3.meta_value > 0 # ignore bundles
			GROUP BY product_id
			ORDER BY profit DESC",
			ARRAY_A
		);

		// Guard clause.
		if ( empty( $rows ) ) {
			$this->sales_data[ $days ] = false;
			return false;
		}

		// Format data.
		foreach ( $rows as $row ) {
			$this->sales_data[ $days ][ $row['product_id'] ] = $row;
		}

		return $this->sales_data[ $days ];
	}

	/**
	 * Get availability score.
	 *
	 * @param  WC_Product $product Product instance.
	 * @return integer
	 */
	private function get_availability_score( $product ) {
		if ( $product->is_type( 'simple' ) ) {
			return (int) $product->is_in_stock();
		}

		if ( $product->is_type( 'variable' ) ) {
			$variations      = $product->get_children();
			$variation_sales = $this->get_sales_for_variation_ids( $variations );

			if ( ! $variation_sales ) {
				return 1;
			}

			$total     = 0;
			$available = 0;

			foreach ( $variations as $variation_id ) {
				$variation = wc_get_product( $variation_id );
				if ( ! $variation || ! $variation->exists() ) {
					continue;
				}

				$total += $variation_sales[ $variation_id ];

				if ( $variation->is_in_stock() && $variation->variation_is_visible() ) {
					$available += $variation_sales[ $variation_id ];
				}
			}

			return ! $total ? 1 : $available / $total;
		}

		return 1;
	}

	/**
	 * Get variation sales.
	 *
	 * @param  array $ids Variations ids.
	 * @return array
	 */
	private function get_sales_for_variation_ids( $ids ) {
		global $wpdb;

		$ids_string = "'" . implode( "','", $ids ) . "'";

		$results = $wpdb->get_results(
			"SELECT oim.meta_value as variation_id, COUNT(*) as sales
			FROM {$wpdb->prefix}woocommerce_order_itemmeta as oim
			INNER JOIN {$wpdb->prefix}woocommerce_order_items as oi
			ON oim.order_item_id=oi.order_item_id
			INNER JOIN {$wpdb->prefix}posts as p
			ON oi.order_id=p.ID
			WHERE oim.meta_key = '_variation_id'
			AND oim.meta_value IN ({$ids_string})
			AND p.post_type = 'shop_order'
			AND p.post_status IN ('wc-processing', 'wc-on-hold', 'wc-packable', 'wc-packing', 'wc-awaiting-stock', 'wc-completed')
			GROUP BY variation_id",
			ARRAY_A
		);

		if ( ! $results ) {
			return false;
		}

		foreach ( $results as $result ) {
			$return[ $result['variation_id'] ] = $result['sales'];
		}

		return $return;
	}

	/**
	 * Calculate trending value.
	 *
	 * @param  integer $product_id Product id.
	 * @return integer
	 *
	 * TODO: Use pageviews so products without exposure can climb a bit
	 * TODO: Use overstock so products that will be sold out soon are penalized
	 */
	private function calculate_trending_value( $product_id ) {
		$value = 0;
		$age   = $this->get_product_age( $product_id );

		// Pull the data from the product.
		$profit_7     = get_post_meta( $product_id, 'profit_7', true );
		$profit_30    = get_post_meta( $product_id, 'profit_30', true );
		$availability = get_post_meta( $product_id, 'availability_score', true );

		// Average out the values if the product is new.
		if ( $age < 7 ) {
			$profit_7 = $profit_7 * ( 7 - $age );
		}

		// Average out the values if the product is new.
		if ( $age < 30 ) {
			$profit_30 = $profit_30 * ( 30 - $age );
		}

		// Add profit to the value calculation.
		$value += ( $profit_7 / 7 ) * 0.75; // Add the average 7 day profit per day to the value. Weight 0.75.
		$value += ( $profit_30 / 30 ) * 0.25; // Add the average 30 profit per day to the value Weight 0.25.

		// Boost new products.
		if ( $age < 14 ) {
			$value += $value * 0.3; // Give all news a 30% boost for 14 days.
			$value += ( 14 - $age ) * ( 600 / 14 ); // Add an 600 profitvalue to new products, and gradually reduce it for 14 days.
		}

		if ( $availability ) {
			$value = $value * (float) $availability;
		}

		return floor( $value );
	}

	/**
	 * Get product age.
	 *
	 * @param  integer $product_id Product id.
	 * @return integer
	 */
	private function get_product_age( $product_id ) {
		// Early Bail!!
		if ( isset( $this->product_age[ $product_id ] ) ) {
			return $this->product_age[ $product_id ];
		}

		$published_timestamp              = get_post_time( 'U', true, $product_id );
		$this->product_age[ $product_id ] = round( ( time() - $published_timestamp ) / DAY_IN_SECONDS );

		return $this->product_age[ $product_id ];
	}
}
