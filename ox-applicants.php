<?php
/**
 * Plugin Name: OX Applicants
 * Plugin URI: https://github.com/ox-applicants
 * Description: Handle applicant form submissions, user creation, and application management with WooCommerce Subscriptions integration.
 * Version: 1.1.0
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
define('OX_APPLICANTS_VERSION', '1.1.0');
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
        // Get the default template from the admin class
        require_once OX_APPLICANTS_PLUGIN_DIR . 'includes/class-ox-applicants-admin.php';
        $admin = new OX_Applicants_Admin();
        $default_template = $admin->get_default_email_template();
        add_option('ox_applicants_email_template', $default_template);
    }
    
    // Flush rewrite rules for any potential changes
    flush_rewrite_rules();
    
    // error_log('OX Applicants: Version 1.1.0 update completed');
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