@extends('emails.layout')

@section('content')
    <h2 style="color: #ef4444; font-size: 24px; margin-bottom: 20px;">Server Suspended</h2>
    
    <p>Hello {{ $userName }},</p>
    
    <p>Your server <strong>{{ $serverName }}</strong> has been suspended.</p>
    
    <p><strong>Reason:</strong> {{ $reason }}</p>
    
    <p><strong>Suspended At:</strong> {{ $suspendedAt }}</p>
    
    <p>If you believe this suspension was made in error or you have questions, please contact support.</p>
    
    <p>To restore access to your server, please resolve the issue that led to the suspension.</p>
@endsection
