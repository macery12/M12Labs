@extends('emails.layout')

@section('content')
    @include('emails.partials.header', [
        'title' => 'New Login Detected',
        'subtitle' => 'A new sign-in was recorded on your account.'
    ])
    <p style="margin:0 0 16px; color:#111827; font-size:15px; line-height:1.6;">Hello {{ $userName }},</p>
    <p style="margin:0 0 16px; color:#111827; font-size:15px; line-height:1.6;">We detected a new login to your account. Review the details below.</p>
    @component('emails.partials.panel', ['title' => 'Login details'])
        @include('emails.partials.key_value_table', [
            'rows' => [
                'Time' => $loginTime,
                'IP Address' => $ipAddress,
                'Device' => $userAgent,
                'Location' => (isset($location) && $location !== 'Unknown') ? $location : null,
            ]
        ])
    @endcomponent
    <p style="margin:0 0 16px; color:#111827; font-size:15px; line-height:1.6;">If this was you, no further action is needed. If you don’t recognize this activity, secure your account immediately.</p>
    @include('emails.partials.button', [
        'url' => url('/auth/login'),
        'text' => 'Secure Your Account'
    ])
@endsection
