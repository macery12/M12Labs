@extends('emails.layout')

@section('content')
    @include('emails.partials.header', [
        'title' => 'Welcome!',
        'subtitle' => 'Your account is ready to use.'
    ])
    <p style="{{ $paragraphStyle }}">Hello {{ $userName }},</p>
    <p style="{{ $paragraphStyle }}">Your account has been successfully created.</p>
    @component('emails.partials.panel', ['title' => 'Account details'])
        @include('emails.partials.key_value_table', [
            'rows' => [
                'Email' => $email,
            ]
        ])
    @endcomponent
    <p style="{{ $paragraphStyle }}">You can now log in to your account using the button below.</p>
    @include('emails.partials.button', ['url' => $loginUrl, 'text' => 'Log In'])
    <p style="{{ $paragraphStyle }}">Thank you for joining us!</p>
@endsection
