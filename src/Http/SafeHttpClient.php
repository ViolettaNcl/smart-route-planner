<?php

namespace App\Http;

/**
 * Tiny dependency-free HTTP GET helper for serverless portability.
 *
 * The community Vercel PHP runtime has had deployments where ext-curl was
 * unavailable even though local PHP had it. The route planner should degrade
 * gracefully instead of fatalling on curl_init(). We therefore use cURL when
 * the extension exists, and PHP streams only when cURL is unavailable.
 *
 * Important: do not retry the same failed request through streams after cURL
 * already attempted it. On serverless platforms that used to double every
 * upstream timeout (Nominatim/OSRM), which could push a normal route request
 * beyond Vercel's function duration limit and make the browser see a generic
 * network error instead of a JSON response.
 */
final class SafeHttpClient
{
    /**
     * @param list<string> $headers
     */
    public static function get(string $url, int $timeoutSeconds = 5, array $headers = []): ?string
    {
        if (function_exists('curl_init')) {
            return self::getWithCurl($url, $timeoutSeconds, $headers);
        }

        return self::getWithStreams($url, $timeoutSeconds, $headers);
    }

    /**
     * @param list<string> $headers
     */
    private static function getWithCurl(string $url, int $timeoutSeconds, array $headers): ?string
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => $timeoutSeconds,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_HTTPHEADER => $headers,
        ];

        // Some serverless environments have flaky IPv6 egress. Prefer IPv4
        // when the cURL build supports the option, because all upstreams used
        // by this project are reachable over IPv4 as well.
        if (defined('CURLOPT_IPRESOLVE') && defined('CURL_IPRESOLVE_V4')) {
            $options[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4;
        }

        curl_setopt_array($ch, $options);
        $body = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $error !== '' || $status < 200 || $status >= 300) {
            return null;
        }

        return (string) $body;
    }

    /**
     * @param list<string> $headers
     */
    private static function getWithStreams(string $url, int $timeoutSeconds, array $headers): ?string
    {
        if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
            return null;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $timeoutSeconds,
                'ignore_errors' => true,
                'header' => $headers !== [] ? implode("\r\n", $headers) . "\r\n" : '',
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            return null;
        }

        $status = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#i', $line, $matches)) {
                $status = (int) $matches[1];
            }
        }

        if ($status !== 0 && ($status < 200 || $status >= 300)) {
            return null;
        }

        return $body;
    }
}
