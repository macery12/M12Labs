# Billing Integration System Architecture

## Overview

The billing system has been refactored to support multiple payment integrations simultaneously with a modular, plugin-like architecture. This allows administrators to enable and configure multiple payment providers (Stripe, Mollie, future integrations) independently.

## Key Features

### 1. Modular Integration System

Each payment integration is self-contained with:
- **Registry Entry**: Metadata defining the integration (name, icon, description)
- **Settings Component**: Dedicated UI for integration-specific configuration
- **Enable/Disable Toggle**: Independent activation control

### 2. Dynamic Tab Rendering

The billing admin interface dynamically renders tabs based on enabled integrations:
- Base tabs: Overview, Products, Orders, Donations, Coupons, Exceptions, Renewal Dates
- **Integration tabs**: Automatically added when an integration is enabled (e.g., "Stripe", "Mollie")
- **Integrations tab**: Central management page for all available integrations
- Settings tab: Global billing settings (currency, legal links, cooldown periods)

### 3. Clean Separation of Concerns

**Global Settings** (`/admin/billing/settings`):
- Currency configuration
- Import/Export billing configuration
- Legal document links
- Plan change cooldown
- Billing module enable/disable

**Integration-Specific Settings** (e.g., `/admin/billing/integrations/stripe`):
- API keys and credentials
- Integration-specific features (e.g., PayPal via Stripe, Link)
- Setup guides and documentation

**Integrations Management** (`/admin/billing/integrations`):
- View all available integrations
- Enable/disable integrations
- Check configuration status
- Quick access to integration settings

## File Structure

```
resources/scripts/components/admin/modules/billing/
├── BillingRouter.tsx                    # Main routing with dynamic integration tabs
├── SettingsContainer.tsx                # Global billing settings (simplified)
├── integrations/
│   ├── types.ts                         # TypeScript interfaces
│   ├── registry.ts                      # Integration registry and utilities
│   ├── IntegrationsContainer.tsx        # Integration management UI
│   ├── StripeSettings.tsx              # Stripe-specific configuration
│   ├── MollieSettings.tsx              # Mollie-specific configuration
│   └── [future integrations...]        # Scalable for new providers
```

## Adding a New Integration

To add a new payment integration:

1. **Create the Settings Component** (`NewIntegrationSettings.tsx`):
```typescript
export default () => {
    const settings = useStoreState(s => s.everest.data!.billing);
    // Integration-specific UI
    return <div>...</div>;
};
```

2. **Register the Integration** in `registry.ts`:
```typescript
{
    id: 'new-integration',
    name: 'New Integration',
    description: 'Description of what this integration provides',
    icon: faIcon,
    enabled: billingSettings.integrations?.newIntegration?.enabled ?? false,
    configured: checkIfConfigured(),
    settingsComponent: NewIntegrationSettings,
}
```

3. **Add Backend Support** (if needed):
- Database settings for credentials
- API endpoints for configuration
- Payment processing logic

## Migration Guide

### For Existing Installations

The refactored system is backward compatible:

1. **Existing `processor` setting** is respected:
   - If `processor === 'stripe'`, Stripe is automatically marked as enabled
   - If `processor === 'mollie'`, Mollie is automatically marked as enabled

2. **Existing API keys** are preserved:
   - Stripe keys continue to work
   - Mollie API key continues to work

3. **New `integrations` state** allows multiple providers:
   - Admins can enable both Stripe AND Mollie simultaneously
   - Each integration tracks its own enabled state

### Recommended Migration Steps

1. Review current payment processor in Settings
2. Visit the new "Integrations" tab
3. Enable desired integrations (can be multiple)
4. Configure each integration in its dedicated tab
5. Test checkout flows with each enabled integration

## State Management

### Before Refactor
```typescript
billing: {
    processor: 'stripe' | 'mollie',  // Single processor only
    keys: { ... },                    // Stripe keys
    mollie: { ... },                  // Mollie config
}
```

### After Refactor
```typescript
billing: {
    processor: 'stripe' | 'mollie',   // Legacy support
    keys: { ... },
    mollie: { ... },
    integrations: {                    // NEW: Multi-integration support
        stripe: { enabled: boolean },
        mollie: { enabled: boolean },
        // Future integrations...
    }
}
```

## Benefits

1. **Scalability**: Easy to add new payment providers without modifying core billing logic
2. **Clean UI**: Integration settings are isolated from global settings
3. **Flexibility**: Multiple integrations can coexist and be enabled simultaneously
4. **Maintainability**: Each integration is self-contained
5. **User Experience**: Clear separation makes it easier for admins to configure billing

## Future Enhancements

Potential improvements:
- Add standalone PayPal integration (not via Stripe)
- Add cryptocurrency payment integrations
- Add regional payment providers (e.g., Razorpay for India)
- Integration-specific analytics and reporting
- Per-integration transaction fees and routing rules
