@extends('emails.layout')

@section('content')
    @include('emails.partials.header', [
        'title' => 'Account Restored',
        'subtitle' => 'Your account suspension has been lifted.'
    ])
    <p style="margin:0 0 16px; color:#111827; font-size:15px; line-height:1.6;">Hello {{ $userName }},</p>
    <p style="margin:0 0 16px; color:#111827; font-size:15px; line-height:1.6;">Good news! Your account has been unsuspended and you now have full access again.</p>
    @component('emails.partials.panel', ['title' => 'Restoration details'])
        @include('emails.partials.key_value_table', [
            'rows' => [
                'Restored At' => $unsuspendedAt,
            ]
        ])
    @endcomponent
    <p style="margin:0 0 16px; color:#111827; font-size:15px; line-height:1.6;">You can now log in and manage your servers normally.</p>
    @include('emails.partials.button', [
        'url' => url('/auth/login'),
        'text' => 'Login to Panel',
        'color' => '#10b981'
    ])
    <p style="margin:0 0 16px; color:#111827; font-size:15px; line-height:1.6;">Thank you for your patience.</p>
@endsection
