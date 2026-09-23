<?php
/**
 * Plugin Name: Meal Plan Admin Tools
 * Description: Standalone backend utilities for the Meal Plan system (Manual Legacy Importer, Bulk CSV Importer, & Database Cleanup).
 * Version: 1.2
 * Author: Fareed M Rifaideen
 */

// Prevent direct file access
if ( ! defined( 'ABSPATH' ) ) { exit; }

// ==========================================
// 1. REGISTER INDEPENDENT ADMIN MENU (BACKEND)
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
// 2. CSV TEMPLATE DOWNLOADER (AVAILABLE FRONT & BACK)
// ==========================================
add_action( 'init', 'cmp_download_csv_template' ); // Changed to init so frontend portals can trigger it
function cmp_download_csv_template() {
    if ( isset( $_GET['cmp_download_template'] ) && (current_user_can( 'manage_options' ) || current_user_can( 'menu_manager' )) ) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="Meal_Plan_Bulk_Import_Template.csv"');
        $output = fopen('php://output', 'w');
        fputs($output, chr(0xEF) . chr(0xBB) . chr(0xBF)); 

        // Define exact headers required for the bulk importer
        fputcsv($output, array('Email', 'First Name', 'Last Name', 'Phone', 'Address', 'Delivery Method (Delivery/Pickup)', 'Receive By (Deliver Day Before/Deliver Same Day)', 'Time Slot', 'Allergies', 'Plan Name', 'Remaining Days'));
        
        // Add a sample row to guide the FOH Manager
        fputcsv($output, array('john@example.com', 'John', 'Doe', '0501234567', 'Dubai Marina', 'Delivery', 'Deliver Day Before', '5:00 AM to 6:00 AM', 'Nuts', '2 Meal Plan', '20'));

        fclose($output);
        exit;
    }
}

// ==========================================
// 3. BACKEND: SUBSCRIBER IMPORT TOOL 
// ==========================================
function cmp_standalone_render_manual_import() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Insufficient permissions.' );
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

                // Skip the header row
                fgetcsv($handle, 1000, ",");

                while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                    $row_count++;
                    
                    // Safely map CSV columns to variables
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

                    // Strict Validation: Skip row if critical data is missing
                    if (empty($email) || empty($plan_name) || $remaining_days <= 0) {
                        $failed_rows[] = "Row $row_count: Missing Email, Plan Name, or Remaining Days.";
                        continue;
                    }

                    // 1. Fetch or Create User Profile
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

                    // 2. Save Logistics to User Meta
                    wp_update_user(array('ID' => $user_id, 'first_name' => $first_name, 'last_name' => $last_name));
                    if(!empty($phone)) update_user_meta($user_id, 'billing_phone', $phone);
                    if(!empty($address)) update_user_meta($user_id, 'billing_address_1', $address);
                    if(!empty($method)) update_user_meta($user_id, 'delivery_method', $method);
                    if(!empty($timing)) update_user_meta($user_id, 'delivery_timing', $timing);
                    if(!empty($time_slot)) update_user_meta($user_id, 'time_slot', $time_slot);
                    if(!empty($allergies)) update_user_meta($user_id, 'allergies', $allergies);

                    // 3. Determine Categories for the Quota System
                    if (stripos($plan_name, 'juice') !== false || stripos($plan_name, 'cleanse') !== false) {
                        $categories = 'Juices';
                    } else {
                        $categories = 'Breakfast,Lunch,Dinner,Snacks';
                    }

                    // 4. Inject Directly into Subscription Database
                    $inserted = $wpdb->insert($table_subs, array(
                        'user_id'            => $user_id,
                        'wc_order_id'        => 0, // Order ID 0 flags this as a manual import
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

                // Build Final Success/Failure Message
                if ($success_count > 0) {
                    $message .= '<div class="notice notice-success"><p><strong>Success!</strong> Successfully imported <strong>' . $success_count . '</strong> customers from the CSV file.</p></div>';
                }
                if (!empty($failed_rows)) {
                    $err_list = implode('<br>', $failed_rows);
                    $message .= '<div class="notice notice-error"><p><strong>Warning:</strong> The following rows failed to import:<br>' . $err_list . '</p></div>';
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
                    $message = '<div class="notice notice-success"><p><strong>Success!</strong> ' . esc_html($first_name) . ' has been manually imported and assigned <strong>' . $remaining_days . ' days</strong>.</p></div>';
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
                <a href="<?php echo admin_url('admin-ajax.php?action=cmp_download_template'); ?>" class="button" style="background: #1d6f42; color: #fff; border: none; font-weight: bold;">Download CSV Template</a>
            </div>
            
            <p style="color: #666; margin-bottom: 20px;">Download the template above, fill it out strictly matching the column headers, and upload it here to import dozens of customers at once.</p>
            
            <form method="POST" action="" enctype="multipart/form-data" style="display: flex; align-items: center; gap: 15px; background: #f8f9fa; padding: 15px; border: 1px dashed #ccc; border-radius: 4px;">
                <?php wp_nonce_field( 'cmp_csv_import_action', 'cmp_csv_import_nonce' ); ?>
                <input type="file" name="csv_file" accept=".csv" required style="font-size: 1em;">
                <button type="submit" name="cmp_run_csv_import" class="button button-primary" style="background: #1d6f42; border-color: #1d6f42;">Process CSV Upload</button>
            </form>
        </div>
        
        <div style="background: #fff; padding: 20px 30px; border: 1px solid #ccd0d4; border-radius: 4px; max-width: 800px; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
            <h2 style="margin-top: 0; border-bottom: 1px solid #eee; padding-bottom: 10px;">Single Manual Entry Form</h2>
            <p style="color: #666; margin-bottom: 25px;">Use this tool to add a single customer who paid offline or is halfway through an old plan. If the email address doesn't exist in the system, a new account will be auto-created.</p>
            
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

                <button type="submit" name="cmp_run_import" class="button button-primary" style="background: #0073aa; padding: 5px 30px;">Import Single Customer</button>
            </form>
        </div>
    </div>
    <?php
}

// ==========================================
// 4. BACKEND: DATABASE CLEANUP TOOL
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

// ==========================================
// 5. FRONTEND: ADMIN TOOLS PORTAL
// ==========================================
add_shortcode( 'meal_admin_tools', 'cmp_render_frontend_admin_tools' );
function cmp_render_frontend_admin_tools() {
    
    // 1. Security Check
    if ( ! is_user_logged_in() ) {
        $login_args = array('echo' => false, 'form_id' => 'cmp-tools-login', 'label_username' => __('Email Address or Username'), 'label_password' => __('Password'));
        $custom_css = '<style>#cmp-tools-login label { display: block; margin-bottom: 5px; font-weight: bold; color: #333; text-align: left; } #cmp-tools-login input[type="text"], #cmp-tools-login input[type="password"] { width: 100%; padding: 10px; margin-bottom: 15px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; } #cmp-tools-login .login-submit input[type="submit"] { width: 100%; background: #0073aa; color: white; border: none; padding: 12px; border-radius: 4px; font-weight: bold; cursor: pointer; }</style>';
        return $custom_css . '<div style="max-width:400px; margin:50px auto; padding:30px; background:#f8f9fa; border-radius:8px; border:1px solid #ddd; box-shadow: 0 2px 10px rgba(0,0,0,0.05);"><h2 style="text-align:center; margin-top:0; color:#222;">Admin Tools Login</h2><p style="text-align:center; color:#666; margin-bottom:20px;">Please log in with an authorized account.</p>' . wp_login_form( $login_args ) . '</div>';
    }

    if ( !current_user_can('manage_options') && !current_user_can('menu_manager') ) {
        return '<div style="max-width: 600px; margin: 50px auto; padding: 30px; background: #fff; border-left: 4px solid #dc3232; box-shadow: 0 4px 6px rgba(0,0,0,0.05);"><p style="font-size: 1.1em; color: #dc3232;"><strong>Access Denied:</strong> You do not have permission to view Admin Tools.</p></div>';
    }

    global $wpdb;
    $table_subs = $wpdb->prefix . 'cmp_subscriptions';
    $table_logs = $wpdb->prefix . 'cmp_daily_logs';
    $import_message = '';
    $cleanup_message = '';

    // --- HANDLE FORM SUBMISSIONS ON FRONTEND ---

    // A. CSV Bulk Import
    if ( isset( $_POST['cmp_frontend_csv_import'] ) && wp_verify_nonce($_POST['cmp_import_nonce'], 'cmp_frontend_import') ) {
        if ( !empty( $_FILES['csv_file']['tmp_name'] ) ) {
            $file = $_FILES['csv_file']['tmp_name'];
            $handle = fopen($file, "r");
            if ($handle !== FALSE) {
                $row_count = 0; $success_count = 0; $failed_rows = array();
                fgetcsv($handle, 1000, ","); // skip header
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

                    if (stripos($plan_name, 'juice') !== false || stripos($plan_name, 'cleanse') !== false) {
                        $categories = 'Juices';
                    } else { $categories = 'Breakfast,Lunch,Dinner,Snacks'; }

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
    if ( isset( $_POST['cmp_frontend_manual_import'] ) && wp_verify_nonce($_POST['cmp_import_nonce'], 'cmp_frontend_import') ) {
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

            if (stripos($plan_name, 'juice') !== false || stripos($plan_name, 'cleanse') !== false) {
                $categories = 'Juices';
            } else { $categories = 'Breakfast,Lunch,Dinner,Snacks'; }

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
    if ( isset( $_POST['cmp_frontend_run_cleanup'] ) && wp_verify_nonce($_POST['cmp_cleanup_nonce'], 'cmp_frontend_cleanup') ) {
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
                <p style="margin: 5px 0 0 0; color: #94a3b8;">Legacy Imports & Database Maintenance</p>
            </div>
        </div>

        <div class="tools-nav">
            <button class="tools-tab-btn active" onclick="switchToolsTab(event, 'tab-import')">Import Subscribers</button>
            <button class="tools-tab-btn" onclick="switchToolsTab(event, 'tab-cleanup')">Database Cleanup</button>
        </div>

        <!-- TAB 1: IMPORTER -->
        <div id="tab-import" class="tools-content active">
            <?php echo $import_message; ?>
            
            <div class="tools-card" style="border-left: 4px solid #10b981;">
                <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #eee; padding-bottom: 15px; margin-bottom: 20px;">
                    <h3 style="margin: 0; color: #047857;">Bulk CSV Import</h3>
                    <a href="?cmp_download_template=1" style="background: #10b981; color: white; padding: 8px 15px; border-radius: 4px; text-decoration: none; font-weight: bold; font-size: 0.9em;">Download CSV Template</a>
                </div>
                <p style="color: #64748b; margin-bottom: 20px;">Download the template above, fill it out exactly as formatted, and upload it here to import multiple customers.</p>
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
                            <label class="tools-label">Remaining Days *</label>
                            <input type="number" name="remaining_days" required min="1" class="tools-input" placeholder="e.g. 14">
                        </div>
                    </div>
                    <button type="submit" name="cmp_frontend_manual_import" style="background: #0284c7; color: white; border: none; padding: 12px 30px; border-radius: 4px; font-weight: bold; cursor: pointer;">Import Single Customer</button>
                </form>
            </div>
        </div>

        <!-- TAB 2: CLEANUP -->
        <div id="tab-cleanup" class="tools-content">
            <?php echo $cleanup_message; ?>
            <div class="tools-card" style="border-left: 4px solid #dc2626;">
                <h3 style="margin-top: 0; color: #991b1b; border-bottom: 1px solid #eee; padding-bottom: 15px;">Purge Old Subscription Data</h3>
                <p style="color: #64748b; margin-bottom: 25px;">Permanently delete old subscriptions and daily meal logs. <strong>Customer accounts and addresses will NOT be deleted.</strong></p>
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

    </div>

    <script>
    function switchToolsTab(evt, tabId) {
        document.querySelectorAll(".tools-content").forEach(c => c.classList.remove("active"));
        document.querySelectorAll(".tools-tab-btn").forEach(b => b.classList.remove("active"));
        document.getElementById(tabId).classList.add("active");
        evt.currentTarget.classList.add("active");
    }
    </script>
    <?php
    return ob_get_clean();
}
