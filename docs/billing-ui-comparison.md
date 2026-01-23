# Billing UI Structure - Before and After

## Before Refactoring

### Settings Tab (Cluttered)
```
/admin/billing/settings

┌─────────────────────────────────────────────────────────────┐
│ Settings                                                     │
├─────────────────────────────────────────────────────────────┤
│                                                              │
│ ┌────────────────────┐  ┌────────────────────┐             │
│ │ Select Processor   │  │ Add PayPal         │             │
│ │ [Stripe ▼]         │  │ Currently: enabled │             │
│ │                    │  │ [Setup] [Disable]  │             │
│ └────────────────────┘  └────────────────────┘             │
│                                                              │
│ ┌────────────────────┐  ┌────────────────────┐             │
│ │ Add Link           │  │ Primary Currency   │             │
│ │ Currently: enabled │  │ [USD ▼]            │             │
│ │ [Setup] [Disable]  │  │                    │             │
│ └────────────────────┘  └────────────────────┘             │
│                                                              │
│ ┌────────────────────┐  ┌────────────────────┐             │
│ │ Stripe API Keys    │  │ Mollie API Key     │             │
│ │ [Add Keys]         │  │ (Optional)         │             │
│ │                    │  │ [Add API Key]      │             │
│ └────────────────────┘  └────────────────────┘             │
│                                                              │
│ ┌────────────────────┐  ┌────────────────────┐             │
│ │ Legal Links        │  │ Plan Cooldown      │             │
│ │ ToS: _______       │  │ [72] hours         │             │
│ │ Privacy: _____     │  │                    │             │
│ └────────────────────┘  └────────────────────┘             │
│                                                              │
└─────────────────────────────────────────────────────────────┘
```

**Problems:**
- ❌ All settings mixed together
- ❌ Can only use ONE processor at a time
- ❌ Hard to find specific integration settings
- ❌ Confusing for admins
- ❌ Hard to add new integrations

---

## After Refactoring

### Navigation Tabs
```
┌─────────────────────────────────────────────────────────────────────────┐
│ Overview | Products | Orders | Donations | Coupons | Exceptions |      │
│ Renewal Dates | 🔵 Stripe | 💳 Mollie | 🧩 Integrations | ⚙️ Settings │
└─────────────────────────────────────────────────────────────────────────┘
          ↑                ↑               ↑              ↑
          |                |               |              |
    Only visible if   Only visible   New management   Global settings
    Stripe enabled    if Mollie      page for all     (currency, legal,
                      enabled        integrations     cooldown, etc.)
```

### 1. Integrations Tab (NEW)
```
/admin/billing/integrations

┌─────────────────────────────────────────────────────────────┐
│ Payment Integrations                                         │
├─────────────────────────────────────────────────────────────┤
│ Enable and configure payment integrations. Multiple can be  │
│ enabled simultaneously.                                      │
│                                                              │
│ ┌──────────────────────────┐  ┌──────────────────────────┐ │
│ │ Stripe                 ✓ │  │ Mollie                 ✓ │ │
│ ├──────────────────────────┤  ├──────────────────────────┤ │
│ │ Accept credit cards,     │  │ European payment         │ │
│ │ digital wallets via      │  │ provider supporting      │ │
│ │ Stripe.                  │  │ iDEAL and more.          │ │
│ │                          │  │                          │ │
│ │ Status: Enabled          │  │ Status: Enabled          │ │
│ │ Configuration: Complete  │  │ Configuration: Complete  │ │
│ │                          │  │                          │ │
│ │ [Disable]                │  │ [Disable]                │ │
│ └──────────────────────────┘  └──────────────────────────┘ │
│                                                              │
│ ┌────────────────────────────────────────────────────────┐  │
│ │ 🧩 Adding New Integrations                             │  │
│ │ This modular system makes it easy to add new payment   │  │
│ │ integrations. Each integration can be enabled          │  │
│ │ independently.                                         │  │
│ └────────────────────────────────────────────────────────┘  │
└─────────────────────────────────────────────────────────────┘
```

### 2. Stripe Tab (only visible when enabled)
```
/admin/billing/integrations/stripe

┌─────────────────────────────────────────────────────────────┐
│ Stripe Configuration                                         │
├─────────────────────────────────────────────────────────────┤
│                                                              │
│ ┌────────────────────┐  ┌────────────────────┐             │
│ │ Stripe API Keys    │  │ PayPal Integration │             │
│ │ Status: Configured │  │ via Stripe         │             │
│ │ [Reset Keys]       │  │ [Enable] [Setup]   │             │
│ └────────────────────┘  └────────────────────┘             │
│                                                              │
│ ┌────────────────────┐                                      │
│ │ Link Integration   │                                      │
│ │ One-click payment  │                                      │
│ │ [Enable] [Setup]   │                                      │
│ └────────────────────┘                                      │
│                                                              │
└─────────────────────────────────────────────────────────────┘
```

### 3. Mollie Tab (only visible when enabled)
```
/admin/billing/integrations/mollie

┌─────────────────────────────────────────────────────────────┐
│ Mollie Configuration                                         │
├─────────────────────────────────────────────────────────────┤
│                                                              │
│ ┌────────────────────┐  ┌────────────────────┐             │
│ │ Mollie API Key     │  │ About Mollie       │             │
│ │ Status: Configured │  │ European payment   │             │
│ │ [Update Key]       │  │ provider.          │             │
│ └────────────────────┘  └────────────────────┘             │
│                                                              │
└─────────────────────────────────────────────────────────────┘
```

### 4. Settings Tab (Simplified - GLOBAL ONLY)
```
/admin/billing/settings

┌─────────────────────────────────────────────────────────────┐
│ Global Settings                                              │
├─────────────────────────────────────────────────────────────┤
│                                                              │
│ ┌────────────────────┐  ┌────────────────────┐             │
│ │ Primary Currency   │  │ Import/Export      │             │
│ │ [USD ▼]            │  │ Config             │             │
│ │                    │  │ [Export] [Import]  │             │
│ └────────────────────┘  └────────────────────┘             │
│                                                              │
│ ┌────────────────────┐  ┌────────────────────┐             │
│ │ Legal Links        │  │ Plan Cooldown      │             │
│ │ ToS: _______       │  │ [72] hours         │             │
│ │ Privacy: _____     │  │                    │             │
│ └────────────────────┘  └────────────────────┘             │
│                                                              │
│ ┌────────────────────┐                                      │
│ │ Disable Billing    │                                      │
│ │ [Disable Module]   │                                      │
│ └────────────────────┘                                      │
│                                                              │
└─────────────────────────────────────────────────────────────┘
```

---

## Key Improvements

### ✅ Modular Architecture
- Each integration has its own configuration tab
- Integration-specific settings are isolated
- Easy to find and configure individual integrations

### ✅ Multiple Integrations
- **Both Stripe AND Mollie can be enabled simultaneously**
- More payment options for users
- Increased conversion rates

### ✅ Clean UI
- Global settings separated from integration settings
- Integration management in dedicated "Integrations" tab
- Clear status indicators (enabled/disabled, configured/not configured)

### ✅ Scalable
Adding a new integration (e.g., PayPal standalone):
1. Create `PayPalSettings.tsx`
2. Add entry to `registry.ts`
3. Done! Tab appears automatically when enabled

### ✅ Backward Compatible
- Existing `processor` setting still works
- Auto-enables the correct integration
- Existing API keys preserved
- Zero downtime migration
