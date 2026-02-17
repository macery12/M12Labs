@extends('emails.layout')
@include('emails.partials.styles')

@section('content')
    @include('emails.partials.header', [
        'title' => 'Your Server Is Expiring Soon',
        'subtitle' => 'Renew now to avoid interruption.'
    ])
    <p style="{{ $paragraphStyle }}">Hello {{ $userName }},</p>
    <p style="{{ $paragraphStyle }}">Your server <strong>{{ $serverName }}</strong> will expire soon. Please review the details below and renew to keep your service running.</p>
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
    <p style="{{ $paragraphStyle }}">If you have already renewed, you can ignore this notice.</p>
@endsection
