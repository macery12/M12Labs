# Email Notification System

A comprehensive event-driven email notification system with template support, rate limiting, and quota management.

## Architecture Overview

The email notification system consists of several key components:

1. **Events** - Trigger email notifications (e.g., `PasswordResetRequested`, `ServerCreatedEmail`)
2. **EmailTypeRegistry** - Maps events to template keys and extracts data
3. **Templates** - Blade templates for email content (stored in `resources/views/emails/`)
4. **EmailNotificationListener** - Listens for events and dispatches jobs
5. **SendEmailJob** - Queued job that sends emails with rate limiting
6. **EmailManager** - Core service that renders templates and sends via provider
7. **EmailQuota** - Tracks and enforces rate limits per user/plan
8. **DeferredEmail** - Stores emails that exceeded quota for later sending

## How to Add a New Email Type

See the full documentation for step-by-step instructions on adding new email types.

The basic steps are:
1. Create an event class
2. Register in EmailTypeRegistry
3. Create a Blade template
4. Add database record (if needed)
5. Dispatch the event from your code

## Rate Limiting Plans

- **Free:** 3,000/month, 100/day, no overage
- **Pro:** 50,000/month, unlimited daily, overage allowed
- **Scale:** 100,000/month, unlimited daily, overage allowed

Overage cost: $0.90 per 1,000 emails

## Support

For full documentation, see the complete guide in this file.
