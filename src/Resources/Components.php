<?php

declare(strict_types=1);

namespace Beacon\Resources;

final class Components extends PageResource
{
    /**
     * @param  list<int>  $ids  every component id on the page, in display order
     * @return list<array<string, mixed>>
     */
    public function reorder(string $slug, array $ids): array
    {
        return $this->client->data('POST', $this->path($slug, 'reorder'), ['ids' => array_values($ids)]);
    }
}
