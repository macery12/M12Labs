@extends('emails.layout')

@section('content')
    @include('emails.partials.header', [
        'title' => 'Reset Your Password',
        'subtitle' => 'Use the link below to choose a new password.'
    ])
    <p style="margin:0 0 16px; color:#111827; font-size:15px; line-height:1.6;">Hello {{ $userName }},</p>
    <p style="margin:0 0 16px; color:#111827; font-size:15px; line-height:1.6;">We received a password reset request for your account. Click the button below to proceed.</p>
    @include('emails.partials.button', ['url' => $resetUrl, 'text' => 'Reset Password'])
    <p style="margin:0 0 16px; color:#111827; font-size:15px; line-height:1.6;">This password reset link will expire in 60 minutes. If you did not request a password reset, no further action is required.</p>
@endsection
