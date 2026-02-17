@php
    $panelTitle = $title ?? null;
@endphp
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-spacing:0; margin: 0 0 16px;">
    <tr>
        <td style="background-color:#f9fafb; border:1px solid {{ $borderColor }}; border-radius:10px; padding:16px 18px;">
            @if($panelTitle)
                <p style="{{ $headingStyle }}; font-size:18px; margin-bottom:10px;">{{ $panelTitle }}</p>
            @endif
            <div style="{{ $paragraphStyle }}; margin:0;">
                {{ $slot }}
            </div>
        </td>
    </tr>
</table>
