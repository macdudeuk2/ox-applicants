<?php
/**
 * OX Applicants Post Types Class
 *
 * @package OXApplicants
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Custom post types for applications
 */
class OX_Applicants_Post_Types {

    /**
     * Constructor
     */
    public function __construct() {
        add_action('init', [$this, 'register_post_types']);
        add_action('add_meta_boxes', [$this, 'add_meta_boxes']);
        add_action('save_post', [$this, 'save_meta_boxes']);
        add_filter('manage_ox_application_posts_columns', [$this, 'set_custom_columns']);
        add_action('manage_ox_application_posts_custom_column', [$this, 'custom_column_content'], 10, 2);
        add_filter('manage_edit-ox_application_sortable_columns', [$this, 'set_sortable_columns']);
        add_action('pre_get_posts', [$this, 'custom_orderby']);
        add_filter('post_row_actions', [$this, 'modify_row_actions'], 10, 2);
    }

    /**
     * Register custom post types
     */
    public function register_post_types(): void {
        register_post_type('ox_application', [
            'labels' => [
                'name' => __('Applications', 'ox-applicants'),
                'singular_name' => __('Application', 'ox-applicants'),
                'menu_name' => __('Applications', 'ox-applicants'),
                'add_new' => __('Add New', 'ox-applicants'),
                'add_new_item' => __('Add New Application', 'ox-applicants'),
                'edit_item' => __('Edit Application', 'ox-applicants'),
                'new_item' => __('New Application', 'ox-applicants'),
                'view_item' => __('View Application', 'ox-applicants'),
                'search_items' => __('Search Applications', 'ox-applicants'),
                'not_found' => __('No applications found', 'ox-applicants'),
                'not_found_in_trash' => __('No applications found in trash', 'ox-applicants'),
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => false, // We'll add this to our custom menu
            'capability_type' => 'post',
            'hierarchical' => false,
            'rewrite' => false,
            'supports' => ['title', 'custom-fields'],
            'show_in_rest' => false,
        ]);

        OX_Applicants_Core::log_debug('Registered ox_application post type');
    }

    /**
     * Add meta boxes
     */
    public function add_meta_boxes(): void {
        add_meta_box(
            'ox_application_details',
            __('Application Details', 'ox-applicants'),
            [$this, 'render_application_details_meta_box'],
            'ox_application',
            'normal',
            'high'
        );

        add_meta_box(
            'ox_application_status',
            __('Application Status', 'ox-applicants'),
            [$this, 'render_application_status_meta_box'],
            'ox_application',
            'side',
            'high'
        );
    }

    /**
     * Render application details meta box
     */
    public function render_application_details_meta_box($post): void {
        wp_nonce_field('ox_application_meta_box', 'ox_application_meta_box_nonce');

        $user_id = get_post_meta($post->ID, '_user_id', true);
        $email = get_post_meta($post->ID, '_email', true);
        $phone = get_post_meta($post->ID, '_phone', true);
        $address = get_post_meta($post->ID, '_address', true);
        $wine_course = get_post_meta($post->ID, '_wine_course', true);
        $course_info = get_post_meta($post->ID, '_course_info', true);
        $username = get_post_meta($post->ID, '_username', true);

        ?>
        <table class="form-table">
            <tr>
                <th scope="row"><?php _e('User ID', 'ox-applicants'); ?></th>
                <td>
                    <input type="text" name="user_id" value="<?php echo esc_attr($user_id); ?>" class="regular-text" readonly />
                    <?php if ($user_id): ?>
                        <a href="<?php echo admin_url('user-edit.php?user_id=' . $user_id); ?>" target="_blank">
                            <?php _e('View User Profile', 'ox-applicants'); ?>
                        </a>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php _e('Email', 'ox-applicants'); ?></th>
                <td><input type="email" name="email" value="<?php echo esc_attr($email); ?>" class="regular-text" readonly /></td>
            </tr>
            <tr>
                <th scope="row"><?php _e('Username', 'ox-applicants'); ?></th>
                <td><input type="text" name="username" value="<?php echo esc_attr($username); ?>" class="regular-text" readonly /></td>
            </tr>
            <tr>
                <th scope="row"><?php _e('Phone', 'ox-applicants'); ?></th>
                <td><input type="text" name="phone" value="<?php echo esc_attr($phone); ?>" class="regular-text" readonly /></td>
            </tr>
            <tr>
                <th scope="row"><?php _e('Address', 'ox-applicants'); ?></th>
                <td><textarea name="address" rows="3" class="large-text" readonly><?php echo esc_textarea($address); ?></textarea></td>
            </tr>
            <tr>
                <th scope="row"><?php _e('Wine Course', 'ox-applicants'); ?></th>
                <td><textarea name="wine_course" rows="3" class="large-text" readonly><?php echo esc_textarea($wine_course); ?></textarea></td>
            </tr>
            <tr>
                <th scope="row"><?php _e('Course Info', 'ox-applicants'); ?></th>
                <td><textarea name="course_info" rows="3" class="large-text" readonly><?php echo esc_textarea($course_info); ?></textarea></td>
            </tr>
        </table>
        <?php
    }

    /**
     * Render application status meta box
     */
    public function render_application_status_meta_box($post): void {
        $status = get_post_meta($post->ID, '_status', true);
        if (empty($status)) {
            $status = 'new';
        }

        $user_id = get_post_meta($post->ID, '_user_id', true);
        $subscription_id = get_post_meta($post->ID, '_subscription_id', true);

        ?>
        <p>
            <label for="application_status"><?php _e('Status:', 'ox-applicants'); ?></label>
            <select name="application_status" id="application_status">
                <option value="new" <?php selected($status, 'new'); ?>><?php _e('New', 'ox-applicants'); ?></option>
                <option value="on_hold" <?php selected($status, 'on_hold'); ?>><?php _e('On Hold', 'ox-applicants'); ?></option>
                <option value="accepted" <?php selected($status, 'accepted'); ?>><?php _e('Accepted', 'ox-applicants'); ?></option>
                <option value="rejected" <?php selected($status, 'rejected'); ?>><?php _e('Rejected', 'ox-applicants'); ?></option>
            </select>
        </p>

        <?php if ($subscription_id): ?>
            <p>
                <strong><?php _e('Subscription ID:', 'ox-applicants'); ?></strong> <?php echo esc_html($subscription_id); ?>
            </p>
        <?php endif; ?>

        <p>
            <strong><?php _e('Application Date:', 'ox-applicants'); ?></strong><br>
            <?php echo get_the_date('F j, Y g:i a', $post->ID); ?>
        </p>
        <?php
    }

    /**
     * Save meta boxes
     */
    public function save_meta_boxes($post_id): void {
        // Security checks
        if (!isset($_POST['ox_application_meta_box_nonce']) || 
            !wp_verify_nonce($_POST['ox_application_meta_box_nonce'], 'ox_application_meta_box')) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        // Save status if changed
        if (isset($_POST['application_status'])) {
            $old_status = get_post_meta($post_id, '_status', true);
            $new_status = sanitize_text_field($_POST['application_status']);
            
            if ($old_status !== $new_status) {
                update_post_meta($post_id, '_status', $new_status);
                
                // Log status change
                OX_Applicants_Core::log_debug("Application {$post_id} status changed from {$old_status} to {$new_status}");
                
                // Handle status change actions
                $this->handle_status_change($post_id, $new_status, $old_status);
            }
        }
    }

    /**
     * Handle application status changes
     */
    private function handle_status_change($post_id, string $new_status, string $old_status): void {
        $user_id = get_post_meta($post_id, '_user_id', true);
        
        if (!$user_id) {
            OX_Applicants_Core::log_error("No user ID found for application {$post_id}");
            return;
        }

        switch ($new_status) {
            case 'accepted':
                $this->handle_application_accepted($post_id, $user_id);
                break;
            case 'rejected':
                $this->handle_application_rejected($post_id, $user_id);
                break;
        }
    }

    /**
     * Handle accepted application
     */
    private function handle_application_accepted($post_id, $user_id): void {
        // Change user role to member
        $user = get_user_by('id', $user_id);
        if ($user) {
            $user->set_role('member');
            OX_Applicants_Core::log_debug("Changed user {$user_id} role to member");
        }

        // Add member tag
        OX_Applicants_Core::add_user_access_tag($user_id, 'member');

        // TODO: Create WooCommerce subscription (will be implemented later)
        OX_Applicants_Core::log_debug("Application {$post_id} accepted - subscription creation will be implemented in next phase");
    }

    /**
     * Handle rejected application
     */
    private function handle_application_rejected($post_id, $user_id): void {
        // Remove member tag if it exists
        OX_Applicants_Core::remove_user_access_tag($user_id, 'member');
        
        OX_Applicants_Core::log_debug("Application {$post_id} rejected");
    }

    /**
     * Set custom columns
     */
    public function set_custom_columns($columns): array {
        $new_columns = [];
        
        $new_columns['cb'] = $columns['cb'];
        $new_columns['title'] = __('Applicant Name', 'ox-applicants');
        $new_columns['email'] = __('Email', 'ox-applicants');
        $new_columns['status'] = __('Status', 'ox-applicants');
        $new_columns['date'] = __('Date', 'ox-applicants');
        
        return $new_columns;
    }

    /**
     * Custom column content
     */
    public function custom_column_content($column, $post_id): void {
        switch ($column) {
            case 'email':
                $email = get_post_meta($post_id, '_email', true);
                echo esc_html($email);
                break;
            case 'status':
                $status = get_post_meta($post_id, '_status', true);
                if (empty($status)) {
                    $status = 'new';
                }
                
                $status_labels = [
                    'new' => __('New', 'ox-applicants'),
                    'on_hold' => __('On Hold', 'ox-applicants'),
                    'accepted' => __('Accepted', 'ox-applicants'),
                    'rejected' => __('Rejected', 'ox-applicants'),
                ];
                
                $status_class = [
                    'new' => 'status-new',
                    'on_hold' => 'status-on-hold',
                    'accepted' => 'status-accepted',
                    'rejected' => 'status-rejected',
                ];
                
                echo '<span class="' . esc_attr($status_class[$status] ?? '') . '">' . esc_html($status_labels[$status] ?? $status) . '</span>';
                break;
        }
    }

    /**
     * Set sortable columns
     */
    public function set_sortable_columns($columns): array {
        $columns['status'] = 'status';
        $columns['email'] = 'email';
        return $columns;
    }

    /**
     * Custom orderby
     */
    public function custom_orderby($query): void {
        if (!is_admin() || !$query->is_main_query()) {
            return;
        }

        if ($query->get('post_type') !== 'ox_application') {
            return;
        }

        $orderby = $query->get('orderby');

        switch ($orderby) {
            case 'status':
                $query->set('meta_key', '_status');
                $query->set('orderby', 'meta_value');
                break;
            case 'email':
                $query->set('meta_key', '_email');
                $query->set('orderby', 'meta_value');
                break;
        }
    }

    /**
     * Modify row actions
     */
    public function modify_row_actions($actions, $post): array {
        if ($post->post_type !== 'ox_application') {
            return $actions;
        }

        // Remove edit link since applications shouldn't be edited
        unset($actions['edit']);
        
        // Add view link
        $actions['view'] = '<a href="' . admin_url('admin.php?page=ox-applications&action=view&id=' . $post->ID) . '">' . __('View', 'ox-applicants') . '</a>';

        return $actions;
    }
} 