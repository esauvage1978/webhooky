<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class AuthMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly UrlGeneratorInterface $urlGenerator,
        #[Autowire('%env(MAILER_FROM)%')]
        private readonly string $mailerFrom,
        #[Autowire('%env(APP_PUBLIC_URL)%')]
        private readonly string $appPublicUrl,
        #[Autowire('%env(DEFAULT_URI)%')]
        private readonly string $defaultUri,
    ) {
    }

    public function sendEmailVerification(User $user, string $plainToken): void
    {
        $verifyUrl = $this->absoluteUrl(
            $this->urlGenerator->generate('verify_email', ['token' => $plainToken]),
        );

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

    public function sendPasswordReset(User $user, string $plainToken): void
    {
        $resetUrl = $this->absoluteUrl('/reinitialisation-mot-de-passe?token='.rawurlencode($plainToken));

        $email = (new TemplatedEmail())
            ->from(new Address($this->mailerFrom))
            ->to($user->getEmail())
            ->subject('Réinitialisation de votre mot de passe — Zapier Hub')
            ->htmlTemplate('email/password_reset.html.twig')
            ->context([
                'resetUrl' => $resetUrl,
                'userEmail' => $user->getEmail(),
            ]);

        $this->mailer->send($email);
    }

    public function sendUserInvitation(User $user, string $plainToken): void
    {
        $url = $this->absoluteUrl('/invitation?token='.rawurlencode($plainToken));

        $email = (new TemplatedEmail())
            ->from(new Address($this->mailerFrom))
            ->to($user->getEmail())
            ->subject('Votre accès Webhooky — définir votre mot de passe')
            ->htmlTemplate('email/user_invitation.html.twig')
            ->context([
                'inviteUrl' => $url,
                'userEmail' => $user->getEmail(),
            ]);

        $this->mailer->send($email);
    }

    private function absoluteUrl(string $pathOrRouteOutput): string
    {
        $base = trim($this->appPublicUrl) !== '' ? $this->appPublicUrl : $this->defaultUri;
        $base = rtrim($base, '/');
        if (str_starts_with($pathOrRouteOutput, 'http://') || str_starts_with($pathOrRouteOutput, 'https://')) {
            return $pathOrRouteOutput;
        }

        return $base.($pathOrRouteOutput[0] === '/' ? '' : '/').$pathOrRouteOutput;
    }
}
