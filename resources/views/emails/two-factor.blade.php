@extends('emails.layout')

@section('content')
    @include('emails.partials.header', [
        'title' => 'Two-Factor Authentication Code',
        'subtitle' => 'Use this code to complete your sign-in.'
    ])
    <p style="{{ $paragraphStyle }}">Hello {{ $userName }},</p>
    <p style="{{ $paragraphStyle }}">Your two-factor authentication code is:</p>
    @component('emails.partials.panel')
        <p style="{{ $headingStyle }} font-size:28px; letter-spacing: 8px; text-align:center; margin:0;">{{ $code }}</p>
    @endcomponent
    <p style="{{ $paragraphStyle }}">This code will expire in 10 minutes. If you did not request this code, please contact support immediately.</p>
    <p style="{{ $paragraphStyle }}">For your security, never share this code with anyone.</p>
@endsection
