@extends('emails.layout')

@section('content')
    @include('emails.partials.header', [
        'title' => 'Server Suspended',
        'subtitle' => 'Action required to restore your service.'
    ])
    <p style="margin:0 0 16px; color:#111827; font-size:15px; line-height:1.6;">Hello {{ $userName }},</p>
    <p style="margin:0 0 16px; color:#111827; font-size:15px; line-height:1.6;">Your server <strong>{{ $serverName }}</strong> has been suspended.</p>
    @component('emails.partials.panel', ['title' => 'Suspension details'])
        @include('emails.partials.key_value_table', [
            'rows' => [
                'Reason' => $reason,
                'Suspended At' => $suspendedAt,
            ]
        ])
    @endcomponent
    <p style="margin:0 0 16px; color:#111827; font-size:15px; line-height:1.6;">To restore access, please resolve the issue that led to the suspension. If you believe this was in error, contact support.</p>
    @include('emails.partials.button', [
        'url' => url('/support'),
        'text' => 'Contact Support',
        'color' => '#ef4444'
    ])
@endsection
