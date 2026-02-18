@extends('emails.layout')

@section('content')
    @include('emails.partials.header', [
        'title' => 'Welcome!',
        'subtitle' => 'Your account is ready to use.'
    ])
    <p style="margin:0 0 16px; color:#111827; font-size:15px; line-height:1.6;">Hello {{ $userName }},</p>
    <p style="margin:0 0 16px; color:#111827; font-size:15px; line-height:1.6;">Your account has been successfully created.</p>
    @component('emails.partials.panel', ['title' => 'Account details'])
        @include('emails.partials.key_value_table', [
            'rows' => [
                'Email' => $email,
            ]
        ])
    @endcomponent
    <p style="margin:0 0 16px; color:#111827; font-size:15px; line-height:1.6;">You can now log in to your account using the button below.</p>
    @include('emails.partials.button', ['url' => $loginUrl, 'text' => 'Log In'])
    <p style="margin:0 0 16px; color:#111827; font-size:15px; line-height:1.6;">Thank you for joining us!</p>
@endsection
