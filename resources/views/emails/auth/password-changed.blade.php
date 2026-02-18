@extends('emails.layout')

@section('content')
    @include('emails.partials.header', [
        'title' => 'Password Changed',
        'subtitle' => 'Your security settings were recently updated.'
    ])
    <p style="margin:0 0 16px; color:#111827; font-size:15px; line-height:1.6;">Hello {{ $userName }},</p>
    <p style="margin:0 0 16px; color:#111827; font-size:15px; line-height:1.6;">This is a confirmation that your password was successfully changed on {{ $changedAt }}.</p>
    @component('emails.partials.panel', ['title' => 'Change details'])
        <p style="margin:0 0 16px; color:#111827; font-size:15px; line-height:1.6;; margin:0 0 8px;">IP Address: <strong>{{ $ipAddress }}</strong></p>
        <p style="margin:0 0 16px; color:#111827; font-size:15px; line-height:1.6;; margin:0;">If this wasn’t you, please secure your account immediately.</p>
    @endcomponent
    <p style="margin:0 0 16px; color:#111827; font-size:15px; line-height:1.6;">If you did not make this change, reset your password and contact support right away.</p>
@endsection
