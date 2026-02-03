# Centralized Alert Manager Documentation

## Overview

The Centralized Alert Manager is a unified system for displaying alerts, notifications, and messages throughout the Jexactyl application. It provides a consistent user experience with proper theming, accessibility support, and flexible configuration options.

## Features

- **Unified Alert System**: Single source of truth for all alerts across the application
- **Type Safety**: Full TypeScript support with proper type definitions
- **Accessibility**: ARIA labels, keyboard navigation, and screen reader support
- **Auto-dismiss**: Configurable timeout for automatic alert dismissal
- **Actions**: Support for custom action buttons within alerts
- **Scoped Alerts**: Show alerts specific to certain pages or components using keys
- **Theming**: Consistent color theming for success, error, info, and warning states
- **Backward Compatibility**: Bridges to existing flash message system

## Architecture

The Alert Manager consists of three main components:

1. **AlertContext** (`resources/scripts/contexts/AlertContext.tsx`): Manages global alert state
2. **AlertComponent** (`resources/scripts/components/AlertComponent.tsx`): Renders individual alerts
3. **AlertRenderer** (`resources/scripts/components/AlertRenderer.tsx`): Renders all active alerts

## Usage

### Basic Usage

Import the `useAlerts` hook to interact with the Alert Manager:

```typescript
import { useAlerts } from '@/contexts/AlertContext';

function MyComponent() {
    const { success, error, info, warning } = useAlerts();

    const handleSuccess = () => {
        success('Operation completed successfully!');
    };

    const handleError = () => {
        error('An error occurred. Please try again.');
    };

    return (
        <div>
            <button onClick={handleSuccess}>Show Success</button>
            <button onClick={handleError}>Show Error</button>
        </div>
    );
}
```

### Alert Types

The Alert Manager supports four alert types:

- **success**: Green - for successful operations
- **error**: Red - for errors and failures
- **info**: Blue - for informational messages
- **warning**: Yellow - for warnings and cautions

### Advanced Usage

#### Custom Title

```typescript
const { error } = useAlerts();

error('Failed to save changes', {
    title: 'Save Error'
});
```

#### Custom Actions

```typescript
const { warning } = useAlerts();

warning('Your session is about to expire', {
    title: 'Session Warning',
    actions: [
        {
            label: 'Extend Session',
            onClick: () => {
                // Extend session logic
            }
        },
        {
            label: 'Logout',
            onClick: () => {
                // Logout logic
            },
            variant: 'secondary'
        }
    ]
});
```

#### Disable Auto-dismiss

```typescript
const { info } = useAlerts();

info('This alert will not auto-dismiss', {
    timeout: false
});
```

#### Custom Timeout

```typescript
const { success } = useAlerts();

success('This will dismiss after 10 seconds', {
    timeout: 10000 // milliseconds
});
```

#### Non-dismissible Alert

```typescript
const { error } = useAlerts();

error('Critical error - cannot dismiss', {
    dismissible: false,
    timeout: false
});
```

### Scoped Alerts

Scoped alerts are useful for showing alerts specific to certain pages or components:

```typescript
import { useAlerts } from '@/contexts/AlertContext';
import AlertRenderer from '@/components/AlertRenderer';

function SettingsPage() {
    const { success } = useAlerts();

    const handleSave = () => {
        success('Settings saved successfully', {
            key: 'settings-page'
        });
    };

    return (
        <div>
            <AlertRenderer filterByKey="settings-page" position="top-center" />
            <h1>Settings</h1>
            <button onClick={handleSave}>Save Settings</button>
        </div>
    );
}
```

### Direct Alert Management

For more control, use the lower-level API:

```typescript
const { addAlert, dismissAlert, clearAlerts } = useAlerts();

// Add an alert and get its ID
const alertId = addAlert({
    type: 'info',
    message: 'Processing...',
    timeout: false
});

// Later, dismiss it manually
setTimeout(() => {
    dismissAlert(alertId);
}, 3000);

// Or clear all alerts with a specific key
clearAlerts('my-key');
```

### Backward Compatibility

The existing `useFlash` hook continues to work and now bridges to the new Alert Manager:

```typescript
import useFlash from '@/plugins/useFlash';

function LegacyComponent() {
    const { addFlash } = useFlash();

    addFlash({
        type: 'success',
        message: 'This still works!',
        key: 'legacy'
    });
}
```

### Using with Easy Peasy Store

The Alert Manager is separate from the Easy Peasy store but bridges to it for backward compatibility. You can still use the store-based flash system, and messages will appear in both systems.

## Rendering Alerts

The main `AlertRenderer` is already included in the App component and displays global alerts. For scoped alerts, add an `AlertRenderer` to your component:

```typescript
import AlertRenderer from '@/components/AlertRenderer';

function MyPage() {
    return (
        <div>
            <AlertRenderer 
                filterByKey="my-page" 
                position="top-center"
                className="mt-4"
            />
            {/* Page content */}
        </div>
    );
}
```

### AlertRenderer Props

- **filterByKey** (optional): Only show alerts with this key
- **position** (optional): Position of alerts - `'top-left' | 'top-right' | 'bottom-left' | 'bottom-right' | 'top-center' | 'bottom-center'`
- **className** (optional): Additional CSS classes

## Backend Integration

Backend flash messages are automatically integrated. Set flash messages in your Laravel controllers:

```php
return redirect()->back()->with('success', 'Operation completed successfully');
```

These will be picked up by the frontend and displayed through the Alert Manager.

## Accessibility

The Alert Manager includes:

- ARIA live regions (`role="alert"`, `aria-live="polite"`)
- Keyboard navigation support
- Screen reader friendly labels
- Proper focus management
- Color contrast compliant theming

## Migration Guide

To migrate from the old flash system to the new Alert Manager:

1. Import `useAlerts` instead of `useFlash`:
   ```typescript
   import { useAlerts } from '@/contexts/AlertContext';
   ```

2. Use the convenience methods:
   ```typescript
   const { success, error, info, warning } = useAlerts();
   success('Message here');
   ```

3. Update alert rendering:
   ```typescript
   import AlertRenderer from '@/components/AlertRenderer';
   <AlertRenderer filterByKey="your-key" />
   ```

4. The old system continues to work during migration for backward compatibility.

## Examples

### Simple Success Message

```typescript
const { success } = useAlerts();
success('File uploaded successfully!');
```

### Error with Details

```typescript
const { error } = useAlerts();
error('Failed to process request. Please check your input and try again.', {
    title: 'Validation Error'
});
```

### Info with Action

```typescript
const { info } = useAlerts();
info('A new version is available', {
    title: 'Update Available',
    actions: [
        {
            label: 'Update Now',
            onClick: () => window.location.reload()
        }
    ],
    timeout: false
});
```

### Persistent Warning

```typescript
const { warning } = useAlerts();
warning('Your trial period expires in 3 days', {
    title: 'Trial Expiring',
    dismissible: true,
    timeout: false
});
```

## Best Practices

1. **Use appropriate alert types**: Choose the right type (success, error, info, warning) for your message
2. **Keep messages concise**: Alert messages should be short and actionable
3. **Use titles for context**: Add titles to provide context for the alert
4. **Provide actions when needed**: If the user can take action, include action buttons
5. **Use scoped alerts**: For page-specific alerts, use keys to scope them
6. **Set appropriate timeouts**: Success messages can auto-dismiss quickly, errors might need more time
7. **Consider accessibility**: Ensure alerts are accessible to all users

## API Reference

### useAlerts Hook

Returns an object with:

- `alerts: Alert[]` - Array of active alerts
- `addAlert(alert): string` - Add an alert, returns alert ID
- `dismissAlert(id): void` - Dismiss a specific alert
- `clearAlerts(key?): void` - Clear alerts, optionally by key
- `success(message, options?): string` - Show success alert
- `error(message, options?): string` - Show error alert
- `info(message, options?): string` - Show info alert
- `warning(message, options?): string` - Show warning alert

### Alert Interface

```typescript
interface Alert {
    id: string;                    // Auto-generated
    type: 'success' | 'error' | 'info' | 'warning';
    message: string;               // Required
    title?: string;                // Optional title
    actions?: AlertAction[];       // Optional action buttons
    dismissible?: boolean;         // Can user dismiss? (default: true)
    timeout?: number | false;      // Auto-dismiss timeout in ms (default: 5000)
    key?: string;                  // For scoped alerts
}
```

### AlertAction Interface

```typescript
interface AlertAction {
    label: string;                 // Button text
    onClick: () => void;           // Click handler
    variant?: 'primary' | 'secondary'; // Button style
}
```

## Troubleshooting

### Alerts not showing

- Ensure `AlertProvider` wraps your component tree
- Check that `AlertRenderer` is included in your layout
- Verify the alert key matches if using scoped alerts

### Alerts not auto-dismissing

- Check that `timeout` is not set to `false`
- Verify `dismissible` is not set to `false`
- Default timeout is 5000ms (5 seconds)

### TypeScript errors

- Ensure you're importing from the correct paths
- Check that your alert type is one of the valid options
- Verify action buttons have required properties

## Support

For issues or questions, please refer to the Jexactyl documentation or open an issue on GitHub.
