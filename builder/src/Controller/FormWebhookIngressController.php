<?php

declare(strict_types=1);

namespace App\Controller;

use App\FormWebhook\FormWebhookIngressCors;
use App\FormWebhook\FormWebhookIngressHandler;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Point d’entrée public (formulaires HTML externes).
 */
final class FormWebhookIngressController
{
    public function __construct(
        private readonly FormWebhookIngressHandler $ingressHandler,
        private readonly FormWebhookIngressCors $ingressCors,
    ) {
    }

    #[Route('/webhook/form/{token}', name: 'form_webhook_ingress', methods: ['POST', 'GET', 'OPTIONS'])]
    public function __invoke(Request $request, string $token): Response
    {
        $cors = $this->ingressCors->responseHeaders($request);

        if ($request->isMethod('OPTIONS')) {
            return new Response('', Response::HTTP_NO_CONTENT, $cors);
        }

        if ($request->isMethod('GET')) {
            $response = new JsonResponse([
                'message' => 'Utilisez POST pour envoyer les données du formulaire.',
                'method' => 'POST',
            ]);
            foreach ($cors as $name => $value) {
                $response->headers->set($name, $value);
            }

            return $response;
        }

        $response = $this->ingressHandler->handle($request, $token);
        foreach ($cors as $name => $value) {
            $response->headers->set($name, $value);
        }

        return $response;
    }
}
