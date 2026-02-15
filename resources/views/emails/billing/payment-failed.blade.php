@component('mail::message')
# Payment Failed

Hello {{ $userName }},

We were unable to process your recent payment attempt.

**Payment Details:**
- Amount: {{ $currency }} {{ $amount }}
@if($invoiceId !== 'N/A')
- Invoice ID: {{ $invoiceId }}
@endif
- Reason: {{ $reason }}

**What happens next?**

To avoid service interruption, please update your payment information and try again as soon as possible.

@component('mail::button', ['url' => $retryUrl])
Update Payment Method
@endcomponent

**Need help?**

If you're experiencing issues with payment, our support team is here to help. Contact us for assistance.

Thanks,<br>
{{ config('app.name') }}
@endcomponent
