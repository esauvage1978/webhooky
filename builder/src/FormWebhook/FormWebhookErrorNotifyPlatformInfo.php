<?php

declare(strict_types=1);

namespace App\FormWebhook;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * État de la configuration plateforme pour les alertes d’erreur (webhook formulaire + repli SMTP).
 */
final readonly class FormWebhookErrorNotifyPlatformInfo
{
    public function __construct(
        #[Autowire('%app.webhooky.error_notify_webhook_url%')]
        private string $errorNotifyWebhookUrlDefault,
        #[Autowire('%env(WEBHOOKY_ERROR_NOTIFY_WEBHOOK_URL)%')]
        private string $errorNotifyWebhookUrlEnv,
    ) {
    }

    /**
     * @return array{
     *   primaryChannelForErrors: 'webhook_form'|'smtp_only',
     *   errorNotifyWebhookConfigured: bool,
     *   mailjetApiKeysConfigured: false,
     *   resolvedMailjetTemplateId: null
     * }
     */
    public function snapshot(): array
    {
        $url = trim($this->errorNotifyWebhookUrlEnv) !== '' ? trim($this->errorNotifyWebhookUrlEnv) : trim($this->errorNotifyWebhookUrlDefault);
        $tokenOk = $url !== '' && preg_match('#/webhook/form/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})#i', $url) === 1;

        return [
            'primaryChannelForErrors' => $tokenOk ? 'webhook_form' : 'smtp_only',
            'errorNotifyWebhookConfigured' => $tokenOk,
            'mailjetApiKeysConfigured' => false,
            'resolvedMailjetTemplateId' => null,
        ];
    }
}
