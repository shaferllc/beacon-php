<?php

declare(strict_types=1);

namespace Beacon\Tests;

use Beacon\Transport\Response;
use Beacon\Transport\Transport;

/** Records requests and replays queued responses. */
final class FakeTransport implements Transport
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: mixed}> */
    public array $requests = [];

    /** @var list<Response> */
    private array $queue;

    public function __construct(Response ...$responses)
    {
        $this->queue = $responses;
    }

    public static function json(mixed $body, int $status = 200, array $headers = []): Response
    {
        return new Response($status, $headers + ['Content-Type' => 'application/json'], json_encode($body));
    }

    public function send(string $method, string $url, array $headers, ?string $body, float $timeout): Response
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body === null ? null : json_decode($body, true)];

        return array_shift($this->queue) ?? throw new \LogicException("Unexpected request: $method $url");
    }

    /** @return list<string> "METHOD path?query" */
    public function lines(): array
    {
        return array_map(function (array $r): string {
            $parts = parse_url($r['url']);

            return $r['method'].' '.$parts['path'].(isset($parts['query']) ? '?'.$parts['query'] : '');
        }, $this->requests);
    }
}
