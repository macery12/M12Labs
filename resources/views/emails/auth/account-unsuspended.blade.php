@extends('emails.layout')
@include('emails.partials.styles')

@section('content')
    @include('emails.partials.header', [
        'title' => 'Account Restored',
        'subtitle' => 'Your account suspension has been lifted.'
    ])
    <p style="{{ $paragraphStyle }}">Hello {{ $userName }},</p>
    <p style="{{ $paragraphStyle }}">Good news! Your account has been unsuspended and you now have full access again.</p>
    @component('emails.partials.panel', ['title' => 'Restoration details'])
        @include('emails.partials.key_value_table', [
            'rows' => [
                'Restored At' => $unsuspendedAt,
            ]
        ])
    @endcomponent
    <p style="{{ $paragraphStyle }}">You can now log in and manage your servers normally.</p>
    @include('emails.partials.button', [
        'url' => url('/auth/login'),
        'text' => 'Login to Panel',
        'color' => '#10b981'
    ])
    <p style="{{ $paragraphStyle }}">Thank you for your patience.</p>
@endsection
