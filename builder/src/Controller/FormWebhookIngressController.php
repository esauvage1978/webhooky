<?php

declare(strict_types=1);

namespace App\Controller;

use App\FormWebhook\FormWebhookIngressHandler;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Point d’entrée public (formulaires HTML externes).
 */
final class FormWebhookIngressController
{
    public function __construct(
        private readonly FormWebhookIngressHandler $ingressHandler,
    ) {
    }

    #[Route('/webhook/form/{token}', name: 'form_webhook_ingress', methods: ['POST', 'GET'])]
    public function __invoke(Request $request, string $token): JsonResponse
    {
        if ($request->isMethod('GET')) {
            return new JsonResponse([
                'message' => 'Utilisez POST pour envoyer les données du formulaire.',
                'method' => 'POST',
            ]);
        }

        return $this->ingressHandler->handle($request, $token);
    }
}
