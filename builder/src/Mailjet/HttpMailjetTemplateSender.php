<?php

declare(strict_types=1);

namespace App\Mailjet;

use App\Entity\Mailjet;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Implémentation REST Mailjet 3.1 /send avec template.
 *
 * @see https://dev.mailjet.com/email/guides/send-api-v31/#send-with-template
 */
final class HttpMailjetTemplateSender implements MailjetTemplateSenderInterface
{
    private const ENDPOINT = 'https://api.mailjet.com/v3.1/send';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    public function sendTemplate(
        Mailjet $mailjetConfig,
        int $templateId,
        bool $templateLanguage,
        string $toEmail,
        ?string $toName,
        array $variables,
    ): MailjetTemplateSendResult {
        $to = ['Email' => $toEmail];
        if ($toName !== null && $toName !== '') {
            $to['Name'] = $toName;
        }

        $body = [
            'Messages' => [
                [
                    'To' => [$to],
                    'TemplateID' => $templateId,
                    'TemplateLanguage' => $templateLanguage,
                    'Variables' => (object) $variables,
                ],
            ],
        ];

        try {
            $response = $this->httpClient->request('POST', self::ENDPOINT, [
                'auth_basic' => [$mailjetConfig->getApiKeyPublic(), $mailjetConfig->getApiKeyPrivate()],
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
