@extends('emails.layout')

@section('content')
    @include('emails.partials.header', [
        'title' => 'Welcome to your new account!',
        'subtitle' => 'We’re excited to have you on board.'
    ])
    <p style="{{ $paragraphStyle }}">Hello {{ $userName }},</p>
    <p style="{{ $paragraphStyle }}">Your account has been successfully created. You can now log in and start using our services.</p>
    @component('emails.partials.panel', ['title' => 'Account details'])
        @include('emails.partials.key_value_table', [
            'rows' => [
                'Email' => $userEmail ?? null,
            ]
        ])
    @endcomponent
    @include('emails.partials.button', ['url' => $loginUrl, 'text' => 'Go to Login'])
    <p style="{{ $paragraphStyle }}">If you have any questions, feel free to reach out to our support team.</p>
@endsection
