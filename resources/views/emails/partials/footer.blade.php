@php
    $appName = config('app.name');
    $currentYear = date('Y');
    /** @var \Everest\Services\Email\EmailSettingsReader $emailSettings */
    $emailSettings = app(\Everest\Services\Email\EmailSettingsReader::class);
    $emailTransport = $emailSettings->transport();
    $supportEmail = $emailSettings->get("settings::modules:email:{$emailTransport}:reply_to")
        ?: $emailSettings->get("settings::modules:email:{$emailTransport}:from_email")
        ?: (config('mail.from.address') ?: 'support@example.com');
@endphp
@include('emails.partials.divider')
<p style="margin:0 0 16px; color:#111827; font-size:14px; line-height:1.6;">
    Need help? Email us at <a href="mailto:{{ $supportEmail }}" style="color:#4F46E5; text-decoration: underline;">{{ $supportEmail }}</a>
</p>
<p style="margin:8px 0 0; color:#6B7280; font-size:13px; line-height:1.5; margin-top:12px;">
    You’re receiving this email because you have an account with {{ $appName }}. If you didn’t expect this email, you can safely ignore it.
</p>
<p style="margin:8px 0 0; color:#6B7280; font-size:13px; line-height:1.5; margin-top:8px;">© {{ $currentYear }} {{ $appName }}. All rights reserved.</p>
