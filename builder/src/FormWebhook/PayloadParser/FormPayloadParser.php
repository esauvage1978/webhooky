<?php

declare(strict_types=1);

namespace App\FormWebhook\PayloadParser;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\HttpFoundation\Request;

/**
 * application/x-www-form-urlencoded et multipart/form-data.
 */
#[AutoconfigureTag('app.form_webhook.payload_parser')]
final class FormPayloadParser implements PayloadParserInterface
{
    public function getPriority(): int
    {
        return 20;
    }

    public function supports(Request $request): bool
    {
        if ($request->request->count() > 0) {
            return true;
        }

        $ct = (string) $request->headers->get('Content-Type', '');
        if (str_contains($ct, 'application/x-www-form-urlencoded') || str_contains($ct, 'multipart/form-data')) {
            return true;
        }

        return false;
    }

    public function parse(Request $request): array
    {
        $out = [];
        foreach ($request->request->all() as $key => $value) {
            if (!\is_string($key)) {
                continue;
            }
            if (\is_array($value)) {
                continue;
            }
            $out[$key] = $value === null || $value === '' ? '' : (string) $value;
        }

        return $out;
    }
}
