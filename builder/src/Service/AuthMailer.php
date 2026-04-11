<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ApplicationErrorLog;
use App\Entity\User;
use App\FormWebhook\FormWebhookIngressHandler;
use App\Logging\ApplicationErrorLogger;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class AuthMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly FormWebhookIngressHandler $formWebhookIngressHandler,
        private readonly LoggerInterface $logger,
        private readonly RegisterVerifyWebhookUrlResolver $registerVerifyWebhookUrlResolver,
        #[Autowire('%env(WEBHOOKY_REGISTER_VERIFY_WEBHOOK_URL)%')]
        private readonly string $registerVerifyWebhookUrlEnv,
        private readonly UserInviteWebhookUrlResolver $userInviteWebhookUrlResolver,
        #[Autowire('%env(WEBHOOKY_USER_INVITE_WEBHOOK_URL)%')]
        private readonly string $userInviteWebhookUrlEnv,
        #[Autowire('%env(MAILER_FROM)%')]
        private readonly string $mailerFrom,
        /** Surcharge explicite (staging, tests locaux avec liens locaux). Vide = URL publique canonique ci-dessous. */
        #[Autowire('%env(APP_PUBLIC_URL)%')]
        private readonly string $appPublicUrl,
        private readonly WebhookyPublicUrlResolver $webhookyPublicUrlResolver,
        private readonly ApplicationErrorLogger $applicationErrorLogger,
    ) {
    }

    public function sendEmailVerification(User $user, string $plainToken): void
    {
        $verifyUrl = $this->absoluteUrl(
            $this->urlGenerator->generate('verify_email', ['token' => $plainToken]),
        );

        $webhookUrl = $this->resolveRegisterVerifyWebhookUrl();
        $token = $webhookUrl !== '' ? $this->extractFormWebhookToken($webhookUrl) : null;

        if ($webhookUrl !== '' && $token === null) {
            $this->logger->error('AuthMailer : WEBHOOKY_REGISTER_VERIFY_WEBHOOK_URL / option webhooky_register_verify_webhook_url ne contient pas un jeton /webhook/form/{uuid} — inscription sans appel webhook.', [
                'webhookUrl' => $webhookUrl,
            ]);
        }

        if ($token !== null) {
            $appOrigin = $this->appOrigin();
            $payload = [
                'source' => 'webhooky.builders-register',
                'user_email' => $user->getEmail(),
                // Alias pour un mapping qui utilise la clé POST « email » (comportement par défaut des actions).
                'email' => $user->getEmail(),
                'verify_url' => $verifyUrl,
                'app_url' => $appOrigin,
                'expires_note' => 'Ce lien expire dans 48 heures.',
                'submitted_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            ];

            try {
                $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                $symfonyRequest = Request::create(
                    '/webhook/form/'.$token,
                    'POST',
                    [],
                    [],
                    [],
                    [
                        'CONTENT_TYPE' => 'application/json',
                        'HTTP_ACCEPT' => 'application/json',
                        'REMOTE_ADDR' => '127.0.0.1',
                    ],
                    $json,
                );

                $ingressResponse = $this->formWebhookIngressHandler->handle($symfonyRequest, $token);
                $status = $ingressResponse->getStatusCode();
                $body = json_decode($ingressResponse->getContent(), true);

                if ($status === 200 && \is_array($body) && ($body['ok'] ?? false) === true) {
                    return;
                }

                $this->logger->warning('AuthMailer : le webhook de confirmation n’a pas réussi (repli mailer Symfony)', [
                    'status' => $status,
                    'body' => $body,
                    'webhookToken' => $token,
                ]);
            } catch (\Throwable $e) {
                $this->applicationErrorLogger->logThrowable($e, null, ApplicationErrorLog::SOURCE_HANDLED, [
                    'handler' => 'auth_mailer_register_verify_webhook',
                    'webhookToken' => $token,
                    'userId' => $user->getId(),
                ]);
                $this->logger->warning('AuthMailer : exception lors de l’appel interne au webhook de confirmation', [
                    'exception' => $e,
                    'webhookToken' => $token,
                ]);
            }
        }

        $this->sendEmailVerificationSymfony($user, $verifyUrl);
    }

    public function sendPasswordReset(User $user, string $plainToken): void
    {
        $resetUrl = $this->absoluteUrl('/reinitialisation-mot-de-passe?token='.rawurlencode($plainToken));

        $email = (new TemplatedEmail())
            ->from(new Address($this->mailerFrom))
            ->to($user->getEmail())
            ->subject('Réinitialisation de votre mot de passe — Webhooky')
            ->htmlTemplate('email/password_reset.html.twig')
            ->context([
                'resetUrl' => $resetUrl,
                'userEmail' => $user->getEmail(),
            ]);

        $this->mailer->send($email);
    }

    public function sendUserInvitation(User $user, string $plainToken): void
    {
        $inviteUrl = $this->absoluteUrl('/invitation?token='.rawurlencode($plainToken));

        $webhookUrl = $this->resolveUserInviteWebhookUrl();
        $token = $webhookUrl !== '' ? $this->extractFormWebhookToken($webhookUrl) : null;

        if ($webhookUrl !== '' && $token === null) {
            $this->logger->error('AuthMailer : WEBHOOKY_USER_INVITE_WEBHOOK_URL / option webhooky_user_invite_webhook_url ne contient pas un jeton /webhook/form/{uuid} — invitation sans appel webhook.', [
                'webhookUrl' => $webhookUrl,
            ]);
        }

        if ($token !== null) {
            $appOrigin = $this->appOrigin();
            $org = $user->getOrganization();
            $expiresNote = $this->formatInviteExpiresNote($user);

            $payload = [
                'source' => 'webhooky.builders-user-invite',
                'user_email' => $user->getEmail(),
                'email' => $user->getEmail(),
                'name' => $this->recipientDisplayNameFromEmail($user->getEmail()),
                'organization_name' => $org !== null ? $org->getName() : '',
                'invite_url' => $inviteUrl,
                'app_url' => $appOrigin,
                'expires_note' => $expiresNote,
                'submitted_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            ];

            try {
                $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                $symfonyRequest = Request::create(
                    '/webhook/form/'.$token,
                    'POST',
                    [],
                    [],
                    [],
                    [
                        'CONTENT_TYPE' => 'application/json',
                        'HTTP_ACCEPT' => 'application/json',
                        'REMOTE_ADDR' => '127.0.0.1',
                    ],
                    $json,
                );

                $ingressResponse = $this->formWebhookIngressHandler->handle($symfonyRequest, $token);
                $status = $ingressResponse->getStatusCode();
                $body = json_decode($ingressResponse->getContent(), true);

                if ($status === 200 && \is_array($body) && ($body['ok'] ?? false) === true) {
                    return;
                }

                $this->logger->warning('AuthMailer : le webhook d’invitation n’a pas réussi (repli mailer Symfony)', [
                    'status' => $status,
                    'body' => $body,
                    'webhookToken' => $token,
                ]);
            } catch (\Throwable $e) {
                $this->applicationErrorLogger->logThrowable($e, null, ApplicationErrorLog::SOURCE_HANDLED, [
                    'handler' => 'auth_mailer_user_invite_webhook',
                    'webhookToken' => $token,
                    'userId' => $user->getId(),
                ]);
                $this->logger->warning('AuthMailer : exception lors de l’appel interne au webhook d’invitation', [
                    'exception' => $e,
                    'webhookToken' => $token,
                ]);
            }
        }

        $this->sendUserInvitationSymfony($user, $inviteUrl);
    }

    private function sendEmailVerificationSymfony(User $user, string $verifyUrl): void
    {
        $email = (new TemplatedEmail())
            ->from(new Address($this->mailerFrom))
            ->to($user->getEmail())
            ->subject('Confirmez votre compte Webhooky')
            ->htmlTemplate('email/verify_email.html.twig')
            ->context([
                'verifyUrl' => $verifyUrl,
                'userEmail' => $user->getEmail(),
            ]);

        $this->mailer->send($email);
    }

    private function resolveRegisterVerifyWebhookUrl(): string
    {
        $fromEnv = trim($this->registerVerifyWebhookUrlEnv);
        if ($fromEnv !== '') {
            return $fromEnv;
        }

        return trim($this->registerVerifyWebhookUrlResolver->resolve());
    }

    private function resolveUserInviteWebhookUrl(): string
    {
        $fromEnv = trim($this->userInviteWebhookUrlEnv);
        if ($fromEnv !== '') {
            return $fromEnv;
        }

        return trim($this->userInviteWebhookUrlResolver->resolve());
    }

    private function recipientDisplayNameFromEmail(string $email): string
    {
        $local = explode('@', $email, 2)[0] ?? '';
        $local = trim($local);

        return $local !== '' ? $local : $email;
    }

    private function formatInviteExpiresNote(User $user): string
    {
        $at = $user->getInviteExpiresAt();
        if ($at instanceof \DateTimeImmutable) {
            return 'Ce lien expire le '.$at->format('d/m/Y \à H:i').' (heure du serveur).';
        }

        return 'Ce lien expire dans 14 jours.';
    }

    /**
     * Extrait le jeton public /webhook/form/{token} depuis une URL absolue ou relative.
     */
    private function extractFormWebhookToken(string $webhookUrl): ?string
    {
        if (preg_match('#/webhook/form/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})#i', $webhookUrl, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    private function appOrigin(): string
    {
        return $this->publicBaseForUserFacingLinks();
    }

    private function absoluteUrl(string $pathOrRouteOutput): string
    {
        $base = $this->publicBaseForUserFacingLinks();
        if (str_starts_with($pathOrRouteOutput, 'http://') || str_starts_with($pathOrRouteOutput, 'https://')) {
            return $pathOrRouteOutput;
        }

        return $base.($pathOrRouteOutput[0] === '/' ? '' : '/').$pathOrRouteOutput;
    }

    /**
     * Évite DEFAULT_URI / http://localhost dans les e-mails lorsque APP_PUBLIC_URL est vide (cas fréquent en dev / hébergeur).
     */
    private function publicBaseForUserFacingLinks(): string
    {
        $fromEnv = trim($this->appPublicUrl);
        if ($fromEnv !== '') {
            return rtrim($fromEnv, '/');
        }

        return rtrim(trim($this->webhookyPublicUrlResolver->resolve()), '/');
    }
}
