<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Empêche les SSRF vers réseaux privés / métadonnées cloud.
 */
final class OutboundUrlGuard
{
    /**
     * @throws \InvalidArgumentException
     */
    public function assertSafe(string $url, bool $allowPrivateNetworks = false, bool $requireHttps = false): string
    {
        $url = trim($url);
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException('URL sortante invalide.');
        }

        $parts = parse_url($url);
        if (!\is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw new \InvalidArgumentException('URL sortante invalide.');
        }

        $scheme = strtolower((string) $parts['scheme']);
        if (!\in_array($scheme, ['http', 'https'], true)) {
            throw new \InvalidArgumentException('Seuls les schémas http/https sont autorisés.');
        }
        if ($requireHttps && $scheme !== 'https') {
            throw new \InvalidArgumentException('HTTPS obligatoire pour cette destination.');
        }

        $host = strtolower((string) $parts['host']);
        $host = trim($host, '[]');

        if ($host === '' || $host === '0') {
            throw new \InvalidArgumentException('Hôte URL invalide.');
        }

        if (!$allowPrivateNetworks && $this->isBlockedHost($host)) {
            throw new \InvalidArgumentException('Destination réseau non autorisée.');
        }

        return $url;
    }

    private function isBlockedHost(string $host): bool
    {
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
            return true;
        }

        if ($host === 'metadata.google.internal' || str_contains($host, 'metadata')) {
            return true;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return $this->isBlockedIp($host);
        }

        // Résolution DNS optionnelle : bloque si toutes les IPs sont privées
        $ips = @gethostbynamel($host);
        if (\is_array($ips) && $ips !== []) {
            foreach ($ips as $ip) {
                if ($this->isBlockedIp($ip)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function isBlockedIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $long = ip2long($ip);
            if ($long === false) {
                return true;
            }
            // 127.0.0.0/8, 10/8, 172.16/12, 192.168/16, 169.254/16, 0.0.0.0/8
            $ranges = [
                ['127.0.0.0', '127.255.255.255'],
                ['10.0.0.0', '10.255.255.255'],
                ['172.16.0.0', '172.31.255.255'],
                ['192.168.0.0', '192.168.255.255'],
                ['169.254.0.0', '169.254.255.255'],
                ['0.0.0.0', '0.255.255.255'],
            ];
            foreach ($ranges as [$start, $end]) {
                $s = ip2long($start);
                $e = ip2long($end);
                if ($s !== false && $e !== false && $long >= $s && $long <= $e) {
                    return true;
                }
            }

            return false;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $normalized = strtolower($ip);
            if ($normalized === '::1' || str_starts_with($normalized, 'fc') || str_starts_with($normalized, 'fd')
                || str_starts_with($normalized, 'fe80') || str_starts_with($normalized, '::ffff:')) {
                return true;
            }
        }

        return false;
    }
}
