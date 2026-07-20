<?php

declare(strict_types=1);

namespace App\ServiceIntegration;

use App\Security\SensitiveStringEncryptor;

/**
 * Masquage API + chiffrement au repos des secrets de connecteurs.
 */
final class ServiceConnectionSecretHelper
{
    public const MASK = '••••••••';

    /** @var list<string> */
    private const SENSITIVE_KEYS = [
        'apiKeyPrivate',
        'apiSecret',
        'apiKey',
        'authToken',
        'botToken',
        'token',
        'password',
        'privateKey',
        'clientSecret',
        'accessToken',
        'secret',
        'accountSid',
        'appToken',
        'userKey',
    ];

    public function __construct(
        private readonly SensitiveStringEncryptor $encryptor,
    ) {
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    public function maskForApi(array $config): array
    {
        $out = $config;
        foreach (self::SENSITIVE_KEYS as $key) {
            if (isset($out[$key]) && \is_string($out[$key]) && $out[$key] !== '') {
                $out[$key] = self::MASK;
            }
        }
        // Headers Authorization
        if (isset($out['headers']) && \is_array($out['headers'])) {
            foreach ($out['headers'] as $hKey => $hVal) {
                if (!\is_string($hVal)) {
                    continue;
                }
                $lk = strtolower((string) $hKey);
                if (str_contains($lk, 'authorization') || str_contains($lk, 'api-key') || str_contains($lk, 'token')) {
                    $out['headers'][$hKey] = self::MASK;
                }
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $existing decrypted or stored
     * @param array<string, mixed> $incoming from client
     *
     * @return array<string, mixed>
     */
    public function mergePreservingMasked(array $existing, array $incoming): array
    {
        $out = array_merge($existing, $incoming);
        foreach (self::SENSITIVE_KEYS as $key) {
            if (!\array_key_exists($key, $incoming)) {
                continue;
            }
            $val = trim((string) $incoming[$key]);
            if ($val === '' || str_starts_with($val, '•') || $val === '********') {
                $out[$key] = isset($existing[$key]) ? $existing[$key] : '';
            }
        }
        if (isset($incoming['headers'], $existing['headers']) && \is_array($incoming['headers']) && \is_array($existing['headers'])) {
            foreach ($incoming['headers'] as $hKey => $hVal) {
                if (!\is_string($hVal)) {
                    continue;
                }
                if (str_starts_with($hVal, '•') || $hVal === '********') {
                    $out['headers'][$hKey] = $existing['headers'][$hKey] ?? '';
                }
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    public function encryptSensitiveFields(array $config): array
    {
        $out = $config;
        foreach (self::SENSITIVE_KEYS as $key) {
            if (!isset($out[$key]) || !\is_string($out[$key]) || $out[$key] === '') {
                continue;
            }
            if ($this->encryptor->isEncrypted($out[$key])) {
                continue;
            }
            $out[$key] = $this->encryptor->encrypt($out[$key]);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    public function decryptSensitiveFields(array $config): array
    {
        $out = $config;
        foreach (self::SENSITIVE_KEYS as $key) {
            if (!isset($out[$key]) || !\is_string($out[$key]) || $out[$key] === '') {
                continue;
            }
            $out[$key] = $this->encryptor->reveal($out[$key]);
        }

        return $out;
    }
}
