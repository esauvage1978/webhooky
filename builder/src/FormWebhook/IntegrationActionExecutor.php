<?php

declare(strict_types=1);

namespace App\FormWebhook;

use App\Entity\FormWebhookAction;
use App\Entity\FormWebhookActionLog;
use App\Security\OutboundUrlGuard;
use App\ServiceIntegration\ServiceConnectionSecretHelper;
use App\ServiceIntegration\ServiceIntegrationType;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Exécute les actions basées sur {@see \App\Entity\ServiceConnection} (Slack, Twilio, etc.).
 */
final class IntegrationActionExecutor
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly VariableMapBuilder $variableMapBuilder,
        private readonly IntegrationPayloadInterpolator $interpolator,
        private readonly OutboundUrlGuard $outboundUrlGuard,
        private readonly ServiceConnectionSecretHelper $secretHelper,
        private readonly RecipientResolver $recipientResolver,
    ) {
    }

    /**
     * @param array<string, string> $parsed
     */
    public function execute(FormWebhookAction $action, array $parsed, FormWebhookActionLog $aLog): void
    {
        $conn = $action->getServiceConnection();
        if ($conn === null) {
            throw new \RuntimeException('Connecteur tiers manquant pour cette action.');
        }

        $type = $action->getActionType();
        if ($type !== $conn->getType()) {
            throw new \RuntimeException('Le type d’action ne correspond pas au connecteur sélectionné.');
        }

        /** @var array<string, mixed> $config */
        $config = $this->secretHelper->decryptSensitiveFields($conn->getConfig());
        $variables = $this->variableMapBuilder->build($parsed, $action->getVariableMapping());
        $aLog->setVariablesSent($variables);

        $text = $this->interpolator->interpolate($action->getPayloadTemplate(), $variables, $parsed);
        if (trim($text) === '') {
            $text = 'Webhooky — '.mb_substr((string) json_encode($parsed, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), 0, 3500);
        }

        match ($type) {
            ServiceIntegrationType::SLACK,
            ServiceIntegrationType::TEAMS,
            ServiceIntegrationType::GOOGLE_CHAT => $this->postJson($this->requireUrl($config, 'webhookUrl'), ['text' => $this->truncate($text, 28000)], $aLog),
            ServiceIntegrationType::DISCORD => $this->postJson($this->requireUrl($config, 'webhookUrl'), ['content' => $this->truncate($text, 1900)], $aLog),
            ServiceIntegrationType::MATTERMOST => $this->postJson($this->requireUrl($config, 'webhookUrl'), ['text' => $this->truncate($text, 16000)], $aLog),
            ServiceIntegrationType::PACFLOW => $this->sendPacflow($config, $variables, $parsed, $aLog),
            ServiceIntegrationType::TWILIO_SMS => $this->sendTwilio($config, $action, $parsed, $this->truncate($text, 1400), $aLog),
            ServiceIntegrationType::VONAGE_SMS => $this->sendVonage($config, $action, $parsed, $this->truncate($text, 1007), $aLog),
            ServiceIntegrationType::MESSAGEBIRD_SMS => $this->sendMessageBird($config, $action, $parsed, $this->truncate($text, 1530), $aLog),
            ServiceIntegrationType::SMSFACTOR_SMS => $this->sendSmsFactor($config, $action, $parsed, $this->truncate($text, 1530), $aLog),
            ServiceIntegrationType::TELEGRAM => $this->sendTelegram($config, $this->truncate($text, 3900), $aLog),
            ServiceIntegrationType::HTTP_WEBHOOK => $this->sendHttpWebhook($config, $action, $variables, $parsed, $aLog),
            ServiceIntegrationType::PUSHOVER => $this->sendPushover($config, $this->truncate($text, 1024), $aLog),
            default => throw new \RuntimeException(sprintf('Type de connecteur non géré : %s', $type)),
        };
    }

    /**
     * @param array<string, mixed> $config
     */
    private function requireUrl(array $config, string $key): string
    {
        $u = isset($config[$key]) ? trim((string) $config[$key]) : '';
        if ($u === '' || !filter_var($u, FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException(sprintf('URL invalide dans la configuration (%s).', $key));
        }

        return $this->outboundUrlGuard->assertSafe($u, allowPrivateNetworks: false, requireHttps: true);
    }

    private function truncate(string $s, int $max): string
    {
        if (mb_strlen($s) <= $max) {
            return $s;
        }

        return mb_substr($s, 0, $max).'…';
    }

    /**
     * Enregistre status + corps (tronqué) sur le journal d’action.
     *
     * @return array{0: int, 1: string}
     */
    private function recordOutboundHttp(ResponseInterface $response, FormWebhookActionLog $aLog): array
    {
        $status = $response->getStatusCode();
        $raw = $response->getContent(false);
        $aLog->setHttpStatus($status);
        $aLog->setProviderResponseBody(mb_substr($raw, 0, 16000));

        return [$status, $raw];
    }

    private function assertHttpOk(int $status, string $errorMessage): void
    {
        if ($status >= 400) {
            throw new \RuntimeException($errorMessage);
        }
    }

    /**
     * @param array<string, mixed> $body
     */
    private function postJson(string $url, array $body, FormWebhookActionLog $aLog): void
    {
        $response = $this->httpClient->request('POST', $url, [
            'headers' => ['Content-Type' => 'application/json'],
            'json' => $body,
            'timeout' => 25,
        ]);
        [$status] = $this->recordOutboundHttp($response, $aLog);
        $this->assertHttpOk($status, sprintf('Réponse HTTP %d du service distant.', $status));
    }

    /**
     * @param array<string, string> $parsed
     */
    private function requireSmsRecipient(FormWebhookAction $action, array $parsed, FormWebhookActionLog $aLog): string
    {
        $to = $this->recipientResolver->resolvePhone($action, $parsed);
        if ($to === '') {
            throw new \InvalidArgumentException('Numéro destinataire SMS manquant (champ POST ou numéro par défaut).');
        }
        $aLog->setRecipient($to);

        return $to;
    }

    /**
     * @param array<string, mixed>  $config
     * @param array<string, string> $parsed
     */
    private function sendTwilio(array $config, FormWebhookAction $action, array $parsed, string $bodyText, FormWebhookActionLog $aLog): void
    {
        $sid = isset($config['accountSid']) ? trim((string) $config['accountSid']) : '';
        $token = isset($config['authToken']) ? trim((string) $config['authToken']) : '';
        $from = isset($config['fromNumber']) ? trim((string) $config['fromNumber']) : '';
        if ($sid === '' || $token === '' || $from === '') {
            throw new \InvalidArgumentException('Configuration Twilio incomplète (accountSid, authToken, fromNumber).');
        }

        $to = $this->requireSmsRecipient($action, $parsed, $aLog);
        $url = sprintf('https://api.twilio.com/2010-04-01/Accounts/%s/Messages.json', rawurlencode($sid));
        $response = $this->httpClient->request('POST', $url, [
            'auth_basic' => [$sid, $token],
            'body' => [
                'To' => $to,
                'From' => $from,
                'Body' => $bodyText,
            ],
            'timeout' => 30,
        ]);
        [$status] = $this->recordOutboundHttp($response, $aLog);
        $this->assertHttpOk($status, sprintf('Twilio HTTP %d', $status));
    }

    /**
     * @param array<string, mixed>  $config
     * @param array<string, string> $parsed
     */
    private function sendVonage(array $config, FormWebhookAction $action, array $parsed, string $bodyText, FormWebhookActionLog $aLog): void
    {
        $apiKey = isset($config['apiKey']) ? trim((string) $config['apiKey']) : '';
        $apiSecret = isset($config['apiSecret']) ? trim((string) $config['apiSecret']) : '';
        $from = isset($config['from']) ? trim((string) $config['from']) : '';
        if ($apiKey === '' || $apiSecret === '' || $from === '') {
            throw new \InvalidArgumentException('Configuration Vonage incomplète (apiKey, apiSecret, from).');
        }

        $to = $this->requireSmsRecipient($action, $parsed, $aLog);
        $response = $this->httpClient->request('POST', 'https://rest.nexmo.com/sms/json', [
            'headers' => ['Content-Type' => 'application/json'],
            'json' => [
                'api_key' => $apiKey,
                'api_secret' => $apiSecret,
                'to' => $to,
                'from' => $from,
                'text' => $bodyText,
            ],
            'timeout' => 30,
        ]);
        [$status] = $this->recordOutboundHttp($response, $aLog);
        $this->assertHttpOk($status, sprintf('Vonage HTTP %d', $status));
    }

    /**
     * @param array<string, mixed>  $config
     * @param array<string, string> $parsed
     */
    private function sendMessageBird(array $config, FormWebhookAction $action, array $parsed, string $bodyText, FormWebhookActionLog $aLog): void
    {
        $accessKey = isset($config['accessKey']) ? trim((string) $config['accessKey']) : '';
        $originator = isset($config['originator']) ? trim((string) $config['originator']) : '';
        if ($accessKey === '' || $originator === '') {
            throw new \InvalidArgumentException('Configuration MessageBird incomplète (accessKey, originator).');
        }

        $to = $this->requireSmsRecipient($action, $parsed, $aLog);
        $recipient = preg_replace('/[^\d+]/', '', $to) ?? $to;

        $response = $this->httpClient->request('POST', 'https://rest.messagebird.com/messages', [
            'headers' => [
                'Authorization' => 'AccessKey '.$accessKey,
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'recipients' => [$recipient],
                'originator' => $originator,
                'body' => $bodyText,
            ],
            'timeout' => 30,
        ]);
        [$status] = $this->recordOutboundHttp($response, $aLog);
        $this->assertHttpOk($status, sprintf('MessageBird HTTP %d', $status));
    }

    /**
     * @see https://dev.smsfactor.com/fr/api/sms/send/send-campaign
     *
     * @param array<string, mixed>  $config
     * @param array<string, string> $parsed
     */
    private function sendSmsFactor(array $config, FormWebhookAction $action, array $parsed, string $bodyText, FormWebhookActionLog $aLog): void
    {
        $apiToken = isset($config['apiToken']) ? trim((string) $config['apiToken']) : '';
        $sender = isset($config['sender']) ? trim((string) $config['sender']) : '';
        if ($apiToken === '' || $sender === '') {
            throw new \InvalidArgumentException('Configuration SMSFactor incomplète (apiToken, sender).');
        }

        $to = $this->requireSmsRecipient($action, $parsed, $aLog);
        $gsm = preg_replace('/\D+/', '', $to) ?? '';
        if ($gsm === '') {
            throw new \InvalidArgumentException('Numéro destinataire SMS invalide.');
        }

        $response = $this->httpClient->request('POST', 'https://api.smsfactor.com/send', [
            'headers' => [
                'Authorization' => 'Bearer '.$apiToken,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'json' => [
                'sms' => [
                    'message' => [
                        'text' => $bodyText,
                        'sender' => $sender,
                    ],
                    'recipients' => [
                        'gsm' => [
                            ['value' => $gsm],
                        ],
                    ],
                ],
            ],
            'timeout' => 30,
        ]);
        [$status, $raw] = $this->recordOutboundHttp($response, $aLog);
        $this->assertHttpOk($status, sprintf('SMSFactor HTTP %d', $status));

        $decoded = json_decode($raw, true);
        if (\is_array($decoded) && isset($decoded['status']) && (int) $decoded['status'] !== 1) {
            $msg = isset($decoded['message']) ? trim((string) $decoded['message']) : 'erreur API';
            throw new \RuntimeException(sprintf('SMSFactor : %s (status %s)', $msg !== '' ? $msg : 'échec', (string) $decoded['status']));
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function sendTelegram(array $config, string $text, FormWebhookActionLog $aLog): void
    {
        $bot = isset($config['botToken']) ? trim((string) $config['botToken']) : '';
        $chatId = isset($config['chatId']) ? trim((string) $config['chatId']) : '';
        if ($bot === '' || $chatId === '') {
            throw new \InvalidArgumentException('Configuration Telegram incomplète (botToken, chatId).');
        }

        $url = sprintf('https://api.telegram.org/bot%s/sendMessage', rawurlencode($bot));
        $response = $this->httpClient->request('POST', $url, [
            'headers' => ['Content-Type' => 'application/json'],
            'json' => ['chat_id' => $chatId, 'text' => $text],
            'timeout' => 25,
        ]);
        [$status] = $this->recordOutboundHttp($response, $aLog);
        $this->assertHttpOk($status, sprintf('Telegram HTTP %d', $status));
    }

    /**
     * @param array<string, mixed>  $config
     * @param array<string, string> $variables
     * @param array<string, string> $parsed
     */
    private function sendPacflow(array $config, array $variables, array $parsed, FormWebhookActionLog $aLog): void
    {
        $body = $variables !== [] ? $variables : $parsed;
        $this->postJson($this->requireUrl($config, 'webhookUrl'), $body, $aLog);
    }

    /**
     * @param array<string, mixed>  $config
     * @param array<string, string> $variables
     * @param array<string, string> $parsed
     */
    private function sendHttpWebhook(array $config, FormWebhookAction $action, array $variables, array $parsed, FormWebhookActionLog $aLog): void
    {
        $url = $this->requireUrl($config, 'url');
        $method = strtoupper(trim((string) ($config['method'] ?? 'POST')));

        if (!\in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            $method = 'POST';
        }

        /** @var array<string, string> $headers */
        $headers = [];
        if (isset($config['headers']) && \is_array($config['headers'])) {
            foreach ($config['headers'] as $hk => $hv) {
                $headers[(string) $hk] = (string) $hv;
            }
        }

        $tpl = $action->getPayloadTemplate();
        $bodyStr = $this->interpolator->interpolate($tpl, $variables, $parsed);
        if (trim($bodyStr) === '') {
            $bodyStr = json_encode(['source' => 'webhooky', 'data' => $parsed], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            $headers['Content-Type'] ??= 'application/json';
        } else {
            try {
                json_decode($bodyStr, true, 512, JSON_THROW_ON_ERROR);
                $headers['Content-Type'] ??= 'application/json';
            } catch (\JsonException) {
                $headers['Content-Type'] ??= 'text/plain; charset=UTF-8';
            }
        }

        $response = $this->httpClient->request($method, $url, [
            'headers' => $headers,
            'body' => $bodyStr,
            'timeout' => 30,
        ]);
        [$status] = $this->recordOutboundHttp($response, $aLog);
        $this->assertHttpOk($status, sprintf('HTTP distant %d', $status));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function sendPushover(array $config, string $message, FormWebhookActionLog $aLog): void
    {
        $appToken = isset($config['appToken']) ? trim((string) $config['appToken']) : '';
        $userKey = isset($config['userKey']) ? trim((string) $config['userKey']) : '';
        if ($appToken === '' || $userKey === '') {
            throw new \InvalidArgumentException('Configuration Pushover incomplète (appToken, userKey).');
        }

        $response = $this->httpClient->request('POST', 'https://api.pushover.net/1/messages.json', [
            'body' => [
                'token' => $appToken,
                'user' => $userKey,
                'message' => $message,
            ],
            'timeout' => 25,
        ]);
        [$status] = $this->recordOutboundHttp($response, $aLog);
        $this->assertHttpOk($status, sprintf('Pushover HTTP %d', $status));
    }
}
