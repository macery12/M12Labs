@extends('emails.layout')

@section('content')
    @include('emails.partials.header', [
        'title' => 'Server Renewal Notice',
        'subtitle' => 'Your server is approaching its renewal date.'
    ])
    <p style="{{ $paragraphStyle }}">Hello {{ $userName }},</p>
    <p style="{{ $paragraphStyle }}">Your server <strong>{{ $serverName }}</strong> is approaching its renewal date and requires your attention.</p>
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
    <p style="{{ $paragraphStyle }}">If payment is not received by the suspension time, your server will be automatically suspended. Renew now to avoid interruption.</p>
    @include('emails.partials.button', [
        'url' => $renewalUrl,
        'text' => 'Renew Server Now'
    ])
    <p style="{{ $paragraphStyle }}">Consider enabling auto-renewal in your server billing settings for uninterrupted service.</p>
@endsection
