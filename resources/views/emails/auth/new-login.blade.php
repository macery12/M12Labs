@extends('emails.layout')

@section('content')
    <div class="header">
        <h1>New Login Detected</h1>
    </div>
    <div class="content">
        <p>Hello {{ $userName }},</p>
        <p>We detected a new login to your account.</p>
        <p><strong>Time:</strong> {{ $loginTime }}</p>
        <p><strong>IP Address:</strong> {{ $ipAddress }}</p>
        <p><strong>Device:</strong> {{ $userAgent }}</p>
        @if(isset($location) && $location !== 'Unknown')
            <p><strong>Location:</strong> {{ $location }}</p>
        @endif
        <p>If this was you, you can safely ignore this email. If you don't recognize this login, please secure your account immediately.</p>
    </div>
    <div class="footer">
        <p>This is an automated security notification.</p>
    </div>
@endsection
