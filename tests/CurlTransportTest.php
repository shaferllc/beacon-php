<?php

declare(strict_types=1);

namespace Beacon\Tests;

use Beacon\Beacon;
use Beacon\BeaconException;
use PHPUnit\Framework\TestCase;

/** Runs the default ext-curl transport against PHP's built-in server (tests/server.php echoes the request). */
final class CurlTransportTest extends TestCase
{
    /** @var resource|null */
    private static $server = null;

    private static int $port;

    public static function setUpBeforeClass(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::$port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);

        self::$server = proc_open([PHP_BINARY, '-S', '127.0.0.1:'.self::$port, __DIR__.'/server.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        for ($i = 0; $i < 50 && ! @fsockopen('127.0.0.1', self::$port); $i++) {
            usleep(100_000);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$server) {
            proc_terminate(self::$server);
        }
    }

    public function test_round_trip_with_json_body_and_query(): void
    {
        $beacon = new Beacon('bchk_curl', 'http://127.0.0.1:'.self::$port);

        $echo = $beacon->request('PATCH', '/status-pages/acme', ['name' => 'Acme'], ['per_page' => 5]);

        $this->assertSame('PATCH', $echo['method']);
        $this->assertSame('/api/v1/status-pages/acme?per_page=5', $echo['uri']);
        $this->assertSame('Bearer bchk_curl', $echo['authorization']);
        $this->assertSame(['name' => 'Acme'], $echo['body']);
    }

    public function test_error_responses_and_headers_are_parsed(): void
    {
        try {
            (new Beacon('t', 'http://127.0.0.1:'.self::$port, maxRetries: 0))->request('GET', '/rate-limited');
            $this->fail('Expected BeaconException');
        } catch (BeaconException $e) {
            $this->assertSame(429, $e->status);
            $this->assertSame('rate_limited', $e->errorCode);
            $this->assertSame(7, $e->retryAfter);
        }
    }

    public function test_head_heartbeat(): void
    {
        Beacon::ping('hb', 'http://127.0.0.1:'.self::$port, 'HEAD');
        $this->addToAssertionCount(1);
    }
}
