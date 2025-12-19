# TA-OS Commerce

**Version 1.2.2** - Modular payments system for TA-OS. Gateway-agnostic with PayPal support.

## Overview

TA-OS Commerce is a companion plugin that adds payment processing to the Total Attainment Operating System. It links pricing and checkout to existing TAOS courses instead of creating standalone course records.

## Features

- **Gateway-Agnostic Architecture** - Add new payment gateways without code changes
- **PayPal Integration** - Ready-to-use PayPal checkout with sandbox support
- **TAOS Course Linking** - Attach prices, currencies, and entitlements to existing TAOS courses via admin UI
- **Bundle Support** - One course can grant multiple entitlements
- **Order History** - Track all payments with transaction details
- **TA-OS Integration** - Automatically grants entitlements on successful payment

## Requirements

- WordPress 5.8+
- PHP 7.4+
- TA-OS (ta-student-dashboard) plugin installed and active

## Installation

1. Ensure TA-OS plugin is installed and activated
2. Upload `taos-commerce` folder to `/wp-content/plugins/`
3. Activate the plugin in WordPress admin
4. Configure under **Commerce** menu

## Configuration

### 1. Set Up Payment Gateway

1. Go to **Commerce → Payments**
2. Click **Configure** next to PayPal
3. Enable the gateway
4. Enter your PayPal API credentials:
   - Client ID
   - Client Secret
5. Toggle Sandbox Mode for testing
6. Save Changes

### 2. Link TAOS Courses

1. Go to **Commerce → Courses**
2. Click **Link TAOS Course**
3. Choose a published TAOS course with live commerce visibility and set:
   - **Price** and **Currency**
   - **Enabled Gateways**
   - Optional additional entitlements
4. Save

### 3. View Orders

Go to **Commerce → Orders** to see payment history.

## PayPal Setup

### Sandbox (Testing)

1. Create a PayPal Developer account at [developer.paypal.com](https://developer.paypal.com)
2. Create a new REST API app in the Sandbox section
3. Copy the Client ID and Secret
4. Enable Sandbox Mode in plugin settings

### Live (Production)

1. Create a Live REST API app in PayPal Developer dashboard
2. Copy the Live Client ID and Secret
3. Disable Sandbox Mode in plugin settings
4. Set up webhook URL: `https://yoursite.com/wp-json/taos-commerce/v1/paypal/webhook`

## How It Works

### Payment Flow

1. User clicks "Buy" button on a course
2. PayPal checkout opens
3. User completes payment
4. PayPal confirms via webhook or redirect
5. Order marked as completed
6. Entitlements granted to user via TA-OS
7. User can access purchased content

### Entitlements Bridge

When a payment completes, TA-OS Commerce calls:

```php
taos_grant_entitlement( $user_id, $entitlement_slug, 'purchase' );
```

This updates the user's `ta_courses` meta, granting access to the relevant content.

## Adding New Gateways

Developers can add new payment gateways by implementing the `TAOS_Gateway_Interface`:

```php
class My_Gateway implements TAOS_Gateway_Interface {
    public function get_id(): string { return 'my_gateway'; }
    public function get_name(): string { return 'My Gateway'; }
    // ... implement all interface methods
}

add_action('taos_commerce_register_gateways', function($registry) {
    $registry->register(new My_Gateway());
});
```

## Database Tables

The plugin creates three tables:

- `wp_taos_commerce_courses` - Course definitions
- `wp_taos_commerce_course_entitlements` - Entitlement mappings
- `wp_taos_commerce_orders` - Payment history

## Shortcodes & Functions

### Display Purchase Button

```php
echo taos_commerce_get_purchase_button('level_1', 'Buy Now');
```

### Grant Entitlement Programmatically

```php
taos_grant_entitlement($user_id, 'level_1', 'manual');
```

## Security

- All admin pages require `manage_options` capability
- REST endpoints validate user authentication
- Nonce verification on all forms
- PayPal webhook signature validation
- Idempotent order processing (prevents duplicate charges)

## Changelog

See CHANGELOG.md for full version history.

### 1.0.1
- Removed strict plugin dependency for flexible installation
- Added soft admin notice when TA-OS not detected

### 1.0.0
- Initial release
- PayPal gateway with sandbox support
- Admin UI for gateways, courses, orders
- Bundle support (multiple entitlements per course)
- TA-OS entitlements integration

## License

GPL v2 or later
