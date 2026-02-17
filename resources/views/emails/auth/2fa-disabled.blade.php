@extends('emails.layout')

@section('content')
    @include('emails.partials.header', [
        'title' => 'Two-Factor Authentication Disabled',
        'subtitle' => 'Security on your account was changed.'
    ])
    <p style="{{ $paragraphStyle }}">Hello {{ $userName }},</p>
    <p style="{{ $paragraphStyle }}">Two-factor authentication was disabled on your account at {{ $disabledAt }}.</p>
    @component('emails.partials.panel', ['title' => 'Change details'])
        @include('emails.partials.key_value_table', [
            'rows' => [
                'IP Address' => $ipAddress,
            ]
        ])
    @endcomponent
    <p style="{{ $paragraphStyle }}">Your account is now less secure. We strongly recommend re-enabling two-factor authentication to protect your account.</p>
    @include('emails.partials.button', [
        'url' => url('/auth/login'),
        'text' => 'Re-enable 2FA'
    ])
    <p style="{{ $paragraphStyle }}">If you did not disable two-factor authentication, please contact support immediately.</p>
@endsection
