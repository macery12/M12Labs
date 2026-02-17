@extends('emails.layout')

@section('content')
    <div class="header">
        <h1>Welcome to Your Account!</h1>
    </div>
    <div class="content">
        <p>Hello {{ $userName }},</p>
        <p>Welcome! Your account has been successfully created.</p>
        <p>You can now log in to your account and start using our services.</p>
        <p style="text-align: center;">
            <a href="{{ $loginUrl }}" class="button">Go to Login</a>
        </p>
        <p>If you have any questions, feel free to reach out to our support team.</p>
    </div>
    <div class="footer">
        <p>This email was sent because an account was created with this email address.</p>
    </div>
@endsection
