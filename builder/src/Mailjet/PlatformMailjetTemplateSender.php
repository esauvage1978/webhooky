<?php

declare(strict_types=1);

namespace App\Mailjet;

use App\Entity\ApplicationErrorLog;
use App\Logging\ApplicationErrorLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Envoi transactionnel Mailjet avec TemplateID et clés API explicites (hors entité organisation).
 * Utilisé pour les alertes plateforme (ex. échec d’exécution d’un webhook formulaire).
 */
final class PlatformMailjetTemplateSender
{
    private const ENDPOINT = 'https://api.mailjet.com/v3.1/send';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly ApplicationErrorLogger $applicationErrorLogger,
    ) {
    }

    /**
     * @param array<string, string|int|float|bool|null> $variables toutes les valeurs seront castées en chaîne côté JSON
     */
    public function sendTemplate(
        string $apiKeyPublic,
        string $apiKeyPrivate,
        int $templateId,
        string $fromEmail,
        string $fromName,
        string $toEmail,
        array $variables,
    ): MailjetTemplateSendResult {
        $stringVars = [];
        foreach ($variables as $k => $v) {
            if ($v === null) {
                $stringVars[(string) $k] = '';
            } elseif (\is_bool($v)) {
                $stringVars[(string) $k] = $v ? '1' : '0';
            } else {
                $stringVars[(string) $k] = (string) $v;
            }
        }

        $body = [
            'Messages' => [
                [
                    'From' => [
                        'Email' => $fromEmail,
                        'Name' => $fromName,
                    ],
                    'To' => [
                        ['Email' => $toEmail],
                    ],
                    'TemplateID' => $templateId,
                    'TemplateLanguage' => true,
                    'Variables' => (object) $stringVars,
                ],
            ],
        ];

        try {
            $response = $this->httpClient->request('POST', self::ENDPOINT, [
                'auth_basic' => [$apiKeyPublic, $apiKeyPrivate],
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'json' => $body,
                'timeout' => 20,
            ]);

            $status = $response->getStatusCode();
            $content = $response->getContent(false);

            if ($status < 200 || $status >= 300) {
                return new MailjetTemplateSendResult(false, $status, $content, null, $this->extractError($content));
            }

            $data = json_decode($content, true);
            $messageId = null;
            if (\is_array($data) && isset($data['Messages'][0]['To'][0]['MessageUUID'])) {
                $messageId = (string) $data['Messages'][0]['To'][0]['MessageUUID'];
            } elseif (\is_array($data) && isset($data['Messages'][0]['To'][0]['MessageID'])) {
                $messageId = (string) $data['Messages'][0]['To'][0]['MessageID'];
            } elseif (\is_array($data) && isset($data['Messages'][0]['MessageID'])) {
                $messageId = (string) $data['Messages'][0]['MessageID'];
            }

            return new MailjetTemplateSendResult(true, $status, $content, $messageId, null);
        } catch (\Throwable $e) {
            $this->applicationErrorLogger->logThrowable($e, null, ApplicationErrorLog::SOURCE_HANDLED, [
                'handler' => 'platform_mailjet_template_sender',
                'templateId' => $templateId,
            ]);

            return new MailjetTemplateSendResult(false, 0, null, null, $e->getMessage());
        }
    }

    private function extractError(string $content): string
    {
        $data = json_decode($content, true);
        if (!\is_array($data)) {
            return mb_substr($content, 0, 500);
        }

        if (isset($data['ErrorMessage'])) {
            return (string) $data['ErrorMessage'];
        }

        if (isset($data['Messages'][0]['Errors'][0]['ErrorMessage'])) {
            return (string) $data['Messages'][0]['Errors'][0]['ErrorMessage'];
        }

        return mb_substr($content, 0, 500);
    }
}
