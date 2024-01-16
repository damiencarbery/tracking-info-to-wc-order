<?php
/*
Plugin Name: Shippo webhook to receive updates
Plugin URI: https://www.damiencarbery.com/
Description: Create a custom REST API endpoint to receive info from Shippo.
Author: Damien Carbery
Author URI: https://www.damiencarbery.com
Version: 0.1.20240116
WC tested to: 8.4.0
*/


// Declare that this plugin supports WooCommerce HPOS.
add_action( 'before_woocommerce_init', function() {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
	\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );


// Code heavily based on: https://www.wpeka.com/make-custom-endpoints-wordpress-rest-api.html
class Shippo_Hook_For_Tracking_Info extends WP_REST_Controller {
	private $api_namespace;
	private $base;
	private $api_version;
	private $required_capability;

	public function __construct() {
		$this->api_namespace = 'shippo/v';
		$this->base = 'transaction-updated';
		$this->api_version = '1';
		$this->required_capability = 'read';  // Minimum capability to use the endpoint
		
		$this->init();
	}


	public function register_routes() {
		$namespace = $this->api_namespace . $this->api_version;
		
		register_rest_route( $namespace, '/' . $this->base, array(
			array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( $this, 'add_tracking_info' ), 'permission_callback' => '__return_true' ),
		)  );
	}


	// Register our REST Server
	public function init(){
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}


	public function add_tracking_info( WP_REST_Request $request ){
		$debug_mode = true;
		//$creds = array();
		$headers = getallheaders();
		$transaction_info = json_decode( $request->get_body() );

		// If it is a test from Shippo just end now.
		//error_log( 'Headers: ' . var_export( $headers, true ) );
		//error_log( 'Body: ' . var_export( $request->get_body(), true ) );
		//if ( empty( $transaction_info ) ) { return new WP_REST_Response( null, 200 ); }

		if ( $debug_mode ) {
			error_log( 'Headers: ' . var_export( $headers, true ) );
			error_log( 'JSON: ' . var_export( $transaction_info, true ) );

			$headers_and_json = sprintf( '%sHeaders:%s%s%sJSON:%s%s', "\n\n", "\n", var_export( $headers, true ), "\n", var_export( $transaction_info, true ), "\n" );
			// ToDo: Get this email from WordPress settings.
			//$debug_email = 'efox321@gmail.com';
			$debug_email = 'damien.carbery@gmail.com';
		}

		// Add tracking info to order as custom fields.
		if ( ! empty( $transaction_info->data->metadata ) && ! empty( $transaction_info->data->tracking_number ) && ! empty( $transaction_info->data->tracking_url_provider ) ) {
			$order_id = (int) filter_var( $transaction_info->data->metadata, FILTER_SANITIZE_NUMBER_INT );  // Extract from string like "Order 1234".
			$order = wc_get_order( $order_id );

			// Ensure we have a valid WooCommerce order before adding tracking info.
			if ( $order ) {
				// Sanitize the tracking number and url.
				$tracking_number = filter_var( $transaction_info->data->tracking_number, FILTER_SANITIZE_STRING, FILTER_FLAG_STRIP_LOW | FILTER_FLAG_STRIP_HIGH | FILTER_FLAG_STRIP_BACKTICK );
				$tracking_url = filter_var( $transaction_info->data->tracking_url_provider, FILTER_SANITIZE_URL );

				// Add the tracking info if both number and url pass sanitization checks.
				if ( $tracking_number && $tracking_url ) {
					$order->update_meta_data( 'tracking_number', $tracking_number );
					$order->update_meta_data( 'tracking_url', $tracking_url );

					if ( $debug_mode ) {
						$message = sprintf( 'Added tracking info: "%s" and "%s"', $tracking_number, $tracking_url );
						error_log( $message );
						wp_mail( $debug_email, 'DEBUG: Shippo webhook', $message . $headers_and_json );
					}
					return 'ok - tracking info added.';
				}
				else {
					if ( $debug_mode ) {
						$message = sprintf( 'One or both of tracking number (%s) and url (%s) failed sanitization check.', $transaction_info->data->tracking_number, $transaction_info->data->tracking_url_provider );
						error_log( $message );
						wp_mail( $debug_email, 'DEBUG: Shippo webhook', $message . $headers_and_json );
					}
					return "Failed - $message.";
				}
			}
			else {
				if ( $debug_mode ) {
					$message = sprintf( 'Invalid order ID (%s) from string (%s)', $order_id, $transaction_info->data->metadata );
					error_log( $message );
					wp_mail( $debug_email, 'DEBUG: Shippo webhook', $message . $headers_and_json );
				}
				return "Failed - $message.";
			}
		}
		else {
			if ( $debug_mode ) {
				$message = 'Missing "metadata" (w/order number) or "tracking_number" or "tracking_url_provider" info in $transaction_info.';
				error_log( $message );
				wp_mail( $debug_email, 'DEBUG: Shippo webhook', $message . $headers_and_json );
			}
			return "Failed - $message.";
		}
	}
}
 
$shippo_hook = new Shippo_Hook_For_Tracking_Info();