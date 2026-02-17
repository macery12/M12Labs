@extends('emails.layout')

@section('content')
    <div class="header">
        <h1>Reset Your Password</h1>
    </div>
    <div class="content">
        <p>Hello {{ $userName }},</p>
        <p>You are receiving this email because we received a password reset request for your account.</p>
        <p style="text-align: center;">
            <a href="{{ $resetUrl }}" class="button">Reset Password</a>
        </p>
        <p>If you did not request a password reset, no further action is required.</p>
        <p>This password reset link will expire in {{ $expiresIn ?? '60 minutes' }}.</p>
    </div>
    <div class="footer">
        <p>If you're having trouble clicking the "Reset Password" button, copy and paste the URL below into your web browser:</p>
        <p>{{ $resetUrl }}</p>
    </div>
@endsection
