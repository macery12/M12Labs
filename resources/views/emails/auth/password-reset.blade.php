@extends('emails.layout')

@section('content')
    @include('emails.partials.header', [
        'title' => 'Reset Your Password',
        'subtitle' => 'Securely update your password to keep your account safe.'
    ])
    <p style="{{ $paragraphStyle }}">Hello {{ $userName }},</p>
    <p style="{{ $paragraphStyle }}">We received a request to reset your password. Click the button below to choose a new one.</p>
    @include('emails.partials.button', ['url' => $resetUrl, 'text' => 'Reset Password'])
    <p style="{{ $paragraphStyle }}">This password reset link will expire in {{ $expiresIn ?? '60 minutes' }}.</p>
    <p style="{{ $paragraphStyle }}">If you did not request a password reset, you can safely ignore this email.</p>
@endsection
