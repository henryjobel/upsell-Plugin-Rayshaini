<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles AJAX requests for accept/decline offers (popup-based).
 */
class JM_Upsell_Ajax {

    public function __construct() {
        add_action( 'wp_ajax_jm_upsell_accept', array( $this, 'accept_offer' ) );
        add_action( 'wp_ajax_nopriv_jm_upsell_accept', array( $this, 'accept_offer' ) );

        add_action( 'wp_ajax_jm_upsell_decline', array( $this, 'decline_offer' ) );
        add_action( 'wp_ajax_nopriv_jm_upsell_decline', array( $this, 'decline_offer' ) );

        // Checkout pre-order: add upsell/downsell product to cart before order is placed.
        add_action( 'wp_ajax_jm_upsell_cart_add', array( $this, 'cart_add' ) );
        add_action( 'wp_ajax_nopriv_jm_upsell_cart_add', array( $this, 'cart_add' ) );

        // Checkout mode: get the downsell data for a declined upsell step.
        add_action( 'wp_ajax_jm_upsell_get_downsell', array( $this, 'get_downsell' ) );
        add_action( 'wp_ajax_nopriv_jm_upsell_get_downsell', array( $this, 'get_downsell' ) );
    }

    /**
     * Return downsell step data for a given upsell step (checkout mode, no order needed).
     */
    public function get_downsell() {
        check_ajax_referer( 'jm_upsell_checkout', 'nonce' );

        $step_id = isset( $_POST['step_id'] ) ? absint( $_POST['step_id'] ) : 0;

        if ( ! $step_id ) {
            wp_send_json_success( array( 'downsell' => null ) );
            return;
        }

        $downsell = JM_Upsell_Funnel::get_downsell_for_step( $step_id );
        if ( ! $downsell ) {
            wp_send_json_success( array( 'downsell' => null ) );
            return;
        }

        $step_data = JM_Upsell_Funnel::build_step_data( $downsell );
        wp_send_json_success( array( 'downsell' => $step_data ) );
    }

    /**
     * Add the upsell product to the cart (called before checkout is submitted).
     */
    public function cart_add() {
        check_ajax_referer( 'jm_upsell_checkout', 'nonce' );

        $step_id = isset( $_POST['step_id'] ) ? absint( $_POST['step_id'] ) : 0;

        $step = JM_Upsell_Funnel::get_step( $step_id );
        if ( ! $step ) {
            wp_send_json_error( array( 'message' => __( 'Invalid step.', 'jejeemech-upsell' ) ) );
            return;
        }

        // Resolve the purchasable product: use the variation if one is saved.
        $variation_id       = isset( $step->variation_id ) ? intval( $step->variation_id ) : 0;
        $purchasable_product = $variation_id > 0 ? wc_get_product( $variation_id ) : wc_get_product( $step->product_id );

        if ( ! $purchasable_product || ! $purchasable_product->is_purchasable() ) {
            wp_send_json_error( array( 'message' => __( 'Product not available.', 'jejeemech-upsell' ) ) );
            return;
        }

        $price    = floatval( $purchasable_product->get_price() );
        $discount = floatval( $step->discount );
        $final    = $discount > 0 ? $price - ( $price * $discount / 100 ) : $price;

        $cart_item_data = array(
            '_jm_upsell'      => 'yes',
            '_jm_upsell_type' => $step->step_type,
        );

        if ( $discount > 0 ) {
            $cart_item_data['_jm_upsell_price']    = $final;
            $cart_item_data['_jm_upsell_discount'] = $discount . '%';
        }

        $variation_attrs = array();
        if ( $variation_id > 0 ) {
            $variation_attrs = $purchasable_product->get_variation_attributes();
        }

        $added = WC()->cart->add_to_cart( intval( $step->product_id ), 1, $variation_id, $variation_attrs, $cart_item_data );

        if ( $added ) {
            wp_send_json_success( array( 'message' => __( 'Added to your order!', 'jejeemech-upsell' ) ) );
        } else {
            wp_send_json_error( array( 'message' => __( 'Could not add product. Please try again.', 'jejeemech-upsell' ) ) );
        }
    }

    /**
     * Accept upsell/downsell offer.
     */
    public function accept_offer() {
        check_ajax_referer( 'jm_upsell_offer', 'nonce' );

        $order_id  = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
        $order_key = isset( $_POST['order_key'] ) ? sanitize_text_field( wp_unslash( $_POST['order_key'] ) ) : '';
        $step_id   = isset( $_POST['step_id'] ) ? absint( $_POST['step_id'] ) : 0;
        $funnel_id = isset( $_POST['funnel_id'] ) ? absint( $_POST['funnel_id'] ) : 0;

        $order = wc_get_order( $order_id );
        if ( ! $order || $order->get_order_key() !== $order_key ) {
            wp_send_json_error( array( 'message' => __( 'Invalid order.', 'jejeemech-upsell' ) ) );
            return;
        }

        $step = JM_Upsell_Funnel::get_step( $step_id );
        if ( ! $step ) {
            wp_send_json_error( array( 'message' => __( 'Invalid step.', 'jejeemech-upsell' ) ) );
            return;
        }

        // Use variation product when one is saved, otherwise the simple/parent product.
        $variation_id = isset( $step->variation_id ) ? intval( $step->variation_id ) : 0;
        $product      = $variation_id > 0 ? wc_get_product( $variation_id ) : wc_get_product( $step->product_id );
        if ( ! $product ) {
            wp_send_json_error( array( 'message' => __( 'Product not found.', 'jejeemech-upsell' ) ) );
            return;
        }

        // Calculate price.
        $price       = floatval( $product->get_price() );
        $discount    = floatval( $step->discount );
        $final_price = $discount > 0 ? $price - ( $price * $discount / 100 ) : $price;

        // Add product to order.
        // set_product() must be called — it copies tax class, SKU, and links the item
        // to the product object so WooCommerce persists it correctly.
        $item = new WC_Order_Item_Product();
        $item->set_product( $product );
        $item->set_quantity( 1 );
        $item->set_subtotal( $final_price );
        $item->set_total( $final_price );
        $item->set_subtotal_tax( 0 );
        $item->set_total_tax( 0 );

        if ( $discount > 0 ) {
            $item->add_meta_data( '_jm_upsell_discount', $discount . '%', true );
        }
        $item->add_meta_data( '_jm_upsell_item', 'yes', true );
        $item->add_meta_data( '_jm_upsell_type', $step->step_type, true );

        $order->add_item( $item );
        $order->calculate_totals();

        // Add order note.
        $type_label = $step->step_type === 'downsell' ? __( 'Downsell', 'jejeemech-upsell' ) : __( 'Upsell', 'jejeemech-upsell' );
        $order->add_order_note(
            sprintf(
                __( '%1$s accepted: %2$s added to order (%3$s)', 'jejeemech-upsell' ),
                $type_label,
                $product->get_name(),
                wc_price( $final_price )
            )
        );
        $order->save();

        // Find next step: after accept, skip to next upsell.
        $next_step_data = $this->get_next_step_data( $order, $step, $funnel_id, 'accept' );

        wp_send_json_success( array(
            'message'   => __( 'Product added to your order!', 'jejeemech-upsell' ),
            'next_step' => $next_step_data,
        ) );
    }

    /**
     * Decline upsell/downsell offer.
     */
    public function decline_offer() {
        check_ajax_referer( 'jm_upsell_offer', 'nonce' );

        $order_id  = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
        $order_key = isset( $_POST['order_key'] ) ? sanitize_text_field( wp_unslash( $_POST['order_key'] ) ) : '';
        $step_id   = isset( $_POST['step_id'] ) ? absint( $_POST['step_id'] ) : 0;
        $funnel_id = isset( $_POST['funnel_id'] ) ? absint( $_POST['funnel_id'] ) : 0;

        $order = wc_get_order( $order_id );
        if ( ! $order || $order->get_order_key() !== $order_key ) {
            wp_send_json_error( array( 'message' => __( 'Invalid order.', 'jejeemech-upsell' ) ) );
            return;
        }

        $step = JM_Upsell_Funnel::get_step( $step_id );
        if ( ! $step ) {
            wp_send_json_error( array( 'message' => __( 'Invalid step.', 'jejeemech-upsell' ) ) );
            return;
        }

        // Add order note.
        $type_label  = $step->step_type === 'downsell' ? __( 'Downsell', 'jejeemech-upsell' ) : __( 'Upsell', 'jejeemech-upsell' );
        $decline_vid = isset( $step->variation_id ) ? intval( $step->variation_id ) : 0;
        $product     = $decline_vid > 0 ? wc_get_product( $decline_vid ) : wc_get_product( $step->product_id );
        $order->add_order_note(
            sprintf(
                __( '%1$s declined: %2$s', 'jejeemech-upsell' ),
                $type_label,
                $product ? $product->get_name() : __( 'Unknown product', 'jejeemech-upsell' )
            )
        );
        $order->save();

        // Find next step: after decline upsell → show downsell; after decline downsell → next upsell.
        $next_step_data = $this->get_next_step_data( $order, $step, $funnel_id, 'decline' );

        wp_send_json_success( array(
            'next_step' => $next_step_data,
        ) );
    }

    /**
     * Determine the next step and return its data for the popup, or null if funnel is done.
     */
    private function get_next_step_data( $order, $current_step, $funnel_id, $action ) {
        $next_step = null;

        if ( $action === 'accept' ) {
            // After accepting: skip to next upsell step (no downsell for accepted upsell).
            $next_step = JM_Upsell_Funnel::get_next_upsell_step( $funnel_id, $current_step->step_order );
        } elseif ( $action === 'decline' ) {
            if ( $current_step->step_type === 'upsell' ) {
                // Declined upsell: check for downsell.
                $downsell = JM_Upsell_Funnel::get_downsell_for_step( $current_step->id );
                if ( $downsell ) {
                    $next_step = $downsell;
                } else {
                    $next_step = JM_Upsell_Funnel::get_next_upsell_step( $funnel_id, $current_step->step_order );
                }
            } else {
                // Declined downsell: go to next upsell.
                $next_step = JM_Upsell_Funnel::get_next_upsell_step( $funnel_id, $current_step->step_order );
            }
        }

        if ( $next_step ) {
            return JM_Upsell_Funnel::build_step_data( $next_step, $order );
        }

        // Funnel complete — mark as processed.
        $order->update_meta_data( '_jm_upsell_processed', 'yes' );
        $order->save();

        return null;
    }
}
