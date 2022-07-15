<?php
/**
 * Plugin Name: 	DNotes Pay Payment Gateway for WooCommerce
 * Description: 	Extends WooCommerce with an DNotes Pay gateway.
 * Version: 		1.0
 * Author:          EcDev Studio
 * Author URI:		https://www.ecdevstudio.com/
 * Text Domain:		dnotespay
 * Domain Path:		/languages
 * 
 * WC requires at least: 3.4.5
 * WC tested up to: 3.4.5
*/

// If this file is called directly, abort.
if ( !defined( 'ABSPATH' ) ) {
    die;
}

/**
 * Check if WooCommerce is active
 **/
if ( !in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
    return;
}

class WC_DNotesPay {

	/**
	 * Number of transaction confirmations required. Default value. Constant
	 */
	const CONFIRM_NUM_DEFAULT = 3;

	/**
	 * Number of transaction confirmations required. Min value. Constant
	 */
	const CONFIRM_NUM_MIN = 0;

	/**
	 * Number of transaction confirmations required. Max value. Constant
	 */
	const CONFIRM_NUM_MAX = 6;

	/**
	 * Nonce key for AJAX requests. Constant
	 */
	const NONCE_KEY = 'GfxM1V4.5-rHz:#F}-aRnU_@O iO^@!0W@Nzuy!E8fM4M7y@Qdcm.I3V0Gp`JDiK';

	/**
	 * Plugin Path
	 * @var string
	 */
	private static $plugin_path;

	/**
	 * Languages Path
	 * @var string
	 */
	private static $languages_path;

	/**
	 * Includes Path
	 * @var string
	 */
	private static $includes_path;

	/**
	 * Plugin URL
	 * @var type
	 */
	private static $plugin_url;

	/**
	 * Assets URL
	 * @var type
	 */
	private static $assets_url;

	/**
	 * CSS URL
	 * @var type
	 */
	private static $css_url;

	/**
	 * JS URL
	 * @var type
	 */
	private static $js_url;

	/**
	 * Images URL
	 * @var type
	 */
	private static $img_url;

	public function __construct() {
		// Add required files
		add_action( 'plugins_loaded', array( $this, 'load_gateway_files' ) );

		// Add a 'Settings' link to the plugin action links
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'settings_support_link' ), 10, 4 );

		add_action( 'init', array( $this, 'load_text_domain' ) );

		add_filter( 'woocommerce_payment_gateways', array( $this, 'add_dnotespay_gateway' ) );

		// Load frontend styles and scripts
		add_action( 'wp_enqueue_scripts', array( $this, 'frontend_scripts' ) );

		// Check payment status via AJAX
		add_action( 'wp_ajax_nopriv_dnotespay_check_payment', array( $this, 'check_payment_ajax' ) );
		add_action( 'wp_ajax_dnotespay_check_payment', array( $this, 'check_payment_ajax' ) );

		// Change DNotes payment status on order status change
		add_action( 'woocommerce_order_status_changed',  array( $this, 'payment_status_change'), 10, 3 );

		// Thank you page custom title
		add_action( 'woocommerce_endpoint_order-received_title',  array( $this, 'thankyou_order_received_title'), 10, 2 );

		// Thank you page custom text
		add_action( 'woocommerce_thankyou_order_received_text',  array( $this, 'thankyou_order_received_text'), 10, 1 );
	}

	/**
	 * Load gateway files
	 *
	 * @since 1.0
	 */
	public function load_gateway_files() {
		if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
			return;
		}

		include_once( self::includes_path().'class-wc-gateway-dnotespay.php' );
		include_once( self::includes_path().'class-wc-dnotespay-cron.php' );
	}

	/**
	 * Add 'Settings' link to the plugin actions links
	 *
	 * @since 1.0
	 * @return array associative array of plugin action links
	 */
	public function settings_support_link( $actions, $plugin_file, $plugin_data, $context ) {
		$page    = 'wc-settings';
		$tab     = 'checkout';
		$section = 'dnotespay';

		return array_merge(
			array ( 'settings' => '<a href="' . admin_url( 'admin.php?page=' . $page . '&tab=' . $tab . '&section=' . $section ) . '">' . __( 'Settings', 'dnotespay' ) . '</a>' ),
			$actions
		);
	}

	/**
	 * Localization
	 *
	 * @since 1.0
	 **/
	public function load_text_domain() {
		load_plugin_textdomain( 'dnotespay', false, self::languages_path() );
	}

	/**
	 * Add the gateway to WooCommerce
	 *
	 * @since 1.0
	 * @param array $methods
	 * @return array
	 */
	function add_dnotespay_gateway( $methods ) {
		$methods[] = 'WC_Gateway_DNotesPay';

		return $methods;
	}

	/**
	 * Get the plugin path
	 *
	 * @since 1.0
	 * @return string
	 */
	public static function plugin_path() {
		if ( self::$plugin_path ) {
			return self::$plugin_path;
		}

		return self::$plugin_path = plugin_dir_path( __FILE__ );
	}

	/**
	 * Get the languages path
	 *
	 * @since 1.0
	 * @return string
	 */
	public static function languages_path() {
		if ( self::$languages_path ) {
			return self::$languages_path;
		}

		return self::$languages_path = self::plugin_path().'languages/';
	}

	/**
	 * Get the plugin path
	 *
	 * @since 1.0
	 * @return string
	 */
	public static function includes_path() {
		if ( self::$includes_path ) {
			return self::$includes_path;
		}

		return self::$includes_path = self::plugin_path().'includes/';
	}

	/**
	 * Get the plugin URL
	 *
	 * @since 1.0
	 * @return string
	 */
	public static function plugin_url() {
		if ( self::$plugin_url ) {
			return self::$plugin_url;
		}

		return self::$plugin_url = plugin_dir_url( __FILE__ );
	}

	/**
	 * Get the assets URL
	 *
	 * @since 1.0
	 * @return string
	 */
	public static function assets_url() {
		if ( self::$assets_url ) {
			return self::$assets_url;
		}

		return self::$assets_url = self::plugin_url().'assets/';
	}

	/**
	 * Get the CSS URL
	 *
	 * @since 1.0
	 * @return string
	 */
	public static function css_url() {
		if ( self::$css_url ) {
			return self::$css_url;
		}

		return self::$css_url = self::assets_url().'css/';
	}

	/**
	 * Get the JS URL
	 *
	 * @since 1.0
	 * @return string
	 */
	public static function js_url() {
		if ( self::$js_url ) {
			return self::$js_url;
		}

		return self::$js_url = self::assets_url().'js/';
	}

	/**
	 * Get the images URL
	 *
	 * @since 1.0
	 * @return string
	 */
	public static function img_url() {
		if ( self::$img_url ) {
			return self::$img_url;
		}

		return self::$img_url = self::assets_url().'images/';
	}

	/**
	 * Fire on activation plugin
	 *
	 * @since 1.0
	 */
	static function install() {
		self::load_gateway_files();
		$dnotespay = new WC_Gateway_DNotesPay();
	    $hold_stock = $dnotespay->dnotespay_check_hold_stock();
	    if ( $hold_stock !== true ) {
	    	update_option( 'woocommerce_hold_stock_minutes', $hold_stock );
	    }
    }

	/**
	 * Fire on deactivation plugin
	 *
	 * @since 1.0
	 */
    static function uninstall() {
        WC_DNotesPay_Cron::unschedule_check_payments_event();
    }

    /**
 	 * Register scripts
 	 *
 	 * @since 1.0
 	 */
	function frontend_scripts() {
		wp_register_style( 'dnotespay_receipt_page', self::css_url().'receipt_page.css', array(), false, 'all' );
		wp_register_script( 'dnotespay_receipt_page', self::js_url().'receipt_page.js', array('jquery'), '', true );
	}

	/**
 	 * check payment status on "Pay for order" page
 	 *
 	 * @since 1.0
 	 */
	function check_payment_ajax() {
		check_ajax_referer( self::NONCE_KEY, 'security' );
		$order_id = (int) $_POST['order_id'];
		$new_status = sanitize_text_field( $_POST['new_status'] );
		$result = array();
		if ( !empty( $order_id ) ) {
			$dnotespay = new WC_Gateway_DNotesPay();
			$result = $dnotespay->dnotespay_check_payment( $order_id, $new_status );
			
			//show error if order status is cancelled
			$order = new WC_Order( $order_id );
			if ( $order->get_status() == 'cancelled' ) {
				wc_add_notice( 'Payment not received.', 'error' );
			}
		}
		wp_send_json( $result );
	}

	/**
 	 * If new order status is "pending" than change payment status to "waiting-payment"
 	 *
 	 * @since 1.0
 	 */
	public function payment_status_change( $order_id, $old_status, $new_status ) {
		if ( $new_status == "pending" ) {
			update_post_meta( $order_id, 'dnotespay_payment_status', 'waiting-payment' );
		}
	}

	/**
 	 * Thank you page custom title
 	 *
 	 * @since 1.0
 	 */
	public function thankyou_order_received_title( $title, $endpoint ) {
		global $wp;

		$order_id = (int) $wp->query_vars['order-received'];
		if ( $order_id ) {
			$order = new WC_Order( $order_id );
			$payment_method = $order->get_payment_method();
			if ( $payment_method == 'dnotespay' ) {
				$title = __( 'Thank you for your order!', 'dnotespay' );
			}
		}

		return $title;
	}

	/**
 	 * Thank you page custom text
 	 *
 	 * @since 1.0
 	 */
	public function thankyou_order_received_text( $text ) {
		global $wp;

		$order_id = (int) $wp->query_vars['order-received'];
		if ( $order_id ) {
			$order = new WC_Order( $order_id );
			$payment_method = $order->get_payment_method();
			if ( $payment_method == 'dnotespay' ) {
				$text = $order->get_status() == 'pending'
					? __( 'Your order is being processed and you will receive an email once payment has been confirmed.', 'dnotespay' )
					: '';
			}
		}

		return $text;
	}	

} new WC_DNotesPay(); // end WC_DNotesPay class

/**
 * Plugin activation hook
 */
register_activation_hook( __FILE__, array( 'WC_DNotesPay', 'install' ) );

/**
 * Plugin deactivation hook
 */
register_deactivation_hook( __FILE__, array( 'WC_DNotesPay', 'uninstall' ) );