<?php
/*
Plugin Name: Tracking Info to WooCommerce order
Plugin URI: https://www.damiencarbery.com/2020/01/add-tracking-info-to-woocommerce-order/
Description: Use CMB2 to add a custom metabox to add tracking information to WooCommerce orders. The information is then added to the "Completed Order" email.
Author: Damien Carbery
Author URI: https://www.damiencarbery.com
Version: 0.5.20240406
WC tested to: 8.5.2
*/

defined( 'ABSPATH' ) || exit;


use Automattic\WooCommerce\Utilities\OrderUtil;

// If HPOS is active then use custom meta box as CMB2 does not yet support HPOS.
add_action( 'woocommerce_loaded', 'dcwd_check_hpos_active' );
function dcwd_check_hpos_active() {
	if ( OrderUtil::custom_orders_table_usage_is_enabled() ) {
		// If HPOS is active then use custom meta box as CMB2 does not yet support HPOS.
		add_action( 'add_meta_boxes', 'dcwd_add_tracking_info_meta_box' );
		add_action( 'woocommerce_process_shop_order_meta', 'dcwd_save_tracking_info_meta_box_data' );
	} else {
		// If HPOS not active then use CMB2.
		add_action( 'admin_notices', 'titwo_verify_cmb2_active' );
	}
}


function dcwd_add_tracking_info_meta_box() {
	add_meta_box( 'dcwd_tracking_info_meta_box', 'Tracking Information', 'dcwd_display_tracking_info_meta_box', 'woocommerce_page_wc-orders', 'side', 'high' );
}


// Render custom meta box.
function dcwd_display_tracking_info_meta_box( $post ) {
	wp_nonce_field( plugin_basename(__FILE__), 'dcwd_tracking_info_nonce' );

	// Get the order object to retrieve the meta.
	if ( $post instanceof WC_Order ) {
		$order_id = $post->get_id();
	} else {
		$order_id = $post->ID;
	}
	$order = wc_get_order( $order_id );
	$tracking_number = $order->get_meta( 'tracking_number', true );
	$tracking_url = $order->get_meta( 'tracking_url', true );
?>
<style>
.dcwd-tracking-info label { font-weight: bold; padding-bottom: 0.5em; }
.dcwd-tracking-info input { width: 100%; margin-bottom: 1em; }
</style>
<div class="dcwd-tracking-info">
  <label for="tracking_number">Tracking number</label>
  <input type="text" class="dcwd-tracking" name="tracking_number" value="<?php esc_attr_e( $tracking_number ); ?>" />
  <label for="tracking_url">Tracking URL</label>
  <input type="text" name="tracking_url" value="<?php esc_attr_e( $tracking_url ); ?>" />
  <p class="dcwd-tracking-info-description">Be sure to add tracking data and click 'Update' before setting the order status to 'Completed', and clicking 'Update' again. If not done in this order the email sent to the customer will not contain the tracking data.</p>
</div>
<?php
}


// Sanitize and store the updated tracking info.
function dcwd_save_tracking_info_meta_box_data( $order_id ) {
	if ( dcwd_user_can_save( $order_id, 'dcwd_tracking_info_nonce' ) ) {
		$order = wc_get_order( $order_id );
		if ( $order ) {
			if ( isset( $_POST['tracking_number'] ) && 0 < strlen( trim( $_POST['tracking_number'] ) ) ) {
				$tracking_number = sanitize_text_field( trim( $_POST['tracking_number'] ) );
				$order->update_meta_data( 'tracking_number', $tracking_number );
			}

			if ( isset( $_POST['tracking_url'] ) && 0 < strlen( trim( $_POST['tracking_url'] ) ) ) {
				$tracking_url = sanitize_url( $_POST['tracking_url'] );
				$order->update_meta_data( 'tracking_url', $tracking_url );
			}
		}
	}
}


// Verify the nonce and that this is not a post revision or autosave.
function dcwd_user_can_save( $post_id, $nonce ) {
	$is_autosave = wp_is_post_autosave( $post_id );
	$is_revision = wp_is_post_revision( $post_id );
	$is_valid_nonce = ( isset( $_POST[ $nonce ] ) && wp_verify_nonce( $_POST [ $nonce ], plugin_basename( __FILE__ ) ) );

	return ! ( $is_autosave || $is_revision ) && $is_valid_nonce;
}


// Verify that CMB2 plugin is active.
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


// Declare that this plugin supports WooCommerce HPOS.
add_action( 'before_woocommerce_init', function() {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
	\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );


// Add the metabox to allow for manual entering (or editing) of tracking information.
add_action( 'cmb2_admin_init', 'dcwd_order_metabox' );
function dcwd_order_metabox() {
	// Set different 'object_types' if HPOS active.
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
		// Uncomment this if you need to set a default tracking url that you can easily edit.
		//'default'    => 'https://the_tracking_url/path/to/tracking',
		'desc' => 'Be sure to add tracking data and click \'Update\' before setting the order status to \'Completed\', and clicking \'Update\' again. If not done in this order the email sent to the customer will not contain the tracking data.',
	) );
}


// 2022-10-16: Comment this out as it is breaking in Woo >6.9.3. Instead, I simply enable the deferring of emails.
/*
// Move the saving of order meta (which triggers emails) to be *after* CMB2 data saved.
// NOTE: This could have unintended consequences.
//add_action( 'wp_loaded', 'dcwd_move_wc_order_meta_save');
function dcwd_move_wc_order_meta_save() {
	remove_action( 'woocommerce_process_shop_order_meta', 'WC_Meta_Box_Order_Data::save', 40 );
	// Call WC_Meta_Box_Order_Data::save later, after CMB2 data is saved.
	add_action( 'save_post', 'dcwd_save_post_order_data', 50, 3 );
}


// Call WC_Meta_Box_Order_Data::save later, after CMB2 data is saved.
function dcwd_save_post_order_data( $post_id, $post, $update ) {
	// Call the WooCommerce Meta Box Order Data save function for WC_Order posts only.
// TODO: Look at wc_get_order_types() as do_action('woocommerce_process_shop_order_meta') runs when in_array( $post->post_type, wc_get_order_types( 'order-meta-boxes' ), true )
	if ( 'shop_order' == $post->post_type) {
		WC_Meta_Box_Order_Data::save( $post_id );
	}
}*/


// Defer emails for 10 seconds to allow time for CMB2 to save the tracking data.
add_filter( 'woocommerce_defer_transactional_emails', '__return_true' );


// If using 'Email Template Customizer for WooCommerce' plugin then use a different hook
// to add the tracking information to the email.
add_action( 'plugins_loaded', 'dcwd_check_for_email_template_customizer' );
function dcwd_check_for_email_template_customizer() {
    if ( class_exists( 'Woo_Email_Template_Customizer' ) ) {
        // Email Template Customizer for WooCommerce plugin does not use the 'woocommerce_email_order_details'
        // hook so use 'woocommerce_email_after_order_table' instead (it is one of the 3 available ones in the
        // plugin's 'WC Hook' field.
        add_action( 'woocommerce_email_after_order_table', 'dcwd_add_tracking_info_to_order_completed_email', 5, 4 );
    }
}


// Examine the tracking url and return a provider name.
function dcwd_get_tracking_provider_from_url( $url ) {
	// ToDo: Consider putting these in an array with apply_filters().
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
	if ( strpos( $url, 'royalmail.com' ) !== false ) {
		return 'Royal Mail';
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
			if ( !$item->is_virtual() && !$item->is_downloadable() && 'pw-gift-card' != $item->get_type() ) {
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
			//error_log( sprintf( 'Order %d does not have both tracking number (%s) and url (%s)', $order_id, $tracking_number, $tracking_url ) );
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
