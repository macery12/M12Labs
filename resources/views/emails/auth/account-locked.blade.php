@extends('emails.layout')
@include('emails.partials.styles')

@section('content')
    @include('emails.partials.header', [
        'title' => 'Account Suspended',
        'subtitle' => 'Access to your account has been temporarily disabled.'
    ])
    <p style="{{ $paragraphStyle }}">Hello {{ $userName }},</p>
    <p style="{{ $paragraphStyle }}">Your account has been suspended by an administrator.</p>
    @component('emails.partials.panel', ['title' => 'Suspension details'])
        @include('emails.partials.key_value_table', [
            'rows' => [
                'Reason' => $reason,
                'Suspended At' => $suspendedAt,
            ]
        ])
    @endcomponent
    <p style="{{ $paragraphStyle }}">While suspended, you cannot access the panel or manage your servers. If you believe this is an error or have questions, please contact support.</p>
    @include('emails.partials.button', [
        'url' => $supportUrl,
        'text' => 'Contact Support',
        'color' => '#ef4444'
    ])
@endsection
