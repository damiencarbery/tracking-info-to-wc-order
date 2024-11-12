<?php
/*
Plugin Name: Tracking Info to WooCommerce order
Plugin URI: https://www.damiencarbery.com/2020/01/add-tracking-info-to-woocommerce-order/
Description: Use custom metabox to add tracking information to WooCommerce orders. The information is then added to the "Completed Order" email.
Author: Damien Carbery
Author URI: https://www.damiencarbery.com
Version: 0.10.20241112
WC tested to: 9.3.3
Text Domain: tracking-info-to-wc-order
Domain Path: /languages
Requires Plugins: woocommerce
License: GPLv2
License URI: https://www.gnu.org/licenses/gpl-2.0.html
*/


defined( 'ABSPATH' ) || exit;


use Automattic\WooCommerce\Utilities\OrderUtil;


// Declare that this plugin supports WooCommerce HPOS.
add_action( 'before_woocommerce_init', function() {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
	\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );


add_action( 'init', 'dcwd_tracking_info_translations' );
function dcwd_tracking_info_translations() {
	load_plugin_textdomain( 'tracking-info-to-wc-order', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}


add_action( 'woocommerce_loaded', 'dcwd_load_meta_box_hooks' );
function dcwd_load_meta_box_hooks() {
	add_action( 'add_meta_boxes', 'dcwd_add_tracking_info_meta_box' );
	// Use different hook to save meta data when HPOS active.
	if ( OrderUtil::custom_orders_table_usage_is_enabled() ) {
		add_action( 'woocommerce_process_shop_order_meta', 'dcwd_save_tracking_info_meta_box_data' );
	}
	else {
		add_action( 'save_post', 'dcwd_save_tracking_info_meta_box_data' );
	}
}


function dcwd_add_tracking_info_meta_box() {
	// Use different screen paaramater to show meta box when HPOS active.
	if ( OrderUtil::custom_orders_table_usage_is_enabled() ) {
		add_meta_box( 'dcwd_tracking_info_meta_box', 'Tracking Information', 'dcwd_display_tracking_info_meta_box', 'woocommerce_page_wc-orders', 'side', 'high' );
	}
	else {
		add_meta_box( 'dcwd_tracking_info_meta_box', 'Tracking Information', 'dcwd_display_tracking_info_meta_box', 'shop_order', 'side', 'high' );
	}
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
  <label for="tracking_number"><?php echo esc_html_x( 'Tracking number', 'Form label for tracking number', 'tracking-info-to-wc-order' ); ?></label>
  <input type="text" class="dcwd-tracking" name="tracking_number" value="<?php esc_attr_e( $tracking_number ); ?>" />
  <label for="tracking_url"><?php echo esc_html_x( 'Tracking URL', 'Form label for tracking url', 'tracking-info-to-wc-order' ); ?></label>
  <input type="text" name="tracking_url" value="<?php esc_attr_e( $tracking_url ); ?>" />
</div>
<?php
}


// Sanitize and store the updated tracking info.
function dcwd_save_tracking_info_meta_box_data( $order_id ) {
	$is_autosave = wp_is_post_autosave( $order_id );
	$is_revision = wp_is_post_revision( $order_id );
	$is_valid_nonce = ( isset( $_POST[ 'dcwd_tracking_info_nonce' ] ) && wp_verify_nonce( wp_unslash( $_POST[ 'dcwd_tracking_info_nonce' ] ), plugin_basename( __FILE__ ) ) );

	// Return if autosave or revision or if nonce invalid.
	if ( ( $is_autosave || $is_revision ) && !$is_valid_nonce ) { return; } 

	if ( ! ( $is_autosave || $is_revision ) && $is_valid_nonce ) {
		$order = wc_get_order( $order_id );
		if ( $order ) {
			$save_needed = false;
			if ( isset( $_POST['tracking_number'] ) && 0 < strlen( trim( wp_unslash( $_POST['tracking_number'] ) ) ) ) {
				$tracking_number = sanitize_text_field( trim( wp_unslash( $_POST['tracking_number'] ) ) );
				$order->update_meta_data( 'tracking_number', $tracking_number );
				$save_needed = true;
			}

			if ( isset( $_POST['tracking_url'] ) && 0 < strlen( trim( wp_unslash( $_POST['tracking_url'] ) ) ) ) {
				$tracking_url = sanitize_url( trim( wp_unslash( $_POST['tracking_url'] ) ) );
				$order->update_meta_data( 'tracking_url', $tracking_url );
				$save_needed = true;
			}

			if ( $save_needed ) {
				$order->save_meta_data();
			}
		}
	}
}


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
	$providers = apply_filters( 'dcwd_set_tracking_providers', array(
		'USPS'  => 'usps.com',
		'FedEx' => 'fedex.com',
		'UPS' => 'ups.com',
		'DHL' => 'dhl.',
		'Singapore Post' => 'www.singpost.com',
		'Royal Mail' => 'royalmail.com',
	) );

	foreach ( $providers as $provider => $provider_url ) {
		if ( strpos( $url, $provider_url ) !== false ) {
			return $provider;
		}
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
			echo wp_kses( __( '<h2>Tracking information</h2><p>Sorry, tracking information is not available at this time.</p>', 'tracking-info-to-wc-order' ), wp_kses_allowed_html( 'post' ) );
			return;
		}

		$tracking_provider = dcwd_get_tracking_provider_from_url( $tracking_url );

		if ( $plain_text ) {
			if ( ! empty( $tracking_provider ) ) {
				// translators: %1$s is tracking provider name; %2$s is tracking number; %3$s is tracking url.
				echo sprintf( "\n" . wp_kses( __( 'Your order has been shipped with %1$s. The tracking number is %2$s and you can track it at %3$s.', 'tracking-info-to-wc-order' ) . "\n", wp_kses_allowed_html( 'post' ) ), esc_html( $tracking_provider ), esc_html( $tracking_number ), esc_url( $tracking_url, array( 'http', 'https' ) ) );
			}
			else {
				// translators: %1$s is tracking provider name; %2$s is tracking number
				echo sprintf( "\n" . wp_kses( __( 'Your order has been shipped. The tracking number is %1$s and you can track it at %2$s.', 'tracking-info-to-wc-order' ) . "\n", wp_kses_allowed_html( 'post' ) ), esc_html( $tracking_number ), esc_url( $tracking_url, array( 'http', 'https' ) ) );
			}
		}
		else {
			if ( ! empty( $tracking_provider ) ) {
				// translators: %1$s is tracking provider name; %2$s is tracking url; %3$s is tracking number.
				echo sprintf( wp_kses( __( '<h2>Tracking information</h2><p>Your %1$s tracking number is <a href="%2$s" style="color: #a7bf49">%3$s</a>.</p>', 'tracking-info-to-wc-order' ), wp_kses_allowed_html( 'post' ) ), esc_html( $tracking_provider ), esc_url( $tracking_url, array( 'http', 'https' ) ), esc_html( $tracking_number ) );
			}
			else {
				// translators: %1$s is tracking url; %2$s is tracking number.
				echo sprintf( wp_kses( __( '<h2>Tracking information</h2><p>Your tracking number is <strong><a href="%1$s" style="color: #a7bf49">%2$s</a></strong>.</p>', 'tracking-info-to-wc-order' ), wp_kses_allowed_html( 'post' ) ), esc_url( $tracking_url, array( 'http', 'https' ) ), esc_html( $tracking_number ) );
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
		echo '<p>', wp_kses( __( 'Sorry, tracking information is not available at this time.', 'tracking-info-to-wc-order' ), wp_kses_allowed_html( 'post' ) ), '</p>';
		return;
	}

	$tracking_provider = dcwd_get_tracking_provider_from_url( $tracking_url );
	if ( ! empty( $tracking_provider ) ) {
		// translators: %1$s is tracking provider name; %2$s is tracking url; %3$s is tracking number.
		echo sprintf( wp_kses( __( '<p>Your order has been shipped with <strong>%1$s</strong>. The tracking number is <strong><a href="%2$s">%3$s</a></strong>.</p>', 'tracking-info-to-wc-order' ), wp_kses_allowed_html( 'post' ) ), esc_html( $tracking_provider ), esc_url( $tracking_url, array( 'http', 'https' ) ), esc_html( $tracking_number ) );
	}
	else {
		// translators: %1$s is tracking url; %2$s is tracking number.
		echo sprintf( wp_kses( __( '<p>Your order has been shipped. The tracking number is <strong><a href="%1$s">%2$s</a></strong>.</p>', 'tracking-info-to-wc-order' ), wp_kses_allowed_html( 'post' ) ), esc_url( $tracking_url, array( 'http', 'https' ) ), esc_html( $tracking_number ) );
	}
}
