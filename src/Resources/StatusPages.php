<?php

declare(strict_types=1);

namespace Beacon\Resources;

use Beacon\Beacon;

/** /status-pages — keyed by slug. */
final class StatusPages
{
    public function __construct(private readonly Beacon $client) {}

    /**
     * @param  array<string, scalar>  $params
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>, links: array<string, mixed>}
     */
    public function list(array $params = []): array
    {
        return $this->client->request('GET', '/status-pages', null, $params);
    }

    /**
     * @param  array<string, scalar>  $params
     * @return \Generator<int, array<string, mixed>>
     */
    public function iterate(array $params = []): \Generator
    {
        return $this->client->paginate('/status-pages', $params);
    }

    /** @return array<string, mixed> */
    public function get(string $slug): array
    {
        return $this->client->data('GET', '/status-pages/'.rawurlencode($slug));
    }

    /**
     * @param  array<string, mixed>  $input  name (required), slug, description, timezone, is_public, theme, incident_notifications_enabled
     * @return array<string, mixed>
     */
    public function create(array $input): array
    {
        return $this->client->data('POST', '/status-pages', $input);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function update(string $slug, array $input): array
    {
        return $this->client->data('PATCH', '/status-pages/'.rawurlencode($slug), $input);
    }

    /** Deletes the page and everything on it. */
    public function delete(string $slug): void
    {
        $this->client->request('DELETE', '/status-pages/'.rawurlencode($slug));
    }
}
