# Billing Exception Tests

This directory contains comprehensive unit tests for the billing exception handling system.

## Test Files

### 1. BillingExceptionTest.php
**Location:** `tests/Unit/Exceptions/Billing/BillingExceptionTest.php`

Tests the custom `BillingException` class functionality:
- ✅ Exception creation with minimal parameters
- ✅ Exception creation with full parameters (order ID, processor, external ID, context)
- ✅ All exception types (payment, deployment, storefront, webhook, refund, validation)
- ✅ All payment processors (stripe, paypal, mollie)
- ✅ Exception getters return correct values

**Test Count:** 6 tests with 2 data providers

### 2. PayPalPaymentServiceTest.php
**Location:** `tests/Unit/Services/Billing/PayPalPaymentServiceTest.php`

Tests PayPal payment service exception handling:
- ✅ Missing credentials throw BillingException
- ✅ Authentication failures throw BillingException with proper type
- ✅ Order creation failures include context (product_id, amount)
- ✅ Successful operations return correct data
- ✅ Invalid order ID format throws validation exception
- ✅ Order retrieval failures throw BillingException
- ✅ Payment capture failures throw BillingException

**Test Count:** 7 tests

### 3. MolliePaymentServiceTest.php
**Location:** `tests/Unit/Services/Billing/MolliePaymentServiceTest.php`

Tests Mollie payment service exception handling:
- ✅ Missing API key throws BillingException
- ✅ Payment creation failures throw BillingException with context
- ✅ Payment retrieval failures include external ID
- ✅ Configuration errors have correct exception type (STOREFRONT)

**Test Count:** 3 tests

### 4. CreateServerServiceTest.php
**Location:** `tests/Unit/Services/Billing/CreateServerServiceTest.php`

Tests server creation service exception handling:
- ✅ No allocation available throws BillingException with DEPLOYMENT type
- ✅ Server creation failures include context (product_id, node_id, egg_id)
- ✅ Unexpected exceptions are wrapped in BillingException
- ✅ Order ID is properly attached to exceptions

**Test Count:** 3 tests

### 5. BillingExceptionModelTest.php
**Location:** `tests/Unit/Models/Billing/BillingExceptionModelTest.php`

Tests the BillingException model:
- ✅ All exception type constants are defined
- ✅ New exception types (webhook, refund, validation) are in validation rules
- ✅ order_id is nullable in validation
- ✅ Required fields (uuid, title, description) are enforced
- ✅ Fillable attributes are correct
- ✅ order_id is cast to integer

**Test Count:** 8 tests with 1 data provider

## Total Test Coverage

**Total Test Files:** 5  
**Total Tests:** 27 tests  
**Total Assertions:** ~70+ assertions

## Running the Tests

### Run all billing exception tests:
```bash
php artisan test --filter=BillingException
```

### Run specific test file:
```bash
php artisan test tests/Unit/Exceptions/Billing/BillingExceptionTest.php
php artisan test tests/Unit/Services/Billing/PayPalPaymentServiceTest.php
php artisan test tests/Unit/Services/Billing/MolliePaymentServiceTest.php
php artisan test tests/Unit/Services/Billing/CreateServerServiceTest.php
php artisan test tests/Unit/Models/Billing/BillingExceptionModelTest.php
```

### Run with PHPUnit directly:
```bash
vendor/bin/phpunit tests/Unit/Exceptions/Billing/BillingExceptionTest.php
vendor/bin/phpunit tests/Unit/Services/Billing/
vendor/bin/phpunit tests/Unit/Models/Billing/
```

## What These Tests Validate

### Exception Creation & Properties
- Exception objects are created correctly
- All getters return expected values
- Context data is properly stored
- Payment processor information is captured
- External transaction IDs are tracked

### Exception Types
- Payment exceptions for payment processing failures
- Deployment exceptions for server creation failures
- Storefront exceptions for configuration issues
- Webhook exceptions for webhook processing errors
- Refund exceptions for refund operations
- Validation exceptions for input validation errors

### Payment Processor Coverage
- Stripe: Configuration, intent creation, order processing
- PayPal: Authentication, order creation/capture, webhooks
- Mollie: Configuration, payment creation/retrieval

### Server Deployment
- Allocation availability checking
- Server creation failure handling
- Context inclusion in exceptions

### Model Validation
- All new exception types included in validation rules
- Nullable order_id support
- Proper data casting

## Test Quality Features

### Mocking
- Uses Mockery for clean, testable code
- Mocks external dependencies (HTTP, database)
- Isolates units under test

### Data Providers
- Parametrized tests for exception types
- Parametrized tests for payment processors
- Reduces code duplication

### Assertions
- Validates exception messages
- Checks exception types and properties
- Verifies context data inclusion
- Ensures proper error categorization

## Continuous Integration

These tests are designed to run in CI/CD pipelines:
- No external dependencies required (uses mocks)
- Fast execution (unit tests only)
- Clear failure messages
- PHPUnit compatible

## Future Test Additions

Potential areas for expansion:
- Integration tests with real database
- End-to-end tests for complete payment flows
- Performance tests for exception logging
- Webhook signature verification tests
- Retry mechanism tests
