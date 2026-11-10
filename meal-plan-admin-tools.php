<?php
/**
 * Plugin Name: Meal Plan Admin Tools
 * Plugin URI: https://github.com/fareed-rifaideen-ecom/meal-plan-admin-tools
 * Description: Standalone backend & frontend utilities for the Meal Plan ecosystem (Custom Plan Manager & Direct Link Generator, Manual & Bulk CSV Subscriber Importer, Bag Deposit Wallet, VIP Magic Links & Database Cleanup).
 * Version: 2.3.0
 * Author: Fareed M Rifaideen
 * Author URI: https://fareed-rifaideen.netlify.app
 */

// Prevent direct file access
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( function_exists( 'opcache_invalidate' ) ) {
    @opcache_invalidate( __FILE__, true );
}

// ==========================================
// 0. PER-PLAN SUBSCRIPTION LOGISTICS SCHEMA & HELPER FUNCTIONS
// ==========================================
function cmp_admin_tools_ensure_logistics_columns() {
    if ( function_exists( 'cmp_ensure_subscription_logistics_columns' ) ) {
        cmp_ensure_subscription_logistics_columns();
    }
    if ( function_exists( 'cmp_ensure_prep_timing_column' ) ) {
        cmp_ensure_prep_timing_column();
    }

    global $wpdb;
    $table_subs = $wpdb->prefix . 'cmp_subscriptions';

    $table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_subs ) );
    if ( $table_exists !== $table_subs ) {
        return;
    }

    $existing_cols = $wpdb->get_col( "DESC {$table_subs}", 0 );
    if ( ! is_array( $existing_cols ) ) {
        return;
    }

    $columns_to_add = array(
        'delivery_method'  => "ALTER TABLE {$table_subs} ADD COLUMN delivery_method VARCHAR(100) DEFAULT ''",
        'pickup_location'  => "ALTER TABLE {$table_subs} ADD COLUMN pickup_location VARCHAR(255) DEFAULT ''",
        'delivery_timing'  => "ALTER TABLE {$table_subs} ADD COLUMN delivery_timing VARCHAR(100) DEFAULT ''",
        'time_slot'        => "ALTER TABLE {$table_subs} ADD COLUMN time_slot VARCHAR(100) DEFAULT ''",
        'allergies'        => "ALTER TABLE {$table_subs} ADD COLUMN allergies TEXT NULL",
        'delivery_address' => "ALTER TABLE {$table_subs} ADD COLUMN delivery_address TEXT NULL",
        'prep_timing'      => "ALTER TABLE {$table_subs} ADD COLUMN prep_timing VARCHAR(30) DEFAULT 'auto'",
    );

    foreach ( $columns_to_add as $col_name => $alter_sql ) {
        if ( ! in_array( $col_name, $existing_cols, true ) ) {
            $wpdb->query( $alter_sql );
        }
    }
}

function cmp_admin_tools_record_logistics_profile( $user_id, $sub_id, $profile_data = array(), $update_default_meta = true ) {
    if ( function_exists( 'cmp_save_user_logistics_profile_history' ) ) {
        cmp_save_user_logistics_profile_history( $user_id, $sub_id, $profile_data, $update_default_meta );
        return;
    }

    $user_id = intval( $user_id );
    $sub_id  = intval( $sub_id );
    if ( $user_id <= 0 ) {
        return;
    }

    $timing    = sanitize_text_field( $profile_data['timing'] ?? '' );
    $time_slot = sanitize_text_field( $profile_data['time_slot'] ?? '' );
    $allergies = sanitize_textarea_field( $profile_data['allergies'] ?? '' );
    $method    = sanitize_text_field( $profile_data['method'] ?? '' );
    $pickup    = sanitize_text_field( $profile_data['pickup'] ?? '' );
    $address   = sanitize_text_field( $profile_data['address'] ?? '' );
    $plan_name = sanitize_text_field( $profile_data['plan_name'] ?? '' );

    if ( $update_default_meta ) {
        if ( ! empty( $timing ) )    update_user_meta( $user_id, 'delivery_timing', $timing );
        if ( ! empty( $time_slot ) ) update_user_meta( $user_id, 'time_slot', $time_slot );
        update_user_meta( $user_id, 'allergies', $allergies );
        if ( ! empty( $method ) )    update_user_meta( $user_id, 'delivery_method', $method );
        if ( ! empty( $pickup ) )    update_user_meta( $user_id, 'pickup_location', $pickup );
        if ( ! empty( $address ) )   update_user_meta( $user_id, 'billing_address_1', $address );
    }

    $profiles = get_user_meta( $user_id, '_cmp_saved_logistics_profiles', true );
    if ( ! is_array( $profiles ) ) {
        $profiles = array();
    }

    $profile_key = 'sub_' . ( $sub_id > 0 ? $sub_id : md5( $plan_name . '|' . $timing . '|' . $time_slot ) );
    $profiles[ $profile_key ] = array(
        'sub_id'     => $sub_id,
        'plan_name'  => $plan_name ?: ( 'Plan #' . $sub_id ),
        'method'     => $method,
        'pickup'     => $pickup,
        'timing'     => $timing,
        'time_slot'  => $time_slot,
        'allergies'  => $allergies,
        'address'    => $address,
        'updated_at' => current_time( 'mysql' ),
    );

    if ( count( $profiles ) > 15 ) {
        $profiles = array_slice( $profiles, -15, 15, true );
    }

    update_user_meta( $user_id, '_cmp_saved_logistics_profiles', $profiles );
}

/**
 * Resolves the frontend URL of the page containing `[meal_plan_checkout]`.
 */
function cmp_admin_tools_get_checkout_base_url() {
    if ( function_exists( 'cmp_get_custom_checkout_base_url' ) ) {
        return cmp_get_custom_checkout_base_url();
    }

    global $wpdb;
    $page_id = intval( $wpdb->get_var(
        "SELECT ID FROM {$wpdb->posts}
         WHERE post_type = 'page'
           AND post_status = 'publish'
           AND post_content LIKE '%[meal_plan_checkout%'
         ORDER BY ID ASC LIMIT 1"
    ) );

    if ( $page_id > 0 ) {
        $permalink = get_permalink( $page_id );
        if ( ! empty( $permalink ) ) {
            return $permalink;
        }
    }

    return home_url( '/' );
}

/**
 * Renders a hoverable "?" Help Icon with a rich CSS tooltip.
 */
function cmp_admin_tools_help_tip( $text ) {
    return '<span class="cmp-at-help-tip" tabindex="0" aria-label="' . esc_attr( $text ) . '">?<span class="cmp-at-help-Running-text">' . esc_html( $text ) . '</span></span>';
}

/**
 * Shared Handler: Creates a WooCommerce Meal Plan / Cleanse Booster Product with Tags & Custom Plan Flags.
 */
function cmp_admin_tools_handle_custom_plan_actions( $nonce_action, $nonce_field ) {
    $result = array(
        'triggered' => false,
        'html'      => '',
    );

    $can_manage_plans = current_user_can( 'manage_options' ) || current_user_can( 'menu_manager' ) || current_user_can( 'foh_manager' );
    if ( ! $can_manage_plans ) {
        return $result;
    }

    // 1. Create New Custom Plan Product
    if ( isset( $_POST['cmp_at_create_custom_plan'] ) && isset( $_POST[ $nonce_field ] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ $nonce_field ] ) ), $nonce_action ) ) {
        $result['triggered'] = true;

        $plan_name       = sanitize_text_field( wp_unslash( $_POST['plan_name'] ?? '' ) );
        $regular_price   = isset( $_POST['regular_price'] ) && $_POST['regular_price'] !== '' ? floatval( $_POST['regular_price'] ) : 0.0;
        $sale_price_raw  = isset( $_POST['sale_price'] ) && trim( $_POST['sale_price'] ) !== '' ? floatval( $_POST['sale_price'] ) : null;
        $total_days      = intval( $_POST['total_days'] ?? 0 );
        $allowed_meals   = max( 1, intval( $_POST['allowed_meals'] ?? 1 ) );
        $plan_type       = sanitize_text_field( wp_unslash( $_POST['plan_type'] ?? 'standard' ) );
        $special_cat_raw = sanitize_text_field( wp_unslash( $_POST['special_category'] ?? '' ) );
        $tags_raw        = sanitize_text_field( wp_unslash( $_POST['plan_tags'] ?? '' ) );
        $description     = sanitize_textarea_field( wp_unslash( $_POST['plan_description'] ?? '' ) );

        $direct_link_only = isset( $_POST['direct_link_only'] ) ? 'yes' : 'no';
        $auto_unpublish   = isset( $_POST['auto_unpublish'] ) ? 'yes' : 'no';

        if ( empty( $plan_name ) || $total_days <= 0 || $regular_price <= 0 ) {
            $result['html'] = '<div class="cmp-at-alert error"><strong>Missing Required Fields:</strong> Please provide a valid Plan Name, Total Days, and Regular Price (AED).</div>';
            return $result;
        }

        $special_category = ( $plan_type === 'juice' || strcasecmp( $special_cat_raw, 'Juices' ) === 0 ) ? 'Juices' : $special_cat_raw;
        $effective_price  = ( $sale_price_raw !== null && $sale_price_raw >= 0 && $sale_price_raw < $regular_price ) ? $sale_price_raw : $regular_price;

        $post_id = wp_insert_post( array(
            'post_title'   => $plan_name,
            'post_content' => ! empty( $description ) ? $description : $plan_name,
            'post_excerpt' => $description,
            'post_status'  => 'publish',
            'post_type'    => 'product',
        ), true );

        if ( is_wp_error( $post_id ) ) {
            $result['html'] = '<div class="cmp-at-alert error"><strong>Error:</strong> ' . esc_html( $post_id->get_error_message() ) . '</div>';
            return $result;
        }

        // Set WooCommerce Simple Product Type & Core Price/Stock Meta
        wp_set_object_terms( $post_id, 'simple', 'product_type' );
        update_post_meta( $post_id, '_visibility', 'visible' );
        update_post_meta( $post_id, '_stock_status', 'instock' );
        update_post_meta( $post_id, '_virtual', 'yes' );
        update_post_meta( $post_id, '_regular_price', wc_format_decimal( $regular_price ) );

        if ( $sale_price_raw !== null && $sale_price_raw >= 0 && $sale_price_raw < $regular_price ) {
            update_post_meta( $post_id, '_sale_price', wc_format_decimal( $sale_price_raw ) );
            update_post_meta( $post_id, '_price', wc_format_decimal( $sale_price_raw ) );
        } else {
            update_post_meta( $post_id, '_sale_price', '' );
            update_post_meta( $post_id, '_price', wc_format_decimal( $regular_price ) );
        }

        // Save Meal Plan Meta Fields (Identical to WooCommerce Product Editor)
        update_post_meta( $post_id, '_cmp_total_days', (string) $total_days );
        update_post_meta( $post_id, '_cmp_allowed_meals', (string) $allowed_meals );
        update_post_meta( $post_id, '_cmp_allowed_categories', $special_category );
        update_post_meta( $post_id, '_cmp_direct_link_only', $direct_link_only );
        update_post_meta( $post_id, '_cmp_auto_unpublish', $auto_unpublish );
        update_post_meta( $post_id, '_cmp_created_via_admin_tools', 'yes' );

        // Assign Product Tags (e.g., "10 Days", "Juice", etc.)
        $tag_list = array_filter( array_map( 'trim', explode( ',', $tags_raw ) ) );
        if ( empty( $tag_list ) ) {
            $tag_list[] = ( $special_category === 'Juices' ) ? 'Juice' : ( $total_days . ' Days' );
        }
        wp_set_object_terms( $post_id, $tag_list, 'product_tag', false );

        if ( function_exists( 'wc_delete_product_transients' ) ) {
            wc_delete_product_transients( $post_id );
        }

        $direct_url = add_query_arg( 'custom_plan', intval( $post_id ), cmp_admin_tools_get_checkout_base_url() );

        ob_start();
        ?>
        <div class="cmp-at-success-banner">
            <div class="cmp-at-success-top">
                <div>
                    <span class="cmp-at-kicker-pill">&#10003; Custom Plan Created (Product #<?php echo intval( $post_id ); ?>)</span>
                    <h4><?php echo esc_html( $plan_name ); ?> &mdash; AED <?php echo number_format( $effective_price, 2 ); ?></h4>
                    <p>
                        <?php echo intval( $total_days ); ?> Days &bull;
                        <?php echo ( $special_category === 'Juices' ) ? 'Cleanse Booster (Juices)' : intval( $allowed_meals ) . ' Meal(s) / Day'; ?> &bull;
                        Tags: <strong><?php echo esc_html( implode( ', ', $tag_list ) ); ?></strong>
                    </p>
                </div>
            </div>
            <div class="cmp-at-link-copy-row">
                <input type="text" id="cmp-at-new-plan-url" value="<?php echo esc_url( $direct_url ); ?>" readonly onclick="this.select();">
                <button type="button" class="cmp-at-btn cmp-at-btn-primary" onclick="cmpAtCopyText('<?php echo esc_js( $direct_url ); ?>', this, '📋 Copy Direct Checkout Link')">
                    &#128203; Copy Direct Checkout Link
                </button>
            </div>
        </div>
        <?php
        $result['html'] = ob_get_clean();
        return $result;
    }

    // 2. Toggle Product Status (Publish <-> Draft)
    if ( isset( $_POST['cmp_at_toggle_plan_status'] ) && isset( $_POST[ $nonce_field ] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ $nonce_field ] ) ), $nonce_action ) ) {
        $result['triggered'] = true;
        $product_id          = intval( $_POST['product_id'] ?? 0 );
        $target_status       = sanitize_text_field( wp_unslash( $_POST['target_status'] ?? 'draft' ) );
        if ( $product_id > 0 && in_array( $target_status, array( 'publish', 'draft' ), true ) ) {
            wp_update_post( array(
                'ID'          => $product_id,
                'post_status' => $target_status,
            ) );
            if ( function_exists( 'wc_delete_product_transients' ) ) {
                wc_delete_product_transients( $product_id );
            }
            $status_label   = ( $target_status === 'publish' ) ? 'Published (Live for Checkout)' : 'Reverted to Draft (Offline)';
            $result['html'] = '<div class="cmp-at-alert success">&#10003; Plan #' . intval( $product_id ) . ' status updated to <strong>' . esc_html( $status_label ) . '</strong>.</div>';
        }
        return $result;
    }

    // 3. Move Custom Plan to Trash
    if ( isset( $_POST['cmp_at_trash_custom_plan'] ) && isset( $_POST[ $nonce_field ] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ $nonce_field ] ) ), $nonce_action ) ) {
        $result['triggered'] = true;
        $trash_id            = intval( $_POST['trash_plan_id'] ?? 0 );
        if ( $trash_id > 0 ) {
            wp_trash_post( $trash_id );
            if ( function_exists( 'wc_delete_product_transients' ) ) {
                wc_delete_product_transients( $trash_id );
            }
            $result['html'] = '<div class="cmp-at-alert success">&#10003; Custom Plan #' . intval( $trash_id ) . ' has been moved to Trash.</div>';
        }
        return $result;
    }

    return $result;
}

// ==========================================
// 0.8 SHARED UI/UX STYLES & INTERACTIVE SCRIPTS (ECOSYSTEM DESIGN SYSTEM)
// ==========================================
function cmp_admin_tools_render_ecosystem_styles_and_scripts() {
    static $rendered = false;
    if ( $rendered ) {
        return;
    }
    $rendered = true;
    ?>
    <style>
        .cmp-at-shell,
        .cmp-at-shell *,
        .cmp-at-shell *::before,
        .cmp-at-shell *::after {
            box-sizing: border-box;
            letter-spacing: normal;
        }
        .cmp-at-shell {
            max-width: 1280px;
            margin: 18px auto 50px auto;
            padding: 0 12px;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            color: #1e293b;
        }
        .cmp-at-shell button,
        .cmp-at-shell input,
        .cmp-at-shell select,
        .cmp-at-shell textarea,
        .cmp-at-shell a {
            letter-spacing: normal !important;
            text-transform: none !important;
            font-family: inherit;
        }

        /* Ecosystem Glassmorphic Forest Header */
        .cmp-at-header-banner {
            background: linear-gradient(135deg, rgba(27, 94, 32, 0.95) 0%, rgba(55, 146, 55, 0.89) 55%, rgba(104, 185, 70, 0.85) 100%) !important;
            backdrop-filter: blur(18px) !important;
            -webkit-backdrop-filter: blur(18px) !important;
            color: #ffffff !important;
            padding: 24px 28px !important;
            border-radius: 18px 18px 0 0 !important;
            border: 1.5px solid #DCECC9 !important;
            border-bottom: 4px solid #68B946 !important;
            display: flex !important;
            justify-content: space-between !important;
            align-items: center !important;
            flex-wrap: wrap !important;
            gap: 15px !important;
            box-shadow: 0 12px 28px rgba(27, 94, 32, 0.14) !important;
        }
        .cmp-at-header-banner.is-standalone-header {
            border-radius: 18px !important;
            margin-bottom: 22px !important;
        }
        .cmp-at-header-banner h1,
        .cmp-at-header-banner h2 {
            margin: 0 !important;
            color: #ffffff !important;
            font-size: 1.75rem !important;
            font-weight: 800 !important;
            line-height: 1.2 !important;
        }
        .cmp-at-header-banner p {
            margin: 5px 0 0 0 !important;
            color: #EEF7E3 !important;
            font-size: 0.95rem !important;
            font-weight: 500 !important;
        }
        .cmp-at-hdr-actions {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        .cmp-at-hdr-btn {
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            gap: 6px !important;
            padding: 9px 16px !important;
            border-radius: 10px !important;
            font-weight: 800 !important;
            font-size: 0.86rem !important;
            text-decoration: none !important;
            transition: all 0.2s ease !important;
            white-space: nowrap !important;
            background: rgba(255, 255, 255, 0.16) !important;
            color: #ffffff !important;
            border: 1.5px solid rgba(220, 236, 201, 0.55) !important;
        }
        .cmp-at-hdr-btn:hover {
            background: #ffffff !important;
            color: #1B5E20 !important;
            transform: translateY(-1px) !important;
        }
        .cmp-at-hdr-btn.btn-logout {
            background: #dc2626 !important;
            border-color: rgba(254, 202, 202, 0.45) !important;
        }
        .cmp-at-hdr-btn.btn-logout:hover {
            background: #b91c1c !important;
            color: #ffffff !important;
        }

        /* Ecosystem Tab Navigation Bar */
        .cmp-at-tab-nav {
            display: flex !important;
            background: #ffffff !important;
            border: 1.5px solid #DCECC9 !important;
            border-top: none !important;
            border-bottom: 2px solid #DCECC9 !important;
            padding: 8px 14px 0 14px !important;
            gap: 8px !important;
            overflow-x: auto !important;
            scrollbar-width: none !important;
            border-radius: 0 0 14px 14px !important;
            margin-bottom: 24px !important;
            box-shadow: 0 4px 14px rgba(27, 94, 32, 0.04) !important;
        }
        .cmp-at-tab-nav::-webkit-scrollbar { display: none !important; }
        .cmp-at-tab-btn {
            padding: 12px 20px !important;
            border: none !important;
            background: transparent !important;
            font-weight: 800 !important;
            font-size: 0.94rem !important;
            cursor: pointer !important;
            border-bottom: 3px solid transparent !important;
            border-radius: 10px 10px 0 0 !important;
            color: #64748b !important;
            white-space: nowrap !important;
            transition: all 0.2s ease !important;
            display: inline-flex !important;
            align-items: center !important;
            gap: 8px !important;
        }
        .cmp-at-tab-btn:hover {
            color: #1B5E20 !important;
            background: rgba(238, 247, 227, 0.35) !important;
        }
        .cmp-at-tab-btn.active {
            color: #1B5E20 !important;
            border-bottom-color: #379237 !important;
            background: rgba(238, 247, 227, 0.72) !important;
        }
        .cmp-at-tab-pane { display: none; }
        .cmp-at-tab-pane.active { display: block; animation: cmpAtFadeIn 0.25s ease-out; }
        @keyframes cmpAtFadeIn {
            from { opacity: 0; transform: translateY(6px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Cards & Grid Layout */
        .cmp-at-grid-2col {
            display: grid;
            grid-template-columns: 1.15fr 1fr;
            gap: 22px;
            align-items: start;
        }
        @media (max-width: 992px) {
            .cmp-at-grid-2col {
                grid-template-columns: 1fr;
            }
        }
        .cmp-at-card {
            background: #ffffff;
            border: 1.5px solid #DCECC9;
            border-top: 4px solid #379237;
            border-radius: 16px;
            padding: 24px 26px;
            margin-bottom: 22px;
            box-shadow: 0 6px 20px rgba(27, 94, 32, 0.05);
        }
        .cmp-at-card.accent-purple { border-top-color: #7c3aed; }
        .cmp-at-card.accent-amber  { border-top-color: #f59e0b; }
        .cmp-at-card.accent-red    { border-top-color: #dc2626; }
        .cmp-at-card.accent-blue   { border-top-color: #0284c7; }

        .cmp-at-card-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
            padding-bottom: 14px;
            margin-bottom: 18px;
            border-bottom: 1px solid #e2e8f0;
        }
        .cmp-at-card-head h3 {
            margin: 0 !important;
            color: #1B5E20 !important;
            font-size: 1.22rem !important;
            font-weight: 800 !important;
            display: flex !important;
            align-items: center !important;
            gap: 8px !important;
        }
        .cmp-at-card-sub {
            color: #64748b;
            font-size: 0.88rem;
            margin: -8px 0 18px 0;
            line-height: 1.45;
        }

        /* Form Rows, Labels & Inputs */
        .cmp-at-row {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
            margin-bottom: 16px;
        }
        .cmp-at-row-3 {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 16px;
            margin-bottom: 16px;
        }
        @media (max-width: 680px) {
            .cmp-at-row,
            .cmp-at-row-3 {
                grid-template-columns: 1fr;
                gap: 12px;
            }
        }
        .cmp-at-field {
            margin-bottom: 16px;
        }
        .cmp-at-row .cmp-at-field,
        .cmp-at-row-3 .cmp-at-field {
            margin-bottom: 0;
        }
        .cmp-at-label {
            display: flex !important;
            align-items: center !important;
            gap: 6px !important;
            font-size: 0.85rem !important;
            font-weight: 800 !important;
            color: #1e293b !important;
            margin-bottom: 6px !important;
            line-height: 1.3 !important;
        }
        .cmp-at-input,
        .cmp-at-select,
        .cmp-at-textarea {
            width: 100% !important;
            min-height: 44px !important;
            padding: 9px 13px !important;
            border: 1.5px solid #cbd5e1 !important;
            border-radius: 10px !important;
            background: #f8fafc !important;
            color: #0f172a !important;
            font-size: 0.94rem !important;
            font-weight: 600 !important;
            transition: all 0.18s ease !important;
            box-shadow: none !important;
        }
        .cmp-at-textarea {
            min-height: 76px !important;
            resize: vertical;
        }
        .cmp-at-input:focus,
        .cmp-at-select:focus,
        .cmp-at-textarea:focus {
            background: #ffffff !important;
            border-color: #379237 !important;
            outline: none !important;
            box-shadow: 0 0 0 3px rgba(55, 146, 55, 0.18) !important;
        }

        /* Segmented Plan Type Switcher */
        .cmp-at-seg-group {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 8px;
            background: #f1f5f9;
            padding: 5px;
            border-radius: 11px;
            border: 1px solid #cbd5e1;
        }
        .cmp-at-seg-btn {
            background: transparent !important;
            color: #475569 !important;
            border: 1px solid transparent !important;
            border-radius: 8px !important;
            padding: 9px 12px !important;
            font-size: 0.86rem !important;
            font-weight: 800 !important;
            cursor: pointer !important;
            transition: all 0.18s ease !important;
            text-align: center !important;
        }
        .cmp-at-seg-btn.is-active {
            background: #ffffff !important;
            color: #1B5E20 !important;
            border-color: #68B946 !important;
            box-shadow: 0 2px 8px rgba(15, 23, 42, 0.08) !important;
        }

        /* Quick Tag Pills */
        .cmp-at-tag-presets {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-top: 7px;
        }
        .cmp-at-tag-chip {
            background: #f1f5f9 !important;
            color: #334155 !important;
            border: 1px solid #cbd5e1 !important;
            border-radius: 999px !important;
            padding: 3px 10px !important;
            font-size: 0.75rem !important;
            font-weight: 700 !important;
            cursor: pointer !important;
            transition: all 0.15s ease !important;
        }
        .cmp-at-tag-chip:hover {
            background: #EEF7E3 !important;
            color: #1B5E20 !important;
            border-color: #379237 !important;
        }

        /* Option Check Cards */
        .cmp-at-check-card {
            display: flex !important;
            align-items: flex-start !important;
            gap: 10px !important;
            background: #f8fafc;
            border: 1.5px solid #e2e8f0;
            border-radius: 10px;
            padding: 11px 14px;
            margin-bottom: 10px;
            cursor: pointer;
            transition: all 0.18s ease;
        }
        .cmp-at-check-card:hover {
            border-color: #68B946;
            background: #f4fbf0;
        }
        .cmp-at-check-card input[type="checkbox"] {
            width: 18px !important;
            height: 18px !important;
            margin-top: 2px !important;
            accent-color: #379237 !important;
            flex-shrink: 0 !important;
            cursor: pointer !important;
        }
        .cmp-at-check-title {
            display: flex;
            align-items: center;
            gap: 6px;
            font-weight: 800;
            font-size: 0.88rem;
            color: #0f172a;
            margin-bottom: 2px;
        }
        .cmp-at-check-desc {
            display: block;
            font-size: 0.8rem;
            color: #64748b;
            line-height: 1.35;
            font-weight: 500;
        }

        /* Hoverable "?" Help Icon with Tooltip */
        .cmp-at-help-tip {
            position: relative;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 17px;
            height: 17px;
            border-radius: 50%;
            background: #EEF7E3;
            color: #1B5E20;
            border: 1px solid #68B946;
            font-size: 11px;
            font-weight: 900;
            cursor: help;
            flex-shrink: 0;
            user-select: none;
        }
        .cmp-at-help-tip:hover,
        .cmp-at-help-tip:focus {
            background: #379237;
            color: #ffffff;
            outline: none;
        }
        .cmp-at-help-Running-text {
            visibility: hidden;
            opacity: 0;
            position: absolute;
            bottom: calc(100% + 9px);
            left: 50%;
            transform: translateX(-50%) translateY(4px);
            width: 250px;
            background: #0f172a;
            color: #ffffff;
            font-size: 0.77rem;
            font-weight: 600;
            line-height: 1.45;
            padding: 9px 12px;
            border-radius: 8px;
            box-shadow: 0 10px 25px rgba(15, 23, 42, 0.28);
            z-index: 9999;
            pointer-events: none;
            transition: opacity 0.18s ease, transform 0.18s ease;
            text-align: left;
            white-space: normal;
        }
        .cmp-at-help-Running-text::after {
            content: "";
            position: absolute;
            top: 100%;
            left: 50%;
            margin-left: -6px;
            border-width: 6px;
            border-style: solid;
            border-color: #0f172a transparent transparent transparent;
        }
        .cmp-at-help-tip:hover .cmp-at-help-Running-text,
        .cmp-at-help-tip:focus .cmp-at-help-Running-text {
            visibility: visible;
            opacity: 1;
            transform: translateX(-50%) translateY(0);
        }

        /* Buttons */
        .cmp-at-btn {
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            gap: 7px !important;
            min-height: 44px !important;
            padding: 10px 22px !important;
            border-radius: 10px !important;
            font-size: 0.92rem !important;
            font-weight: 800 !important;
            border: none !important;
            cursor: pointer !important;
            text-decoration: none !important;
            transition: all 0.2s ease !important;
            white-space: nowrap !important;
        }
        .cmp-at-btn-primary {
            background: linear-gradient(135deg, #379237 0%, #1B5E20 100%) !important;
            color: #ffffff !important;
            box-shadow: 0 6px 16px rgba(27, 94, 32, 0.22) !important;
        }
        .cmp-at-btn-primary:hover {
            filter: brightness(1.07);
            transform: translateY(-1px);
            color: #ffffff !important;
        }
        .cmp-at-btn-purple {
            background: linear-gradient(135deg, #7c3aed 0%, #5b21b6 100%) !important;
            color: #ffffff !important;
            box-shadow: 0 6px 16px rgba(124, 58, 237, 0.22) !important;
        }
        .cmp-at-btn-amber {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%) !important;
            color: #ffffff !important;
        }
        .cmp-at-btn-danger {
            background: #dc2626 !important;
            color: #ffffff !important;
        }
        .cmp-at-btn-outline {
            background: #ffffff !important;
            color: #1B5E20 !important;
            border: 1.5px solid #68B946 !important;
            min-height: 36px !important;
            padding: 6px 12px !important;
            font-size: 0.8rem !important;
        }
        .cmp-at-btn-outline:hover {
            background: #EEF7E3 !important;
        }

        /* Alerts & Success Link Banner */
        .cmp-at-alert {
            padding: 14px 18px;
            border-radius: 12px;
            margin-bottom: 20px;
            font-size: 0.92rem;
            line-height: 1.45;
            font-weight: 600;
        }
        .cmp-at-alert.success {
            background: #f0fdf4;
            color: #166534;
            border: 1.5px solid #86efac;
            border-left: 5px solid #379237;
        }
        .cmp-at-alert.error {
            background: #fef2f2;
            color: #991b1b;
            border: 1.5px solid #fecaca;
            border-left: 5px solid #dc2626;
        }
        .cmp-at-success-banner {
            background: linear-gradient(135deg, #f0fdf4 0%, #EEF7E3 100%);
            border: 2px solid #68B946;
            border-radius: 14px;
            padding: 18px 20px;
            margin-bottom: 22px;
            box-shadow: 0 8px 22px rgba(27, 94, 32, 0.10);
        }
        .cmp-at-kicker-pill {
            display: inline-block;
            background: #1B5E20;
            color: #ffffff;
            font-size: 0.72rem;
            font-weight: 800;
            padding: 3px 10px;
            border-radius: 999px;
            text-transform: uppercase;
            margin-bottom: 6px;
        }
        .cmp-at-success-banner h4 {
            margin: 0 0 4px 0 !important;
            color: #1B5E20 !important;
            font-size: 1.15rem !important;
            font-weight: 800 !important;
        }
        .cmp-at-success-banner p {
            margin: 0 0 12px 0 !important;
            color: #334155 !important;
            font-size: 0.86rem !important;
        }
        .cmp-at-link-copy-row {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
        }
        .cmp-at-link-copy-row input[type="text"] {
            flex: 1;
            min-width: 240px;
            height: 44px;
            padding: 8px 12px;
            border: 1.5px solid #86efac;
            border-radius: 9px;
            background: #ffffff;
            color: #0f172a;
            font-weight: 700;
            font-size: 0.88rem;
        }

        /* Recent Custom Plans Cards */
        .cmp-at-plan-item {
            background: #f8fafc;
            border: 1.5px solid #e2e8f0;
            border-radius: 12px;
            padding: 14px 16px;
            margin-bottom: 12px;
            transition: border-color 0.18s ease, box-shadow 0.18s ease;
        }
        .cmp-at-plan-item:hover {
            border-color: #b7e1a1;
            background: #ffffff;
            box-shadow: 0 4px 12px rgba(15, 23, 42, 0.05);
        }
        .cmp-at-plan-item-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 10px;
            margin-bottom: 6px;
        }
        .cmp-at-plan-title {
            font-weight: 800;
            color: #0f172a;
            font-size: 0.98rem;
            line-height: 1.3;
        }
        .cmp-at-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 9px;
            border-radius: 999px;
            font-size: 0.72rem;
            font-weight: 800;
            white-space: nowrap;
        }
        .cmp-at-badge.badge-live {
            background: #dcfce7;
            color: #166534;
            border: 1px solid #86efac;
        }
        .cmp-at-badge.badge-draft {
            background: #f1f5f9;
            color: #475569;
            border: 1px solid #cbd5e1;
        }
        .cmp-at-badge.badge-purple {
            background: #ede9fe;
            color: #6d28d9;
            border: 1px solid #ddd6fe;
        }
        .cmp-at-badge.badge-amber {
            background: #fef3c7;
            color: #92400e;
            border: 1px solid #fde68a;
        }
        .cmp-at-plan-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 6px 12px;
            font-size: 0.81rem;
            color: #475569;
            font-weight: 600;
            margin-bottom: 10px;
        }
        .cmp-at-plan-url-box {
            display: flex;
            gap: 6px;
            align-items: center;
            margin-bottom: 10px;
        }
        .cmp-at-plan-url-box input[type="text"] {
            flex: 1;
            height: 34px;
            padding: 4px 10px;
            font-size: 0.78rem;
            border: 1px solid #cbd5e1;
            border-radius: 7px;
            background: #ffffff;
            color: #334155;
            font-weight: 600;
        }
        .cmp-at-plan-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
            padding-top: 8px;
            border-top: 1px dashed #e2e8f0;
        }
    </style>

    <script>
    function cmpAtShowToast(message, type) {
        document.querySelectorAll('.cmp-at-toast').forEach(function(el) { el.remove(); });
        var toast = document.createElement('div');
        toast.className = 'cmp-at-toast';
        var isSuccess = (type !== 'error');
        Object.assign(toast.style, {
            position: 'fixed',
            top: '24px',
            left: '50%',
            transform: 'translateX(-50%)',
            background: isSuccess ? '#1B5E20' : '#dc2626',
            color: '#ffffff',
            border: '1.5px solid ' + (isSuccess ? '#68B946' : '#fca5a5'),
            borderRadius: '10px',
            padding: '11px 20px',
            fontSize: '0.88rem',
            fontWeight: '800',
            boxShadow: '0 10px 25px rgba(15, 23, 42, 0.25)',
            zIndex: '100099',
            maxWidth: '92vw',
            textAlign: 'center'
        });
        toast.textContent = message;
        document.body.appendChild(toast);
        setTimeout(function() {
            toast.style.transition = 'opacity 0.25s ease';
            toast.style.opacity = '0';
            setTimeout(function() { toast.remove(); }, 260);
        }, 3200);
    }

    function cmpAtCopyText(text, btnEl, defaultLabel) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text);
        } else {
            var temp = document.createElement('input');
            temp.value = text;
            document.body.appendChild(temp);
            temp.select();
            document.execCommand('copy');
            temp.remove();
        }
        if (btnEl) {
            var orig = defaultLabel || btnEl.innerText;
            btnEl.innerText = '✓ Link Copied!';
            setTimeout(function() { btnEl.innerText = orig; }, 2000);
        }
        cmpAtShowToast('Direct customer checkout link copied to clipboard!', 'success');
    }

    function cmpAtConfirmModal(message, onConfirm) {
        document.querySelectorAll('.cmp-at-modal-backdrop').forEach(function(el) { el.remove(); });
        var backdrop = document.createElement('div');
        backdrop.className = 'cmp-at-modal-backdrop';
        Object.assign(backdrop.style, {
            position: 'fixed',
            inset: '0',
            background: 'rgba(15, 23, 42, 0.55)',
            backdropFilter: 'blur(4px)',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            zIndex: '100100',
            padding: '16px'
        });

        var box = document.createElement('div');
        Object.assign(box.style, {
            background: '#ffffff',
            border: '2px solid #DCECC9',
            borderTop: '5px solid #dc2626',
            borderRadius: '14px',
            maxWidth: '420px',
            width: '100%',
            padding: '22px 20px',
            boxShadow: '0 20px 40px rgba(15, 23, 42, 0.25)',
            textAlign: 'center',
            fontFamily: '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif'
        });

        var p = document.createElement('p');
        Object.assign(p.style, {
            margin: '0 0 18px 0',
            color: '#1e293b',
            fontSize: '0.95rem',
            fontWeight: '700',
            lineHeight: '1.45',
            whiteSpace: 'pre-line'
        });
        p.textContent = message;

        var btnRow = document.createElement('div');
        Object.assign(btnRow.style, { display: 'flex', gap: '10px', justifyContent: 'center' });

        var cancelBtn = document.createElement('button');
        cancelBtn.type = 'button';
        cancelBtn.textContent = 'Cancel';
        Object.assign(cancelBtn.style, {
            flex: '1',
            padding: '10px 14px',
            borderRadius: '8px',
            border: '1.5px solid #cbd5e1',
            background: '#f8fafc',
            color: '#334155',
            fontWeight: '800',
            cursor: 'pointer'
        });
        cancelBtn.onclick = function() { backdrop.remove(); };

        var okBtn = document.createElement('button');
        okBtn.type = 'button';
        okBtn.textContent = 'Confirm';
        Object.assign(okBtn.style, {
            flex: '1',
            padding: '10px 14px',
            borderRadius: '8px',
            border: 'none',
            background: '#dc2626',
            color: '#ffffff',
            fontWeight: '800',
            cursor: 'pointer'
        });
        okBtn.onclick = function() {
            backdrop.remove();
            if (typeof onConfirm === 'function') onConfirm();
        };

        btnRow.appendChild(cancelBtn);
        btnRow.appendChild(okBtn);
        box.appendChild(p);
        box.appendChild(btnRow);
        backdrop.appendChild(box);
        document.body.appendChild(backdrop);
    }

    function cmpAtSetPlanType(btnEl, typeVal, prefix) {
        var container = btnEl.closest('.cmp-at-seg-group');
        if (container) {
            container.querySelectorAll('.cmp-at-seg-btn').forEach(function(b) { b.classList.remove('is-active'); });
        }
        btnEl.classList.add('is-active');

        var typeInput = document.getElementById(prefix + '_plan_type');
        var specInput = document.getElementById(prefix + '_special_category');
        var mealsWrap = document.getElementById(prefix + '_meals_wrap');
        var tagsInput = document.getElementById(prefix + '_plan_tags');

        if (typeInput) typeInput.value = typeVal;
        if (typeVal === 'juice') {
            if (specInput) specInput.value = 'Juices';
            if (mealsWrap) mealsWrap.style.opacity = '0.55';
            if (tagsInput && !tagsInput.value.trim()) tagsInput.value = 'Juice';
        } else {
            if (specInput && specInput.value === 'Juices') specInput.value = '';
            if (mealsWrap) mealsWrap.style.opacity = '1';
        }
    }

    function cmpAtAddTagChip(tagValue, inputId) {
        var inp = document.getElementById(inputId);
        if (!inp) return;
        var current = inp.value.split(',').map(function(s) { return s.trim(); }).filter(Boolean);
        if (current.indexOf(tagValue) === -1) {
            current.push(tagValue);
            inp.value = current.join(', ');
        }
    }
    </script>
    <?php
}

/**
 * Shared UI Renderer for the Custom Plan Manager (Used in both Frontend Master Portal and WP Admin).
 */
function cmp_admin_tools_render_custom_plan_manager_section( $nonce_action, $nonce_field, $prefix = 'fe' ) {
    $base_checkout_url = cmp_admin_tools_get_checkout_base_url();

    // Fetch Custom Plans & Meal Plan Products
    $custom_products = get_posts( array(
        'post_type'      => 'product',
        'post_status'    => array( 'publish', 'draft' ),
        'posts_per_page' => 20,
        'orderby'        => 'ID',
        'order'          => 'DESC',
        'meta_query'     => array(
            'relation' => 'OR',
            array(
                'key'     => '_cmp_created_via_admin_tools',
                'value'   => 'yes',
                'compare' => '=',
            ),
            array(
                'key'     => '_cmp_direct_link_only',
                'value'   => 'yes',
                'compare' => '=',
            ),
            array(
                'key'     => '_cmp_auto_unpublish',
                'value'   => 'yes',
                'compare' => '=',
            ),
        ),
    ) );
    ?>
    <div class="cmp-at-grid-2col">
        <!-- LEFT COLUMN: CREATE CUSTOM PLAN / CLEANSE BOOSTER FORM -->
        <div class="cmp-at-card">
            <div class="cmp-at-card-head">
                <h3>
                    <span>&#10024; Create Custom Plan or Cleanse Booster</span>
                    <?php echo cmp_admin_tools_help_tip( 'Creates a WooCommerce meal plan product with your exact days, meals per day, category override, and tags, and generates a direct customer checkout link.' ); ?>
                </h3>
            </div>
            <p class="cmp-at-card-sub">
                Build a custom package for a customer request and share the generated link on WhatsApp. When <strong>Direct Link Only</strong> is ticked, normal website visitors will never see this plan on the public checkout page.
            </p>

            <form method="POST">
                <?php wp_nonce_field( $nonce_action, $nonce_field ); ?>

                <!-- 1. Plan Type Switcher (Standard Meal Plan vs. Cleanse Booster / Juices) -->
                <div class="cmp-at-field">
                    <label class="cmp-at-label">
                        <span>Package Type *</span>
                        <?php echo cmp_admin_tools_help_tip( 'Choose Standard Meal Plan for Breakfast/Lunch/Dinner plans, or Cleanse Booster (Juices) to automatically set the Special Category Override to "Juices".' ); ?>
                    </label>
                    <input type="hidden" name="plan_type" id="<?php echo esc_attr( $prefix ); ?>_plan_type" value="standard">
                    <div class="cmp-at-seg-group">
                        <button type="button" class="cmp-at-seg-btn is-active" onclick="cmpAtSetPlanType(this, 'standard', '<?php echo esc_js( $prefix ); ?>')">
                            &#127869;&#65039; Standard Meal Plan
                        </button>
                        <button type="button" class="cmp-at-seg-btn" onclick="cmpAtSetPlanType(this, 'juice', '<?php echo esc_js( $prefix ); ?>')">
                            &#129380; Cleanse Booster (Juices)
                        </button>
                    </div>
                </div>

                <!-- 2. Plan Title / Name -->
                <div class="cmp-at-field">
                    <label class="cmp-at-label">
                        <span>Product / Plan Name *</span>
                        <?php echo cmp_admin_tools_help_tip( 'The exact title shown to the customer on the checkout page and in their Meal Portal (e.g., "10 Days - 1 Meal per day" or "Cleanse Booster - 5 Days").' ); ?>
                    </label>
                    <input type="text" name="plan_name" required class="cmp-at-input" placeholder="e.g. 10 Days - 1 Meal per day">
                </div>

                <!-- 3. Regular Price & Sale Price -->
                <div class="cmp-at-row">
                    <div class="cmp-at-field">
                        <label class="cmp-at-label">
                            <span>Regular Price (AED) *</span>
                            <?php echo cmp_admin_tools_help_tip( 'Standard price in AED for this custom package (e.g., 680 or 1215).' ); ?>
                        </label>
                        <input type="number" step="0.01" min="1" name="regular_price" required class="cmp-at-input" placeholder="e.g. 680">
                    </div>
                    <div class="cmp-at-field">
                        <label class="cmp-at-label">
                            <span>Sale Price (AED) (Optional)</span>
                            <?php echo cmp_admin_tools_help_tip( 'Optional discounted price in AED. Leave blank if there is no sale price.' ); ?>
                        </label>
                        <input type="number" step="0.01" min="0" name="sale_price" class="cmp-at-input" placeholder="Leave blank if none">
                    </div>
                </div>

                <!-- 4. Total Days, Allowed Meals Per Day & Special Category Override -->
                <div class="cmp-at-row-3">
                    <div class="cmp-at-field">
                        <label class="cmp-at-label">
                            <span>Meal Plan: Total Days *</span>
                            <?php echo cmp_admin_tools_help_tip( 'Exact number of days the customer can schedule in the Customer Portal (e.g., 5, 7, 10, 14, 20, or 24).' ); ?>
                        </label>
                        <input type="number" name="total_days" id="<?php echo esc_attr( $prefix ); ?>_total_days" required min="1" max="365" class="cmp-at-input" placeholder="e.g. 10">
                    </div>

                    <div class="cmp-at-field" id="<?php echo esc_attr( $prefix ); ?>_meals_wrap">
                        <label class="cmp-at-label">
                            <span>Allowed Meals / Day *</span>
                            <?php echo cmp_admin_tools_help_tip( 'Enter 1, 2, or 3 main meals per day. (For Cleanse Boosters, you can leave this as 1; the system automatically allocates 3 Juices/day).' ); ?>
                        </label>
                        <select name="allowed_meals" class="cmp-at-select">
                            <option value="1">1 Meal / Day (+1 Snack)</option>
                            <option value="2">2 Meals / Day (+2 Snacks)</option>
                            <option value="3">3 Meals / Day (+2 Snacks)</option>
                        </select>
                    </div>

                    <div class="cmp-at-field">
                        <label class="cmp-at-label">
                            <span>Category Override</span>
                            <?php echo cmp_admin_tools_help_tip( 'Enter EXACTLY "Juices" if this is a Cleanse Booster. Leave blank for standard Breakfast/Lunch/Dinner meal plans.' ); ?>
                        </label>
                        <input type="text" name="special_category" id="<?php echo esc_attr( $prefix ); ?>_special_category" class="cmp-at-input" placeholder="Blank or Juices">
                    </div>
                </div>

                <!-- 5. Product Tags with 1-Tap Quick Chips -->
                <div class="cmp-at-field">
                    <label class="cmp-at-label">
                        <span>Product Tags</span>
                        <?php echo cmp_admin_tools_help_tip( 'Tags organize plans into sections on Step 1 of the checkout page (e.g., "10 Days", "7 Days", "Juice"). Click any quick tag below or type custom tags separated by commas.' ); ?>
                    </label>
                    <input type="text" name="plan_tags" id="<?php echo esc_attr( $prefix ); ?>_plan_tags" class="cmp-at-input" placeholder="e.g. 10 Days, Custom Plan">
                    <div class="cmp-at-tag-presets">
                        <span style="font-size:0.75rem; color:#64748b; font-weight:700; align-self:center; margin-right:2px;">Quick Add:</span>
                        <?php foreach ( array( '7 Days', '10 Days', '14 Days', '20 Days', '24 Days', 'Juice' ) as $preset_tag ) : ?>
                            <button type="button" class="cmp-at-tag-chip" onclick="cmpAtAddTagChip('<?php echo esc_js( $preset_tag ); ?>', '<?php echo esc_js( $prefix . '_plan_tags' ); ?>')">
                                + <?php echo esc_html( $preset_tag ); ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- 6. Visibility & Auto-Unpublish Controls -->
                <div class="cmp-at-field" style="margin-top: 18px;">
                    <label class="cmp-at-label">
                        <span>Checkout Visibility &amp; Lifecycle Controls</span>
                        <?php echo cmp_admin_tools_help_tip( 'Control whether this custom plan is private (Direct Link Only) and whether it automatically unpublishes itself after the customer pays.' ); ?>
                    </label>

                    <label class="cmp-at-check-card">
                        <input type="checkbox" name="direct_link_only" value="1" checked>
                        <div>
                            <span class="cmp-at-check-title">
                                <span>&#128274; Hide from Public Checkout (Direct Link Only)</span>
                                <?php echo cmp_admin_tools_help_tip( 'When checked, this plan is invisible on the public checkout page. Only customers who open your direct link (?custom_plan=ID) will see it.' ); ?>
                            </span>
                            <span class="cmp-at-check-desc">Keep this custom package private so only the customer with the direct link can view and purchase it.</span>
                        </div>
                    </label>

                    <label class="cmp-at-check-card">
                        <input type="checkbox" name="auto_unpublish" value="1" checked>
                        <div>
                            <span class="cmp-at-check-title">
                                <span>&#9889; Auto-Unpublish After Purchase (Revert to Draft on Payment)</span>
                                <?php echo cmp_admin_tools_help_tip( 'As soon as the customer completes payment and the order reaches Processing, the product automatically switches to Draft status so it cannot be bought again.' ); ?>
                            </span>
                            <span class="cmp-at-check-desc">Automatically switch this plan back to Draft status as soon as the customer completes payment.</span>
                        </div>
                    </label>
                </div>

                <button type="submit" name="cmp_at_create_custom_plan" class="cmp-at-btn cmp-at-btn-primary" style="width:100%; margin-top:6px;">
                    &#10024; Create Custom Plan &amp; Generate Direct Link
                </button>
            </form>
        </div>

        <!-- RIGHT COLUMN: CUSTOM PLANS DIRECTORY & DIRECT LINK MANAGER -->
        <div class="cmp-at-card">
            <div class="cmp-at-card-head">
                <h3>
                    <span>&#128279; Custom Plans &amp; Direct Links</span>
                    <?php echo cmp_admin_tools_help_tip( 'Copy the direct checkout link for any custom plan, toggle its status between Live (Published) and Offline (Draft), or move old plans to Trash.' ); ?>
                </h3>
                <span class="cmp-at-badge badge-live"><?php echo count( $custom_products ); ?> Plan(s)</span>
            </div>
            <p class="cmp-at-card-sub">
                Share the <strong>Direct Customer Link</strong> via WhatsApp or Email. Make sure the plan status is <strong>Live (Published)</strong> while the customer is completing payment.
            </p>

            <?php if ( empty( $custom_products ) ) : ?>
                <div style="text-align:center; padding:40px 20px; background:#f8fafc; border:1.5px dashed #cbd5e1; border-radius:12px; color:#64748b;">
                    <strong>No custom plans created yet.</strong>
                    <p style="margin:6px 0 0 0; font-size:0.86rem;">Use the generator on the left to create your first custom meal plan or cleanse booster link.</p>
                </div>
            <?php else : ?>
                <div style="max-height: 680px; overflow-y: auto; padding-right: 4px;">
                    <?php foreach ( $custom_products as $cp ) :
                        $pid            = intval( $cp->ID );
                        $is_published   = ( $cp->post_status === 'publish' );
                        $price          = get_post_meta( $pid, '_price', true );
                        $days           = get_post_meta( $pid, '_cmp_total_days', true );
                        $meals          = get_post_meta( $pid, '_cmp_allowed_meals', true );
                        $spec_cat       = get_post_meta( $pid, '_cmp_allowed_categories', true );
                        $is_direct_only = ( get_post_meta( $pid, '_cmp_direct_link_only', true ) === 'yes' );
                        $is_auto_unpub  = ( get_post_meta( $pid, '_cmp_auto_unpublish', true ) === 'yes' );

                        $tag_names = wp_get_post_terms( $pid, 'product_tag', array( 'fields' => 'names' ) );
                        if ( is_wp_error( $tag_names ) ) {
                            $tag_names = array();
                        }

                        $direct_url = add_query_arg( 'custom_plan', $pid, $base_checkout_url );
                        $quota_text = ( strcasecmp( $spec_cat, 'Juices' ) === 0 ) ? 'Cleanse Booster (Juices)' : ( intval( $meals ?: 1 ) . ' Meal(s)/Day' );
                    ?>
                        <div class="cmp-at-plan-item">
                            <div class="cmp-at-plan-item-top">
                                <div>
                                    <div class="cmp-at-plan-title"><?php echo esc_html( $cp->post_title ); ?></div>
                                    <div class="cmp-at-plan-meta" style="margin-top:4px; margin-bottom:4px;">
                                        <span style="color:#1B5E20; font-weight:800;">AED <?php echo number_format( floatval( $price ), 2 ); ?></span>
                                        <span>&bull; <?php echo intval( $days ?: 30 ); ?> Days</span>
                                        <span>&bull; <?php echo esc_html( $quota_text ); ?></span>
                                        <?php if ( ! empty( $tag_names ) ) : ?>
                                            <span>&bull; Tags: <?php echo esc_html( implode( ', ', $tag_names ) ); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php if ( $is_published ) : ?>
                                    <span class="cmp-at-badge badge-live">&#9679; Live (Published)</span>
                                <?php else : ?>
                                    <span class="cmp-at-badge badge-draft">Draft (Offline)</span>
                                <?php endif; ?>
                            </div>

                            <div style="display:flex; gap:6px; flex-wrap:wrap; margin-bottom:9px;">
                                <?php if ( $is_direct_only ) : ?>
                                    <span class="cmp-at-badge badge-purple">&#128274; Direct Link Only</span>
                                <?php endif; ?>
                                <?php if ( $is_auto_unpub ) : ?>
                                    <span class="cmp-at-badge badge-amber">&#9889; Auto-Unpublish on Pay</span>
                                <?php endif; ?>
                            </div>

                            <div class="cmp-at-plan-url-box">
                                <input type="text" value="<?php echo esc_url( $direct_url ); ?>" readonly onclick="this.select();">
                                <button type="button" class="cmp-at-btn cmp-at-btn-outline" onclick="cmpAtCopyText('<?php echo esc_js( $direct_url ); ?>', this, '📋 Copy Link')">
                                    &#128203; Copy Link
                                </button>
                            </div>

                            <div class="cmp-at-plan-actions">
                                <span style="font-size:0.76rem; color:#94a3b8; font-weight:600;">
                                    ID #<?php echo intval( $pid ); ?> &bull; <?php echo esc_html( get_the_date( 'd M Y', $pid ) ); ?>
                                </span>

                                <div style="display:flex; gap:6px; align-items:center;">
                                    <!-- Publish / Draft Quick Toggle -->
                                    <form method="POST" style="margin:0;">
                                        <?php wp_nonce_field( $nonce_action, $nonce_field ); ?>
                                        <input type="hidden" name="product_id" value="<?php echo esc_attr( $pid ); ?>">
                                        <input type="hidden" name="target_status" value="<?php echo $is_published ? 'draft' : 'publish'; ?>">
                                        <button type="submit" name="cmp_at_toggle_plan_status" class="cmp-at-btn cmp-at-btn-outline" style="min-height:30px !important; padding:4px 10px !important; font-size:0.75rem !important;">
                                            <?php echo $is_published ? 'Set to Draft (Hide)' : 'Publish (Make Live)'; ?>
                                        </button>
                                    </form>

                                    <!-- Trash Plan (Uses Non-Blocking Modal) -->
                                    <form method="POST" style="margin:0;" id="cmp-at-trash-form-<?php echo esc_attr( $prefix . '_' . $pid ); ?>">
                                        <?php wp_nonce_field( $nonce_action, $nonce_field ); ?>
                                        <input type="hidden" name="trash_plan_id" value="<?php echo esc_attr( $pid ); ?>">
                                        <input type="hidden" name="cmp_at_trash_custom_plan" value="1">
                                        <button type="button" class="cmp-at-btn" style="background:#fff1f2 !important; color:#dc2626 !important; border:1px solid #fecaca !important; min-height:30px !important; padding:4px 10px !important; font-size:0.75rem !important;"
                                                onclick="cmpAtConfirmModal('Move custom plan &quot;<?php echo esc_js( $cp->post_title ); ?>&quot; to Trash?', function(){ document.getElementById('cmp-at-trash-form-<?php echo esc_js( $prefix . '_' . $pid ); ?>').submit(); });">
                                            Trash
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

// ==========================================
// 1. REGISTER INDEPENDENT WP ADMIN SUBMENUS (UNDER MEAL PORTAL)
// ==========================================
add_action( 'admin_menu', 'cmp_standalone_admin_tools_menu', 60 );
function cmp_standalone_admin_tools_menu() {

    // Submenu 1: Custom Plan Manager
    add_submenu_page(
        'cmp-menu-manager',
        'Custom Plan Manager',
        'Custom Plan Manager',
        'read',
        'cmp-custom-plan-manager',
        'cmp_standalone_render_custom_plan_manager'
    );

    // Submenu 2: Default Importer
    add_submenu_page(
        'cmp-menu-manager',
        'Import Legacy Subscriber',
        'Import Subscriber',
        'read',
        'cmp-admin-tools',
        'cmp_standalone_render_manual_import'
    );

    // Submenu 3: Cleanup Tool (Strictly Super Admin)
    add_submenu_page(
        'cmp-menu-manager',
        'Database Cleanup',
        'Cleanup Tool',
        'manage_options',
        'cmp-db-cleanup',
        'cmp_standalone_render_cleanup'
    );

    // Submenu 4: Deposit Manager
    add_submenu_page(
        'cmp-menu-manager',
        'Deposit Manager',
        'Deposit Manager',
        'read',
        'cmp-deposit-manager',
        'cmp_standalone_render_deposit_manager'
    );
}

// ==========================================
// 2. CSV TEMPLATE DOWNLOADER
// ==========================================
add_action( 'admin_init', 'cmp_download_csv_template' );
add_action( 'template_redirect', 'cmp_download_csv_template' );
function cmp_download_csv_template() {
    if ( isset( $_GET['cmp_download_template'] ) && ( current_user_can( 'manage_options' ) || current_user_can( 'menu_manager' ) ) ) {
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="Meal_Plan_Bulk_Import_Template.csv"' );
        $output = fopen( 'php://output', 'w' );
        fputs( $output, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) );

        fputcsv( $output, array( 'Email', 'First Name', 'Last Name', 'Phone', 'Address', 'Delivery Method (Delivery/Pickup)', 'Receive By (Deliver Day Before/Deliver Same Day)', 'Time Slot', 'Allergies', 'Plan Name', 'Remaining Days', 'Plan Price', 'Discount Amount' ) );
        fputcsv( $output, array( 'john@example.com', 'John', 'Doe', '0501234567', 'Dubai Marina', 'Delivery', 'Deliver Day Before', '5:00 AM to 6:00 AM', 'Nuts', '2 Meal Plan', '20', '2420.00', '0.00' ) );

        fclose( $output );
        exit;
    }
}

// ==========================================
// 2.5 WP ADMIN SUBMENU: CUSTOM PLAN MANAGER
// ==========================================
function cmp_standalone_render_custom_plan_manager() {
    if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'menu_manager' ) && ! current_user_can( 'foh_manager' ) ) {
        wp_die( 'Access Denied: You do not have permission to manage custom plans.' );
    }

    cmp_admin_tools_render_ecosystem_styles_and_scripts();
    $action_res = cmp_admin_tools_handle_custom_plan_actions( 'cmp_wpadmin_custom_plan_action', 'cmp_wpadmin_custom_plan_nonce' );
    ?>
    <div class="wrap cmp-at-shell">
        <div class="cmp-at-header-banner is-standalone-header">
            <div>
                <h1>Custom Plan Manager</h1>
                <p>The Cycle Bistro &mdash; Create Bespoke Meal Plans, Cleanse Boosters &amp; Direct Customer Checkout Links</p>
            </div>
        </div>
        <?php echo $action_res['html']; ?>
        <?php cmp_admin_tools_render_custom_plan_manager_section( 'cmp_wpadmin_custom_plan_action', 'cmp_wpadmin_custom_plan_nonce', 'wpa' ); ?>
    </div>
    <?php
}

// ==========================================
// 3. WP ADMIN SUBMENU: SUBSCRIBER IMPORT TOOL (MANUAL & BULK CSV)
// ==========================================
function cmp_standalone_render_manual_import() {
    if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'menu_manager' ) ) {
        wp_die( 'Access Denied: You do not have permission to view this tool.' );
    }

    cmp_admin_tools_ensure_logistics_columns();
    cmp_admin_tools_render_ecosystem_styles_and_scripts();

    global $wpdb;
    $message       = '';
    $table_subs    = $wpdb->prefix . 'cmp_subscriptions';
    $existing_cols = $wpdb->get_col( "DESC {$table_subs}", 0 );
    if ( ! is_array( $existing_cols ) ) {
        $existing_cols = array();
    }

    $custom_saved_timings = get_option( 'cmp_custom_delivery_timings', array() );
    if ( ! is_array( $custom_saved_timings ) ) $custom_saved_timings = array();
    $custom_saved_slots   = get_option( 'cmp_custom_time_slots', array() );
    if ( ! is_array( $custom_saved_slots ) ) $custom_saved_slots = array();

    $timing_options = array_values( array_unique( array_merge(
        array( 'Deliver Day Before', 'Deliver Same Day' ),
        $custom_saved_timings
    ) ) );
    $slot_options = array_values( array_unique( array_merge(
        array(
            '5:00 AM to 6:00 AM',
            '6:00 AM to 7:00 AM',
            '7:00 AM to 8:00 AM',
            '8:00 AM to 9:00 AM',
            '5:00 PM to 8:00 PM',
        ),
        $custom_saved_slots
    ) ) );

    // --- HANDLE BULK CSV IMPORT ---
    if ( isset( $_POST['cmp_run_csv_import'] ) && check_admin_referer( 'cmp_csv_import_action', 'cmp_csv_import_nonce' ) ) {
        if ( ! empty( $_FILES['csv_file']['tmp_name'] ) ) {
            $file   = $_FILES['csv_file']['tmp_name'];
            $handle = fopen( $file, 'r' );

            if ( $handle !== false ) {
                $row_count     = 0;
                $success_count = 0;
                $failed_rows   = array();

                fgetcsv( $handle, 1000, ',' ); // Skip header

                while ( ( $data = fgetcsv( $handle, 1000, ',' ) ) !== false ) {
                    $row_count++;
                    $email          = isset( $data[0] ) ? sanitize_email( $data[0] ) : '';
                    $first_name     = isset( $data[1] ) ? sanitize_text_field( $data[1] ) : '';
                    $last_name      = isset( $data[2] ) ? sanitize_text_field( $data[2] ) : '';
                    $phone          = isset( $data[3] ) ? sanitize_text_field( $data[3] ) : '';
                    $address        = isset( $data[4] ) ? sanitize_text_field( $data[4] ) : '';
                    $method         = isset( $data[5] ) ? sanitize_text_field( $data[5] ) : '';
                    $timing         = isset( $data[6] ) ? sanitize_text_field( $data[6] ) : '';
                    $time_slot      = isset( $data[7] ) ? sanitize_text_field( $data[7] ) : '';
                    $allergies      = isset( $data[8] ) ? sanitize_text_field( $data[8] ) : '';
                    $plan_name      = isset( $data[9] ) ? sanitize_text_field( $data[9] ) : '';
                    $remaining_days = isset( $data[10] ) ? intval( $data[10] ) : 0;
                    $plan_price     = isset( $data[11] ) ? floatval( $data[11] ) : 0.00;
                    $discount_amt   = isset( $data[12] ) ? floatval( $data[12] ) : 0.00;

                    if ( empty( $email ) || empty( $plan_name ) || $remaining_days <= 0 ) {
                        $failed_rows[] = "Row $row_count: Missing Email, Plan Name, or Remaining Days.";
                        continue;
                    }

                    $user = get_user_by( 'email', $email );
                    if ( ! $user ) {
                        $password = wp_generate_password( 12, false );
                        $user_id  = wp_create_user( $email, $password, $email );
                        if ( is_wp_error( $user_id ) ) {
                            $failed_rows[] = "Row $row_count ($email): " . $user_id->get_error_message();
                            continue;
                        }
                    } else {
                        $user_id = $user->ID;
                    }

                    $existing_active_subs = intval( $wpdb->get_var( $wpdb->prepare(
                        "SELECT COUNT(*) FROM {$table_subs} WHERE user_id = %d AND status IN ('active','paused')",
                        $user_id
                    ) ) );

                    wp_update_user( array( 'ID' => $user_id, 'first_name' => $first_name, 'last_name' => $last_name ) );
                    if ( ! empty( $phone ) )   update_user_meta( $user_id, 'billing_phone', $phone );
                    if ( ! empty( $address ) ) update_user_meta( $user_id, 'billing_address_1', $address );

                    if ( $existing_active_subs === 0 ) {
                        if ( ! empty( $method ) )    update_user_meta( $user_id, 'delivery_method', $method );
                        if ( ! empty( $timing ) )    update_user_meta( $user_id, 'delivery_timing', $timing );
                        if ( ! empty( $time_slot ) ) update_user_meta( $user_id, 'time_slot', $time_slot );
                        update_user_meta( $user_id, 'allergies', $allergies );
                    }

                    $categories = ( stripos( $plan_name, 'juice' ) !== false || stripos( $plan_name, 'cleanse' ) !== false ) ? 'Juices' : 'Breakfast,Lunch,Dinner,Snacks';

                    $insert_data = array(
                        'user_id'            => $user_id,
                        'wc_order_id'        => 0,
                        'plan_name'          => $plan_name,
                        'total_days'         => $remaining_days,
                        'allowed_categories' => $categories,
                        'plan_price'         => $plan_price,
                        'discount_amount'    => $discount_amt,
                        'status'             => 'active',
                        'start_date'         => date( 'Y-m-d H:i:s' ),
                        'expiry_date'        => date( 'Y-m-d H:i:s', strtotime( "+$remaining_days days" ) ),
                    );

                    if ( in_array( 'delivery_method', $existing_cols, true ) )  $insert_data['delivery_method']  = $method;
                    if ( in_array( 'delivery_timing', $existing_cols, true ) )  $insert_data['delivery_timing']  = $timing;
                    if ( in_array( 'time_slot', $existing_cols, true ) )        $insert_data['time_slot']        = $time_slot;
                    if ( in_array( 'allergies', $existing_cols, true ) )        $insert_data['allergies']        = ( $allergies !== '' ) ? $allergies : '__NONE__';
                    if ( in_array( 'delivery_address', $existing_cols, true ) ) $insert_data['delivery_address'] = $address;

                    $inserted = $wpdb->insert( $table_subs, $insert_data );

                    if ( $inserted ) {
                        $new_sub_id = intval( $wpdb->insert_id );
                        $wpdb->update( $table_subs, array( 'wc_order_id' => -$new_sub_id ), array( 'id' => $new_sub_id ) );

                        cmp_admin_tools_record_logistics_profile(
                            $user_id,
                            $new_sub_id,
                            array(
                                'plan_name' => $plan_name,
                                'method'    => $method,
                                'pickup'    => '',
                                'timing'    => $timing,
                                'time_slot' => $time_slot,
                                'allergies' => $allergies,
                                'address'   => $address,
                            ),
                            ( $existing_active_subs === 0 )
                        );
                        $success_count++;
                    } else {
                        $failed_rows[] = "Row $row_count ($email): Database insertion failed.";
                    }
                }
                fclose( $handle );

                if ( $success_count > 0 ) {
                    $message .= '<div class="cmp-at-alert success"><strong>Success!</strong> Imported <strong>' . intval( $success_count ) . '</strong> customers from the CSV file.</div>';
                }
                if ( ! empty( $failed_rows ) ) {
                    $message .= '<div class="cmp-at-alert error"><strong>Warning:</strong> The following rows failed to import:<br>' . implode( '<br>', array_map( 'esc_html', $failed_rows ) ) . '</div>';
                }
            }
        }
    }

    // --- HANDLE SINGLE MANUAL IMPORT ---
    if ( isset( $_POST['cmp_run_import'] ) && check_admin_referer( 'cmp_import_action', 'cmp_import_nonce' ) ) {
        $email          = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
        $first_name     = sanitize_text_field( wp_unslash( $_POST['first_name'] ?? '' ) );
        $last_name      = sanitize_text_field( wp_unslash( $_POST['last_name'] ?? '' ) );
        $phone          = sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) );
        $plan_name      = sanitize_text_field( wp_unslash( $_POST['plan_name'] ?? '' ) );
        $recipient_name = sanitize_text_field( wp_unslash( $_POST['recipient_name'] ?? '' ) );
        $remaining_days = intval( $_POST['remaining_days'] ?? 0 );
        $method         = sanitize_text_field( wp_unslash( $_POST['delivery_method'] ?? '' ) );
        $timing         = sanitize_text_field( wp_unslash( $_POST['delivery_timing'] ?? '' ) );
        $time_slot      = sanitize_text_field( wp_unslash( $_POST['time_slot'] ?? '' ) );
        $address        = sanitize_text_field( wp_unslash( $_POST['address'] ?? '' ) );
        $allergies      = sanitize_textarea_field( wp_unslash( $_POST['allergies'] ?? '' ) );
        $plan_price     = floatval( $_POST['plan_price'] ?? 0 );
        $discount_amt   = floatval( $_POST['discount_amount'] ?? 0 );
        $prep_timing    = sanitize_text_field( wp_unslash( $_POST['prep_timing'] ?? 'auto' ) );

        $plan_mode    = isset( $_POST['plan_mode'] ) ? sanitize_text_field( wp_unslash( $_POST['plan_mode'] ) ) : 'standard';
        $is_flexible  = ( $plan_mode === 'flexible' ) ? 1 : 0;
        $allow_custom = ( $plan_mode === 'custom' ) ? 1 : 0;

        if ( ! empty( $recipient_name ) ) {
            $plan_name .= ' - ' . $recipient_name;
        }

        if ( empty( $email ) || empty( $plan_name ) || $remaining_days <= 0 ) {
            $message = '<div class="cmp-at-alert error">Error: Email, Plan Name, and Remaining Days are required.</div>';
        } else {
            $user = get_user_by( 'email', $email );
            if ( ! $user ) {
                $password = wp_generate_password( 12, false );
                $user_id  = wp_create_user( $email, $password, $email );
                if ( is_wp_error( $user_id ) ) {
                    $message = '<div class="cmp-at-alert error">Error creating user account: ' . esc_html( $user_id->get_error_message() ) . '</div>';
                }
            } else {
                $user_id = $user->ID;
            }

            if ( empty( $message ) ) {
                $existing_active_subs = intval( $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table_subs} WHERE user_id = %d AND status IN ('active','paused')",
                    $user_id
                ) ) );

                wp_update_user( array( 'ID' => $user_id, 'first_name' => $first_name, 'last_name' => $last_name ) );
                update_user_meta( $user_id, 'billing_phone',     $phone );
                update_user_meta( $user_id, 'billing_address_1', $address );

                if ( $existing_active_subs === 0 ) {
                    update_user_meta( $user_id, 'delivery_method', $method );
                    update_user_meta( $user_id, 'delivery_timing', $timing );
                    update_user_meta( $user_id, 'time_slot',       $time_slot );
                    update_user_meta( $user_id, 'allergies',       $allergies );
                }

                $categories = ( stripos( $plan_name, 'juice' ) !== false || stripos( $plan_name, 'cleanse' ) !== false ) ? 'Juices' : 'Breakfast,Lunch,Dinner,Snacks';

                $insert_data = array(
                    'user_id'            => $user_id,
                    'wc_order_id'        => 0,
                    'plan_name'          => $plan_name,
                    'total_days'         => $remaining_days,
                    'allowed_categories' => $categories,
                    'plan_price'         => $plan_price,
                    'discount_amount'    => $discount_amt,
                    'is_flexible'        => $is_flexible,
                    'allow_custom'       => $allow_custom,
                    'status'             => 'active',
                    'start_date'         => date( 'Y-m-d H:i:s' ),
                    'expiry_date'        => date( 'Y-m-d H:i:s', strtotime( "+$remaining_days days" ) ),
                );

                if ( in_array( 'delivery_method', $existing_cols, true ) )  $insert_data['delivery_method']  = $method;
                if ( in_array( 'delivery_timing', $existing_cols, true ) )  $insert_data['delivery_timing']  = $timing;
                if ( in_array( 'time_slot', $existing_cols, true ) )        $insert_data['time_slot']        = $time_slot;
                if ( in_array( 'allergies', $existing_cols, true ) )        $insert_data['allergies']        = ( $allergies !== '' ) ? $allergies : '__NONE__';
                if ( in_array( 'delivery_address', $existing_cols, true ) ) $insert_data['delivery_address'] = $address;
                if ( in_array( 'prep_timing', $existing_cols, true ) )      $insert_data['prep_timing']      = $prep_timing;

                $inserted = $wpdb->insert( $table_subs, $insert_data );

                if ( $inserted ) {
                    $new_sub_id = intval( $wpdb->insert_id );
                    $wpdb->update( $table_subs, array( 'wc_order_id' => -$new_sub_id ), array( 'id' => $new_sub_id ) );

                    cmp_admin_tools_record_logistics_profile(
                        $user_id,
                        $new_sub_id,
                        array(
                            'plan_name' => $plan_name,
                            'method'    => $method,
                            'pickup'    => '',
                            'timing'    => $timing,
                            'time_slot' => $time_slot,
                            'allergies' => $allergies,
                            'address'   => $address,
                        ),
                        ( $existing_active_subs === 0 )
                    );

                    $message = '<div class="cmp-at-alert success"><strong>Success!</strong> ' . esc_html( $first_name ) . ' has been imported with <strong>' . intval( $remaining_days ) . ' days</strong> and independent plan logistics.</div>';
                } else {
                    $message = '<div class="cmp-at-alert error">Database error during insertion.</div>';
                }
            }
        }
    }
    ?>
    <div class="wrap cmp-at-shell">
        <div class="cmp-at-header-banner is-standalone-header">
            <div>
                <h1>Import Subscribers</h1>
                <p>The Cycle Bistro &mdash; Manual Subscriber Entry &amp; Bulk CSV Importer</p>
            </div>
            <div class="cmp-at-hdr-actions">
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=cmp-admin-tools&cmp_download_template=1' ) ); ?>" class="cmp-at-hdr-btn">
                    &#128229; Download CSV Template
                </a>
            </div>
        </div>

        <?php echo $message; ?>

        <div class="cmp-at-card">
            <div class="cmp-at-card-head">
                <h3>
                    <span>&#128196; Bulk CSV Import</span>
                    <?php echo cmp_admin_tools_help_tip( 'Upload a CSV matching the 13-column template to import multiple active subscribers at once with isolated per-plan logistics.' ); ?>
                </h3>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=cmp-admin-tools&cmp_download_template=1' ) ); ?>" class="cmp-at-btn cmp-at-btn-outline">Download CSV Template</a>
            </div>
            <p class="cmp-at-card-sub">Upload a CSV strictly matching the template headers to import multiple customers at once. Each row's delivery timing, time slot, and allergies are saved directly to that plan.</p>
            <form method="POST" action="" enctype="multipart/form-data" style="display:flex; align-items:center; gap:14px; flex-wrap:wrap; background:#f8fafc; padding:16px; border:1.5px dashed #cbd5e1; border-radius:12px;">
                <?php wp_nonce_field( 'cmp_csv_import_action', 'cmp_csv_import_nonce' ); ?>
                <input type="file" name="csv_file" accept=".csv" required style="flex:1; min-width:220px;">
                <button type="submit" name="cmp_run_csv_import" class="cmp-at-btn cmp-at-btn-primary">Process CSV Upload</button>
            </form>
        </div>

        <div class="cmp-at-card accent-blue">
            <div class="cmp-at-card-head">
                <h3>
                    <span>&#128100; Single Manual Subscriber Entry</span>
                    <?php echo cmp_admin_tools_help_tip( 'Manually provision an active meal plan for a customer (or a second family recipient plan) without going through payment checkout.' ); ?>
                </h3>
            </div>
            <form method="POST" action="">
                <?php wp_nonce_field( 'cmp_import_action', 'cmp_import_nonce' ); ?>

                <div class="cmp-at-row">
                    <div class="cmp-at-field">
                        <label class="cmp-at-label">Customer Email *</label>
                        <input type="email" name="email" required class="cmp-at-input" placeholder="customer@example.com">
                    </div>
                    <div class="cmp-at-field">
                        <label class="cmp-at-label">Phone Number *</label>
                        <input type="text" name="phone" required class="cmp-at-input" placeholder="0501234567">
                    </div>
                </div>

                <div class="cmp-at-row">
                    <div class="cmp-at-field">
                        <label class="cmp-at-label">First Name *</label>
                        <input type="text" name="first_name" required class="cmp-at-input">
                    </div>
                    <div class="cmp-at-field">
                        <label class="cmp-at-label">Last Name *</label>
                        <input type="text" name="last_name" required class="cmp-at-input">
                    </div>
                </div>

                <div class="cmp-at-field">
                    <label class="cmp-at-label">Delivery Address</label>
                    <input type="text" name="address" class="cmp-at-input" placeholder="Villa / Apartment, Street, Area in Dubai">
                </div>

                <div class="cmp-at-row-3">
                    <div class="cmp-at-field">
                        <label class="cmp-at-label">Delivery Method</label>
                        <select name="delivery_method" class="cmp-at-select">
                            <option value="Delivery">Home/Office Delivery</option>
                            <option value="Pickup">Store Pick-up</option>
                        </select>
                    </div>
                    <div class="cmp-at-field">
                        <label class="cmp-at-label">Receive By (Plan-Specific)</label>
                        <select name="delivery_timing" class="cmp-at-select">
                            <?php foreach ( $timing_options as $t_opt ) : ?>
                                <option value="<?php echo esc_attr( $t_opt ); ?>"><?php echo esc_html( $t_opt ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="cmp-at-field">
                        <label class="cmp-at-label">Time Slot (Plan-Specific)</label>
                        <select name="time_slot" class="cmp-at-select">
                            <?php foreach ( $slot_options as $s_opt ) : ?>
                                <option value="<?php echo esc_attr( $s_opt ); ?>" <?php selected( $s_opt, '8:00 AM to 9:00 AM' ); ?>><?php echo esc_html( $s_opt ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="cmp-at-field">
                    <label class="cmp-at-label">Allergies (Plan-Specific)</label>
                    <input type="text" name="allergies" class="cmp-at-input" placeholder="e.g. Nuts, Shellfish (Leave blank if none)">
                </div>

                <div class="cmp-at-row-3">
                    <div class="cmp-at-field">
                        <label class="cmp-at-label">Select Plan Quota *</label>
                        <select name="plan_name" class="cmp-at-select">
                            <option value="1 Meal Plan (Manual)">1 Meal Plan</option>
                            <option value="2 Meal Plan (Manual)">2 Meal Plan</option>
                            <option value="3 Meal Plan (Manual)">3 Meal Plan</option>
                            <option value="Juice Cleanse (Manual)">Juice Cleanse</option>
                        </select>
                    </div>
                    <div class="cmp-at-field">
                        <label class="cmp-at-label">
                            <span>Recipient Name (Optional)</span>
                            <?php echo cmp_admin_tools_help_tip( 'If this is a second plan or family member plan under the same email, enter the recipient name (e.g., Sarah) so it gets its own tab.' ); ?>
                        </label>
                        <input type="text" name="recipient_name" class="cmp-at-input" placeholder="e.g. Sarah">
                    </div>
                    <div class="cmp-at-field">
                        <label class="cmp-at-label">Remaining Days *</label>
                        <input type="number" name="remaining_days" required min="1" max="365" class="cmp-at-input" placeholder="e.g. 14">
                    </div>
                </div>

                <div class="cmp-at-row">
                    <div class="cmp-at-field">
                        <label class="cmp-at-label">Plan Price (AED) *</label>
                        <input type="number" step="0.01" name="plan_price" required min="0" value="0.00" class="cmp-at-input">
                    </div>
                    <div class="cmp-at-field">
                        <label class="cmp-at-label">Discount Amount (AED)</label>
                        <input type="number" step="0.01" name="discount_amount" min="0" value="0.00" class="cmp-at-input">
                    </div>
                </div>

                <div class="cmp-at-row">
                    <div class="cmp-at-field">
                        <label class="cmp-at-label">
                            <span>Plan Mode (Menu Selection Type)</span>
                            <?php echo cmp_admin_tools_help_tip( 'Standard restricts to Breakfast/Lunch/Dinner. Flexible allows picking any combination. Custom replaces dropdowns with free-text inputs.' ); ?>
                        </label>
                        <select name="plan_mode" class="cmp-at-select">
                            <option value="standard">Standard Menu (Strict Categories)</option>
                            <option value="flexible">Flexible Menu (Universal Dropdowns)</option>
                            <option value="custom">Bespoke Custom (Text Inputs)</option>
                        </select>
                    </div>
                    <div class="cmp-at-field">
                        <label class="cmp-at-label">
                            <span>Kitchen Prep Schedule</span>
                            <?php echo cmp_admin_tools_help_tip( 'Auto applies Same-Day Preparation when Receive By is Same Day and Time Slot is 4:00 PM or later.' ); ?>
                        </label>
                        <select name="prep_timing" class="cmp-at-select">
                            <option value="auto">Auto (Same-Day if Same Day &ge; 4:00 PM)</option>
                            <option value="same_day">Same-Day Prep (Manual Override)</option>
                            <option value="day_before">Day-Before Prep (Manual Override)</option>
                        </select>
                    </div>
                </div>

                <button type="submit" name="cmp_run_import" class="cmp-at-btn cmp-at-btn-primary">Import Single Customer</button>
            </form>
        </div>
    </div>
    <?php
}

// ==========================================
// 4. WP ADMIN SUBMENU: DATABASE CLEANUP TOOL
// ==========================================
function cmp_standalone_render_cleanup() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Access Denied: Only Super Administrators can run database cleanups.' );
    }

    cmp_admin_tools_render_ecosystem_styles_and_scripts();

    global $wpdb;
    $table_subs = $wpdb->prefix . 'cmp_subscriptions';
    $table_logs = $wpdb->prefix . 'cmp_daily_logs';
    $message    = '';

    if ( isset( $_POST['cmp_run_cleanup'] ) && check_admin_referer( 'cmp_cleanup_action', 'cmp_cleanup_nonce' ) ) {
        $days_old = intval( $_POST['days_old'] );
        $confirm  = isset( $_POST['confirm_delete'] );
        if ( $days_old < 30 ) {
            $message = '<div class="cmp-at-alert error"><strong>Error:</strong> Minimum timeframe is 30 days.</div>';
        } elseif ( ! $confirm ) {
            $message = '<div class="cmp-at-alert error"><strong>Error:</strong> Please check the confirmation box.</div>';
        } else {
            $cutoff_date    = date( 'Y-m-d H:i:s', strtotime( "-$days_old days" ) );
            $subs_to_delete = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM $table_subs WHERE expiry_date < %s", $cutoff_date ) );
            if ( empty( $subs_to_delete ) ) {
                $message = '<div class="cmp-at-alert success">No expired subscriptions found older than ' . intval( $days_old ) . ' days. Database is already clean.</div>';
            } else {
                $ids_list     = implode( ',', array_map( 'intval', $subs_to_delete ) );
                $logs_deleted = $wpdb->query( "DELETE FROM $table_logs WHERE subscription_id IN ($ids_list)" );
                $subs_deleted = $wpdb->query( "DELETE FROM $table_subs WHERE id IN ($ids_list)" );
                $message      = '<div class="cmp-at-alert success"><strong>Success!</strong> Deleted <strong>' . intval( $subs_deleted ) . '</strong> expired subscriptions and <strong>' . intval( $logs_deleted ) . '</strong> meal logs.</div>';
            }
        }
    }
    ?>
    <div class="wrap cmp-at-shell">
        <div class="cmp-at-header-banner is-standalone-header">
            <div>
                <h1>Meal Plan Database Cleanup</h1>
                <p>The Cycle Bistro &mdash; Archive &amp; Purge Historical Expired Subscription Data</p>
            </div>
        </div>
        <?php echo $message; ?>
        <div class="cmp-at-card accent-red" style="max-width: 720px;">
            <div class="cmp-at-card-head">
                <h3 style="color:#dc2626 !important;">&#129529; Purge Old Subscription Data</h3>
            </div>
            <p class="cmp-at-card-sub">Permanently delete expired subscriptions and daily meal logs older than your selected threshold. <strong>Customer accounts and WooCommerce orders are preserved.</strong></p>
            <form method="POST" action="">
                <?php wp_nonce_field( 'cmp_cleanup_action', 'cmp_cleanup_nonce' ); ?>
                <div class="cmp-at-field">
                    <label class="cmp-at-label">
                        <span>Target Timeframe (Minimum 30 Days)</span>
                        <?php echo cmp_admin_tools_help_tip( 'Only subscriptions whose expiry date passed more than this many days ago will be removed.' ); ?>
                    </label>
                    <div style="display:flex; align-items:center; gap:10px;">
                        <span>Delete plans expired more than</span>
                        <input type="number" name="days_old" id="days_old" value="90" min="30" max="3650" class="cmp-at-input" style="max-width:110px !important;">
                        <span>days ago.</span>
                    </div>
                </div>
                <label class="cmp-at-check-card" style="border-color:#fecaca; background:#fff1f2;">
                    <input type="checkbox" name="confirm_delete" value="1" required>
                    <div>
                        <span class="cmp-at-check-title" style="color:#991b1b;">I understand this action is permanent and irreversible</span>
                        <span class="cmp-at-check-desc">Active and paused subscriptions will not be touched.</span>
                    </div>
                </label>
                <button type="submit" name="cmp_run_cleanup" class="cmp-at-btn cmp-at-btn-danger" style="margin-top:10px;">Permanently Delete Old Records</button>
            </form>
        </div>
    </div>
    <?php
}

// ==========================================
// 5. WP ADMIN SUBMENU: DEPOSIT MANAGER TAB
// ==========================================
function cmp_standalone_render_deposit_manager() {
    if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'menu_manager' ) && ! current_user_can( 'accounts_team' ) && ! current_user_can( 'foh_manager' ) ) {
        wp_die( 'Access Denied: You do not have permission to manage deposits.' );
    }

    cmp_admin_tools_render_ecosystem_styles_and_scripts();

    global $wpdb;
    $message     = '';
    $found_users = array();

    if ( isset( $_POST['cmp_generate_magic_link'] ) && check_admin_referer( 'cmp_deposit_action', 'cmp_deposit_nonce' ) ) {
        $links = get_option( 'cmp_active_magic_links', array() );
        $token = substr( str_shuffle( 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789' ), 0, 8 );
        $links[ $token ] = array(
            'date' => current_time( 'mysql' ),
            'by'   => wp_get_current_user()->display_name,
        );
        update_option( 'cmp_active_magic_links', $links );
        $message = '<div class="cmp-at-alert success">&#10003; Single-use "Own Bag" VIP link generated successfully.</div>';
    }

    if ( isset( $_POST['cmp_revoke_magic_link'] ) && check_admin_referer( 'cmp_deposit_action', 'cmp_deposit_nonce' ) ) {
        $token_to_revoke = sanitize_text_field( $_POST['revoke_token'] );
        $links           = get_option( 'cmp_active_magic_links', array() );
        if ( isset( $links[ $token_to_revoke ] ) ) {
            unset( $links[ $token_to_revoke ] );
            update_option( 'cmp_active_magic_links', $links );
            $message = '<div class="cmp-at-alert success">&#10003; VIP Magic link revoked.</div>';
        }
    }

    if ( isset( $_POST['cmp_toggle_deposit'] ) && check_admin_referer( 'cmp_deposit_action', 'cmp_deposit_nonce' ) ) {
        $user_id    = intval( $_POST['user_id'] );
        $new_status = sanitize_text_field( $_POST['new_status'] );
        update_user_meta( $user_id, '_cmp_deposit_held', $new_status );
        $message = '<div class="cmp-at-alert success">&#10003; Customer deposit wallet status updated.</div>';

        $found_users[] = get_userdata( $user_id );
    }

    $raw_query = isset( $_POST['search_term'] ) ? sanitize_text_field( $_POST['search_term'] ) : '';

    if ( ! empty( $raw_query ) && isset( $_POST['cmp_search_user'] ) ) {
        $user_query = new WP_User_Query( array(
            'search'         => '*' . $raw_query . '*',
            'search_columns' => array( 'user_login', 'user_email', 'user_nicename', 'display_name' ),
            'number'         => 20,
        ) );
        $found_users = $user_query->get_results();

        if ( empty( $found_users ) ) {
            $meta_query = new WP_User_Query( array(
                'meta_query' => array(
                    'relation' => 'OR',
                    array( 'key' => 'first_name', 'value' => $raw_query, 'compare' => 'LIKE' ),
                    array( 'key' => 'last_name', 'value' => $raw_query, 'compare' => 'LIKE' ),
                    array( 'key' => 'billing_phone', 'value' => $raw_query, 'compare' => 'LIKE' ),
                ),
                'number' => 20,
            ) );
            $found_users = $meta_query->get_results();
        }

        if ( empty( $found_users ) ) {
            $message = '<div class="cmp-at-alert error">No customer found matching "' . esc_html( $raw_query ) . '".</div>';
        }
    }

    ?>
    <div class="wrap cmp-at-shell">
        <div class="cmp-at-header-banner is-standalone-header">
            <div>
                <h1>Customer Deposit Wallet &amp; VIP Links</h1>
                <p>The Cycle Bistro &mdash; Manage Thermal Bag Deposits &amp; Single-Use "Own Bag" Links</p>
            </div>
        </div>
        <?php echo $message; ?>

        <div class="cmp-at-grid-2col">
            <div class="cmp-at-card accent-amber">
                <div class="cmp-at-card-head">
                    <h3>
                        <span>&#128092; Search Customer Deposit Wallet</span>
                        <?php echo cmp_admin_tools_help_tip( 'Search by customer name, email, or phone number to check or update whether their AED 150 Thermal Bag Deposit is Held, Own Bag, or Refunded.' ); ?>
                    </h3>
                </div>
                <form method="POST" style="display: flex; gap: 10px; margin-bottom: 20px;">
                    <input type="text" name="search_term" class="cmp-at-input" placeholder="Search by Name, Email, or Phone..." value="<?php echo esc_attr( $raw_query ); ?>" required>
                    <button type="submit" name="cmp_search_user" class="cmp-at-btn cmp-at-btn-amber">Search</button>
                </form>

                <?php if ( ! empty( $found_users ) ) :
                    foreach ( $found_users as $searched_user ) :
                        $deposit_held = get_user_meta( $searched_user->ID, '_cmp_deposit_held', true );
                        if ( $deposit_held === '' ) {
                            $past_plans   = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}cmp_subscriptions WHERE user_id = %d AND status IN ('active','paused','completed')", $searched_user->ID ) );
                            $deposit_held = ( intval( $past_plans ) > 0 ) ? 'yes' : 'no';
                        }

                        $status_color = '#dc2626';
                        $status_bg    = '#fee2e2';
                        $status_text  = 'Refunded / No Deposit (Charge AED 150)';
                        if ( $deposit_held === 'yes' ) {
                            $status_color = '#166534';
                            $status_bg    = '#dcfce7';
                            $status_text  = 'Bag Deposit Held (Waived)';
                        } elseif ( $deposit_held === 'own_bag' ) {
                            $status_color = '#6d28d9';
                            $status_bg    = '#ede9fe';
                            $status_text  = "Customer's Own Bag (Waived)";
                        }
                ?>
                    <div class="cmp-at-plan-item" style="border-left: 4px solid <?php echo esc_attr( $status_color ); ?>;">
                        <div class="cmp-at-plan-title"><?php echo esc_html( trim( $searched_user->first_name . ' ' . $searched_user->last_name ) ?: $searched_user->display_name ); ?></div>
                        <div class="cmp-at-plan-meta" style="margin-top:4px;">
                            <span><?php echo esc_html( $searched_user->user_email ); ?></span>
                            <?php $phone = get_user_meta( $searched_user->ID, 'billing_phone', true ); if ( $phone ) : ?>
                                <span>&bull; <?php echo esc_html( $phone ); ?></span>
                            <?php endif; ?>
                        </div>
                        <div style="margin-bottom:12px;">
                            <span class="cmp-at-badge" style="background:<?php echo esc_attr( $status_bg ); ?>; color:<?php echo esc_attr( $status_color ); ?>;">
                                <?php echo esc_html( $status_text ); ?>
                            </span>
                        </div>
                        <form method="POST" style="display:flex; gap:8px; align-items:center;">
                            <?php wp_nonce_field( 'cmp_deposit_action', 'cmp_deposit_nonce' ); ?>
                            <input type="hidden" name="user_id" value="<?php echo esc_attr( $searched_user->ID ); ?>">
                            <select name="new_status" class="cmp-at-select" style="flex:1;">
                                <option value="yes" <?php selected( $deposit_held, 'yes' ); ?>>Deposit Held (Waive AED 150)</option>
                                <option value="own_bag" <?php selected( $deposit_held, 'own_bag' ); ?>>Customer's Own Bag (Waive AED 150)</option>
                                <option value="no" <?php selected( $deposit_held, 'no' ); ?>>Refunded / No Deposit (Charge AED 150)</option>
                            </select>
                            <button type="submit" name="cmp_toggle_deposit" class="cmp-at-btn cmp-at-btn-primary">Update</button>
                        </form>
                    </div>
                <?php endforeach; endif; ?>
            </div>

            <div class="cmp-at-card accent-purple">
                <div class="cmp-at-card-head">
                    <h3>
                        <span style="color:#6d28d9;">&#127915; "Own Bag" VIP Magic Links</span>
                        <?php echo cmp_admin_tools_help_tip( 'Generates a single-use checkout link (?vip_token=...) that waives the AED 150 bag deposit for a customer using their own thermal bag. Automatically expires once paid.' ); ?>
                    </h3>
                </div>
                <p class="cmp-at-card-sub">Generate a single-use checkout link that waives the AED 150 deposit and tags the order as "Own Bag".</p>

                <form method="POST" style="margin-bottom: 20px;">
                    <?php wp_nonce_field( 'cmp_deposit_action', 'cmp_deposit_nonce' ); ?>
                    <button type="submit" name="cmp_generate_magic_link" class="cmp-at-btn cmp-at-btn-purple" style="width:100%;">+ Generate New "Own Bag" VIP Link</button>
                </form>

                <?php
                $active_links = get_option( 'cmp_active_magic_links', array() );
                if ( ! empty( $active_links ) ) :
                    foreach ( $active_links as $token => $data ) :
                        $checkout_url = add_query_arg( 'vip_token', $token, cmp_admin_tools_get_checkout_base_url() );
                ?>
                    <div class="cmp-at-plan-item">
                        <div class="cmp-at-plan-url-box" style="margin-bottom:6px;">
                            <input type="text" value="<?php echo esc_url( $checkout_url ); ?>" readonly onclick="this.select();">
                            <button type="button" class="cmp-at-btn cmp-at-btn-outline" onclick="cmpAtCopyText('<?php echo esc_js( $checkout_url ); ?>', this, 'Copy VIP Link')">Copy VIP Link</button>
                        </div>
                        <div class="cmp-at-plan-actions">
                            <span style="font-size:0.75rem; color:#94a3b8;">Created by <?php echo esc_html( $data['by'] ); ?></span>
                            <form method="POST" style="margin:0;">
                                <?php wp_nonce_field( 'cmp_deposit_action', 'cmp_deposit_nonce' ); ?>
                                <input type="hidden" name="revoke_token" value="<?php echo esc_attr( $token ); ?>">
                                <button type="submit" name="cmp_revoke_magic_link" class="cmp-at-btn" style="background:#fff1f2 !important; color:#dc2626 !important; border:1px solid #fecaca !important; min-height:28px !important; padding:3px 10px !important; font-size:0.75rem !important;">Revoke</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; else : ?>
                    <p style="color:#94a3b8; font-style:italic; text-align:center;">No active VIP magic links.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php
}

// ==========================================
// 6. FRONTEND: MASTER ADMIN TOOLS PORTAL ([meal_admin_tools])
// ==========================================
add_shortcode( 'meal_admin_tools', 'cmp_render_frontend_admin_tools' );
function cmp_render_frontend_admin_tools() {
    if ( ! defined( 'DONOTCACHEPAGE' ) ) {
        define( 'DONOTCACHEPAGE', true );
    }

    $has_tools_access = current_user_can( 'manage_options' ) || current_user_can( 'menu_manager' ) || current_user_can( 'accounts_team' ) || current_user_can( 'foh_manager' );

    // 1. Ecosystem Glassmorphic Login Screen
    if ( ! is_user_logged_in() || ! $has_tools_access ) {
        if ( function_exists( 'cmp_render_portal_login_ui' ) ) {
            return cmp_render_portal_login_ui( array(
                'portal_id'      => 'admin_tools',
                'title'          => 'Admin Tools Portal',
                'subtitle'       => 'Custom Plan Generator, Subscriber Imports & Deposit Wallet',
                'required_caps'  => array( 'manage_options', 'menu_manager', 'foh_manager', 'accounts_team' ),
                'denied_message' => 'Authorized staff clearance is required to access Admin Tools.',
            ) );
        }
        return '<div style="max-width:460px; margin:40px auto; padding:30px; background:#fff; border-radius:14px; border:1.5px solid #DCECC9; text-align:center;"><h3>Admin Tools Login</h3>' . wp_login_form( array( 'echo' => false ) ) . '</div>';
    }

    cmp_admin_tools_ensure_logistics_columns();
    global $wpdb;
    $table_subs    = $wpdb->prefix . 'cmp_subscriptions';
    $table_logs    = $wpdb->prefix . 'cmp_daily_logs';
    $existing_cols = $wpdb->get_col( "DESC {$table_subs}", 0 );
    if ( ! is_array( $existing_cols ) ) {
        $existing_cols = array();
    }

    $custom_saved_timings = get_option( 'cmp_custom_delivery_timings', array() );
    if ( ! is_array( $custom_saved_timings ) ) $custom_saved_timings = array();
    $custom_saved_slots   = get_option( 'cmp_custom_time_slots', array() );
    if ( ! is_array( $custom_saved_slots ) ) $custom_saved_slots = array();

    $timing_options = array_values( array_unique( array_merge(
        array( 'Deliver Day Before', 'Deliver Same Day' ),
        $custom_saved_timings
    ) ) );
    $slot_options = array_values( array_unique( array_merge(
        array(
            '5:00 AM to 6:00 AM',
            '6:00 AM to 7:00 AM',
            '7:00 AM to 8:00 AM',
            '8:00 AM to 9:00 AM',
            '5:00 PM to 8:00 PM',
        ),
        $custom_saved_slots
    ) ) );

    $can_custom_plans = current_user_can( 'manage_options' ) || current_user_can( 'menu_manager' ) || current_user_can( 'foh_manager' );
    $can_import       = current_user_can( 'manage_options' ) || current_user_can( 'menu_manager' );
    $can_cleanup      = current_user_can( 'manage_options' );

    $active_tab = $can_custom_plans ? 'tab-custom-plans' : 'tab-deposit';
    if ( isset( $_GET['tools_tab'] ) ) {
        $req_tab = sanitize_text_field( wp_unslash( $_GET['tools_tab'] ) );
        if ( in_array( $req_tab, array( 'tab-custom-plans', 'tab-import', 'tab-deposit', 'tab-cleanup' ), true ) ) {
            $active_tab = $req_tab;
        }
    }

    $import_message  = '';
    $cleanup_message = '';
    $deposit_message = '';

    // 0. Handle Custom Plan Manager Actions (Frontend)
    $custom_plan_res = cmp_admin_tools_handle_custom_plan_actions( 'cmp_fe_custom_plan_action', 'cmp_fe_custom_plan_nonce' );
    if ( $custom_plan_res['triggered'] ) {
        $active_tab = 'tab-custom-plans';
    }

    // A. CSV Bulk Import (Frontend)
    if ( isset( $_POST['cmp_frontend_csv_import'] ) && wp_verify_nonce( $_POST['cmp_import_nonce'], 'cmp_frontend_import' ) && $can_import ) {
        $active_tab = 'tab-import';
        if ( ! empty( $_FILES['csv_file']['tmp_name'] ) ) {
            $file   = $_FILES['csv_file']['tmp_name'];
            $handle = fopen( $file, 'r' );
            if ( $handle !== false ) {
                $row_count     = 0;
                $success_count = 0;
                $failed_rows   = array();
                fgetcsv( $handle, 1000, ',' );
                while ( ( $data = fgetcsv( $handle, 1000, ',' ) ) !== false ) {
                    $row_count++;
                    $email          = isset( $data[0] ) ? sanitize_email( $data[0] ) : '';
                    $first_name     = isset( $data[1] ) ? sanitize_text_field( $data[1] ) : '';
                    $last_name      = isset( $data[2] ) ? sanitize_text_field( $data[2] ) : '';
                    $phone          = isset( $data[3] ) ? sanitize_text_field( $data[3] ) : '';
                    $address        = isset( $data[4] ) ? sanitize_text_field( $data[4] ) : '';
                    $method         = isset( $data[5] ) ? sanitize_text_field( $data[5] ) : '';
                    $timing         = isset( $data[6] ) ? sanitize_text_field( $data[6] ) : '';
                    $time_slot      = isset( $data[7] ) ? sanitize_text_field( $data[7] ) : '';
                    $allergies      = isset( $data[8] ) ? sanitize_text_field( $data[8] ) : '';
                    $plan_name      = isset( $data[9] ) ? sanitize_text_field( $data[9] ) : '';
                    $remaining_days = isset( $data[10] ) ? intval( $data[10] ) : 0;
                    $plan_price     = isset( $data[11] ) ? floatval( $data[11] ) : 0.00;
                    $discount_amt   = isset( $data[12] ) ? floatval( $data[12] ) : 0.00;

                    if ( empty( $email ) || empty( $plan_name ) || $remaining_days <= 0 ) {
                        $failed_rows[] = "Row $row_count: Missing Email, Plan Name, or Days.";
                        continue;
                    }

                    $user = get_user_by( 'email', $email );
                    if ( ! $user ) {
                        $password = wp_generate_password( 12, false );
                        $user_id  = wp_create_user( $email, $password, $email );
                        if ( is_wp_error( $user_id ) ) {
                            $failed_rows[] = "Row $row_count ($email): " . $user_id->get_error_message();
                            continue;
                        }
                    } else {
                        $user_id = $user->ID;
                    }

                    $existing_active_subs = intval( $wpdb->get_var( $wpdb->prepare(
                        "SELECT COUNT(*) FROM {$table_subs} WHERE user_id = %d AND status IN ('active','paused')",
                        $user_id
                    ) ) );

                    wp_update_user( array( 'ID' => $user_id, 'first_name' => $first_name, 'last_name' => $last_name ) );
                    if ( ! empty( $phone ) )   update_user_meta( $user_id, 'billing_phone', $phone );
                    if ( ! empty( $address ) ) update_user_meta( $user_id, 'billing_address_1', $address );

                    if ( $existing_active_subs === 0 ) {
                        if ( ! empty( $method ) )    update_user_meta( $user_id, 'delivery_method', $method );
                        if ( ! empty( $timing ) )    update_user_meta( $user_id, 'delivery_timing', $timing );
                        if ( ! empty( $time_slot ) ) update_user_meta( $user_id, 'time_slot', $time_slot );
                        update_user_meta( $user_id, 'allergies', $allergies );
                    }

                    $categories = ( stripos( $plan_name, 'juice' ) !== false || stripos( $plan_name, 'cleanse' ) !== false ) ? 'Juices' : 'Breakfast,Lunch,Dinner,Snacks';

                    $insert_data = array(
                        'user_id'            => $user_id,
                        'wc_order_id'        => 0,
                        'plan_name'          => $plan_name,
                        'total_days'         => $remaining_days,
                        'allowed_categories' => $categories,
                        'plan_price'         => $plan_price,
                        'discount_amount'    => $discount_amt,
                        'status'             => 'active',
                        'start_date'         => date( 'Y-m-d H:i:s' ),
                        'expiry_date'        => date( 'Y-m-d H:i:s', strtotime( "+$remaining_days days" ) ),
                    );

                    if ( in_array( 'delivery_method', $existing_cols, true ) )  $insert_data['delivery_method']  = $method;
                    if ( in_array( 'delivery_timing', $existing_cols, true ) )  $insert_data['delivery_timing']  = $timing;
                    if ( in_array( 'time_slot', $existing_cols, true ) )        $insert_data['time_slot']        = $time_slot;
                    if ( in_array( 'allergies', $existing_cols, true ) )        $insert_data['allergies']        = ( $allergies !== '' ) ? $allergies : '__NONE__';
                    if ( in_array( 'delivery_address', $existing_cols, true ) ) $insert_data['delivery_address'] = $address;

                    $inserted = $wpdb->insert( $table_subs, $insert_data );

                    if ( $inserted ) {
                        $new_sub_id = intval( $wpdb->insert_id );
                        $wpdb->update( $table_subs, array( 'wc_order_id' => -$new_sub_id ), array( 'id' => $new_sub_id ) );

                        cmp_admin_tools_record_logistics_profile(
                            $user_id,
                            $new_sub_id,
                            array(
                                'plan_name' => $plan_name,
                                'method'    => $method,
                                'pickup'    => '',
                                'timing'    => $timing,
                                'time_slot' => $time_slot,
                                'allergies' => $allergies,
                                'address'   => $address,
                            ),
                            ( $existing_active_subs === 0 )
                        );
                        $success_count++;
                    } else {
                        $failed_rows[] = "Row $row_count ($email): DB Error.";
                    }
                }
                fclose( $handle );

                if ( $success_count > 0 ) {
                    $import_message .= '<div class="cmp-at-alert success"><strong>Success!</strong> Imported ' . intval( $success_count ) . ' customers.</div>';
                }
                if ( ! empty( $failed_rows ) ) {
                    $import_message .= '<div class="cmp-at-alert error"><strong>Warning:</strong> Some rows failed:<br>' . implode( '<br>', array_map( 'esc_html', $failed_rows ) ) . '</div>';
                }
            } else {
                $import_message = '<div class="cmp-at-alert error">Error reading CSV.</div>';
            }
        }
    }

    // B. Manual Single Import (Frontend)
    if ( isset( $_POST['cmp_frontend_manual_import'] ) && wp_verify_nonce( $_POST['cmp_import_nonce'], 'cmp_frontend_import' ) && $can_import ) {
        $active_tab     = 'tab-import';
        $email          = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
        $first_name     = sanitize_text_field( wp_unslash( $_POST['first_name'] ?? '' ) );
        $last_name      = sanitize_text_field( wp_unslash( $_POST['last_name'] ?? '' ) );
        $phone          = sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) );
        $plan_name      = sanitize_text_field( wp_unslash( $_POST['plan_name'] ?? '' ) );
        $recipient_name = sanitize_text_field( wp_unslash( $_POST['recipient_name'] ?? '' ) );
        $remaining_days = intval( $_POST['remaining_days'] ?? 0 );
        $method         = sanitize_text_field( wp_unslash( $_POST['delivery_method'] ?? '' ) );
        $timing         = sanitize_text_field( wp_unslash( $_POST['delivery_timing'] ?? '' ) );
        $time_slot      = sanitize_text_field( wp_unslash( $_POST['time_slot'] ?? '' ) );
        $address        = sanitize_text_field( wp_unslash( $_POST['address'] ?? '' ) );
        $allergies      = sanitize_textarea_field( wp_unslash( $_POST['allergies'] ?? '' ) );
        $plan_price     = floatval( $_POST['plan_price'] ?? 0 );
        $discount_amt   = floatval( $_POST['discount_amount'] ?? 0 );
        $prep_timing    = sanitize_text_field( wp_unslash( $_POST['prep_timing'] ?? 'auto' ) );

        $plan_mode    = isset( $_POST['plan_mode'] ) ? sanitize_text_field( wp_unslash( $_POST['plan_mode'] ) ) : 'standard';
        $is_flexible  = ( $plan_mode === 'flexible' ) ? 1 : 0;
        $allow_custom = ( $plan_mode === 'custom' ) ? 1 : 0;

        if ( ! empty( $recipient_name ) ) {
            $plan_name .= ' - ' . $recipient_name;
        }

        if ( empty( $email ) || empty( $plan_name ) || $remaining_days <= 0 ) {
            $import_message = '<div class="cmp-at-alert error">Error: Email, Plan, and Days are required.</div>';
        } else {
            $user = get_user_by( 'email', $email );
            if ( ! $user ) {
                $password = wp_generate_password( 12, false );
                $user_id  = wp_create_user( $email, $password, $email );
            } else {
                $user_id = $user->ID;
            }

            if ( is_wp_error( $user_id ) ) {
                $import_message = '<div class="cmp-at-alert error">Error creating user: ' . esc_html( $user_id->get_error_message() ) . '</div>';
            } else {
                $existing_active_subs = intval( $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table_subs} WHERE user_id = %d AND status IN ('active','paused')",
                    $user_id
                ) ) );

                wp_update_user( array( 'ID' => $user_id, 'first_name' => $first_name, 'last_name' => $last_name ) );
                update_user_meta( $user_id, 'billing_phone',     $phone );
                update_user_meta( $user_id, 'billing_address_1', $address );

                if ( $existing_active_subs === 0 ) {
                    update_user_meta( $user_id, 'delivery_method', $method );
                    update_user_meta( $user_id, 'delivery_timing', $timing );
                    update_user_meta( $user_id, 'time_slot',       $time_slot );
                    update_user_meta( $user_id, 'allergies',       $allergies );
                }

                $categories = ( stripos( $plan_name, 'juice' ) !== false || stripos( $plan_name, 'cleanse' ) !== false ) ? 'Juices' : 'Breakfast,Lunch,Dinner,Snacks';

                $insert_data = array(
                    'user_id'            => $user_id,
                    'wc_order_id'        => 0,
                    'plan_name'          => $plan_name,
                    'total_days'         => $remaining_days,
                    'allowed_categories' => $categories,
                    'plan_price'         => $plan_price,
                    'discount_amount'    => $discount_amt,
                    'is_flexible'        => $is_flexible,
                    'allow_custom'       => $allow_custom,
                    'status'             => 'active',
                    'start_date'         => date( 'Y-m-d H:i:s' ),
                    'expiry_date'        => date( 'Y-m-d H:i:s', strtotime( "+$remaining_days days" ) ),
                );

                if ( in_array( 'delivery_method', $existing_cols, true ) )  $insert_data['delivery_method']  = $method;
                if ( in_array( 'delivery_timing', $existing_cols, true ) )  $insert_data['delivery_timing']  = $timing;
                if ( in_array( 'time_slot', $existing_cols, true ) )        $insert_data['time_slot']        = $time_slot;
                if ( in_array( 'allergies', $existing_cols, true ) )        $insert_data['allergies']        = ( $allergies !== '' ) ? $allergies : '__NONE__';
                if ( in_array( 'delivery_address', $existing_cols, true ) ) $insert_data['delivery_address'] = $address;
                if ( in_array( 'prep_timing', $existing_cols, true ) )      $insert_data['prep_timing']      = $prep_timing;

                $inserted = $wpdb->insert( $table_subs, $insert_data );

                if ( $inserted ) {
                    $new_sub_id = intval( $wpdb->insert_id );
                    $wpdb->update( $table_subs, array( 'wc_order_id' => -$new_sub_id ), array( 'id' => $new_sub_id ) );

                    cmp_admin_tools_record_logistics_profile(
                        $user_id,
                        $new_sub_id,
                        array(
                            'plan_name' => $plan_name,
                            'method'    => $method,
                            'pickup'    => '',
                            'timing'    => $timing,
                            'time_slot' => $time_slot,
                            'allergies' => $allergies,
                            'address'   => $address,
                        ),
                        ( $existing_active_subs === 0 )
                    );

                    $import_message = '<div class="cmp-at-alert success"><strong>Success!</strong> ' . esc_html( $first_name ) . ' added with independent plan logistics.</div>';
                } else {
                    $import_message = '<div class="cmp-at-alert error">Database Error.</div>';
                }
            }
        }
    }

    // C. Database Cleanup (Frontend)
    if ( isset( $_POST['cmp_frontend_run_cleanup'] ) && wp_verify_nonce( $_POST['cmp_cleanup_nonce'], 'cmp_frontend_cleanup' ) && $can_cleanup ) {
        $active_tab = 'tab-cleanup';
        $days_old   = intval( $_POST['days_old'] );
        $confirm    = isset( $_POST['confirm_delete'] );
        if ( $days_old < 30 ) {
            $cleanup_message = '<div class="cmp-at-alert error">Minimum 30 days required.</div>';
        } elseif ( ! $confirm ) {
            $cleanup_message = '<div class="cmp-at-alert error">Please check the confirmation box.</div>';
        } else {
            $cutoff_date    = date( 'Y-m-d H:i:s', strtotime( "-$days_old days" ) );
            $subs_to_delete = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM $table_subs WHERE expiry_date < %s", $cutoff_date ) );
            if ( empty( $subs_to_delete ) ) {
                $cleanup_message = '<div class="cmp-at-alert success">No old expired subscriptions found. Database is clean.</div>';
            } else {
                $ids_list        = implode( ',', array_map( 'intval', $subs_to_delete ) );
                $logs_deleted    = $wpdb->query( "DELETE FROM $table_logs WHERE subscription_id IN ($ids_list)" );
                $subs_deleted    = $wpdb->query( "DELETE FROM $table_subs WHERE id IN ($ids_list)" );
                $cleanup_message = '<div class="cmp-at-alert success"><strong>Success!</strong> Deleted ' . intval( $subs_deleted ) . ' expired plans and ' . intval( $logs_deleted ) . ' logs.</div>';
            }
        }
    }

    // D. Deposit Manager (Frontend)
    $found_users = array();
    $raw_query   = '';

    if ( isset( $_POST['cmp_frontend_generate_magic_link'] ) && wp_verify_nonce( $_POST['cmp_deposit_nonce'], 'cmp_frontend_deposit' ) ) {
        $active_tab = 'tab-deposit';
        $links      = get_option( 'cmp_active_magic_links', array() );
        $token      = substr( str_shuffle( 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789' ), 0, 8 );
        $links[ $token ] = array(
            'date' => current_time( 'mysql' ),
            'by'   => wp_get_current_user()->display_name,
        );
        update_option( 'cmp_active_magic_links', $links );
        $deposit_message = '<div class="cmp-at-alert success"><strong>Success!</strong> Single-use VIP magic link generated.</div>';
    }

    if ( isset( $_POST['cmp_frontend_revoke_magic_link'] ) && wp_verify_nonce( $_POST['cmp_deposit_nonce'], 'cmp_frontend_deposit' ) ) {
        $active_tab      = 'tab-deposit';
        $token_to_revoke = sanitize_text_field( $_POST['revoke_token'] );
        $links           = get_option( 'cmp_active_magic_links', array() );
        if ( isset( $links[ $token_to_revoke ] ) ) {
            unset( $links[ $token_to_revoke ] );
            update_option( 'cmp_active_magic_links', $links );
            $deposit_message = '<div class="cmp-at-alert success"><strong>Success!</strong> Magic link revoked.</div>';
        }
    }

    if ( isset( $_POST['cmp_frontend_search_deposit'] ) && wp_verify_nonce( $_POST['cmp_deposit_nonce'], 'cmp_frontend_deposit' ) ) {
        $active_tab = 'tab-deposit';
        $raw_query  = sanitize_text_field( $_POST['search_term'] );

        $user_query = new WP_User_Query( array(
            'search'         => '*' . $raw_query . '*',
            'search_columns' => array( 'user_login', 'user_email', 'user_nicename', 'display_name' ),
            'number'         => 20,
        ) );
        $found_users = $user_query->get_results();

        if ( empty( $found_users ) ) {
            $meta_query = new WP_User_Query( array(
                'meta_query' => array(
                    'relation' => 'OR',
                    array( 'key' => 'first_name', 'value' => $raw_query, 'compare' => 'LIKE' ),
                    array( 'key' => 'last_name', 'value' => $raw_query, 'compare' => 'LIKE' ),
                    array( 'key' => 'billing_phone', 'value' => $raw_query, 'compare' => 'LIKE' ),
                ),
                'number' => 20,
            ) );
            $found_users = $meta_query->get_results();
        }

        if ( empty( $found_users ) ) {
            $deposit_message = '<div class="cmp-at-alert error">No customer found matching "' . esc_html( $raw_query ) . '".</div>';
        }
    }

    if ( isset( $_POST['cmp_frontend_toggle_deposit'] ) && wp_verify_nonce( $_POST['cmp_deposit_nonce'], 'cmp_frontend_deposit' ) ) {
        $active_tab      = 'tab-deposit';
        $user_id         = intval( $_POST['user_id'] );
        $new_status      = sanitize_text_field( $_POST['new_status'] );
        update_user_meta( $user_id, '_cmp_deposit_held', $new_status );
        $deposit_message = '<div class="cmp-at-alert success"><strong>Success!</strong> Deposit wallet status updated.</div>';

        $found_users[] = get_userdata( $user_id );
    }

    ob_start();
    cmp_admin_tools_render_ecosystem_styles_and_scripts();
    ?>

    <div class="cmp-at-shell">
        <!-- ECOSYSTEM HEADER BANNER -->
        <div class="cmp-at-header-banner">
            <div>
                <h2>Admin Tools Command Center</h2>
                <p>The Cycle Bistro &mdash; Custom Plan Manager, Subscriber Imports &amp; Deposit Operations</p>
            </div>
            <div class="cmp-at-hdr-actions">
                <a href="<?php echo esc_url( site_url( '/menu-manager-portal/' ) ); ?>" class="cmp-at-hdr-btn">Menu Manager &nearr;</a>
                <a href="<?php echo esc_url( site_url( '/foh-admin-portal/' ) ); ?>" class="cmp-at-hdr-btn">FOH Portal &nearr;</a>
                <a href="<?php echo esc_url( wp_logout_url( get_permalink() ) ); ?>" class="cmp-at-hdr-btn btn-logout">Log Out</a>
            </div>
        </div>

        <!-- ECOSYSTEM TAB NAVIGATION -->
        <div class="cmp-at-tab-nav">
            <?php if ( $can_custom_plans ) : ?>
                <button type="button" class="cmp-at-tab-btn <?php echo $active_tab === 'tab-custom-plans' ? 'active' : ''; ?>" onclick="switchCmpAtTab(event, 'tab-custom-plans')">
                    <span>&#10024; Custom Plan Manager</span>
                </button>
            <?php endif; ?>

            <?php if ( $can_import ) : ?>
                <button type="button" class="cmp-at-tab-btn <?php echo $active_tab === 'tab-import' ? 'active' : ''; ?>" onclick="switchCmpAtTab(event, 'tab-import')">
                    <span>&#128229; Import Subscribers</span>
                </button>
            <?php endif; ?>

            <button type="button" class="cmp-at-tab-btn <?php echo $active_tab === 'tab-deposit' ? 'active' : ''; ?>" onclick="switchCmpAtTab(event, 'tab-deposit')">
                <span>&#128092; Deposit Manager &amp; VIP Links</span>
            </button>

            <?php if ( $can_cleanup ) : ?>
                <button type="button" class="cmp-at-tab-btn <?php echo $active_tab === 'tab-cleanup' ? 'active' : ''; ?>" onclick="switchCmpAtTab(event, 'tab-cleanup')">
                    <span>&#129529; Database Cleanup</span>
                </button>
            <?php endif; ?>
        </div>

        <!-- =========================================================
             TAB 1: CUSTOM PLAN MANAGER (OPTION CPG-1)
             ========================================================= -->
        <?php if ( $can_custom_plans ) : ?>
        <div id="tab-custom-plans" class="cmp-at-tab-pane <?php echo $active_tab === 'tab-custom-plans' ? 'active' : ''; ?>">
            <?php echo $custom_plan_res['html']; ?>
            <?php cmp_admin_tools_render_custom_plan_manager_section( 'cmp_fe_custom_plan_action', 'cmp_fe_custom_plan_nonce', 'fe' ); ?>
        </div>
        <?php endif; ?>

        <!-- =========================================================
             TAB 2: IMPORT SUBSCRIBERS (MANUAL & BULK CSV)
             ========================================================= -->
        <?php if ( $can_import ) : ?>
        <div id="tab-import" class="cmp-at-tab-pane <?php echo $active_tab === 'tab-import' ? 'active' : ''; ?>">
            <?php echo $import_message; ?>

            <div class="cmp-at-card">
                <div class="cmp-at-card-head">
                    <h3>
                        <span>&#128196; Bulk CSV Import</span>
                        <?php echo cmp_admin_tools_help_tip( 'Upload a CSV matching the template headers to import multiple active subscribers at once.' ); ?>
                    </h3>
                    <a href="?cmp_download_template=1" class="cmp-at-btn cmp-at-btn-outline">Download CSV Template</a>
                </div>
                <p class="cmp-at-card-sub">Upload a CSV to import multiple subscribers. Family recipients can be specified by appending their name to the Plan Name (e.g., "2 Meal Plan - Sarah").</p>
                <form method="POST" enctype="multipart/form-data" style="display:flex; gap:14px; align-items:center; flex-wrap:wrap; background:#f8fafc; padding:16px; border:1.5px dashed #cbd5e1; border-radius:12px;">
                    <?php wp_nonce_field( 'cmp_frontend_import', 'cmp_import_nonce' ); ?>
                    <input type="file" name="csv_file" accept=".csv" required style="flex:1; min-width:220px;">
                    <button type="submit" name="cmp_frontend_csv_import" class="cmp-at-btn cmp-at-btn-primary">Upload &amp; Process CSV</button>
                </form>
            </div>

            <div class="cmp-at-card accent-blue">
                <div class="cmp-at-card-head">
                    <h3>
                        <span>&#128100; Single Manual Subscriber Entry</span>
                        <?php echo cmp_admin_tools_help_tip( 'Directly create an active subscription record for a customer with isolated per-plan delivery logistics and allergies.' ); ?>
                    </h3>
                </div>
                <form method="POST">
                    <?php wp_nonce_field( 'cmp_frontend_import', 'cmp_import_nonce' ); ?>
                    <div class="cmp-at-row">
                        <div class="cmp-at-field">
                            <label class="cmp-at-label">Email *</label>
                            <input type="email" name="email" required class="cmp-at-input" placeholder="customer@example.com">
                        </div>
                        <div class="cmp-at-field">
                            <label class="cmp-at-label">Phone *</label>
                            <input type="text" name="phone" required class="cmp-at-input" placeholder="0501234567">
                        </div>
                    </div>
                    <div class="cmp-at-row">
                        <div class="cmp-at-field">
                            <label class="cmp-at-label">First Name *</label>
                            <input type="text" name="first_name" required class="cmp-at-input">
                        </div>
                        <div class="cmp-at-field">
                            <label class="cmp-at-label">Last Name *</label>
                            <input type="text" name="last_name" required class="cmp-at-input">
                        </div>
                    </div>
                    <div class="cmp-at-field">
                        <label class="cmp-at-label">Delivery Address</label>
                        <input type="text" name="address" class="cmp-at-input" placeholder="Villa / Apartment, Street, Area in Dubai">
                    </div>

                    <div class="cmp-at-row-3">
                        <div class="cmp-at-field">
                            <label class="cmp-at-label">Delivery Method</label>
                            <select name="delivery_method" class="cmp-at-select">
                                <option value="Delivery">Home/Office Delivery</option>
                                <option value="Pickup">Store Pick-up</option>
                            </select>
                        </div>
                        <div class="cmp-at-field">
                            <label class="cmp-at-label">Receive By (Plan-Specific)</label>
                            <select name="delivery_timing" class="cmp-at-select">
                                <?php foreach ( $timing_options as $t_opt ) : ?>
                                    <option value="<?php echo esc_attr( $t_opt ); ?>"><?php echo esc_html( $t_opt ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="cmp-at-field">
                            <label class="cmp-at-label">Time Slot (Plan-Specific)</label>
                            <select name="time_slot" class="cmp-at-select">
                                <?php foreach ( $slot_options as $s_opt ) : ?>
                                    <option value="<?php echo esc_attr( $s_opt ); ?>" <?php selected( $s_opt, '8:00 AM to 9:00 AM' ); ?>><?php echo esc_html( $s_opt ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="cmp-at-field">
                        <label class="cmp-at-label">Allergies (Plan-Specific)</label>
                        <input type="text" name="allergies" placeholder="e.g., Nuts, Shellfish (Leave blank if none)" class="cmp-at-input">
                    </div>

                    <div class="cmp-at-row-3">
                        <div class="cmp-at-field">
                            <label class="cmp-at-label">Select Plan Quota *</label>
                            <select name="plan_name" class="cmp-at-select">
                                <option value="1 Meal Plan (Manual)">1 Meal Plan</option>
                                <option value="2 Meal Plan (Manual)">2 Meal Plan</option>
                                <option value="3 Meal Plan (Manual)">3 Meal Plan</option>
                                <option value="Juice Cleanse (Manual)">Juice Cleanse</option>
                            </select>
                        </div>
                        <div class="cmp-at-field">
                            <label class="cmp-at-label">
                                <span>Recipient Name (Optional)</span>
                                <?php echo cmp_admin_tools_help_tip( 'Enter a recipient name (e.g., Sarah) if this is a family member or second plan on the same account.' ); ?>
                            </label>
                            <input type="text" name="recipient_name" class="cmp-at-input" placeholder="e.g. Sarah">
                        </div>
                        <div class="cmp-at-field">
                            <label class="cmp-at-label">Remaining Days *</label>
                            <input type="number" name="remaining_days" required min="1" class="cmp-at-input" placeholder="e.g. 14">
                        </div>
                    </div>

                    <div class="cmp-at-row">
                        <div class="cmp-at-field">
                            <label class="cmp-at-label">Plan Price (AED) *</label>
                            <input type="number" step="0.01" name="plan_price" required min="0" class="cmp-at-input" value="0.00">
                        </div>
                        <div class="cmp-at-field">
                            <label class="cmp-at-label">Discount Amount (AED)</label>
                            <input type="number" step="0.01" name="discount_amount" min="0" class="cmp-at-input" value="0.00">
                        </div>
                    </div>

                    <div class="cmp-at-row">
                        <div class="cmp-at-field">
                            <label class="cmp-at-label">
                                <span>Plan Mode (Menu Selection Type)</span>
                                <?php echo cmp_admin_tools_help_tip( 'Standard restricts to Breakfast/Lunch/Dinner. Flexible allows picking any combination. Custom replaces dropdowns with text inputs.' ); ?>
                            </label>
                            <select name="plan_mode" class="cmp-at-select">
                                <option value="standard">Standard Menu (Strict Categories)</option>
                                <option value="flexible">Flexible Menu (Universal Dropdowns)</option>
                                <option value="custom">Bespoke Custom (Text Inputs)</option>
                            </select>
                        </div>
                        <div class="cmp-at-field">
                            <label class="cmp-at-label">
                                <span>Kitchen Prep Schedule</span>
                                <?php echo cmp_admin_tools_help_tip( 'Auto applies Same-Day Preparation when Receive By is Same Day and Time Slot is 4:00 PM or later.' ); ?>
                            </label>
                            <select name="prep_timing" class="cmp-at-select">
                                <option value="auto">Auto (Same-Day if Same Day &ge; 4:00 PM)</option>
                                <option value="same_day">Same-Day Prep (Manual Override)</option>
                                <option value="day_before">Day-Before Prep (Manual Override)</option>
                            </select>
                        </div>
                    </div>

                    <button type="submit" name="cmp_frontend_manual_import" class="cmp-at-btn cmp-at-btn-primary">Import Single Customer</button>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <!-- =========================================================
             TAB 3: DEPOSIT MANAGER & VIP MAGIC LINKS
             ========================================================= -->
        <div id="tab-deposit" class="cmp-at-tab-pane <?php echo $active_tab === 'tab-deposit' ? 'active' : ''; ?>">
            <?php echo $deposit_message; ?>

            <div class="cmp-at-grid-2col">
                <div class="cmp-at-card accent-amber">
                    <div class="cmp-at-card-head">
                        <h3>
                            <span>&#128092; Customer Bag Deposit Wallet</span>
                            <?php echo cmp_admin_tools_help_tip( 'Search by Name, Email, or Phone to view or update a customer\'s AED 150 Thermal Bag Deposit status.' ); ?>
                        </h3>
                    </div>
                    <p class="cmp-at-card-sub">Search by Name, Email, or Phone to view or update a customer's Bag Deposit Wallet.</p>

                    <form method="POST" style="display:flex; gap:10px; margin-bottom:20px;">
                        <?php wp_nonce_field( 'cmp_frontend_deposit', 'cmp_deposit_nonce' ); ?>
                        <input type="text" name="search_term" class="cmp-at-input" placeholder="Search by Name, Email, or Phone Number..." value="<?php echo esc_attr( $raw_query ); ?>" required>
                        <button type="submit" name="cmp_frontend_search_deposit" class="cmp-at-btn cmp-at-btn-amber">Search</button>
                    </form>

                    <?php if ( ! empty( $found_users ) ) :
                        foreach ( $found_users as $searched_user ) :
                            $deposit_held = get_user_meta( $searched_user->ID, '_cmp_deposit_held', true );
                            if ( $deposit_held === '' ) {
                                $past_plans   = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}cmp_subscriptions WHERE user_id = %d AND status IN ('active','paused','completed')", $searched_user->ID ) );
                                $deposit_held = ( intval( $past_plans ) > 0 ) ? 'yes' : 'no';
                            }

                            $status_color = '#dc2626';
                            $status_bg    = '#fee2e2';
                            $status_text  = 'Refunded / No Deposit (Charge AED 150)';
                            if ( $deposit_held === 'yes' ) {
                                $status_color = '#166534';
                                $status_bg    = '#dcfce7';
                                $status_text  = 'Bag Deposit Held (Waived)';
                            } elseif ( $deposit_held === 'own_bag' ) {
                                $status_color = '#6d28d9';
                                $status_bg    = '#ede9fe';
                                $status_text  = "Customer's Own Bag (Waived)";
                            }
                    ?>
                        <div class="cmp-at-plan-item" style="border-left: 4px solid <?php echo esc_attr( $status_color ); ?>;">
                            <div class="cmp-at-plan-title"><?php echo esc_html( trim( $searched_user->first_name . ' ' . $searched_user->last_name ) ?: $searched_user->display_name ); ?></div>
                            <div class="cmp-at-plan-meta" style="margin-top:4px;">
                                <span><?php echo esc_html( $searched_user->user_email ); ?></span>
                                <?php $phone = get_user_meta( $searched_user->ID, 'billing_phone', true ); if ( $phone ) : ?>
                                    <span>&bull; <?php echo esc_html( $phone ); ?></span>
                                <?php endif; ?>
                            </div>
                            <div style="margin-bottom:12px;">
                                <span class="cmp-at-badge" style="background:<?php echo esc_attr( $status_bg ); ?>; color:<?php echo esc_attr( $status_color ); ?>;">
                                    <?php echo esc_html( $status_text ); ?>
                                </span>
                            </div>
                            <form method="POST" style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                                <?php wp_nonce_field( 'cmp_frontend_deposit', 'cmp_deposit_nonce' ); ?>
                                <input type="hidden" name="search_term" value="<?php echo esc_attr( $raw_query ); ?>">
                                <input type="hidden" name="user_id" value="<?php echo esc_attr( $searched_user->ID ); ?>">
                                <select name="new_status" class="cmp-at-select" style="flex:1;">
                                    <option value="yes" <?php selected( $deposit_held, 'yes' ); ?>>Deposit Held (Waived)</option>
                                    <option value="own_bag" <?php selected( $deposit_held, 'own_bag' ); ?>>Customer's Own Bag (Waived)</option>
                                    <option value="no" <?php selected( $deposit_held, 'no' ); ?>>Refunded / No Deposit</option>
                                </select>
                                <button type="submit" name="cmp_frontend_toggle_deposit" class="cmp-at-btn cmp-at-btn-primary">Update Wallet</button>
                            </form>
                        </div>
                    <?php endforeach; endif; ?>
                </div>

                <div class="cmp-at-card accent-purple">
                    <div class="cmp-at-card-head">
                        <h3>
                            <span style="color:#6d28d9;">&#127915; "Own Bag" VIP Links</span>
                            <?php echo cmp_admin_tools_help_tip( 'Generates a single-use checkout link that automatically waives the AED 150 deposit for a customer using their own thermal bag.' ); ?>
                        </h3>
                    </div>
                    <p class="cmp-at-card-sub">Generate secure, single-use checkout links that waive the AED 150 deposit and tag the customer as "Own Bag". The link auto-destroys once payment succeeds.</p>

                    <form method="POST" style="margin-bottom:20px;">
                        <?php wp_nonce_field( 'cmp_frontend_deposit', 'cmp_deposit_nonce' ); ?>
                        <button type="submit" name="cmp_frontend_generate_magic_link" class="cmp-at-btn cmp-at-btn-purple" style="width:100%;">+ Generate New VIP Link</button>
                    </form>

                    <?php
                    $active_links = get_option( 'cmp_active_magic_links', array() );
                    if ( ! empty( $active_links ) ) :
                        foreach ( $active_links as $token => $data ) :
                            $checkout_url = add_query_arg( 'vip_token', $token, cmp_admin_tools_get_checkout_base_url() );
                    ?>
                        <div class="cmp-at-plan-item">
                            <div class="cmp-at-plan-url-box" style="margin-bottom:6px;">
                                <input type="text" value="<?php echo esc_url( $checkout_url ); ?>" readonly onclick="this.select();">
                                <button type="button" class="cmp-at-btn cmp-at-btn-outline" onclick="cmpAtCopyText('<?php echo esc_js( $checkout_url ); ?>', this, 'Copy Link')">Copy Link</button>
                            </div>
                            <div class="cmp-at-plan-actions">
                                <span style="font-size:0.75rem; color:#94a3b8;">Created by <?php echo esc_html( $data['by'] ); ?></span>
                                <form method="POST" style="margin:0;">
                                    <?php wp_nonce_field( 'cmp_frontend_deposit', 'cmp_deposit_nonce' ); ?>
                                    <input type="hidden" name="revoke_token" value="<?php echo esc_attr( $token ); ?>">
                                    <button type="submit" name="cmp_frontend_revoke_magic_link" class="cmp-at-btn" style="background:#fff1f2 !important; color:#dc2626 !important; border:1px solid #fecaca !important; min-height:28px !important; padding:3px 10px !important; font-size:0.75rem !important;">Revoke</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; else : ?>
                        <p style="color:#94a3b8; font-style:italic; text-align:center;">No active VIP links.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- =========================================================
             TAB 4: DATABASE CLEANUP (SUPER ADMIN ONLY)
             ========================================================= -->
        <?php if ( $can_cleanup ) : ?>
        <div id="tab-cleanup" class="cmp-at-tab-pane <?php echo $active_tab === 'tab-cleanup' ? 'active' : ''; ?>">
            <?php echo $cleanup_message; ?>
            <div class="cmp-at-card accent-red" style="max-width:720px;">
                <div class="cmp-at-card-head">
                    <h3 style="color:#dc2626 !important;">&#129529; Purge Old Expired Subscription Data</h3>
                </div>
                <p class="cmp-at-card-sub">Permanently delete expired subscriptions and daily logs older than your threshold. Customer accounts and WooCommerce orders are preserved.</p>
                <form method="POST">
                    <?php wp_nonce_field( 'cmp_frontend_cleanup', 'cmp_cleanup_nonce' ); ?>
                    <div class="cmp-at-field">
                        <label class="cmp-at-label">Target Timeframe (Min 30 Days)</label>
                        <div style="display:flex; align-items:center; gap:10px;">
                            <span>Delete plans expired more than</span>
                            <input type="number" name="days_old" value="90" min="30" class="cmp-at-input" style="max-width:110px !important;">
                            <span>days ago.</span>
                        </div>
                    </div>
                    <label class="cmp-at-check-card" style="border-color:#fecaca; background:#fff1f2;">
                        <input type="checkbox" name="confirm_delete" value="1" required>
                        <div>
                            <span class="cmp-at-check-title" style="color:#991b1b;">I understand this action is irreversible</span>
                            <span class="cmp-at-check-desc">Active and paused subscriptions are never deleted by this tool.</span>
                        </div>
                    </label>
                    <button type="submit" name="cmp_frontend_run_cleanup" class="cmp-at-btn cmp-at-btn-danger" style="margin-top:8px;">Permanently Delete Old Records</button>
                </form>
            </div>
        </div>
        <?php endif; ?>

    </div>

    <script>
    function switchCmpAtTab(evt, tabId) {
        document.querySelectorAll(".cmp-at-tab-pane").forEach(function(c) { c.classList.remove("active"); });
        document.querySelectorAll(".cmp-at-tab-btn").forEach(function(b) { b.classList.remove("active"); });
        var target = document.getElementById(tabId);
        if (target) { target.classList.add("active"); }
        if (evt && evt.currentTarget) { evt.currentTarget.classList.add("active"); }
    }
    </script>
    <?php
    return ob_get_clean();
}
