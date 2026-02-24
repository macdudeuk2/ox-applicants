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
- **Custom Email Templates**: Fully customizable acceptance notification emails with variable substitution
- **Password Setup**: Automatic password setup links for new users
- **Manual Renewal Support**: Proper manual renewal subscription creation with payment links

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
- **Custom Email Template**: HTML template for acceptance notification emails with variable substitution

### Email Template Variables
The custom email template supports the following variables:

| Variable | Description |
|----------|-------------|
| `{customer_name}` | Customer's full name |
| `{customer_first_name}` | Customer's first name |
| `{customer_email}` | Customer's email address |
| `{subscription_id}` | Subscription ID |
| `{order_id}` | Order ID |
| `{order_number}` | Order number |
| `{product_name}` | Subscription product name |
| `{product_price}` | Formatted product price |
| `{billing_period}` | Billing period (e.g., "week", "month") |
| `{billing_text}` | Formatted billing text (e.g., "weekly", "monthly") |
| `{total}` | Formatted order total |
| `{next_payment_date}` | Next payment date |
| `{payment_url}` | Direct payment link for the order |
| `{password_setup_url}` | Secure link for setting initial password |
| `{billing_address}` | Formatted billing address |
| `{billing_email}` | Billing email address |
| `{billing_phone}` | Billing phone number |

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
2. **User Creation**: WordPress user account created with "Applicant" role (no password)
3. **Application Review**: Admin reviews application in Applications & Renewals > Applications
4. **Status Management**: Admin can set status to New, On Hold, Accepted, or Rejected
5. **Acceptance Process**: When accepted:
   - User role changes to "Member"
   - "member" tag added to ox-content-blocker
   - WooCommerce subscription created (manual renewal, on-hold status)
   - Order created (pending status, awaiting payment)
   - Custom acceptance email sent to user with:
     - Password setup link
     - Payment link
     - Subscription details
     - Billing information

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
- Persistent success messages

#### Renewals Page
- View all subscriptions due for renewal
- Filter by: Next 30 Days, Current Month, Next Month, Last Month
- Custom date range filtering
- Export to CSV functionality
- Shows subscription ID, customer, product, next payment date, renewal method, status
- Click subscription ID to edit in WooCommerce

#### Settings Page
- Configure subscription product ID
- Product lookup functionality
- Validation of subscription products
- Custom email template editor with variable documentation
- Template sanitization and validation

## Database Schema

### Custom Post Type: `ox_application`
- Stores application data with status tracking
- Meta fields for user ID, status, dates, subscription references
- Last action date tracking

### User Meta
- ACF fields: `wine_course`, `course_info`
- WooCommerce billing/shipping fields: `billing_first_name`, `billing_last_name`, `billing_address_1`, etc.
- ox-content-blocker tags: `_oxcb_access_tags`

## Error Handling

The plugin includes comprehensive error handling:
- **Rollback functionality**: If any step fails, all changes are reverted
- **Database compatibility**: Detects SQLite and prevents subscription creation
- **Validation**: Validates all inputs and dependencies
- **Logging**: Detailed error logging for debugging
- **Payment method validation**: Ensures proper manual renewal setup

## Development Notes

### Local Development (SQLite)
- All functionality works except WooCommerce Subscriptions
- Subscription creation is blocked with clear error messages
- Perfect for testing application workflow and admin interface

### Production (MySQL)
- Full functionality including subscription creation
- WooCommerce Subscriptions integration works seamlessly
- All features available including manual renewal payments

### Debugging
- Check `wp-content/debug.log` for detailed error messages
- All plugin actions are logged with "OX Applicants:" prefix
- Error messages include context and rollback information
- Payment gateway debugging for manual renewal orders

## Changelog

### Version 1.1.3
- **Renewals Date Filter Fix**: Fixed bug where renewal date filters (Next Month, Current Month, Last Month, Next 30 Days) could miss subscriptions due on boundary dates because `strtotime()` preserved the current time-of-day in filter boundaries instead of using start/end of day
- **Custom Date Range Fix**: Ensured custom date range start boundary is normalised to 00:00:00

### Version 1.1.2
- **Duplicate Application Handling**: Added comprehensive duplicate application detection and management
- **New Application Status**: Introduced "duplicate" status for applications from existing users
- **Enhanced Admin Interface**: Added duplicate warnings and existing user information display
- **Improved User Experience**: No more silent rejections - all applications are now processed and stored
- **Data Preservation**: All application data is preserved, even for duplicate submissions
- **Admin Notifications**: Clear warnings and information about potential duplicate applications

### Version 1.1.1
- **CSV Export Fix**: Fixed issue where CSV export contained HTML content instead of clean CSV data
- **Improved Export Handling**: Moved CSV export to admin_init stage to prevent WordPress admin content interference
- **Better Separation of Concerns**: Separated display logic from export logic for cleaner code structure

### Version 1.1.0
- **Custom Email Templates**: Added fully customizable HTML email templates for acceptance notifications
- **Password Setup Links**: Automatic secure password setup links for new users
- **Manual Renewal Fixes**: Proper order status (pending) and subscription status (on-hold) configuration
- **Payment Link Integration**: Direct payment links in acceptance emails
- **Enhanced Renewals Dashboard**: Added "Last Month" filter and custom date range functionality
- **CSV Export**: Export renewals data to CSV format
- **Improved Error Handling**: Better validation and rollback for subscription creation
- **Template Variables**: Comprehensive variable substitution system for email templates
- **WooCommerce Integration**: Proper billing/shipping address storage in WooCommerce fields

### Version 1.0.0
- Initial release

## Support

For support and bug reports, please contact the development team.

## License

GPL v2 or later 