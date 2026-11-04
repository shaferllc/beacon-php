<?php

declare(strict_types=1);

namespace Beacon;

use Beacon\Resources\Components;
use Beacon\Resources\Incidents;
use Beacon\Resources\PageResource;
use Beacon\Resources\StatusPages;
use Beacon\Resources\Webhooks;
use Beacon\Transport\CurlTransport;
use Beacon\Transport\Response;
use Beacon\Transport\Transport;
use Beacon\Transport\TransportException;

/**
 * Beacon API client.
 *
 *   $beacon = new Beacon\Beacon(getenv('BEACON_API_TOKEN'), pageSlug: 'acme');
 *   $beacon->createIncident(['title' => 'Payments down', 'impact' => 'major']);
 *   $beacon->monitors()->create('acme', ['name' => 'API', 'url' => 'https://api.acme.com/health']);
 */
class Beacon
{
    public const VERSION = '1.0.0';

    public const DEFAULT_URL = 'https://usebeacon.pro';

    public readonly string $baseUrl;

    private readonly Transport $transport;

    /** @var array<string, object> */
    private array $resources = [];

    /**
     * @param  string  $token  workspace API token (bchk_…)
     * @param  string|null  $pageSlug  default page for the ingest helpers
     * @param  int  $maxRetries  429s are retried after Retry-After for every method; 5xx / network errors only for GET
     */
    public function __construct(
        private readonly string $token,
        ?string $baseUrl = null,
        public readonly ?string $pageSlug = null,
        private readonly float $timeout = 10.0,
        private readonly int $maxRetries = 2,
        ?Transport $transport = null,
    ) {
        if ($token === '') {
            throw new \InvalidArgumentException('Beacon: an API token is required.');
        }
        $this->baseUrl = rtrim($baseUrl ?: self::DEFAULT_URL, '/');
        $this->transport = $transport ?? new CurlTransport;
    }

    // ------------------------------------------------------------------ REST resources

    public function statusPages(): StatusPages
    {
        return $this->resources[__FUNCTION__] ??= new StatusPages($this);
    }

    public function components(): Components
    {
        return $this->resources[__FUNCTION__] ??= new Components($this, 'components');
    }

    /** Fields: see MonitorInput in openapi.json (type http|heartbeat|tcp|ping|dns, allow_cold_start, check_window_start/end "HH:MM", …). */
    public function monitors(): PageResource
    {
        return $this->resources[__FUNCTION__] ??= new PageResource($this, 'monitors');
    }

    public function incidents(): Incidents
    {
        return $this->resources[__FUNCTION__] ??= new Incidents($this, 'incidents');
    }

    public function maintenances(): PageResource
    {
        return $this->resources[__FUNCTION__] ??= new PageResource($this, 'maintenances');
    }

    /** update() replaces the component filter: ['component_ids' => [...]] (empty = all components). */
    public function subscribers(): PageResource
    {
        return $this->resources[__FUNCTION__] ??= new PageResource($this, 'subscribers');
    }

    public function webhooks(): Webhooks
    {
        return $this->resources[__FUNCTION__] ??= new Webhooks($this, 'webhooks');
    }

    /** Statuspage.io imports: list, iterate, get and create only. */
    public function imports(): PageResource
    {
        return $this->resources[__FUNCTION__] ??= new PageResource($this, 'imports');
    }

    // ------------------------------------------------------------------ Ingest (flat JSON, string ids)

    /**
     * @param  array{title: string, impact?: string, state?: string, message?: string, component_ids?: list<int|string>}  $input
     * @return array<string, mixed>
     */
    public function createIncident(array $input, ?string $pageSlug = null): array
    {
        return $this->request('POST', '/pages/'.rawurlencode($this->slug($pageSlug)).'/incidents', $input);
    }

    /**
     * @param  array{title?: string, impact?: string, state?: string, message?: string|null, resolved?: bool}  $input
     * @return array<string, mixed>
     */
    public function updateIncident(int|string $incidentId, array $input): array
    {
        return $this->request('PATCH', '/incidents/'.rawurlencode((string) $incidentId), $input);
    }

    /** @return array<string, mixed> */
    public function addIncidentUpdate(int|string $incidentId, string $message): array
    {
        return $this->request('POST', '/incidents/'.rawurlencode((string) $incidentId).'/updates', ['message' => $message]);
    }

    /** Resolves the incident (restoring linked components), with an optional closing message. */
    public function resolveIncident(int|string $incidentId, ?string $message = null): array
    {
        return $this->updateIncident($incidentId, array_filter(['resolved' => true, 'message' => $message], fn ($v) => $v !== null));
    }

    /**
     * @param  array{status: string, description?: string|null}  $input
     * @return array<string, mixed>
     */
    public function updateComponent(int|string $componentId, array $input, ?string $pageSlug = null): array
    {
        return $this->request('PATCH', '/pages/'.rawurlencode($this->slug($pageSlug)).'/components/'.rawurlencode((string) $componentId), $input);
    }

    /** Heartbeat check-in with this client's base URL. Accepts a token or a full heartbeat_url. */
    public function heartbeat(string $tokenOrUrl, string $method = 'POST'): void
    {
        self::ping($tokenOrUrl, $this->baseUrl, $method, $this->timeout, $this->transport);
    }

    /** Heartbeat check-in without an API token (the URL is the secret). Throws BeaconException on non-2xx. */
    public static function ping(string $tokenOrUrl, ?string $baseUrl = null, string $method = 'POST', float $timeout = 10.0, ?Transport $transport = null): void
    {
        $url = preg_match('#^https?://#i', $tokenOrUrl)
            ? $tokenOrUrl
            : rtrim($baseUrl ?: self::DEFAULT_URL, '/').'/api/v1/heartbeat/'.rawurlencode($tokenOrUrl);

        $response = ($transport ?? new CurlTransport)->send(strtoupper($method), $url, [
            'Accept' => 'application/json',
            'User-Agent' => 'beacon-php/'.self::VERSION,
        ], null, $timeout);

        if ($response->status >= 300) {
            throw BeaconException::fromResponse($response);
        }
    }

    // ------------------------------------------------------------------ Plumbing

    /**
     * Raw request against /api/v1. Returns the decoded JSON body ([] for 204).
     *
     * @param  array<string, mixed>|null  $body
     * @param  array<string, scalar|null>  $query
     * @return array<mixed>
     */
    public function request(string $method, string $path, ?array $body = null, array $query = []): array
    {
        $query = array_map(fn ($v) => is_bool($v) ? (int) $v : $v, array_filter($query, fn ($v) => $v !== null));
        $url = $this->baseUrl.'/api/v1'.$path.($query ? '?'.http_build_query($query) : '');
        $headers = [
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
            'User-Agent' => 'beacon-php/'.self::VERSION,
        ];
        $payload = null;
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
            $payload = json_encode((object) $body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        }

        for ($attempt = 0; ; $attempt++) {
            try {
                $response = $this->transport->send($method, $url, $headers, $payload, $this->timeout);
            } catch (TransportException $e) {
                if ($method === 'GET' && $attempt < $this->maxRetries) {
                    $this->pause($attempt, null);

                    continue;
                }
                throw $e;
            }

            if ($response->status >= 200 && $response->status < 300) {
                return $this->decode($response);
            }

            $retryable = $response->status === 429 || ($response->status >= 500 && $method === 'GET');
            if ($retryable && $attempt < $this->maxRetries) {
                $this->pause($attempt, BeaconException::retryAfter($response->header('retry-after')));

                continue;
            }

            throw BeaconException::fromResponse($response);
        }
    }

    /**
     * Unwraps {"data": …}.
     *
     * @param  array<string, mixed>|null  $body
     * @return array<mixed>
     */
    public function data(string $method, string $path, ?array $body = null): array
    {
        return $this->request($method, $path, $body)['data'] ?? [];
    }

    /**
     * @param  array<string, scalar|null>  $params
     * @return \Generator<int, array<string, mixed>>
     */
    public function paginate(string $path, array $params = []): \Generator
    {
        do {
            $page = $this->request('GET', $path, null, $params);
            yield from $page['data'] ?? [];
            $params['cursor'] = $page['meta']['next_cursor'] ?? null;
        } while ($params['cursor'] !== null);
    }

    /** @return array<mixed> */
    private function decode(Response $response): array
    {
        if ($response->status === 204 || trim($response->body) === '') {
            return [];
        }
        $decoded = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }

    private function pause(int $attempt, ?int $retryAfter): void
    {
        usleep($retryAfter !== null ? min($retryAfter, 60) * 1_000_000 : 500_000 * 2 ** $attempt);
    }

    private function slug(?string $pageSlug): string
    {
        return $pageSlug ?? $this->pageSlug
            ?? throw new \InvalidArgumentException('Beacon: pass a page slug or set pageSlug on the client.');
    }
}
