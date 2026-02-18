@extends('emails.layout')

@section('content')
    @include('emails.partials.header', [
        'title' => 'Your Server Is Expiring Soon',
        'subtitle' => 'Renew now to avoid interruption.'
    ])
    <p style="margin:0 0 16px; color:#111827; font-size:15px; line-height:1.6;">Hello {{ $userName }},</p>
    <p style="margin:0 0 16px; color:#111827; font-size:15px; line-height:1.6;">Your server <strong>{{ $serverName }}</strong> will expire soon. Please review the details below and renew to keep your service running.</p>
    @component('emails.partials.panel', ['title' => 'Expiration details'])
        @include('emails.partials.key_value_table', [
            'rows' => [
                'Expires At' => $expiresAt,
                'Days Remaining' => $daysRemaining,
            ]
        ])
    @endcomponent
    @include('emails.partials.button', [
        'url' => url('/billing'),
        'text' => 'Renew Server'
    ])
    <p style="margin:0 0 16px; color:#111827; font-size:15px; line-height:1.6;">If you have already renewed, you can ignore this notice.</p>
@endsection
