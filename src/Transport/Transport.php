<?php

declare(strict_types=1);

namespace Beacon\Transport;

/** Sends one HTTP request. Network failures throw TransportException. */
interface Transport
{
    /**
     * @param  array<string, string>  $headers
     */
    public function send(string $method, string $url, array $headers, ?string $body, float $timeout): Response;
}
