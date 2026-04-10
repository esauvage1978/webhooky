<?php

declare(strict_types=1);

namespace App\FormWebhook;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;

/**
 * CORS pour l’ingress public des formulaires (ex. site vitrine → webhooky.builders).
 */
final class FormWebhookIngressCors
{
    /**
     * @param list<string> $allowedOrigins origines exactes (schéma + hôte + port)
     */
    public function __construct(
        #[Autowire('%app.webhooky.form_webhook_cors_origins%')]
        private readonly array $allowedOrigins,
    ) {
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
