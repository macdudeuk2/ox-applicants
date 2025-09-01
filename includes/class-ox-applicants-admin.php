<?php
/**
 * OX Applicants Admin Class
 *
 * @package OXApplicants
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin interface for OX Applicants plugin
 */
class OX_Applicants_Admin {

    /**
     * Constructor
     */
    public function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'init_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
        add_action('wp_ajax_ox_update_application_status', [$this, 'handle_status_update']);
        add_action('wp_ajax_ox_lookup_product', [$this, 'handle_product_lookup']);
        
        // Handle CSV export at admin_init to prevent HTML output interference
        add_action('admin_init', [$this, 'handle_csv_export']);
        
        // Prevent standard order emails for subscription orders created by our plugin
        add_filter('woocommerce_email_enabled_customer_on_hold_order', [$this, 'maybe_disable_order_email'], 10, 2);
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu(): void {
        // Main menu
        add_menu_page(
            __('Applications & Renewals', 'ox-applicants'),
            __('Applications & Renewals', 'ox-applicants'),
            'manage_options',
            'ox-applications',
            [$this, 'render_applications_page'],
            'dashicons-groups',
            30
        );

        // Applications submenu
        add_submenu_page(
            'ox-applications',
            __('Applications', 'ox-applicants'),
            __('Applications', 'ox-applicants'),
            'manage_options',
            'ox-applications',
            [$this, 'render_applications_page']
        );

        // Settings submenu
        add_submenu_page(
            'ox-applications',
            __('Settings', 'ox-applicants'),
            __('Settings', 'ox-applicants'),
            'manage_options',
            'ox-applications-settings',
            [$this, 'render_settings_page']
        );

        // Renewals submenu (placeholder for now)
        add_submenu_page(
            'ox-applications',
            __('Renewals', 'ox-applicants'),
            __('Renewals', 'ox-applicants'),
            'manage_options',
            'ox-renewals',
            [$this, 'render_renewals_page']
        );
    }

    /**
     * Initialize settings
     */
    public function init_settings(): void {
        register_setting('ox_applicants_options', 'ox_applicants_subscription_product_id');
        register_setting('ox_applicants_options', 'ox_applicants_email_template', [
            'sanitize_callback' => [$this, 'sanitize_email_template']
        ]);
    }

    /**
     * Enqueue admin scripts
     */
    public function enqueue_admin_scripts($hook): void {
        if (strpos($hook, 'ox-applications') === false && strpos($hook, 'ox-renewals') === false) {
            return;
        }

        wp_enqueue_script(
            'ox-applicants-admin',
            OX_APPLICANTS_PLUGIN_URL . 'assets/js/admin.js',
            ['jquery'],
            OX_APPLICANTS_VERSION,
            true
        );

        wp_localize_script('ox-applicants-admin', 'oxApplicantsAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ox_applicants_nonce'),
            'strings' => [
                'confirmStatusChange' => __('Are you sure you want to change the application status?', 'ox-applicants'),
                'statusUpdated' => __('Status updated successfully.', 'ox-applicants'),
                'errorUpdating' => __('Error updating status.', 'ox-applicants'),
            ],
        ]);

        wp_enqueue_style(
            'ox-applicants-admin',
            OX_APPLICANTS_PLUGIN_URL . 'assets/css/admin.css',
            [],
            OX_APPLICANTS_VERSION
        );
    }

    /**
     * Render applications page
     */
    public function render_applications_page(): void {
        // Initialize legacy last action dates (force for debugging)
        $this->initialize_legacy_last_action_dates();

        // Handle status update form submission
        if (isset($_POST['update_status']) && isset($_POST['application_id']) && isset($_POST['application_status'])) {
            $this->handle_status_form_submission();
        }

        $action = $_GET['action'] ?? 'list';
        $application_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

        switch ($action) {
            case 'view':
                if ($application_id) {
                    $this->render_application_detail($application_id);
                } else {
                    $this->render_applications_list();
                }
                break;
            default:
                $this->render_applications_list();
                break;
        }
    }

    /**
     * Initialize last action dates for legacy applications
     */
    private function initialize_legacy_last_action_dates(): void {
        $applications = get_posts([
            'post_type' => 'ox_application',
            'numberposts' => -1,
            'post_status' => 'any'
        ]);

        foreach ($applications as $application) {
            $last_action_date = get_post_meta($application->ID, '_last_action_date', true);
            if (empty($last_action_date)) {
                // Use current_time('mysql') for consistency
                update_post_meta($application->ID, '_last_action_date', current_time('mysql'));
            }
        }
    }

    /**
     * Handle status form submission
     */
    private function handle_status_form_submission(): void {
        // Verify nonce
        if (!wp_verify_nonce($_POST['ox_status_nonce'], 'ox_application_status')) {
            wp_die(__('Security check failed.', 'ox-applicants'));
        }

        $application_id = (int) $_POST['application_id'];
        $new_status = sanitize_text_field($_POST['application_status']);
        $old_status = get_post_meta($application_id, '_status', true);
        
        if (empty($old_status)) {
            $old_status = 'new';
        }

        // Update the status
        update_post_meta($application_id, '_status', $new_status);
        
        // Update the last action date (create if it doesn't exist)
        $current_last_action = get_post_meta($application_id, '_last_action_date', true);
        if (empty($current_last_action)) {
            // For legacy applications, set it to the application creation date initially
            $application_date = get_the_date('mysql', $application_id);
            update_post_meta($application_id, '_last_action_date', $application_date);
        }
        // Always update to current time when status changes
        update_post_meta($application_id, '_last_action_date', current_time('mysql'));
        
        // Handle any special actions based on status change
        $this->handle_status_change($application_id, $new_status, $old_status);

        // Store success message in transient
        set_transient('ox_applicants_status_updated_' . $application_id, true, 30); // 30 seconds
        
        // Redirect back to the application detail page
        $redirect_url = admin_url('admin.php?page=ox-applications&action=view&id=' . $application_id);
        wp_redirect($redirect_url);
        exit;
    }

    /**
     * Render applications list
     */
    private function render_applications_list(): void {
        // Get current filter from URL parameter
        $current_filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : 'waiting';
        
        // Get status counts
        $counts = $this->get_status_counts();
        
        // Get applications with current filter
        $applications = $this->get_applications($current_filter);

        ?>
        <div class="wrap">
            <h1><?php _e('Applications', 'ox-applicants'); ?></h1>

            <!-- Status Filter Links -->
            <div class="ox-status-filters">
                <?php
                $filter_links = [
                    'waiting' => __('Waiting', 'ox-applicants'),
                    'new' => __('New', 'ox-applicants'),
                    'on_hold' => __('On Hold', 'ox-applicants'),
                    'accepted' => __('Accepted', 'ox-applicants'),
                    'rejected' => __('Rejected', 'ox-applicants'),
                    'duplicate' => __('Duplicate', 'ox-applicants'),
                ];

                $link_parts = [];
                foreach ($filter_links as $filter => $label) {
                    $count = $counts[$filter] ?? 0;
                    $is_active = ($current_filter === $filter);
                    $url = add_query_arg('status', $filter, admin_url('admin.php?page=ox-applications'));
                    
                    $link_class = $is_active ? 'ox-filter-link active' : 'ox-filter-link';
                    $link_parts[] = sprintf(
                        '<a href="%s" class="%s">%s (%d)</a>',
                        esc_url($url),
                        esc_attr($link_class),
                        esc_html($label),
                        $count
                    );
                }
                echo implode(' - ', $link_parts);
                ?>
            </div>

            <?php if (empty($applications)): ?>
                <div class="notice notice-info">
                    <p><?php printf(__('No %s applications found.', 'ox-applicants'), strtolower($filter_links[$current_filter] ?? $current_filter)); ?></p>
                </div>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php _e('Applicant', 'ox-applicants'); ?></th>
                            <th><?php _e('Email', 'ox-applicants'); ?></th>
                            <th><?php _e('Status', 'ox-applicants'); ?></th>
                            <th><?php _e('Date of Application', 'ox-applicants'); ?></th>
                            <th><?php _e('Last Action', 'ox-applicants'); ?></th>

                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($applications as $application): ?>
                            <tr>
                                <td>
                                    <strong>
                                        <a href="<?php echo add_query_arg(['action' => 'view', 'id' => $application->ID], admin_url('admin.php?page=ox-applications')); ?>">
                                            <?php echo esc_html($application->post_title); ?>
                                        </a>
                                    </strong>
                                </td>
                                <td><?php echo esc_html(get_post_meta($application->ID, '_email', true)); ?></td>
                                <td>
                                    <?php
                                    $status = get_post_meta($application->ID, '_status', true);
                                    if (empty($status)) {
                                        $status = 'new';
                                    }
                                    echo $this->get_status_badge($status);
                                    ?>
                                </td>
                                <td><?php echo get_the_date('F j, Y g:i a', $application->ID); ?></td>
                                <td>
                                    <?php
                                    $last_action_date = get_post_meta($application->ID, '_last_action_date', true);
                                    if ($last_action_date && strtotime($last_action_date) !== false) {
                                        echo date('F j, Y g:i a', strtotime($last_action_date));
                                    } else {
                                        echo get_the_date('F j, Y g:i a', $application->ID);
                                    }
                                    ?>
                                </td>

                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render application detail view
     */
    private function render_application_detail(int $application_id): void {
        $application = get_post($application_id);
        
        if (!$application || $application->post_type !== 'ox_application') {
            wp_die(__('Application not found.', 'ox-applicants'));
        }

        $user_id = (int) get_post_meta($application_id, '_user_id', true);
        $email = get_post_meta($application_id, '_email', true);
        $phone = get_post_meta($application_id, '_phone', true);
        $address = get_post_meta($application_id, '_address', true);
        $wine_course = get_post_meta($application_id, '_wine_course', true);
        $course_info = get_post_meta($application_id, '_course_info', true);
        $username = get_post_meta($application_id, '_username', true);
        $status = get_post_meta($application_id, '_status', true);
        if (empty($status)) {
            $status = 'new';
        }
        $last_action_date = get_post_meta($application_id, '_last_action_date', true);
        if (!$last_action_date || strtotime($last_action_date) === false) {
            // For display purposes, use the application creation date as fallback
            $last_action_date = get_the_date('mysql', $application_id);
        }

        ?>
        <div class="wrap">
            <?php 
            // Check for success message in transient
            $status_updated = get_transient('ox_applicants_status_updated_' . $application_id);
            if ($status_updated) {
                delete_transient('ox_applicants_status_updated_' . $application_id);
                ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php _e('Application status and last action date updated successfully.', 'ox-applicants'); ?></p>
                </div>
                <?php
            }
            ?>
            
            <h1><?php _e('Application Details', 'ox-applicants'); ?></h1>
            
            <p>
                <a href="<?php echo admin_url('admin.php?page=ox-applications'); ?>" class="button">
                    <?php _e('← Back to Applications', 'ox-applicants'); ?>
                </a>
            </p>

            <div class="ox-application-detail">
                <div class="ox-application-header">
                    <h2><?php echo esc_html($application->post_title); ?></h2>
                    <div class="ox-application-status">
                        <?php echo $this->get_status_badge($status); ?>
                    </div>
                </div>

                <?php if ($status === 'duplicate'): ?>
                    <div class="notice notice-warning" style="margin: 20px 0; padding: 15px; background: #fff3cd; border-left: 4px solid #ffc107;">
                        <h4 style="margin-top: 0; color: #856404;">⚠️ <?php _e('Duplicate Application Detected', 'ox-applicants'); ?></h4>
                        <p style="margin-bottom: 10px;">
                            <strong><?php _e('This application appears to be a duplicate.', 'ox-applicants'); ?></strong>
                            <?php 
                            $existing_user_id = get_post_meta($application_id, '_existing_user_id', true);
                            $duplicate_note = get_post_meta($application_id, '_duplicate_note', true);
                            if ($existing_user_id) {
                                $existing_user = get_user_by('id', $existing_user_id);
                                if ($existing_user) {
                                    echo sprintf(
                                        __('The applicant already has an existing user account (User ID: %d, Email: %s).', 'ox-applicants'),
                                        $existing_user_id,
                                        $existing_user->user_email
                                    );
                                }
                            }
                            if ($duplicate_note) {
                                echo ' ' . esc_html($duplicate_note);
                            }
                            ?>
                        </p>
                        <p style="margin-bottom: 0;">
                            <a href="<?php echo admin_url('user-edit.php?user_id=' . $existing_user_id); ?>" class="button button-secondary" target="_blank">
                                <?php _e('View Existing User Profile', 'ox-applicants'); ?>
                            </a>
                        </p>
                    </div>
                <?php endif; ?>

                <div class="ox-application-content">
                    <div class="ox-application-section">
                        <h3><?php _e('Personal Information', 'ox-applicants'); ?></h3>
                        <table class="form-table">
                            <tr>
                                <th><?php _e('Name:', 'ox-applicants'); ?></th>
                                <td><?php echo esc_html($application->post_title); ?></td>
                            </tr>
                            <tr>
                                <th><?php _e('Email:', 'ox-applicants'); ?></th>
                                <td><?php echo esc_html($email); ?></td>
                            </tr>
                            <tr>
                                <th><?php _e('Username:', 'ox-applicants'); ?></th>
                                <td><?php echo esc_html($username); ?></td>
                            </tr>
                            <tr>
                                <th><?php _e('Phone:', 'ox-applicants'); ?></th>
                                <td><?php echo esc_html($phone); ?></td>
                            </tr>
                            <tr>
                                <th><?php _e('Address:', 'ox-applicants'); ?></th>
                                <td><?php echo nl2br(esc_html($address)); ?></td>
                            </tr>
                        </table>
                    </div>

                    <div class="ox-application-section">
                        <h3><?php _e('Course Information', 'ox-applicants'); ?></h3>
                        <table class="form-table">
                            <tr>
                                <th><?php _e('Wine Course:', 'ox-applicants'); ?></th>
                                <td><?php echo nl2br(esc_html($wine_course)); ?></td>
                            </tr>
                            <tr>
                                <th><?php _e('Course Info:', 'ox-applicants'); ?></th>
                                <td><?php echo nl2br(esc_html($course_info)); ?></td>
                            </tr>
                        </table>
                    </div>

                    <div class="ox-application-section">
                        <h3><?php _e('Application Dates', 'ox-applicants'); ?></h3>
                        <table class="form-table">
                            <tr>
                                <th><?php _e('Date of Application:', 'ox-applicants'); ?></th>
                                <td><?php echo get_the_date('F j, Y g:i a', $application_id); ?></td>
                            </tr>
                            <tr>
                                <th><?php _e('Last Action:', 'ox-applicants'); ?></th>
                                <td>
                                    <?php 
                                    $last_action_date_display = get_post_meta($application_id, '_last_action_date', true);
                                    if ($last_action_date_display && strtotime($last_action_date_display) !== false) {
                                        echo date('F j, Y g:i a', strtotime($last_action_date_display));
                                    } else {
                                        echo get_the_date('F j, Y g:i a', $application_id);
                                    }
                                    ?>
                                </td>
                            </tr>
                            <?php if ($status === 'accepted'): ?>
                                <tr>
                                    <th><?php _e('Accepted Date:', 'ox-applicants'); ?></th>
                                    <td>
                                        <?php 
                                        $accepted_date = get_post_meta($application_id, '_accepted_date', true);
                                        if ($accepted_date && strtotime($accepted_date) !== false) {
                                            echo date('F j, Y g:i a', strtotime($accepted_date));
                                        } else {
                                            echo __('Not recorded', 'ox-applicants');
                                        }
                                        ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th><?php _e('Accepted By:', 'ox-applicants'); ?></th>
                                    <td>
                                        <?php 
                                        $accepted_by = get_post_meta($application_id, '_accepted_by', true);
                                        if ($accepted_by) {
                                            $accepted_user = get_user_by('id', $accepted_by);
                                            echo $accepted_user ? esc_html($accepted_user->display_name) : __('Unknown user', 'ox-applicants');
                                        } else {
                                            echo __('Not recorded', 'ox-applicants');
                                        }
                                        ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </table>
                    </div>

                    <div class="ox-application-section">
                        <h3><?php _e('Application Status', 'ox-applicants'); ?></h3>
                        <form method="post" action="" class="ox-status-form">
                            <?php wp_nonce_field('ox_application_status', 'ox_status_nonce'); ?>
                            <input type="hidden" name="application_id" value="<?php echo $application_id; ?>">
                            
                            <p>
                                <label for="application_status"><?php _e('Status:', 'ox-applicants'); ?></label>
                                <select name="application_status" id="application_status">
                                    <option value="new" <?php selected($status, 'new'); ?>><?php _e('New', 'ox-applicants'); ?></option>
                                    <option value="on_hold" <?php selected($status, 'on_hold'); ?>><?php _e('On Hold', 'ox-applicants'); ?></option>
                                    <option value="accepted" <?php selected($status, 'accepted'); ?>><?php _e('Accepted', 'ox-applicants'); ?></option>
                                    <option value="rejected" <?php selected($status, 'rejected'); ?>><?php _e('Rejected', 'ox-applicants'); ?></option>
                                    <option value="duplicate" <?php selected($status, 'duplicate'); ?>><?php _e('Duplicate', 'ox-applicants'); ?></option>
                                </select>
                                <input type="submit" name="update_status" class="button button-primary" value="<?php _e('Update Status', 'ox-applicants'); ?>" style="margin-left: 10px;">
                            </p>
                        </form>
                    </div>

                    <?php if ($user_id): ?>
                        <div class="ox-application-section">
                            <h3><?php _e('User Information', 'ox-applicants'); ?></h3>
                            <p>
                                <a href="<?php echo admin_url('user-edit.php?user_id=' . $user_id); ?>" class="button" target="_blank">
                                    <?php _e('View User Profile', 'ox-applicants'); ?>
                                </a>
                            </p>
                        </div>
                    <?php endif; ?>

                    <?php if ($status === 'accepted'): ?>
                        <div class="ox-application-section">
                            <h3><?php _e('Membership Information', 'ox-applicants'); ?></h3>
                            <table class="form-table">
                                <tr>
                                    <th><?php _e('User Role:', 'ox-applicants'); ?></th>
                                    <td>
                                        <?php 
                                        if ($user_id) {
                                            $user = get_user_by('id', $user_id);
                                            $role = $user ? $user->roles[0] ?? __('No role', 'ox-applicants') : __('User not found', 'ox-applicants');
                                            echo esc_html(ucfirst($role));
                                        } else {
                                            echo __('N/A', 'ox-applicants');
                                        }
                                        ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th><?php _e('Member Tag:', 'ox-applicants'); ?></th>
                                    <td>
                                        <?php 
                                        if ($user_id) {
                                            $access_tags = OX_Applicants_Core::get_user_access_tags($user_id);
                                            if (in_array('member', $access_tags)) {
                                                echo '<span class="ox-status-badge status-accepted">' . __('Active', 'ox-applicants') . '</span>';
                                            } else {
                                                echo '<span class="ox-status-badge status-rejected">' . __('Not Set', 'ox-applicants') . '</span>';
                                            }
                                        } else {
                                            echo __('N/A', 'ox-applicants');
                                        }
                                        ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th><?php _e('Subscription:', 'ox-applicants'); ?></th>
                                    <td>
                                        <?php 
                                        $subscription_id = get_post_meta($application_id, '_subscription_id', true);
                                        $order_id = get_post_meta($application_id, '_order_id', true);
                                        if ($subscription_id) {
                                            echo '<a href="' . admin_url('post.php?post=' . $subscription_id . '&action=edit') . '" target="_blank">';
                                            echo __('View Subscription', 'ox-applicants') . ' #' . $subscription_id;
                                            echo '</a>';
                                            if ($order_id) {
                                                echo '<br><a href="' . admin_url('post.php?post=' . $order_id . '&action=edit') . '" target="_blank">';
                                                echo __('View Order', 'ox-applicants') . ' #' . $order_id;
                                                echo '</a>';
                                            }
                                        } else {
                                            echo __('Not created yet', 'ox-applicants');
                                        }
                                        ?>
                                    </td>
                                </tr>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render settings page
     */
    public function render_settings_page(): void {
        if (isset($_POST['submit'])) {
            $this->save_settings();
        }

        $subscription_product_id = get_option('ox_applicants_subscription_product_id', '');
        $email_template = get_option('ox_applicants_email_template', $this->get_default_email_template());
        $product_name = '';
        
        // Get product name if ID exists
        if ($subscription_product_id && function_exists('wc_get_product')) {
            $product = wc_get_product($subscription_product_id);
            if ($product) {
                $product_name = $product->get_name();
            }
        }
        ?>
        <div class="wrap">
            <h1><?php _e('OX Applicants Settings', 'ox-applicants'); ?></h1>
            
            <?php settings_errors('ox_applicants'); ?>
            
            <form method="post" action="">
                <?php wp_nonce_field('ox_applicants_settings', 'ox_applicants_nonce'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="subscription_product_id"><?php _e('Subscription Product ID', 'ox-applicants'); ?></label>
                        </th>
                        <td>
                            <input type="text" name="subscription_product_id" id="subscription_product_id" 
                                   value="<?php echo esc_attr($subscription_product_id); ?>" class="regular-text" 
                                   pattern="[0-9]*" inputmode="numeric" />
                            <button type="button" id="lookup_product" class="button"><?php _e('Lookup', 'ox-applicants'); ?></button>
                            <div id="product_lookup_result" style="margin-top: 10px;">
                                <?php if ($product_name): ?>
                                    <p class="description">
                                        <strong><?php _e('Current Product:', 'ox-applicants'); ?></strong> <?php echo esc_html($product_name); ?>
                                    </p>
                                <?php endif; ?>
                            </div>
                            <p class="description">
                                <?php _e('Enter the WooCommerce subscription product ID that will be used for new members.', 'ox-applicants'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="email_template"><?php _e('Acceptance Email Template', 'ox-applicants'); ?></label>
                        </th>
                        <td>
                            <textarea name="email_template" id="email_template" rows="20" cols="80" class="large-text code"><?php echo esc_textarea($email_template); ?></textarea>
                            <p class="description">
                                <?php _e('HTML email template for acceptance notifications. Use the variables below to insert dynamic content:', 'ox-applicants'); ?>
                            </p>
                            <div class="email-template-variables" style="background: #f9f9f9; padding: 15px; border: 1px solid #ddd; margin-top: 10px;">
                                <h4><?php _e('Available Variables:', 'ox-applicants'); ?></h4>
                                <table class="widefat" style="margin-top: 10px;">
                                    <thead>
                                        <tr>
                                            <th><?php _e('Variable', 'ox-applicants'); ?></th>
                                            <th><?php _e('Description', 'ox-applicants'); ?></th>
                                            <th><?php _e('Example', 'ox-applicants'); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td><code>{customer_first_name}</code></td>
                                            <td><?php _e('Customer first name', 'ox-applicants'); ?></td>
                                            <td>John</td>
                                        </tr>
                                        <tr>
                                            <td><code>{customer_last_name}</code></td>
                                            <td><?php _e('Customer last name', 'ox-applicants'); ?></td>
                                            <td>Doe</td>
                                        </tr>
                                        <tr>
                                            <td><code>{customer_email}</code></td>
                                            <td><?php _e('Customer email address', 'ox-applicants'); ?></td>
                                            <td>john@example.com</td>
                                        </tr>
                                        <tr>
                                            <td><code>{product_name}</code></td>
                                            <td><?php _e('Subscription product name', 'ox-applicants'); ?></td>
                                            <td>Premium Membership</td>
                                        </tr>
                                        <tr>
                                            <td><code>{product_price}</code></td>
                                            <td><?php _e('Formatted product price', 'ox-applicants'); ?></td>
                                            <td>$29.99</td>
                                        </tr>
                                        <tr>
                                            <td><code>{billing_cycle}</code></td>
                                            <td><?php _e('Billing cycle (e.g., Monthly, Weekly)', 'ox-applicants'); ?></td>
                                            <td>Monthly</td>
                                        </tr>
                                        <tr>
                                            <td><code>{next_payment_date}</code></td>
                                            <td><?php _e('Next payment date', 'ox-applicants'); ?></td>
                                            <td>January 15, 2025</td>
                                        </tr>
                                        <tr>
                                            <td><code>{order_number}</code></td>
                                            <td><?php _e('Order number', 'ox-applicants'); ?></td>
                                            <td>#1234</td>
                                        </tr>
                                        <tr>
                                            <td><code>{payment_url}</code></td>
                                            <td><?php _e('Secure payment link', 'ox-applicants'); ?></td>
                                            <td>https://example.com/checkout/pay/1234/</td>
                                        </tr>
                                        <tr>
                                            <td><code>{password_setup_url}</code></td>
                                            <td><?php _e('Link for new user to set up password', 'ox-applicants'); ?></td>
                                            <td>https://example.com/wp-login.php?login=john&key=abc123&action=rp</td>
                                        </tr>
                                        <tr>
                                            <td><code>{billing_address}</code></td>
                                            <td><?php _e('Formatted billing address', 'ox-applicants'); ?></td>
                                            <td>123 Main St, City, State 12345</td>
                                        </tr>
                                        <tr>
                                            <td><code>{billing_phone}</code></td>
                                            <td><?php _e('Billing phone number', 'ox-applicants'); ?></td>
                                            <td>+1-555-123-4567</td>
                                        </tr>
                                        <tr>
                                            <td><code>{site_name}</code></td>
                                            <td><?php _e('Website name', 'ox-applicants'); ?></td>
                                            <td>My Website</td>
                                        </tr>
                                        <tr>
                                            <td><code>{site_url}</code></td>
                                            <td><?php _e('Website URL', 'ox-applicants'); ?></td>
                                            <td>https://example.com</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button(); ?>
            </form>
        </div>

        <script>
        jQuery(document).ready(function($) {
            $('#lookup_product').on('click', function() {
                var productId = $('#subscription_product_id').val();
                if (!productId) {
                    alert('<?php _e('Please enter a product ID first.', 'ox-applicants'); ?>');
                    return;
                }
                
                var button = $(this);
                var originalText = button.text();
                button.text('<?php _e('Looking up...', 'ox-applicants'); ?>').prop('disabled', true);
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'ox_lookup_product',
                        product_id: productId,
                        nonce: '<?php echo wp_create_nonce('ox_lookup_product'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            $('#product_lookup_result').html('<p class="description"><strong><?php _e('Product Found:', 'ox-applicants'); ?></strong> ' + response.data.name + '</p>');
                        } else {
                            $('#product_lookup_result').html('<p class="description" style="color: #dc3232;"><strong><?php _e('Error:', 'ox-applicants'); ?></strong> ' + response.data + '</p>');
                        }
                    },
                    error: function() {
                        $('#product_lookup_result').html('<p class="description" style="color: #dc3232;"><strong><?php _e('Error:', 'ox-applicants'); ?></strong> <?php _e('Failed to lookup product.', 'ox-applicants'); ?></p>');
                    },
                    complete: function() {
                        button.text(originalText).prop('disabled', false);
                    }
                });
            });
        });
        </script>
        <?php
    }

    /**
     * Render renewals page
     */
    public function render_renewals_page(): void {
        // Check if WooCommerce Subscriptions is active
        if (!OX_Applicants_Core::is_woocommerce_subscriptions_active()) {
            ?>
            <div class="wrap">
                <h1><?php _e('Renewals', 'ox-applicants'); ?></h1>
                <div class="notice notice-error">
                    <p><?php _e('WooCommerce Subscriptions is required to view renewal information.', 'ox-applicants'); ?></p>
                </div>
            </div>
            <?php
            return;
        }

        // Get filter parameters
        $filter = $_GET['filter'] ?? 'next_30_days';
        $custom_start = $_GET['custom_start'] ?? '';
        $custom_end = $_GET['custom_end'] ?? '';
        
        // Get subscriptions data
        $subscriptions = $this->get_renewals_data($filter, $custom_start, $custom_end);
        $filter_counts = $this->get_renewal_filter_counts();
        
        ?>
        <div class="wrap">
            <h1><?php _e('Renewals', 'ox-applicants'); ?></h1>
            
            <style>
            .ox-renewal-filters {
                margin: 20px 0;
                padding: 15px;
                background: #fff;
                border: 1px solid #ccd0d4;
                border-radius: 4px;
            }
            .ox-renewal-filters .button {
                margin-right: 10px;
                margin-bottom: 10px;
            }
            .ox-custom-date-range {
                display: inline-block;
                vertical-align: top;
                margin-left: 20px;
            }
            .ox-custom-date-range input[type="date"] {
                padding: 5px;
                border: 1px solid #ddd;
                border-radius: 3px;
            }
            .ox-custom-date-range label {
                font-weight: 500;
                margin-right: 5px;
            }
            .ox-export-section {
                margin-top: 10px;
            }
            .ox-renewals-table {
                margin-top: 20px;
            }
            .ox-renewal-method {
                padding: 3px 8px;
                border-radius: 3px;
                font-size: 12px;
                font-weight: 500;
            }
            .ox-renewal-method.manual {
                background: #f0f0f0;
                color: #666;
            }
            .ox-renewal-method.automatic {
                background: #d4edda;
                color: #155724;
            }
            </style>
            
            <!-- Filter Navigation -->
            <div class="ox-renewal-filters">
                <button type="button" 
                        onclick="window.location.href='<?php echo add_query_arg('filter', 'next_30_days'); ?>'" 
                        class="button <?php echo $filter === 'next_30_days' ? 'button-primary' : ''; ?>">
                    <?php _e('Next 30 Days', 'ox-applicants'); ?> (<?php echo $filter_counts['next_30_days']; ?>)
                </button>
                <button type="button" 
                        onclick="window.location.href='<?php echo add_query_arg('filter', 'current_month'); ?>'" 
                        class="button <?php echo $filter === 'current_month' ? 'button-primary' : ''; ?>">
                    <?php _e('Current Month', 'ox-applicants'); ?> (<?php echo $filter_counts['current_month']; ?>)
                </button>
                <button type="button" 
                        onclick="window.location.href='<?php echo add_query_arg('filter', 'next_month'); ?>'" 
                        class="button <?php echo $filter === 'next_month' ? 'button-primary' : ''; ?>">
                    <?php _e('Next Month', 'ox-applicants'); ?> (<?php echo $filter_counts['next_month']; ?>)
                </button>
                <button type="button" 
                        onclick="window.location.href='<?php echo add_query_arg('filter', 'last_month'); ?>'" 
                        class="button <?php echo $filter === 'last_month' ? 'button-primary' : ''; ?>">
                    <?php _e('Last Month', 'ox-applicants'); ?> (<?php echo $filter_counts['last_month']; ?>)
                </button>
                
                <!-- Custom Date Range Form -->
                <div class="ox-custom-date-range">
                    <form method="get" style="display: inline-block; margin-left: 10px;">
                        <input type="hidden" name="page" value="ox-renewals">
                        <input type="hidden" name="filter" value="custom">
                        <label for="custom_start"><?php _e('From:', 'ox-applicants'); ?></label>
                        <input type="date" id="custom_start" name="custom_start" value="<?php echo esc_attr($custom_start); ?>" style="margin: 0 5px;">
                        <label for="custom_end"><?php _e('To:', 'ox-applicants'); ?></label>
                        <input type="date" id="custom_end" name="custom_end" value="<?php echo esc_attr($custom_end); ?>" style="margin: 0 5px;">
                        <button type="submit" class="button"><?php _e('Filter', 'ox-applicants'); ?></button>
                    </form>
                </div>
                
                <!-- Export Button -->
                <div class="ox-export-section" style="float: right;">
                    <button type="button" 
                            onclick="exportRenewalsCSV()" 
                            class="button button-secondary">
                        <?php _e('Export CSV', 'ox-applicants'); ?>
                    </button>
                </div>
            </div>

            <!-- Subscriptions Table -->
            <div class="ox-renewals-table">
                <?php if (empty($subscriptions)): ?>
                    <p><?php _e('No subscriptions found for the selected period.', 'ox-applicants'); ?></p>
                <?php else: ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th><?php _e('Subscription ID', 'ox-applicants'); ?></th>
                                <th><?php _e('Customer', 'ox-applicants'); ?></th>
                                <th><?php _e('Product', 'ox-applicants'); ?></th>
                                <th><?php _e('Next Payment Date', 'ox-applicants'); ?></th>
                                <th><?php _e('Renewal Method', 'ox-applicants'); ?></th>
                                <th><?php _e('Status', 'ox-applicants'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($subscriptions as $subscription): ?>
                                <tr>
                                    <td>
                                        <a href="<?php echo admin_url('post.php?post=' . $subscription['id'] . '&action=edit'); ?>" target="_blank">
                                            #<?php echo esc_html($subscription['id']); ?>
                                        </a>
                                    </td>
                                    <td>
                                        <?php echo esc_html($subscription['customer_name']); ?>
                                        <br><small><?php echo esc_html($subscription['customer_email']); ?></small>
                                    </td>
                                    <td><?php echo esc_html($subscription['product_name']); ?></td>
                                    <td>
                                        <?php 
                                        if ($subscription['next_payment_date']) {
                                            echo date('F j, Y', strtotime($subscription['next_payment_date']));
                                        } else {
                                            echo __('N/A', 'ox-applicants');
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <span class="ox-renewal-method <?php echo $subscription['renewal_method']; ?>">
                                            <?php echo esc_html(ucfirst($subscription['renewal_method'])); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="ox-status-badge status-<?php echo $subscription['status']; ?>">
                                            <?php 
                                            $status_display = function_exists('wcs_get_subscription_status_name') ? 
                                                wcs_get_subscription_status_name($subscription['status']) : 
                                                ucfirst($subscription['status']);
                                            echo esc_html($status_display); 
                                            ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
            
            <script>
            function exportRenewalsCSV() {
                var currentUrl = new URL(window.location.href);
                currentUrl.searchParams.set('export', 'csv');
                window.location.href = currentUrl.toString();
            }
            </script>
        </div>
        <?php
    }

    /**
     * Save settings
     */
    private function save_settings(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Verify nonce
        if (!wp_verify_nonce($_POST['ox_applicants_nonce'], 'ox_applicants_settings')) {
            add_settings_error(
                'ox_applicants',
                'nonce_failed',
                __('Security check failed.', 'ox-applicants'),
                'error'
            );
            return;
        }

        $subscription_product_id = isset($_POST['subscription_product_id']) ? sanitize_text_field($_POST['subscription_product_id']) : '';
        $email_template = isset($_POST['email_template']) ? $this->sanitize_email_template($_POST['email_template']) : '';
        
        // Convert to integer if not empty
        if (!empty($subscription_product_id)) {
            $subscription_product_id = (int) $subscription_product_id;
            
            // Validate subscription product
            if (!OX_Applicants_Core::validate_subscription_product($subscription_product_id)) {
                add_settings_error(
                    'ox_applicants',
                    'invalid_product',
                    __('The selected product is not a valid subscription product.', 'ox-applicants'),
                    'error'
                );
                return;
            }
        } else {
            $subscription_product_id = ''; // Store empty string if no ID provided
        }

        update_option('ox_applicants_subscription_product_id', $subscription_product_id);
        update_option('ox_applicants_email_template', $email_template);
        add_settings_error(
            'ox_applicants',
            'settings_updated',
            __('Settings updated successfully.', 'ox-applicants'),
            'success'
        );
    }

    /**
     * Get applications with optional status filter
     */
    private function get_applications(string $status_filter = 'waiting'): array {
        $args = [
            'post_type' => 'ox_application',
            'post_status' => 'publish',
            'numberposts' => -1,
            'orderby' => 'date',
            'order' => 'DESC',
        ];

        // Apply status filter
        if ($status_filter !== 'all') {
            if ($status_filter === 'waiting') {
                // Filter for 'new' and 'on_hold' statuses
                $args['meta_query'] = [
                    [
                        'key' => '_status',
                        'value' => ['new', 'on_hold'],
                        'compare' => 'IN'
                    ]
                ];
            } else {
                // Filter for specific status
                $args['meta_query'] = [
                    [
                        'key' => '_status',
                        'value' => $status_filter,
                        'compare' => '='
                    ]
                ];
            }
        }

        return get_posts($args);
    }

    /**
     * Get status counts for all applications
     */
    private function get_status_counts(): array {
        $all_applications = get_posts([
            'post_type' => 'ox_application',
            'post_status' => 'publish',
            'numberposts' => -1,
        ]);

        $counts = [
            'new' => 0,
            'on_hold' => 0,
            'accepted' => 0,
            'rejected' => 0,
            'duplicate' => 0,
        ];

        foreach ($all_applications as $application) {
            $status = get_post_meta($application->ID, '_status', true);
            if (empty($status)) {
                $status = 'new';
            }
            if (isset($counts[$status])) {
                $counts[$status]++;
            }
        }

        // Calculate waiting (new + on_hold)
        $counts['waiting'] = $counts['new'] + $counts['on_hold'];
        $counts['all'] = array_sum($counts);

        return $counts;
    }

    /**
     * Get status badge HTML
     */
    private function get_status_badge(string $status): string {
        $status_labels = [
            'new' => __('New', 'ox-applicants'),
            'on_hold' => __('On Hold', 'ox-applicants'),
            'accepted' => __('Accepted', 'ox-applicants'),
            'rejected' => __('Rejected', 'ox-applicants'),
            'duplicate' => __('Duplicate', 'ox-applicants'),
        ];

        $status_classes = [
            'new' => 'status-new',
            'on_hold' => 'status-on-hold',
            'accepted' => 'status-accepted',
            'rejected' => 'status-rejected',
            'duplicate' => 'status-duplicate',
        ];

        $label = $status_labels[$status] ?? $status;
        $class = $status_classes[$status] ?? '';

        return '<span class="ox-status-badge ' . esc_attr($class) . '">' . esc_html($label) . '</span>';
    }

    /**
     * Handle AJAX status update
     */
    public function handle_status_update(): void {
        check_ajax_referer('ox_applicants_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions.', 'ox-applicants'));
        }

        $application_id = isset($_POST['application_id']) ? (int) $_POST['application_id'] : 0;
        $new_status = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : '';

        if (!$application_id || !$new_status) {
            wp_send_json_error(__('Invalid parameters.', 'ox-applicants'));
        }

        $old_status = get_post_meta($application_id, '_status', true);
        
        try {
            // Handle status change actions first (this may throw exceptions)
            $this->handle_status_change($application_id, $new_status, $old_status);
            
            // Only update the status if the process completed successfully
            update_post_meta($application_id, '_status', $new_status);
            update_post_meta($application_id, '_last_action_date', current_time('mysql'));
            
            wp_send_json_success(__('Status updated successfully.', 'ox-applicants'));
            
        } catch (Exception $e) {
            error_log("OX Applicants: Status update failed: " . $e->getMessage());
            wp_send_json_error(__('Status update failed: ', 'ox-applicants') . $e->getMessage());
        }
    }

    /**
     * Handle AJAX product lookup
     */
    public function handle_product_lookup(): void {
        check_ajax_referer('ox_lookup_product', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions.', 'ox-applicants'));
        }

        $product_id = isset($_POST['product_id']) ? (int) $_POST['product_id'] : 0;

        if (!$product_id) {
            wp_send_json_error(__('Invalid product ID.', 'ox-applicants'));
        }

        // Check if WooCommerce is active
        if (!function_exists('wc_get_product')) {
            wp_send_json_error(__('WooCommerce is not active.', 'ox-applicants'));
        }

        // Get the product
        $product = wc_get_product($product_id);

        if (!$product) {
            wp_send_json_error(__('Product not found.', 'ox-applicants'));
        }

        // Check if it's a subscription product
        $is_subscription = false;
        if (class_exists('WC_Subscriptions_Product')) {
            $is_subscription = WC_Subscriptions_Product::is_subscription($product);
        }

        $response_data = [
            'name' => $product->get_name(),
            'type' => $product->get_type(),
            'is_subscription' => $is_subscription,
            'price' => $product->get_price(),
            'status' => $product->get_status(),
        ];

        // Validate that it's a subscription product
        if (!$is_subscription) {
            wp_send_json_error(__('The selected product is not a subscription product.', 'ox-applicants'));
        }

        wp_send_json_success($response_data);
    }

    /**
     * Handle status change
     */
    private function handle_status_change(int $application_id, string $new_status, string $old_status): void {
        $user_id = (int) get_post_meta($application_id, '_user_id', true);
        
        if (!$user_id) {
            error_log("OX Applicants: No user ID found for application {$application_id}");
            throw new Exception("No user ID found for application {$application_id}");
        }

        switch ($new_status) {
            case 'accepted':
                $this->handle_application_accepted($application_id, $user_id);
                break;
            case 'rejected':
                $this->handle_application_rejected($application_id, $user_id);
                break;
        }
    }

    /**
     * Handle accepted application
     */
    private function handle_application_accepted(int $application_id, int $user_id): void {
        error_log("OX Applicants: Starting acceptance process for application {$application_id}, user {$user_id}");
        
        // Store original state for potential rollback
        $user = get_user_by('id', $user_id);
        if (!$user) {
            error_log("OX Applicants: ERROR - User {$user_id} not found");
            throw new Exception("User {$user_id} not found");
        }
        
        $original_role = $user->roles[0] ?? 'no_role';
        $original_tags = OX_Applicants_Core::get_user_access_tags($user_id);
        
        try {
            // 0. Ensure member role exists
            OX_Applicants_Core::create_member_role();
            
            // 1. Change user role to member
            $user->set_role('member');
            error_log("OX Applicants: Changed user {$user_id} role from '{$original_role}' to 'member'");
            
            // 2. Add member tag to ox-content-blocker
            $tag_result = OX_Applicants_Core::add_user_access_tag($user_id, 'member');
            if (!$tag_result) {
                throw new Exception("Failed to add 'member' tag to user {$user_id}");
            }
            error_log("OX Applicants: Successfully added 'member' tag to user {$user_id}");
            
            // 3. Update application meta to track acceptance
            update_post_meta($application_id, '_accepted_date', current_time('mysql'));
            update_post_meta($application_id, '_accepted_by', get_current_user_id());
            error_log("OX Applicants: Updated application {$application_id} with acceptance metadata");
            
            // 4. WooCommerce Subscriptions Integration (REQUIRED)
            if (!OX_Applicants_Core::is_wcs_active()) {
                throw new Exception("WooCommerce Subscriptions is required but not active");
            }
            
            // Check if we're using SQLite (which has compatibility issues with WooCommerce Subscriptions)
            $is_sqlite = OX_Applicants_Core::is_sqlite_database();
            
            if ($is_sqlite) {
                throw new Exception("SQLite database detected. WooCommerce Subscriptions requires MySQL. Please test on a MySQL environment.");
            }
            
            $subscription_product_id = OX_Applicants_Core::get_subscription_product_id();
            
            if (!$subscription_product_id || !OX_Applicants_Core::validate_subscription_product($subscription_product_id)) {
                throw new Exception("Invalid or missing subscription product ID: {$subscription_product_id}");
            }
            
            error_log("OX Applicants: Creating WooCommerce subscription for user {$user_id}");
            
            // Create the subscription
            $subscription_result = $this->create_woocommerce_subscription($user_id, $subscription_product_id, $application_id);
            
            if (!$subscription_result['success']) {
                throw new Exception("Failed to create subscription: " . $subscription_result['error']);
            }
            
            error_log("OX Applicants: Successfully created subscription {$subscription_result['subscription_id']} for user {$user_id}");
            
            // Store subscription reference in application
            update_post_meta($application_id, '_subscription_id', $subscription_result['subscription_id']);
            update_post_meta($application_id, '_order_id', $subscription_result['order_id']);
            
            // 5. Send manual renewal notification email instead of standard order email
            $this->send_manual_renewal_notification($subscription_result['subscription_id']);
            
            error_log("OX Applicants: Application {$application_id} acceptance process completed successfully");
            
        } catch (Exception $e) {
            error_log("OX Applicants: ERROR - Exception during acceptance process: " . $e->getMessage());
            
            // ROLLBACK: Restore original state
            error_log("OX Applicants: Starting rollback process...");
            
            // Rollback user role
            $user->set_role($original_role);
            error_log("OX Applicants: Rolled back user {$user_id} role to '{$original_role}'");
            
            // Rollback member tag
            OX_Applicants_Core::remove_user_access_tag($user_id, 'member');
            error_log("OX Applicants: Removed 'member' tag from user {$user_id}");
            
            // Remove acceptance metadata
            delete_post_meta($application_id, '_accepted_date');
            delete_post_meta($application_id, '_accepted_by');
            error_log("OX Applicants: Removed acceptance metadata from application {$application_id}");
            
            // Re-throw the exception to prevent status change
            throw $e;
        }
    }

    /**
     * Handle rejected application
     */
    private function handle_application_rejected(int $application_id, int $user_id): void {
        // No action needed for rejection - member tag is only set on acceptance
        error_log("OX Applicants: Application {$application_id} rejected");
    }

    /**
     * Create WooCommerce subscription
     * 
     * Improved version based on WooCommerce Subscriptions best practices:
     * - Uses wcs_ functions where available
     * - Better error handling and validation
     * - Proper subscription metadata and dates
     * - Enhanced logging and cleanup
     */
    private function create_woocommerce_subscription(int $user_id, int $product_id, int $application_id): array {
        error_log("OX Applicants: Starting subscription creation for user {$user_id}, product {$product_id}, application {$application_id}");
        
        try {
            // Validate input parameters
            if (!is_numeric($user_id) || $user_id <= 0) {
                error_log("OX Applicants: ERROR - Invalid user ID: {$user_id}");
                return ['success' => false, 'error' => 'Invalid user ID', 'subscription_id' => 0, 'order_id' => 0];
            }
            if (!is_numeric($product_id) || $product_id <= 0) {
                error_log("OX Applicants: ERROR - Invalid product ID: {$product_id}");
                return ['success' => false, 'error' => 'Invalid product ID', 'subscription_id' => 0, 'order_id' => 0];
            }
            if (!class_exists('WC_Subscriptions')) {
                error_log("OX Applicants: ERROR - WooCommerce Subscriptions class not found");
                return ['success' => false, 'error' => 'WooCommerce Subscriptions plugin is not active', 'subscription_id' => 0, 'order_id' => 0];
            }
            
            // Check for other WooCommerce Subscriptions classes
            error_log("OX Applicants: Checking WooCommerce Subscriptions classes...");
            error_log("OX Applicants: WC_Subscriptions exists: " . (class_exists('WC_Subscriptions') ? 'yes' : 'no'));
            error_log("OX Applicants: WC_Subscriptions_Manager exists: " . (class_exists('WC_Subscriptions_Manager') ? 'yes' : 'no'));
            error_log("OX Applicants: WC_Subscriptions_Product exists: " . (class_exists('WC_Subscriptions_Product') ? 'yes' : 'no'));

            // Get user data
            $user = get_user_by('id', $user_id);
            if (!$user) {
                error_log("OX Applicants: ERROR - User {$user_id} not found");
                return ['success' => false, 'error' => 'User not found', 'subscription_id' => 0, 'order_id' => 0];
            }
            error_log("OX Applicants: Found user {$user_id}: {$user->user_email}");

            // Get product
            $product = wc_get_product($product_id);
            if (!$product) {
                error_log("OX Applicants: ERROR - Product {$product_id} not found");
                return ['success' => false, 'error' => 'Product not found', 'subscription_id' => 0, 'order_id' => 0];
            }
            if (!WC_Subscriptions_Product::is_subscription($product)) {
                error_log("OX Applicants: ERROR - Product {$product_id} is not a subscription product");
                return ['success' => false, 'error' => 'Product is not a subscription product', 'subscription_id' => 0, 'order_id' => 0];
            }
            error_log("OX Applicants: Found subscription product {$product_id}: {$product->get_name()}");

            // Create order using standard WooCommerce method
            error_log("OX Applicants: Creating order using wc_create_order()...");
            
            if (!function_exists('wc_create_order')) {
                error_log("OX Applicants: ERROR - wc_create_order function not available");
                return ['success' => false, 'error' => 'WooCommerce order creation function not available', 'subscription_id' => 0, 'order_id' => 0];
            }
            
            $order = wc_create_order();
            if (!$order) {
                error_log("OX Applicants: ERROR - Failed to create order");
                return ['success' => false, 'error' => 'Failed to create order', 'subscription_id' => 0, 'order_id' => 0];
            }
            
            if (!$order) {
                error_log("OX Applicants: ERROR - Failed to create order");
                return ['success' => false, 'error' => 'Failed to create order', 'subscription_id' => 0, 'order_id' => 0];
            }
            
            error_log("OX Applicants: Order created with ID: {$order->get_id()}");
            $order->set_customer_id($user_id);

            // Set billing and shipping address using WooCommerce user meta fields
            $billing_data = [
                'first_name' => get_user_meta($user_id, 'billing_first_name', true) ?: $user->user_firstname ?: '',
                'last_name' => get_user_meta($user_id, 'billing_last_name', true) ?: $user->user_lastname ?: '',
                'email' => get_user_meta($user_id, 'billing_email', true) ?: $user->user_email,
                'phone' => get_user_meta($user_id, 'billing_phone', true) ?: '',
                'address_1' => get_user_meta($user_id, 'billing_address_1', true) ?: '',
                'address_2' => get_user_meta($user_id, 'billing_address_2', true) ?: '',
                'city' => get_user_meta($user_id, 'billing_city', true) ?: '',
                'state' => get_user_meta($user_id, 'billing_state', true) ?: '',
                'postcode' => get_user_meta($user_id, 'billing_postcode', true) ?: '',
                'country' => get_user_meta($user_id, 'billing_country', true) ?: '',
            ];
            
            $shipping_data = [
                'first_name' => get_user_meta($user_id, 'shipping_first_name', true) ?: $user->user_firstname ?: '',
                'last_name' => get_user_meta($user_id, 'shipping_last_name', true) ?: $user->user_lastname ?: '',
                'address_1' => get_user_meta($user_id, 'shipping_address_1', true) ?: '',
                'address_2' => get_user_meta($user_id, 'shipping_address_2', true) ?: '',
                'city' => get_user_meta($user_id, 'shipping_city', true) ?: '',
                'state' => get_user_meta($user_id, 'shipping_state', true) ?: '',
                'postcode' => get_user_meta($user_id, 'shipping_postcode', true) ?: '',
                'country' => get_user_meta($user_id, 'shipping_country', true) ?: '',
            ];
            
            error_log("OX Applicants: Setting order addresses...");
            $order->set_address($billing_data, 'billing');
            $order->set_address($shipping_data, 'shipping');

            // Add subscription product to order with metadata
            error_log("OX Applicants: Adding product to order...");
            $item_id = $order->add_product($product, 1);
            if (!$item_id) {
                error_log("OX Applicants: ERROR - Failed to add product to order");
                $order->delete(true);
                return ['success' => false, 'error' => 'Failed to add product to order', 'subscription_id' => 0, 'order_id' => 0];
            }
            error_log("OX Applicants: Product added to order with item ID: {$item_id}");
            
            // Get subscription product details
            $period = WC_Subscriptions_Product::get_period($product);
            $interval = WC_Subscriptions_Product::get_interval($product);
            $length = WC_Subscriptions_Product::get_length($product);
            
            error_log("OX Applicants: Subscription period: {$period}, interval: {$interval}, length: {$length}");
            
            // Add subscription-specific metadata to order item
            error_log("OX Applicants: Adding subscription metadata to order item...");
            if (function_exists('wc_add_order_item_meta')) {
                wc_add_order_item_meta($item_id, '_subscription_period', $period);
                wc_add_order_item_meta($item_id, '_subscription_interval', $interval);
                wc_add_order_item_meta($item_id, '_subscription_length', $length);
                wc_add_order_item_meta($item_id, '_subscription_trial_length', WC_Subscriptions_Product::get_trial_length($product));
                wc_add_order_item_meta($item_id, '_subscription_trial_period', WC_Subscriptions_Product::get_trial_period($product));
                wc_add_order_item_meta($item_id, '_subscription_sign_up_fee', WC_Subscriptions_Product::get_sign_up_fee($product));
            } else {
                error_log("OX Applicants: ERROR - wc_add_order_item_meta function not available");
                $order->delete(true);
                return ['success' => false, 'error' => 'WooCommerce order item meta function not available', 'subscription_id' => 0, 'order_id' => 0];
            }

            // Set payment method and calculate totals
            error_log("OX Applicants: Setting payment method and calculating totals...");
            // For manual renewals, leave payment method empty - this triggers manual renewal logic
            $order->set_payment_method('');
            $order->set_payment_method_title('Manual Renewal');
            $order->calculate_totals();

            // Add subscription metadata to order
            error_log("OX Applicants: Adding subscription metadata to order...");
            $order->add_meta_data('_subscription_period', $period);
            $order->add_meta_data('_subscription_interval', $interval);
            $order->add_meta_data('_subscription_length', $length);
            $order->add_meta_data('_subscription_trial_length', WC_Subscriptions_Product::get_trial_length($product));
            $order->add_meta_data('_subscription_trial_period', WC_Subscriptions_Product::get_trial_period($product));
            $order->add_meta_data('_subscription_sign_up_fee', WC_Subscriptions_Product::get_sign_up_fee($product));
            $order->add_meta_data('_application_id', $application_id);
            
            // Set order status to "pending" for payment - on-hold orders don't show payment options
            error_log("OX Applicants: Setting order status to pending...");
            $order->update_status('pending', 'Order created programmatically for manual renewal subscription.');
            $order->save();
            error_log("OX Applicants: Order saved with status: {$order->get_status()}");

            // Use the documented wcs_create_subscription() function
            error_log("OX Applicants: Using documented wcs_create_subscription() function...");
            
            if (!function_exists('wcs_create_subscription')) {
                error_log("OX Applicants: ERROR - wcs_create_subscription function not available");
                $order->delete(true);
                return ['success' => false, 'error' => 'WooCommerce Subscriptions wcs_create_subscription function not available', 'subscription_id' => 0, 'order_id' => 0];
            }
            
            // Create subscription using the documented function
            $subscription = wcs_create_subscription(array(
                'order_id' => $order->get_id(),
                'customer_id' => $user_id,
                'start_date' => current_time('mysql'),
                'status' => 'pending',
                'billing_period' => $period,
                'billing_interval' => $interval,
                'customer_note' => 'Subscription created programmatically for manual renewal.'
            ));
            
            if (is_wp_error($subscription)) {
                error_log("OX Applicants: ERROR - Failed to create subscription: " . $subscription->get_error_message());
                $order->delete(true);
                return ['success' => false, 'error' => 'Failed to create subscription: ' . $subscription->get_error_message(), 'subscription_id' => 0, 'order_id' => 0];
            }
            
            error_log("OX Applicants: Successfully created subscription: {$subscription->get_id()}");
            
            // Add the product to the subscription
            $item_id = $subscription->add_product($product, 1);
            if (!$item_id) {
                error_log("OX Applicants: ERROR - Failed to add product to subscription");
                $subscription->delete(true);
                $order->delete(true);
                return ['success' => false, 'error' => 'Failed to add product to subscription', 'subscription_id' => 0, 'order_id' => 0];
            }
            
            // Copy order address to subscription
            if (function_exists('wcs_copy_order_address')) {
                wcs_copy_order_address($order, $subscription);
            }
            
            // Set subscription dates
            $subscription->update_dates(array(
                'trial_end' => WC_Subscriptions_Product::get_trial_expiration_date($product, current_time('mysql')),
                'next_payment' => WC_Subscriptions_Product::get_first_renewal_payment_date($product, current_time('mysql')),
                'end' => WC_Subscriptions_Product::get_expiration_date($product, current_time('mysql'))
            ));
            
            // Set payment method and manual renewal flag
            // For manual renewals, leave payment method empty - this triggers manual renewal logic
            $subscription->set_payment_method('');
            $subscription->set_requires_manual_renewal(true);
            
            // Add application metadata
            $subscription->add_meta_data('_application_id', $application_id);
            
            // Save the subscription
            $subscription->save();
            
            error_log("OX Applicants: Subscription configured and saved with status: {$subscription->get_status()}");

            // Subscription is already configured above, just log the final status
            error_log("OX Applicants: Final subscription status: {$subscription->get_status()}");

            error_log("OX Applicants: Created subscription {$subscription->get_id()} and order {$order->get_id()} for user {$user_id}, application {$application_id}");
            
            // Debug order payment method
            error_log("OX Applicants: Order payment method: " . $order->get_payment_method());
            error_log("OX Applicants: Order payment method title: " . $order->get_payment_method_title());
            
            // Debug subscription payment method
            error_log("OX Applicants: Subscription payment method: " . $subscription->get_payment_method());
            error_log("OX Applicants: Subscription requires manual renewal: " . ($subscription->get_requires_manual_renewal() ? 'yes' : 'no'));
            error_log("OX Applicants: Subscription is manual: " . ($subscription->is_manual() ? 'yes' : 'no'));

            return [
                'success' => true,
                'subscription_id' => $subscription->get_id(),
                'order_id' => $order->get_id(),
                'error' => ''
            ];

        } catch (WC_Data_Exception $e) {
            error_log("OX Applicants: WooCommerce data exception creating subscription: " . $e->getMessage());
            return ['success' => false, 'error' => 'WooCommerce data error: ' . $e->getMessage(), 'subscription_id' => 0, 'order_id' => 0];
        } catch (Exception $e) {
            error_log("OX Applicants: General exception creating subscription: " . $e->getMessage());
            return ['success' => false, 'error' => 'Unexpected error: ' . $e->getMessage(), 'subscription_id' => 0, 'order_id' => 0];
        }
    }

    /**
     * Send custom acceptance notification email with subscription details
     */
    private function send_manual_renewal_notification(int $subscription_id): void {
        error_log("OX Applicants: Sending custom acceptance notification for subscription {$subscription_id}");
        
        // Get the subscription
        $subscription = wcs_get_subscription($subscription_id);
        if (!$subscription) {
            error_log("OX Applicants: Cannot send notification - subscription {$subscription_id} not found");
            return;
        }
        
        // Get the parent order
        $order = $subscription->get_parent();
        if (!$order) {
            error_log("OX Applicants: Cannot send notification - no parent order found for subscription {$subscription_id}");
            return;
        }
        
        // Get customer details
        $customer_id = $subscription->get_customer_id();
        $customer = get_user_by('id', $customer_id);
        if (!$customer) {
            error_log("OX Applicants: Cannot send notification - no customer found for subscription {$subscription_id}");
            return;
        }
        
        // Get subscription product details
        $items = $subscription->get_items();
        $product = null;
        $product_name = '';
        $product_price = '';
        
        if (!empty($items)) {
            $item = reset($items); // Get first item
            $product = $item->get_product();
            $product_name = $item->get_name();
            $product_price = $subscription->get_formatted_line_subtotal($item);
        }
        
        // Get subscription details
        $billing_period = $subscription->get_billing_period();
        $billing_interval = $subscription->get_billing_interval();
        $next_payment_date = $subscription->get_date('next_payment');
        $total = $subscription->get_total();
        
        // Format billing period
        $billing_text = '';
        if ($billing_interval > 1) {
            $billing_text = sprintf('%d %ss', $billing_interval, $billing_period);
        } else {
            $billing_text = $billing_period;
        }
        
        // Get payment link - try different methods for manual renewal orders
        $payment_url = $order->get_checkout_payment_url();
        
        // Debug the payment URL and order details
        error_log("OX Applicants: Generated payment URL: " . $payment_url);
        error_log("OX Applicants: Order status: " . $order->get_status());
        error_log("OX Applicants: Order needs payment: " . ($order->needs_payment() ? 'yes' : 'no'));
        error_log("OX Applicants: Order contains subscription: " . (wcs_order_contains_subscription($order) ? 'yes' : 'no'));
        error_log("OX Applicants: Order contains renewal: " . (wcs_order_contains_renewal($order) ? 'yes' : 'no'));
        
        // Debug payment gateways for this order
        $available_gateways = WC()->payment_gateways->get_available_payment_gateways();
        error_log("OX Applicants: Available payment gateways for order {$order->get_id()}: " . print_r(array_keys($available_gateways), true));
        
        // Check if manual renewals are enabled
        $manual_renewals_enabled = wcs_is_manual_renewal_enabled();
        error_log("OX Applicants: Manual renewals enabled: " . ($manual_renewals_enabled ? 'yes' : 'no'));
        
        // Check subscription payment method
        error_log("OX Applicants: Subscription payment method: " . $subscription->get_payment_method());
        error_log("OX Applicants: Subscription is manual: " . ($subscription->is_manual() ? 'yes' : 'no'));
        
        // Generate password setup link for new user
        $password_setup_url = $this->generate_password_setup_url($customer);
        
        // Get billing address
        $billing_address = $subscription->get_formatted_billing_address();
        $billing_email = $subscription->get_billing_email();
        $billing_phone = $subscription->get_billing_phone();
        
        // Get custom email template
        $email_template = get_option('ox_applicants_email_template', $this->get_default_email_template());
        
        // Build email content
        $to = $billing_email;
        $subject = sprintf('Your Application Has Been Accepted - Subscription Invoice #%s', $order->get_order_number());
        
        // Process template with variables
        $message = $this->process_email_template($email_template, $customer, $subscription, $order, $product_name, $product_price, $billing_text, $total, $next_payment_date, $payment_url, $billing_address, $password_setup_url);
        
        // Email headers
        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>'
        ];
        
        // Send email
        $sent = wp_mail($to, $subject, $message, $headers);
        
        if ($sent) {
            error_log("OX Applicants: Custom acceptance notification sent to {$billing_email}");
        } else {
            error_log("OX Applicants: Failed to send custom acceptance notification to {$billing_email}");
        }
    }
    
    /**
     * Generate HTML email content
     */
    private function get_custom_email_html($customer, $subscription, $order, $product_name, $product_price, $billing_text, $total, $next_payment_date, $payment_url, $billing_address): string {
        $site_name = get_bloginfo('name');
        $site_url = get_bloginfo('url');
        
        $html = '
        <!DOCTYPE html>
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
            </style>
        </head>
        <body>
            <div class="header">
                <h1>🎉 Your Application Has Been Accepted!</h1>
                <p>Welcome to ' . esc_html($site_name) . '</p>
            </div>
            
            <div class="content">
                <p>Dear ' . esc_html($customer->get_first_name()) . ',</p>
                
                <p>Congratulations! Your application has been accepted and your membership is now active. You now have access to all member-only content and benefits.</p>
                
                <div class="highlight">
                    <strong>Important:</strong> To complete your membership setup, please complete the payment for your subscription below.
                </div>
                
                <h2>Subscription Details</h2>
                <div class="subscription-details">
                    <p><strong>Product:</strong> ' . esc_html($product_name) . '</p>
                    <p><strong>Billing Cycle:</strong> ' . esc_html(ucfirst($billing_text)) . '</p>
                    <p><strong>Amount:</strong> ' . esc_html($product_price) . '</p>
                    <p><strong>Next Payment:</strong> ' . esc_html($next_payment_date ? date('F j, Y', strtotime($next_payment_date)) : 'N/A') . '</p>
                    <p><strong>Order Number:</strong> #' . esc_html($order->get_order_number()) . '</p>
                </div>
                
                <h2>Billing Information</h2>
                <div class="billing-info">
                    ' . nl2br(esc_html($billing_address)) . '
                    <br><strong>Email:</strong> ' . esc_html($subscription->get_billing_email()) . '
                    ' . ($subscription->get_billing_phone() ? '<br><strong>Phone:</strong> ' . esc_html($subscription->get_billing_phone()) : '') . '
                </div>
                
                <div style="text-align: center; margin: 30px 0;">
                    <a href="' . esc_url($payment_url) . '" class="payment-button">Complete Payment Now</a>
                </div>
                
                <p><strong>Payment Link:</strong> <a href="' . esc_url($payment_url) . '">' . esc_url($payment_url) . '</a></p>
                
                <p>If you have any questions about your subscription or need assistance, please don\'t hesitate to contact us.</p>
                
                <p>Thank you for choosing ' . esc_html($site_name) . '!</p>
                
                <p>Best regards,<br>The ' . esc_html($site_name) . ' Team</p>
            </div>
            
            <div class="footer">
                <p>This email was sent from ' . esc_html($site_name) . '<br>
                <a href="' . esc_url($site_url) . '">' . esc_url($site_url) . '</a></p>
            </div>
        </body>
        </html>';
        
        return $html;
    }
    
    /**
     * Generate plain text email content
     */
    private function get_custom_email_plain($customer, $subscription, $order, $product_name, $product_price, $billing_text, $total, $next_payment_date, $payment_url, $billing_address): string {
        $site_name = get_bloginfo('name');
        
        $message = "Your Application Has Been Accepted!\n\n";
        $message .= "Dear " . $customer->get_first_name() . ",\n\n";
        $message .= "Congratulations! Your application has been accepted and your membership is now active. You now have access to all member-only content and benefits.\n\n";
        $message .= "IMPORTANT: To complete your membership setup, please complete the payment for your subscription.\n\n";
        $message .= "SUBSCRIPTION DETAILS:\n";
        $message .= "Product: " . $product_name . "\n";
        $message .= "Billing Cycle: " . ucfirst($billing_text) . "\n";
        $message .= "Amount: " . $product_price . "\n";
        $message .= "Next Payment: " . ($next_payment_date ? date('F j, Y', strtotime($next_payment_date)) : 'N/A') . "\n";
        $message .= "Order Number: #" . $order->get_order_number() . "\n\n";
        $message .= "BILLING INFORMATION:\n";
        $message .= $billing_address . "\n";
        $message .= "Email: " . $subscription->get_billing_email() . "\n";
        if ($subscription->get_billing_phone()) {
            $message .= "Phone: " . $subscription->get_billing_phone() . "\n";
        }
        $message .= "\n";
        $message .= "PAYMENT LINK:\n";
        $message .= $payment_url . "\n\n";
        $message .= "If you have any questions about your subscription or need assistance, please don't hesitate to contact us.\n\n";
        $message .= "Thank you for choosing " . $site_name . "!\n\n";
        $message .= "Best regards,\nThe " . $site_name . " Team";
        
        return $message;
    }
    
    /**
     * Maybe disable the standard order email for subscription orders created by our plugin
     */
    public function maybe_disable_order_email($enabled, $order) {
        // Check if this order has an application_id meta (created by our plugin)
        if ($order && $order->get_meta('_application_id')) {
            error_log("OX Applicants: Disabling standard order email for order {$order->get_id()} (created by our plugin)");
            return false;
        }
        
        return $enabled;
    }

    /**
     * Get renewals data for the specified filter
     */
    private function get_renewals_data(string $filter, string $custom_start = '', string $custom_end = ''): array {
        if (!function_exists('wcs_get_subscriptions')) {
            return [];
        }

        // Get all active subscriptions
        $subscriptions = wcs_get_subscriptions([
            'subscription_status' => ['active', 'on-hold', 'pending-cancel'],
            'subscriptions_per_page' => -1
        ]);

        if (empty($subscriptions)) {
            return [];
        }

        $filtered_subscriptions = [];
        $current_time = current_time('timestamp');
        
        foreach ($subscriptions as $subscription) {
            $next_payment_date = $subscription->get_date('next_payment');
            
            if (!$next_payment_date) {
                continue; // Skip subscriptions without next payment date
            }

            $next_payment_timestamp = strtotime($next_payment_date);
            
            // Check if subscription falls within the selected filter period
            $include_subscription = false;
            
            switch ($filter) {
                case 'next_30_days':
                    $thirty_days_from_now = $current_time + (30 * 24 * 60 * 60);
                    $include_subscription = ($next_payment_timestamp >= $current_time && $next_payment_timestamp <= $thirty_days_from_now);
                    break;
                    
                case 'current_month':
                    $current_month_start = strtotime('first day of this month', $current_time);
                    $current_month_end = strtotime('last day of this month', $current_time);
                    $include_subscription = ($next_payment_timestamp >= $current_month_start && $next_payment_timestamp <= $current_month_end);
                    break;
                    
                case 'next_month':
                    $next_month_start = strtotime('first day of next month', $current_time);
                    $next_month_end = strtotime('last day of next month', $current_time);
                    $include_subscription = ($next_payment_timestamp >= $next_month_start && $next_payment_timestamp <= $next_month_end);
                    break;
                    
                case 'last_month':
                    $last_month_start = strtotime('first day of last month', $current_time);
                    $last_month_end = strtotime('last day of last month', $current_time);
                    $include_subscription = ($next_payment_timestamp >= $last_month_start && $next_payment_timestamp <= $last_month_end);
                    break;
                    
                case 'custom':
                    if (!empty($custom_start) && !empty($custom_end)) {
                        $custom_start_timestamp = strtotime($custom_start);
                        $custom_end_timestamp = strtotime($custom_end . ' 23:59:59');
                        $include_subscription = ($next_payment_timestamp >= $custom_start_timestamp && $next_payment_timestamp <= $custom_end_timestamp);
                    }
                    break;
            }
            
            if ($include_subscription) {
                $customer_id = $subscription->get_customer_id();
                $customer = $customer_id ? get_user_by('id', $customer_id) : null;
                
                // Get the first line item (assuming single product subscriptions)
                $items = $subscription->get_items();
                $first_item = reset($items);
                $product_name = $first_item ? $first_item->get_name() : __('Unknown Product', 'ox-applicants');
                
                // Determine renewal method - use the correct method to check if subscription is manual
                $renewal_method = $subscription->is_manual() ? 'manual' : 'automatic';
                

                
                $filtered_subscriptions[] = [
                    'id' => $subscription->get_id(),
                    'customer_id' => $customer_id,
                    'customer_name' => $customer ? $customer->display_name : __('Unknown Customer', 'ox-applicants'),
                    'customer_email' => $customer ? $customer->user_email : '',
                    'product_name' => $product_name,
                    'next_payment_date' => $next_payment_date,
                    'renewal_method' => $renewal_method,
                    'status' => $subscription->get_status()
                ];
            }
        }
        
        // Sort by next payment date
        usort($filtered_subscriptions, function($a, $b) {
            return strtotime($a['next_payment_date']) - strtotime($b['next_payment_date']);
        });
        
        return $filtered_subscriptions;
    }

    /**
     * Export renewals data as CSV
     */
    private function export_renewals_csv(): void {
        // Check if WooCommerce Subscriptions is active
        if (!OX_Applicants_Core::is_woocommerce_subscriptions_active()) {
            wp_die(__('WooCommerce Subscriptions is required to export renewal information.', 'ox-applicants'));
        }

        // Get filter parameters
        $filter = $_GET['filter'] ?? 'next_30_days';
        $custom_start = $_GET['custom_start'] ?? '';
        $custom_end = $_GET['custom_end'] ?? '';
        
        // Get subscriptions data
        $subscriptions = $this->get_renewals_data($filter, $custom_start, $custom_end);
        
        // Set headers for CSV download
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="renewals-' . date('Y-m-d') . '.csv"');
        header('Cache-Control: no-cache, must-revalidate');
        header('Expires: Sat, 26 Jul 1997 05:00:00 GMT');
        
        // Create output stream
        $output = fopen('php://output', 'w');
        
        // Add BOM for UTF-8
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        // CSV headers
        $headers = [
            __('Subscription ID', 'ox-applicants'),
            __('Customer Name', 'ox-applicants'),
            __('Customer Email', 'ox-applicants'),
            __('Product Name', 'ox-applicants'),
            __('Next Payment Date', 'ox-applicants'),
            __('Renewal Method', 'ox-applicants'),
            __('Status', 'ox-applicants')
        ];
        
        fputcsv($output, $headers);
        
        // Add data rows
        foreach ($subscriptions as $subscription) {
            $row = [
                $subscription['id'],
                $subscription['customer_name'],
                $subscription['customer_email'],
                $subscription['product_name'],
                $subscription['next_payment_date'] ? date('Y-m-d', strtotime($subscription['next_payment_date'])) : '',
                ucfirst($subscription['renewal_method']),
                ucfirst($subscription['status'])
            ];
            
            fputcsv($output, $row);
        }
        
        fclose($output);
        exit;
    }

    /**
     * Get counts for each filter option
     */
    private function get_renewal_filter_counts(): array {
        $counts = [
            'next_30_days' => 0,
            'current_month' => 0,
            'next_month' => 0,
            'last_month' => 0
        ];
        
        foreach (array_keys($counts) as $filter) {
            $subscriptions = $this->get_renewals_data($filter);
            $counts[$filter] = count($subscriptions);
        }
        
        return $counts;
    }
    
    /**
     * Sanitize email template
     */
    public function sanitize_email_template($template): string {
        // Allow HTML but strip potentially dangerous tags
        $allowed_html = [
            'html' => ['lang' => [], 'xmlns' => []],
            'head' => [],
            'body' => ['class' => [], 'style' => []],
            'meta' => ['charset' => [], 'name' => [], 'content' => [], 'http-equiv' => []],
            'title' => [],
            'style' => ['type' => [], 'media' => []],
            'div' => ['class' => [], 'style' => [], 'id' => []],
            'p' => ['class' => [], 'style' => []],
            'h1' => ['class' => [], 'style' => []],
            'h2' => ['class' => [], 'style' => []],
            'h3' => ['class' => [], 'style' => []],
            'h4' => ['class' => [], 'style' => []],
            'h5' => ['class' => [], 'style' => []],
            'h6' => ['class' => [], 'style' => []],
            'span' => ['class' => [], 'style' => []],
            'strong' => ['class' => [], 'style' => []],
            'em' => ['class' => [], 'style' => []],
            'br' => [],
            'hr' => ['class' => [], 'style' => []],
            'table' => ['class' => [], 'style' => [], 'border' => [], 'cellpadding' => [], 'cellspacing' => [], 'width' => []],
            'thead' => ['class' => [], 'style' => []],
            'tbody' => ['class' => [], 'style' => []],
            'tr' => ['class' => [], 'style' => []],
            'th' => ['class' => [], 'style' => [], 'colspan' => [], 'rowspan' => []],
            'td' => ['class' => [], 'style' => [], 'colspan' => [], 'rowspan' => []],
            'a' => ['href' => [], 'class' => [], 'style' => [], 'target' => [], 'rel' => []],
            'img' => ['src' => [], 'alt' => [], 'class' => [], 'style' => [], 'width' => [], 'height' => []],
            'ul' => ['class' => [], 'style' => []],
            'ol' => ['class' => [], 'style' => []],
            'li' => ['class' => [], 'style' => []],
            'blockquote' => ['class' => [], 'style' => []],
            'code' => ['class' => [], 'style' => []],
            'small' => ['class' => [], 'style' => []],
            'b' => ['class' => [], 'style' => []],
            'i' => ['class' => [], 'style' => []],
        ];
        
        // For debugging - log what's being stripped
        $original_length = strlen($template);
        $sanitized = wp_kses($template, $allowed_html);
        $sanitized_length = strlen($sanitized);
        
        if ($original_length !== $sanitized_length) {
            error_log("OX Applicants: Email template sanitization removed " . ($original_length - $sanitized_length) . " characters");
        }
        
        return $sanitized;
    }
    
    /**
     * Get default email template
     */
    public function get_default_email_template(): string {
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
            <ol>
                <li><strong>Set up your account password</strong> (required first step)</li>
                <li><strong>Log in to your account</strong> using your new password</li>
                <li><strong>Complete the payment</strong> for your subscription</li>
            </ol>
        </div>
        
        <h2>Step 1: Account Setup</h2>
        <p>Since this is your first time accessing your account, you need to set up your password first:</p>
        <div style="text-align: center; margin: 20px 0;">
            <a href="{password_setup_url}" class="payment-button" style="background: #28a745;">Set Up Your Password</a>
        </div>
        <p><strong>Password Setup Link:</strong> <a href="{password_setup_url}">{password_setup_url}</a></p>
        
        <h2>Step 2: Complete Payment</h2>
        <p>After setting up your password and logging in, you can complete your subscription payment:</p>
        <div class="highlight" style="background: #e7f3ff; border-left-color: #0073aa;">
            <strong>Note:</strong> You must be logged in to complete the payment. If you are not logged in when you click the payment link, you will be prompted to log in first.
        </div>
        <div class="highlight" style="background: #fff3cd; border-left-color: #ffc107;">
            <strong>Troubleshooting:</strong> If you do not see any payment options on the payment page, please contact us. This may be due to payment gateway configuration.
        </div>
        
        <h2>Subscription Details</h2>
        <div class="subscription-details">
            <p><strong>Product:</strong> {product_name}</p>
            <p><strong>Billing Cycle:</strong> {billing_cycle}</p>
            <p><strong>Amount:</strong> {product_price}</p>
            <p><strong>Next Payment:</strong> {next_payment_date}</p>
            <p><strong>Order Number:</strong> {order_number}</p>
        </div>
        
        <h2>Billing Information</h2>
        <div class="billing-info">
            {billing_address}
            <br><strong>Email:</strong> {customer_email}
            {billing_phone}
        </div>
        
        <div style="text-align: center; margin: 30px 0;">
            <a href="{payment_url}" class="payment-button">Complete Payment Now</a>
        </div>
        
        <p><strong>Payment Link:</strong> <a href="{payment_url}">{payment_url}</a></p>
        
        <p>If you have any questions about your subscription or need assistance, please do not hesitate to contact us.</p>
        
        <p>Thank you for choosing {site_name}!</p>
        
        <p>Best regards,<br>The {site_name} Team</p>
    </div>
    
    <div class="footer">
        <p>This email was sent from {site_name}<br>
        <a href="{site_url}">{site_url}</a></p>
    </div>
</body>
</html>';
    }
    
    /**
     * Generate password setup URL for new user
     */
    private function generate_password_setup_url($customer): string {
        $key = get_password_reset_key($customer);
        if (is_wp_error($key)) {
            error_log("OX Applicants: Failed to generate password setup key for user {$customer->ID}");
            return '';
        }
        
        return network_site_url('wp-login.php?login=' . rawurlencode($customer->user_login) . "&key=$key&action=rp", 'login');
    }
    
    /**
     * Process email template variables
     */
    private function process_email_template($template, $customer, $subscription, $order, $product_name, $product_price, $billing_text, $total, $next_payment_date, $payment_url, $billing_address, $password_setup_url = ''): string {
        $variables = [
            '{customer_first_name}' => $customer->get_first_name(),
            '{customer_last_name}' => $customer->get_last_name(),
            '{customer_email}' => $subscription->get_billing_email(),
            '{product_name}' => $product_name,
            '{product_price}' => $product_price,
            '{billing_cycle}' => ucfirst($billing_text),
            '{next_payment_date}' => $next_payment_date ? date('F j, Y', strtotime($next_payment_date)) : 'N/A',
            '{order_number}' => '#' . $order->get_order_number(),
            '{payment_url}' => $payment_url,
            '{billing_address}' => nl2br($billing_address),
            '{billing_phone}' => $subscription->get_billing_phone() ? '<br><strong>Phone:</strong> ' . $subscription->get_billing_phone() : '',
            '{site_name}' => get_bloginfo('name'),
            '{site_url}' => get_bloginfo('url'),
            '{password_setup_url}' => $password_setup_url,
        ];
        
        return str_replace(array_keys($variables), array_values($variables), $template);
    }

    /**
     * Handle CSV export at admin_init stage to prevent HTML output interference
     */
    public function handle_csv_export(): void {
        // Only handle CSV export for our renewals page
        if (!isset($_GET['page']) || $_GET['page'] !== 'ox-renewals') {
            return;
        }
        
        // Check for CSV export request
        if (isset($_GET['export']) && $_GET['export'] === 'csv') {
            $this->export_renewals_csv();
        }
    }
} 