@extends('emails.layout')

@section('content')
    @include('emails.partials.header', [
        'title' => 'Server Created Successfully',
        'subtitle' => 'Your new server is being prepared.'
    ])
    <p style="margin:0 0 16px; color:#111827; font-size:15px; line-height:1.6;">Hello {{ $userName }},</p>
    <p style="margin:0 0 16px; color:#111827; font-size:15px; line-height:1.6;">Your server <strong>{{ $serverName }}</strong> has been created successfully and is being set up.</p>
    @component('emails.partials.panel', ['title' => 'Server details'])
        @include('emails.partials.key_value_table', [
            'rows' => [
                'Server ID' => $serverId,
                'Location' => $nodeLocation,
            ]
        ])
    @endcomponent
    @include('emails.partials.button', ['url' => $serverUrl, 'text' => 'View Server'])
    <p style="margin:0 0 16px; color:#111827; font-size:15px; line-height:1.6;">Thank you for using our service.</p>
@endsection
