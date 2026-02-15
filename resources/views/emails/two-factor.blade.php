@extends('emails.layout')

@section('content')
    <div class="header">
        <h1>Two-Factor Authentication Code</h1>
    </div>
    <div class="content">
        <p>Hello {{ $userName }},</p>
        <p>Your two-factor authentication code is:</p>
        <p style="text-align: center; font-size: 32px; font-weight: bold; letter-spacing: 8px; margin: 30px 0;">
            {{ $code }}
        </p>
        <p>This code will expire in 10 minutes.</p>
        <p>If you did not request this code, please contact support immediately.</p>
    </div>
    <div class="footer">
        <p>For your security, never share this code with anyone.</p>
    </div>
@endsection
