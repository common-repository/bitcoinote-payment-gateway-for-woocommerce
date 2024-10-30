<?php
/**
 * Plugin Name: BitcoiNote payment gateway for WooCommerce
 * Description: Enables users of your WooCommerce site to take BitcoiNote payments when checking out
 * Author: BitcoiNote
 * Author URI: http://www.bitcoinote.org/
 * Version: 1.0.0
 * Text Domain: wc-gateway-btcn
 *
 * Copyright: (c) 2019 BitcoiNote Team (support@bitcoinote.org), 2015-2016 SkyVerge, Inc. (info@skyverge.com) and WooCommerce
 *
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 *
 * @package   WC-BTCN-Payment
 * @author    BitcoiNote
 * @category  Admin
 * @copyright Copyright (c) 2019 BitcoiNote Team (support@bitcoinote.org), 2015-2016, SkyVerge, Inc. and WooCommerce
 * @license   http://www.gnu.org/licenses/gpl-3.0.html GNU General Public License v3.0
 *
 * This online gateway uses the BTCN Payment Gateway Service to enable BTCN payments.
 */

defined( 'ABSPATH' ) or exit;


// Make sure WooCommerce is active
if ( ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
	return;
}

/**
 * Add the gateway to WC Available Gateways
 * 
 * @since 1.0.0
 * @param array $gateways all available WC gateways
 * @return array $gateways all WC gateways + BTCN gateway
 */
function wc_btcn_add_to_gateways( $gateways ) {
	$gateways[] = 'WC_Gateway_BTCN';
	return $gateways;
}
add_filter( 'woocommerce_payment_gateways', 'wc_btcn_add_to_gateways' );


/**
 * Adds plugin page links
 * 
 * @since 1.0.0
 * @param array $links all plugin links
 * @return array $links all plugin links + our custom links (i.e., "Settings")
 */
function wc_btcn_gateway_plugin_links( $links ) {

	$plugin_links = array(
		'<a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout&section=btcn_gateway' ) . '">' . __( 'Configure', 'wc-gateway-btcn' ) . '</a>'
	);

	return array_merge( $plugin_links, $links );
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'wc_btcn_gateway_plugin_links' );


/**
 * BTCN Payment Gateway
 *
 * Provides an integration to the BTCN Payment Gateway Service for BTCN payments
 * We load it later to ensure WC is loaded first since we're extending it.
 *
 * @class 		WC_Gateway_BTCN
 * @extends		WC_Payment_Gateway
 * @version		1.0.0
 * @package		WooCommerce/Classes/Payment
 * @author 		BitcoiNote
 */
add_action( 'plugins_loaded', 'wc_btcn_gateway_init', 11 );

/**
 * Implement the "Complete Payment" button functionality
 * Must be done outside of the class, otherwise the hook is not fired
 * Also checks if the payment was completed and updates internal status accordingly
 * (in case the IPN was not yet received)
 */
function wc_btcn_gateway_thankyou_custom_payment_redirect(){
	/* do nothing if we are not on the appropriate page */
	if( (!is_wc_endpoint_url( 'order-received' ) || empty( $_GET['key'] )) && !is_wc_endpoint_url( 'view-order' ) ) {
		return;
	}

	$order_id = is_wc_endpoint_url( 'view-order' ) ? get_query_var('view-order') : wc_get_order_id_by_order_key( $_GET['key'] );
	$order = wc_get_order( $order_id );

	if( $order && $order->get_payment_method() == 'btcn_gateway' && $order->has_status('on-hold') ) {
		$paymentId = $order->get_meta('btcn_payment_id');
		if (!$paymentId) {
			error_log('No payment ID found for order ' . $order_id);
		}

		$gateway = wc_get_payment_gateway_by_order($order);
		if ($gateway && $gateway->id == 'btcn_gateway') {
			try {
				$tx = $paymentId ? $gateway->gateway_request('GET', '/api/transactions/' . $paymentId, [], true) : null;
				if ($tx && $tx->status === 'completed') {
					$gateway->update_order_status($order, $tx);
				} else if (!empty($_GET['completePayment'])) {
					if ($tx && $tx->status === 'pending') {
						wp_redirect($tx->statusUrl);
						exit;
					} else {
						// We need to create a new transaction here, the old one doesn't exist or was cancelled/expired
						$tx = $gateway->create_transaction($order);
						wp_redirect($tx->statusUrl);
						exit;
					}
				}
			} catch (Exception $e) {
				if (!empty($_GET['completePayment'])) {
					throw $e;
				} else {
					error_log('Exception during TX verification for order ' . $order_id . ': ' . $e->getMessage());
				}
			}
		} else {
			error_log('No BTCN gateway instance returned for order ' . $order_id);
		}
	}
}
add_action( 'template_redirect', 'wc_btcn_gateway_thankyou_custom_payment_redirect' );


function wc_btcn_gateway_init() {

	class WC_Gateway_BTCN extends WC_Payment_Gateway {

		/**
		 * Constructor for the gateway.
		 */
		public function __construct() {
			$this->id                 = 'btcn_gateway';
			$this->icon               = plugin_dir_url( __FILE__ ) . 'btcn-logo.png';
			$this->has_fields         = false;
			$this->method_title       = __( 'BitcoiNote', 'wc-gateway-btcn' );
			$this->method_description = __( 'Integrates to the BTCN Payment Gateway Service for payments with BitcoiNote. (You must have the gateway service installed somewhere on a server!)', 'wc-gateway-btcn' );
		  
			// Load the settings.
			$this->init_form_fields();
			$this->init_settings();
		  
			// Define user set variables
			$this->title        = $this->get_option( 'title' );
			$this->description  = $this->get_option( 'description' );
			$this->instructions = $this->get_option( 'instructions', $this->description );
		  
			$this->gatewayUrl       = $this->get_option( 'gatewayUrl' );
			$this->gatewayUsername  = $this->get_option( 'gatewayUsername' );
			$this->gatewayPassword  = $this->get_option( 'gatewayPassword' );
			$this->gatewayIpnSecret = $this->get_option( 'gatewayIpnSecret' );

			// Actions
			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		  
			// Customer Emails
			add_action( 'woocommerce_email_before_order_table', array( $this, 'email_instructions' ), 10, 3 );

			// Complete Payment button on order received page / order details page
			add_action('woocommerce_order_details_before_order_table', array( $this, 'order_details_payment_reminder' ), 10, 3 );
			add_filter('woocommerce_thankyou_order_received_text', array( $this, 'change_order_received_text' ), 10, 2 );

			// IPN
			add_action('woocommerce_api_wc_gateway_btcn', array($this, 'ipn_callback'));
		}
	
		/**
		 * Initialize Gateway Settings Form Fields
		 */
		public function init_form_fields() {
	  
			$this->form_fields = apply_filters( 'wc_btcn_form_fields', array(
		  
				'enabled' => array(
					'title'   => __( 'Enable/Disable', 'wc-gateway-btcn' ),
					'type'    => 'checkbox',
					'label'   => __( 'Enable BTCN Payment', 'wc-gateway-btcn' ),
					'default' => 'yes'
				),
				
				'title' => array(
					'title'       => __( 'Title', 'wc-gateway-btcn' ),
					'type'        => 'text',
					'description' => __( 'This controls the title for the payment method the customer sees during checkout.', 'wc-gateway-btcn' ),
					'default'     => __( 'BitcoiNote', 'wc-gateway-btcn' ),
					'desc_tip'    => true,
				),
				
				'description' => array(
					'title'       => __( 'Description', 'wc-gateway-btcn' ),
					'type'        => 'textarea',
					'description' => __( 'Payment method description that the customer will see on your checkout.', 'wc-gateway-btcn' ),
					'default'     => __( 'Pay your order with your BTCN coins', 'wc-gateway-btcn' ),
					'desc_tip'    => true,
				),
				
				'instructions' => array(
					'title'       => __( 'Instructions', 'wc-gateway-btcn' ),
					'type'        => 'textarea',
					'description' => __( 'Instructions that will be added to the thank you page and emails.', 'wc-gateway-btcn' ),
					'default'     => '',
					'desc_tip'    => true,
				),

				'gatewayUrl' => array(
					'title'       => __( 'Gateway Service URL', 'wc-gateway-btcn' ),
					'type'        => 'text',
					'description' => __( 'URL of the BitcoiNote Payment Gateway Service. (Don\'t know what to put? Make sure you have the <a href="https://github.com/Bitcoinote/BTCN-Gateway-Service">gateway service</a> installed first!)', 'wc-gateway-btcn' ),
					'default'     => __( 'http://localhost:38071', 'wc-gateway-btcn' ),
					'desc_tip'    => true,
				),
				
				'gatewayUsername' => array(
					'title'       => __( 'Gateway Service Username', 'wc-gateway-btcn' ),
					'type'        => 'text',
					'description' => __( 'Username for the gateway service API.', 'wc-gateway-btcn' ),
					'default'     => __( 'client', 'wc-gateway-btcn' ),
					'desc_tip'    => true,
				),
				
				'gatewayPassword' => array(
					'title'       => __( 'Gateway Service Password', 'wc-gateway-btcn' ),
					'type'        => 'password',
					'description' => __( 'Password for the gateway service API.', 'wc-gateway-btcn' ),
					'default'     => __( '', 'wc-gateway-btcn' ),
					'desc_tip'    => true,
				),
				
				'gatewayIpnSecret' => array(
					'title'       => __( 'Gateway Service IPN Secret', 'wc-gateway-btcn' ),
					'type'        => 'text',
					'description' => __( 'This value must be the same as configured in the <tt>IPN_SECRET</tt> variable of the gateway service.', 'wc-gateway-btcn' ),
					'default'     => __( '', 'wc-gateway-btcn' ),
					'desc_tip'    => true,
				),
			) );
		}

		/**
		 * Return "Complete Payment" button if required
		 *
		 * @access public
		 * @param WC_Order $order
		 */
		function get_payment_reminder ( $order ) {
			$str = '';

			if ( isset($_GET['paymentId']) ) {
				if ($_GET['status'] === 'cancelled') {
					$str .= '<div class="woocommerce-error">' .  __('Your payment was cancelled!', 'wc-gateway-btcn') . '</div>';
				} else if ($_GET['status'] === 'expired') {
					$str .= '<div class="woocommerce-error">' .  __('Your payment has expired!', 'wc-gateway-btcn') . '</div>';
				} else {
					$str .= '<div class="woocommerce-message">' .  __('Your payment was successful!', 'wc-gateway-btcn') . '</div>';
				}
			}

			if ($this->id === $order->get_payment_method() && $order->has_status( 'on-hold' )) {
				$str .= '<div class="woocommerce-info">' .  __('We didn\'t receive your payment yet! Please click the button below to complete your order:', 'wc-gateway-btcn') . '</div><a class="button alt" href="' . $order->get_checkout_order_received_url() . '&completePayment=1">' . __('Complete Payment', 'wc-gateway-btcn') . '</a><br><br>';
			}

			return $str;
		}

		/**
		 * Add "Complete Payment" button to order details page if required
		 *
		 * @access public
		 * @param WC_Order $order
		 */
		function order_details_payment_reminder( $order ) {
			if (!is_wc_endpoint_url('order-received')) echo $this->get_payment_reminder($order);
		}

		/**
		 * Add "Complete Payment" button to order received page if required
		 *
		 * @access public
		 * @param string $str
		 * @param WC_Order $order
		 */
		function change_order_received_text( $str, $order ) {
			if (!is_wc_endpoint_url('order-received')) return $str;
			return $this->get_payment_reminder($order) . $str;
		}

		/**
		 * Add content to the WC emails.
		 *
		 * @access public
		 * @param WC_Order $order
		 * @param bool $sent_to_admin
		 * @param bool $plain_text
		 */
		public function email_instructions( $order, $sent_to_admin, $plain_text = false ) {
			if ( ! $sent_to_admin && $this->id === $order->get_payment_method() && $order->has_status( 'on-hold' ) ) {
				if ($this->instructions) {
					echo wpautop( wptexturize( $this->instructions ) ) . PHP_EOL;
				}
				echo wpautop('<strong>' . sprintf(__('To see your order status or finish payment, visit <a href="%s">this link</a>!', 'wc-gateway-btcn'), $order->get_checkout_order_received_url()) . '</strong>') . PHP_EOL;
			}

			if ( ! $sent_to_admin && $this->id === $order->get_payment_method() && $order->has_status( 'completed' ) ) {
				echo wpautop(__('BitcoiNote Payment ID:', 'wc-gateway-btcn') . ' <tt>' . $order->get_meta('btcn_payment_id') . '</tt>' . PHP_EOL);
			}
		}

		/**
		 * Send a request to the gateway service
		 *
		 * @param string $method
		 * @param string $path
		 * @param array $body
		 * @param bool $nullOn404
		 * @return $result
		 */
		public function gateway_request ( $method, $path, $body = [], $nullOn404 = false ) {
			$args = array(
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( $this->get_option('gatewayUsername') . ':' . $this->get_option('gatewayPassword') )
				),
				'method' => $method,
				'body' => $body
			);
			$res = wp_remote_request($this->get_option('gatewayUrl') . $path, $args);
			if (is_wp_error($res)) throw new Exception('Gateway request failed, error: ' . $res->get_error_message());
			$code = wp_remote_retrieve_response_code($res);
			$respBody = wp_remote_retrieve_body($res);
			if ($code === 204) return null;
			if ($code === 200 || $code === 201) return json_decode($respBody);
			if ($code === 404 && $nullOn404) return null;
			throw new Exception('Gateway request failed, status: ' . $code . ', data: '. $respBody);
		}
	
		/**
		 * Creates a gateway transaction for an order, saves it in the order and returns it
		 *
		 * @param WC_Order $order
		 * @return object
		 */
		public function create_transaction( $order ) {
			$tx = $this->gateway_request('POST', '/api/transactions', [
				'amount' => $order->get_total(),
				'currency' => get_woocommerce_currency(),
				'description' => get_bloginfo('name') . ' ' . __( 'Order #', 'wc-gateway-btcn' ) . $order->get_id(),
				'customData' => $order->get_id(),
				'ipnUrl' => WC()->api_request_url('WC_Gateway_BTCN'),
				'successRedirectUrl' => $this->get_return_url( $order ),
				'errorRedirectUrl' => $order->get_checkout_order_received_url(),
				'allowUserCancel' => '1'
			]);

			$order->update_meta_data('btcn_payment_id', $tx->paymentId);
			$order->update_status( 'on-hold', __( 'Awaiting BTCN payment, payment ID: ' , 'wc-gateway-btcn') . $tx->paymentId );
			if ($order->has_status('on-hold')) $order->add_order_note(__('New gateway transaction created, payment ID: ', 'wc-gateway-btcn' ) . $tx->paymentId );
			return $tx;
		}

		/**
		 * Process the payment and return the result
		 *
		 * @param int $order_id
		 * @return array
		 */
		public function process_payment( $order_id ) {
	
			$order = wc_get_order( $order_id );
			
			// Create transaction with gateway service
			$tx = $this->create_transaction($order);
			
			WC()->cart->empty_cart();

			// Return thankyou redirect
			return array(
				'result' 	=> 'success',
				'redirect'	=> $tx->statusUrl
			);
		}

		/**
		 * Updates an order to the given status from the gateway
		 * 
		 * @param WC_Order $order
		 * @param object $tx
		 */
		public function update_order_status ($order, $tx) {
			if ($tx->status == 'completed') {
				if ($order->get_status() == 'on-hold') {
					// Reduce stock levels
					$order->reduce_order_stock();

					$order->update_status('completed', __('BTCN payment successful, payment ID: ', 'wc-gateway-btcn') . $tx->paymentId . ' (' . $tx->amount . ' BTCN)');
				}
			}
		}
	
		/**
		 * Process the IPN callback from the gateway service
		 */
		public function ipn_callback() {
			$rawBody = file_get_contents("php://input");
			$expectedSignature = hash_hmac('sha256', $rawBody, $this->get_option('gatewayIpnSecret'));
			if ($_SERVER['HTTP_X_IPN_SIGNATURE'] != $expectedSignature) {
				throw new Exception('Invalid signature');
			}
			$tx = json_decode($rawBody);

			$order = wc_get_order($tx->customData);
			if (!$order) {
				throw new Exception('Order not found: ' . $order);
			}

			$expectedPaymentId = $order->get_meta('btcn_payment_id');
			if ($expectedPaymentId != $tx->paymentId) {
				error_log('Unexpected payment ID, "' . $tx->paymentId . '" instead of "' . $expectedPaymentId . '"');
				$order->update_meta_data('btcn_payment_id', $tx->paymentId);
			}

			$this->update_order_status($order, $tx);

			echo 'OK';
		}
  } // end \WC_Gateway_BTCN class
}