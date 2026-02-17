@extends('emails.layout')

@section('content')
    <div class="header">
        <h1>Welcome!</h1>
    </div>
    <div class="content">
        <p>Hello {{ $userName }},</p>
        <p>Your account has been successfully created!</p>
        <p><strong>Email:</strong> {{ $email }}</p>
        <p>You can now log in to your account using the button below:</p>
        <p style="text-align: center;">
            <a href="{{ $loginUrl }}" class="button">Log In</a>
        </p>
        <p>Thank you for joining us!</p>
    </div>
    <div class="footer">
        <p>If you're having trouble clicking the "Log In" button, copy and paste the URL below into your web browser:</p>
        <p>{{ $loginUrl }}</p>
    </div>
@endsection
