<?php

declare(strict_types=1);

namespace App\FormWebhook\PayloadParser;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\HttpFoundation\Request;

/**
 * Corps JSON (objet plat ou quelques champs imbriqués aplatis un niveau).
 */
#[AutoconfigureTag('app.form_webhook.payload_parser')]
final class JsonPayloadParser implements PayloadParserInterface
{
    public function getPriority(): int
    {
        return 30;
    }

    public function supports(Request $request): bool
    {
        $ct = (string) $request->headers->get('Content-Type', '');
        if (str_contains($ct, 'application/json')) {
            return true;
        }

        $raw = $request->getContent();
        if ($raw !== '' && !str_contains($ct, 'application/x-www-form-urlencoded') && !str_contains($ct, 'multipart/form-data')) {
            $t = ltrim($raw);
            if ($t !== '' && ($t[0] === '{' || $t[0] === '[')) {
                return true;
            }
        }

        return false;
    }

    public function parse(Request $request): array
    {
        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            return [];
        }

        return $this->flattenScalars($data);
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<string, string>
     */
    private function flattenScalars(array $data, string $prefix = ''): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            $key = $prefix === '' ? (string) $k : $prefix.'.'.$k;
            if (\is_array($v)) {
                foreach ($this->flattenScalars($v, $key) as $sk => $sv) {
                    $out[$sk] = $sv;
                }
            } elseif (\is_scalar($v) || $v === null) {
                $out[$key] = $v === null ? '' : (string) $v;
            }
        }

        return $out;
    }
}
