@extends('emails.layout')

@section('content')
    @include('emails.partials.header', [
        'title' => 'Server Renewal Notice',
        'subtitle' => 'Your server is approaching its renewal date.'
    ])
    <p style="margin:0 0 16px; color:#111827; font-size:15px; line-height:1.6;">Hello {{ $userName }},</p>
    <p style="margin:0 0 16px; color:#111827; font-size:15px; line-height:1.6;">Your server <strong>{{ $serverName }}</strong> is approaching its renewal date and requires your attention.</p>
    @component('emails.partials.panel', ['title' => 'Renewal details'])
        @include('emails.partials.key_value_table', [
            'rows' => [
                'Renewal Date' => $renewalDate,
                'Renewal Amount' => $currency . ' ' . $renewalAmount,
                'Billing Cycle' => $billingCycle ?: null,
                'Suspension Time' => $suspensionTime,
            ]
        ])
    @endcomponent
    <p style="margin:0 0 16px; color:#111827; font-size:15px; line-height:1.6;">If payment is not received by the suspension time, your server will be automatically suspended. Renew now to avoid interruption.</p>
    @include('emails.partials.button', [
        'url' => $renewalUrl,
        'text' => 'Renew Server Now'
    ])
    <p style="margin:0 0 16px; color:#111827; font-size:15px; line-height:1.6;">Consider enabling auto-renewal in your server billing settings for uninterrupted service.</p>
@endsection
