<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Chiffrement symétrique des secrets applicatifs (tokens OAuth) via libsodium.
 */
final class SensitiveStringEncryptor
{
    private const VERSION = 'v1';

    public function __construct(
        private readonly string $appSecret,
    ) {
    }

    public function encrypt(string $plain): string
    {
        if ($plain === '') {
            return '';
        }
        $key = $this->deriveKey();
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plain, $nonce, $key);

        return self::VERSION.'|'.base64_encode($nonce.$cipher);
    }

    public function decrypt(string $stored): string
    {
        if ($stored === '') {
            return '';
        }
        $parts = explode('|', $stored, 2);
        if (\count($parts) !== 2 || $parts[0] !== self::VERSION) {
            throw new \RuntimeException('Jeton chiffré invalide ou version non supportée.');
        }
        $raw = base64_decode($parts[1], true);
        if ($raw === false || \strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \RuntimeException('Jeton chiffré corrompu.');
        }
        $key = $this->deriveKey();
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open($cipher, $nonce, $key);
        if ($plain === false) {
            throw new \RuntimeException('Impossible de déchiffrer le jeton (clé ou intégrité).');
        }

        return $plain;
    }

    /**
     * @return non-empty-string
     */
    private function deriveKey(): string
    {
        $raw = hash('sha256', 'webhooky|org-integration|'.$this->appSecret, true);
        if (\strlen($raw) < SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \RuntimeException('Clé de chiffrement interne invalide.');
        }

        return substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }
}
