<?php

declare(strict_types=1);

namespace App\Tests\ServiceIntegration;

use App\Security\SensitiveStringEncryptor;
use App\ServiceIntegration\ServiceConnectionSecretHelper;
use PHPUnit\Framework\TestCase;

final class ServiceConnectionSecretHelperTest extends TestCase
{
    public function testMaskEncryptDecryptAndMerge(): void
    {
        $helper = new ServiceConnectionSecretHelper(new SensitiveStringEncryptor('unit-test-secret'));
        $plain = ['apiKeyPublic' => 'pub', 'apiKeyPrivate' => 'priv-secret'];
        $encrypted = $helper->encryptSensitiveFields($plain);
        self::assertNotSame('priv-secret', $encrypted['apiKeyPrivate']);
        self::assertSame('pub', $encrypted['apiKeyPublic']);

        $masked = $helper->maskForApi($encrypted);
        self::assertSame(ServiceConnectionSecretHelper::MASK, $masked['apiKeyPrivate']);

        $merged = $helper->mergePreservingMasked(
            $helper->decryptSensitiveFields($encrypted),
            ['apiKeyPublic' => 'pub2', 'apiKeyPrivate' => ServiceConnectionSecretHelper::MASK],
        );
        self::assertSame('priv-secret', $merged['apiKeyPrivate']);
        self::assertSame('pub2', $merged['apiKeyPublic']);
    }
}
