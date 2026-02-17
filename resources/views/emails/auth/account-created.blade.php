@extends('emails.layout')

@section('content')
    @include('emails.partials.header', [
        'title' => 'Welcome to your new account!',
        'subtitle' => 'We’re excited to have you on board.'
    ])
    <p style="margin:0 0 16px; color:#111827; font-size:15px; line-height:1.6;">Hello {{ $userName }},</p>
    <p style="margin:0 0 16px; color:#111827; font-size:15px; line-height:1.6;">Your account has been successfully created. You can now log in and start using our services.</p>
    @component('emails.partials.panel', ['title' => 'Account details'])
        @include('emails.partials.key_value_table', [
            'rows' => [
                'Email' => $userEmail ?? null,
            ]
        ])
    @endcomponent
    @include('emails.partials.button', ['url' => $loginUrl, 'text' => 'Go to Login'])
    <p style="margin:0 0 16px; color:#111827; font-size:15px; line-height:1.6;">If you have any questions, feel free to reach out to our support team.</p>
@endsection
