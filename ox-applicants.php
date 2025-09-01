<?php
/**
 * Plugin Name: OX Applicants
 * Plugin URI: https://github.com/ox-applicants
 * Description: Handle applicant form submissions, user creation, and application management with WooCommerce Subscriptions integration.
 * Version: 1.1.2
 * Author: Andy McLeod
 * License: GPL2+
 * Text Domain: ox-applicants
 * Requires at least: 5.0
 * Tested up to: 6.4
 * Requires PHP: 7.4
 *
 * @package OXApplicants
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Define plugin constants
define('OX_APPLICANTS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('OX_APPLICANTS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('OX_APPLICANTS_VERSION', '1.1.2');
define('OX_APPLICANTS_VERSION_OPTION', 'ox_applicants_version');

// Check for required plugins
function ox_applicants_check_dependencies() {
    $missing_plugins = [];
    
    // Check for FluentForms
    if (!class_exists('FluentForm') && !class_exists('FluentForm\App\Modules\Form\Form')) {
        $missing_plugins[] = 'FluentForms';
        // error_log('OX Applicants Debug: FluentForms dependency check failed');
    } else {
        // error_log('OX Applicants Debug: FluentForms dependency check passed');
    }
    
    // Check for WooCommerce Subscriptions - try multiple possible class names
    if (!class_exists('WC_Subscriptions') && !class_exists('WC_Subscription') && !function_exists('wcs_get_subscription')) {
        $missing_plugins[] = 'WooCommerce Subscriptions';
    }
    
    if (!empty($missing_plugins)) {
        add_action('admin_notices', function() use ($missing_plugins) {
            $plugin_list = implode(', ', $missing_plugins);
            echo '<div class="notice notice-error"><p>';
            echo sprintf(
                __('OX Applicants requires the following plugins to be installed and activated: %s', 'ox-applicants'),
                $plugin_list
            );
            echo '</p></div>';
        });
        return false;
    }
    
    return true;
}

// Check for updates and run migrations
function ox_applicants_check_updates() {
    $current_version = get_option(OX_APPLICANTS_VERSION_OPTION, '0.0.0');
    
    if (version_compare($current_version, OX_APPLICANTS_VERSION, '<')) {
        // error_log('OX Applicants: Updating from version ' . $current_version . ' to ' . OX_APPLICANTS_VERSION);
        
        // Run updates in sequence
        if (version_compare($current_version, '1.0.0', '<')) {
            ox_applicants_update_to_1_0_0();
        }
        
        if (version_compare($current_version, '1.1.0', '<')) {
            ox_applicants_update_to_1_1_0();
        }
        
        if (version_compare($current_version, '1.1.1', '<')) {
            ox_applicants_update_to_1_1_1();
        }
        
        if (version_compare($current_version, '1.1.2', '<')) {
            ox_applicants_update_to_1_1_2();
        }
        
        update_option(OX_APPLICANTS_VERSION_OPTION, OX_APPLICANTS_VERSION);
        // error_log('OX Applicants: Update completed successfully');
    }
}

// Update to version 1.0.0
function ox_applicants_update_to_1_0_0() {
    // error_log('OX Applicants: Running update to version 1.0.0');
    
    // Create user roles
    OX_Applicants_Core::create_applicant_role();
    OX_Applicants_Core::create_member_role();
    
    // Initialize legacy last action dates for existing applications
    $applications = get_posts([
        'post_type' => 'ox_application',
        'post_status' => 'publish',
        'numberposts' => -1,
        'meta_query' => [
            [
                'key' => '_last_action_date',
                'compare' => 'NOT EXISTS'
            ]
        ]
    ]);

    if (!empty($applications)) {
        // error_log('OX Applicants: Initializing last action dates for ' . count($applications) . ' applications');
        
        foreach ($applications as $application) {
            update_post_meta($application->ID, '_last_action_date', current_time('mysql'));
        }
    }
    
    // Flush rewrite rules for custom post type
    flush_rewrite_rules();
    
    // error_log('OX Applicants: Version 1.0.0 update completed');
}

// Update to version 1.1.0
function ox_applicants_update_to_1_1_0() {
    // error_log('OX Applicants: Running update to version 1.1.0');
    
    // Add default email template option if it doesn't exist
    if (!get_option('ox_applicants_email_template')) {
        // Use a simple default template instead of trying to instantiate the admin class
        $default_template = ox_applicants_get_default_email_template();
        add_option('ox_applicants_email_template', $default_template);
    }
    
    // Flush rewrite rules for any potential changes
    flush_rewrite_rules();
    
    // error_log('OX Applicants: Version 1.1.0 update completed');
}

// Update to version 1.1.1
function ox_applicants_update_to_1_1_1() {
    // error_log('OX Applicants: Running update to version 1.1.1');
    
    // No database changes needed for this version
    // This version only includes CSV export fixes
    
    // Flush rewrite rules for any potential changes
    flush_rewrite_rules();
    
    // error_log('OX Applicants: Version 1.1.1 update completed');
}

// Update to version 1.1.2
function ox_applicants_update_to_1_1_2() {
    // error_log('OX Applicants: Running update to version 1.1.2');
    
    // Add duplicate status to existing applications that might be duplicates
    // This is a safety measure for any applications that might have been created
    // before the duplicate detection was implemented
    
    // Flush rewrite rules for any potential changes
    flush_rewrite_rules();
    
    // error_log('OX Applicants: Version 1.1.2 update completed');
}

// Helper function for default email template (can be called from global scope)
function ox_applicants_get_default_email_template(): string {
    return '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Application Accepted - Subscription Invoice</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #f8f9fa; padding: 20px; text-align: center; border-radius: 5px; margin-bottom: 30px; }
        .content { background: #fff; padding: 30px; border: 1px solid #ddd; border-radius: 5px; }
        .subscription-details { background: #f8f9fa; padding: 20px; border-radius: 5px; margin: 20px 0; }
        .payment-button { display: inline-block; background: #0073aa; color: #fff; padding: 15px 30px; text-decoration: none; border-radius: 5px; font-weight: bold; margin: 20px 0; }
        .footer { text-align: center; margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd; color: #666; font-size: 14px; }
        .billing-info { background: #f8f9fa; padding: 15px; border-radius: 5px; margin: 15px 0; }
        .highlight { background: #fff3cd; padding: 10px; border-left: 4px solid #ffc107; margin: 15px 0; }
        .password-setup { background: #d4edda; padding: 15px; border-radius: 5px; margin: 15px 0; }
    </style>
</head>
<body>
    <div class="header">
        <h1>🎉 Your Application Has Been Accepted!</h1>
        <p>Welcome to {site_name}</p>
    </div>
    
    <div class="content">
        <p>Dear {customer_first_name},</p>
        
        <p>Congratulations! Your application has been accepted and your membership is now active. You now have access to all member-only content and benefits.</p>
        
        <div class="highlight">
            <strong>Important:</strong> To complete your membership setup, please follow these steps:
        </div>
        
        <div class="password-setup">
            <h3>Step 1: Set Your Password</h3>
            <p>Since this is your first time, you need to set up your account password:</p>
            <a href="{password_setup_url}" class="payment-button">Set Password</a>
        </div>
        
        <div class="highlight">
            <h3>Step 2: Complete Payment</h3>
            <p>After setting your password, please complete the payment for your subscription:</p>
            <a href="{payment_url}" class="payment-button">Pay Now</a>
            <p><small><strong>Note:</strong> You must be logged in to complete payment. If no payment options appear, please log in first.</small></p>
        </div>
        
        <h2>Subscription Details</h2>
        <div class="subscription-details">
            <p><strong>Product:</strong> {product_name}</p>
            <p><strong>Billing Cycle:</strong> {billing_text}</p>
            <p><strong>Amount:</strong> {product_price}</p>
            <p><strong>Next Payment:</strong> {next_payment_date}</p>
            <p><strong>Order Number:</strong> #{order_number}</p>
        </div>
        
        <h2>Billing Information</h2>
        <div class="billing-info">
            {billing_address}
        </div>
        
        <p>If you have any questions, please don\'t hesitate to contact us.</p>
        
        <p>Thank you for choosing us!</p>
    </div>
    
    <div class="footer">
        <p>This email was sent from {site_name}</p>
    </div>
</body>
</html>';
}

// Activation hook
register_activation_hook(__FILE__, function () {
    // Create default options
    add_option('ox_applicants_subscription_product_id', '');
    
    // Check for updates
    ox_applicants_check_updates();
    
    // Check dependencies and show notices if needed
    ox_applicants_check_dependencies();
});

// Deactivation hook
register_deactivation_hook(__FILE__, function () {
    // Flush rewrite rules
    flush_rewrite_rules();
});



// Load plugin classes
require_once OX_APPLICANTS_PLUGIN_DIR . 'includes/class-ox-applicants-core.php';
require_once OX_APPLICANTS_PLUGIN_DIR . 'includes/class-ox-applicants-fluentforms.php';
require_once OX_APPLICANTS_PLUGIN_DIR . 'includes/class-ox-applicants-post-types.php';
require_once OX_APPLICANTS_PLUGIN_DIR . 'includes/class-ox-applicants-admin.php';

// Initialize plugin
add_action('plugins_loaded', function () {
    try {
        // Check for updates on every load
        ox_applicants_check_updates();
        
        // Check dependencies before initializing
        if (!ox_applicants_check_dependencies()) {
            return;
        }
        
        // error_log('OX Applicants: Plugin loaded, initializing...');
        
        // Initialize core functionality
        new OX_Applicants_Core();
        
        // Initialize FluentForms integration
        try {
            new OX_Applicants_FluentForms();
            // error_log('OX Applicants: FluentForms integration initialized successfully');
        } catch (Exception $e) {
            error_log('OX Applicants: FluentForms integration failed: ' . $e->getMessage());
        }
        
        // Initialize post types
        new OX_Applicants_Post_Types();
        
        // Initialize admin interface
        if (is_admin()) {
            new OX_Applicants_Admin();
        }
        
        // error_log('OX Applicants: Plugin initialization complete');
    } catch (Exception $e) {
        error_log('OX Applicants: Initialization error: ' . $e->getMessage());
    }
}); 