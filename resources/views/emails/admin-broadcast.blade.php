@extends('emails.layout')
@include('emails.partials.styles')

@section('content')
    @include('emails.partials.header', [
        'title' => 'Important Announcement',
        'subtitle' => 'A message from the administration team.'
    ])
    <p style="{{ $paragraphStyle }}">Hello,</p>
    @component('emails.partials.panel')
        {!! nl2br(e($message)) !!}
    @endcomponent
    <p style="{{ $paragraphStyle }}">— {{ $adminName }}</p>
@endsection
