# Billing Integration System - Quick Start

## What Changed?

The billing admin UI has been refactored to support **multiple payment integrations simultaneously** with a modular, plugin-like architecture.

### Before → After

**Before**: Cluttered settings, one processor only  
**After**: Clean UI, multiple processors, modular design

## For Administrators

### Accessing the New System

1. Navigate to `/admin/billing`
2. Look for the new **"Integrations"** tab
3. Enable desired payment providers
4. Configure each in its dedicated tab

### Navigation Changes

New tabs will appear based on your enabled integrations:
- **Stripe tab** - appears when Stripe is enabled
- **Mollie tab** - appears when Mollie is enabled
- **Integrations tab** - always visible, manage all integrations
- **Settings tab** - simplified, only global settings

### Quick Actions

**Enable an integration:**
1. Go to Integrations tab
2. Click "Enable" on desired provider
3. Visit provider's tab to configure

**Configure Stripe:**
1. Go to Stripe tab (if enabled)
2. Add API keys
3. Optionally enable PayPal or Link

**Configure Mollie:**
1. Go to Mollie tab (if enabled)
2. Add API key

## For Developers

### Adding a New Integration

**Step 1**: Create settings component
```typescript
// integrations/NewProviderSettings.tsx
export default () => {
    const settings = useStoreState(s => s.everest.data!.billing);
    // Your configuration UI
    return <div>...</div>;
};
```

**Step 2**: Register in `registry.ts`
```typescript
{
    id: 'newprovider',
    name: 'New Provider',
    description: 'Description...',
    icon: faIcon,
    enabled: billingSettings.integrations?.newprovider?.enabled ?? false,
    configured: checkIfConfigured(),
    settingsComponent: NewProviderSettings,
}
```

**Step 3**: Done! The system automatically:
- Shows it in Integrations management
- Adds tab when enabled
- Routes to your settings component

### File Structure

```
billing/
├── BillingRouter.tsx              # Main router with dynamic tabs
├── SettingsContainer.tsx          # Global settings only
└── integrations/
    ├── types.ts                   # TypeScript interfaces
    ├── registry.ts                # Integration registry
    ├── IntegrationsContainer.tsx  # Management UI
    ├── StripeSettings.tsx        # Stripe configuration
    ├── MollieSettings.tsx        # Mollie configuration
    └── README.md                 # Developer guide
```

## Documentation

📚 **Complete documentation available:**

1. **[Architecture Guide](../docs/billing-integration-architecture.md)**  
   Complete system architecture, migration guide, future enhancements

2. **[UI Comparison](../docs/billing-ui-comparison.md)**  
   Visual before/after comparison with diagrams

3. **[Implementation Summary](../docs/billing-integration-ui-refactoring-summary.md)**  
   Complete implementation details, metrics, benefits

4. **[Developer README](README.md)**  
   Quick reference for developers (integrations directory)

## Key Features

✅ Multiple integrations simultaneously  
✅ Modular, plugin-like architecture  
✅ Dynamic tab rendering  
✅ Clean UI separation  
✅ Easy to extend  
✅ Type safe  
✅ Backward compatible  
✅ Well documented  

## Benefits

### For Admins
- Clear organization
- Enable multiple payment options
- Easy configuration

### For Developers
- Modular design
- Simple to add integrations
- Type safety
- Clear documentation

### For Business
- More payment options → higher conversion
- Geographic flexibility
- Reliability through redundancy

## Backward Compatibility

✅ **Fully backward compatible**

- Existing `processor` setting still works
- API keys preserved
- No breaking changes
- Zero migration required

## Support

- **Architecture questions**: See [billing-integration-architecture.md](../docs/billing-integration-architecture.md)
- **UI questions**: See [billing-ui-comparison.md](../docs/billing-ui-comparison.md)
- **Implementation details**: See [billing-integration-ui-refactoring-summary.md](../docs/billing-integration-ui-refactoring-summary.md)

## Quick Links

- [Main Documentation](../docs/billing-integration-architecture.md)
- [Developer Guide](./integrations/README.md)
- [UI Comparison](../docs/billing-ui-comparison.md)
- [Implementation Summary](../docs/billing-integration-ui-refactoring-summary.md)

---

**Last Updated**: January 2026  
**Status**: ✅ Complete and Production Ready
