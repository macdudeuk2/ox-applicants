<?php
/**
 * OX Applicants Core Class
 *
 * @package OXApplicants
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Core functionality for OX Applicants plugin
 */
class OX_Applicants_Core {

    /**
     * Constructor
     */
    public function __construct() {
        add_action('init', [$this, 'init']);
        add_action('admin_init', [$this, 'admin_init']);
    }

    /**
     * Initialize plugin
     */
    public function init(): void {
        // Load text domain for internationalization
        load_plugin_textdomain('ox-applicants', false, dirname(plugin_basename(OX_APPLICANTS_PLUGIN_DIR)) . '/languages');
        
        // error_log('OX Applicants: Core initialization complete');
    }

    /**
     * Admin initialization
     */
    public function admin_init(): void {
        // Admin-specific initialization
    }

    /**
     * Log error with context
     *
     * @param string $message Error message
     * @param array $context Additional context data
     */
    public static function log_error(string $message, array $context = []): void {
        $log_message = 'OX Applicants Error: ' . $message;
        
        if (!empty($context)) {
            $log_message .= ' Context: ' . json_encode($context);
        }
        
        error_log($log_message);
    }

    /**
     * Log debug information
     *
     * @param string $message Debug message
     * @param array $context Additional context data
     */
    public static function log_debug(string $message, array $context = []): void {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $log_message = 'OX Applicants Debug: ' . $message;
            
            if (!empty($context)) {
                $log_message .= ' Context: ' . json_encode($context);
            }
            
            error_log($log_message);
        }
    }

    /**
     * Check if ACF is active and available
     *
     * @return bool
     */
    public static function is_acf_active(): bool {
        return class_exists('ACF') && function_exists('get_field');
    }

    /**
     * Check if WooCommerce Subscriptions is active
     *
     * @return bool
     */
    public static function is_wcs_active(): bool {
        return class_exists('WC_Subscriptions');
    }

    /**
     * Check if WooCommerce Subscriptions is active (alias for is_wcs_active)
     *
     * @return bool
     */
    public static function is_woocommerce_subscriptions_active(): bool {
        return self::is_wcs_active();
    }

    /**
     * Check if current database is SQLite
     *
     * @return bool
     */
    public static function is_sqlite_database(): bool {
        global $wpdb;
        
        // Check if we're using SQLite by looking at the database class name
        if (isset($wpdb->use_mysqli) && !$wpdb->use_mysqli) {
            // Check if it's the SQLite database class
            if (class_exists('WP_SQLite_DB') && $wpdb instanceof WP_SQLite_DB) {
                return true;
            }
        }
        
        // Alternative check: look for SQLite-specific properties or methods
        if (method_exists($wpdb, 'get_db_type')) {
            return strpos($wpdb->get_db_type(), 'sqlite') !== false;
        }
        
        // Check database name for SQLite indicators
        if (isset($wpdb->dbname) && strpos($wpdb->dbname, '.db') !== false) {
            return true;
        }
        
        // Check if we're in a SQLite environment by looking for the mu-plugin
        if (file_exists(WPMU_PLUGIN_DIR . '/sqlite-database-integration/')) {
            return true;
        }
        
        return false;
    }

    /**
     * Check if FluentForms is active
     *
     * @return bool
     */
    public static function is_fluentforms_active(): bool {
        return class_exists('FluentForm');
    }

    /**
     * Get subscription product ID from settings
     *
     * @return int|null
     */
    public static function get_subscription_product_id(): ?int {
        $product_id = get_option('ox_applicants_subscription_product_id');
        return !empty($product_id) ? (int) $product_id : null;
    }

    /**
     * Validate subscription product
     *
     * @param int $product_id Product ID to validate
     * @return bool
     */
    public static function validate_subscription_product(int $product_id): bool {
        if (!self::is_wcs_active()) {
            return false;
        }

        $product = wc_get_product($product_id);
        if (!$product) {
            return false;
        }

        // Check if product is a subscription product
        return WC_Subscriptions_Product::is_subscription($product);
    }

    /**
     * Create "Applicant" role if it doesn't exist
     */
    public static function create_applicant_role(): void {
        if (!get_role('applicant')) {
            // Clone subscriber role
            $subscriber_role = get_role('subscriber');
            $capabilities = $subscriber_role ? $subscriber_role->capabilities : [];
            
            add_role('applicant', __('Applicant', 'ox-applicants'), $capabilities);
            // error_log('OX Applicants: Created applicant role');
        }
    }

    /**
     * Create "Member" role if it doesn't exist
     */
    public static function create_member_role(): void {
        if (!get_role('member')) {
            // Clone subscriber role
            $subscriber_role = get_role('subscriber');
            $capabilities = $subscriber_role ? $subscriber_role->capabilities : [];
            
            add_role('member', __('Member', 'ox-applicants'), $capabilities);
            // error_log('OX Applicants: Created member role');
        }
    }

    /**
     * Get user's access tags from ox-content-blocker
     *
     * @param int $user_id User ID
     * @return array Array of tag slugs
     */
    public static function get_user_access_tags(int $user_id): array {
        // Ensure user_id is an integer
        $user_id = (int) $user_id;
        
        if (!class_exists('OX_Content_Blocker_Tags')) {
            return [];
        }

        return OX_Content_Blocker_Tags::get_user_access_tags($user_id);
    }

    /**
     * Add access tag to user
     *
     * @param int $user_id User ID
     * @param string $tag_slug Tag slug to add
     * @return bool Success status
     */
    public static function add_user_access_tag(int $user_id, string $tag_slug): bool {
        // Ensure user_id is an integer
        $user_id = (int) $user_id;
        
        if (!class_exists('OX_Content_Blocker_Tags')) {
            // error_log('OX Applicants: OX Content Blocker Tags class not found');
            return false;
        }

        $current_tags = self::get_user_access_tags($user_id);
        
        if (!in_array($tag_slug, $current_tags)) {
            $current_tags[] = $tag_slug;
            update_user_meta($user_id, '_oxcb_access_tags', json_encode($current_tags));
            // error_log("OX Applicants: Added tag '{$tag_slug}' to user {$user_id}");
            return true;
        }

        return true; // Tag already exists
    }

    /**
     * Remove access tag from user
     *
     * @param int $user_id User ID
     * @param string $tag_slug Tag slug to remove
     * @return bool Success status
     */
    public static function remove_user_access_tag(int $user_id, string $tag_slug): bool {
        // Ensure user_id is an integer
        $user_id = (int) $user_id;
        
        if (!class_exists('OX_Content_Blocker_Tags')) {
            // error_log('OX Applicants: OX Content Blocker Tags class not found');
            return false;
        }

        $current_tags = self::get_user_access_tags($user_id);
        
        if (in_array($tag_slug, $current_tags)) {
            $current_tags = array_diff($current_tags, [$tag_slug]);
            update_user_meta($user_id, '_oxcb_access_tags', json_encode($current_tags));
            // error_log("OX Applicants: Removed tag '{$tag_slug}' from user {$user_id}");
            return true;
        }

        return true; // Tag doesn't exist
    }
} 