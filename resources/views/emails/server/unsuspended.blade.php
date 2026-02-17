@extends('emails.layout')
@include('emails.partials.styles')

@section('content')
    @include('emails.partials.header', [
        'title' => 'Server Restored',
        'subtitle' => 'Your server is active again.'
    ])
    <p style="{{ $paragraphStyle }}">Hello {{ $userName }},</p>
    <p style="{{ $paragraphStyle }}">Good news! Your server <strong>{{ $serverName }}</strong> has been unsuspended and is now active again.</p>
    @component('emails.partials.panel', ['title' => 'Restoration details'])
        @include('emails.partials.key_value_table', [
            'rows' => [
                'Restored At' => $unsuspendedAt,
            ]
        ])
    @endcomponent
    <p style="{{ $paragraphStyle }}">You can now access and manage your server normally.</p>
    <p style="{{ $paragraphStyle }}">Thank you for your patience.</p>
@endsection
