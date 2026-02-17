@extends('emails.layout')

@section('content')
    <div class="header">
        <h1>Password Changed</h1>
    </div>
    <div class="content">
        <p>Hello {{ $userName }},</p>
        <p>This is a confirmation that your password was successfully changed on {{ $changedAt }}.</p>
        <p>If you did not make this change, please contact our support team immediately.</p>
        <p><strong>IP Address:</strong> {{ $ipAddress }}</p>
    </div>
    <div class="footer">
        <p>This is an automated security notification.</p>
    </div>
@endsection
