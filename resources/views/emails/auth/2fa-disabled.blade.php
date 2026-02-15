@extends('emails.layout')

@section('content')
    <div class="header">
        <h1>Two-Factor Authentication Disabled</h1>
    </div>
    <div class="content">
        <p>Hello {{ $userName }},</p>
        <p>Two-factor authentication has been disabled on your account at {{ $disabledAt }}.</p>
        <p><strong>IP Address:</strong> {{ $ipAddress }}</p>
        <p>Your account is now less secure. We strongly recommend re-enabling two-factor authentication to protect your account.</p>
        <p>If you did not disable two-factor authentication, please contact our support team immediately and secure your account.</p>
    </div>
    <div class="footer">
        <p>This is an automated security notification.</p>
    </div>
@endsection
