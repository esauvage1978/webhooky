<?php

declare(strict_types=1);

namespace App\FormWebhook;

/**
 * Jeton d’ingress : [préfixe org 12 hex][UUID workflow], ou historiquement seul l’UUID (36 car.).
 */
final class FormWebhookIngressTokenParser
{
    public const ORG_PREFIX_HEX_LENGTH = 12;

    private const UUID_SEGMENT = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    /**
     * Extrait le segment jeton depuis une URL ou un chemin contenant /webhook/form/{token}.
     */
    public static function extractTokenSegmentFromUrl(string $webhookUrl): ?string
    {
        if (preg_match('~/webhook/form/([^/?#]+)~i', $webhookUrl, $m) !== 1) {
            return null;
        }

        return strtolower($m[1]);
    }

    /**
     * Indique si la chaîne est un jeton d’ingress valide (composé ou UUID seul).
     */
    public static function isValidIngressToken(string $token): bool
    {
        $token = strtolower($token);

        return self::parseComposite($token) !== null || self::parseLegacyUuidOnly($token) !== null;
    }

    /**
     * @return array{prefix: string, workflowPublicToken: string}|null
     */
    public static function parseComposite(string $token): ?array
    {
        $token = strtolower($token);
        // Copie « lisible » : préfixe org (12 hex) + tiret + UUID workflow — normaliser en concaténation stricte.
        if (preg_match('/^([0-9a-f]{12})-('.self::UUID_SEGMENT.')$/i', $token, $m) === 1) {
            $token = $m[1].$m[2];
        }
        $pattern = '/^([0-9a-f]{12})('.self::UUID_SEGMENT.')$/i';
        if (preg_match($pattern, $token, $m) !== 1) {
            return null;
        }

        return ['prefix' => $m[1], 'workflowPublicToken' => $m[2]];
    }

    public static function parseLegacyUuidOnly(string $token): ?string
    {
        $token = strtolower($token);
        if (preg_match('/^('.self::UUID_SEGMENT.')$/i', $token, $m) !== 1) {
            return null;
        }

        return $m[1];
    }
}
