@extends('emails.layout')

@section('content')
    @include('emails.partials.header', [
        'title' => 'Server Created Successfully',
        'subtitle' => 'Your new server is being prepared.'
    ])
    <p style="{{ $paragraphStyle }}">Hello {{ $userName }},</p>
    <p style="{{ $paragraphStyle }}">Your server <strong>{{ $serverName }}</strong> has been created successfully and is being set up.</p>
    @component('emails.partials.panel', ['title' => 'Server details'])
        @include('emails.partials.key_value_table', [
            'rows' => [
                'Server ID' => $serverId,
                'Location' => $nodeLocation,
            ]
        ])
    @endcomponent
    @include('emails.partials.button', ['url' => $serverUrl, 'text' => 'View Server'])
    <p style="{{ $paragraphStyle }}">Thank you for using our service.</p>
@endsection
