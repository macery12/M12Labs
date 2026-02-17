<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $subject ?? config('app.name') }}</title>
</head>
@php
    $brandPrimary = $brandPrimary ?? '#4F46E5';
    $brandDark = $brandDark ?? '#111827';
    $brandMuted = $brandMuted ?? '#6B7280';
    $backgroundColor = $backgroundColor ?? '#f3f4f6';
    $cardBackground = $cardBackground ?? '#ffffff';
    $borderColor = $borderColor ?? '#e5e7eb';
    $fontFamily = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif";
    $headingStyle = "margin:0 0 12px; font-size:22px; line-height:1.3; color:$brandDark; font-weight:700;";
    $paragraphStyle = "margin:0 0 16px; color:$brandDark; font-size:15px; line-height:1.6;";
    $mutedStyle = "margin:8px 0 0; color:$brandMuted; font-size:13px; line-height:1.5;";
    $preheaderText = trim($preheader ?? '');
@endphp
<body style="margin:0; padding:0; background-color:{{ $backgroundColor }}; font-family: {{ $fontFamily }}; color: {{ $brandDark }};">
    @if(!empty($preheaderText))
        <div style="display:none; max-height:0px; overflow:hidden; font-size:1px; line-height:1px; color:{{ $backgroundColor }}; opacity:0;">
            {{ $preheaderText }}
        </div>
    @endif
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color: {{ $backgroundColor }}; width: 100%; border-spacing:0;">
        <tr>
            <td align="center" style="padding: 24px;">
                <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="max-width: 600px; width:100%; border-spacing:0;">
                    <tr>
                        <td style="padding: 0 0 12px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color: {{ $cardBackground }}; border:1px solid {{ $borderColor }}; border-radius: 12px; overflow:hidden; width:100%; border-spacing:0;">
                                <tr>
                                    <td style="padding: 32px 32px 0;">
                                        @yield('content')
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding: 0 32px 32px;">
                                        @include('emails.partials.footer')
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
