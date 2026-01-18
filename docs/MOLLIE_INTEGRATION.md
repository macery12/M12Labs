# Mollie Payment Integration

This document explains how to configure and use Mollie as a payment processor in Jexactyl.

## Overview

Jexactyl now supports **two payment processors**:
- **Stripe** (default)
- **Mollie** (new)

Users can choose which processor to use from the admin billing settings panel.

## Features

- ✅ Full payment processing for product purchases
- ✅ Server renewals
- ✅ Coupon support
- ✅ Free and paid products
- ✅ Webhook support for payment confirmation
- ✅ Multi-currency support (using Mollie's supported currencies)
- ✅ Admin configuration UI
- ✅ Seamless switching between Stripe and Mollie

## Setup Instructions

### 1. Install Dependencies

The Mollie PHP library is already included in `composer.json`. Run:

```bash
composer install
```

### 2. Get Mollie API Key

1. Create an account at [mollie.com](https://www.mollie.com)
2. Log in to your Mollie Dashboard
3. Navigate to **Developers → API keys**
4. Copy your **Live API key** (starts with `live_`) for production
5. Or use **Test API key** (starts with `test_`) for testing

### 3. Run Database Migration

Run the database migration to add Mollie support:

```bash
php artisan migrate
```

This adds the following fields to the `billing_orders` table:
- `payment_processor` - Stores which processor was used ('stripe' or 'mollie')
- `mollie_payment_id` - Stores the Mollie payment ID

### 4. Configure in Admin Panel

1. Log in to your Jexactyl admin panel
2. Navigate to **Billing → Settings**
3. Select **Payment Processor** and choose **Mollie**
4. Click **Configure Mollie API Key** button
5. Enter your Mollie API key
6. Save

### 5. Configure Webhook (Important!)

For Mollie to notify your application about payment status changes, you need to configure the webhook URL:

1. In your Mollie Dashboard, go to **Developers → Webhooks**
2. Add the following webhook URL:
   ```
   https://your-domain.com/api/client/billing/mollie/webhook
   ```
3. Save the webhook

**Note:** The webhook is essential for processing payments correctly. Without it, payments may not be confirmed automatically.

## Configuration Options

### Environment Variables

You can also configure Mollie using environment variables in `.env`:

```env
# Payment processor selection
BILLING_PROCESSOR=mollie

# Mollie API key
MOLLIE_API_KEY=live_xxxxxxxxxxxxx
```

### Config File

Configuration is stored in `config/modules/billing.php`:

```php
'processor' => env('BILLING_PROCESSOR', 'stripe'),

'mollie' => [
    'api_key' => env('MOLLIE_API_KEY', ''),
],
```

## Switching Between Processors

You can switch between Stripe and Mollie at any time:

1. Go to **Billing → Settings** in the admin panel
2. Select **Payment Processor**
3. Choose **Stripe** or **Mollie**
4. Click Save

**Important Notes:**
- Existing orders retain their original payment processor
- New orders will use the currently selected processor
- Ensure you have configured API keys for the processor you want to use

## Payment Flow

### For New Product Purchases

1. User selects a product and configures options
2. User clicks "Pay with Mollie" (when Mollie is selected)
3. User is redirected to Mollie's secure checkout page
4. User completes payment
5. Mollie sends webhook notification to Jexactyl
6. Server is automatically deployed
7. User is redirected back to Jexactyl

### For Server Renewals

1. User clicks "Renew Server"
2. User is redirected to Mollie checkout
3. After payment, webhook processes the renewal
4. Server renewal date is extended

## Supported Payment Methods

Mollie supports many payment methods including:
- Credit/Debit Cards (Visa, Mastercard, American Express)
- PayPal
- Apple Pay
- Google Pay
- iDEAL (Netherlands)
- Bancontact (Belgium)
- SEPA Direct Debit
- And many more regional methods

The available methods depend on your Mollie account settings and the customer's location.

## Troubleshooting

### Payments Not Processing

1. **Check webhook configuration**: Ensure the webhook URL is correctly set in Mollie Dashboard
2. **Verify API key**: Make sure you're using the correct API key (test vs live)
3. **Check logs**: Look at `storage/logs/laravel.log` for error messages
4. **Check billing exceptions**: Go to Admin → Billing → Exceptions for any errors

### Webhook Issues

If the webhook is not receiving notifications:

1. Verify the webhook URL is publicly accessible
2. Check that your server's firewall allows Mollie's IP addresses
3. Test the webhook using Mollie's testing tools in the Dashboard
4. Ensure SSL/HTTPS is properly configured

### Currency Issues

Mollie supports multiple currencies, but:
- Make sure the currency in your billing settings is supported by Mollie
- The currency code must match Mollie's format (e.g., "EUR", "USD", "GBP")
- Check Mollie's [supported currencies](https://docs.mollie.com/payments/multicurrency) documentation

## API Endpoints

### Client API Routes

- `POST /api/client/billing/products/{id}/mollie/payment` - Create Mollie payment
- `PUT /api/client/billing/products/{id}/mollie/payment` - Update payment with order details
- `POST /api/client/billing/mollie/webhook` - Webhook endpoint (called by Mollie)

## Development & Testing

### Test Mode

Use Mollie's test API key (starts with `test_`) to test the integration:

1. Set `MOLLIE_API_KEY=test_xxxxx` in your `.env`
2. Use test payment methods provided by Mollie
3. Monitor the Mollie Dashboard for test transactions

### Test Payment Methods

Mollie provides test payment methods that always succeed or fail. See [Mollie Testing Guide](https://docs.mollie.com/overview/testing) for details.

## Security Considerations

1. **API Keys**: Store API keys securely using environment variables
2. **Webhook Verification**: The webhook handler validates payment status through Mollie's API
3. **HTTPS**: Always use HTTPS in production to protect payment data
4. **PCI Compliance**: Mollie handles all payment data, so you don't need PCI compliance

## Support

For issues specific to:
- **Jexactyl Integration**: Open an issue on the Jexactyl GitHub repository
- **Mollie API**: Contact [Mollie Support](https://www.mollie.com/contact)
- **Payment Processing**: Check Mollie Dashboard for transaction details

## Additional Resources

- [Mollie API Documentation](https://docs.mollie.com/)
- [Mollie PHP Library](https://github.com/mollie/mollie-api-php)
- [Mollie Dashboard](https://www.mollie.com/dashboard)
- [Supported Payment Methods](https://www.mollie.com/payments)
- [Mollie Pricing](https://www.mollie.com/pricing)
