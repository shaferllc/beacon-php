<?php

declare(strict_types=1);

namespace Beacon\Transport;

final class Response
{
    /** @var array<string, string> lower-cased header names */
    public readonly array $headers;

    /** @param array<string, string> $headers */
    public function __construct(public readonly int $status, array $headers = [], public readonly string $body = '')
    {
        $this->headers = array_change_key_case($headers, CASE_LOWER);
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
