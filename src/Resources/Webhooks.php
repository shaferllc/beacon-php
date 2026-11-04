<?php

declare(strict_types=1);

namespace Beacon\Resources;

final class Webhooks extends PageResource
{
    /**
     * The signing secret is returned only here (as 'secret'); store it to verify deliveries.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function create(string $slug, array $input): array
    {
        $response = $this->client->request('POST', $this->path($slug), $input);

        return $response['data'] + ['secret' => $response['secret'] ?? null];
    }
}
