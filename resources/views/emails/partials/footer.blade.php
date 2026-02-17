@php
    $appName = config('app.name');
    $currentYear = date('Y');
    $supportEmail = config('mail.from.address') ?? 'support@example.com';
    $supportUrl = $supportUrl ?? (config('app.url') ? rtrim(config('app.url'), '/') . '/support' : null);
    $brandPrimary = $brandPrimary ?? '#4F46E5';
    $paragraphStyle = $paragraphStyle ?? "margin:0 0 16px; color:#111827; font-size:15px; line-height:1.6;";
    $mutedStyle = $mutedStyle ?? "margin:8px 0 0; color:#6B7280; font-size:13px; line-height:1.5;";
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
