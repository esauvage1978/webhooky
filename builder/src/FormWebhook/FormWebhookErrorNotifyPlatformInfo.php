<?php

declare(strict_types=1);

namespace App\FormWebhook;

/**
 * État de la configuration plateforme pour les alertes d’erreur (webhook formulaire + repli SMTP).
 */
final readonly class FormWebhookErrorNotifyPlatformInfo
{
    public function __construct(
        private ErrorNotifyWebhookUrlResolver $errorNotifyWebhookUrlResolver,
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
        $url = $this->errorNotifyWebhookUrlResolver->resolve();
        $segment = $url !== '' ? FormWebhookIngressTokenParser::extractTokenSegmentFromUrl($url) : null;
        $tokenOk = $segment !== null && FormWebhookIngressTokenParser::isValidIngressToken($segment);

        return [
            'primaryChannelForErrors' => $tokenOk ? 'webhook_form' : 'smtp_only',
            'errorNotifyWebhookConfigured' => $tokenOk,
            'mailjetApiKeysConfigured' => false,
            'resolvedMailjetTemplateId' => null,
        ];
    }
}
