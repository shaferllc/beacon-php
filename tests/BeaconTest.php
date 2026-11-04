<?php

declare(strict_types=1);

namespace Beacon\Tests;

use Beacon\Beacon;
use Beacon\BeaconException;
use Beacon\Transport\Response;
use PHPUnit\Framework\TestCase;

final class BeaconTest extends TestCase
{
    private function client(FakeTransport $transport, ?string $pageSlug = null, int $maxRetries = 2): Beacon
    {
        return new Beacon('bchk_test', 'https://beacon.test/', $pageSlug, maxRetries: $maxRetries, transport: $transport);
    }

    private static function page(array $data, ?string $next): array
    {
        return ['data' => $data, 'meta' => ['path' => '', 'per_page' => 2, 'next_cursor' => $next, 'prev_cursor' => null], 'links' => []];
    }

    public function test_it_authenticates_and_unwraps_the_data_envelope(): void
    {
        $http = new FakeTransport(FakeTransport::json(['data' => ['id' => 1, 'slug' => 'acme', 'name' => 'Acme']]));

        $page = $this->client($http)->statusPages()->get('acme');

        $this->assertSame('Acme', $page['name']);
        $this->assertSame('https://beacon.test/api/v1/status-pages/acme', $http->requests[0]['url']);
        $this->assertSame('Bearer bchk_test', $http->requests[0]['headers']['Authorization']);
        $this->assertArrayNotHasKey('Content-Type', $http->requests[0]['headers']);
    }

    public function test_it_defaults_to_the_hosted_url(): void
    {
        $this->assertSame('https://usebeacon.pro', (new Beacon('t'))->baseUrl);
    }

    public function test_crud_on_nested_resources(): void
    {
        $http = new FakeTransport(
            FakeTransport::json(['data' => ['id' => 7, 'name' => 'API', 'type' => 'tcp']], 201),
            FakeTransport::json(['data' => ['id' => 7, 'name' => 'API v2']]),
            new Response(204),
        );
        $monitors = $this->client($http)->monitors();

        $created = $monitors->create('acme', ['name' => 'API', 'type' => 'tcp', 'host' => 'db.acme.test', 'port' => 5432, 'check_window_start' => '08:00', 'check_window_end' => '18:00']);
        $updated = $monitors->update('acme', 7, ['name' => 'API v2']);
        $monitors->delete('acme', 7);

        $this->assertSame(7, $created['id']);
        $this->assertSame('API v2', $updated['name']);
        $this->assertSame([
            'POST /api/v1/status-pages/acme/monitors',
            'PATCH /api/v1/status-pages/acme/monitors/7',
            'DELETE /api/v1/status-pages/acme/monitors/7',
        ], $http->lines());
        $this->assertSame(['name' => 'API', 'type' => 'tcp', 'host' => 'db.acme.test', 'port' => 5432, 'check_window_start' => '08:00', 'check_window_end' => '18:00'], $http->requests[0]['body']);
        $this->assertSame('application/json', $http->requests[0]['headers']['Content-Type']);
    }

    public function test_filters_and_iteration_follow_the_cursor(): void
    {
        $http = new FakeTransport(
            FakeTransport::json(self::page([['id' => 1], ['id' => 2]], 'abc')),
            FakeTransport::json(self::page([['id' => 3]], null)),
            FakeTransport::json(self::page([], null)),
        );
        $beacon = $this->client($http);

        $ids = array_column(iterator_to_array($beacon->incidents()->iterate('acme', ['state' => 'open', 'per_page' => 2]), false), 'id');
        $beacon->subscribers()->list('acme', ['confirmed' => true]);

        $this->assertSame([1, 2, 3], $ids);
        $this->assertSame([
            'GET /api/v1/status-pages/acme/incidents?state=open&per_page=2',
            'GET /api/v1/status-pages/acme/incidents?state=open&per_page=2&cursor=abc',
            'GET /api/v1/status-pages/acme/subscribers?confirmed=1',
        ], $http->lines());
    }

    public function test_webhook_create_returns_the_one_time_secret(): void
    {
        $http = new FakeTransport(FakeTransport::json(['data' => ['id' => 3, 'url' => 'https://hook.test'], 'secret' => 'whsec_abc'], 201));

        $hook = $this->client($http)->webhooks()->create('acme', ['url' => 'https://hook.test']);

        $this->assertSame(['id' => 3, 'url' => 'https://hook.test', 'secret' => 'whsec_abc'], $hook);
    }

    public function test_reorder_and_incident_updates(): void
    {
        $http = new FakeTransport(FakeTransport::json(['data' => [['id' => 2], ['id' => 1]]]), FakeTransport::json(['data' => ['id' => 9]], 201));
        $beacon = $this->client($http);

        $this->assertCount(2, $beacon->components()->reorder('acme', [2, 1]));
        $this->assertSame(9, $beacon->incidents()->addUpdate('acme', 4, 'Fixed')['id']);
        $this->assertSame(['ids' => [2, 1]], $http->requests[0]['body']);
        $this->assertSame('POST /api/v1/status-pages/acme/incidents/4/updates', $http->lines()[1]);
    }

    public function test_errors_carry_code_status_and_field_errors(): void
    {
        $http = new FakeTransport(FakeTransport::json(['message' => 'Validation failed.', 'code' => 'validation_failed', 'errors' => ['name' => ['required']]], 422));

        try {
            $this->client($http)->components()->create('acme', ['name' => '']);
            $this->fail('Expected BeaconException');
        } catch (BeaconException $e) {
            $this->assertSame(422, $e->status);
            $this->assertSame('validation_failed', $e->errorCode);
            $this->assertSame(['name' => ['required']], $e->errors);
            $this->assertNull($e->retryAfter);
        }
    }

    public function test_rate_limit_exposes_retry_after_when_retries_are_exhausted(): void
    {
        $http = new FakeTransport(FakeTransport::json(['message' => 'Slow down', 'code' => 'rate_limited'], 429, ['Retry-After' => '42']));

        try {
            $this->client($http, maxRetries: 0)->statusPages()->list();
            $this->fail('Expected BeaconException');
        } catch (BeaconException $e) {
            $this->assertSame('rate_limited', $e->errorCode);
            $this->assertSame(42, $e->retryAfter);
        }
    }

    public function test_429_is_retried_but_a_5xx_write_is_not(): void
    {
        $http = new FakeTransport(
            FakeTransport::json(['code' => 'rate_limited', 'message' => 'x'], 429, ['Retry-After' => '0']),
            FakeTransport::json(['id' => '5'], 201),
            new Response(502, [], 'Bad gateway'),
        );
        $beacon = $this->client($http, 'acme');

        $this->assertSame('5', $beacon->createIncident(['title' => 'Down'])['id']);
        $this->assertCount(2, $http->requests);

        $this->expectException(BeaconException::class);
        try {
            $beacon->statusPages()->create(['name' => 'x']);
        } finally {
            $this->assertCount(3, $http->requests);
        }
    }

    public function test_ingest_helpers(): void
    {
        $http = new FakeTransport(
            FakeTransport::json(['id' => '10', 'state' => 'investigating'], 201),
            FakeTransport::json(['id' => '10']),
            FakeTransport::json(['id' => '10', 'state' => 'resolved']),
            FakeTransport::json(['id' => '3', 'status' => 'major_outage']),
        );
        $beacon = $this->client($http, 'acme');

        $beacon->createIncident(['title' => 'Payments down', 'impact' => 'major', 'component_ids' => [1]]);
        $beacon->addIncidentUpdate('10', 'Deploying fix');
        $this->assertSame('resolved', $beacon->resolveIncident(10, 'All good')['state']);
        $beacon->updateComponent(3, ['status' => 'major_outage'], 'other');

        $this->assertSame([
            'POST /api/v1/pages/acme/incidents',
            'POST /api/v1/incidents/10/updates',
            'PATCH /api/v1/incidents/10',
            'PATCH /api/v1/pages/other/components/3',
        ], $http->lines());
        $this->assertSame(['resolved' => true, 'message' => 'All good'], $http->requests[2]['body']);
    }

    public function test_ingest_requires_a_page_slug(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->client(new FakeTransport)->createIncident(['title' => 'x']);
    }

    public function test_heartbeat_ping_skips_the_api_token(): void
    {
        $http = new FakeTransport(FakeTransport::json(['ok' => true]), new Response(200));

        $this->client($http)->heartbeat('hb_123');
        Beacon::ping('https://other.test/api/v1/heartbeat/abc', method: 'HEAD', transport: $http);

        $this->assertSame(['POST /api/v1/heartbeat/hb_123', 'HEAD /api/v1/heartbeat/abc'], $http->lines());
        $this->assertSame('https://beacon.test/api/v1/heartbeat/hb_123', $http->requests[0]['url']);
        $this->assertArrayNotHasKey('Authorization', $http->requests[0]['headers']);
    }

    public function test_heartbeat_ping_throws_on_unknown_token(): void
    {
        $this->expectExceptionObject(new BeaconException('Beacon API returned HTTP 404', 404, 'not_found'));
        Beacon::ping('nope', transport: new FakeTransport(new Response(404)));
    }
}
