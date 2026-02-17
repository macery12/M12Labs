@extends('emails.layout')
@include('emails.partials.styles')

@section('content')
    @include('emails.partials.header', [
        'title' => $isRenewal ? 'Payment Received - Server Renewal' : 'Payment Received',
        'subtitle' => 'Thanks for your payment. Your services remain active.'
    ])
    <p style="{{ $paragraphStyle }}">Hello {{ $userName }},</p>
    <p style="{{ $paragraphStyle }}">We've successfully received your payment{{ $isRenewal ? ' for your server renewal' : '' }}. Your services will continue without interruption.</p>
    @component('emails.partials.panel', ['title' => 'Payment details'])
        @php
            $discountLabel = $couponCode ? "Discount ({$couponCode})" : 'Discount';
        @endphp
        @include('emails.partials.key_value_table', [
            'rows' => [
                'Amount' => $currency . ' ' . $amount,
                'Original Amount' => ($originalAmount && $discountAmount) ? $currency . ' ' . $originalAmount : null,
                $discountLabel => ($originalAmount && $discountAmount) ? '-' . $currency . ' ' . $discountAmount : null,
                'Payment Method' => $paymentMethod,
                'Billing Cycle' => $billingCycle ?: null,
                'Transaction Date' => $transactionDate,
                'Invoice ID' => $invoiceId !== 'N/A' ? $invoiceId : null,
            ]
        ])
    @endcomponent
    @include('emails.partials.button', [
        'url' => url('/billing'),
        'text' => 'View Billing History'
    ])
    <p style="{{ $paragraphStyle }}">If you have any questions about this payment, please contact our support team.</p>
@endsection
