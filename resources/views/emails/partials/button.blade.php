@props(['url', 'text' => 'View', 'color' => null, 'align' => 'center', 'showFallback' => true])
@php
    $buttonColor = $color ?? $brandPrimary;
@endphp
<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="border-spacing:0; margin: 8px 0 8px;">
    <tr>
        <td align="{{ $align }}">
            <table role="presentation" cellpadding="0" cellspacing="0" style="border-spacing:0;">
                <tr>
                    <td align="center" bgcolor="{{ $buttonColor }}" style="border-radius: 10px;">
                        <a href="{{ $url }}" style="display:inline-block; padding: 12px 22px; font-size: 15px; color:#ffffff; text-decoration:none; font-weight:700; letter-spacing:0.3px; border-radius:10px;">
                            {{ $text }}
                        </a>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
@if($showFallback)
    <p style="{{ $mutedStyle }} word-break: break-word; margin-top:8px;">
        If the button doesn’t work, copy and paste this link:<br>
        <a href="{{ $url }}" style="color: {{ $brandPrimary }}; text-decoration: underline; word-break: break-word;">{{ $url }}</a>
    </p>
@endif
