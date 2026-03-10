@extends('emails.layout')

@section('content')
    @include('emails.partials.header', [
        'title' => 'Server Restored',
        'subtitle' => 'Your server is active again.'
    ])
    <p style="margin:0 0 16px; color:#111827; font-size:15px; line-height:1.6;">Hello {{ $userName }},</p>
    <p style="margin:0 0 16px; color:#111827; font-size:15px; line-height:1.6;">Good news! Your server <strong>{{ $serverName }}</strong> has been unsuspended and is now active again.</p>
    @component('emails.partials.panel', ['title' => 'Restoration details'])
        @include('emails.partials.key_value_table', [
            'rows' => [
                'Restored At' => $unsuspendedAt,
            ]
        ])
    @endcomponent
    <p style="margin:0 0 16px; color:#111827; font-size:15px; line-height:1.6;">You can now access and manage your server normally.</p>
    <p style="margin:0 0 16px; color:#111827; font-size:15px; line-height:1.6;">Thank you for your patience.</p>
@endsection
