@php
    // Brand colors
    $brandPrimary = $brandPrimary ?? '#4F46E5';
    $brandDark = $brandDark ?? '#111827';
    $brandMuted = $brandMuted ?? '#6B7280';
    $backgroundColor = $backgroundColor ?? '#f3f4f6';
    $cardBackground = $cardBackground ?? '#ffffff';
    $borderColor = $borderColor ?? '#e5e7eb';

    // Typography
    $fontFamily = $fontFamily ?? "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif";
    $headingStyle = $headingStyle ?? "margin:0 0 12px; font-size:22px; line-height:1.3; color:$brandDark; font-weight:700;";
    $paragraphStyle = $paragraphStyle ?? "margin:0 0 16px; color:$brandDark; font-size:15px; line-height:1.6;";
    $mutedStyle = $mutedStyle ?? "margin:8px 0 0; color:$brandMuted; font-size:13px; line-height:1.5;";

    // Preheader
    $preheaderText = isset($preheader) ? trim($preheader) : '';
@endphp
