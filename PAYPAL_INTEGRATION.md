# PayPal Standalone Integration

This document describes the standalone PayPal integration implementation for the Jexactyl billing system.

## Overview

The PayPal standalone integration provides native PayPal checkout without requiring Stripe. It runs alongside existing Stripe and Mollie integrations, allowing users to select their preferred payment method during checkout.

## Architecture

### Backend Components

#### Configuration (`config/modules/billing.php`)
- Database-backed credential storage (not environment variables)
- Supports sandbox and live modes
- Client ID and Client Secret stored securely

#### Payment Service (`app/Services/Billing/PayPalPaymentService.php`)
- Uses PayPal REST API v2
- OAuth2 token management with automatic refresh
- Order creation, capture, and status checking
- Secure error handling that doesn't expose API details

#### Checkout Controller (`app/Http/Controllers/Api/Client/Billing/PayPalCheckoutController.php`)
- Create and update PayPal orders
- Capture payments after customer approval
- Transaction-safe order fulfillment with row locking
- Idempotent processing to prevent duplicate charges

#### Database Schema
- `paypal_order_id` column in `orders` table
- Links internal orders to PayPal order IDs

### Frontend Components

#### Admin Panel
- **PayPalSettings.tsx**: Configuration interface showing status
- **SetupPayPalKeys.tsx**: Credential setup modal with sandbox/live mode
- **Integration Registry**: Registers PayPal as available payment method

#### Customer Checkout
- **PayPalPaymentButton.tsx**: Payment button component
- **PaymentMethodSelector.tsx**: Shows PayPal as payment option
- **Processing.tsx**: Handles return from PayPal and captures payment

## Payment Flow

```
1. Customer selects product → Checkout page loads
2. Customer chooses PayPal as payment method
3. Click "Pay with PayPal" button
   ├─ Frontend: createPayPalOrder() → Backend creates order
   ├─ Backend: Calls PayPal API to create order
   ├─ PayPal returns approval URL
   └─ Frontend: Redirect to PayPal approval page
4. Customer approves payment on PayPal
5. PayPal redirects to processing page with token
6. Processing page:
   ├─ Looks up order by token
   ├─ Calls capturePayPalOrder()
   ├─ Backend captures payment on PayPal
   ├─ Fulfills order (creates server or renews)
   └─ Redirects to success page
```

## Security Features

### Backend
1. **OAuth Token Management**: Tokens refresh automatically before expiration
2. **Input Validation**: Order IDs validated against expected format
3. **Error Sanitization**: API errors logged but not exposed to users
4. **Transaction Safety**: Database transactions with row locking prevent race conditions
5. **Idempotent Processing**: Duplicate capture requests safely ignored

### Frontend
1. **Client Secret Protection**: Only boolean indicator sent to frontend
2. **Processor Identification**: `processor` parameter prevents token collision
3. **Polling Timeout**: 2-minute maximum prevents infinite loops
4. **Flash Message Consistency**: Error messages use consistent keys

## Configuration

### Admin Setup

1. Navigate to `/admin/billing/integrations`
2. Click on PayPal tab
3. Click "Add PayPal Credentials"
4. Enter:
   - **Client ID**: From PayPal Developer Dashboard
   - **Client Secret**: From PayPal Developer Dashboard
   - **Mode**: Sandbox (testing) or Live (production)
5. Click "Save PayPal Credentials"

### Getting PayPal Credentials

1. Go to [PayPal Developer Dashboard](https://developer.paypal.com/dashboard/applications/live)
2. Create a new app or use existing app
3. Copy **Client ID** and **Client Secret**
4. For sandbox testing, use sandbox credentials
5. For production, switch to live mode and use live credentials

### Enabling PayPal

Once credentials are configured, enable PayPal:
1. The integration automatically becomes available
2. Users will see PayPal as a payment option during checkout
3. Can be disabled by removing credentials or via integration settings

## API Endpoints

### Client Routes (Authenticated)

```
POST   /api/client/billing/products/{id}/paypal/order
  - Create PayPal order for product
  - Returns: { id, token, approval_url }

PUT    /api/client/billing/products/{id}/paypal/order
  - Update order with server configuration
  - Body: { order_id, node_id, egg_id, name, variables }

POST   /api/client/billing/paypal/capture
  - Capture approved PayPal order
  - Body: { order_id }
  - Returns: { success, message, order_id }

GET    /api/client/billing/paypal/status
  - Check order status
  - Query: ?order_id={id}
  - Returns: { processed, failed, pending, order_status }

GET    /api/client/billing/paypal/token/{token}
  - Get order from processing token
  - Returns: { order_id, status, product_id }
```

## Database

### Migration
```php
// 2026_01_30_183000_add_paypal_order_id_to_orders_table.php
Schema::table('orders', function (Blueprint $table) {
    $table->string('paypal_order_id')->nullable()->after('mollie_payment_id');
});
```

### Order Model Fields
- `paypal_order_id`: PayPal's order identifier
- `payment_processor`: Set to 'paypal'
- `payment_token`: Secure UUID for order tracking

## Testing

### Sandbox Testing

1. Set mode to "Sandbox" in admin panel
2. Use sandbox credentials from PayPal Developer Dashboard
3. Use [PayPal sandbox accounts](https://developer.paypal.com/dashboard/accounts) for testing
4. Test complete flow: create order → approve → capture

### Production Testing

1. Switch mode to "Live" in admin panel
2. Update to live credentials
3. Test with small real transaction
4. Verify order fulfillment works correctly

## Troubleshooting

### Common Issues

**Error: "PayPal is not configured"**
- Solution: Add PayPal Client ID and Client Secret in admin panel

**Error: "Failed to authenticate with PayPal"**
- Solution: Verify credentials are correct for selected mode (sandbox/live)

**Order stuck in pending**
- Possible causes:
  - Customer didn't approve payment
  - Capture failed silently
  - Server error during fulfillment
- Solution: Check order logs, verify PayPal order status in dashboard

**Infinite polling on processing page**
- Should auto-timeout after 2 minutes
- If not, check browser console for errors
- Verify order status via admin panel

### Logging

All PayPal operations are logged:
```php
\Log::info("Fulfilling PayPal order {$order->id}");
\Log::error("PayPal order creation failed", ['response' => ...]);
```

Check logs at: `storage/logs/laravel.log`

## Future Enhancements

### Recommended Additions

1. **Webhook Support**
   - Currently uses synchronous capture on return
   - Adding webhooks would improve reliability
   - Would catch payment events even if user doesn't return

2. **Abandoned Order Cleanup**
   - Orders created but not completed
   - Implement cron job to mark as cancelled after timeout

3. **Refund Support**
   - Add admin interface for refunds
   - Integrate with PayPal refund API

4. **Subscription Support**
   - PayPal supports recurring billing
   - Could integrate with renewal system

## Comparison with Other Processors

| Feature | Stripe | Mollie | PayPal Standalone |
|---------|--------|--------|-------------------|
| Setup Complexity | Medium | Low | Low |
| Redirect Required | No | Yes | Yes |
| Webhook Support | Yes | Yes | No (future) |
| Transaction Fees | ~2.9% | ~2.9% | ~2.9% |
| Global Coverage | High | Europe | High |
| Customer UX | Embedded | Redirect | Redirect |

## Code Review Findings Addressed

All critical and high-priority issues from code review have been fixed:

✅ OAuth token expiration handling  
✅ Order ID format validation  
✅ Error message sanitization  
✅ Transaction safety with row locking  
✅ Token parameter collision fix  
✅ Client secret exposure prevention  
✅ Polling timeout implementation  
✅ Flash message key consistency  

## Maintainer Notes

- PayPal API v2 documentation: https://developer.paypal.com/api/rest/
- Credentials stored via Settings model in database
- Integration follows same patterns as Mollie for consistency
- All payment operations are logged for debugging
- Error handling prioritizes user experience over technical details
