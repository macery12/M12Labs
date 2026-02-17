@extends('emails.layout')

@section('content')
    @include('emails.partials.header', [
        'title' => 'Account Suspended',
        'subtitle' => 'Access to your account has been temporarily disabled.'
    ])
    <p style="margin:0 0 16px; color:#111827; font-size:15px; line-height:1.6;">Hello {{ $userName }},</p>
    <p style="margin:0 0 16px; color:#111827; font-size:15px; line-height:1.6;">Your account has been suspended by an administrator.</p>
    @component('emails.partials.panel', ['title' => 'Suspension details'])
        @include('emails.partials.key_value_table', [
            'rows' => [
                'Reason' => $reason,
                'Suspended At' => $suspendedAt,
            ]
        ])
    @endcomponent
    <p style="margin:0 0 16px; color:#111827; font-size:15px; line-height:1.6;">While suspended, you cannot access the panel or manage your servers. If you believe this is an error or have questions, please contact support.</p>
    @include('emails.partials.button', [
        'url' => $supportUrl,
        'text' => 'Contact Support',
        'color' => '#ef4444'
    ])
@endsection
