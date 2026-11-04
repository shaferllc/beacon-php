<?php

declare(strict_types=1);

namespace Beacon\Tests;

use Beacon\Webhook;
use PHPUnit\Framework\TestCase;

final class WebhookTest extends TestCase
{
    private const SECRET = 'whsec_test_secret';

    private const BODY = '{"event":"incident.opened","incident":{"id":"1"}}';

    private const NOW = 1760000000;

    private function headers(?string $signature = null, int|string $timestamp = self::NOW): array
    {
        // Computed independently of Webhook::sign, exactly as the docs describe it.
        $signature ??= 'sha256='.hash_hmac('sha256', self::NOW.'.'.self::BODY, self::SECRET);

        return ['X-Beacon-Timestamp' => (string) $timestamp, 'X-Beacon-Signature' => $signature];
    }

    public function test_valid_signatures_pass_in_any_header_format(): void
    {
        $sig = $this->headers()['X-Beacon-Signature'];

        $this->assertSame($sig, Webhook::sign(self::BODY, self::SECRET, self::NOW));
        $this->assertTrue(Webhook::verify(self::BODY, $this->headers(), self::SECRET, now: self::NOW));
        $this->assertTrue(Webhook::verify(self::BODY, ['x-beacon-timestamp' => [(string) self::NOW], 'x-beacon-signature' => [$sig]], self::SECRET, now: self::NOW));
        $this->assertTrue(Webhook::verify(self::BODY, ['HTTP_X_BEACON_TIMESTAMP' => (string) self::NOW, 'HTTP_X_BEACON_SIGNATURE' => $sig], self::SECRET, now: self::NOW));
    }

    public function test_tampering_and_wrong_secrets_fail(): void
    {
        $this->assertFalse(Webhook::verify(str_replace('1', '2', self::BODY), $this->headers(), self::SECRET, now: self::NOW));
        $this->assertFalse(Webhook::verify(self::BODY, $this->headers(), 'other', now: self::NOW));
        $this->assertFalse(Webhook::verify(self::BODY, $this->headers('sha256=deadbeef'), self::SECRET, now: self::NOW));
        $this->assertFalse(Webhook::verify(self::BODY, $this->headers(), '', now: self::NOW));
    }

    public function test_timestamps_outside_the_tolerance_fail(): void
    {
        $this->assertFalse(Webhook::verify(self::BODY, $this->headers(), self::SECRET, now: self::NOW + 301));
        $this->assertFalse(Webhook::verify(self::BODY, $this->headers(), self::SECRET, now: self::NOW - 301));
        $this->assertTrue(Webhook::verify(self::BODY, $this->headers(), self::SECRET, now: self::NOW + 300));
        $this->assertTrue(Webhook::verify(self::BODY, $this->headers(), self::SECRET, 1000, self::NOW + 900));
    }

    public function test_missing_or_malformed_timestamps_fail(): void
    {
        $this->assertFalse(Webhook::verify(self::BODY, ['X-Beacon-Signature' => $this->headers()['X-Beacon-Signature']], self::SECRET, now: self::NOW));
        $this->assertFalse(Webhook::verify(self::BODY, $this->headers(timestamp: self::NOW.'.5'), self::SECRET, now: self::NOW));
        $this->assertFalse(Webhook::verify(self::BODY, $this->headers(timestamp: '-1'), self::SECRET, now: self::NOW));
    }
}
