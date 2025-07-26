<?php
/**
 * OX Applicants FluentForms Integration Class
 *
 * @package OXApplicants
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles FluentForms integration for application processing
 */
class OX_Applicants_FluentForms {



    /**
     * Constructor
     */
    public function __construct() {
        // Hook into FluentForms submission (only after insert to avoid duplicates)
        add_action('fluentform/submission_inserted', [$this, 'handle_form_submission_after'], 10, 3);
        
        // Create applicant role on init
        add_action('init', [OX_Applicants_Core::class, 'create_applicant_role']);
        add_action('init', [OX_Applicants_Core::class, 'create_member_role']);
        
        // error_log('OX Applicants: FluentForms integration initialized with submission_inserted hook');
    }


    
    /**
     * Handle FluentForms submission (after insert)
     *
     * @param int $insertId Inserted submission ID
     * @param array $formData Form submission data
     * @param object $form Form object
     */
    public function handle_form_submission_after($insertId, $formData, $form): void {
        $form_id = $form->id;
        
        // Only process the "Apply to Join" form (ID: 3)
        if ($form_id !== 3) {
            // error_log('OX Applicants: Skipping form - not the target form (ID: ' . $form_id . ')');
            return;
        }
        
        // error_log('OX Applicants: Processing target form submission - form ID: ' . $form_id . ', insert ID: ' . $insertId);
        
        $this->process_form_submission($formData, $form, $insertId);
    }
    
    /**
     * Process form submission (common logic)
     *
     * @param array $form_data Form submission data
     * @param object $form Form object
     * @param int $insert_id Inserted submission ID
     */
    private function process_form_submission($form_data, $form, $insert_id): void {
        // Only process the "Apply to Join" form (ID: 3)
        if ($form->id !== 3) {
            // error_log('OX Applicants: Skipping form - not the target form');
            return;
        }

        try {
            // Extract and validate form data
            $application_data = $this->extract_application_data($form_data);
            
            if (!$application_data) {
                error_log('OX Applicants: Failed to extract application data from form submission');
                return;
            }

            // Create user account
            $user_id = $this->create_user_account($application_data);
            
            if (!$user_id) {
                error_log('OX Applicants: Failed to create user account');
                return;
            }

            // Store application data
            $application_id = $this->store_application_data($user_id, $application_data);
            
            if (!$application_id) {
                error_log('OX Applicants: Failed to store application data');
                return;
            }

            // error_log('OX Applicants: Application processed successfully - User ID: ' . $user_id . ', Application ID: ' . $application_id . ', Insert ID: ' . $insert_id);

        } catch (Exception $e) {
            error_log('OX Applicants: Exception during form processing: ' . $e->getMessage());
        }
    }

    /**
     * Extract application data from form submission
     *
     * @param array $form_data Form submission data
     * @return array|false Application data or false on failure
     */
    private function extract_application_data(array $form_data) {
        OX_Applicants_Core::log_debug('Extracting application data from form submission');
        OX_Applicants_Core::log_debug('Form data structure:', $form_data);
        
        // Map form fields to application data
        $application_data = [
            'first_name' => $this->get_form_field($form_data, 'names.first_name'),
            'last_name' => $this->get_form_field($form_data, 'names.last_name'),
            'email' => $this->get_form_field($form_data, 'email'),
            'username' => $this->get_form_field($form_data, 'input_text'),
            'phone' => $this->get_form_field($form_data, 'numeric_field'),
            'address_line_1' => $this->get_form_field($form_data, 'address_1.address_line_1'),
            'address_line_2' => $this->get_form_field($form_data, 'address_1.address_line_2'),
            'city' => $this->get_form_field($form_data, 'address_1.city'),
            'state' => $this->get_form_field($form_data, 'address_1.state'),
            'zip' => $this->get_form_field($form_data, 'address_1.zip'),
            'country' => $this->get_form_field($form_data, 'address_1.country'),
            'wine_course' => $this->get_form_field($form_data, 'description'),
            'course_info' => $this->get_form_field($form_data, 'description_1'),
        ];
        
        // Debug: Log the extracted values
        // error_log('OX Applicants: Extracted wine_course: "' . $application_data['wine_course'] . '"');
        // error_log('OX Applicants: Extracted course_info: "' . $application_data['course_info'] . '"');
        
        OX_Applicants_Core::log_debug('Extracted application data', [
            'first_name' => $application_data['first_name'],
            'last_name' => $application_data['last_name'],
            'email' => $application_data['email'],
            'username' => $application_data['username'],
            'phone' => $application_data['phone']
        ]);

        // Only check if user already exists (to avoid duplicates)
        OX_Applicants_Core::log_debug('Checking if user exists with email: ' . $application_data['email']);
        if (email_exists($application_data['email'])) {
            OX_Applicants_Core::log_error('User already exists with email: ' . $application_data['email']);
            return false;
        }
        
        OX_Applicants_Core::log_debug('User existence check passed');

        // Generate username if not provided
        if (empty($application_data['username'])) {
            $application_data['username'] = $this->generate_username($application_data['first_name'], $application_data['last_name']);
        }

        // Check if username is available
        if (username_exists($application_data['username'])) {
            $application_data['username'] = $this->generate_unique_username($application_data['username']);
        }

        return $application_data;
    }

    /**
     * Get form field value using dot notation
     *
     * @param array $form_data Form data
     * @param string $field_path Field path (e.g., 'names.first_name')
     * @return string Field value
     */
    private function get_form_field(array $form_data, string $field_path): string {
        $keys = explode('.', $field_path);
        $value = $form_data;

        foreach ($keys as $key) {
            if (isset($value[$key])) {
                $value = $value[$key];
            } else {
                return '';
            }
        }

        return is_string($value) ? trim($value) : '';
    }

    /**
     * Generate username from first and last name
     *
     * @param string $first_name First name
     * @param string $last_name Last name
     * @return string Username
     */
    private function generate_username(string $first_name, string $last_name): string {
        $username = strtolower($first_name . '.' . $last_name);
        $username = preg_replace('/[^a-z0-9.]/', '', $username);
        return $username;
    }

    /**
     * Generate unique username
     *
     * @param string $base_username Base username
     * @return string Unique username
     */
    private function generate_unique_username(string $base_username): string {
        $username = $base_username;
        $counter = 1;

        while (username_exists($username)) {
            $username = $base_username . $counter;
            $counter++;
        }

        return $username;
    }

    /**
     * Create user account
     *
     * @param array $application_data Application data
     * @return int|false User ID or false on failure
     */
    private function create_user_account(array $application_data) {
        // Prepare user data
        $user_data = [
            'user_login' => $application_data['username'],
            'user_email' => $application_data['email'],
            'first_name' => $application_data['first_name'],
            'last_name' => $application_data['last_name'],
            'display_name' => $application_data['first_name'] . ' ' . $application_data['last_name'],
            'role' => 'applicant',
            'user_pass' => '', // No password - user will set via reset
        ];

        // Create user
        $user_id = wp_insert_user($user_data);

        if (is_wp_error($user_id)) {
            OX_Applicants_Core::log_error('Failed to create user: ' . $user_id->get_error_message());
            return false;
        }

        // Store additional user meta
        $this->store_user_meta($user_id, $application_data);

        // Store ACF fields if available
        $this->store_acf_fields($user_id, $application_data);

        OX_Applicants_Core::log_debug("Created user account: {$user_id}");

        return $user_id;
    }

    /**
     * Store user meta data
     *
     * @param int $user_id User ID
     * @param array $application_data Application data
     */
    private function store_user_meta(int $user_id, array $application_data): void {
        // Store address information
        $address_parts = [
            $application_data['address_line_1'],
            $application_data['address_line_2'],
            $application_data['city'],
            $application_data['state'],
            $application_data['zip'],
            $application_data['country']
        ];
        
        $address = implode("\n", array_filter($address_parts));
        update_user_meta($user_id, 'address', $address);

        // Store phone
        if (!empty($application_data['phone'])) {
            update_user_meta($user_id, 'phone', $application_data['phone']);
        }

        // Store application-specific meta
        update_user_meta($user_id, 'ox_application_username', $application_data['username']);
        update_user_meta($user_id, 'ox_application_date', current_time('mysql'));
    }

    /**
     * Store ACF fields if available
     *
     * @param int $user_id User ID
     * @param array $application_data Application data
     */
    private function store_acf_fields(int $user_id, array $application_data): void {
        if (!OX_Applicants_Core::is_acf_active()) {
            OX_Applicants_Core::log_debug('ACF not available, skipping ACF field storage');
            return;
        }

        try {
            // Store wine_course field
            if (!empty($application_data['wine_course'])) {
                // error_log('OX Applicants: Storing ACF wine_course field: "' . $application_data['wine_course'] . '"');
                update_field('wine_course', $application_data['wine_course'], 'user_' . $user_id);
            } else {
                // error_log('OX Applicants: wine_course field is empty, not storing ACF field');
            }

            // Store course_info field
            if (!empty($application_data['course_info'])) {
                // error_log('OX Applicants: Storing ACF course_info field: "' . $application_data['course_info'] . '"');
                update_field('course_info', $application_data['course_info'], 'user_' . $user_id);
            } else {
                // error_log('OX Applicants: course_info field is empty, not storing ACF field');
            }

            // error_log('OX Applicants: ACF fields stored for user ' . $user_id);
        } catch (Exception $e) {
            error_log('OX Applicants: Failed to store ACF fields: ' . $e->getMessage());
        }
    }

    /**
     * Store application data as custom post type
     *
     * @param int $user_id User ID
     * @param array $application_data Application data
     * @return int|false Application post ID or false on failure
     */
    private function store_application_data(int $user_id, array $application_data) {
        // Prepare address for storage
        $address_parts = [
            $application_data['address_line_1'],
            $application_data['address_line_2'],
            $application_data['city'],
            $application_data['state'],
            $application_data['zip'],
            $application_data['country']
        ];
        $address = implode("\n", array_filter($address_parts));

        // Create application post
        $post_data = [
            'post_title' => $application_data['first_name'] . ' ' . $application_data['last_name'],
            'post_content' => '',
            'post_status' => 'publish',
            'post_type' => 'ox_application',
            'post_author' => 1, // Admin user
        ];

        $application_id = wp_insert_post($post_data);

        if (is_wp_error($application_id)) {
            OX_Applicants_Core::log_error('Failed to create application post: ' . $application_id->get_error_message());
            return false;
        }

        // Store application meta data
        update_post_meta($application_id, '_user_id', $user_id);
        update_post_meta($application_id, '_email', $application_data['email']);
        update_post_meta($application_id, '_username', $application_data['username']);
        update_post_meta($application_id, '_phone', $application_data['phone']);
        update_post_meta($application_id, '_address', $address);
        update_post_meta($application_id, '_wine_course', $application_data['wine_course']);
        update_post_meta($application_id, '_course_info', $application_data['course_info']);
        update_post_meta($application_id, '_status', 'new');
        update_post_meta($application_id, '_application_date', current_time('mysql'));
        update_post_meta($application_id, '_last_action_date', current_time('mysql'));
        
        // error_log('OX Applicants: Stored post meta _wine_course: "' . $application_data['wine_course'] . '"');
        // error_log('OX Applicants: Stored post meta _course_info: "' . $application_data['course_info'] . '"');

        OX_Applicants_Core::log_debug("Created application post: {$application_id}");

        return $application_id;
    }
} 