<?php
/**
 * Plugin Name: Meal Plan Admin Tools
 * Description: Standalone backend utilities for the Meal Plan system (Manual Legacy Importer, Bulk CSV Importer, Database Cleanup & Deposit Wallet).
 * Version: 1.5
 * Author: Fareed M Rifaideen
 */

// Prevent direct file access
if ( ! defined( 'ABSPATH' ) ) { exit; }

// ==========================================
// 1. REGISTER INDEPENDENT ADMIN MENU
// ==========================================
add_action( 'admin_menu', 'cmp_standalone_admin_tools_menu', 60 );
function cmp_standalone_admin_tools_menu() {
    
    // Top-Level Menu (Accessible by Admin, Menu Manager & Accounts Team)
    add_menu_page(
        'Meal Admin Tools',
        'Meal Admin Tools',
        'read',
        'cmp-admin-tools',
        'cmp_standalone_render_manual_import',
        'dashicons-admin-tools',
        59
    );

    // Submenu 1: Default Importer
    add_submenu_page(
        'cmp-admin-tools',
        'Import Legacy Subscriber',
        'Import Subscriber',
        'read',
        'cmp-admin-tools',
        'cmp_standalone_render_manual_import'
    );

    // Submenu 2: Cleanup Tool (Strictly Super Admin)
    add_submenu_page(
        'cmp-admin-tools',
        'Database Cleanup',
        'Cleanup Tool',
        'manage_options',
        'cmp-db-cleanup',
        'cmp_standalone_render_cleanup'
    );

    // Submenu 3: Deposit Manager (Under Meal Admin Tools)
    add_submenu_page(
        'cmp-admin-tools',
        'Deposit Manager',
        'Deposit Manager',
        'read',
        'cmp-deposit-manager',
        'cmp_standalone_render_deposit_manager'
    );

    // Cross-Registration: Also show under the main Menu Manager menu for easy discovery
    add_submenu_page(
        'cmp-menu-manager',
        'Deposit Manager',
        'Deposit Manager',
        'read',
        'cmp-deposit-manager-portal',
        'cmp_standalone_render_deposit_manager'
    );
}

// ==========================================
// 2. CSV TEMPLATE DOWNLOADER
// ==========================================
add_action( 'admin_init', 'cmp_download_csv_template' );
function cmp_download_csv_template() {
    if ( isset( $_GET['cmp_download_template'] ) && ( current_user_can( 'manage_options' ) || current_user_can( 'menu_manager' ) ) ) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="Meal_Plan_Bulk_Import_Template.csv"');
        $output = fopen('php://output', 'w');
        fputs($output, chr(0xEF) . chr(0xBB) . chr(0xBF)); 

        fputcsv($output, array('Email', 'First Name', 'Last Name', 'Phone', 'Address', 'Delivery Method (Delivery/Pickup)', 'Receive By (Deliver Day Before/Deliver Same Day)', 'Time Slot', 'Allergies', 'Plan Name', 'Remaining Days'));
        fputcsv($output, array('john@example.com', 'John', 'Doe', '0501234567', 'Dubai Marina', 'Delivery', 'Deliver Day Before', '5:00 AM to 6:00 AM', 'Nuts', '2 Meal Plan', '20'));

        fclose($output);
        exit;
    }
}

// ==========================================
// 3. SUBSCRIBER IMPORT TOOL (MANUAL & BULK CSV)
// ==========================================
function cmp_standalone_render_manual_import() {
    if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'menu_manager' ) ) {
        wp_die( 'Access Denied: You do not have permission to view this tool.' );
    }
    
    global $wpdb;
    $message = '';
    $table_subs = $wpdb->prefix . 'cmp_subscriptions';

    // --- HANDLE BULK CSV IMPORT ---
    if ( isset( $_POST['cmp_run_csv_import'] ) && check_admin_referer( 'cmp_csv_import_action', 'cmp_csv_import_nonce' ) ) {
        if ( !empty( $_FILES['csv_file']['tmp_name'] ) ) {
            $file = $_FILES['csv_file']['tmp_name'];
            $handle = fopen($file, "r");
            
            if ($handle !== FALSE) {
                $row_count = 0;
                $success_count = 0;
                $failed_rows = array();

                fgetcsv($handle, 1000, ","); // Skip header

                while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                    $row_count++;
                    $email          = isset($data[0]) ? sanitize_email($data[0]) : '';
                    $first_name     = isset($data[1]) ? sanitize_text_field($data[1]) : '';
                    $last_name      = isset($data[2]) ? sanitize_text_field($data[2]) : '';
                    $phone          = isset($data[3]) ? sanitize_text_field($data[3]) : '';
                    $address        = isset($data[4]) ? sanitize_text_field($data[4]) : '';
                    $method         = isset($data[5]) ? sanitize_text_field($data[5]) : '';
                    $timing         = isset($data[6]) ? sanitize_text_field($data[6]) : '';
                    $time_slot      = isset($data[7]) ? sanitize_text_field($data[7]) : '';
                    $allergies      = isset($data[8]) ? sanitize_text_field($data[8]) : '';
                    $plan_name      = isset($data[9]) ? sanitize_text_field($data[9]) : '';
                    $remaining_days = isset($data[10]) ? intval($data[10]) : 0;

                    if (empty($email) || empty($plan_name) || $remaining_days <= 0) {
                        $failed_rows[] = "Row $row_count: Missing Email, Plan Name, or Remaining Days.";
                        continue;
                    }

                    $user = get_user_by('email', $email);
                    if (!$user) {
                        $password = wp_generate_password(12, false);
                        $user_id = wp_create_user($email, $password, $email);
                        if (is_wp_error($user_id)) {
                            $failed_rows[] = "Row $row_count ($email): " . $user_id->get_error_message();
                            continue;
                        }
                    } else {
                        $user_id = $user->ID;
                    }

                    wp_update_user(array('ID' => $user_id, 'first_name' => $first_name, 'last_name' => $last_name));
                    if(!empty($phone)) update_user_meta($user_id, 'billing_phone', $phone);
                    if(!empty($address)) update_user_meta($user_id, 'billing_address_1', $address);
                    if(!empty($method)) update_user_meta($user_id, 'delivery_method', $method);
                    if(!empty($timing)) update_user_meta($user_id, 'delivery_timing', $timing);
                    if(!empty($time_slot)) update_user_meta($user_id, 'time_slot', $time_slot);
                    if(!empty($allergies)) update_user_meta($user_id, 'allergies', $allergies);

                    if (stripos($plan_name, 'juice') !== false || stripos($plan_name, 'cleanse') !== false) {
                        $categories = 'Juices';
                    } else {
                        $categories = 'Breakfast,Lunch,Dinner,Snacks';
                    }

                    $inserted = $wpdb->insert($table_subs, array(
                        'user_id'            => $user_id,
                        'wc_order_id'        => 0,
                        'plan_name'          => $plan_name,
                        'total_days'         => $remaining_days,
                        'allowed_categories' => $categories,
                        'status'             => 'active',
                        'start_date'         => date('Y-m-d H:i:s'),
                        'expiry_date'        => date('Y-m-d H:i:s', strtotime("+$remaining_days days")),
                    ));

                    if ($inserted) {
                        $success_count++;
                    } else {
                        $failed_rows[] = "Row $row_count ($email): Database insertion failed.";
                    }
                }
                fclose($handle);

                if ($success_count > 0) {
                    $message .= '<div class="notice notice-success"><p><strong>Success!</strong> Successfully imported <strong>' . $success_count . '</strong> customers from the CSV file.</p></div>';
                }
                if (!empty($failed_rows)) {
                    $message .= '<div class="notice notice-error"><p><strong>Warning:</strong> The following rows failed to import:<br>' . implode('<br>', $failed_rows) . '</p></div>';
                }
            } else {
                $message = '<div class="notice notice-error"><p>Error: Could not read the CSV file.</p></div>';
            }
        } else {
            $message = '<div class="notice notice-error"><p>Error: Please select a valid CSV file to upload.</p></div>';
        }
    }

    // --- HANDLE SINGLE MANUAL IMPORT ---
    if ( isset( $_POST['cmp_run_import'] ) && check_admin_referer( 'cmp_import_action', 'cmp_import_nonce' ) ) {
        $email          = sanitize_email($_POST['email']);
        $first_name     = sanitize_text_field($_POST['first_name']);
        $last_name      = sanitize_text_field($_POST['last_name']);
        $phone          = sanitize_text_field($_POST['phone']);
        $plan_name      = sanitize_text_field($_POST['plan_name']);
        $recipient_name = sanitize_text_field($_POST['recipient_name'] ?? '');
        $remaining_days = intval($_POST['remaining_days']);
        $method         = sanitize_text_field($_POST['delivery_method']);
        $timing         = sanitize_text_field($_POST['delivery_timing']);
        $time_slot      = sanitize_text_field($_POST['time_slot']);
        $address        = sanitize_text_field($_POST['address']);
        $allergies      = sanitize_textarea_field($_POST['allergies']);

        if (!empty($recipient_name)) {
            $plan_name .= ' - ' . $recipient_name;
        }

        if (empty($email) || empty($plan_name) || $remaining_days <= 0) {
            $message = '<div class="notice notice-error"><p>Error: Email, Plan Name, and Remaining Days are strictly required.</p></div>';
        } else {
            $user = get_user_by('email', $email);
            if (!$user) {
                $password = wp_generate_password(12, false);
                $user_id = wp_create_user($email, $password, $email);
                if (is_wp_error($user_id)) {
                    $message = '<div class="notice notice-error"><p>Error creating user account: ' . $user_id->get_error_message() . '</p></div>';
                }
            } else {
                $user_id = $user->ID;
            }

            if (!isset($message) || empty($message)) {
                wp_update_user(array('ID' => $user_id, 'first_name' => $first_name, 'last_name' => $last_name));
                update_user_meta($user_id, 'billing_phone',     $phone);
                update_user_meta($user_id, 'billing_address_1', $address);
                update_user_meta($user_id, 'delivery_method',   $method);
                update_user_meta($user_id, 'delivery_timing',   $timing);
                update_user_meta($user_id, 'time_slot',         $time_slot);
                update_user_meta($user_id, 'allergies',         $allergies);

                if (stripos($plan_name, 'juice') !== false || stripos($plan_name, 'cleanse') !== false) {
                    $categories = 'Juices';
                } else {
                    $categories = 'Breakfast,Lunch,Dinner,Snacks';
                }

                $inserted = $wpdb->insert($table_subs, array(
                    'user_id'            => $user_id,
                    'wc_order_id'        => 0,
                    'plan_name'          => $plan_name,
                    'total_days'         => $remaining_days,
                    'allowed_categories' => $categories,
                    'status'             => 'active',
                    'start_date'         => date('Y-m-d H:i:s'),
                    'expiry_date'        => date('Y-m-d H:i:s', strtotime("+$remaining_days days")),
                ));

                if ($inserted) {
                    $message = '<div class="notice notice-success"><p><strong>Success!</strong> ' . esc_html($first_name) . ' has been manually imported with <strong>' . $remaining_days . ' days</strong>.</p></div>';
                } else {
                    $message = '<div class="notice notice-error"><p>Database error during insertion.</p></div>';
                }
            }
        }
    }
    ?>
    <div class="wrap">
        <h1 style="margin-bottom: 20px;">Import Legacy Subscribers</h1>
        <?php echo $message; ?>

        <div style="background: #fff; padding: 20px 30px; border: 1px solid #ccd0d4; border-radius: 4px; max-width: 800px; box-shadow: 0 1px 1px rgba(0,0,0,.04); margin-bottom: 30px;">
            <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #eee; padding-bottom: 10px; margin-bottom: 20px;">
                <h2 style="margin: 0; color: #1d6f42;">Bulk CSV Import</h2>
                <a href="<?php echo admin_url('admin.php?page=cmp-admin-tools&cmp_download_template=1'); ?>" class="button" style="background: #1d6f42; color: #fff; border: none; font-weight: bold;">Download CSV Template</a>
            </div>
            
            <p style="color: #666; margin-bottom: 20px;">Upload a CSV strictly matching the template headers to import multiple customers at once.</p>
            
            <form method="POST" action="" enctype="multipart/form-data" style="display: flex; align-items: center; gap: 15px; background: #f8f9fa; padding: 15px; border: 1px dashed #ccc; border-radius: 4px;">
                <?php wp_nonce_field( 'cmp_csv_import_action', 'cmp_csv_import_nonce' ); ?>
                <input type="file" name="csv_file" accept=".csv" required style="font-size: 1em;">
                <button type="submit" name="cmp_run_csv_import" class="button button-primary" style="background: #1d6f42; border-color: #1d6f42;">Process CSV Upload</button>
            </form>
        </div>
        
        <div style="background: #fff; padding: 20px 30px; border: 1px solid #ccd0d4; border-radius: 4px; max-width: 800px; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
            <h2 style="margin-top: 0; border-bottom: 1px solid #eee; padding-bottom: 10px;">Single Manual Entry Form</h2>
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
                    </div>
                    <div style="flex: 1;">
                        <label style="font-weight: bold; display: block; margin-bottom: 5px;">Recipient Name (Optional)</label>
                        <input type="text" name="recipient_name" style="width: 100%; padding: 6px;" placeholder="e.g. Sarah">
                    </div>
                    <div style="flex: 1;">
                        <label style="font-weight: bold; display: block; margin-bottom: 5px;">Remaining Days *</label>
                        <input type="number" name="remaining_days" required min="1" max="100" style="width: 100%; padding: 6px;" placeholder="e.g. 14">
                    </div>
                </div>

                <button type="submit" name="cmp_run_import" class="button button-primary" style="background: #0073aa; padding: 5px 30px;">Import Single Customer</button>
            </form>
        </div>
    </div>
    <?php
}

// ==========================================
// 4. DATABASE CLEANUP TOOL
// ==========================================
function cmp_standalone_render_cleanup() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Access Denied: Only Super Administrators can run database cleanups.' );
    }
    
    global $wpdb;
    $table_subs = $wpdb->prefix . 'cmp_subscriptions';
    $table_logs = $wpdb->prefix . 'cmp_daily_logs';
    $message    = '';

    if ( isset( $_POST['cmp_run_cleanup'] ) && check_admin_referer( 'cmp_cleanup_action', 'cmp_cleanup_nonce' ) ) {
        $days_old = intval( $_POST['days_old'] );
        $confirm  = isset( $_POST['confirm_delete'] );
        if ( $days_old < 30 ) {
            $message = '<div class="notice notice-error"><p><strong>Error:</strong> Minimum timeframe is 30 days.</p></div>';
        } elseif ( ! $confirm ) {
            $message = '<div class="notice notice-error"><p><strong>Error:</strong> Please check the confirmation box.</p></div>';
        } else {
            $cutoff_date    = date( 'Y-m-d H:i:s', strtotime( "-$days_old days" ) );
            $subs_to_delete = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM $table_subs WHERE expiry_date < %s", $cutoff_date ) );
            if ( empty( $subs_to_delete ) ) {
                $message = '<div class="notice notice-info"><p>No subscriptions found. Database is already clean.</p></div>';
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
            <p>Permanently delete expired subscriptions and logs. <strong>Customer profiles and orders are preserved.</strong></p>
            <form method="POST" action="">
                <?php wp_nonce_field( 'cmp_cleanup_action', 'cmp_cleanup_nonce' ); ?>
                <table class="form-table">
                    <tr>
                        <th><label for="days_old">Target Timeframe:</label></th>
                        <td>Delete plans expired more than <input type="number" name="days_old" id="days_old" value="90" min="30" max="3650" style="width: 80px;"> days ago.</td>
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

// ==========================================
// 5. BACKEND DEPOSIT MANAGER TAB
// ==========================================
function cmp_standalone_render_deposit_manager() {
    if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'menu_manager' ) && ! current_user_can( 'accounts_team' ) && ! current_user_can( 'foh_manager' ) ) {
        wp_die( 'Access Denied: You do not have permission to manage deposits.' );
    }

    global $wpdb;
    $message = '';
    $found_users = array();

    // -- HANDLE MAGIC LINK ACTIONS (BACKEND) --
    if (isset($_POST['cmp_generate_magic_link']) && check_admin_referer('cmp_deposit_action', 'cmp_deposit_nonce')) {
        $links = get_option('cmp_active_magic_links', array());
        $token = substr(str_shuffle('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 8);
        $links[$token] = array(
            'date' => current_time('mysql'),
            'by'   => wp_get_current_user()->display_name
        );
        update_option('cmp_active_magic_links', $links);
        $message = '<div class="notice notice-success"><p>Magic link generated successfully.</p></div>';
    }

    if (isset($_POST['cmp_revoke_magic_link']) && check_admin_referer('cmp_deposit_action', 'cmp_deposit_nonce')) {
        $token_to_revoke = sanitize_text_field($_POST['revoke_token']);
        $links = get_option('cmp_active_magic_links', array());
        if (isset($links[$token_to_revoke])) {
            unset($links[$token_to_revoke]);
            update_option('cmp_active_magic_links', $links);
            $message = '<div class="notice notice-success"><p>Magic link revoked successfully.</p></div>';
        }
    }

    // -- HANDLE DEPOSIT WALLET STATUS UPDATE --
    if(isset($_POST['cmp_toggle_deposit']) && check_admin_referer('cmp_deposit_action', 'cmp_deposit_nonce')) {
        $user_id = intval($_POST['user_id']);
        $new_status = sanitize_text_field($_POST['new_status']); // 'yes', 'no', or 'own_bag'
        update_user_meta($user_id, '_cmp_deposit_held', $new_status);
        $message = '<div class="notice notice-success"><p>Deposit status updated successfully.</p></div>';
        
        $found_users[] = get_userdata($user_id);
    }

    $raw_query = isset($_POST['search_term']) ? sanitize_text_field($_POST['search_term']) : '';

    if(!empty($raw_query) && isset($_POST['cmp_search_user'])) {
        $user_query = new WP_User_Query( array(
            'search'         => '*' . $raw_query . '*',
            'search_columns' => array( 'user_login', 'user_email', 'user_nicename', 'display_name' ),
            'number'         => 20
        ));
        $found_users = $user_query->get_results();

        if (empty($found_users)) {
            $meta_query = new WP_User_Query( array(
                'meta_query' => array(
                    'relation' => 'OR',
                    array( 'key' => 'first_name', 'value' => $raw_query, 'compare' => 'LIKE' ),
                    array( 'key' => 'last_name', 'value' => $raw_query, 'compare' => 'LIKE' ),
                    array( 'key' => 'billing_phone', 'value' => $raw_query, 'compare' => 'LIKE' )
                ),
                'number' => 20
            ));
            $found_users = $meta_query->get_results();
        }

        if (empty($found_users)) {
            $message = '<div class="notice notice-error"><p>No customer found matching "'.esc_html($raw_query).' ".</p></div>';
        }
    }

    ?>
    <div class="wrap">
        <h1 style="margin-bottom: 20px;">Customer Deposit Wallet</h1>
        <?php echo $message; ?>
        
        <div style="display: flex; gap: 20px; flex-wrap: wrap; align-items: flex-start;">
            
            <!-- LEFT COLUMN: SEARCH & UPDATE -->
            <div style="flex: 2; min-width: 400px; background: #fff; padding: 20px 30px; border: 1px solid #ccd0d4; border-radius: 4px; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                <p style="color: #666; margin-bottom: 20px;">Search by Name, Email, or Phone to view or update a customer's Bag Deposit Wallet.</p>
                
                <form method="POST" style="display: flex; gap: 15px; margin-bottom: 30px;">
                    <input type="text" name="search_term" placeholder="Search by Name, Email, or Phone Number..." value="<?php echo esc_attr($raw_query); ?>" required style="flex: 1; padding: 8px;">
                    <button type="submit" name="cmp_search_user" class="button button-primary" style="background: #f59e0b; border-color: #f59e0b; font-weight: bold;">Search Customer</button>
                </form>

                <?php if (!empty($found_users)): 
                    foreach ($found_users as $searched_user):
                        $deposit_held = get_user_meta($searched_user->ID, '_cmp_deposit_held', true);
                        if ($deposit_held === '') {
                            $past_plans = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}cmp_subscriptions WHERE user_id = %d", $searched_user->ID));
                            $deposit_held = (intval($past_plans) > 0) ? 'yes' : 'no';
                        }
                        
                        $status_color = '#dc2626'; $status_bg = '#fee2e2'; $status_text = 'Refunded / No Deposit (Charge AED 150)';
                        if ($deposit_held === 'yes') { $status_color = '#10b981'; $status_bg = '#dcfce7'; $status_text = 'Bag Deposit Held (Waived)'; }
                        if ($deposit_held === 'own_bag') { $status_color = '#8b5cf6'; $status_bg = '#ede9fe'; $status_text = "Customer's Own Bag (Waived)"; }
                ?>
                <div style="background: #f9f9f9; padding: 20px; border: 1px solid #eee; border-left: 4px solid <?php echo $status_color; ?>; margin-bottom: 15px;">
                    <h3 style="margin-top: 0; font-size: 1.3em;"><?php echo esc_html($searched_user->first_name . ' ' . $searched_user->last_name); ?></h3>
                    <p style="font-size: 1em; color: #555; margin-bottom: 8px;"><strong>Email:</strong> <?php echo esc_html($searched_user->user_email); ?></p>
                    <?php $phone = get_user_meta($searched_user->ID, 'billing_phone', true); if($phone): ?>
                        <p style="font-size: 1em; color: #555; margin-bottom: 8px;"><strong>Phone:</strong> <?php echo esc_html($phone); ?></p>
                    <?php endif; ?>
                    <p style="font-size: 1em; margin-bottom: 20px;"><strong>Status:</strong> 
                        <span style="color: <?php echo $status_color; ?>; font-weight: bold; background: <?php echo $status_bg; ?>; padding: 4px 10px; border-radius: 4px;"><?php echo $status_text; ?></span>
                    </p>
                    
                    <form method="POST" style="margin-top: 15px; display: flex; gap: 10px; align-items: center;">
                        <?php wp_nonce_field('cmp_deposit_action', 'cmp_deposit_nonce'); ?>
                        <input type="hidden" name="user_id" value="<?php echo $searched_user->ID; ?>">
                        <select name="new_status" style="padding: 6px; border-radius: 4px;">
                            <option value="yes" <?php selected($deposit_held, 'yes'); ?>>Deposit Held</option>
                            <option value="own_bag" <?php selected($deposit_held, 'own_bag'); ?>>Customer's Own Bag</option>
                            <option value="no" <?php selected($deposit_held, 'no'); ?>>Refunded / No Deposit</option>
                        </select>
                        <button type="submit" name="cmp_toggle_deposit" class="button" style="background: #334155; color: #fff; border: none; font-weight: bold; cursor: pointer;">
                            Update Wallet
                        </button>
                    </form>
                </div>
                <?php endforeach; endif; ?>
            </div>
            
            <!-- RIGHT COLUMN: MAGIC LINKS -->
            <div style="flex: 1; min-width: 300px; background: #fff; padding: 20px 30px; border: 1px solid #ccd0d4; border-radius: 4px; box-shadow: 0 1px 1px rgba(0,0,0,.04); border-top: 4px solid #8b5cf6;">
                <h3 style="margin-top: 0; color: #7c3aed; border-bottom: 1px solid #eee; padding-bottom: 10px;">"Own Bag" VIP Links</h3>
                <p style="color: #64748b; font-size: 0.9em; margin-bottom: 20px;">Generate secure, single-use checkout links that automatically waive the AED 150 deposit and tag the new customer as "Own Bag". The link auto-destroys once the order is placed.</p>
                
                <form method="POST" style="margin-bottom: 25px;">
                    <?php wp_nonce_field('cmp_deposit_action', 'cmp_deposit_nonce'); ?>
                    <button type="submit" name="cmp_generate_magic_link" class="button button-primary" style="background: #7c3aed; border-color: #7c3aed; font-weight: bold; width: 100%;">+ Generate New Link</button>
                </form>

                <?php 
                $active_links = get_option('cmp_active_magic_links', array());
                if (!empty($active_links)): 
                ?>
                <table class="wp-list-table widefat fixed striped" style="width: 100%; border-collapse: collapse; text-align: left;">
                    <thead>
                        <tr>
                            <th style="padding: 8px;">Active Link URL</th>
                            <th style="padding: 8px; width: 70px;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($active_links as $token => $data): 
                            $checkout_url = site_url('/meal-plan-checkout/?vip_token=' . $token);
                        ?>
                        <tr>
                            <td style="padding: 8px;">
                                <input type="text" value="<?php echo esc_attr($checkout_url); ?>" readonly style="width: 100%; padding: 6px; background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 0.85em; cursor: copy;" onclick="this.select(); document.execCommand('copy'); alert('Link copied to clipboard!');">
                                <div style="font-size: 0.8em; color: #94a3b8; margin-top: 3px;">Created by: <?php echo esc_html($data['by']); ?></div>
                            </td>
                            <td style="padding: 8px; vertical-align: middle;">
                                <form method="POST" style="margin: 0;">
                                    <?php wp_nonce_field('cmp_deposit_action', 'cmp_deposit_nonce'); ?>
                                    <input type="hidden" name="revoke_token" value="<?php echo esc_attr($token); ?>">
                                    <button type="submit" name="cmp_revoke_magic_link" style="background: #ef4444; color: white; border: none; padding: 5px 10px; border-radius: 4px; cursor: pointer; font-size: 0.85em; font-weight: bold;">Revoke</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                    <p style="color: #94a3b8; font-style: italic; text-align: center;">No active links.</p>
                <?php endif; ?>
            </div>
            
        </div>
    </div>
    <?php
}

// ==========================================
// 6. FRONTEND: ADMIN TOOLS PORTAL
// ==========================================
add_shortcode( 'meal_admin_tools', 'cmp_render_frontend_admin_tools' );
function cmp_render_frontend_admin_tools() {
    
    // 1. Security Check
    if ( ! is_user_logged_in() ) {
        $login_args = array('echo' => false, 'form_id' => 'cmp-tools-login', 'label_username' => __('Email Address or Username'), 'label_password' => __('Password'));
        $custom_css = '<style>#cmp-tools-login label { display: block; margin-bottom: 5px; font-weight: bold; color: #333; text-align: left; } #cmp-tools-login input[type="text"], #cmp-tools-login input[type="password"] { width: 100%; padding: 10px; margin-bottom: 15px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; } #cmp-tools-login .login-submit input[type="submit"] { width: 100%; background: #0073aa; color: white; border: none; padding: 12px; border-radius: 4px; font-weight: bold; cursor: pointer; }</style>';
        return $custom_css . '<div style="max-width:400px; margin:50px auto; padding:30px; background:#f8f9fa; border-radius:8px; border:1px solid #ddd; box-shadow: 0 2px 10px rgba(0,0,0,0.05);"><h2 style="text-align:center; margin-top:0; color:#222;">Admin Tools Login</h2><p style="text-align:center; color:#666; margin-bottom:20px;">Authorized staff account required.</p>' . wp_login_form( $login_args ) . '</div>';
    }

    if ( !current_user_can('manage_options') && !current_user_can('menu_manager') && !current_user_can('accounts_team') && !current_user_can('foh_manager') ) {
        return '<div style="max-width: 600px; margin: 50px auto; padding: 30px; background: #fff; border-left: 4px solid #dc3232; box-shadow: 0 4px 6px rgba(0,0,0,0.05);"><p style="font-size: 1.1em; color: #dc3232;"><strong>Access Denied:</strong> Authorized personnel only.</p></div>';
    }

    global $wpdb;
    $table_subs = $wpdb->prefix . 'cmp_subscriptions';
    $table_logs = $wpdb->prefix . 'cmp_daily_logs';
    
    $import_message  = '';
    $cleanup_message = '';
    $deposit_message = '';

    $can_import  = current_user_can('manage_options') || current_user_can('menu_manager');
    $can_cleanup = current_user_can('manage_options');

    // Default tab: Accounts team opens directly into the Deposit Manager
    $active_tab = ( current_user_can('accounts_team') && !$can_import ) ? 'tab-deposit' : 'tab-import';

    // A. CSV Bulk Import
    if ( isset( $_POST['cmp_frontend_csv_import'] ) && wp_verify_nonce($_POST['cmp_import_nonce'], 'cmp_frontend_import') && $can_import ) {
        $active_tab = 'tab-import';
        if ( !empty( $_FILES['csv_file']['tmp_name'] ) ) {
            $file = $_FILES['csv_file']['tmp_name'];
            $handle = fopen($file, "r");
            if ($handle !== FALSE) {
                $row_count = 0; $success_count = 0; $failed_rows = array();
                fgetcsv($handle, 1000, ",");
                while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                    $row_count++;
                    $email          = isset($data[0]) ? sanitize_email($data[0]) : '';
                    $first_name     = isset($data[1]) ? sanitize_text_field($data[1]) : '';
                    $last_name      = isset($data[2]) ? sanitize_text_field($data[2]) : '';
                    $phone          = isset($data[3]) ? sanitize_text_field($data[3]) : '';
                    $address        = isset($data[4]) ? sanitize_text_field($data[4]) : '';
                    $method         = isset($data[5]) ? sanitize_text_field($data[5]) : '';
                    $timing         = isset($data[6]) ? sanitize_text_field($data[6]) : '';
                    $time_slot      = isset($data[7]) ? sanitize_text_field($data[7]) : '';
                    $allergies      = isset($data[8]) ? sanitize_text_field($data[8]) : '';
                    $plan_name      = isset($data[9]) ? sanitize_text_field($data[9]) : '';
                    $remaining_days = isset($data[10]) ? intval($data[10]) : 0;

                    if (empty($email) || empty($plan_name) || $remaining_days <= 0) {
                        $failed_rows[] = "Row $row_count: Missing Email, Plan Name, or Days.";
                        continue;
                    }

                    $user = get_user_by('email', $email);
                    if (!$user) {
                        $password = wp_generate_password(12, false);
                        $user_id = wp_create_user($email, $password, $email);
                        if (is_wp_error($user_id)) {
                            $failed_rows[] = "Row $row_count ($email): " . $user_id->get_error_message(); continue;
                        }
                    } else { $user_id = $user->ID; }

                    wp_update_user(array('ID' => $user_id, 'first_name' => $first_name, 'last_name' => $last_name));
                    if(!empty($phone)) update_user_meta($user_id, 'billing_phone', $phone);
                    if(!empty($address)) update_user_meta($user_id, 'billing_address_1', $address);
                    if(!empty($method)) update_user_meta($user_id, 'delivery_method', $method);
                    if(!empty($timing)) update_user_meta($user_id, 'delivery_timing', $timing);
                    if(!empty($time_slot)) update_user_meta($user_id, 'time_slot', $time_slot);
                    if(!empty($allergies)) update_user_meta($user_id, 'allergies', $allergies);

                    $categories = (stripos($plan_name, 'juice') !== false || stripos($plan_name, 'cleanse') !== false) ? 'Juices' : 'Breakfast,Lunch,Dinner,Snacks';

                    $inserted = $wpdb->insert($table_subs, array(
                        'user_id'            => $user_id,
                        'wc_order_id'        => 0,
                        'plan_name'          => $plan_name,
                        'total_days'         => $remaining_days,
                        'allowed_categories' => $categories,
                        'status'             => 'active',
                        'start_date'         => date('Y-m-d H:i:s'),
                        'expiry_date'        => date('Y-m-d H:i:s', strtotime("+$remaining_days days")),
                    ));

                    if ($inserted) $success_count++;
                    else $failed_rows[] = "Row $row_count ($email): DB Error.";
                }
                fclose($handle);

                if ($success_count > 0) $import_message .= '<div style="background:#dcfce7; color:#166534; padding:15px; border-radius:6px; margin-bottom:20px;"><strong>Success!</strong> Imported ' . $success_count . ' customers.</div>';
                if (!empty($failed_rows)) $import_message .= '<div style="background:#fee2e2; color:#991b1b; padding:15px; border-radius:6px; margin-bottom:20px;"><strong>Warning:</strong> Some rows failed:<br>' . implode('<br>', $failed_rows) . '</div>';
            } else { $import_message = '<div style="background:#fee2e2; color:#991b1b; padding:15px; border-radius:6px; margin-bottom:20px;">Error reading CSV.</div>'; }
        }
    }

    // B. Manual Single Import
    if ( isset( $_POST['cmp_frontend_manual_import'] ) && wp_verify_nonce($_POST['cmp_import_nonce'], 'cmp_frontend_import') && $can_import ) {
        $active_tab     = 'tab-import';
        $email          = sanitize_email($_POST['email']);
        $first_name     = sanitize_text_field($_POST['first_name']);
        $last_name      = sanitize_text_field($_POST['last_name']);
        $phone          = sanitize_text_field($_POST['phone']);
        $plan_name      = sanitize_text_field($_POST['plan_name']);
        $recipient_name = sanitize_text_field($_POST['recipient_name'] ?? '');
        $remaining_days = intval($_POST['remaining_days']);
        $method         = sanitize_text_field($_POST['delivery_method']);
        $timing         = sanitize_text_field($_POST['delivery_timing']);
        $time_slot      = sanitize_text_field($_POST['time_slot']);
        $address        = sanitize_text_field($_POST['address']);
        $allergies      = sanitize_textarea_field($_POST['allergies']);

        if (!empty($recipient_name)) { $plan_name .= ' - ' . $recipient_name; }

        if (empty($email) || empty($plan_name) || $remaining_days <= 0) {
            $import_message = '<div style="background:#fee2e2; color:#991b1b; padding:15px; border-radius:6px; margin-bottom:20px;">Error: Email, Plan, and Days are required.</div>';
        } else {
            $user = get_user_by('email', $email);
            if (!$user) {
                $password = wp_generate_password(12, false);
                $user_id = wp_create_user($email, $password, $email);
            } else { $user_id = $user->ID; }

            wp_update_user(array('ID' => $user_id, 'first_name' => $first_name, 'last_name' => $last_name));
            update_user_meta($user_id, 'billing_phone',     $phone);
            update_user_meta($user_id, 'billing_address_1', $address);
            update_user_meta($user_id, 'delivery_method',   $method);
            update_user_meta($user_id, 'delivery_timing',   $timing);
            update_user_meta($user_id, 'time_slot',         $time_slot);
            update_user_meta($user_id, 'allergies',         $allergies);

            $categories = (stripos($plan_name, 'juice') !== false || stripos($plan_name, 'cleanse') !== false) ? 'Juices' : 'Breakfast,Lunch,Dinner,Snacks';

            $inserted = $wpdb->insert($table_subs, array(
                'user_id'            => $user_id,
                'wc_order_id'        => 0,
                'plan_name'          => $plan_name,
                'total_days'         => $remaining_days,
                'allowed_categories' => $categories,
                'status'             => 'active',
                'start_date'         => date('Y-m-d H:i:s'),
                'expiry_date'        => date('Y-m-d H:i:s', strtotime("+$remaining_days days")),
            ));

            if ($inserted) {
                $import_message = '<div style="background:#dcfce7; color:#166534; padding:15px; border-radius:6px; margin-bottom:20px;"><strong>Success!</strong> ' . esc_html($first_name) . ' added.</div>';
            } else {
                $import_message = '<div style="background:#fee2e2; color:#991b1b; padding:15px; border-radius:6px; margin-bottom:20px;">Database Error.</div>';
            }
        }
    }

    // C. Database Cleanup
    if ( isset( $_POST['cmp_frontend_run_cleanup'] ) && wp_verify_nonce($_POST['cmp_cleanup_nonce'], 'cmp_frontend_cleanup') && $can_cleanup ) {
        $active_tab = 'tab-cleanup';
        $days_old = intval( $_POST['days_old'] );
        $confirm  = isset( $_POST['confirm_delete'] );
        if ( $days_old < 30 ) {
            $cleanup_message = '<div style="background:#fee2e2; color:#991b1b; padding:15px; border-radius:6px; margin-bottom:20px;">Minimum 30 days required.</div>';
        } elseif ( ! $confirm ) {
            $cleanup_message = '<div style="background:#fee2e2; color:#991b1b; padding:15px; border-radius:6px; margin-bottom:20px;">Please check confirmation box.</div>';
        } else {
            $cutoff_date    = date( 'Y-m-d H:i:s', strtotime( "-$days_old days" ) );
            $subs_to_delete = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM $table_subs WHERE expiry_date < %s", $cutoff_date ) );
            if ( empty( $subs_to_delete ) ) {
                $cleanup_message = '<div style="background:#e0f2fe; color:#0369a1; padding:15px; border-radius:6px; margin-bottom:20px;">No old subscriptions found.</div>';
            } else {
                $ids_list     = implode( ',', array_map( 'intval', $subs_to_delete ) );
                $logs_deleted = $wpdb->query( "DELETE FROM $table_logs WHERE subscription_id IN ($ids_list)" );
                $subs_deleted = $wpdb->query( "DELETE FROM $table_subs WHERE id IN ($ids_list)" );
                $cleanup_message = '<div style="background:#dcfce7; color:#166534; padding:15px; border-radius:6px; margin-bottom:20px;"><strong>Success!</strong> Deleted ' . intval($subs_deleted) . ' plans and ' . intval($logs_deleted) . ' logs.</div>';
            }
        }
    }

    // D. Deposit Manager
    $found_users = array();
    $raw_query = '';

    // -- HANDLE MAGIC LINK ACTIONS (FRONTEND) --
    if (isset($_POST['cmp_frontend_generate_magic_link']) && wp_verify_nonce($_POST['cmp_deposit_nonce'], 'cmp_frontend_deposit')) {
        $active_tab = 'tab-deposit';
        $links = get_option('cmp_active_magic_links', array());
        $token = substr(str_shuffle('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 8);
        $links[$token] = array(
            'date' => current_time('mysql'),
            'by'   => wp_get_current_user()->display_name
        );
        update_option('cmp_active_magic_links', $links);
        $deposit_message = '<div style="background:#dcfce7; color:#166534; padding:15px; border-radius:6px; margin-bottom:20px;"><strong>Success!</strong> Magic link generated successfully.</div>';
    }

    if (isset($_POST['cmp_frontend_revoke_magic_link']) && wp_verify_nonce($_POST['cmp_deposit_nonce'], 'cmp_frontend_deposit')) {
        $active_tab = 'tab-deposit';
        $token_to_revoke = sanitize_text_field($_POST['revoke_token']);
        $links = get_option('cmp_active_magic_links', array());
        if (isset($links[$token_to_revoke])) {
            unset($links[$token_to_revoke]);
            update_option('cmp_active_magic_links', $links);
            $deposit_message = '<div style="background:#dcfce7; color:#166534; padding:15px; border-radius:6px; margin-bottom:20px;"><strong>Success!</strong> Magic link revoked.</div>';
        }
    }
    
    // -- HANDLE CUSTOMER SEARCH --
    if ( isset( $_POST['cmp_frontend_search_deposit'] ) && wp_verify_nonce($_POST['cmp_deposit_nonce'], 'cmp_frontend_deposit') ) {
        $active_tab = 'tab-deposit';
        $raw_query  = sanitize_text_field($_POST['search_term']);
        
        $user_query = new WP_User_Query( array(
            'search'         => '*' . $raw_query . '*',
            'search_columns' => array( 'user_login', 'user_email', 'user_nicename', 'display_name' ),
            'number'         => 20
        ));
        $found_users = $user_query->get_results();

        if (empty($found_users)) {
            $meta_query = new WP_User_Query( array(
                'meta_query' => array(
                    'relation' => 'OR',
                    array( 'key' => 'first_name', 'value' => $raw_query, 'compare' => 'LIKE' ),
                    array( 'key' => 'last_name', 'value' => $raw_query, 'compare' => 'LIKE' ),
                    array( 'key' => 'billing_phone', 'value' => $raw_query, 'compare' => 'LIKE' )
                ),
                'number' => 20
            ));
            $found_users = $meta_query->get_results();
        }

        if (empty($found_users)) {
            $deposit_message = '<div style="background:#fee2e2; color:#991b1b; padding:15px; border-radius:6px; margin-bottom:20px;">No customer found matching "'.esc_html($raw_query).' ".</div>';
        }
    }

    // -- HANDLE DEPOSIT STATUS TOGGLE --
    if ( isset( $_POST['cmp_frontend_toggle_deposit'] ) && wp_verify_nonce($_POST['cmp_deposit_nonce'], 'cmp_frontend_deposit') ) {
        $active_tab = 'tab-deposit';
        $user_id    = intval($_POST['user_id']);
        $new_status = sanitize_text_field($_POST['new_status']);
        update_user_meta($user_id, '_cmp_deposit_held', $new_status);
        $deposit_message = '<div style="background:#dcfce7; color:#166534; padding:15px; border-radius:6px; margin-bottom:20px;"><strong>Success!</strong> Deposit wallet status updated.</div>';
        
        $found_users[] = get_userdata($user_id);
    }

    ob_start();
    ?>
    <style>
        .tools-wrap { max-width: 1200px; margin: 0 auto; font-family: inherit; }
        .tools-nav { display: flex; border-bottom: 2px solid #ddd; margin-bottom: 25px; overflow-x: auto; }
        .tools-tab-btn { background: none; border: none; padding: 15px 30px; font-size: 1.1em; font-weight: bold; color: #64748b; cursor: pointer; border-bottom: 3px solid transparent; }
        .tools-tab-btn.active { color: #0f172a; border-bottom: 3px solid #0f172a; }
        .tools-content { display: none; padding: 10px 0; }
        .tools-content.active { display: block; }
        .tools-card { background: #fff; padding: 25px; border-radius: 8px; border: 1px solid #e2e8f0; margin-bottom: 30px; box-shadow: 0 2px 5px rgba(0,0,0,0.02); }
        .tools-input { width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 4px; box-sizing: border-box; margin-top: 5px; }
        .tools-label { font-weight: bold; color: #334155; display: block; margin-bottom: 2px; }
        .tools-flex { display: flex; gap: 20px; margin-bottom: 15px; flex-wrap: wrap; }
        .tools-flex > div { flex: 1; min-width: 200px; }
    </style>

    <div class="tools-wrap">
        <div style="background: #0f172a; color: #fff; padding: 25px; border-radius: 8px 8px 0 0; display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <div>
                <h2 style="margin: 0; color: #fff;">Admin Tools</h2>
                <p style="margin: 5px 0 0 0; color: #94a3b8;">Legacy Imports, Logistics & Deposit Operations</p>
            </div>
        </div>

        <div class="tools-nav">
            <?php if ($can_import): ?>
            <button class="tools-tab-btn <?php echo $active_tab == 'tab-import' ? 'active' : ''; ?>" onclick="switchToolsTab(event, 'tab-import')">Import Subscribers</button>
            <?php endif; ?>

            <?php if ($can_cleanup): ?>
            <button class="tools-tab-btn <?php echo $active_tab == 'tab-cleanup' ? 'active' : ''; ?>" onclick="switchToolsTab(event, 'tab-cleanup')">Database Cleanup</button>
            <?php endif; ?>

            <button class="tools-tab-btn <?php echo $active_tab == 'tab-deposit' ? 'active' : ''; ?>" onclick="switchToolsTab(event, 'tab-deposit')">Deposit Manager</button>
        </div>

        <?php if ($can_import): ?>
        <!-- TAB 1: IMPORTER -->
        <div id="tab-import" class="tools-content <?php echo $active_tab == 'tab-import' ? 'active' : ''; ?>">
            <?php echo $import_message; ?>
            
            <div class="tools-card" style="border-left: 4px solid #10b981;">
                <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #eee; padding-bottom: 15px; margin-bottom: 20px;">
                    <h3 style="margin: 0; color: #047857;">Bulk CSV Import</h3>
                    <a href="?cmp_download_template=1" style="background: #10b981; color: white; padding: 8px 15px; border-radius: 4px; text-decoration: none; font-weight: bold; font-size: 0.9em;">Download CSV Template</a>
                </div>
                <p style="color: #64748b; margin-bottom: 20px;">Upload a CSV to import multiple subscribers. Family recipients can be specified by appending their name to the Plan Name (e.g., "2 Meal Plan - Sarah").</p>
                <form method="POST" enctype="multipart/form-data" style="display: flex; gap: 15px; align-items: center;">
                    <?php wp_nonce_field('cmp_frontend_import', 'cmp_import_nonce'); ?>
                    <input type="file" name="csv_file" accept=".csv" required style="padding: 10px; background: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 4px; flex-grow: 1;">
                    <button type="submit" name="cmp_frontend_csv_import" style="background: #047857; color: white; border: none; padding: 12px 25px; border-radius: 4px; font-weight: bold; cursor: pointer;">Upload CSV</button>
                </form>
            </div>

            <div class="tools-card" style="border-left: 4px solid #0284c7;">
                <h3 style="margin-top: 0; color: #0369a1; border-bottom: 1px solid #eee; padding-bottom: 15px;">Single Manual Entry Form</h3>
                <form method="POST">
                    <?php wp_nonce_field('cmp_frontend_import', 'cmp_import_nonce'); ?>
                    <div class="tools-flex">
                        <div><label class="tools-label">Email *</label><input type="email" name="email" required class="tools-input"></div>
                        <div><label class="tools-label">Phone *</label><input type="text" name="phone" required class="tools-input"></div>
                    </div>
                    <div class="tools-flex">
                        <div><label class="tools-label">First Name *</label><input type="text" name="first_name" required class="tools-input"></div>
                        <div><label class="tools-label">Last Name *</label><input type="text" name="last_name" required class="tools-input"></div>
                    </div>
                    <div style="margin-bottom: 15px;">
                        <label class="tools-label">Delivery Address</label><input type="text" name="address" class="tools-input">
                    </div>
                    <hr style="border:0; border-top:1px solid #e2e8f0; margin:25px 0;">
                    <div class="tools-flex">
                        <div>
                            <label class="tools-label">Delivery Method</label>
                            <select name="delivery_method" class="tools-input">
                                <option value="Delivery">Home/Office Delivery</option>
                                <option value="Pickup">Store Pick-up</option>
                            </select>
                        </div>
                        <div>
                            <label class="tools-label">Receive By</label>
                            <select name="delivery_timing" class="tools-input">
                                <option value="Deliver Day Before">Deliver Day Before</option>
                                <option value="Deliver Same Day">Deliver Same Day</option>
                            </select>
                        </div>
                        <div>
                            <label class="tools-label">Time Slot</label>
                            <select name="time_slot" class="tools-input">
                                <option value="5:00 AM to 6:00 AM">5:00 AM to 6:00 AM</option>
                                <option value="6:00 AM to 7:00 AM">6:00 AM to 7:00 AM</option>
                                <option value="7:00 AM to 8:00 AM">7:00 AM to 8:00 AM</option>
                                <option value="8:00 AM to 9:00 AM" selected>8:00 AM to 9:00 AM</option>
                                <option value="5:00 PM to 8:00 PM">5:00 PM to 8:00 PM</option>
                            </select>
                        </div>
                    </div>
                    <div style="margin-bottom: 20px;">
                        <label class="tools-label">Allergies</label><input type="text" name="allergies" placeholder="e.g., Nuts, Shellfish" class="tools-input">
                    </div>
                    <hr style="border:0; border-top:1px solid #e2e8f0; margin:25px 0;">
                    <div class="tools-flex">
                        <div style="flex:2;">
                            <label class="tools-label">Select Plan Quota *</label>
                            <select name="plan_name" class="tools-input">
                                <option value="1 Meal Plan (Manual)">1 Meal Plan</option>
                                <option value="2 Meal Plan (Manual)">2 Meal Plan</option>
                                <option value="3 Meal Plan (Manual)">3 Meal Plan</option>
                                <option value="Juice Cleanse (Manual)">Juice Cleanse</option>
                            </select>
                        </div>
                        <div style="flex:1;">
                            <label class="tools-label">Recipient Name</label>
                            <input type="text" name="recipient_name" class="tools-input" placeholder="e.g. Sarah">
                        </div>
                        <div style="flex:1;">
                            <label class="tools-label">Remaining Days *</label>
                            <input type="number" name="remaining_days" required min="1" class="tools-input" placeholder="e.g. 14">
                        </div>
                    </div>
                    <button type="submit" name="cmp_frontend_manual_import" style="background: #0284c7; color: white; border: none; padding: 12px 30px; border-radius: 4px; font-weight: bold; cursor: pointer;">Import Single Customer</button>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($can_cleanup): ?>
        <!-- TAB 2: CLEANUP -->
        <div id="tab-cleanup" class="tools-content <?php echo $active_tab == 'tab-cleanup' ? 'active' : ''; ?>">
            <?php echo $cleanup_message; ?>
            <div class="tools-card" style="border-left: 4px solid #dc2626;">
                <h3 style="margin-top: 0; color: #991b1b; border-bottom: 1px solid #eee; padding-bottom: 15px;">Purge Old Subscription Data</h3>
                <p style="color: #64748b; margin-bottom: 25px;">Permanently delete expired subscriptions and logs. Customer profiles and addresses will not be deleted.</p>
                <form method="POST">
                    <?php wp_nonce_field('cmp_frontend_cleanup', 'cmp_cleanup_nonce'); ?>
                    <div style="margin-bottom: 20px;">
                        <label class="tools-label">Target Timeframe:</label>
                        Delete plans expired more than <input type="number" name="days_old" value="90" min="30" style="width: 80px; padding: 8px; border: 1px solid #cbd5e1; border-radius: 4px;"> days ago. (Min 30)
                    </div>
                    <div style="margin-bottom: 25px;">
                        <label style="color: #dc2626; font-weight: bold; cursor: pointer;">
                            <input type="checkbox" name="confirm_delete" value="1" required style="transform: scale(1.2); margin-right: 8px;"> 
                            I understand this is irreversible.
                        </label>
                    </div>
                    <button type="submit" name="cmp_frontend_run_cleanup" style="background: #dc2626; color: white; border: none; padding: 12px 30px; border-radius: 4px; font-weight: bold; cursor: pointer;">Permanently Delete Old Records</button>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <!-- TAB 3: DEPOSIT MANAGER -->
        <div id="tab-deposit" class="tools-content <?php echo $active_tab == 'tab-deposit' ? 'active' : ''; ?>">
            <?php echo $deposit_message; ?>
            
            <div style="display: flex; gap: 20px; flex-wrap: wrap; align-items: flex-start;">
                
                <!-- LEFT COLUMN: SEARCH & UPDATE -->
                <div style="flex: 2; min-width: 400px; background: #fff; padding: 20px 30px; border: 1px solid #ccd0d4; border-radius: 4px; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                    <p style="color: #64748b; margin-bottom: 20px;">Search by Name, Email, or Phone to view or update a customer's Bag Deposit Wallet.</p>
                    
                    <form method="POST" style="display: flex; gap: 15px; margin-bottom: 25px;">
                        <?php wp_nonce_field('cmp_frontend_deposit', 'cmp_deposit_nonce'); ?>
                        <input type="text" name="search_term" placeholder="Search by Name, Email, or Phone Number..." value="<?php echo esc_attr($raw_query); ?>" required style="flex: 1; padding: 10px; border: 1px solid #cbd5e1; border-radius: 4px;">
                        <button type="submit" name="cmp_frontend_search_deposit" style="background: #f59e0b; color: white; border: none; padding: 10px 25px; border-radius: 4px; font-weight: bold; cursor: pointer;">Search Customer</button>
                    </form>

                    <?php if (!empty($found_users)): 
                        foreach ($found_users as $searched_user):
                            $deposit_held = get_user_meta($searched_user->ID, '_cmp_deposit_held', true);
                            if ($deposit_held === '') {
                                $past_plans = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}cmp_subscriptions WHERE user_id = %d", $searched_user->ID));
                                $deposit_held = (intval($past_plans) > 0) ? 'yes' : 'no';
                            }
                            
                            $status_color = '#dc2626'; $status_bg = '#fee2e2'; $status_text = 'Refunded / No Deposit (Charge AED 150)';
                            if ($deposit_held === 'yes') { $status_color = '#10b981'; $status_bg = '#dcfce7'; $status_text = 'Bag Deposit Held (Waived)'; }
                            if ($deposit_held === 'own_bag') { $status_color = '#8b5cf6'; $status_bg = '#ede9fe'; $status_text = "Customer's Own Bag (Waived)"; }
                    ?>
                    <div style="background: #f8fafc; padding: 20px; border: 1px solid #e2e8f0; border-left: 4px solid <?php echo $status_color; ?>; border-radius: 6px; margin-bottom: 15px;">
                        <h4 style="margin: 0 0 5px 0; color: #0f172a; font-size: 1.2em;"><?php echo esc_html($searched_user->first_name . ' ' . $searched_user->last_name); ?></h4>
                        <p style="margin: 0 0 5px 0; color: #64748b;"><?php echo esc_html($searched_user->user_email); ?></p>
                        <?php $phone = get_user_meta($searched_user->ID, 'billing_phone', true); if($phone): ?>
                            <p style="margin: 0 0 15px 0; color: #64748b; font-size: 0.9em;">Phone: <?php echo esc_html($phone); ?></p>
                        <?php endif; ?>
                        
                        <p style="font-size: 1em; margin-bottom: 15px;"><strong>Status:</strong> 
                            <span style="color: <?php echo $status_color; ?>; font-weight: bold; background: <?php echo $status_bg; ?>; padding: 4px 10px; border-radius: 4px; font-size: 0.9em;"><?php echo $status_text; ?></span>
                        </p>
                        
                        <form method="POST" style="margin: 0; display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                            <?php wp_nonce_field('cmp_frontend_deposit', 'cmp_deposit_nonce'); ?>
                            <input type="hidden" name="search_term" value="<?php echo esc_attr($raw_query); ?>">
                            <input type="hidden" name="user_id" value="<?php echo $searched_user->ID; ?>">
                            <select name="new_status" style="padding: 8px; border: 1px solid #cbd5e1; border-radius: 4px;">
                                <option value="yes" <?php selected($deposit_held, 'yes'); ?>>Deposit Held</option>
                                <option value="own_bag" <?php selected($deposit_held, 'own_bag'); ?>>Customer's Own Bag</option>
                                <option value="no" <?php selected($deposit_held, 'no'); ?>>Refunded / No Deposit</option>
                            </select>
                            <button type="submit" name="cmp_frontend_toggle_deposit" class="button" style="background: #334155; color: #fff; border: none; padding: 8px 20px; border-radius: 4px; font-weight: bold; cursor: pointer;">
                                Update Wallet
                            </button>
                        </form>
                    </div>
                    <?php endforeach; endif; ?>
                </div>
                
                <!-- RIGHT COLUMN: MAGIC LINKS -->
                <div style="flex: 1; min-width: 300px; background: #fff; padding: 20px 30px; border: 1px solid #ccd0d4; border-radius: 4px; box-shadow: 0 1px 1px rgba(0,0,0,.04); border-top: 4px solid #8b5cf6;">
                    <h3 style="margin-top: 0; color: #7c3aed; border-bottom: 1px solid #eee; padding-bottom: 10px;">"Own Bag" VIP Links</h3>
                    <p style="color: #64748b; font-size: 0.9em; margin-bottom: 20px;">Generate secure, single-use checkout links that automatically waive the AED 150 deposit and tag the new customer as "Own Bag". The link auto-destroys once the order is placed.</p>
                    
                    <form method="POST" style="margin-bottom: 25px;">
                        <?php wp_nonce_field('cmp_frontend_deposit', 'cmp_deposit_nonce'); ?>
                        <button type="submit" name="cmp_frontend_generate_magic_link" class="button button-primary" style="background: #7c3aed; border-color: #7c3aed; font-weight: bold; width: 100%;">+ Generate New Link</button>
                    </form>

                    <?php 
                    $active_links = get_option('cmp_active_magic_links', array());
                    if (!empty($active_links)): 
                    ?>
                    <table class="wp-list-table widefat fixed striped" style="width: 100%; border-collapse: collapse; text-align: left;">
                        <thead>
                            <tr>
                                <th style="padding: 8px;">Active Link URL</th>
                                <th style="padding: 8px; width: 70px;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($active_links as $token => $data): 
                                $checkout_url = site_url('/meal-plan-checkout/?vip_token=' . $token);
                            ?>
                            <tr>
                                <td style="padding: 8px;">
                                    <input type="text" value="<?php echo esc_attr($checkout_url); ?>" readonly style="width: 100%; padding: 6px; background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 0.85em; cursor: copy;" onclick="this.select(); document.execCommand('copy'); alert('Link copied to clipboard!');">
                                    <div style="font-size: 0.8em; color: #94a3b8; margin-top: 3px;">Created by: <?php echo esc_html($data['by']); ?></div>
                                </td>
                                <td style="padding: 8px; vertical-align: middle;">
                                    <form method="POST" style="margin: 0;">
                                        <?php wp_nonce_field('cmp_frontend_deposit', 'cmp_deposit_nonce'); ?>
                                        <input type="hidden" name="revoke_token" value="<?php echo esc_attr($token); ?>">
                                        <button type="submit" name="cmp_frontend_revoke_magic_link" style="background: #ef4444; color: white; border: none; padding: 5px 10px; border-radius: 4px; cursor: pointer; font-size: 0.85em; font-weight: bold;">Revoke</button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php else: ?>
                        <p style="color: #94a3b8; font-style: italic; text-align: center;">No active links.</p>
                    <?php endif; ?>
                </div>
            </div>

        </div>

    </div>

    <script>
    function switchToolsTab(evt, tabId) {
        document.querySelectorAll(".tools-content").forEach(c => c.classList.remove("active"));
        document.querySelectorAll(".tools-tab-btn").forEach(b => b.classList.remove("active"));
        const target = document.getElementById(tabId);
        if (target) { target.classList.add("active"); }
        if (evt && evt.currentTarget) { evt.currentTarget.classList.add("active"); }
    }
    </script>
    <?php
    return ob_get_clean();
}
