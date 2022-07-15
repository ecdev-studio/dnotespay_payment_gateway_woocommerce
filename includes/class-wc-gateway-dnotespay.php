<?php
/*
 * Main DNotes Payment class
 *
 * Author: DNotes Global, Inc.
 * Author URI: https://dnotesglobal.com/
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WC_Gateway_DNotesPay extends WC_Payment_Gateway {

	/**
	 * wp-config.php path
	 * @var string
	 */
	private static $wp_config_path;

	/**
	 * UNIX cronjob string
	 * @var string
	 */
	private static $cronjob_string;

	public function __construct() {
		$this->id = 'dnotespay'; // payment gateway plugin ID
		$this->icon = WC_DNotesPay::img_url().'dnotes-icon.png'; // URL of the icon that will be displayed on checkout page near gateway name
		$this->has_fields = false; // should be true if you need a custom credit card form
		$this->order_button_text = __( 'Pay with DNotes', 'dnotespay' ); // Button text on the frontend
		$this->method_title = __( 'DNotes Pay' ); // will be displayed on the options page
		$this->method_description = __( 'DNotes payment gateway' ); // will be displayed on the options page
		$this->supports = array( // gateways can support subscriptions, refunds, saved payment methods etc
			'products'
		); 
		
		// Load the options form fields.
		$this->init_form_fields();

		// Load the settings.
		$this->init_settings();

		// Define user set variables
		$this->enabled = $this->get_option( 'enabled' ) == 'yes' ? true : false;
		$this->title = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );
		$this->wallet = $this->dnotespay_get_wallet();
		$this->tolerance = ( $this->get_option( 'tolerance' ) ) ? (float) trim( $this->get_option( 'tolerance' ) ) : 0.01;
		$this->confirm_num = $this->dnotespay_get_confirm_num();
		$this->payment_waiting_time = (int) $this->get_option( 'payment_waiting_time' );
		$this->payment_processing_time = (int) $this->get_option( 'payment_processing_time' );

		// Receipt page creates POST to gateway or hosts iFrame
		add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'receipt_page' ) );

		// This action hook saves the settings
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

		// Show admin notices if there are any
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
	}

	/**
	 * Output the gateway settings screen.
	 */
	public function admin_options() {
		parent::admin_options();
		printf(
			'<table class="form-table">
				<tbody>
					<tr valign="top">
						<th scope="row" class="titledesc">
							<label>Notice</label>
						</th>
						<td>
							%s
							<blockquote style="font-weight: 700"><pre>define(&#39;DISABLE_WP_CRON&#39;, true);</pre></blockquote>
							%s
  							<blockquote style="font-weight: 700"><pre style="word-wrap: break-word">%s</pre></blockquote>							
						</td>
					</tr>				
				</tbody>
			</table>',
			sprintf( 
				__( 'To ensure this plugin checks payments correctly, it is recommended to disable the default WordPress cron job schedules and setup a unix cron job. Please add the following to the end of your %s file.', 'dnotespay'),
				'<span style="font-family: monospace;font-weight: 700">'.(self::wp_config_path() ? esc_html( self::wp_config_path() ) : 'wp-config.php').'</span>'
			),
			__( 'It is important that you setup a cron job also to run every minute, the recommended setting for your installation is:', 'dnotespay'),
			self::cronjob_string()
		);
	 }

	/**
     * Determines the location of the wp-config.php file
     * @return string returns absolute path to wp-config.php
     */
    public static function wp_config_path() {
    	if ( !self::$wp_config_path ) {
	        // determine the current path
	        $base = dirname( __FILE__ );

	        $path = dirname( dirname( $base ) ) . '/wp-config.php';
	        if ( file_exists( $path ) ) {
	            // we have found the wp-config.php file
	            self::$wp_config_path = $path;
	        } elseif ( file_exists( dirname( $path ) ) ) {
	            // we have found the wp-config.php file
	            self::$wp_config_path = dirname( $path ) . '/wp-config.php';
	        } else {
	            self::$wp_config_path = false;
	        }
		}

        return self::$wp_config_path;
    }

	/**
     * Returns the ideal cronjob string
     * @return string string
     */
    public static function cronjob_string() {
    	if ( !self::$cronjob_string ) {
    		self::$cronjob_string = sprintf(
    			'* * * * * wget -qO- &quot;%s/wp-cron.php?doing_wp_cron&quot; &>/dev/null',
            	esc_attr( get_bloginfo( 'wpurl' ) )
    		);
    	}
        return self::$cronjob_string;
    }

	/**
	 * Plugin options
	 */
	public function init_form_fields(){
		$this->form_fields = array(
			'enabled' => array(
				'title'       => __( 'Enable/Disable', 'dnotespay' ),
				'label'       => __( 'Enable DNotes Gateway', 'dnotespay' ),
				'type'        => 'checkbox',
				'description' => '',
				'default'     => 'no'
			),
			'title' => array(
				'title'       => __( 'Title', 'dnotespay' ),
				'type'        => 'text',
				'description' => __( 'This controls the title which the user sees during checkout.', 'dnotespay' ),
				'default'     => __( 'DNotes Pay', 'dnotespay' ),
			),
			'description' => array(
				'title'       => __( 'Description', 'dnotespay' ),
				'type'        => 'textarea',
				'description' => __( 'This controls the description which the user sees during checkout.', 'dnotespay' ),
				'default'     => __( 'Pay with DNotes.', 'dnotespay' )
			),
			'wallet_addresses' => array(
				'title'       => __( 'DNotes addresses', 'dnotespay' ),
				'type'        => 'textarea',
				'description' => __( 'Add your DNotes addresses to receive payment. Each address in a new line.', 'dnotespay' )
			),
			'tolerance' => array(
				'title'       => __( 'Payment tolerance', 'dnotespay' ),
				'type'        => 'text',
				'description' => __( 'Payment tolerance default 0.01', 'dnotespay' ),
				'default'	  => 0.01
			),
			'confirm_num' => array(
				'title'       => __( 'Number of transaction confirmations', 'dnotespay' ),
				'type'        => 'text',
				'description' => __( 'Number of transaction confirmations required <br>0 = Fast, payment has been sent but not confirmed to be valid, up to 1 minute <br>6 = Slow, transaction fully validated on the network, up to 6 minutes', 'dnotespay' ),
				'default'	  => WC_DNotesPay::CONFIRM_NUM_DEFAULT
			),
			'payment_waiting_time' => array(
				'title'       => __( 'Waiting for payment time limit', 'dnotespay' ),
				'type'        => 'text',
				'description' => __( 'This value should be in seconds. Default is 900 (15 minutes in seconds)', 'dnotespay' ),
				'default'     => 900,
			),
			'payment_processing_time' => array(
				'title'       => __( 'Processing payment time limit', 'dnotespay' ),
				'type'        => 'text',
				'description' => __( 'This value should be greater than "Waiting for payment time limit". Default is 172800 (2 days in seconds)', 'dnotespay' ),
				'default'     => 172800,
			)
		);
	}

	/**
	 * Check if gateway is enabled and available
	 */
	public function is_available() {
		return $this->enabled && $this->wallet && $this->dnotespay_is_valid_currency() && $this->dnotespay_check_hold_stock() === true && $this->dnotespay_is_time_limit_correct() === true;
	}

	/**
	 *  Output admin notices if there are any
	 */
	public function admin_notices() {
	    if ( !$this->enabled ) return;

	    if ( !$this->wallet ) {
	        printf(
	        	'<div class="error"><p>%s</p></div>',
				__( 'DNotes Pay error: Please enter your DNotes addresses to receive payment.', 'dnotespay')
	        );
	    } 
	    if ( !$this->dnotespay_is_valid_currency() ) {
	    	printf(
	        	'<div class="error"><p>%s</p></div>',
				__( 'DNotes Pay error: Currently, the plugin can only work with online stores in which the main currency is USD.', 'dnotespay')
	        );
	    }
	    if ( $this->dnotespay_is_valid_confirm_num( $this->get_option( 'confirm_num' ) ) !== true ) {
	    	printf(
	        	'<div class="error"><p>%s</p></div>',
				sprintf( 
					__( 'DNotes Pay error: Number of transaction confirmations should be between %s and %s.', 'dnotespay'),
					WC_DNotesPay::CONFIRM_NUM_MIN,
					WC_DNotesPay::CONFIRM_NUM_MAX
				)
	        );
	    }
	    if ( $this->dnotespay_check_hold_stock() !== true ) {
			printf(
	        	'<div class="error"><p>%s</p></div>',
	        	sprintf( 
					__( 'DNotes Pay error: Hold stock should be equals or bigger than %s minutes. Please follow the <a href="/wp-admin/admin.php?page=wc-settings&tab=products&section=inventory">link</a> for set this value.', 'dnotespay'),
					$this->dnotespay_check_hold_stock()
				)
	        );
	    }
	    if ( $this->dnotespay_is_time_limit_correct() !== true ) {
			printf(
	        	'<div class="error"><p>%s</p></div>',
				__( 'DNotes Pay error: Error in Payment processing/waiting time limit.', 'dnotespay')
	        );
	    }
	}

	/**
	 * Check if woocommerce currency is suitable for the plugin
	 */
	public function dnotespay_is_valid_currency() {
	    return in_array( get_woocommerce_currency(), array( 'USD' ) );
	}

	/**
	 * Check if woocommerce hold stock value is not lower than processing payment time
	 */
	public function dnotespay_check_hold_stock() {
		$woocommerce_hold_stock_minutes = (float) get_option( 'woocommerce_hold_stock_minutes' );
    	$woocommerce_hold_stock_minutes_min = ceil( $this->payment_processing_time / 60 );
	    return ( $woocommerce_hold_stock_minutes >= $woocommerce_hold_stock_minutes_min ) ? true : $woocommerce_hold_stock_minutes_min;
	}

	/**
	 * Get random wallet address from wallet addresses list (user settings)
	 */
	public function dnotespay_get_wallet() {
		$wallet = false;
		$wallets = $this->get_option( 'wallet_addresses' );
		if ( !empty( $wallets ) ) {
			$wallets = explode( PHP_EOL, $wallets );
			$wallets = array_flip( $wallets );
			$wallet = array_rand( $wallets );
			$wallet = trim( $wallet );
		}
		return $wallet;
	}

	/**
	 * Get confirmation number from user settings
	 */
	public function dnotespay_get_confirm_num( ) {
		$confirm_num = $this->get_option( 'confirm_num' );
		$valid_confirm_num = $this->dnotespay_is_valid_confirm_num( $confirm_num );
		return $valid_confirm_num !== true ? $valid_confirm_num : (int) $confirm_num;
	}

	/**
	 * Check if confirmation nymber is valid
	 */
	public function dnotespay_is_valid_confirm_num( $confirm_num ) {
		$result = true;
		if ( !is_numeric( $confirm_num ) ) {
			$result = WC_DNotesPay::CONFIRM_NUM_DEFAULT;
		} else {
			$confirm_num = (int) $confirm_num;
			if ( $confirm_num < WC_DNotesPay::CONFIRM_NUM_MIN ) {
				$result = WC_DNotesPay::CONFIRM_NUM_MIN;
			} else if ( $confirm_num > WC_DNotesPay::CONFIRM_NUM_MAX ) {
				$result = WC_DNotesPay::CONFIRM_NUM_MAX;
			}
		}

		return $result;
	}

	/**
	 * Check if waiting time limit is corect
	 */
	public function dnotespay_is_time_limit_correct() {
		return $this->payment_waiting_time > 0 && $this->payment_processing_time > 0 && $this->payment_processing_time > $this->payment_waiting_time;
	}

	/*
	 * Redirecting to receipt page here
	 */
	public function process_payment( $order_id ) {
		$order = new WC_Order( $order_id );

		add_post_meta( $order_id, 'dnotes_send_amount', $this->dnotespay_convert_order_total( $order->get_total() ), true );
		add_post_meta( $order_id, 'dnotes_send_address', $this->dnotespay_send_address(), true );
		add_post_meta( $order_id, 'dnotes_tolerance', $this->tolerance, true );
		add_post_meta( $order_id, 'dnotes_confirm_num', $this->confirm_num, true );

		// Redirect to receipt page
		return array(
			'result'   => 'success',
			'redirect' => $order->get_checkout_payment_url( true )
		);
	}

	/**
	 * Convert order total to DNotes currency
	 *
	 * @param float $order_total
	 *
	 * @return float
	 */
	public function dnotespay_convert_order_total( $order_total ) {
		$ch = curl_init();
	    curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
	    curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
	    curl_setopt( $ch, CURLOPT_URL, 'https://api.coinmarketcap.com/v2/ticker/184/' );
	    $result = curl_exec( $ch );
	    curl_close( $ch );
	    
	    $result_json = json_decode( $result );
	    $result_data = $result_json->data;
	    $result_quotes = $result_data->quotes;
	    $result_usd = $result_quotes->USD;
	    $usd_price = $result_usd->price;

		return round( ( $order_total / $usd_price ) , 5 );
	}

	/**
	 * Generate and add invoice number to DNotes wallet address
	 *
	 * @return string
	 */
	public function dnotespay_send_address() {
		$unix_time = time();
	    $characters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
	    $charactersLength = strlen( $characters );
	    $randomString = '';
	    for ( $i = 0; $i < 10; $i++ ) {
	        $randomString .= $characters[rand(0, $charactersLength - 1)];
	    }
	    $invoice_number = $unix_time.$randomString;

	    $send_address = $this->wallet."+".$invoice_number;

	    return $send_address;
	}

	/**
	 * Output payment form on receipt page
	 *
	 * @access public
	 *
	 * @param $order_id
	 */
	public function receipt_page( $order_id ) {
		global $woocommerce;
		// Empty cart
		if ( $woocommerce && $woocommerce->cart ) {
			$woocommerce->cart->empty_cart();
		}
		
		$order = new WC_Order( $order_id );
		$thanks_url = $this->get_return_url( $order );
		$check_payment = $this->dnotespay_check_payment( $order_id );
		$payment_status = $check_payment['payment_status'];
		$time_left = $check_payment['time_left'];

		// if payment completed - redirect to thanks page
		if ( $payment_status == 'success' ) {
			wp_safe_redirect( $thanks_url );
			exit;
		}

		if ( $time_left <= 0 ) {
			wp_safe_redirect( $order->get_checkout_payment_url( true ) );
			exit;
		}

		wp_enqueue_style( 'dnotespay_receipt_page' );
		wp_enqueue_script( 'dnotespay_receipt_page' );
		wp_localize_script( 'dnotespay_receipt_page', 'dnotespay', array(
			'ajaxurl' => admin_url( 'admin-ajax.php' ),
			'order_id' => $order_id,
			'nonce' => wp_create_nonce( WC_DNotesPay::NONCE_KEY ),
			'redirect' => $thanks_url
		) );

		$send_amount = (float) get_post_meta( $order_id, 'dnotes_send_amount', true );
		$send_amount = round( $send_amount , 5 );
		$send_address = get_post_meta( $order_id, 'dnotes_send_address', true );
		
		printf(
			'<div class="dnotespay-wrapper" data-payment-status="%s">
				<div class="dnotespay-row">
					<img class="dnotespay-logo" src="%s" />
				</div>
				<div class="dnotespay-row">
					<div class="dnotespay-label">%s</div>
					<div class="dnotespay-value"><span class="dnotespay-click-to-copy">%s</span></div>
				</div>
				<div class="dnotespay-row">
					<div class="dnotespay-label">%s</div>
					<div class="dnotespay-value"><span class="dnotespay-click-to-copy">%s</span></div>
				</div>
				<div class="dnotespay-row">
					<div class="dnotespay-label">%s</div>
					<div class="dnotespay-value dnotespay-payment-status">
						<span class="dnotespay-payment-status-waiting">%s</span>
						<span class="dnotespay-payment-status-processing">%s</span>
						<span class="dnotespay-payment-status-complete">%s</span>
						<span class="dnotespay-payment-status-cancelled">%s</span>
					</div>
				</div>
				<div class="dnotespay-row">
					<div class="dnotespay-label">%s</div>
					<div class="dnotespay-value" id="dnotespay-time-left">%s</div>
				</div>
				<div class="dnotespay-row">
					<a href="#dnotespay_check_status" id="dnotespay-check-status" class="dnotespay-check-status">%s</a>
					<a href="#dnotespay_just_paid" id="dnotespay-just-paid" class="dnotespay-just-paid">%s</a>
				</div>
				<div class="dnotespay-row">
					%s
				</div>
			</div>',
			$payment_status,
			WC_DNotesPay::img_url().'dnotes-logo.png',
			__( 'Send:', 'dnotespay'),
			$send_amount,
			__( 'To:', 'dnotespay'),
			$send_address,
			__( 'Status:', 'dnotespay'),
			__( 'Waiting for payment', 'dnotespay'),
			__( 'Processing payment', 'dnotespay'),
			__( 'Completed payment', 'dnotespay'),
			__( 'Cancelled payment', 'dnotespay'),
			__( 'Time left:', 'dnotespay'),
			$this->dnotespay_seconds_to_time( $time_left ),
			__( 'Update payment status', 'dnotespay'),
			__( 'Click Here Once Paid', 'dnotespay'),
			__( 'This page can be closed. Your order will be processed automatically after the payment confirmation', 'dnotespay')
		);
	}

	/**
	 * Get current order status
	 *
	 * @access public
	 *
	 * @param $order_id
	 * @param $new_status - optional
	 * @return array('payment_status', 'time_left')
	 */
	public function dnotespay_check_payment( $order_id, $new_status = false ) {
		$order = new WC_Order( $order_id );
		$order_age = $this->dnotespay_get_order_age( $order );
		
		$amount = get_post_meta( $order_id, 'dnotes_send_amount', true );
		$address = get_post_meta( $order_id, 'dnotes_send_address', true );
		$tolerance = get_post_meta( $order_id, 'dnotes_tolerance', true );
		$confirm_num = get_post_meta( $order_id, 'dnotes_confirm_num', true );
		
		$call_url = 'https://abe.dnotescoin.com/chain/DNotes/q/invoice/'.$address;
	    $ch = curl_init();
	    curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
	    curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
	    curl_setopt( $ch, CURLOPT_URL, $call_url );
	    $result = curl_exec( $ch );
	    curl_close( $ch );
	    $result_val = explode( ",", $result );
	    $limit_price = $amount - $tolerance;

	    if ( ( $result_val[0] > $limit_price ) && ( $result_val[1] >= $confirm_num ) ) {
	        $payment_status = 'success';
	    } elseif ( $order_age >= $this->payment_processing_time ) {
	    	$payment_status = 'cancelled-payment';
	    } elseif ( $result_val[0] > 0 ) {
	        $payment_status = 'processing-payment';
	    } else {
            $payment_status = $new_status ?: 'waiting-payment';
	    }

	    // try update payment status
		$payment_status = $this->dnotespay_update_payment_status( $order_id, $payment_status );

	    return array(
	    	'payment_status' 	=> $payment_status,
			'time_left'			=> $this->dnotespay_get_left_time( $order, $payment_status )
	    );
	}

	/**
	 * Try to update payment status
	 *
	 * @access public
	 *
	 * @param $order_id
	 * @param $new_status
	 * @return (string) current payment status
	 */
	public function dnotespay_update_payment_status( $order_id, $new_status ) {
		$current_status = $this->dnotespay_get_payment_status( $order_id );
		if ( $current_status != $new_status &&
			( in_array( $new_status, ['success', 'cancelled-payment'] ) ||
			 ( $new_status == 'processing-payment' && !in_array( $current_status, ['success', 'cancelled-payment'] ) ) ||
			 ( $new_status == 'waiting-payment' && !in_array( $current_status, ['processing-payment', 'success', 'cancelled-payment'] ) ) ) ) {
			if ( update_post_meta( $order_id, 'dnotespay_payment_status', $new_status ) ) {
				$current_status = $new_status;
			}
		}

		$order = new WC_Order( $order_id );
		if ( $current_status == 'success' ) {
			// we received the payment
			$order->payment_complete();
			$order->reduce_order_stock();
			// some notes to customer (replace true with false to make it private)
			$order->add_order_note( 'Hey, your order is paid! Thank you!', true );
		} elseif ( $current_status == 'cancelled-payment' ) {
			$order->update_status( 'cancelled', __( 'DNotes Payment failed. Payment timeout.', 'dnotespay' ) );
		} elseif ( in_array( $current_status, ['waiting-payment', 'processing-payment'] ) ) {
			if ( $this->dnotespay_get_left_time( $order, $current_status ) <= 0 ) {
				$order->update_status( 'cancelled', __( 'DNotes payment timeout.', 'dnotespay' ) );
			} else {
				if ( $order->get_status() == 'cancelled' ) {
					$order->update_status( 'pending', __( 'Processing DNotes payment.', 'dnotespay' ) );
				}
			}
		}
		
		return $current_status;
	}

	/**
	 * Get current payment status from DB
	 *
	 * @access public
	 *
	 * @param $order_id
	 * @return (string) paymen status
	 */
	public function dnotespay_get_payment_status( $order_id ) {
		return get_post_meta( $order_id, 'dnotespay_payment_status', true );
	}

	/**
	 * Get time to cancell payment
	 *
	 * @access public
	 *
	 * @param $order_id
	 * @param $payment_status
	 * @return payment lifetime in seconds
	 */
	public function dnotespay_get_left_time( $order, $payment_status ) {
		$order_age = $this->dnotespay_get_order_age( $order );
		$time_limit = ( $payment_status == 'waiting-payment' ) ? $this->payment_waiting_time : $this->payment_processing_time;
		$time_left = $time_limit - $order_age;
		return $time_left;
	}

	/**
	 * Get time to cancell payment
	 *
	 * @access public
	 *
	 * @param $order - WC Order
	 * @return order age in seconds
	 */
	public function dnotespay_get_order_age( $order ) {
		$order_data = $order->get_data();
		$order_age = time() - $order_data['date_created']->getTimestamp();
		return $order_age;
	}

	/**
	 * Convert seconds to right format
	 *
	 * @access public
	 *
	 * @param $seconds
	 * @return formated time
	 */
	public function dnotespay_seconds_to_time( $seconds ) {
		if ( $seconds <= 0 ) return 0;

		$seconds_per_day = 86400;
		$seconds_per_hour = 3600;
		$seconds_per_minute = 60;
		$minutes_per_hour = 60;
		$hours_per_day = 24;

		$days = intval( $seconds / $seconds_per_day ); // days
		$days = $days ? sprintf( _n( '%s day', '%s days', $days, 'dnotespay' ), $days ).' ' : '';

		$time = array();
		$time[] = intval( $seconds / $seconds_per_hour ) % $hours_per_day; // hours
		$time[] = intval( $seconds / $seconds_per_minute ) % $minutes_per_hour; // minutes
		$time[] = $seconds % $seconds_per_minute; // seconds

		foreach ( $time as $k => $val ) {
			if ( $val == 0 && $days == '' && ( $k == 0 || ( $k == 1 && $time[0] == 0 ) ) ) {
				unset( $time[$k] );
			} elseif ( $val < 10 ) {
				$time[$k] = '0'.$val;
			}
		}

		return $days.implode( ':', $time );
	}
}