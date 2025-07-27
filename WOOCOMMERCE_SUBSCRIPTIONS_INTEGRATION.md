# WooCommerce Subscriptions Integration Guide

This document summarizes our learnings and best practices for integrating with WooCommerce Subscriptions, based on the development of the OX-Applicants plugin.

## Table of Contents

1. [Core Concepts](#core-concepts)
2. [Dependencies and Requirements](#dependencies-and-requirements)
3. [Programmatic Order Creation](#programmatic-order-creation)
4. [Subscription Creation](#subscription-creation)
5. [Manual Renewal Setup](#manual-renewal-setup)
6. [Payment Processing](#payment-processing)
7. [Email Integration](#email-integration)
8. [Database Compatibility](#database-compatibility)
9. [Auto-Renewal Status Checking](#auto-renewal-status-checking)
10. [Common Issues and Solutions](#common-issues-and-solutions)
11. [Best Practices](#best-practices)

## Core Concepts

### Order vs Subscription Relationship

- **Orders**: Contain subscription products and trigger subscription creation
- **Subscriptions**: Created automatically when orders contain subscription products
- **Manual Renewals**: Orders with empty payment method that require manual payment processing

### Status Flow

- **Order Status**: `pending` (needs payment) → `processing` (payment complete)
- **Subscription Status**: `on-hold` (awaiting payment) → `active` (payment complete)

## Dependencies and Requirements

### Required Plugins

```php
// Check if WooCommerce Subscriptions is active
if (!class_exists('WC_Subscriptions')) {
    throw new Exception('WooCommerce Subscriptions is required');
}

// Check if WooCommerce is active
if (!class_exists('WooCommerce')) {
    throw new Exception('WooCommerce is required');
}
```

### Function Availability Checks

```php
// Check for WooCommerce Subscriptions functions
if (!function_exists('wcs_create_order')) {
    throw new Exception('WooCommerce Subscriptions order creation function not available');
}

if (!function_exists('wcs_create_subscription')) {
    throw new Exception('WooCommerce Subscriptions subscription creation function not available');
}
```

## Programmatic Order Creation

### Basic Order Creation

```php
// Create order using WooCommerce function
$order = wc_create_order();

// Set customer
$order->set_customer_id($user_id);

// Add subscription product
$order->add_product($product, $quantity);

// Set addresses
$order->set_address($billing_address, 'billing');
$order->set_address($shipping_address, 'shipping');

// Calculate totals
$order->calculate_totals();
```

### Manual Renewal Order Setup

```php
// For manual renewals, leave payment method empty
$order->set_payment_method('');
$order->set_payment_method_title('Manual Renewal');

// Set order status to 'pending' for payment options to show
$order->update_status('pending', 'Order created programmatically for manual renewal subscription.');
```

### Critical Status Requirements

- **Order Status**: Must be `'pending'` for payment options to appear on order-pay page
- **Payment Method**: Leave empty (`''`) for manual renewals to trigger manual renewal logic
- **Subscription Products**: Must be properly configured with subscription metadata

## Subscription Creation

### Automatic Creation

When an order contains subscription products, WooCommerce Subscriptions automatically creates the subscription:

```php
// Add subscription metadata to order item
$order_item->add_meta_data('_subscription_period', $period);
$order_item->add_meta_data('_subscription_interval', $interval);
$order_item->add_meta_data('_subscription_length', $length);

// Add subscription metadata to order
$order->add_meta_data('_subscription_switch', 'false');
$order->add_meta_data('_subscription_renewal', 'false');
$order->add_meta_data('_subscription_resubscribe', 'false');
```

### Manual Creation (if needed)

```php
$subscription = wcs_create_subscription(array(
    'order_id' => $order->get_id(),
    'customer_id' => $user_id,
    'start_date' => current_time('mysql'),
    'status' => 'on-hold', // Subscription remains on-hold until payment is complete
    'billing_period' => $period,
    'billing_interval' => $interval,
    'next_payment_date' => $next_payment_date,
    'end_date' => $end_date,
    'trial_end_date' => $trial_end_date,
));
```

## Manual Renewal Setup

### Payment Method Configuration

```php
// Set payment method and manual renewal flag
$subscription->set_payment_method('');
$subscription->set_requires_manual_renewal(true);
```

### Manual Renewal Detection

```php
// Check if manual renewal is enabled
if (wcs_is_manual_renewal_enabled()) {
    // Handle manual renewal logic
}
```

## Payment Processing

### Payment URL Generation

```php
// Get payment URL for pending order
$payment_url = $order->get_checkout_payment_url();

// Check if order needs payment
if ($order->needs_payment()) {
    // Order requires payment
}
```

### Payment Status Checks

```php
// Check if order contains subscription
if (wcs_order_contains_subscription($order)) {
    // Handle subscription order
}

// Check if order contains renewal
if (wcs_order_contains_renewal($order)) {
    // Handle renewal order
}
```

## Email Integration

### Disabling Standard Emails

```php
// Disable standard WooCommerce order emails for programmatic orders
add_filter('woocommerce_email_enabled_customer_on_hold_order', function($enabled, $order) {
    // Check if this is a programmatically created order
    if ($order->get_meta('_ox_applicants_created')) {
        return false; // Disable standard email
    }
    return $enabled;
}, 10, 2);
```

### Custom Email Templates

```php
// Process custom email template with variable substitution
$email_content = $this->process_email_template(
    $template,
    $customer,
    $subscription,
    $order,
    $product_name,
    $product_price,
    $billing_text,
    $total,
    $next_payment_date,
    $payment_url,
    $billing_address,
    $password_setup_url
);

// Send custom email
wp_mail($customer->get_email(), $subject, $email_content, $headers);
```

### Email Template Variables

Available substitution variables for custom email templates:

- `{site_name}` - Site name
- `{customer_first_name}` - Customer first name
- `{customer_last_name}` - Customer last name
- `{customer_email}` - Customer email
- `{product_name}` - Subscription product name
- `{product_price}` - Product price
- `{billing_text}` - Billing cycle text
- `{next_payment_date}` - Next payment date
- `{payment_url}` - Payment URL
- `{billing_address}` - Formatted billing address
- `{order_number}` - Order number
- `{subscription_number}` - Subscription number
- `{total}` - Order total
- `{password_setup_url}` - Password setup URL

## Database Compatibility

### SQLite vs MySQL

- **Local Development**: SQLite (WordPress Studio)
- **Production**: MySQL
- **WooCommerce Subscriptions**: May have compatibility issues with SQLite
- **Testing**: Always test on MySQL before production deployment

### Database Schema

WooCommerce Subscriptions creates additional tables:

- `wp_wc_subscriptions`
- `wp_wc_subscription_meta`
- `wp_wc_subscription_schedule`

## Auto-Renewal Status Checking

### Checking if Subscription is Manual vs Automatic

There are several methods to determine if a subscription is set to auto-renew:

#### Method 1: Using `is_manual()` (Recommended)
```php
// Check if subscription is manual (not auto-renew)
if ($subscription->is_manual()) {
    // Subscription requires manual renewal
    echo "Manual renewal required";
} else {
    // Subscription is set to auto-renew
    echo "Auto-renewal enabled";
}
```

#### Method 2: Using `get_requires_manual_renewal()`
```php
// Check the manual renewal flag directly
if ($subscription->get_requires_manual_renewal()) {
    // Subscription requires manual renewal
    echo "Manual renewal required";
} else {
    // Subscription is set to auto-renew
    echo "Auto-renewal enabled";
}
```

#### Method 3: Checking Payment Method
```php
// Check if payment method is empty (indicates manual renewal)
if (empty($subscription->get_payment_method())) {
    // Manual renewal
    echo "Manual renewal (no payment method)";
} else {
    // Check if payment gateway supports automatic payments
    $payment_gateway = wc_get_payment_gateway_by_order($subscription);
    if ($payment_gateway && $payment_gateway->supports('subscription_cancellation')) {
        echo "Auto-renewal enabled";
    } else {
        echo "Manual renewal (gateway doesn't support auto-payments)";
    }
}
```

### Checking Auto-Renewal Status in Renewals Data

When building renewal reports, you can include auto-renewal status:

```php
// In your renewals data collection
$renewal_method = 'manual';
if (!$subscription->is_manual()) {
    $renewal_method = 'automatic';
}

$subscription_data = [
    'id' => $subscription->get_id(),
    'customer_id' => $customer_id,
    'customer_name' => $customer_name,
    'next_payment_date' => $next_payment_date,
    'renewal_method' => $renewal_method, // 'manual' or 'automatic'
    'status' => $subscription->get_status()
];
```

### User Interface for Auto-Renewal Toggle

WooCommerce Subscriptions provides a built-in toggle for users to enable/disable auto-renewal:

```php
// Check if user can toggle auto-renewal
if (WCS_My_Account_Auto_Renew_Toggle::can_user_toggle_auto_renewal($subscription)) {
    // User can change auto-renewal setting
    if ($subscription->is_manual()) {
        // Show "Enable auto renew" button
        echo "Enable auto-renewal";
    } else {
        // Show "Disable auto renew" button
        echo "Disable auto-renewal";
    }
}
```

### Admin Interface Considerations

In admin interfaces, you can display auto-renewal status:

```php
// In admin subscription list
$renewal_status = $subscription->is_manual() ? 
    '<span class="manual-renewal">Manual</span>' : 
    '<span class="auto-renewal">Automatic</span>';

echo $renewal_status;
```

### Global Settings

Check if manual renewals are required globally:

```php
// Check if manual renewals are required site-wide
if (wcs_is_manual_renewal_required()) {
    // All new subscriptions will be manual
    echo "Manual renewals required site-wide";
}

// Check if manual renewals are enabled
if (wcs_is_manual_renewal_enabled()) {
    // Manual renewals are allowed
    echo "Manual renewals enabled";
}
```

### Key Differences Between Methods

- **`is_manual()`**: Most reliable method, considers all factors including staging sites
- **`get_requires_manual_renewal()`**: Direct flag value, may not consider staging sites
- **Payment method check**: More complex but gives detailed information about why auto-renewal isn't available

### Best Practices

1. **Use `is_manual()` for most checks** - it's the most comprehensive method
2. **Consider staging environments** - auto-renewal may be disabled in staging
3. **Check payment gateway support** - not all gateways support automatic payments
4. **Provide clear user feedback** - explain why auto-renewal isn't available if applicable

## Common Issues and Solutions

### Issue: "No subscription found for order"

**Cause**: Order not properly configured as subscription order
**Solution**: Ensure subscription metadata is added to order items and order

### Issue: "Payment options not showing"

**Cause**: Order status not set to `'pending'`
**Solution**: Set order status to `'pending'` instead of `'on-hold'`

### Issue: "Manual renewal not working"

**Cause**: Payment method not set to empty string
**Solution**: Use `set_payment_method('')` for manual renewals

### Issue: "Function not available"

**Cause**: WooCommerce Subscriptions not fully loaded
**Solution**: Check function availability before use

### Issue: "Email template HTML stripped"

**Cause**: Using `wp_kses_post()` instead of custom sanitization
**Solution**: Use custom sanitization function that allows `<head>`, `<style>`, etc.

## Best Practices

### Error Handling

```php
try {
    // WooCommerce Subscriptions operations
    $order = wc_create_order();
    // ... setup order
  
    // Verify subscription was created
    $subscriptions = wcs_get_subscriptions_for_order($order);
    if (empty($subscriptions)) {
        throw new Exception('Failed to create subscription from order');
    }
  
} catch (Exception $e) {
    // Log error and rollback changes
    error_log("OX Applicants: " . $e->getMessage());
    // Rollback user role, tags, etc.
}
```

### Debug Logging

```php
// Add comprehensive logging for troubleshooting
error_log("OX Applicants: Creating order...");
error_log("OX Applicants: Order created with ID: " . $order->get_id());
error_log("OX Applicants: Setting order status to pending...");
error_log("OX Applicants: Generated payment URL: " . $payment_url);
```

### Validation

```php
// Validate subscription product
if (!WC_Subscriptions_Product::is_subscription($product_id)) {
    throw new Exception('Product is not a subscription product');
}

// Validate user exists
$user = get_user_by('id', $user_id);
if (!$user) {
    throw new Exception('User not found');
}
```

### Security

```php
// Verify nonces for admin actions
if (!wp_verify_nonce($_POST['nonce'], 'action_name')) {
    wp_die('Security check failed');
}

// Check user capabilities
if (!current_user_can('manage_options')) {
    wp_die('Insufficient permissions');
}
```

## Resources

### Official Documentation

- [WooCommerce Subscriptions Developer Documentation](https://woocommerce.com/documentation/products/extensions/woocommerce-subscriptions/developer-docs/)
- [WooCommerce REST API Documentation](https://woocommerce.github.io/woocommerce-rest-api-docs/)

### Key Functions

- `wcs_create_order()` - Create subscription order
- `wcs_create_subscription()` - Create subscription manually
- `wcs_get_subscriptions_for_order()` - Get subscriptions for order
- `wcs_order_contains_subscription()` - Check if order contains subscription
- `wcs_is_manual_renewal_enabled()` - Check manual renewal status

### Hooks and Filters

- `woocommerce_email_enabled_*` - Control email sending
- `woocommerce_order_status_*` - Order status change hooks
- `woocommerce_subscription_status_*` - Subscription status change hooks

---

*This document was created based on the development experience of the OX-Applicants plugin. Last updated: July 2025*
