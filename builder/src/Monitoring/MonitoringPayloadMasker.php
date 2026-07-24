<?php

declare(strict_types=1);

namespace App\Monitoring;

final class MonitoringPayloadMasker
{
    private const SENSITIVE = '/password|secret|token|api[_-]?key|authorization|private|passwd|credential/i';

    public function maskSecrets(mixed $data): mixed
    {
        if (\is_array($data)) {
            $out = [];
            foreach ($data as $k => $v) {
                if (\is_string($k) && preg_match(self::SENSITIVE, $k)) {
                    $out[$k] = '***';
                } else {
                    $out[$k] = $this->maskSecrets($v);
                }
            }

            return $out;
        }
        if (\is_string($data) && mb_strlen($data) > 4000) {
            return mb_substr($data, 0, 4000).'… [tronqué]';
        }

        return $data;
    }

    public function maskIp(?string $ip): ?string
    {
        if ($ip === null || $ip === '') {
            return $ip;
        }
        if (preg_match('/^(\d+)\.(\d+)\.(\d+)\.(\d+)$/', $ip, $m)) {
            return $m[1].'.'.$m[2].'.x.x';
        }

        return $ip;
    }

    public function maskRecipient(?string $recipient): ?string
    {
        if ($recipient === null || $recipient === '') {
            return $recipient;
        }
        if (str_contains($recipient, '@')) {
            [$local, $domain] = explode('@', $recipient, 2);
            $keep = mb_substr($local, 0, 2);

            return $keep.'***@'.$domain;
        }
        $len = mb_strlen($recipient);
        if ($len <= 4) {
            return '****';
        }

        return mb_substr($recipient, 0, 2).str_repeat('*', max(0, $len - 4)).mb_substr($recipient, -2);
    }
}
