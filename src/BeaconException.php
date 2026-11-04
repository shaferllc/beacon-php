<?php

declare(strict_types=1);

namespace Beacon;

use Beacon\Transport\Response;

/**
 * A non-2xx API response. $errorCode is the API's stable code: unauthenticated, insufficient_scope,
 * not_found, validation_failed, plan_limit_reached, workspace_read_only, rate_limited (or http_error).
 */
class BeaconException extends \RuntimeException
{
    /**
     * @param  array<string, list<string>>  $errors  field => messages (validation_failed)
     * @param  array<string, mixed>|null  $body  the decoded error envelope
     */
    public function __construct(
        string $message,
        public readonly int $status,
        public readonly string $errorCode,
        public readonly array $errors = [],
        public readonly ?int $retryAfter = null,
        public readonly ?array $body = null,
    ) {
        parent::__construct($message, $status);
    }

    public static function fromResponse(Response $response): self
    {
        $body = json_decode($response->body, true);
        $body = is_array($body) ? $body : null;
        $fallback = match ($response->status) {
            429 => 'rate_limited',
            404 => 'not_found',
            default => 'http_error',
        };

        return new self(
            (string) ($body['message'] ?? "Beacon API returned HTTP {$response->status}"),
            $response->status,
            (string) ($body['code'] ?? $fallback),
            is_array($body['errors'] ?? null) ? $body['errors'] : [],
            self::retryAfter($response->header('retry-after')),
            $body,
        );
    }

    public static function retryAfter(?string $header): ?int
    {
        if ($header === null || $header === '') {
            return null;
        }
        if (is_numeric($header)) {
            return max(0, (int) $header);
        }
        $time = strtotime($header);

        return $time === false ? null : max(0, $time - time());
    }
}
