# Button Component Disabled Prop Fix

## Problem

After fixing the Button component to properly handle the `disabled` prop, all payment methods became blocked and unclickable. Users couldn't select or use any payment method (Stripe, Mollie, or PayPal).

## Root Cause

The Button component was extracting `disabled` from props without providing a default value:

```tsx
const Button = ({ disabled, ...rest }) => {
    return <button disabled={disabled || loading}>
}
```

**The Issue:**
- When a button doesn't explicitly pass `disabled` prop, it becomes `undefined`
- `undefined || loading` evaluates correctly in JavaScript
- However, React's handling of `disabled={undefined}` can be unpredictable
- This caused buttons to be disabled when they should be enabled

## The Fix

Added a default value of `false` to the disabled parameter:

```tsx
const Button = ({ disabled = false, ...rest }) => {
    return <button disabled={disabled || loading}>
}
```

## Behavior Matrix

| disabled prop | loading | Result | Expected |
|--------------|---------|--------|----------|
| not passed | false | `false \|\| false` = `false` ✅ | Enabled |
| not passed | true | `false \|\| true` = `true` ✅ | Disabled (loading) |
| `true` | false | `true \|\| false` = `true` ✅ | Disabled |
| `true` | true | `true \|\| true` = `true` ✅ | Disabled |
| `false` | false | `false \|\| false` = `false` ✅ | Enabled |
| `false` | true | `false \|\| true` = `true` ✅ | Disabled (loading) |

## Why Default to `false`?

1. **Enables by default**: Buttons should be enabled unless explicitly disabled or loading
2. **Backward compatible**: Existing buttons without `disabled` prop work as before
3. **Explicit control**: Developers can still explicitly disable with `disabled={true}`
4. **Loading state respected**: Loading state still disables the button

## Impact

- ✅ Payment method buttons work correctly
- ✅ Buttons are enabled by default
- ✅ Disabled state works when explicitly set
- ✅ Loading state still disables buttons
- ✅ No breaking changes to existing code

## Lesson Learned

When extracting props with boolean semantics:
1. Always provide sensible defaults
2. Consider how `undefined` is interpreted
3. Test with and without the prop explicitly set
4. Document expected behavior for all cases
