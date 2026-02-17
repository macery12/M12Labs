@extends('emails.layout')

@section('content')
    <div class="header">
        <h1>Important Announcement</h1>
    </div>
    <div class="content">
        <p>Hello,</p>
        <div style="background-color: #f9fafb; padding: 20px; border-radius: 6px; margin: 20px 0;">
            {!! nl2br(e($message)) !!}
        </div>
        <p>— {{ $adminName }}</p>
    </div>
    <div class="footer">
        <p>This is an automated message from the administration team.</p>
    </div>
@endsection
