<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class JM_Upsell_Admin {

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'wp_ajax_jm_upsell_save_funnel', array( $this, 'ajax_save_funnel' ) );
        add_action( 'wp_ajax_jm_upsell_delete_funnel', array( $this, 'ajax_delete_funnel' ) );
        add_action( 'wp_ajax_jm_upsell_toggle_funnel', array( $this, 'ajax_toggle_funnel' ) );
        add_action( 'wp_ajax_jm_upsell_search_products', array( $this, 'ajax_search_products' ) );
    }

    /**
     * Add admin menu.
     */
    public function add_menu() {
        add_menu_page(
            __( 'Upsell Funnels', 'jejeemech-upsell' ),
            __( 'Upsell Funnels', 'jejeemech-upsell' ),
            'manage_woocommerce',
            'jm-upsell-funnels',
            array( $this, 'render_funnels_page' ),
            'dashicons-chart-line',
            56
        );
    }

    /**
     * Enqueue admin assets.
     */
    public function enqueue_assets( $hook ) {
        if ( strpos( $hook, 'jm-upsell-funnels' ) === false ) {
            return;
        }

        wp_enqueue_style(
            'jm-upsell-admin',
            JM_UPSELL_URL . 'assets/css/admin.css',
            array(),
            JM_UPSELL_VERSION
        );

        wp_enqueue_script(
            'jm-upsell-admin',
            JM_UPSELL_URL . 'assets/js/admin.js',
            array( 'jquery' ),
            JM_UPSELL_VERSION,
            true
        );

        wp_localize_script( 'jm-upsell-admin', 'jmUpsellAdmin', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'jm_upsell_admin' ),
            'i18n'     => array(
                'confirm_delete'  => __( 'Are you sure you want to delete this funnel?', 'jejeemech-upsell' ),
                'saving'          => __( 'Saving...', 'jejeemech-upsell' ),
                'saved'           => __( 'Funnel saved successfully!', 'jejeemech-upsell' ),
                'error'           => __( 'An error occurred. Please try again.', 'jejeemech-upsell' ),
                'search_product'  => __( 'Search for a product...', 'jejeemech-upsell' ),
            ),
        ) );
    }

    /**
     * Render the funnels admin page.
     */
    public function render_funnels_page() {
        $action = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( $_GET['action'] ) ) : 'list';
        $funnel_id = isset( $_GET['funnel_id'] ) ? absint( $_GET['funnel_id'] ) : 0;

        echo '<div class="wrap jm-upsell-wrap">';

        if ( $action === 'edit' || $action === 'new' ) {
            $this->render_funnel_form( $funnel_id );
        } else {
            $this->render_funnel_list();
        }

        echo '</div>';
    }

    /**
     * Avatar colour palette — cycles by funnel id.
     */
    private function get_avatar_color( $id ) {
        $palette = array( '#6c5ce7', '#00b894', '#e17055', '#0984e3', '#fd79a8', '#6ab04c', '#e55039', '#8e44ad' );
        return $palette[ absint( $id ) % count( $palette ) ];
    }

    /**
     * Render funnel list — premium card dashboard.
     */
    private function render_funnel_list() {
        global $wpdb;
        $funnels_table = $wpdb->prefix . 'jm_upsell_funnels';
        $steps_table   = $wpdb->prefix . 'jm_upsell_steps';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $funnels = $wpdb->get_results( "SELECT * FROM {$funnels_table} ORDER BY created_at DESC" );

        if ( ! is_array( $funnels ) ) {
            $funnels = array();
        }

        // Stats.
        $total_funnels  = count( $funnels );
        $active_funnels = 0;
        $total_steps    = 0;
        $step_counts    = array();
        foreach ( $funnels as $f ) {
            if ( $f->status === 'active' ) {
                $active_funnels++;
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $cnt = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$steps_table} WHERE funnel_id = %d", $f->id ) );
            $step_counts[ $f->id ] = $cnt;
            $total_steps += $cnt;
        }
        ?>

        <!-- ── Dashboard Header ─────────────────────────────── -->
        <div class="jm-dash-header">
            <div class="jm-dash-header-left">
                <div class="jm-dash-logo">
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M13 2L3 14H12L11 22L21 10H12L13 2Z" fill="currentColor"/>
                    </svg>
                </div>
                <div>
                    <h1><?php esc_html_e( 'Upsell Funnels', 'jejeemech-upsell' ); ?></h1>
                    <p><?php esc_html_e( 'Boost average order value with post-checkout funnels', 'jejeemech-upsell' ); ?></p>
                </div>
            </div>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=jm-upsell-funnels&action=new' ) ); ?>" class="jm-btn-create">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/></svg>
                <?php esc_html_e( 'Create New Funnel', 'jejeemech-upsell' ); ?>
            </a>
        </div>

        <!-- ── Stats Bar ────────────────────────────────────── -->
        <div class="jm-stats-bar">
            <div class="jm-stat-item">
                <div class="jm-stat-icon jm-stat-icon--purple">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M3 6h18M3 12h18M3 18h18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                </div>
                <div class="jm-stat-body">
                    <span class="jm-stat-value"><?php echo esc_html( $total_funnels ); ?></span>
                    <span class="jm-stat-label"><?php esc_html_e( 'Total Funnels', 'jejeemech-upsell' ); ?></span>
                </div>
            </div>
            <div class="jm-stat-item">
                <div class="jm-stat-icon jm-stat-icon--green">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M5 13l4 4L19 7" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </div>
                <div class="jm-stat-body">
                    <span class="jm-stat-value"><?php echo esc_html( $active_funnels ); ?></span>
                    <span class="jm-stat-label"><?php esc_html_e( 'Active', 'jejeemech-upsell' ); ?></span>
                </div>
            </div>
            <div class="jm-stat-item">
                <div class="jm-stat-icon jm-stat-icon--orange">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </div>
                <div class="jm-stat-body">
                    <span class="jm-stat-value"><?php echo esc_html( $total_steps ); ?></span>
                    <span class="jm-stat-label"><?php esc_html_e( 'Total Steps', 'jejeemech-upsell' ); ?></span>
                </div>
            </div>
            <div class="jm-stat-item">
                <div class="jm-stat-icon jm-stat-icon--blue">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2"/><path d="M12 6v6l4 2" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                </div>
                <div class="jm-stat-body">
                    <span class="jm-stat-value"><?php echo esc_html( $total_funnels - $active_funnels ); ?></span>
                    <span class="jm-stat-label"><?php esc_html_e( 'Inactive', 'jejeemech-upsell' ); ?></span>
                </div>
            </div>
        </div>

        <!-- ── Notice ───────────────────────────────────────── -->
        <div id="jm-upsell-notice" class="notice" style="display:none;"><p></p></div>

        <?php if ( empty( $funnels ) ) : ?>

        <!-- ── Empty State ──────────────────────────────────── -->
        <div class="jm-empty-state">
            <div class="jm-empty-icon">
                <svg width="56" height="56" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M13 2L3 14H12L11 22L21 10H12L13 2Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>
                </svg>
            </div>
            <h3><?php esc_html_e( 'No funnels yet', 'jejeemech-upsell' ); ?></h3>
            <p><?php esc_html_e( 'Create your first upsell funnel and start increasing your revenue today.', 'jejeemech-upsell' ); ?></p>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=jm-upsell-funnels&action=new' ) ); ?>" class="jm-btn-create">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/></svg>
                <?php esc_html_e( 'Create Your First Funnel', 'jejeemech-upsell' ); ?>
            </a>
        </div>

        <?php else : ?>

        <!-- ── Funnel Cards Grid ─────────────────────────────── -->
        <div class="jm-funnels-grid">
            <?php foreach ( $funnels as $funnel ) : ?>
                <?php
                $product      = wc_get_product( $funnel->trigger_product_id );
                $product_name = $product ? $product->get_name() : __( 'Product not found', 'jejeemech-upsell' );
                $step_count   = $step_counts[ $funnel->id ] ?? 0;
                $is_active    = $funnel->status === 'active';
                $avatar_color = $this->get_avatar_color( $funnel->id );
                $avatar_letter = mb_strtoupper( mb_substr( $funnel->name, 0, 1 ) );
                $edit_url     = esc_url( admin_url( 'admin.php?page=jm-upsell-funnels&action=edit&funnel_id=' . $funnel->id ) );
                ?>
                <div class="jm-funnel-card <?php echo $is_active ? 'is-active' : 'is-inactive'; ?>" data-funnel-id="<?php echo esc_attr( $funnel->id ); ?>">

                    <!-- Active glow bar -->
                    <div class="jm-funnel-card-bar" style="background: <?php echo esc_attr( $avatar_color ); ?>;"></div>

                    <!-- Card Head -->
                    <div class="jm-funnel-card-head">
                        <div class="jm-funnel-avatar" style="background: <?php echo esc_attr( $avatar_color ); ?>;">
                            <?php echo esc_html( $avatar_letter ); ?>
                        </div>
                        <div class="jm-funnel-info">
                            <h3 class="jm-funnel-name">
                                <a href="<?php echo $edit_url; ?>"><?php echo esc_html( $funnel->name ); ?></a>
                            </h3>
                            <div class="jm-funnel-trigger">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none"><path d="M20 7H4a2 2 0 00-2 2v6a2 2 0 002 2h16a2 2 0 002-2V9a2 2 0 00-2-2z" stroke="currentColor" stroke-width="2"/><path d="M16 21V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v16" stroke="currentColor" stroke-width="2"/></svg>
                                <?php echo esc_html( $product_name ); ?>
                            </div>
                        </div>
                        <div class="jm-funnel-toggle-wrap">
                            <label class="jm-toggle" title="<?php echo $is_active ? esc_attr__( 'Click to deactivate', 'jejeemech-upsell' ) : esc_attr__( 'Click to activate', 'jejeemech-upsell' ); ?>">
                                <input type="checkbox" class="jm-toggle-funnel" data-id="<?php echo esc_attr( $funnel->id ); ?>" <?php checked( $is_active ); ?>>
                                <span class="jm-toggle-slider"></span>
                            </label>
                        </div>
                    </div>

                    <!-- Card Body: badges -->
                    <div class="jm-funnel-card-body">
                        <div class="jm-funnel-badge">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none"><path d="M2 17l10 5 10-5M2 12l10 5 10-5M12 2L2 7l10 5 10-5z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/></svg>
                            <?php
                            printf(
                                /* translators: %d: number of steps */
                                esc_html( _n( '%d Step', '%d Steps', $step_count, 'jejeemech-upsell' ) ),
                                esc_html( $step_count )
                            );
                            ?>
                        </div>
                        <div class="jm-funnel-badge jm-funnel-badge--status <?php echo $is_active ? 'jm-badge-active' : 'jm-badge-inactive'; ?>">
                            <span class="jm-badge-dot"></span>
                            <?php echo $is_active ? esc_html__( 'Active', 'jejeemech-upsell' ) : esc_html__( 'Inactive', 'jejeemech-upsell' ); ?>
                        </div>
                        <div class="jm-funnel-badge jm-funnel-badge--date">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none"><rect x="3" y="4" width="18" height="18" rx="2" stroke="currentColor" stroke-width="2"/><path d="M16 2v4M8 2v4M3 10h18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                            <?php echo esc_html( date_i18n( 'M j, Y', strtotime( $funnel->created_at ) ) ); ?>
                        </div>
                    </div>

                    <!-- Card Footer -->
                    <div class="jm-funnel-card-footer">
                        <a href="<?php echo $edit_url; ?>" class="jm-fc-btn jm-fc-btn--edit">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            <?php esc_html_e( 'Edit Funnel', 'jejeemech-upsell' ); ?>
                        </a>
                        <button type="button" class="jm-fc-btn jm-fc-btn--delete jm-delete-funnel" data-id="<?php echo esc_attr( $funnel->id ); ?>">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none"><polyline points="3 6 5 6 21 6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><path d="M10 11v6M14 11v6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><path d="M9 6V4a1 1 0 011-1h4a1 1 0 011 1v2" stroke="currentColor" stroke-width="2"/></svg>
                            <?php esc_html_e( 'Delete', 'jejeemech-upsell' ); ?>
                        </button>
                    </div>

                </div>
            <?php endforeach; ?>
        </div>

        <?php endif; ?>
        <?php
    }

    /**
     * Render funnel creation/edit form.
     */
    private function render_funnel_form( $funnel_id = 0 ) {
        global $wpdb;

        $funnel = null;
        $steps  = array();

        if ( $funnel_id > 0 ) {
            $funnels_table = $wpdb->prefix . 'jm_upsell_funnels';
            $steps_table   = $wpdb->prefix . 'jm_upsell_steps';

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $funnel = $wpdb->get_row(
                $wpdb->prepare( "SELECT * FROM {$funnels_table} WHERE id = %d", $funnel_id )
            );

            if ( $funnel ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $steps = $wpdb->get_results(
                    $wpdb->prepare( "SELECT * FROM {$steps_table} WHERE funnel_id = %d ORDER BY step_order ASC", $funnel_id )
                );
            }
        }

        $trigger_product = null;
        if ( $funnel && $funnel->trigger_product_id ) {
            $trigger_product = wc_get_product( $funnel->trigger_product_id );
        }

        ?>
        <h1>
            <?php echo $funnel_id > 0 ? esc_html__( 'Edit Funnel', 'jejeemech-upsell' ) : esc_html__( 'Create New Funnel', 'jejeemech-upsell' ); ?>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=jm-upsell-funnels' ) ); ?>" class="page-title-action"><?php esc_html_e( '← Back to Funnels', 'jejeemech-upsell' ); ?></a>
        </h1>
        <hr class="wp-header-end">

        <div id="jm-upsell-notice" class="notice" style="display:none;"><p></p></div>

        <form id="jm-funnel-form" class="jm-funnel-form">
            <input type="hidden" name="funnel_id" value="<?php echo esc_attr( $funnel_id ); ?>">

            <div class="jm-card">
                <h2><?php esc_html_e( 'Funnel Settings', 'jejeemech-upsell' ); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><label for="funnel_name"><?php esc_html_e( 'Funnel Name', 'jejeemech-upsell' ); ?></label></th>
                        <td>
                            <input type="text" id="funnel_name" name="funnel_name" class="regular-text"
                                value="<?php echo $funnel ? esc_attr( $funnel->name ) : ''; ?>"
                                placeholder="<?php esc_attr_e( 'e.g. Hair Removal Upsell', 'jejeemech-upsell' ); ?>" required>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="trigger_product"><?php esc_html_e( 'Trigger Product', 'jejeemech-upsell' ); ?></label></th>
                        <td>
                            <div class="jm-product-search">
                                <input type="text" id="trigger_product_search" class="regular-text jm-product-search-input"
                                    data-context="trigger"
                                    placeholder="<?php esc_attr_e( 'Search for a product...', 'jejeemech-upsell' ); ?>"
                                    value="<?php echo $trigger_product ? esc_attr( $trigger_product->get_name() ) : ''; ?>">
                                <input type="hidden" id="trigger_product_id" name="trigger_product_id" class="jm-product-id-hidden"
                                    value="<?php echo $funnel ? esc_attr( $funnel->trigger_product_id ) : ''; ?>">
                                <div class="jm-search-results" id="trigger_product_results"></div>
                            </div>
                            <p class="description"><?php esc_html_e( 'The product that triggers this funnel when purchased.', 'jejeemech-upsell' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label><?php esc_html_e( 'Status', 'jejeemech-upsell' ); ?></label></th>
                        <td>
                            <select name="funnel_status">
                                <option value="active" <?php echo ( $funnel && $funnel->status === 'active' ) || ! $funnel ? 'selected' : ''; ?>><?php esc_html_e( 'Active', 'jejeemech-upsell' ); ?></option>
                                <option value="inactive" <?php echo $funnel && $funnel->status === 'inactive' ? 'selected' : ''; ?>><?php esc_html_e( 'Inactive', 'jejeemech-upsell' ); ?></option>
                            </select>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="jm-card">
                <h2><?php esc_html_e( 'Funnel Steps', 'jejeemech-upsell' ); ?></h2>
                <p class="description"><?php esc_html_e( 'Add up to 3 upsell steps. Each upsell can have 1 optional downsell.', 'jejeemech-upsell' ); ?></p>

                <div id="jm-steps-container">
                    <?php
                    if ( ! empty( $steps ) ) {
                        $step_num = 0;
                        $i = 0;
                        while ( $i < count( $steps ) ) {
                            $step = $steps[ $i ];
                            if ( $step->step_type === 'upsell' ) {
                                $step_num++;
                                $upsell_vid     = isset( $step->variation_id ) ? intval( $step->variation_id ) : 0;
                                $upsell_product = wc_get_product( $upsell_vid > 0 ? $upsell_vid : $step->product_id );

                                // Check for downsell
                                $downsell = null;
                                $downsell_product = null;
                                if ( isset( $steps[ $i + 1 ] ) && $steps[ $i + 1 ]->step_type === 'downsell' && (int) $steps[ $i + 1 ]->parent_step_id === (int) $step->id ) {
                                    $downsell     = $steps[ $i + 1 ];
                                    $downsell_vid = isset( $downsell->variation_id ) ? intval( $downsell->variation_id ) : 0;
                                    $downsell_product = wc_get_product( $downsell_vid > 0 ? $downsell_vid : $downsell->product_id );
                                    $i++;
                                }

                                $this->render_step_block( $step_num, $step, $upsell_product, $downsell, $downsell_product );
                            }
                            $i++;
                        }
                    }
                    ?>
                </div>

                <button type="button" id="jm-add-step" class="button button-secondary">
                    <?php esc_html_e( '+ Add Upsell Step', 'jejeemech-upsell' ); ?>
                </button>
            </div>

            <p class="submit">
                <button type="submit" class="button button-primary button-large" id="jm-save-funnel">
                    <?php esc_html_e( 'Save Funnel', 'jejeemech-upsell' ); ?>
                </button>
            </p>
        </form>

        <!-- Step template for JS -->
        <script type="text/html" id="jm-step-template">
            <?php $this->render_step_block( '{{step_num}}', null, null, null, null ); ?>
        </script>
        <?php
    }

    /**
     * Render a single step block.
     */
    private function render_step_block( $step_num, $upsell_step = null, $upsell_product = null, $downsell_step = null, $downsell_product = null ) {
        $prefix = 'steps[' . $step_num . ']';
        ?>
        <div class="jm-step-block" data-step="<?php echo esc_attr( $step_num ); ?>">
            <div class="jm-step-header">
                <h3><?php
                    /* translators: %s: step number */
                    printf( esc_html__( 'Step %s – Upsell', 'jejeemech-upsell' ), esc_html( $step_num ) );
                ?></h3>
                <button type="button" class="button button-link-delete jm-remove-step"><?php esc_html_e( 'Remove', 'jejeemech-upsell' ); ?></button>
            </div>

            <input type="hidden" name="<?php echo esc_attr( $prefix ); ?>[upsell_step_id]"
                value="<?php echo $upsell_step ? esc_attr( $upsell_step->id ) : ''; ?>">

            <table class="form-table">
                <tr>
                    <th><label><?php esc_html_e( 'Upsell Product', 'jejeemech-upsell' ); ?></label></th>
                    <td>
                        <div class="jm-product-search">
                            <input type="text" class="regular-text jm-product-search-input"
                                data-target="<?php echo esc_attr( $prefix ); ?>[upsell_product_id]"
                                data-context="step"
                                placeholder="<?php esc_attr_e( 'Search for a product...', 'jejeemech-upsell' ); ?>"
                                value="<?php echo $upsell_product ? esc_attr( $upsell_product->get_name() ) : ''; ?>">
                            <input type="hidden" name="<?php echo esc_attr( $prefix ); ?>[upsell_product_id]" class="jm-product-id-hidden"
                                value="<?php echo $upsell_step ? esc_attr( $upsell_step->product_id ) : ''; ?>">
                            <input type="hidden" name="<?php echo esc_attr( $prefix ); ?>[upsell_variation_id]" class="jm-variation-id-hidden"
                                value="<?php echo $upsell_step ? esc_attr( isset( $upsell_step->variation_id ) ? $upsell_step->variation_id : 0 ) : 0; ?>">
                            <div class="jm-search-results"></div>
                        </div>
                    </td>
                </tr>
                <tr>
                    <th><label><?php esc_html_e( 'Discount (%)', 'jejeemech-upsell' ); ?></label></th>
                    <td>
                        <input type="number" name="<?php echo esc_attr( $prefix ); ?>[upsell_discount]"
                            class="small-text" min="0" max="100" step="1"
                            value="<?php echo $upsell_step ? esc_attr( $upsell_step->discount ) : '0'; ?>">
                        <p class="description"><?php esc_html_e( 'Leave 0 for no discount.', 'jejeemech-upsell' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label><?php esc_html_e( 'Badge Text', 'jejeemech-upsell' ); ?></label></th>
                    <td>
                        <input type="text" name="<?php echo esc_attr( $prefix ); ?>[upsell_badge_text]"
                            class="regular-text"
                            value="<?php echo $upsell_step && ! empty( $upsell_step->badge_text ) ? esc_attr( $upsell_step->badge_text ) : ''; ?>"
                            placeholder="<?php esc_attr_e( 'e.g. LIMITED TIME OFFER', 'jejeemech-upsell' ); ?>">
                        <p class="description"><?php esc_html_e( 'Leave empty for default: LIMITED TIME OFFER', 'jejeemech-upsell' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label><?php esc_html_e( 'Headline', 'jejeemech-upsell' ); ?></label></th>
                    <td>
                        <input type="text" name="<?php echo esc_attr( $prefix ); ?>[upsell_headline]"
                            class="regular-text"
                            value="<?php echo $upsell_step && ! empty( $upsell_step->headline ) ? esc_attr( $upsell_step->headline ) : ''; ?>"
                            placeholder="<?php esc_attr_e( 'e.g. Wait, You Won a Special Offer!', 'jejeemech-upsell' ); ?>">
                        <p class="description"><?php esc_html_e( 'Leave empty for auto-generated headline based on discount.', 'jejeemech-upsell' ); ?></p>
                    </td>
                </tr>
            </table>

            <div class="jm-downsell-section">
                <h4>
                    <?php esc_html_e( 'Downsell (Optional)', 'jejeemech-upsell' ); ?>
                    <label class="jm-downsell-toggle">
                        <input type="checkbox" class="jm-enable-downsell" name="<?php echo esc_attr( $prefix ); ?>[has_downsell]"
                            <?php checked( ! empty( $downsell_step ) ); ?>>
                        <?php esc_html_e( 'Enable', 'jejeemech-upsell' ); ?>
                    </label>
                </h4>
                <div class="jm-downsell-fields" style="<?php echo empty( $downsell_step ) ? 'display:none;' : ''; ?>">
                    <input type="hidden" name="<?php echo esc_attr( $prefix ); ?>[downsell_step_id]"
                        value="<?php echo $downsell_step ? esc_attr( $downsell_step->id ) : ''; ?>">
                    <table class="form-table">
                        <tr>
                            <th><label><?php esc_html_e( 'Downsell Product', 'jejeemech-upsell' ); ?></label></th>
                            <td>
                                <div class="jm-product-search">
                                    <input type="text" class="regular-text jm-product-search-input"
                                        data-target="<?php echo esc_attr( $prefix ); ?>[downsell_product_id]"
                                        data-context="step"
                                        placeholder="<?php esc_attr_e( 'Search for a product...', 'jejeemech-upsell' ); ?>"
                                        value="<?php echo $downsell_product ? esc_attr( $downsell_product->get_name() ) : ''; ?>">
                                    <input type="hidden" name="<?php echo esc_attr( $prefix ); ?>[downsell_product_id]" class="jm-product-id-hidden"
                                        value="<?php echo $downsell_step ? esc_attr( $downsell_step->product_id ) : ''; ?>">
                                    <input type="hidden" name="<?php echo esc_attr( $prefix ); ?>[downsell_variation_id]" class="jm-variation-id-hidden"
                                        value="<?php echo $downsell_step ? esc_attr( isset( $downsell_step->variation_id ) ? $downsell_step->variation_id : 0 ) : 0; ?>">
                                    <div class="jm-search-results"></div>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th><label><?php esc_html_e( 'Downsell Discount (%)', 'jejeemech-upsell' ); ?></label></th>
                            <td>
                                <input type="number" name="<?php echo esc_attr( $prefix ); ?>[downsell_discount]"
                                    class="small-text" min="0" max="100" step="1"
                                    value="<?php echo $downsell_step ? esc_attr( $downsell_step->discount ) : '0'; ?>">
                            </td>
                        </tr>
                        <tr>
                            <th><label><?php esc_html_e( 'Downsell Badge Text', 'jejeemech-upsell' ); ?></label></th>
                            <td>
                                <input type="text" name="<?php echo esc_attr( $prefix ); ?>[downsell_badge_text]"
                                    class="regular-text"
                                    value="<?php echo $downsell_step && ! empty( $downsell_step->badge_text ) ? esc_attr( $downsell_step->badge_text ) : ''; ?>"
                                    placeholder="<?php esc_attr_e( 'e.g. LAST CHANCE', 'jejeemech-upsell' ); ?>">
                                <p class="description"><?php esc_html_e( 'Leave empty for default: LIMITED TIME OFFER', 'jejeemech-upsell' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><label><?php esc_html_e( 'Downsell Headline', 'jejeemech-upsell' ); ?></label></th>
                            <td>
                                <input type="text" name="<?php echo esc_attr( $prefix ); ?>[downsell_headline]"
                                    class="regular-text"
                                    value="<?php echo $downsell_step && ! empty( $downsell_step->headline ) ? esc_attr( $downsell_step->headline ) : ''; ?>"
                                    placeholder="<?php esc_attr_e( 'e.g. Wait! Here\'s a Better Deal', 'jejeemech-upsell' ); ?>">
                                <p class="description"><?php esc_html_e( 'Leave empty for default: Wait! Here\'s a Better Deal', 'jejeemech-upsell' ); ?></p>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * AJAX: Save funnel.
     */
    public function ajax_save_funnel() {
        check_ajax_referer( 'jm_upsell_admin', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'jejeemech-upsell' ) ) );
            return;
        }

        global $wpdb;
        $funnels_table = $wpdb->prefix . 'jm_upsell_funnels';
        $steps_table   = $wpdb->prefix . 'jm_upsell_steps';

        $funnel_id          = isset( $_POST['funnel_id'] ) ? absint( $_POST['funnel_id'] ) : 0;
        $name               = isset( $_POST['funnel_name'] ) ? sanitize_text_field( wp_unslash( $_POST['funnel_name'] ) ) : '';
        $trigger_product_id = isset( $_POST['trigger_product_id'] ) ? absint( $_POST['trigger_product_id'] ) : 0;
        $status             = isset( $_POST['funnel_status'] ) ? sanitize_text_field( wp_unslash( $_POST['funnel_status'] ) ) : 'active';
        $steps_data         = isset( $_POST['steps'] ) ? wp_unslash( $_POST['steps'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

        if ( empty( $name ) || empty( $trigger_product_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Funnel name and trigger product are required.', 'jejeemech-upsell' ) ) );
            return;
        }

        if ( ! in_array( $status, array( 'active', 'inactive' ), true ) ) {
            $status = 'active';
        }

        // Save funnel.
        $funnel_data = array(
            'name'               => $name,
            'trigger_product_id' => $trigger_product_id,
            'status'             => $status,
        );

        if ( $funnel_id > 0 ) {
            $wpdb->update( $funnels_table, $funnel_data, array( 'id' => $funnel_id ), array( '%s', '%d', '%s' ), array( '%d' ) );
        } else {
            $funnel_data['created_at'] = current_time( 'mysql' );
            $wpdb->insert( $funnels_table, $funnel_data, array( '%s', '%d', '%s', '%s' ) );
            $funnel_id = $wpdb->insert_id;
        }

        // Delete existing steps.
        $wpdb->delete( $steps_table, array( 'funnel_id' => $funnel_id ), array( '%d' ) );

        // Save steps.
        $step_order = 1;
        if ( is_array( $steps_data ) ) {
            // Limit to 3 upsell steps.
            $step_count = 0;
            foreach ( $steps_data as $step ) {
                if ( $step_count >= 3 ) {
                    break;
                }

                $upsell_product_id   = isset( $step['upsell_product_id'] ) ? absint( $step['upsell_product_id'] ) : 0;
                $upsell_variation_id = isset( $step['upsell_variation_id'] ) ? absint( $step['upsell_variation_id'] ) : 0;
                $upsell_discount     = isset( $step['upsell_discount'] ) ? floatval( $step['upsell_discount'] ) : 0;
                $upsell_discount     = max( 0, min( 100, $upsell_discount ) );

                if ( empty( $upsell_product_id ) ) {
                    continue;
                }

                $upsell_badge_text = isset( $step['upsell_badge_text'] ) ? sanitize_text_field( $step['upsell_badge_text'] ) : '';
                $upsell_headline   = isset( $step['upsell_headline'] ) ? sanitize_text_field( $step['upsell_headline'] ) : '';

                // Insert upsell step.
                $wpdb->insert(
                    $steps_table,
                    array(
                        'funnel_id'    => $funnel_id,
                        'step_type'    => 'upsell',
                        'product_id'   => $upsell_product_id,
                        'variation_id' => $upsell_variation_id,
                        'discount'     => $upsell_discount,
                        'badge_text'   => $upsell_badge_text,
                        'headline'     => $upsell_headline,
                        'step_order'   => $step_order,
                    ),
                    array( '%d', '%s', '%d', '%d', '%f', '%s', '%s', '%d' )
                );
                $upsell_step_id = $wpdb->insert_id;
                $step_order++;

                // Insert downsell if enabled.
                $has_downsell = ! empty( $step['has_downsell'] );
                if ( $has_downsell ) {
                    $downsell_product_id   = isset( $step['downsell_product_id'] ) ? absint( $step['downsell_product_id'] ) : 0;
                    $downsell_variation_id = isset( $step['downsell_variation_id'] ) ? absint( $step['downsell_variation_id'] ) : 0;
                    $downsell_discount     = isset( $step['downsell_discount'] ) ? floatval( $step['downsell_discount'] ) : 0;
                    $downsell_discount     = max( 0, min( 100, $downsell_discount ) );

                    $downsell_badge_text = isset( $step['downsell_badge_text'] ) ? sanitize_text_field( $step['downsell_badge_text'] ) : '';
                    $downsell_headline   = isset( $step['downsell_headline'] ) ? sanitize_text_field( $step['downsell_headline'] ) : '';

                    if ( $downsell_product_id > 0 ) {
                        $wpdb->insert(
                            $steps_table,
                            array(
                                'funnel_id'      => $funnel_id,
                                'step_type'      => 'downsell',
                                'product_id'     => $downsell_product_id,
                                'variation_id'   => $downsell_variation_id,
                                'discount'       => $downsell_discount,
                                'badge_text'     => $downsell_badge_text,
                                'headline'       => $downsell_headline,
                                'step_order'     => $step_order,
                                'parent_step_id' => $upsell_step_id,
                            ),
                            array( '%d', '%s', '%d', '%d', '%f', '%s', '%s', '%d', '%d' )
                        );
                        $step_order++;
                    }
                }

                $step_count++;
            }
        }

        wp_send_json_success( array(
            'message'   => __( 'Funnel saved successfully!', 'jejeemech-upsell' ),
            'funnel_id' => $funnel_id,
            'redirect'  => admin_url( 'admin.php?page=jm-upsell-funnels' ),
        ) );
    }

    /**
     * AJAX: Delete funnel.
     */
    public function ajax_delete_funnel() {
        check_ajax_referer( 'jm_upsell_admin', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'jejeemech-upsell' ) ) );
            return;
        }

        global $wpdb;
        $funnel_id = isset( $_POST['funnel_id'] ) ? absint( $_POST['funnel_id'] ) : 0;

        if ( $funnel_id < 1 ) {
            wp_send_json_error( array( 'message' => __( 'Invalid funnel ID.', 'jejeemech-upsell' ) ) );
            return;
        }

        $funnels_table = $wpdb->prefix . 'jm_upsell_funnels';
        $steps_table   = $wpdb->prefix . 'jm_upsell_steps';

        $wpdb->delete( $steps_table, array( 'funnel_id' => $funnel_id ), array( '%d' ) );
        $wpdb->delete( $funnels_table, array( 'id' => $funnel_id ), array( '%d' ) );

        wp_send_json_success( array( 'message' => __( 'Funnel deleted successfully.', 'jejeemech-upsell' ) ) );
    }

    /**
     * AJAX: Toggle funnel status.
     */
    public function ajax_toggle_funnel() {
        check_ajax_referer( 'jm_upsell_admin', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'jejeemech-upsell' ) ) );
            return;
        }

        global $wpdb;
        $funnel_id = isset( $_POST['funnel_id'] ) ? absint( $_POST['funnel_id'] ) : 0;
        $status    = isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : 'inactive';

        if ( ! in_array( $status, array( 'active', 'inactive' ), true ) ) {
            $status = 'inactive';
        }

        $table = $wpdb->prefix . 'jm_upsell_funnels';
        $wpdb->update( $table, array( 'status' => $status ), array( 'id' => $funnel_id ), array( '%s' ), array( '%d' ) );

        wp_send_json_success( array( 'status' => $status ) );
    }

    /**
     * AJAX: Search products.
     * context=trigger  → returns simple products + variable product parents.
     * context=step     → returns simple products + individual purchasable variations.
     */
    public function ajax_search_products() {
        check_ajax_referer( 'jm_upsell_admin', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'jejeemech-upsell' ) ) );
            return;
        }

        $term    = isset( $_GET['term'] ) ? sanitize_text_field( wp_unslash( $_GET['term'] ) ) : '';
        $context = isset( $_GET['context'] ) && $_GET['context'] === 'trigger' ? 'trigger' : 'step';

        if ( empty( $term ) ) {
            wp_send_json_success( array() );
            return;
        }

        $args = array(
            'status'  => 'publish',
            'limit'   => 15,
            's'       => $term,
            'orderby' => 'title',
            'order'   => 'ASC',
        );

        // Query simple and variable products separately for maximum WooCommerce compatibility.
        $args['type'] = 'simple';
        $simple_products = wc_get_products( $args );
        $args['type'] = 'variable';
        $variable_products = wc_get_products( $args );
        $products = array_merge( $simple_products, $variable_products );
        $results  = array();

        foreach ( $products as $product ) {
            if ( $product->is_type( 'variable' ) ) {
                if ( $context === 'trigger' ) {
                    // Trigger: show the variable parent (any variation will match).
                    $results[] = array(
                        'id'           => $product->get_id(),
                        'variation_id' => 0,
                        'text'         => $product->get_name(),
                        'price'        => wc_price( $product->get_price() ),
                    );
                } else {
                    // Step: expand to individual purchasable variations.
                    foreach ( $product->get_children() as $variation_id ) {
                        $variation = wc_get_product( $variation_id );
                        if ( ! $variation || ! $variation->is_purchasable() ) {
                            continue;
                        }

                        // Build human-readable attribute label.
                        $attr_parts = array();
                        foreach ( $variation->get_variation_attributes() as $attr_key => $attr_val ) {
                            if ( '' === $attr_val ) {
                                continue;
                            }
                            $taxonomy = str_replace( 'attribute_', '', $attr_key );
                            if ( taxonomy_exists( $taxonomy ) ) {
                                $term_obj = get_term_by( 'slug', $attr_val, $taxonomy );
                                $label    = $term_obj ? $term_obj->name : $attr_val;
                            } else {
                                $label = $attr_val;
                            }
                            $attr_parts[] = $label;
                        }
                        $attr_str = implode( ' / ', $attr_parts );
                        $name     = $product->get_name() . ( $attr_str ? ' – ' . $attr_str : '' );

                        $results[] = array(
                            'id'           => $product->get_id(),
                            'variation_id' => $variation->get_id(),
                            'text'         => $name,
                            'price'        => wc_price( $variation->get_price() ),
                        );
                    }
                }
            } else {
                // Simple product.
                $results[] = array(
                    'id'           => $product->get_id(),
                    'variation_id' => 0,
                    'text'         => $product->get_name(),
                    'price'        => wc_price( $product->get_price() ),
                );
            }
        }

        wp_send_json_success( $results );
    }
}
