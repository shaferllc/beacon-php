<?php

declare(strict_types=1);

namespace Beacon;

/** Beacon webhook signatures: X-Beacon-Signature = "sha256=" . hmac_sha256(timestamp . "." . rawBody, secret). */
final class Webhook
{
    public const DEFAULT_TOLERANCE = 300;

    public static function sign(string $rawBody, string $secret, int|string $timestamp): string
    {
        return 'sha256='.hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);
    }

    /**
     * @param  string  $rawBody  the exact request body bytes, before JSON decoding
     * @param  array<string, string|list<string>>  $headers  any casing; PSR-7 / Symfony style arrays are fine
     */
    public static function verify(string $rawBody, array $headers, string $secret, int $toleranceSeconds = self::DEFAULT_TOLERANCE, ?int $now = null): bool
    {
        $timestamp = self::header($headers, 'x-beacon-timestamp');
        $signature = self::header($headers, 'x-beacon-signature');

        if ($secret === '' || $timestamp === null || $signature === null || ! ctype_digit($timestamp)) {
            return false;
        }
        if (abs(($now ?? time()) - (int) $timestamp) > $toleranceSeconds) {
            return false;
        }

        return hash_equals(self::sign($rawBody, $secret, $timestamp), $signature);
    }

    /** @param array<string, string|list<string>> $headers */
    private static function header(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            // Accept both "X-Beacon-Timestamp" and $_SERVER-style "HTTP_X_BEACON_TIMESTAMP".
            $key = strtolower(str_replace('_', '-', (string) preg_replace('/^HTTP_/i', '', (string) $key)));
            if ($key === $name) {
                $value = is_array($value) ? ($value[0] ?? null) : $value;

                return is_string($value) ? $value : null;
            }
        }

        return null;
    }
}
