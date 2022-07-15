<?php
/*
 * DNotes checking payments class
 *
 * Author: DNotes Global, Inc.
 * Author URI: https://dnotesglobal.com/
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WC_DNotesPay_Cron {

	/**
	 * Cron action name. Constant
	 */
	const CRON_NAME = 'dnotespay_check_payments';

	public function __construct() {

		// filter for getting orders by custom order meta key "dnotespay_payment_status"
		add_filter( 'woocommerce_order_data_store_cpt_get_orders_query', array( $this, 'handle_custom_query_var' ), 10, 2 );

		// register action for cron job
		add_action( self::CRON_NAME, array( $this, 'check_payments_cronjob' ) );

		// adding wp cron custom interval
		add_filter( 'cron_schedules', array( $this, 'cron_custom_interval' ) );
		
		// Schedule check payments event on wp init
		add_action( 'init', array( $this, 'schedule_check_payments_event' ) );		
	}

	/**
	 * Handle a custom 'dnotespay_payment_status' query var to get orders with the 'dnotespay_payment_status' meta.
	 *
	 * @since 1.0
	 * @param array $query - Args for WP_Query.
	 * @param array $query_vars - Query vars from WC_Order_Query.
	 * @return array modified $query
	 */
	public function handle_custom_query_var( $query, $query_vars ) {
		if ( ! empty( $query_vars['dnotespay_payment_status'] ) ) {
			$query['meta_query'][] = $query_vars['dnotespay_payment_status'];
		}
		return $query;
	}

 	/**
 	 * Check payment status via cron job
 	 *
 	 * @since 1.0
 	 */
 	public function check_payments_cronjob() {
		$args = array(
			'limit'						=> -1,
			'return'					=> 'ids',
			'dnotespay_payment_status' 	=> array(
												'key'     => 'dnotespay_payment_status',
												'value'   => array( 'waiting-payment', 'processing-payment' ),
												'compare' => 'IN'
											)
		);
		$orders = wc_get_orders( $args );
		if ( count( $orders ) > 0 ) {
			$dnotespay = new WC_Gateway_DNotesPay();
			foreach( $orders as $order_id ) {
				$dnotespay->dnotespay_check_payment( $order_id );
			}
		}
 	}

 	/**
 	 * Adding wp cron custom interval
 	 *
 	 * @since 1.0
 	 */
	public function cron_custom_interval( $schedules ) {
		$schedules['one_minute'] = array(
			'interval'	=> 60,	// Number of seconds, 60 in 1 minute
			'display'	=> 'Every Minute'
		);
		return $schedules; 
	}
 	
	/**
 	 * Schedule check payments event
 	 *
 	 * @since 1.0
 	 */
	public function schedule_check_payments_event() {
		// Make sure this event hasn't been scheduled
		if( !wp_next_scheduled( self::CRON_NAME ) ) {
			// Schedule the event
			wp_schedule_event( time(), 'one_minute', self::CRON_NAME );
		}
	}

	/**
 	 * Unschedule check payments event. Fire on deactivation plugin.
 	 *
 	 * @since 1.0
 	 */
	public function unschedule_check_payments_event() {
		 while ( $timestamp = wp_next_scheduled( self::CRON_NAME ) ) {
			wp_unschedule_event( $timestamp, self::CRON_NAME );
		}
	}
	
} new WC_DNotesPay_Cron(); // end WC_DNotesPay_Cron class
