# beacon-status/beacon-php

PHP 8.2+ client for [Beacon](https://usebeacon.pro) status pages: the full REST API v1, the incident ingest endpoints, heartbeat pings and webhook signature verification.

- No Composer dependencies — only `ext-json` and `ext-curl`.
- Bring your own PSR-18 client (Guzzle, Symfony) via `Psr18Transport` if you prefer.
- Cursor pagination generators, `BeaconException` with the API's `errorCode`, `status`, field `errors` and `retryAfter`.

Using Laravel? See [`beacon-status/laravel`](../laravel) for the service provider, facade, artisan command, scheduler macro and webhook middleware.

> **Not yet published to Packagist.** Until it is, install from GitHub with a Composer `vcs` repository (or a `path` repository pointing at a local clone):
>
> ```jsonc
> // composer.json
> "repositories": [
>     { "type": "vcs", "url": "https://github.com/shaferllc/beacon-php" }
> ],
> "require": { "beacon-status/beacon-php": "^1.0" }
> ```

## Setup

```php
use Beacon\Beacon;

$beacon = new Beacon(
    token: getenv('BEACON_API_TOKEN'),   // bchk_… (Settings → API tokens)
    baseUrl: getenv('BEACON_URL') ?: null, // defaults to https://usebeacon.pro
    pageSlug: 'acme',                      // default page for the ingest helpers
);
```

Other options: `timeout` (seconds, default 10), `maxRetries` (default 2: 429s are retried after `Retry-After` for every method, 5xx and network errors only for GETs) and `transport`.

## Incidents and components (ingest)

```php
$incident = $beacon->createIncident([
    'title' => 'Payment processing delays',
    'impact' => 'major',
    'state' => 'investigating',
    'message' => 'Stripe webhook delivery is delayed.',
    'component_ids' => [12],
]);

$beacon->addIncidentUpdate($incident['id'], 'Fix deployed, monitoring.');
$beacon->resolveIncident($incident['id'], 'Webhook delivery restored.');

$beacon->updateComponent(12, ['status' => 'degraded_performance']);
```

## REST resources

All page-scoped resources take the status page slug first and return the API objects as arrays.

```php
$page = $beacon->statusPages()->create(['name' => 'Acme Cloud']);
$api = $beacon->components()->create($page['slug'], ['name' => 'API']);

$beacon->monitors()->create($page['slug'], [
    'name' => 'Postgres',
    'type' => 'tcp',
    'host' => 'db.acme.com',
    'port' => 5432,
    'check_window_start' => '08:00',
    'check_window_end' => '20:00',
    'status_component_id' => $api['id'],
]);

foreach ($beacon->incidents()->iterate($page['slug'], ['state' => 'open']) as $incident) {
    echo $incident['title'], PHP_EOL;
}

$hook = $beacon->webhooks()->create($page['slug'], ['url' => 'https://example.com/beacon']);
$hook['secret']; // returned once — store it
```

| Method | Operations |
| --- | --- |
| `statusPages()` | `list`, `iterate`, `get`, `create`, `update`, `delete` |
| `components()` | `list`, `iterate`, `get`, `create`, `update`, `delete`, `reorder` |
| `monitors()`, `maintenances()`, `webhooks()` | `list`, `iterate`, `get`, `create`, `update`, `delete` |
| `incidents()` | the above plus `listUpdates`, `addUpdate` |
| `subscribers()` | the above; `update` replaces `component_ids` |
| `imports()` | `list`, `iterate`, `get`, `create` |

`$beacon->request($method, $path, $body, $query)` reaches anything else under `/api/v1`.

## Heartbeats

```php
Beacon::ping('YOUR-HEARTBEAT-TOKEN');                  // no API token needed
Beacon::ping($monitor['heartbeat_url'], method: 'GET'); // a full URL works too
$beacon->heartbeat('YOUR-HEARTBEAT-TOKEN');             // uses the client's base URL
```

## Errors

```php
use Beacon\BeaconException;
use Beacon\Transport\TransportException;

try {
    $beacon->components()->create('acme', ['name' => '']);
} catch (BeaconException $e) {
    $e->status;      // 422
    $e->errorCode;   // validation_failed, unauthenticated, insufficient_scope, not_found, plan_limit_reached, workspace_read_only, rate_limited…
    $e->errors;      // ['name' => ['The name field is required.']]
    $e->retryAfter;  // seconds, on 429
} catch (TransportException $e) {
    // DNS, TLS, timeout…
}
```

## Verifying webhooks

```php
use Beacon\Webhook;

$valid = Webhook::verify(
    file_get_contents('php://input'),          // the raw body
    getallheaders(),                           // any casing; $_SERVER and PSR-7 arrays work too
    getenv('BEACON_WEBHOOK_SECRET'),
    toleranceSeconds: 300,
);

if (! $valid) {
    http_response_code(403);
    exit;
}
```

The comparison is constant-time (`hash_equals`) and timestamps more than 300 seconds off are rejected.

## PSR-18 clients

```php
use Beacon\Transport\Psr18Transport;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;

$beacon = new Beacon($token, transport: new Psr18Transport(new Client(['timeout' => 10]), new HttpFactory, new HttpFactory));
```

## Development

```bash
composer install
vendor/bin/phpunit
```
