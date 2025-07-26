# OX Applicants Plugin

A WordPress plugin for handling applicant form submissions, user creation, and application management with WooCommerce Subscriptions integration.

## Features

### Phase 1 (Current)
- **FluentForms Integration**: Automatically processes form submissions from the "Apply to Join" form
- **User Creation**: Creates WordPress user accounts with "Applicant" role immediately upon form submission
- **Application Management**: Custom post type for storing and managing applications
- **Admin Interface**: Clean, intuitive admin interface for reviewing applications
- **Status Management**: Four application statuses: New, On Hold, Accepted, Rejected
- **Role Management**: Automatically changes user role from "Applicant" to "Member" when accepted
- **Access Tags**: Integrates with ox-content-blocker plugin for member access control
- **ACF Integration**: Stores custom fields if Advanced Custom Fields is available
- **WooCommerce Subscriptions Integration**: Automatic subscription creation for accepted applications
- **Renewal Management**: Dashboard for managing subscription renewals
- **Email Notifications**: Custom email templates for application status changes

## Requirements

- WordPress 5.0+
- PHP 7.4+
- FluentForms plugin
- WooCommerce Subscriptions plugin
- ox-content-blocker plugin (for access tags)
- MySQL database (WooCommerce Subscriptions requires MySQL, not SQLite)

## Installation

1. Upload the `ox-applicants` folder to your `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Configure the subscription product ID in the plugin settings

## Configuration

### Settings Page
Navigate to **Applications & Renewals > Settings** to configure:

- **Subscription Product ID**: The WooCommerce subscription product to use for new members

### FluentForms Setup
The plugin automatically processes submissions from FluentForms form ID 3 ("Apply to Join"). Ensure your form has the following fields:

- `names.first_name` - First name
- `names.last_name` - Last name
- `email` - Email address
- `phone` - Phone number
- `wine_course` - Wine course selection (ACF field)
- `course_info` - Course information (ACF field)

### User Roles
The plugin automatically creates two user roles if they don't exist:
- **Applicant**: Assigned to new users upon form submission
- **Member**: Assigned when applications are accepted

## Usage

### Application Workflow

1. **Form Submission**: User submits application via FluentForms
2. **User Creation**: WordPress user account created with "Applicant" role
3. **Application Review**: Admin reviews application in Applications & Renewals > Applications
4. **Status Management**: Admin can set status to New, On Hold, Accepted, or Rejected
5. **Acceptance Process**: When accepted:
   - User role changes to "Member"
   - "member" tag added to ox-content-blocker
   - WooCommerce subscription created (manual renewal, on-hold status)
   - Order created (awaiting payment)
   - Acceptance email sent to user

### Admin Interface

#### Applications Page
- View all applications with status filtering
- Filter by: Waiting (New + On Hold), New, On Hold, Accepted, Rejected
- Click application name to view details
- Update application status with inline form

#### Application Detail Page
- View complete application information
- Update application status
- View user profile link
- View subscription and order links (if created)
- Track acceptance metadata

#### Renewals Page
- View all subscriptions due for renewal
- Filter by: Next 30 Days, Current Month, Next Month
- Shows subscription ID, customer, product, next payment date, renewal method, status
- Click subscription ID to edit in WooCommerce

#### Settings Page
- Configure subscription product ID
- Product lookup functionality
- Validation of subscription products

## Database Schema

### Custom Post Type: `ox_application`
- Stores application data with status tracking
- Meta fields for user ID, status, dates, subscription references

### User Meta
- ACF fields: `wine_course`, `course_info`
- ox-content-blocker tags: `_oxcb_access_tags`

## Error Handling

The plugin includes comprehensive error handling:
- **Rollback functionality**: If any step fails, all changes are reverted
- **Database compatibility**: Detects SQLite and prevents subscription creation
- **Validation**: Validates all inputs and dependencies
- **Logging**: Detailed error logging for debugging

## Development Notes

### Local Development (SQLite)
- All functionality works except WooCommerce Subscriptions
- Subscription creation is blocked with clear error messages
- Perfect for testing application workflow and admin interface

### Production (MySQL)
- Full functionality including subscription creation
- WooCommerce Subscriptions integration works seamlessly
- All features available

### Debugging
- Check `wp-content/debug.log` for detailed error messages
- All plugin actions are logged with "OX Applicants:" prefix
- Error messages include context and rollback information

## Changelog

### Version 1.0.0
- Initial release
- FluentForms integration
- Application management system
- WooCommerce Subscriptions integration
- Renewal dashboard
- Comprehensive error handling and rollback

## Support

For support and bug reports, please contact the development team.

## License

GPL v2 or later 