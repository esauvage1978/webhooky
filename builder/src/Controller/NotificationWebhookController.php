<?php

declare(strict_types=1);

namespace App\Controller;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Webhook générique : envoi d’un e-mail simple à partir d’un corps JSON (to, subject, text, html optionnel).
 * Protégé par secret partagé (Bearer ou HMAC-SHA256 du body).
 */
final class NotificationWebhookController
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly KernelInterface $kernel,
        #[Autowire('%env(string:default:notification_webhook_secret_default:NOTIFICATION_WEBHOOK_SECRET)%')]
        private readonly string $notificationWebhookSecret,
    ) {
    }

    #[Route('/webhook/notification', name: 'webhook_notification', methods: ['POST'])]
    public function __invoke(
        Request $request,
        MailerInterface $mailer,
        #[Autowire(env: 'MAILER_FROM')]
        string $mailerFrom,
    ): JsonResponse {
        $secret = trim($this->notificationWebhookSecret);
        $isProd = $this->kernel->getEnvironment() === 'prod';

        if ($secret === '') {
            if ($isProd) {
                $this->logger->warning('notification_webhook_rejected_empty_secret');

                return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
            }

            $this->logger->warning('notification_webhook_dev_without_secret');
        } elseif (!$this->isAuthorized($request, $secret)) {
            $this->logger->warning('notification_webhook_unauthorized', [
                'ip' => $request->getClientIp(),
            ]);

            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

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

        $to = str_replace(["\r", "\n", "\0"], '', $to);
        if (\is_string($subject)) {
            $subject = str_replace(["\r", "\n", "\0"], '', $subject);
        } else {
            $subject = 'Notification Webhooky';
        }

        if (!filter_var($mailerFrom, FILTER_VALIDATE_EMAIL)) {
            return new JsonResponse(
                ['error' => 'Configuration serveur invalide'],
                Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        $email = (new Email())
            ->from($mailerFrom)
            ->to($to)
            ->subject($subject)
            ->text(\is_string($text) && $text !== '' ? $text : '(vide)');

        if (isset($data['html']) && \is_string($data['html']) && $data['html'] !== '') {
            $email->html($data['html']);
        }

        try {
            $mailer->send($email);
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('notification_webhook_mailer_failed', [
                'exception' => $e::class,
            ]);

            return new JsonResponse(
                ['error' => 'Échec envoi e-mail'],
                Response::HTTP_BAD_GATEWAY,
            );
        }

        return new JsonResponse(['status' => 'sent']);
    }

    private function isAuthorized(Request $request, string $secret): bool
    {
        $auth = (string) $request->headers->get('Authorization', '');
        if (preg_match('/^Bearer\s+(.+)$/i', $auth, $m) === 1) {
            return hash_equals($secret, trim($m[1]));
        }

        $provided = (string) $request->headers->get('X-Webhooky-Signature', '');
        if ($provided === '') {
            return false;
        }

        $provided = preg_replace('/^sha256=/i', '', trim($provided)) ?? trim($provided);
        $expected = hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expected, $provided);
    }
}
