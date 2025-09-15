# Libookin Auto Payments Plugin

A comprehensive WordPress plugin that automates Stripe Connect payments for authors and publishers on the Libookin platform. This plugin replaces manual payment systems with automated 2-month rolling payouts while maintaining the existing royalty calculation structure.

## Features

### 🚀 Core Functionality
- **Automated Stripe Connect Integration**: Seamless account creation and management
- **2-Month Rolling Payouts**: Automatic payments every 2 months with €15 minimum threshold
- **Real-time Balance Tracking**: Live synchronization with Stripe Connect accounts
- **Enhanced Vendor Dashboard**: Modern interface with sales analytics and payout history
- **Admin Control Panel**: Comprehensive management tools for platform owners

### 💰 Royalty Management
- **Tiered Royalty Structure**:
  - €0.99-€2.98 → 50%
  - €2.99-€4.98 → 75%
  - €4.99-€9.98 → 80%
  - €9.99+ → 70%
- **Minimum Price Enforcement**: €0.99 minimum product price
- **VAT Exclusion**: All calculations performed excluding VAT
- **Promo Support**: Automatic discount application for promotional periods

### 🌍 Geographic Coverage
- **Buyers**: No restrictions - international customers welcome
- **Sellers**: Limited to Stripe Connect supported countries (25+ countries)
- **Currency**: Automatic conversion via Stripe (135+ currencies supported)

## Installation

### Prerequisites
- WordPress 5.0+
- WooCommerce 5.0+
- PHP 7.4+
- Dokan Multi-vendor plugin (for vendor functionality)

### Step 1: Install Dependencies
```bash
cd wp-content/plugins/libookin-auto-payments
composer install
```

### Step 2: Activate Plugin
1. Upload the plugin folder to `/wp-content/plugins/`
2. Activate through WordPress admin panel
3. The plugin will automatically create required database tables

### Step 3: Configure Stripe
1. Go to **Libookin Payments > Settings** in WordPress admin
2. Add your Stripe API keys:
   - Secret Key (sk_live_... or sk_test_...)
   - Publishable Key (pk_live_... or pk_test_...)

### Step 4: Enable Stripe Connect
1. Log into your Stripe Dashboard
2. Navigate to **Connect > Settings**
3. Enable Connect for your account
4. Set platform name to "LiBookin"
5. Configure webhooks (see Webhook Configuration below)

## Configuration

### Stripe Connect Setup
```php
// Example account creation (handled automatically)
$account = \Stripe\Account::create([
    'type' => 'custom',
    'country' => 'FR', // Auto-detected
    'email' => $author_email,
    'capabilities' => [
        'transfers' => ['requested' => true],
    ],
]);
```

### Webhook Configuration
Add these webhook endpoints to your Stripe Dashboard:

**Endpoint URL**: `https://yourdomain.com/wp-json/libookin/v1/stripe-webhook`

**Events to listen for**:
- `payout.created`
- `payout.updated` 
- `payout.paid`
- `payout.failed`
- `account.updated`

### Cron Jobs
The plugin uses WordPress cron for automated payouts:
```php
// Daily check for eligible payouts
wp_schedule_event(time(), 'daily', 'libookin_daily_payout_check');
```

## Usage

### For Vendors/Authors

#### Dashboard Access
Navigate to **My Account > Dashboard > My Royalties** to view:
- Available balance (ready for payout)
- Pending royalties (awaiting 2-month period)
- Total earnings and books sold
- Sales performance charts
- Payout history

#### Payment Settings
Go to **My Account > Dashboard > Payment Settings** to:
- View Stripe Connect account status
- Complete verification requirements
- Understand payment schedule

### For Administrators

#### Payout Management
Access **Libookin Payments** in WordPress admin to:
- View upcoming payout preview
- Trigger manual payouts
- Monitor payout history
- Manage Stripe settings

#### Vendor Management
- View all vendor Stripe Connect accounts
- Monitor verification status
- Handle support requests

## API Reference

### Main Classes

#### `Libookin_Auto_Payments`
Main plugin class handling initialization and core functionality.

#### `Libookin_Stripe_Connect_Manager`
Manages all Stripe Connect operations:
```php
// Create vendor account
$result = $stripe_manager->create_connect_account($vendor_id, $country, $email);

// Get account balance
$balance = $stripe_manager->get_account_balance($account_id);

// Process payout
$payout = $stripe_manager->create_payout($account_id, $amount, $metadata);
```

#### `Libookin_Payout_Scheduler`
Handles automated payout scheduling:
```php
// Get eligible vendors
$eligible = $scheduler->get_eligible_vendors();

// Process payouts
$scheduler->process_scheduled_payouts();
```

### Database Schema

#### `wp_libookin_royalties` (Enhanced)
```sql
CREATE TABLE wp_libookin_royalties (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id BIGINT NOT NULL,
    product_id BIGINT NOT NULL,
    vendor_id BIGINT NOT NULL,
    price_ht DECIMAL(10,2) NOT NULL,
    royalty_percent DECIMAL(5,2) NOT NULL,
    royalty_amount DECIMAL(10,2) NOT NULL,
    payout_status ENUM('pending', 'processing', 'paid', 'failed') DEFAULT 'pending',
    stripe_payout_id VARCHAR(255) NULL,
    payout_date DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

#### `wp_libookin_payouts` (New)
```sql
CREATE TABLE wp_libookin_payouts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vendor_id BIGINT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    currency VARCHAR(3) DEFAULT 'EUR',
    stripe_payout_id VARCHAR(255) NOT NULL,
    stripe_account_id VARCHAR(255) NOT NULL,
    status ENUM('pending', 'in_transit', 'paid', 'failed', 'canceled') DEFAULT 'pending',
    failure_reason TEXT NULL,
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
```

## Workflow

### 1. Order Processing
```
Customer Purchase → WooCommerce Order Complete → Royalty Calculation → Database Storage
```

### 2. Payout Schedule
```
Daily Cron Check → Eligible Vendors (€15+, 2+ months) → Admin Notification → 6-Hour Delay → Automatic Payout
```

### 3. Vendor Onboarding
```
User Registration → Auto Stripe Connect Account → KYC Verification → Payout Enabled
```

## Security

### Data Protection
- All sensitive data encrypted in transit
- Stripe handles PCI compliance
- No credit card data stored locally
- Secure webhook verification

### Access Control
- Role-based permissions
- Nonce verification for AJAX requests
- Capability checks for admin functions
- Sanitized input/output

## Troubleshooting

### Common Issues

#### Stripe Connect Account Creation Fails
```php
// Check error logs
error_log('Stripe Connect Error: ' . $error->getMessage());

// Verify API keys are correct
$test_key = get_option('libookin_stripe_secret_key');
```

#### Payouts Not Processing
1. Check cron is running: `wp cron event list`
2. Verify minimum amounts: €15 threshold
3. Check account verification status
4. Review Stripe Dashboard for errors

#### Dashboard Not Loading
1. Ensure Dokan is active and updated
2. Check for JavaScript errors in browser console
3. Verify user roles and capabilities

### Debug Mode
Enable WordPress debug mode to see detailed error messages:
```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
```

## Support

### Documentation
- [Stripe Connect Documentation](https://stripe.com/docs/connect)
- [WooCommerce Developer Docs](https://woocommerce.com/developers/)
- [WordPress Plugin Development](https://developer.wordpress.org/plugins/)

### Contact
For technical support or feature requests:
- Email: support@libookin.com
- Documentation: [Internal Wiki]
- Issue Tracker: [Internal System]

## Changelog

### Version 1.0.0
- Initial release with full Stripe Connect integration
- Automated 2-month payout system
- Enhanced vendor dashboard
- Real-time balance synchronization
- Comprehensive admin interface
- Email notification system

## License

GPL v2 or later - see LICENSE file for details.

## Credits

Developed by Abu Hena for the Libookin platform.
Built with WordPress best practices and WPCS standards.
