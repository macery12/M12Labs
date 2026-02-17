@extends('emails.layout')

@section('content')
    @include('emails.partials.header', [
        'title' => $isRenewal ? 'Payment Failed - Server Renewal' : 'Payment Failed',
        'subtitle' => 'We were unable to process your recent payment.'
    ])
    <p style="{{ $paragraphStyle }}">Hello {{ $userName }},</p>
    <p style="{{ $paragraphStyle }}">We couldn’t process your recent payment{{ $isRenewal ? ' for your server renewal' : '' }}. Please update your payment information to avoid service interruption.</p>
    @component('emails.partials.panel', ['title' => 'Payment attempt'])
        @include('emails.partials.key_value_table', [
            'rows' => [
                'Amount' => $currency . ' ' . $amount,
                'Payment Method' => $paymentMethod !== 'Unknown' ? $paymentMethod : null,
                'Invoice ID' => $invoiceId !== 'N/A' ? $invoiceId : null,
                'Reason' => $reason,
            ]
        ])
    @endcomponent
    @include('emails.partials.button', [
        'url' => $retryUrl,
        'text' => 'Update Payment Method'
    ])
    <p style="{{ $paragraphStyle }}">To avoid service interruption{{ $isRenewal ? ' and potential server suspension' : '' }}, please retry the payment as soon as possible. If you continue to experience issues, contact support for assistance.</p>
@endsection
