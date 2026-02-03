# Donation System

This donation system allows users to make voluntary monetary contributions to support the panel service.

## Important Notes

- **No Benefits**: Donations do not provide any server resources, credits, or other benefits
- **Voluntary**: All donations are completely voluntary
- **Non-Refundable**: Donations are typically non-refundable unless required by law

## Features

- Secure payment processing via Stripe
- Support for multiple payment methods (Card, PayPal, Link - if enabled)
- Optional donation messages
- Donation history tracking
- Same Stripe integration as billing system

## Requirements

- Billing module must be enabled (`BILLING_ENABLED=true`)
- Stripe API keys must be configured:
  - `BILLING_PUBLISHABLE_KEY`
  - `BILLING_SECRET_KEY`

## Setup

1. Enable the billing module in your `.env` file
2. Configure your Stripe API keys
3. Run the migration: `php artisan migrate`
4. Users can now access donations via the "Donate" link in their account menu

## Usage

1. Navigate to Account → Donate
2. Enter donation amount (minimum $1, maximum $10,000)
3. Optionally add a message
4. Complete payment via Stripe
5. View donation history at Account → Donations → History

## Database Schema

The `donations` table stores:
- User ID (foreign key to users table)
- Payment intent ID (Stripe reference)
- Amount and currency
- Status (pending, completed, failed)
- Optional message
- Timestamps

## API Endpoints

- `GET /api/client/donations` - List user's donations
- `GET /api/client/donations/key` - Get Stripe public key
- `POST /api/client/donations/intent` - Create payment intent
- `POST /api/client/donations/complete` - Complete donation

## Security

- All payments processed through Stripe
- No server resources or benefits granted
- CSRF protection enabled
- User authentication required
- Amount validation (min: $1, max: $10,000)
