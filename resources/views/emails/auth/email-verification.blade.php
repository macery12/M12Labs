@extends('emails.layout')

@section('content')
    @include('emails.partials.header', [
        'title' => 'Verify Your Email Address',
        'subtitle' => 'Confirm your email to unlock all account features.'
    ])
    <p style="margin:0 0 16px; color:#111827; font-size:15px; line-height:1.6;">Hello {{ $userName }},</p>
    <p style="margin:0 0 16px; color:#111827; font-size:15px; line-height:1.6;">
        Please verify your email address to continue using all features of your account. Click the button below to
        confirm your email.
    </p>
    @include('emails.partials.button', ['url' => $verificationUrl, 'text' => 'Verify Email'])
    <p style="margin:0 0 16px; color:#111827; font-size:15px; line-height:1.6;">
        This verification link will expire in {{ $expiresIn ?? '60 minutes' }}. If the button does not work, copy and
        paste the link below into your browser:
    </p>
    <p style="margin:0 0 16px; color:#111827; font-size:13px; line-height:1.6; word-break: break-all;">
        {{ $verificationUrl }}
    </p>
    <p style="margin:0 0 16px; color:#6b7280; font-size:13px; line-height:1.6;">
        If you did not create an account with us, you can safely ignore this email.
    </p>
@endsection
