@props(['rows' => [], 'labelWidth' => '38%'])
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-spacing:0; margin: 4px 0 0;">
    @foreach($rows as $label => $value)
        @if(isset($value) && $value !== '')
            <tr>
                <td style="padding: 8px 8px 6px 0; width: {{ $labelWidth }}; font-weight:700; color: {{ $brandDark }}; font-size:14px; line-height:1.4;">{{ $label }}</td>
                <td style="padding: 8px 0 6px; color: {{ $brandDark }}; font-size:14px; line-height:1.5; word-break: break-word;">
                    @if($value instanceof \Illuminate\Support\HtmlString)
                        {!! $value !!}
                    @else
                        {{ $value }}
                    @endif
                </td>
            </tr>
        @endif
    @endforeach
</table>
