@extends('emails.layout')

@section('content')
    <div class="header">
        <h1>Server Created Successfully</h1>
    </div>
    <div class="content">
        <p>Hello {{ $userName }},</p>
        <p>Your server <strong>{{ $serverName }}</strong> has been successfully created!</p>
        <p><strong>Server ID:</strong> {{ $serverId }}</p>
        <p><strong>Location:</strong> {{ $nodeLocation }}</p>
        <p>Your server is now being set up and will be ready shortly.</p>
        <p style="text-align: center;">
            <a href="{{ $serverUrl }}" class="button">View Server</a>
        </p>
    </div>
    <div class="footer">
        <p>Thank you for using our service!</p>
    </div>
@endsection
