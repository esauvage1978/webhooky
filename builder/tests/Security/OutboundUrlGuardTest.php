<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\OutboundUrlGuard;
use PHPUnit\Framework\TestCase;

final class OutboundUrlGuardTest extends TestCase
{
    private OutboundUrlGuard $guard;

    protected function setUp(): void
    {
        $this->guard = new OutboundUrlGuard();
    }

    public function testAllowsPublicHttps(): void
    {
        $url = $this->guard->assertSafe('https://hooks.slack.com/services/T/B/X', false, true);
        self::assertStringStartsWith('https://', $url);
    }

    public function testRejectsLocalhostWhenPrivateForbidden(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->guard->assertSafe('https://127.0.0.1/api', false, true);
    }

    public function testRejectsMetadataHost(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->guard->assertSafe('http://169.254.169.254/latest/meta-data/', false, false);
    }

    public function testAllowsPrivateWhenExplicitlyEnabled(): void
    {
        $url = $this->guard->assertSafe('http://127.0.0.1:11434', true, false);
        self::assertSame('http://127.0.0.1:11434', $url);
    }

    public function testRejectsHttpWhenHttpsRequired(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->guard->assertSafe('http://example.com/hook', false, true);
    }
}
