<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles the funnel logic: intercepts checkout and shows upsell popup on Place Order click.
 */
class JM_Upsell_Funnel {

    /** @var object|null Funnel matching current checkout cart. */
    private $checkout_funnel = null;

    /** @var object|null First step of checkout funnel. */
    private $checkout_step = null;

    public function __construct() {
        // Run detection inside wp_enqueue_scripts (priority 99) so WooCommerce cart
        // is fully loaded from session before we access it.
        add_action( 'wp_enqueue_scripts', array( $this, 'maybe_init_checkout_popup' ), 99 );

        // Apply discounted price for upsell items added to cart.
        add_action( 'woocommerce_before_calculate_totals', array( $this, 'apply_upsell_cart_price' ), 99 );
    }

    /**
     * On the checkout page, detect a matching funnel then enqueue assets + schedule HTML.
     * Hooked to wp_enqueue_scripts so the WooCommerce cart session is available.
     */
    public function maybe_init_checkout_popup() {
        if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_order_received_page() ) {
            return;
        }

        if ( ! WC()->cart || WC()->cart->is_empty() ) {
            return;
        }

        $cart_product_ids = array();
        foreach ( WC()->cart->get_cart() as $cart_item ) {
            $cart_product_ids[] = absint( $cart_item['product_id'] );
        }

        $funnel = $this->find_funnel_for_products( $cart_product_ids );
        if ( ! $funnel ) {
            return;
        }

        $first_step = $this->get_first_step( $funnel->id );
        if ( ! $first_step ) {
            return;
        }

        $this->checkout_funnel = $funnel;
        $this->checkout_step   = $first_step;

        // We are already inside wp_enqueue_scripts — enqueue directly.
        wp_enqueue_style(
            'jm-upsell-popup',
            JM_UPSELL_URL . 'assets/css/offer.css',
            array(),
            JM_UPSELL_VERSION
        );

        wp_enqueue_script(
            'jm-upsell-popup',
            JM_UPSELL_URL . 'assets/js/offer.js',
            array( 'jquery' ),
            JM_UPSELL_VERSION,
            true
        );

        wp_localize_script( 'jm-upsell-popup', 'jmUpsellOffer', array(
            'mode'       => 'checkout',
            'ajax_url'   => admin_url( 'admin-ajax.php' ),
            'nonce'      => wp_create_nonce( 'jm_upsell_checkout' ),
            'step_id'    => intval( $this->checkout_step->id ),
            'step_type'  => $this->checkout_step->step_type,
            'funnel_id'  => intval( $this->checkout_funnel->id ),
            'i18n'       => array(
                'adding'   => __( 'Adding to your order...', 'jejeemech-upsell' ),
                'added'    => __( 'Added! Placing your order...', 'jejeemech-upsell' ),
                'error'    => __( 'Something went wrong. Please try again.', 'jejeemech-upsell' ),
                'placing'  => __( 'Placing your order...', 'jejeemech-upsell' ),
                'checking' => __( 'Loading offer...', 'jejeemech-upsell' ),
            ),
        ) );

        // Render the popup HTML before footer scripts (priority 5).
        add_action( 'wp_footer', array( $this, 'render_checkout_popup_html' ), 5 );
    }

    /**
     * Render the popup HTML in the footer — hidden via CSS class, NOT inline style,
     * so that jQuery display:flex is preserved when the popup is shown.
     */
    public function render_checkout_popup_html() {
        if ( ! $this->checkout_step ) {
            return;
        }
        try {
            $step_data = self::build_step_data( $this->checkout_step );
            if ( $step_data ) {
                $this->render_popup_html( $step_data, true );
            }
        } catch ( \Throwable $e ) {
            // Prevent popup errors from crashing the checkout page.
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'JM Upsell popup error: ' . $e->getMessage() );
            }
        }
    }

    /**
     * Apply custom discounted price for upsell items in the cart.
     */
    public function apply_upsell_cart_price( $cart ) {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
            return;
        }
        foreach ( $cart->get_cart() as $cart_item ) {
            if ( isset( $cart_item['_jm_upsell_price'] ) ) {
                $cart_item['data']->set_price( floatval( $cart_item['_jm_upsell_price'] ) );
            }
        }
    }

    /**
     * Find a funnel that matches a list of product IDs.
     */
    public function find_funnel_for_products( array $product_ids ) {
        global $wpdb;

        if ( empty( $product_ids ) ) {
            return null;
        }

        $table        = $wpdb->prefix . 'jm_upsell_funnels';
        $placeholders = implode( ',', array_fill( 0, count( $product_ids ), '%d' ) );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $query = $wpdb->prepare(
            "SELECT * FROM {$table} WHERE trigger_product_id IN ({$placeholders}) AND status = 'active' ORDER BY id ASC LIMIT 1",
            ...$product_ids
        );

        return $wpdb->get_row( $query );
    }

    /**
     * Get the first upsell step in a funnel.
     */
    public function get_first_step( $funnel_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'jm_upsell_steps';

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE funnel_id = %d AND step_type = 'upsell' ORDER BY step_order ASC LIMIT 1",
                $funnel_id
            )
        );
    }

    /**
     * Get a specific step.
     */
    public static function get_step( $step_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'jm_upsell_steps';

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE id = %d",
                $step_id
            )
        );
    }

    /**
     * Get the next step after accepting an upsell.
     * Returns the next upsell step, or null if done.
     */
    public static function get_next_upsell_step( $funnel_id, $current_step_order ) {
        global $wpdb;
        $table = $wpdb->prefix . 'jm_upsell_steps';

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE funnel_id = %d AND step_type = 'upsell' AND step_order > %d ORDER BY step_order ASC LIMIT 1",
                $funnel_id,
                $current_step_order
            )
        );
    }

    /**
     * Get the downsell for a specific upsell step.
     */
    public static function get_downsell_for_step( $upsell_step_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'jm_upsell_steps';

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE parent_step_id = %d AND step_type = 'downsell' LIMIT 1",
                $upsell_step_id
            )
        );
    }

    /**
     * Build step data array for the popup.
     */
    public static function build_step_data( $step, $order = null ) {
        // Use the specific variation when one is saved; fall back to the parent/simple product.
        $variation_id = isset( $step->variation_id ) ? intval( $step->variation_id ) : 0;
        $product      = wc_get_product( $variation_id > 0 ? $variation_id : $step->product_id );
        if ( ! $product ) {
            return null;
        }

        $is_downsell = $step->step_type === 'downsell';
        $discount    = floatval( $step->discount );
        $price       = floatval( $product->get_price() );
        $final_price = $discount > 0 ? $price - ( $price * $discount / 100 ) : $price;

        $image_url = '';
        $image_id  = $product->get_image_id();
        if ( $image_id ) {
            $image_src = wp_get_attachment_image_src( $image_id, 'medium' );
            $image_url = $image_src ? $image_src[0] : wc_placeholder_img_src( 'medium' );
        } else {
            $image_url = wc_placeholder_img_src( 'medium' );
        }

        if ( $is_downsell ) {
            $headline    = __( 'Wait! Here\'s a Better Deal', 'jejeemech-upsell' );
            $subheadline = __( 'We have an even better offer for you!', 'jejeemech-upsell' );
        } else {
            if ( $discount > 0 ) {
                $headline = sprintf(
                    /* translators: %s: discount percentage */
                    __( 'Wait, You Won a %s%% Discount Offer on This Product', 'jejeemech-upsell' ),
                    intval( $discount )
                );
            } else {
                $headline = __( 'Special Offer Just For You!', 'jejeemech-upsell' );
            }
            $subheadline = __( 'Add this to your order with one click', 'jejeemech-upsell' );
        }

        $short_desc = $product->get_short_description();

        return array(
            'step_id'           => $step->id,
            'funnel_id'         => intval( $step->funnel_id ),
            'product_name'      => $product->get_name(),
            'product_short_desc'=> wp_kses_post( $short_desc ),
            'image_url'         => $image_url,
            'discount'          => $discount,
            'price_html'        => wc_price( $price ),
            'final_price_html'  => wc_price( $final_price ),
            'save_html'         => wc_price( $price - $final_price ),
            'is_downsell'       => $is_downsell,
            'headline'          => $headline,
            'subheadline'       => $subheadline,
            'badge_text'        => __( 'LIMITED TIME OFFER', 'jejeemech-upsell' ),
        );
    }

    /**
     * Render the popup overlay HTML on the thank you page.
     */
    private function render_popup_html( $data, $hidden = false ) {
        ?>
        <!-- JejeeMech Upsell Popup Overlay -->
        <div id="jm-popup-overlay" class="jm-popup-overlay <?php echo $data['is_downsell'] ? 'jm-downsell' : 'jm-upsell'; ?><?php echo $hidden ? ' jm-popup-checkout' : ''; ?>">
            <div class="jm-popup-card" id="jm-popup-card">

                <!-- Close button -->
                <button type="button" id="jm-close-popup" class="jm-close-btn" aria-label="<?php esc_attr_e( 'Close', 'jejeemech-upsell' ); ?>">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>

                <!-- Badge pill -->
                <div class="jm-popup-badge-wrap">
                    <span class="jm-popup-badge-pill" id="jm-popup-badge">
                        <svg class="jm-fire-icon" width="13" height="13" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2c0 0-5 4.5-5 9.5a5 5 0 0 0 10 0C17 6.5 12 2 12 2z"/></svg>
                        <span id="jm-popup-badge-text"><?php echo esc_html( $data['badge_text'] ); ?></span>
                    </span>
                </div>

                <!-- Headline -->
                <div class="jm-popup-header">
                    <h2 class="jm-popup-headline" id="jm-popup-headline"><?php echo esc_html( $data['headline'] ); ?></h2>
                </div>

                <!-- Full-width product image -->
                <div class="jm-popup-image" id="jm-popup-image-wrap">
                    <img src="<?php echo esc_url( $data['image_url'] ); ?>"
                         alt="<?php echo esc_attr( $data['product_name'] ); ?>"
                         id="jm-popup-img">
                </div>

                <!-- Product info -->
                <div class="jm-popup-body">

                    <h3 class="jm-popup-title" id="jm-popup-title"><?php echo esc_html( $data['product_name'] ); ?></h3>

                    <!-- Product description -->
                    <div class="jm-popup-desc" id="jm-popup-desc">
                        <?php if ( ! empty( $data['product_short_desc'] ) ) : ?>
                            <?php echo wp_kses_post( $data['product_short_desc'] ); ?>
                        <?php endif; ?>
                    </div>

                    <!-- Divider -->
                    <div class="jm-popup-divider"></div>

                    <!-- Price -->
                    <div class="jm-popup-price-block" id="jm-popup-price">
                        <?php if ( $data['discount'] > 0 ) : ?>
                            <div class="jm-price-row">
                                <span class="jm-original-price"><?php echo wp_kses_post( $data['price_html'] ); ?></span>
                                <span class="jm-final-price"><?php echo wp_kses_post( $data['final_price_html'] ); ?></span>
                            </div>
                            <div class="jm-save-line">
                                <?php
                                printf(
                                    /* translators: %s: money saved */
                                    esc_html__( 'Save %s When You Add it Now', 'jejeemech-upsell' ),
                                    wp_kses_post( $data['save_html'] )
                                );
                                ?>
                            </div>
                        <?php else : ?>
                            <div class="jm-price-row">
                                <span class="jm-final-price"><?php echo wp_kses_post( $data['final_price_html'] ); ?></span>
                            </div>
                        <?php endif; ?>

                        <!-- Urgency line -->
                        <div class="jm-urgency-line">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                            <?php esc_html_e( 'This offer expires at checkout', 'jejeemech-upsell' ); ?>
                        </div>
                    </div>

                    <!-- CTA accept button -->
                    <button type="button" id="jm-accept-offer" class="jm-btn jm-btn-accept"
                        data-step-id="<?php echo esc_attr( $data['step_id'] ); ?>"
                        data-funnel-id="<?php echo esc_attr( $data['funnel_id'] ); ?>">
                        <?php esc_html_e( 'Add To My Order', 'jejeemech-upsell' ); ?>
                    </button>

                    <!-- Decline text link -->
                    <button type="button" id="jm-decline-offer" class="jm-btn-decline"
                        data-step-id="<?php echo esc_attr( $data['step_id'] ); ?>"
                        data-funnel-id="<?php echo esc_attr( $data['funnel_id'] ); ?>">
                        <?php esc_html_e( "No thanks, I don't want this deal", 'jejeemech-upsell' ); ?>
                    </button>

                    <!-- Trust bar -->
                    <div class="jm-popup-trust">
                        <div class="jm-trust-item">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="3" width="15" height="13" rx="2"/><path d="M16 8h5l2 3v3h-7V8z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
                            <?php esc_html_e( 'Same delivery', 'jejeemech-upsell' ); ?>
                        </div>
                        <div class="jm-trust-item">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                            <?php esc_html_e( 'No extra shipping', 'jejeemech-upsell' ); ?>
                        </div>
                        <div class="jm-trust-item">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                            <?php esc_html_e( 'Secure checkout', 'jejeemech-upsell' ); ?>
                        </div>
                    </div>

                </div><!-- /.jm-popup-body -->

                <!-- Loading spinner overlay -->
                <div id="jm-popup-loading" class="jm-popup-loading">
                    <div class="jm-spinner"></div>
                    <p id="jm-loading-text"><?php esc_html_e( 'Processing...', 'jejeemech-upsell' ); ?></p>
                </div>

            </div><!-- /.jm-popup-card -->

        </div>
        <?php
    }
}
