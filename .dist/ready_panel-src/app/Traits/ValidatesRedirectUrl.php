<?php

namespace Everest\Traits;

use Everest\Exceptions\DisplayException;

trait ValidatesRedirectUrl
{
    protected function validateRedirectUrl(string $url, array $allowedHosts): string
    {
        $parsed = parse_url($url);

        if (!$parsed || ($parsed['scheme'] ?? '') !== 'https' || empty($parsed['host'])) {
            throw new DisplayException('Invalid redirect URL.');
        }

        $host = strtolower($parsed['host']);
        $isAllowed = false;
        foreach ($allowedHosts as $allowed) {
            $allowed = strtolower($allowed);
            $pattern = '/(^|\.)' . preg_quote($allowed, '/') . '$/';
            if (preg_match($pattern, $host)) {
                $isAllowed = true;
                break;
            }
        }

        if (!$isAllowed) {
            throw new DisplayException('Unapproved redirect host.');
        }

        return $url;
    }
}
