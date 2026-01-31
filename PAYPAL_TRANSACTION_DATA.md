# PayPal Transaction Data Storage

## Overview

This document explains the comprehensive PayPal transaction data that Jexactyl now stores for each PayPal payment, why each field is important, and how to use this data.

## The Problem

Initially, we only stored `paypal_order_id` for PayPal transactions. This was insufficient for:
- Processing refunds (need capture ID)
- Customer support inquiries
- Financial reconciliation
- Dispute handling
- Audit compliance
- Transaction history

## The Solution

We now store **7 additional fields** that capture complete PayPal transaction details.

## Fields Stored

### 1. `paypal_order_id` (Original)
- **Type:** String
- **Purpose:** PayPal order identifier
- **When Set:** During order creation
- **Example:** `"5AB12345CD678901E"`
- **Use:** Initial order tracking, correlation with PayPal dashboard

### 2. `paypal_capture_id` (NEW - Critical for Refunds)
- **Type:** String, Indexed
- **Purpose:** PayPal capture/transaction identifier
- **When Set:** After payment is captured
- **Example:** `"9XY98765ZW432109X"`
- **Use:** **Required for issuing refunds via PayPal API**
- **Why Critical:** Without this, you cannot programmatically refund a payment

### 3. `paypal_payer_id` (NEW)
- **Type:** String
- **Purpose:** PayPal account ID of the customer
- **When Set:** After payment is captured
- **Example:** `"ABCDEFGH12345"`
- **Use:** 
  - Identify repeat customers
  - Cross-reference with PayPal account
  - Fraud detection

### 4. `paypal_payer_email` (NEW)
- **Type:** String
- **Purpose:** Email address used for PayPal payment
- **When Set:** After payment is captured
- **Example:** `"customer@example.com"`
- **Use:**
  - Customer support inquiries
  - Verify payment source
  - Contact customer about transaction
  - Compare with user's registered email

### 5. `paypal_status` (NEW)
- **Type:** String
- **Purpose:** Current PayPal transaction status
- **When Set:** After payment is captured, updated if status changes
- **Possible Values:**
  - `COMPLETED` - Payment successfully captured
  - `REFUNDED` - Payment has been refunded
  - `PARTIALLY_REFUNDED` - Partial refund issued
  - `PENDING` - Payment pending
  - `DENIED` - Payment denied
  - `FAILED` - Payment failed
- **Example:** `"COMPLETED"`
- **Use:**
  - Track refund status
  - Reconciliation
  - Dispute management

### 6. `paypal_amount` (NEW)
- **Type:** Decimal(10,2)
- **Purpose:** Actual amount PayPal charged
- **When Set:** After payment is captured
- **Example:** `29.99`
- **Use:**
  - **Verify against order total** - Ensure PayPal charged correct amount
  - Currency conversion verification
  - Financial reconciliation
  - Detect discrepancies

### 7. `paypal_currency` (NEW)
- **Type:** String (3 characters)
- **Purpose:** Currency code of the payment
- **When Set:** After payment is captured
- **Example:** `"USD"`, `"EUR"`, `"GBP"`
- **Use:**
  - Multi-currency support
  - Reconciliation
  - Financial reporting

### 8. `paypal_captured_at` (NEW)
- **Type:** Timestamp
- **Purpose:** When PayPal actually captured the payment
- **When Set:** After payment is captured
- **Example:** `"2026-01-31 02:15:30"`
- **Use:**
  - Audit trail
  - Timing analysis (how long from order creation to capture?)
  - Compliance reporting
  - Detect delays

## Database Schema

```sql
ALTER TABLE orders ADD COLUMN paypal_capture_id VARCHAR(255) NULL AFTER paypal_order_id;
ALTER TABLE orders ADD COLUMN paypal_payer_id VARCHAR(255) NULL AFTER paypal_capture_id;
ALTER TABLE orders ADD COLUMN paypal_payer_email VARCHAR(255) NULL AFTER paypal_payer_id;
ALTER TABLE orders ADD COLUMN paypal_status VARCHAR(255) NULL AFTER paypal_payer_email;
ALTER TABLE orders ADD COLUMN paypal_amount DECIMAL(10,2) NULL AFTER paypal_status;
ALTER TABLE orders ADD COLUMN paypal_currency VARCHAR(3) NULL AFTER paypal_amount;
ALTER TABLE orders ADD COLUMN paypal_captured_at TIMESTAMP NULL AFTER paypal_currency;
ALTER TABLE orders ADD INDEX idx_paypal_capture_id (paypal_capture_id);
```

## Usage Examples

### Viewing Transaction Details

```php
$order = Order::find(123);

echo "PayPal Transaction Details:\n";
echo "Order ID: " . $order->paypal_order_id . "\n";
echo "Capture ID: " . $order->paypal_capture_id . "\n";
echo "Payer: " . $order->paypal_payer_email . "\n";
echo "Amount: " . $order->paypal_amount . " " . $order->paypal_currency . "\n";
echo "Status: " . $order->paypal_status . "\n";
echo "Captured: " . $order->paypal_captured_at->format('Y-m-d H:i:s') . "\n";
```

### Issuing a Refund

```php
$order = Order::find(123);

if (!$order->paypal_capture_id) {
    throw new Exception('Cannot refund: No capture ID available');
}

// Use PayPal API to refund
$paypalService = new PayPalPaymentService();
$refund = $paypalService->refundCapture(
    $order->paypal_capture_id,
    $order->paypal_amount,
    $order->paypal_currency,
    'Refund reason here'
);

// Update order status
$order->paypal_status = 'REFUNDED';
$order->save();
```

### Reconciliation Report

```php
// Find discrepancies between order total and PayPal amount
$discrepancies = Order::where('payment_processor', 'paypal')
    ->whereNotNull('paypal_amount')
    ->whereRaw('ABS(total - paypal_amount) > 0.01')
    ->get();

foreach ($discrepancies as $order) {
    echo "Order #{$order->id}: Expected {$order->total}, PayPal charged {$order->paypal_amount}\n";
}
```

### Customer Support Query

```php
// Find all payments from a specific email
$customerEmail = 'customer@example.com';
$payments = Order::where('paypal_payer_email', $customerEmail)
    ->orderBy('created_at', 'desc')
    ->get();

echo "Customer $customerEmail has {$payments->count()} PayPal payments\n";
foreach ($payments as $payment) {
    echo "#{$payment->id}: {$payment->paypal_amount} {$payment->paypal_currency} on {$payment->paypal_captured_at}\n";
}
```

### Timing Analysis

```php
// How long does it take from order creation to PayPal capture?
$orders = Order::where('payment_processor', 'paypal')
    ->whereNotNull('paypal_captured_at')
    ->get();

foreach ($orders as $order) {
    $delay = $order->created_at->diffInSeconds($order->paypal_captured_at);
    if ($delay > 300) { // More than 5 minutes
        echo "Order #{$order->id} took {$delay} seconds to capture (slow!)\n";
    }
}
```

## What Data Gets Saved

The data is extracted from PayPal's capture API response:

```json
{
  "id": "5AB12345CD678901E",
  "status": "COMPLETED",
  "purchase_units": [{
    "payments": {
      "captures": [{
        "id": "9XY98765ZW432109X",
        "status": "COMPLETED",
        "amount": {
          "value": "29.99",
          "currency_code": "USD"
        },
        "create_time": "2026-01-31T02:15:30Z"
      }]
    }
  }],
  "payer": {
    "payer_id": "ABCDEFGH12345",
    "email_address": "customer@example.com"
  }
}
```

This gets saved as:
- `paypal_order_id` = `"5AB12345CD678901E"`
- `paypal_capture_id` = `"9XY98765ZW432109X"`
- `paypal_status` = `"COMPLETED"`
- `paypal_amount` = `29.99`
- `paypal_currency` = `"USD"`
- `paypal_captured_at` = `2026-01-31 02:15:30`
- `paypal_payer_id` = `"ABCDEFGH12345"`
- `paypal_payer_email` = `"customer@example.com"`

## Migration

Run the migration to add these fields:

```bash
php artisan migrate
```

The migration file is: `2026_01_31_020000_add_paypal_transaction_details_to_orders_table.php`

## Backward Compatibility

- All new fields are nullable
- Existing orders remain unchanged
- Only new PayPal payments will have these fields populated
- The system works with or without these fields (graceful degradation)

## Benefits

### For Administrators
- Complete transaction history
- Easy refund processing
- Reconciliation reports
- Audit compliance
- Customer support efficiency

### For Developers
- Programmatic refund API
- Data for analytics
- Multi-currency support
- Webhook status tracking
- Integration testing data

### For Customers
- Transparent transaction details
- Easier dispute resolution
- Payment verification
- Refund tracking

## Future Features Enabled

With this comprehensive data, we can now build:

1. **Refund Management UI**
   - Admin can refund directly from dashboard
   - Uses `paypal_capture_id` to process refunds

2. **Transaction Reports**
   - Revenue by currency
   - Payment timing analysis
   - Customer payment history

3. **Reconciliation Tools**
   - Automated discrepancy detection
   - PayPal vs database comparison
   - Currency conversion verification

4. **Customer Portal**
   - Users see their PayPal payment details
   - Transaction history with amounts and dates

5. **Webhook Status Updates**
   - Update `paypal_status` when PayPal sends notifications
   - Track refunds, chargebacks automatically

6. **Analytics Dashboard**
   - Average time to capture
   - Popular payment currencies
   - Repeat customer tracking (via payer_id)

## Comparison with Other Processors

### Stripe
- Stores: `payment_intent_id`
- Similar level of detail through Stripe's API

### Mollie
- Stores: `mollie_payment_id`, `payment_token`
- Can fetch additional details via Mollie API

### PayPal (Now)
- Stores: Full transaction details in database
- No need for additional API calls to get basic info
- Self-contained transaction record

## Important Notes

1. **Capture ID is Critical**
   - Without `paypal_capture_id`, refunds cannot be issued
   - Always verify this field is populated

2. **Email Verification**
   - Compare `paypal_payer_email` with user's email
   - Detect potential fraud or account mismatches

3. **Amount Reconciliation**
   - Always compare `total` vs `paypal_amount`
   - Flag any discrepancies for review

4. **Currency Handling**
   - PayPal can charge in different currencies
   - Use `paypal_currency` to determine actual currency charged

5. **Timing Analysis**
   - Large gaps between `created_at` and `paypal_captured_at` may indicate issues
   - Normal delay is seconds to minutes

## Conclusion

By storing comprehensive PayPal transaction data, Jexactyl now has:
- Complete audit trail
- Refund capability
- Reconciliation data
- Customer support information
- Analytics potential

This matches or exceeds the data stored for other payment processors and enables professional-grade payment management.
