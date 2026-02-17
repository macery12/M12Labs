@extends('emails.layout')
@include('emails.partials.styles')

@section('content')
    @include('emails.partials.header', [
        'title' => 'Two-Factor Authentication Enabled',
        'subtitle' => 'An extra layer of security is now active.'
    ])
    <p style="{{ $paragraphStyle }}">Hello {{ $userName }},</p>
    <p style="{{ $paragraphStyle }}">Two-factor authentication has been enabled on your account at {{ $enabledAt }}.</p>
    <p style="{{ $paragraphStyle }}">You will need to provide a verification code along with your password when logging in. If you did not make this change, please contact support immediately.</p>
@endsection
