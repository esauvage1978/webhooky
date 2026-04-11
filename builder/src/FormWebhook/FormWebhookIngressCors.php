<?php

declare(strict_types=1);

namespace App\FormWebhook;

use Symfony\Component\HttpFoundation\Request;

/**
 * CORS pour l’ingress public des formulaires (ex. site vitrine → webhooky.builders).
 */
final class FormWebhookIngressCors
{
    /**
     * Origines exactes (schéma + hôte + port), issues de l’option plateforme {@see FormWebhookCorsOriginsResolver::OPTION_NAME}.
     *
     * @var list<string>
     */
    private readonly array $allowedOrigins;

    public function __construct(
        FormWebhookCorsOriginsResolver $corsOriginsResolver,
    ) {
        $this->allowedOrigins = $corsOriginsResolver->resolve();
    }

    /**
     * En-têtes à ajouter à la réponse si l’en-tête Origin correspond à une origine autorisée.
     *
     * @return array<string, string>
     */
    public function responseHeaders(Request $request): array
    {
        $origin = $request->headers->get('Origin');
        if ($origin === null || $origin === '') {
            return [];
        }
        if (!\in_array($origin, $this->allowedOrigins, true)) {
            return [];
        }

        return [
            'Access-Control-Allow-Origin' => $origin,
            'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Accept',
            'Access-Control-Max-Age' => '86400',
        ];
    }
}
