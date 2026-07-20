<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\SensitiveStringEncryptor;
use PHPUnit\Framework\TestCase;

final class SensitiveStringEncryptorTest extends TestCase
{
    public function testRoundTripAndRevealLegacy(): void
    {
        $enc = new SensitiveStringEncryptor('test-secret-for-unit-tests-only');
        $cipher = $enc->encrypt('super-secret');
        self::assertTrue($enc->isEncrypted($cipher));
        self::assertSame('super-secret', $enc->decrypt($cipher));
        self::assertSame('legacy-plain', $enc->reveal('legacy-plain'));
        self::assertSame('super-secret', $enc->reveal($cipher));
    }
}
