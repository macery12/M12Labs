@extends('emails.layout')

@section('content')
    @include('emails.partials.header', [
        'title' => 'Important Announcement',
        'subtitle' => 'A message from the administration team.'
    ])
    <p style="margin:0 0 16px; color:#111827; font-size:15px; line-height:1.6;">Hello,</p>
    @component('emails.partials.panel')
        {!! nl2br(e($message)) !!}
    @endcomponent
    <p style="margin:0 0 16px; color:#111827; font-size:15px; line-height:1.6;">— {{ $adminName }}</p>
@endsection
