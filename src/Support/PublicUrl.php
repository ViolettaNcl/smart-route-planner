<?php

namespace App\Support;

/**
 * Resolves the canonical production origin without tying releases to one
 * generated Vercel hostname. APP_PUBLIC_URL remains the explicit override;
 * Vercel's production-domain variable lets a later custom domain become the
 * canonical URL without another code change.
 */
final class PublicUrl
{
    private const DEFAULT_URL = 'https://smart-route-planner-violettancls-projects.vercel.app';

    public static function resolve(): string
    {
        $explicit = getenv('APP_PUBLIC_URL');
        $normalized = self::normalize(is_string($explicit) ? $explicit : '');
        if ($normalized !== null) {
            return $normalized;
        }

        $vercelProduction = getenv('VERCEL_PROJECT_PRODUCTION_URL');
        $candidate = is_string($vercelProduction) ? trim($vercelProduction) : '';
        if ($candidate !== '' && !str_contains($candidate, '://')) {
            $candidate = 'https://' . $candidate;
        }
        $normalized = self::normalize($candidate);

        return $normalized ?? self::DEFAULT_URL;
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
