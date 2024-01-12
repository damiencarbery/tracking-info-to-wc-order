<?php
/*
Plugin Name: Tracking Info to WooCommerce order
Plugin URI: https://www.damiencarbery.com/
Description: Use CMB2 to add a custom metabox to add tracking information to WooCommerce orders. The information is then added to the "Completed Order" email. Also add custom REST API endpoint to receive info from Shippo.
Author: Damien Carbery
Author URI: https://www.damiencarbery.com
Version: 0.3.20240112
*/


// Declare that this plugin supports WooCommerce HPOS.
add_action( 'before_woocommerce_init', function() {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
	\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );


// Add the metabox to allow for manual entering (or editing) of tracking information.
add_action( 'cmb2_admin_init', 'dcwd_order_metabox' );
function dcwd_order_metabox() {
	$cmb = new_cmb2_box( array(
		'id'            => 'order_tracking_info',
		'title'         => 'Tracking Information',
		'object_types'  => array( 'shop_order', ), // Post type
		'context'       => 'side',
		'priority'      => 'high',
		'show_names'    => true, // Show field names on the left
	) );
	$cmb->add_field( array(
		'name'  => 'Tracking number',
		'id'    => 'tracking_number',
		'type'  => 'text',
	) );
	$cmb->add_field( array(
		'name'       => 'Tracking URL',
		'id'         => 'tracking_url',
		'type'       => 'text_url',
		'protocols'  => array( 'http', 'https' ),
	) );
}


// Examine the tracking url and return a provider name.
function dcwd_get_tracking_provider_from_url( $url ) {
	if ( strpos( $url, 'usps.com' ) !== false ) {
		return 'USPS';
	}
	if ( strpos( $url, 'fedex.com' ) !== false ) {
		return 'FedEx';
	}
	if ( strpos( $url, 'ups.com' ) !== false ) {
		return 'UPS';
	}
	// Add more as necessary.
	
	// Unknown provider.
	return null;
}


// If available, include the tracking information in the Completed Order email.
add_action( 'woocommerce_email_order_details', 'dcwd_add_tracking_info_to_order_completed_email', 5, 4 ); 
function dcwd_add_tracking_info_to_order_completed_email( $order, $sent_to_admin, $plain_text, $email ) {
/*	// Only customers need to know about the tracking information.
	if ( ! $sent_to_admin ) {
		return;
	}
*/
	if ( 'customer_completed_order' == $email->id ) {
		$order_id = $order->get_id();
		$tracking_number = $order->get_meta( $order_id, 'tracking_number', true );
		$tracking_url = $order->get_meta( $order_id, 'tracking_url', true );
		
		// Quit if either tracking field is empty.
		if ( empty( $tracking_number ) || empty( $tracking_url ) ) {
			// Debugging code.
			error_log( sprintf( 'Order %d does not have both tracking number (%s) and url (%s)', $order_id, $tracking_number, $tracking_url ) );
			echo '<h2>Tracking information</h2><p>Sorry, tracking information is not available at this time.</p>';
			return;
		}
		
		$tracking_provider = dcwd_get_tracking_provider_from_url( $tracking_url );

		if ( $plain_text ) {
			if ( ! empty( $tracking_provider ) ) {
				printf( "\nYour order has been shipped with %s. The tracking number is %s and you can track it at %s.\n", $tracking_provider, esc_html( $tracking_number ), esc_url( $tracking_url, array( 'http', 'https' ) ) );
			}
			else {
				printf( "\nYour order has been shipped. The tracking number is %s and you can track it at %s.\n", esc_html( $tracking_number ), esc_url( $tracking_url, array( 'http', 'https' ) ) );
			}
		}
		else {
			if ( ! empty( $tracking_provider ) ) {
				printf( '<h2>Tracking information</h2><p>Your %s tracking number is <a href="%s" style="color: #a7bf49">%s</a>.</p>', $tracking_provider, esc_url( $tracking_url, array( 'http', 'https' ) ), esc_html( $tracking_number ) );
			}
			else {
				printf( '<h2>Tracking information</h2><p>Your tracking number is <strong><a href="%s" style="color: #a7bf49">%s</a></strong>.</p>', esc_url( $tracking_url, array( 'http', 'https' ) ), esc_html( $tracking_number ) );
			}
		}
	}
}


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
			array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( $this, 'add_tracking_info' ), ),
		)  );
	}


	// Register our REST Server
	public function init(){
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}
	
	
	public function add_tracking_info( WP_REST_Request $request ){
		$debug_mode = true;
		$creds = array();
		$headers = getallheaders();
		$transaction_info = json_decode( $request->get_body() );
		
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
					$order->update_meta_data( $order_id, 'tracking_number', $tracking_number );
					$order->update_meta_data( $order_id, 'tracking_url', $tracking_url );
					
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