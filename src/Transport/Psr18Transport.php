<?php

declare(strict_types=1);

namespace Beacon\Transport;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Use any PSR-18 client (Guzzle 7, Symfony HttpClient's Psr18Client, …).
 * Guzzle: new Psr18Transport($guzzle, new HttpFactory(), new HttpFactory()).
 * Timeouts are whatever the client is configured with.
 */
final class Psr18Transport implements Transport
{
    public function __construct(
        private readonly ClientInterface $client,
        private readonly RequestFactoryInterface $requests,
        private readonly StreamFactoryInterface $streams,
    ) {}

    public function send(string $method, string $url, array $headers, ?string $body, float $timeout): Response
    {
        $request = $this->requests->createRequest($method, $url);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        if ($body !== null) {
            $request = $request->withBody($this->streams->createStream($body));
        }

        try {
            $response = $this->client->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new TransportException('Beacon request failed: '.$e->getMessage(), 0, $e);
        }

        return new Response(
            $response->getStatusCode(),
            array_map(fn (array $values) => implode(', ', $values), $response->getHeaders()),
            (string) $response->getBody(),
        );
    }
}
