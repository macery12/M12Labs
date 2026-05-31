<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Invoice {{ $invoiceNumber }}</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700;1,9..40,300&family=DM+Mono:wght@400;500&display=swap');

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'DM Sans', Arial, sans-serif;
            font-size: 13.5px;
            color: #1a1a2e;
            background: #f0f2f7;
            padding: 0;
            margin: 0;
        }

        /* ==============================
           Page Shell
        ============================== */
        .page {
            max-width: 760px;
            margin: 0 auto;
            background: #fdfcfb;
            box-shadow: 0 4px 40px rgba(0,0,0,0.10);
        }

        /* ==============================
           Header
        ============================== */
        .header {
            padding: 44px 52px 36px;
            display: table;
            width: 100%;
            border-bottom: 1px solid #ebebf0;
            position: relative;
        }
        .header::after {
            content: '';
            display: block;
            position: absolute;
            bottom: 0; left: 0;
            width: 80px; height: 3px;
            background: #4f46e5;
        }
        .header-left  { display: table-cell; vertical-align: top; width: 60%; }
        .header-right { display: table-cell; vertical-align: top; text-align: right; width: 40%; }

        .company-logo  { max-height: 44px; max-width: 160px; margin-bottom: 10px; }
        .company-name  { font-size: 22px; font-weight: 700; color: #0f0f1a; letter-spacing: -0.4px; }
        .company-sub   { font-size: 11px; color: #6b7280; margin-top: 6px; line-height: 1.7; font-weight: 400; }

        .doc-label     { font-size: 40px; font-weight: 700; color: #0f0f1a; letter-spacing: -1px; line-height: 1; }
        .doc-number    { font-family: 'DM Mono', monospace; font-size: 12px; color: #6b7280; margin-top: 6px; letter-spacing: 0.03em; }

        .badge {
            display: inline-block;
            margin-top: 12px;
            padding: 3px 11px;
            border-radius: 100px;
            font-size: 10px;
            font-weight: 600;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }
        .badge-active  { background: #ecfdf5; color: #059669; border: 1px solid #a7f3d0; }
        .badge-void    { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; }
        .badge-expired { background: #f8fafc; color: #64748b; border: 1px solid #e2e8f0; }

        /* ==============================
           Body
        ============================== */
        .body { padding: 40px 52px 48px; }

        /* ==============================
           Bill To / Meta row
        ============================== */
        .meta-row { display: table; width: 100%; margin-bottom: 36px; }
        .meta-left  { display: table-cell; vertical-align: top; width: 55%; }
        .meta-right { display: table-cell; vertical-align: top; width: 45%; }

        .meta-section { margin-bottom: 20px; }
        .meta-label {
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.12em;
            color: #8b8ba8;
            margin-bottom: 6px;
        }
        .meta-name  { font-size: 16px; font-weight: 600; color: #0f0f1a; letter-spacing: -0.2px; }
        .meta-email { font-size: 12px; color: #4b5563; margin-top: 3px; font-weight: 400; }
        .meta-addr  { font-size: 11.5px; color: #6b7280; margin-top: 8px; line-height: 1.8; }

        .meta-right-inner { text-align: right; }
        .meta-date  { font-size: 18px; font-weight: 600; color: #0f0f1a; letter-spacing: -0.3px; }
        .meta-mono  { font-family: 'DM Mono', monospace; font-size: 12px; color: #4b5563; }

        /* ==============================
           Line Items
        ============================== */
        .items-table-wrap {
            border: 1px solid #ebebf0;
            border-radius: 10px;
            overflow: hidden;
            margin-bottom: 0;
        }
        table.items { width: 100%; border-collapse: collapse; }
        table.items thead tr { background: #0f0f1a; }
        table.items th {
            padding: 12px 18px;
            text-align: left;
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: #8b8ba8;
        }
        table.items th:last-child { text-align: right; }
        table.items td {
            padding: 16px 18px;
            font-size: 13.5px;
            color: #374151;
            background: #ffffff;
            border-bottom: 1px solid #f5f5f8;
        }
        table.items tbody tr:last-child td { border-bottom: none; }
        table.items td.desc-cell strong { font-size: 14px; font-weight: 600; color: #0f0f1a; }
        table.items td.desc-cell .desc-sub { font-size: 11px; color: #6b7280; margin-top: 2px; }
        table.items td.amount-cell { text-align: right; font-weight: 600; font-size: 14px; color: #0f0f1a; }
        table.items td.discount-cell { color: #059669; }
        table.items td.discount-amount { text-align: right; color: #059669; font-weight: 500; }

        /* ==============================
           Totals
        ============================== */
        .totals-row { display: table; width: 100%; margin-top: 0; }
        .totals-spacer { display: table-cell; width: 50%; }
        .totals-block  { display: table-cell; width: 50%; vertical-align: top; }

        .totals-inner {
            border: 1px solid #ebebf0;
            border-top: none;
            border-radius: 0 0 10px 10px;
            overflow: hidden;
        }
        table.totals { width: 100%; border-collapse: collapse; }
        table.totals td { padding: 10px 18px; font-size: 13px; background: #fafafa; border-bottom: 1px solid #f0f0f5; }
        table.totals .t-lbl { color: #6b7280; font-weight: 400; }
        table.totals .t-amt { text-align: right; color: #374151; }
        table.totals .t-dis { color: #059669; }
        table.totals .grand-row td {
            background: #4f46e5;
            border-bottom: none;
            padding: 13px 18px;
        }
        table.totals .grand-row .t-lbl { color: rgba(255,255,255,0.65); font-weight: 500; font-size: 11px; letter-spacing: 0.06em; text-transform: uppercase; }
        table.totals .grand-row .t-amt { color: #ffffff; font-weight: 700; font-size: 15px; }

        /* ==============================
           Payment Card
        ============================== */
        .pay-card {
            margin-top: 28px;
            border: 1px solid #ebebf0;
            border-radius: 10px;
            padding: 20px 22px;
        }
        .pay-card-title {
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.12em;
            color: #8b8ba8;
            margin-bottom: 14px;
            padding-bottom: 10px;
            border-bottom: 1px solid #f0f0f5;
        }
        .kv-grid { display: table; width: 100%; }
        .kv-row  { display: table-row; }
        .kv-k, .kv-v { display: table-cell; padding: 5px 0; }
        .kv-k { font-size: 11px; color: #8b8ba8; width: 36%; font-weight: 400; }
        .kv-v { font-size: 11px; color: #1f2937; font-weight: 500; }
        .kv-mono { font-family: 'DM Mono', monospace; font-size: 10.5px; color: #4b5563; font-weight: 400; }

        /* ==============================
           Divider
        ============================== */
        .section-gap { height: 28px; }

        /* ==============================
           Footer
        ============================== */
        .footer {
            border-top: 1px solid #f0f0f5;
            margin-top: 36px;
            padding-top: 20px;
            display: table;
            width: 100%;
        }
        .footer-left  { display: table-cell; vertical-align: middle; width: 50%; }
        .footer-right { display: table-cell; vertical-align: middle; width: 50%; text-align: right; }
        .footer-brand { font-size: 12px; font-weight: 600; color: #0f0f1a; }
        .footer-sub   { font-size: 10.5px; color: #6b7280; margin-top: 3px; line-height: 1.7; }
        .footer-note  { font-size: 10.5px; color: #6b7280; text-align: right; line-height: 1.7; }

    </style>
</head>
<body>
<div class="page">

{{-- Header --}}
<div class="header">
    <div class="header-left">
        @if(!empty($companyLogoUrl))
            <img src="{{ $companyLogoUrl }}" alt="{{ $companyName }}" class="company-logo" />
        @else
            <div class="company-name">{{ $companyName }}</div>
        @endif
        @if(!empty($companyAddress))
            <div class="company-sub">
                {{ $companyAddress }}<br>
                @if(!empty($companyCity) || !empty($companyState) || !empty($companyZip))
                    {{ implode(', ', array_filter([$companyCity, $companyState, $companyZip])) }}<br>
                @endif
                @if(!empty($companyCountry)){{ $companyCountry }}<br>@endif
                @if(!empty($companyTaxId))VAT / Tax ID: {{ $companyTaxId }}@endif
            </div>
        @endif
    </div>
    <div class="header-right">
        <div class="doc-label">Invoice</div>
        <div class="doc-number">{{ $invoiceNumber }}</div>
        <div>
            <span class="badge badge-{{ $invoiceStatus }}">{{ ucfirst($invoiceStatus) }}</span>
        </div>
    </div>
</div>

{{-- Body --}}
<div class="body">

    {{-- Bill To & Meta --}}
    <div class="meta-row">
        <div class="meta-left">
            <div class="meta-section">
                <div class="meta-label">Bill To</div>
                @php
                    $billingFullName = trim((string)(($billingFirstName ?? '') . ' ' . ($billingLastName ?? '')));
                    $billingLine = implode(', ', array_filter([$billingCity ?? null, $billingState ?? null, $billingPostalCode ?? null]));
                    $hasBillingAddress = !empty($billingAddressLine1) || !empty($billingLine) || !empty($billingCountry);
                @endphp
                <div class="meta-name">{{ $billingFullName !== '' ? $billingFullName : $userName }}</div>
                <div class="meta-email">{{ $userEmail }}</div>
                @if($hasBillingAddress)
                    <div class="meta-addr">
                        @if(!empty($billingAddressLine1)){{ $billingAddressLine1 }}<br>@endif
                        @if(!empty($billingAddressLine2)){{ $billingAddressLine2 }}<br>@endif
                        @if(!empty($billingLine)){{ $billingLine }}<br>@endif
                        @if(!empty($billingCountry)){{ $billingCountry }}@endif
                    </div>
                @endif
            </div>
        </div>
        <div class="meta-right">
            <div class="meta-right-inner">
                <div class="meta-section">
                    <div class="meta-label">Invoice Date</div>
                    <div class="meta-date">{{ $generatedAt }}</div>
                </div>
                <div class="meta-section">
                    <div class="meta-label">Invoice #</div>
                    <div class="meta-mono">{{ $invoiceNumber }}</div>
                </div>
                @if(!empty($orderId))
                <div class="meta-section">
                    <div class="meta-label">Order Reference</div>
                    <div class="meta-mono">#{{ $orderId }}</div>
                </div>
                @endif
            </div>
        </div>
    </div>

    {{-- Line Items --}}
    <div class="items-table-wrap">
        <table class="items">
            <thead>
                <tr>
                    <th style="width:44%;">Description</th>
                    <th>Billing Cycle</th>
                    <th>Type</th>
                    <th>Amount</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td class="desc-cell">
                        <strong>{{ $productName }}</strong>
                    </td>
                    <td>{{ $billingCycle ?? '—' }}</td>
                    <td>{{ !empty($orderType) ? ucwords(str_replace('_', ' ', $orderType)) : '—' }}</td>
                    <td class="amount-cell">{{ $currency }} {{ number_format($subtotal, 2) }}</td>
                </tr>
                @if(!empty($couponCode) && $discountAmount > 0)
                <tr>
                    <td class="discount-cell" colspan="3">Discount — <em>{{ $couponCode }}</em></td>
                    <td class="discount-amount">−{{ $currency }} {{ number_format($discountAmount, 2) }}</td>
                </tr>
                @endif
            </tbody>
        </table>
    </div>

    {{-- Totals --}}
    <div class="totals-row">
        <div class="totals-spacer"></div>
        <div class="totals-block">
            <div class="totals-inner">
                <table class="totals">
                    @if(!empty($couponCode) && $discountAmount > 0)
                    <tr>
                        <td class="t-lbl">Subtotal</td>
                        <td class="t-amt">{{ $currency }} {{ number_format($subtotal, 2) }}</td>
                    </tr>
                    <tr>
                        <td class="t-lbl t-dis">Discount</td>
                        <td class="t-amt t-dis">−{{ $currency }} {{ number_format($discountAmount, 2) }}</td>
                    </tr>
                    @endif
                    <tr class="grand-row">
                        <td class="t-lbl">Total Due</td>
                        <td class="t-amt">{{ $currency }} {{ number_format($total, 2) }}</td>
                    </tr>
                </table>
            </div>
        </div>
    </div>

    {{-- Payment Details --}}
    @if(!empty($paymentMethod) || !empty($transactionId) || !empty($paymentProcessor))
    <div class="pay-card">
        <div class="pay-card-title">Payment Details</div>
        <div class="kv-grid">
            @if(!empty($paymentMethod))
            <div class="kv-row">
                <div class="kv-k">Method</div>
                <div class="kv-v">{{ $paymentMethod }}</div>
            </div>
            @endif
            @if(!empty($paymentProcessor))
            <div class="kv-row">
                <div class="kv-k">Processor</div>
                <div class="kv-v">{{ $paymentProcessor }}</div>
            </div>
            @endif
            @if(!empty($transactionId))
            <div class="kv-row">
                <div class="kv-k">Transaction ID</div>
                <div class="kv-v kv-mono">{{ $transactionId }}</div>
            </div>
            @endif
        </div>
    </div>
    @endif

    {{-- Footer --}}
    <div class="footer">
        <div class="footer-left">
            <div class="footer-brand">{{ $companyName }}</div>
            <div class="footer-sub">
                @if(!empty($appUrl)){{ $appUrl }}<br>@endif
                @if(!empty($companyTaxId))Tax / VAT ID: {{ $companyTaxId }}@endif
            </div>
        </div>
        <div class="footer-right">
            <div class="footer-note">
                Generated automatically<br>
                {{ $generatedAt }}
            </div>
        </div>
    </div>

</div>
</div>
</body>
</html>