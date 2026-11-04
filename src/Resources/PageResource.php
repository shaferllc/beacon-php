<?php

declare(strict_types=1);

namespace Beacon\Resources;

use Beacon\Beacon;

/**
 * A collection nested under a status page: /status-pages/{slug}/{name}.
 * Items are the API's JSON objects as associative arrays (see openapi.json for fields).
 */
class PageResource
{
    public function __construct(protected readonly Beacon $client, protected readonly string $name) {}

    /**
     * One page of results: ['data' => list, 'meta' => [... 'next_cursor'], 'links' => [...]].
     *
     * @param  array<string, scalar>  $params  cursor, per_page and filters (state, confirmed)
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>, links: array<string, mixed>}
     */
    public function list(string $slug, array $params = []): array
    {
        return $this->client->request('GET', $this->path($slug), null, $params);
    }

    /**
     * Every item across all pages, fetched lazily.
     *
     * @param  array<string, scalar>  $params
     * @return \Generator<int, array<string, mixed>>
     */
    public function iterate(string $slug, array $params = []): \Generator
    {
        return $this->client->paginate($this->path($slug), $params);
    }

    /** @return array<string, mixed> */
    public function get(string $slug, int $id): array
    {
        return $this->client->data('GET', $this->path($slug, $id));
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function create(string $slug, array $input): array
    {
        return $this->client->data('POST', $this->path($slug), $input);
    }

    /**
     * @param  array<string, mixed>  $input  only the fields to change
     * @return array<string, mixed>
     */
    public function update(string $slug, int $id, array $input): array
    {
        return $this->client->data('PATCH', $this->path($slug, $id), $input);
    }

    public function delete(string $slug, int $id): void
    {
        $this->client->request('DELETE', $this->path($slug, $id));
    }

    protected function path(string $slug, int|string|null $suffix = null): string
    {
        return '/status-pages/'.rawurlencode($slug).'/'.$this->name.($suffix === null ? '' : '/'.$suffix);
    }
}
