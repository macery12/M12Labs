@extends('emails.layout')

@section('content')
    <h2 style="color: #ef4444; font-size: 24px; margin-bottom: 20px;">Account Suspended</h2>
    
    <p>Hello {{ $userName }},</p>
    
    <p>Your account has been suspended by an administrator.</p>
    
    <p><strong>Reason:</strong> {{ $reason }}</p>
    
    <p><strong>Suspended At:</strong> {{ $suspendedAt }}</p>
    
    <p>While your account is suspended, you will not be able to access the panel or manage your servers.</p>
    
    <p>If you believe this suspension was made in error or you have questions, please contact our support team:</p>
    
    <p style="text-align: center; margin: 20px 0;">
        <a href="{{ $supportUrl }}" style="display: inline-block; padding: 12px 24px; background-color: #3b82f6; color: #ffffff; text-decoration: none; border-radius: 6px;">
            Contact Support
        </a>
    </p>
@endsection
