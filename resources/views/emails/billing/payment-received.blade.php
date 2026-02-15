@component('mail::message')
# Payment Received

Hello {{ $userName }},

We've successfully received your payment!

**Payment Details:**
- Amount: {{ $currency }} {{ $amount }}
- Payment Method: {{ $paymentMethod }}
- Transaction Date: {{ $transactionDate }}
@if($invoiceId !== 'N/A')
- Invoice ID: {{ $invoiceId }}
@endif

Thank you for your payment. Your account has been credited and your services will continue uninterrupted.

@component('mail::button', ['url' => url('/billing')])
View Billing History
@endcomponent

If you have any questions about this payment, please don't hesitate to contact our support team.

Thanks,<br>
{{ config('app.name') }}
@endcomponent
