<?php

namespace App\Support;

/**
 * Resolves the canonical origin from an explicit override, hosting metadata,
 * or the public Render deployment.
 */
final class PublicUrl
{
    private const DEFAULT_URL = 'https://smart-route-planner-wiwk.onrender.com';

    public static function resolve(): string
    {
        $explicit = getenv('APP_PUBLIC_URL');
        $normalized = self::normalize(is_string($explicit) ? $explicit : '');
        if ($normalized !== null) {
            return $normalized;
        }

        $renderPublicUrl = getenv('RENDER_EXTERNAL_URL');
        $normalized = self::normalize(is_string($renderPublicUrl) ? $renderPublicUrl : '');
        if ($normalized !== null) {
            return $normalized;
        }

        return self::DEFAULT_URL;
    }

    private static function normalize(string $candidate): ?string
    {
        $candidate = rtrim(trim($candidate), '/');
        if ($candidate === '' || filter_var($candidate, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $scheme = strtolower((string) parse_url($candidate, PHP_URL_SCHEME));
        $host = parse_url($candidate, PHP_URL_HOST);
        if (!in_array($scheme, ['http', 'https'], true) || !is_string($host) || $host === '') {
            return null;
        }

        return $candidate;
    }
}
