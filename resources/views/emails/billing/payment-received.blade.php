@component('mail::message')
# Payment Received{{ $isRenewal ? ' - Server Renewal' : '' }}

Hello {{ $userName }},

We've successfully received your payment{{ $isRenewal ? ' for your server renewal' : '' }}!

**Payment Details:**
@if($originalAmount && $discountAmount && $couponCode)
- Original Amount: {{ $currency }} {{ $originalAmount }}
- Discount ({{ $couponCode }}): -{{ $currency }} {{ $discountAmount }}
- **Final Amount: {{ $currency }} {{ $amount }}**
@else
- Amount: {{ $currency }} {{ $amount }}
@endif
- Payment Method: {{ $paymentMethod }}
@if($billingCycle)
- Billing Cycle: {{ $billingCycle }}
@endif
- Transaction Date: {{ $transactionDate }}
@if($invoiceId !== 'N/A')
- Invoice ID: {{ $invoiceId }}
@endif

Thank you for your payment. Your {{ $isRenewal ? 'server has been renewed and' : 'account has been credited and your' }} services will continue uninterrupted.

@component('mail::button', ['url' => url('/billing')])
View Billing History
@endcomponent

If you have any questions about this payment, please don't hesitate to contact our support team.

Thanks,<br>
{{ config('app.name') }}
@endcomponent
