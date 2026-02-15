@extends('emails.layout')

@section('content')
    <h2 style="color: #10b981; font-size: 24px; margin-bottom: 20px;">Account Restored</h2>
    
    <p>Hello {{ $userName }},</p>
    
    <p>Good news! Your account has been unsuspended and you now have full access to the panel again.</p>
    
    <p><strong>Restored At:</strong> {{ $unsuspendedAt }}</p>
    
    <p>You can now log in and manage your servers normally.</p>
    
    <p style="text-align: center; margin: 20px 0;">
        <a href="{{ url('/auth/login') }}" style="display: inline-block; padding: 12px 24px; background-color: #10b981; color: #ffffff; text-decoration: none; border-radius: 6px;">
            Login to Panel
        </a>
    </p>
    
    <p>Thank you for your patience!</p>
@endsection
