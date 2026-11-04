<?php

declare(strict_types=1);

namespace Beacon\Resources;

final class Incidents extends PageResource
{
    /**
     * @param  array<string, scalar>  $params
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>, links: array<string, mixed>}
     */
    public function listUpdates(string $slug, int $incidentId, array $params = []): array
    {
        return $this->client->request('GET', $this->path($slug, $incidentId.'/updates'), null, $params);
    }

    /** @return array<string, mixed> */
    public function addUpdate(string $slug, int $incidentId, string $message): array
    {
        return $this->client->data('POST', $this->path($slug, $incidentId.'/updates'), ['message' => $message]);
    }
}
