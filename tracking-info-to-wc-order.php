<?php
/*
Plugin Name: Tracking Info to WooCommerce order
Plugin URI: https://www.damiencarbery.com/
Description: Use CMB2 to add a custom metabox to add tracking information to WooCommerce orders. The information is then added to the "Completed Order" email. Also add custom REST API endpoint to receive info from Shippo.
Author: Damien Carbery
Author URI: https://www.damiencarbery.com
Version: 0.4.20240116
WC tested to: 8.5.1
*/


// Declare that this plugin supports WooCommerce HPOS.
add_action( 'before_woocommerce_init', function() {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
	\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );


// Verify that CMB2 plugin is active.
add_action( 'admin_notices', 'titwo_verify_cmb2_active' );
function titwo_verify_cmb2_active() {
	if ( ! defined( 'CMB2_LOADED' ) ) {
		$current_screen = get_current_screen();
		if ( $current_screen->id == 'shop_order' ) {
			$plugin_data = get_plugin_data( __FILE__ );
			$plugin_name = $plugin_data['Name'];
?>
<div class="notice notice-warning is-dismissible"><p>The <strong><?php echo $plugin_name; ?></strong> plugin requires <a href="https://wordpress.org/plugins/cmb2/">CMB2 plugin</a> to be active to enable adding tracking information to orders.</p></div>
<?php
		}
	}
}


// Add the metabox to allow for manual entering (or editing) of tracking information.
add_action( 'cmb2_admin_init', 'dcwd_order_metabox' );
function dcwd_order_metabox() {
	$woo_hpos_active = get_option( 'woocommerce_custom_orders_table_enabled' );
	$object_types = ( 'yes' == $woo_hpos_active ) ? array( 'woocommerce_page_wc-orders' ) : array( 'shop_order' );

	$cmb = new_cmb2_box( array(
		'id'            => 'order_tracking_info',
		'title'         => 'Tracking Information',
		'object_types'  => $object_types,
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
		'desc' => 'Be sure to add tracking data and click \'Update\' before setting the order status to \'Completed\', and clicking \'Update\' again. If not done in this order the email sent to the customer will not contain the tracking data.',
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
	if ( strpos( $url, 'dhl.' ) !== false ) {
		return 'DHL';
	}
	// Add more as necessary.
	if ( strpos( $url, 'www.singpost.com' ) !== false ) {
		return 'Singapore Post';
	}
	
	// Unknown provider.
	return null;
}


// Determine whether an order has only virtual items (and therefore does not have shipping).
function dcwd_is_order_virtual_only( $order_id ) {
	$order = wc_get_order( $order_id );
	$only_virtual = true;

	if ( $order ) {
		foreach ( $order->get_items() as $order_item ) {
			$item = wc_get_product( $order_item->get_product_id() );
			if ( !$item->is_type( 'virtual' ) && 'pw-gift-card' != $item->get_type() ) {
				// This order contains a physical product so stop looking.
				return false;
			}
		}

		if ( $only_virtual ) {
			//error_log( sprintf( '<p>Order: %d - Virtual.</p>', $order->get_id() ) );
			return true;
		}
		else {
			//error_log( sprintf( '<p>Order: %d - Contains physical products.</p>', $order->get_id() ) );
		}
	}

	return false;
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

		// Do not add tracking info if the order only has virtual items and does not have shipping.
		if ( dcwd_is_order_virtual_only( $order_id ) ) { return; }

		$tracking_number = $order->get_meta( 'tracking_number', true );
		$tracking_url = $order->get_meta( 'tracking_url', true );
		
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


// Display tracking information in My Account area.
add_action( 'woocommerce_view_order', 'dcwd_add_tracking_info_to_view_order_page', 5 );
function dcwd_add_tracking_info_to_view_order_page( $order_id ) {
	$order = wc_get_order( $order_id );
	if ( ! $order ) { return; }
	// Do not add tracking info if the order only has virtual items and does not have shipping.
	if ( dcwd_is_order_virtual_only( $order_id ) ) { return; }

	$tracking_number = $order->get_meta( 'tracking_number');
	$tracking_url = $order->get_meta( 'tracking_url' );

	// Quit if either tracking field is empty.
	if ( empty( $tracking_number ) || empty( $tracking_url ) ) {
		// Debugging code.
		//error_log( sprintf( 'Order %d does not have both tracking number (%s) and url (%s)', $order_id, $tracking_number, $tracking_url ) );
		echo '<p>Sorry, tracking information is not available at this time.</p>';
		return;
	}
		
	$tracking_provider = dcwd_get_tracking_provider_from_url( $tracking_url );
	if ( ! empty( $tracking_provider ) ) {
		printf( '<p>Your order has been shipped with <strong>%s</strong>. The tracking number is <strong><a href="%s">%s</a></strong>.</p>', $tracking_provider, esc_url( $tracking_url, array( 'http', 'https' ) ), esc_html( $tracking_number ) );
	}
	else {
		printf( '<p>Your order has been shipped. The tracking number is <strong><a href="%s">%s</a></strong>.</p>', esc_url( $tracking_url, array( 'http', 'https' ) ), esc_html( $tracking_number ) );
	}
}
