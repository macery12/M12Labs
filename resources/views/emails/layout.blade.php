<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $subject ?? config('app.name') }}</title>
</head>
@include('emails.partials.styles')
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
