@extends('emails.layout')

@section('content')
    <div class="header">
        <h1>Two-Factor Authentication Enabled</h1>
    </div>
    <div class="content">
        <p>Hello {{ $userName }},</p>
        <p>Two-factor authentication has been enabled on your account at {{ $enabledAt }}.</p>
        <p>Your account is now more secure. You will be required to provide a verification code along with your password when logging in.</p>
        <p>If you did not enable two-factor authentication, please contact our support team immediately.</p>
    </div>
    <div class="footer">
        <p>This is an automated security notification.</p>
    </div>
@endsection
