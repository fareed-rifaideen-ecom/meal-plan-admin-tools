<?php
/**
 * Plugin Name: Meal Plan Admin Tools
 * Description: Standalone backend utilities for the Meal Plan system (Manual Legacy Importer & Database Cleanup).
 * Version: 1.1
 * Author: FMR
 */

// Prevent direct file access
if ( ! defined( 'ABSPATH' ) ) { exit; }

// ==========================================
// 1. REGISTER INDEPENDENT ADMIN MENU
// ==========================================
add_action( 'admin_menu', 'cmp_standalone_admin_tools_menu' );
function cmp_standalone_admin_tools_menu() {
    
    // Create a brand new Top-Level Menu to completely avoid slug/loading conflicts
    add_menu_page(
        'Meal Admin Tools',
        'Meal Admin Tools',
        'manage_options',
        'cmp-admin-tools',
        'cmp_standalone_render_manual_import',
        'dashicons-admin-tools', // Wrench & Gear icon
        59 // Places it right below your existing plugins
    );

    // Default Submenu: Importer
    add_submenu_page(
        'cmp-admin-tools',
        'Import Legacy Subscriber',
        'Import Subscriber',
        'manage_options',
        'cmp-admin-tools', // Matching parent slug makes this the default tab
        'cmp_standalone_render_manual_import'
    );

    // Second Submenu: Cleanup Tool
    add_submenu_page(
        'cmp-admin-tools',
        'Database Cleanup',
        'Cleanup Tool',
        'manage_options',
        'cmp-db-cleanup',
        'cmp_standalone_render_cleanup'
    );
}

// ==========================================
// 2. MANUAL SUBSCRIBER IMPORT TOOL
// ==========================================
function cmp_standalone_render_manual_import() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Insufficient permissions.' );
    global $wpdb;
    $message = '';

    if ( isset( $_POST['cmp_run_import'] ) && check_admin_referer( 'cmp_import_action', 'cmp_import_nonce' ) ) {
        $email          = sanitize_email($_POST['email']);
        $first_name     = sanitize_text_field($_POST['first_name']);
        $last_name      = sanitize_text_field($_POST['last_name']);
        $phone          = sanitize_text_field($_POST['phone']);
        $plan_name      = sanitize_text_field($_POST['plan_name']);
        $remaining_days = intval($_POST['remaining_days']);
        $method         = sanitize_text_field($_POST['delivery_method']);
        $timing         = sanitize_text_field($_POST['delivery_timing']);
        $time_slot      = sanitize_text_field($_POST['time_slot']);
        $address        = sanitize_text_field($_POST['address']);
        $allergies      = sanitize_textarea_field($_POST['allergies']);

        if (empty($email) || empty($plan_name) || $remaining_days <= 0) {
            $message = '<div class="notice notice-error"><p>Error: Email, Plan Name, and Remaining Days are strictly required.</p></div>';
        } else {
            // 1. Fetch or Create User Profile
            $user = get_user_by('email', $email);
            if (!$user) {
                // Auto-generate a secure random password if they don't exist in WP
                $password = wp_generate_password(12, false);
                $user_id = wp_create_user($email, $password, $email);
                if (is_wp_error($user_id)) {
                    $message = '<div class="notice notice-error"><p>Error creating user account: ' . $user_id->get_error_message() . '</p></div>';
                }
            } else {
                $user_id = $user->ID;
            }

            if (!isset($message) || empty($message)) {
                // 2. Save Logistics to User Meta (Bulletproof fallback for portals)
                wp_update_user(array('ID' => $user_id, 'first_name' => $first_name, 'last_name' => $last_name));
                update_user_meta($user_id, 'billing_phone',     $phone);
                update_user_meta($user_id, 'billing_address_1', $address);
                update_user_meta($user_id, 'delivery_method',   $method);
                update_user_meta($user_id, 'delivery_timing',   $timing);
                update_user_meta($user_id, 'time_slot',         $time_slot);
                update_user_meta($user_id, 'allergies',         $allergies);

                // 3. Determine Categories for the Quota System
                if (stripos($plan_name, 'juice') !== false || stripos($plan_name, 'cleanse') !== false) {
                    $categories = 'Juices';
                } else {
                    $categories = 'Breakfast,Lunch,Dinner,Snacks';
                }

                // 4. Inject Directly into Subscription Database
                $table_subs = $wpdb->prefix . 'cmp_subscriptions';
                $inserted = $wpdb->insert($table_subs, array(
                    'user_id'            => $user_id,
                    'wc_order_id'        => 0, // Order ID 0 flags this as a manual import for FOH
                    'plan_name'          => $plan_name,
                    'total_days'         => $remaining_days,
                    'allowed_categories' => $categories,
                    'status'             => 'active', // Activate instantly
                    'start_date'         => date('Y-m-d H:i:s'),
                    'expiry_date'        => date('Y-m-d H:i:s', strtotime("+$remaining_days days")),
                ));

                if ($inserted) {
                    $message = '<div class="notice notice-success"><p><strong>Success!</strong> ' . esc_html($first_name) . ' has been imported and assigned <strong>' . $remaining_days . ' days</strong>. They can now log into the Customer Portal using their email address.</p></div>';
                } else {
                    $message = '<div class="notice notice-error"><p>Database error during insertion.</p></div>';
                }
            }
        }
    }
    ?>
    <div class="wrap">
        <h1 style="margin-bottom: 20px;">Import Legacy Subscriber</h1>
        <?php echo $message; ?>
        
        <div style="background: #fff; padding: 20px 30px; border: 1px solid #ccd0d4; border-radius: 4px; max-width: 800px; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
            <h2 style="margin-top: 0; border-bottom: 1px solid #eee; padding-bottom: 10px;">Manual Entry Form</h2>
            <p style="color: #666; margin-bottom: 25px;">Use this tool to add customers who paid offline or are halfway through an old plan. If the email address doesn't exist in the system, a new account will be auto-created.</p>
            
            <form method="POST" action="">
                <?php wp_nonce_field( 'cmp_import_action', 'cmp_import_nonce' ); ?>
                
                <div style="display: flex; gap: 20px; margin-bottom: 15px;">
                    <div style="flex: 1;">
                        <label style="font-weight: bold; display: block; margin-bottom: 5px;">Customer Email *</label>
                        <input type="email" name="email" required style="width: 100%; padding: 6px;">
                    </div>
                    <div style="flex: 1;">
                        <label style="font-weight: bold; display: block; margin-bottom: 5px;">Phone Number *</label>
                        <input type="text" name="phone" required style="width: 100%; padding: 6px;">
                    </div>
                </div>

                <div style="display: flex; gap: 20px; margin-bottom: 15px;">
                    <div style="flex: 1;">
                        <label style="font-weight: bold; display: block; margin-bottom: 5px;">First Name *</label>
                        <input type="text" name="first_name" required style="width: 100%; padding: 6px;">
                    </div>
                    <div style="flex: 1;">
                        <label style="font-weight: bold; display: block; margin-bottom: 5px;">Last Name *</label>
                        <input type="text" name="last_name" required style="width: 100%; padding: 6px;">
                    </div>
                </div>

                <div style="margin-bottom: 20px;">
                    <label style="font-weight: bold; display: block; margin-bottom: 5px;">Delivery Address</label>
                    <input type="text" name="address" style="width: 100%; padding: 6px;">
                </div>

                <hr style="border: 0; border-top: 1px solid #eee; margin: 25px 0;">

                <div style="display: flex; gap: 20px; margin-bottom: 15px;">
                    <div style="flex: 1;">
                        <label style="font-weight: bold; display: block; margin-bottom: 5px;">Delivery Method</label>
                        <select name="delivery_method" style="width: 100%; padding: 6px;">
                            <option value="Delivery">Home/Office Delivery</option>
                            <option value="Pickup">Store Pick-up</option>
                        </select>
                    </div>
                    <div style="flex: 1;">
                        <label style="font-weight: bold; display: block; margin-bottom: 5px;">Receive By</label>
                        <select name="delivery_timing" style="width: 100%; padding: 6px;">
                            <option value="Deliver Day Before">Deliver Day Before</option>
                            <option value="Deliver Same Day">Deliver Same Day</option>
                        </select>
                    </div>
                    <div style="flex: 1;">
                        <label style="font-weight: bold; display: block; margin-bottom: 5px;">Time Slot</label>
                        <select name="time_slot" style="width: 100%; padding: 6px;">
                            <option value="5:00 AM to 6:00 AM">5:00 AM to 6:00 AM</option>
                            <option value="6:00 AM to 7:00 AM">6:00 AM to 7:00 AM</option>
                            <option value="7:00 AM to 8:00 AM">7:00 AM to 8:00 AM</option>
                            <option value="8:00 AM to 9:00 AM" selected>8:00 AM to 9:00 AM</option>
                            <option value="5:00 PM to 8:00 PM">5:00 PM to 8:00 PM</option>
                        </select>
                    </div>
                </div>

                <div style="margin-bottom: 20px;">
                    <label style="font-weight: bold; display: block; margin-bottom: 5px;">Allergies</label>
                    <input type="text" name="allergies" placeholder="e.g., Nuts, Shellfish (Leave blank if none)" style="width: 100%; padding: 6px;">
                </div>

                <hr style="border: 0; border-top: 1px solid #eee; margin: 25px 0;">

                <div style="display: flex; gap: 20px; margin-bottom: 25px;">
                    <div style="flex: 2;">
                        <label style="font-weight: bold; display: block; margin-bottom: 5px;">Select Plan Quota *</label>
                        <select name="plan_name" style="width: 100%; padding: 6px;">
                            <option value="1 Meal Plan (Manual)">1 Meal Plan</option>
                            <option value="2 Meal Plan (Manual)">2 Meal Plan</option>
                            <option value="3 Meal Plan (Manual)">3 Meal Plan</option>
                            <option value="Juice Cleanse (Manual)">Juice Cleanse</option>
                        </select>
                        <p class="description">This determines their daily limits in the Customer Portal.</p>
                    </div>
                    <div style="flex: 1;">
                        <label style="font-weight: bold; display: block; margin-bottom: 5px;">Remaining Days *</label>
                        <input type="number" name="remaining_days" required min="1" max="100" style="width: 100%; padding: 6px;" placeholder="e.g. 14">
                        <p class="description" style="color:#d63638; font-weight:bold;">If they had a 24-day plan but already ate 10 days, enter 14 here.</p>
                    </div>
                </div>

                <button type="submit" name="cmp_run_import" class="button button-primary" style="background: #16a34a; border-color: #16a34a; padding: 5px 30px;">Import Customer</button>
            </form>
        </div>
    </div>
    <?php
}

// ==========================================
// 3. DATABASE CLEANUP TOOL
// ==========================================
function cmp_standalone_render_cleanup() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Insufficient permissions.' );
    global $wpdb;
    $table_subs = $wpdb->prefix . 'cmp_subscriptions';
    $table_logs = $wpdb->prefix . 'cmp_daily_logs';
    $message    = '';

    if ( isset( $_POST['cmp_run_cleanup'] ) && check_admin_referer( 'cmp_cleanup_action', 'cmp_cleanup_nonce' ) ) {
        $days_old = intval( $_POST['days_old'] );
        $confirm  = isset( $_POST['confirm_delete'] );
        if ( $days_old < 30 ) {
            $message = '<div class="notice notice-error"><p><strong>Error:</strong> Minimum 30 days.</p></div>';
        } elseif ( ! $confirm ) {
            $message = '<div class="notice notice-error"><p><strong>Error:</strong> Please check the confirmation box.</p></div>';
        } else {
            $cutoff_date    = date( 'Y-m-d H:i:s', strtotime( "-$days_old days" ) );
            $subs_to_delete = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM $table_subs WHERE expiry_date < %s", $cutoff_date ) );
            if ( empty( $subs_to_delete ) ) {
                $message = '<div class="notice notice-info"><p>No subscriptions found. Database is clean!</p></div>';
            } else {
                $ids_list     = implode( ',', array_map( 'intval', $subs_to_delete ) );
                $logs_deleted = $wpdb->query( "DELETE FROM $table_logs WHERE subscription_id IN ($ids_list)" );
                $subs_deleted = $wpdb->query( "DELETE FROM $table_subs WHERE id IN ($ids_list)" );
                $message      = '<div class="notice notice-success"><p><strong>Success!</strong> Deleted <strong>' . intval($subs_deleted) . '</strong> subscriptions and <strong>' . intval($logs_deleted) . '</strong> meal logs.</p></div>';
            }
        }
    }
    ?>
    <div class="wrap">
        <h1 style="margin-bottom: 20px;">Meal Plan Database Cleanup</h1>
        <?php echo $message; ?>
        <div style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 4px; max-width: 700px;">
            <h2 style="margin-top: 0; border-bottom: 1px solid #eee; padding-bottom: 10px;">Purge Old Subscription Data</h2>
            <p>Permanently delete old subscriptions and daily meal logs. <strong>Customer accounts and addresses will NOT be deleted.</strong></p>
            <form method="POST" action="">
                <?php wp_nonce_field( 'cmp_cleanup_action', 'cmp_cleanup_nonce' ); ?>
                <table class="form-table">
                    <tr>
                        <th><label for="days_old">Target Timeframe:</label></th>
                        <td>Delete plans expired more than <input type="number" name="days_old" id="days_old" value="90" min="30" max="3650" style="width: 80px;"> days ago.
                            <p class="description">Minimum 30 days.</p></td>
                    </tr>
                    <tr>
                        <th>Confirm:</th>
                        <td><label style="color: #dc3232; font-weight: bold;"><input type="checkbox" name="confirm_delete" value="1" required> I understand this is irreversible.</label></td>
                    </tr>
                </table>
                <p class="submit"><button type="submit" name="cmp_run_cleanup" class="button button-primary" style="background: #dc3232; border-color: #dc3232;">Permanently Delete Old Records</button></p>
            </form>
        </div>
    </div>
    <?php
}