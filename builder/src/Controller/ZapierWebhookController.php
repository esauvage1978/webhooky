<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Attribute\Route;

final class ZapierWebhookController
{
    #[Route('/webhook/zapier', name: 'webhook_zapier', methods: ['POST'])]
    public function __invoke(
        Request $request,
        MailerInterface $mailer,
        #[Autowire(env: 'MAILER_FROM')]
        string $mailerFrom,
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            return new JsonResponse(['error' => 'Corps JSON invalide'], Response::HTTP_BAD_REQUEST);
        }

        $to = $data['to'] ?? $data['email'] ?? null;
        $subject = $data['subject'] ?? 'Notification Webhooky';
        $text = $data['text'] ?? $data['body'] ?? $data['message'] ?? '';

        if (!\is_string($to) || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return new JsonResponse(
                ['error' => 'Adresse destinataire invalide ou absente (champs: to, email)'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        if (!filter_var($mailerFrom, FILTER_VALIDATE_EMAIL)) {
            return new JsonResponse(
                ['error' => 'MAILER_FROM doit être une adresse e-mail valide dans .env'],
                Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        $email = (new Email())
            ->from($mailerFrom)
            ->to($to)
            ->subject(\is_string($subject) ? $subject : 'Notification Webhooky')
            ->text(\is_string($text) && $text !== '' ? $text : '(vide)');

        if (isset($data['html']) && \is_string($data['html']) && $data['html'] !== '') {
            $email->html($data['html']);
        }

        try {
            $mailer->send($email);
        } catch (TransportExceptionInterface $e) {
            return new JsonResponse(
                ['error' => 'Échec envoi Mailjet', 'detail' => $e->getMessage()],
                Response::HTTP_BAD_GATEWAY,
            );
        }

        return new JsonResponse(['status' => 'sent', 'to' => $to]);
    }
}
