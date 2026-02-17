@extends('emails.layout')

@section('content')
    <h2 style="color: #10b981; font-size: 24px; margin-bottom: 20px;">Server Restored</h2>
    
    <p>Hello {{ $userName }},</p>
    
    <p>Good news! Your server <strong>{{ $serverName }}</strong> has been unsuspended and is now active again.</p>
    
    <p><strong>Restored At:</strong> {{ $unsuspendedAt }}</p>
    
    <p>You can now access and manage your server normally.</p>
    
    <p>Thank you for your patience!</p>
@endsection
