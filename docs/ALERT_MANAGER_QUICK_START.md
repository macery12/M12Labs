# Alert Manager - Quick Start Guide

## Overview

The Alert Manager is a centralized system for displaying alerts throughout the Jexactyl application. It provides a consistent, accessible, and easy-to-use interface for notifications.

## Basic Usage

### 1. Import the Hook

```typescript
import { useAlerts } from '@/contexts/AlertContext';
```

### 2. Use in Your Component

```typescript
function MyComponent() {
    const { success, error, info, warning } = useAlerts();

    const handleClick = () => {
        success('Operation completed successfully!');
    };

    return <button onClick={handleClick}>Do Something</button>;
}
```

## Alert Types

- **success** (green) - Successful operations
- **error** (red) - Errors and failures
- **info** (blue) - Informational messages
- **warning** (yellow) - Warnings and cautions

## Common Patterns

### Simple Alert

```typescript
const { success } = useAlerts();
success('File uploaded successfully!');
```

### Alert with Title

```typescript
const { error } = useAlerts();
error('Failed to process request', {
    title: 'Validation Error'
});
```

### Alert with Actions

```typescript
const { warning } = useAlerts();
warning('Your session is about to expire', {
    title: 'Session Warning',
    actions: [
        {
            label: 'Extend Session',
            onClick: () => extendSession()
        },
        {
            label: 'Logout',
            onClick: () => logout(),
            variant: 'secondary'
        }
    ],
    timeout: false
});
```

### Persistent Alert (No Auto-dismiss)

```typescript
const { info } = useAlerts();
info('Please wait while we process your request', {
    timeout: false
});
```

### Scoped Alerts (Page-specific)

In your component:

```typescript
import { useAlerts } from '@/contexts/AlertContext';
import AlertRenderer from '@/components/AlertRenderer';

function SettingsPage() {
    const { success } = useAlerts();

    const handleSave = () => {
        success('Settings saved', { key: 'settings' });
    };

    return (
        <div>
            <AlertRenderer filterByKey="settings" position="top-center" />
            {/* Page content */}
        </div>
    );
}
```

## Configuration Options

When calling alert methods, you can pass these options:

```typescript
{
    title?: string;              // Optional title
    timeout?: number | false;    // Auto-dismiss timeout (default: 5000ms, false = never)
    dismissible?: boolean;       // Can user dismiss? (default: true)
    key?: string;                // For scoped alerts
    actions?: AlertAction[];     // Action buttons
}
```

## Backend Integration

Laravel flash messages are automatically displayed:

```php
// In your controller
return redirect()->back()->with('success', 'Profile updated successfully');

// Or
return redirect()->back()->with('error', 'Invalid credentials');
```

Supported session keys: `success`, `error`, `info`, `warning`

## Migration from Old System

### Before (useFlash)

```typescript
import useFlash from '@/plugins/useFlash';

const { addFlash } = useFlash();
addFlash({ type: 'success', message: 'Done!' });
```

### After (useAlerts)

```typescript
import { useAlerts } from '@/contexts/AlertContext';

const { success } = useAlerts();
success('Done!');
```

The old `useFlash` hook still works but now bridges to the new system.

## Examples

See `/resources/scripts/components/examples/AlertExample.tsx` for a complete working example with all features demonstrated.

## Full Documentation

See `/docs/ALERT_MANAGER.md` for complete API documentation and advanced usage.

## Best Practices

1. ✅ Use appropriate alert types (success for successes, error for errors, etc.)
2. ✅ Keep messages short and actionable
3. ✅ Use titles to provide context
4. ✅ Add actions when users can do something about the alert
5. ✅ Use scoped alerts for page-specific messages
6. ✅ Consider timeout duration based on message importance

## Troubleshooting

**Alerts not showing?**
- Ensure `AlertProvider` wraps your component tree (already done in App.tsx)
- Check that `AlertRenderer` is included (already in App.tsx for global alerts)

**Need help?**
- Check the full documentation at `/docs/ALERT_MANAGER.md`
- Review the example component at `/resources/scripts/components/examples/AlertExample.tsx`
