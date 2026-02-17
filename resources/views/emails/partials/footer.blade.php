@php
    $appName = config('app.name');
    $currentYear = date('Y');
    $supportEmail = config('mail.from.address') ?? 'support@example.com';
    $supportUrl = $supportUrl ?? (config('app.url') ? rtrim(config('app.url'), '/') . '/support' : null);
@endphp
@include('emails.partials.divider')
<p style="{{ $paragraphStyle }}; margin:0; font-size:14px;">
    Need help? Email us at <a href="mailto:{{ $supportEmail }}" style="color: {{ $brandPrimary }}; text-decoration: underline;">{{ $supportEmail }}</a>
    @if($supportUrl)
        or visit <a href="{{ $supportUrl }}" style="color: {{ $brandPrimary }}; text-decoration: underline;">our support center</a>.
    @endif
</p>
<p style="{{ $mutedStyle }}; margin-top:12px;">
    You’re receiving this email because you have an account with {{ $appName }}. If you didn’t expect this email, you can safely ignore it.
</p>
<p style="{{ $mutedStyle }}; margin-top:8px;">© {{ $currentYear }} {{ $appName }}. All rights reserved.</p>
