@component('mail::message')
# Server Renewal Notice

Hello {{ $userName }},

Your server **{{ $serverName }}** is approaching its renewal date and requires your attention.

**Important Information:**
- Renewal Date: {{ $renewalDate }}
- Renewal Amount: {{ $currency }} {{ $renewalAmount }}
@if($billingCycle)
- Billing Cycle: {{ $billingCycle }}
@endif
- **Suspension Time: {{ $suspensionTime }}**

**What happens if I don't renew?**

If payment is not received by the suspension time, your server will be automatically suspended to prevent additional charges. You can renew at any time to restore service.

@component('mail::button', ['url' => $renewalUrl])
Renew Server Now
@endcomponent

**Auto-renewal not enabled?**

Consider enabling auto-renewal to ensure uninterrupted service. You can manage this in your server billing settings.

If you have any questions or need assistance, please contact our support team.

Thanks,<br>
{{ config('app.name') }}
@endcomponent
