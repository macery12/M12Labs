@extends('emails.layout')

@section('content')
    @include('emails.partials.header', [
        'title' => $isRenewal ? 'Payment Received - Server Renewal' : 'Payment Received',
        'subtitle' => 'Thanks for your payment. Your services remain active.'
    ])
    <p style="margin:0 0 16px; color:#111827; font-size:15px; line-height:1.6;">Hello {{ $userName }},</p>
    <p style="margin:0 0 16px; color:#111827; font-size:15px; line-height:1.6;">We've successfully received your payment{{ $isRenewal ? ' for your server renewal' : '' }}. Your services will continue without interruption.</p>
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
    <p style="margin:0 0 16px; color:#111827; font-size:15px; line-height:1.6;">If you have any questions about this payment, please contact our support team.</p>
@endsection
