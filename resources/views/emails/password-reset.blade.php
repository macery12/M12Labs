@extends('emails.layout')
@include('emails.partials.styles')

@section('content')
    @include('emails.partials.header', [
        'title' => 'Reset Your Password',
        'subtitle' => 'Use the link below to choose a new password.'
    ])
    <p style="{{ $paragraphStyle }}">Hello {{ $userName }},</p>
    <p style="{{ $paragraphStyle }}">We received a password reset request for your account. Click the button below to proceed.</p>
    @include('emails.partials.button', ['url' => $resetUrl, 'text' => 'Reset Password'])
    <p style="{{ $paragraphStyle }}">This password reset link will expire in 60 minutes. If you did not request a password reset, no further action is required.</p>
@endsection
