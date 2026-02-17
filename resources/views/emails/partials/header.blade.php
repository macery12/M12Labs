@php
    $appName = config('app.name');
    $logoUrl = $logoUrl ?? null;
    $title = $title ?? $appName;
    $subtitle = $subtitle ?? null;
@endphp
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-spacing:0; margin:0 0 24px;">
    <tr>
        <td align="center" style="padding: 0 0 8px;">
            @if($logoUrl)
                <img src="{{ $logoUrl }}" alt="{{ $appName }} logo" style="max-width: 120px; height: auto; display: block; margin: 0 auto 12px;">
            @else
                <div style="display: inline-block; padding: 10px 14px; background-color:#4F46E5; color: #ffffff; border-radius: 10px; font-weight: 700; letter-spacing: 0.3px; font-size: 14px;">
                    {{ $appName }}
                </div>
            @endif
            <h1 style="margin:0 0 12px; font-size:22px; line-height:1.3; color:#111827; font-weight:700; margin-top:12px;">{{ $title }}</h1>
            @if($subtitle)
                <p style="margin:8px 0 0; color:#6B7280; font-size:13px; line-height:1.5; margin:0;">{{ $subtitle }}</p>
            @endif
        </td>
    </tr>
</table>
