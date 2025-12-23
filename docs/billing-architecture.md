# Billing System Architecture

## Overview

The Jexactyl billing system provides a unified, extensible architecture for processing server orders and renewals. The system supports multiple billing types (free, paid, coupons) while maintaining consistent validation and processing logic.

## Architecture Principles

1. **Separation of Concerns**: Each service has a single, well-defined responsibility
2. **Reusability**: Shared components eliminate code duplication
3. **Extensibility**: Easy to add new billing types or payment methods
4. **Backward Compatibility**: Existing integrations and data models remain unchanged

## Core Components

### Services

#### BillingValidationService
**Location**: `app/Services/Billing/BillingValidationService.php`

Centralizes all billing-related validation logic.

**Responsibilities**:
- Validate billing module is enabled
- Validate node deployment permissions (free vs paid)
- Validate egg selection against product category
- Calculate prices with coupon discounts
- Validate price types (free vs paid)
- Validate free product ownership limits

**Key Methods**:
- `validateBillingEnabled()`: Ensures billing module is active
- `validateNodeDeployment(int $nodeId, bool $isFreeProduct)`: Checks node deployment permissions
- `validateAndGetEggId(Product $product, ?int $requestedEggId)`: Validates egg selection
- `calculatePriceWithCoupon(Product $product, ?int $couponId)`: Applies coupon discounts
- `validatePriceType(float $finalPrice, bool $expectFree)`: Ensures price matches expected type
- `validateFreeProductOwnership(int $userId, Product $product)`: Prevents duplicate free products

#### OrderProcessorService
**Location**: `app/Services/Billing/OrderProcessorService.php`

Orchestrates the order processing workflow.

**Responsibilities**:
- Create server orders (new purchases)
- Process server renewals
- Record coupon usage
- Coordinate with CreateServerService and ServerRenewalService
- Update order status

**Key Methods**:
- `createServerOrder(...)`: Creates a new server and order
- `processRenewal(Server $server, Product $product, ?int $couponId)`: Renews a server
- `recordCouponUsage(int $couponId, int $userId, int $orderId)`: Tracks coupon usage

#### CreateServerService
**Location**: `app/Services/Billing/CreateServerService.php`

Handles server creation for billing orders.

**Responsibilities**:
- Create servers with product specifications
- Handle environment variable configuration
- Allocate resources (ports, etc.)
- Set renewal dates based on product type

**Key Methods**:
- `process(Request $request, Product $product, object $metadata, Order $order)`: Unified server creation
- `processFree(...)`: Backward-compatible wrapper for free server creation

#### ServerRenewalService
**Location**: `app/Services/Billing/ServerRenewalService.php`

Handles server renewal logic (already unified).

**Responsibilities**:
- Extend server renewal dates
- Unsuspend servers if needed
- Create renewal order records
- Handle both free and paid renewals

**Key Methods**:
- `renew(Server $server, Product $product, ?int $couponId)`: Renews a server

#### CreateOrderService
**Location**: `app/Services/Billing/CreateOrderService.php`

Creates order records with proper metadata.

**Responsibilities**:
- Generate order records
- Calculate order totals with coupons
- Set order status and type

**Key Methods**:
- `create(...)`: Creates an order record

### Controllers

#### CheckoutController
**Location**: `app/Http/Controllers/Api/Client/Billing/CheckoutController.php`

Unified controller that handles both free and paid product purchases and renewals.

**Endpoints**:
- `POST /api/client/billing/process/free`: Process free product purchase
- `POST /api/client/billing/renew/free`: Renew free server
- `GET /api/client/billing/products/{id}/key`: Get Stripe public key
- `POST /api/client/billing/products/{id}/intent`: Create payment intent
- `PUT /api/client/billing/products/{id}/intent`: Update payment intent
- `POST /api/client/billing/process`: Process payment and create server

**Free Product Flow**:
1. Validate billing is enabled (via BillingValidationService)
2. Calculate price with coupon
3. Validate price is free
4. Validate node and egg selection
5. Process order (via OrderProcessorService)
6. Return server details

**Paid Product Flow**:
1. Create Stripe payment intent with calculated price
2. Update intent with order metadata
3. Process payment
4. Create server or renew existing server
5. Capture payment
6. Record coupon usage

## Data Flow

### New Free Product Purchase
```
User Request
    ↓
CheckoutController::processFree()
    ↓
BillingValidationService::validateBillingEnabled()
    ↓
BillingValidationService::calculatePriceWithCoupon()
    ↓
BillingValidationService::validatePriceType()
    ↓
BillingValidationService::validateFreeProductOwnership()
    ↓
BillingValidationService::validateNodeDeployment()
    ↓
BillingValidationService::validateAndGetEggId()
    ↓
OrderProcessorService::createServerOrder()
    ├─ CreateOrderService::create()
    ├─ CreateServerService::processFree()
    │   └─ CreateServerService::process()
    └─ Record coupon usage
    ↓
Return server details
```

### New Paid Product Purchase
```
User Request
    ↓
CheckoutController::createIntent()
    ↓
BillingValidationService::calculatePriceWithCoupon()
    ↓
BillingValidationService::validatePriceType()
    ↓
Create Stripe PaymentIntent
    ↓
Return intent to client
    ↓
CheckoutController::updateIntent()
    ↓
BillingValidationService::validateBillingEnabled()
    ↓
BillingValidationService::validateNodeDeployment()
    ↓
BillingValidationService::validateAndGetEggId()
    ↓
CreateOrderService::create()
    ↓
Client confirms payment
    ↓
CheckoutController::processPaid()
    ├─ CreateServerService::process()
    ├─ Stripe capture payment
    └─ Record coupon usage
    ↓
Return success
```

### Server Renewal (Free or Paid)
```
User Request
    ↓
CheckoutController::renewFree() or processPaid()
    ↓
BillingValidationService::validateBillingEnabled()
    ↓
BillingValidationService::calculatePriceWithCoupon()
    ↓
BillingValidationService::validatePriceType()
    ↓
OrderProcessorService::processRenewal()
    ├─ ServerRenewalService::renew()
    │   ├─ Create renewal order
    │   ├─ Unsuspend if needed
    │   └─ Update renewal date
    └─ Record coupon usage
    ↓
Return server details
```

## Migration Guide

### From Old Free Product Flow

**Old Code**:
```php
// Scattered validation
if (!config('modules.billing.enabled')) { ... }
if (!Node::findOrFail($nodeId)->deployable_free) { ... }

// Manual coupon calculation
$finalPrice = $product->price;
if ($couponId) {
    $coupon = Coupon::find($couponId);
    if ($coupon) {
        $discount = $coupon->calculateDiscount($product->price);
        $finalPrice = max(0, $product->price - $discount);
    }
}

// Direct service calls
$order = $this->orderService->create(...);
$server = $this->serverCreation->processFree(...);
```

**New Code**:
```php
// Centralized validation
$this->validationService->validateBillingEnabled();
$this->validationService->validateNodeDeployment($nodeId, true);

// Unified coupon handling
$priceInfo = $this->validationService->calculatePriceWithCoupon($product, $couponId);

// Coordinated processing
$result = $this->processorService->createServerOrder(...);
```

## Benefits of the Refactor

1. **Code Reusability**: ~60% reduction in duplicated validation logic
2. **Maintainability**: Single source of truth for each operation
3. **Testability**: Isolated services are easier to test
4. **Extensibility**: New billing types can be added without modifying existing code
5. **Consistency**: All billing flows use the same validation and processing
6. **Readability**: Clear separation of responsibilities
7. **Type Safety**: Proper typing enables better IDE support and error detection
