<?php

declare(strict_types=1);

namespace App\ServiceIntegration;

/**
 * Validation et schémas d’aide pour la config JSON des connecteurs.
 */
final class ServiceConnectionConfigValidator
{
    /**
     * @param array<string, mixed> $config
     */
    public function validateShape(string $type, array $config): ?string
    {
        $httpsUrl = static function (mixed $v): bool {
            $u = \is_string($v) ? trim($v) : '';

            return str_starts_with(strtolower($u), 'https://') && filter_var($u, FILTER_VALIDATE_URL) !== false;
        };

        return match ($type) {
            ServiceIntegrationType::MAILJET => $this->nonEmptyStrings($config, ['apiKeyPublic', 'apiKeyPrivate'])
                ? null
                : 'Mailjet : apiKeyPublic et apiKeyPrivate sont requis.',
            ServiceIntegrationType::SLACK,
            ServiceIntegrationType::TEAMS,
            ServiceIntegrationType::DISCORD,
            ServiceIntegrationType::GOOGLE_CHAT,
            ServiceIntegrationType::MATTERMOST,
            ServiceIntegrationType::PACFLOW => $httpsUrl($config['webhookUrl'] ?? '') ? null : 'Renseignez une webhookUrl HTTPS valide.',
            ServiceIntegrationType::TWILIO_SMS => $this->nonEmptyStrings($config, ['accountSid', 'authToken', 'fromNumber'])
                ? null
                : 'Twilio : accountSid, authToken et fromNumber sont requis.',
            ServiceIntegrationType::VONAGE_SMS => $this->nonEmptyStrings($config, ['apiKey', 'apiSecret', 'from'])
                ? null
                : 'Vonage : apiKey, apiSecret et from sont requis.',
            ServiceIntegrationType::MESSAGEBIRD_SMS => $this->nonEmptyStrings($config, ['accessKey', 'originator'])
                ? null
                : 'MessageBird : accessKey et originator sont requis.',
            ServiceIntegrationType::SMSFACTOR_SMS => $this->nonEmptyStrings($config, ['apiToken', 'sender'])
                ? null
                : 'SMSFactor : apiToken et sender sont requis.',
            ServiceIntegrationType::TELEGRAM => $this->nonEmptyStrings($config, ['botToken', 'chatId'])
                ? null
                : 'Telegram : botToken et chatId sont requis.',
            ServiceIntegrationType::PUSHOVER => $this->nonEmptyStrings($config, ['appToken', 'userKey'])
                ? null
                : 'Pushover : appToken et userKey sont requis.',
            ServiceIntegrationType::HTTP_WEBHOOK => $httpsUrl($config['url'] ?? '') ? null : 'Renseignez une url HTTPS valide.',
            default => 'Type inconnu.',
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function schemaHint(string $type): array
    {
        return match ($type) {
            ServiceIntegrationType::MAILJET => [
                'apiKeyPublic' => 'string (clé API REST publique Mailjet)',
                'apiKeyPrivate' => 'string (clé API secrète)',
            ],
            ServiceIntegrationType::SLACK,
            ServiceIntegrationType::TEAMS,
            ServiceIntegrationType::DISCORD,
            ServiceIntegrationType::GOOGLE_CHAT,
            ServiceIntegrationType::MATTERMOST,
            ServiceIntegrationType::PACFLOW => [
                'webhookUrl' => 'https://…',
            ],
            ServiceIntegrationType::TWILIO_SMS => [
                'accountSid' => 'AC…',
                'authToken' => '…',
                'fromNumber' => '+33…',
            ],
            ServiceIntegrationType::VONAGE_SMS => [
                'apiKey' => 'xxxxxxxx',
                'apiSecret' => 'xxxxxxxxXXXXXXXX',
                'from' => 'Acme ou +33123456789',
            ],
            ServiceIntegrationType::MESSAGEBIRD_SMS => [
                'accessKey' => 'live_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
                'originator' => 'Webhooky ou +33123456789',
            ],
            ServiceIntegrationType::SMSFACTOR_SMS => [
                'apiToken' => 'jeton API (my.smsfactor.com → Développeurs)',
                'sender' => 'Webhooky (max. 11 car. alphanum. ou numéro)',
            ],
            ServiceIntegrationType::TELEGRAM => [
                'botToken' => '123456:ABC…',
                'chatId' => '123456789',
            ],
            ServiceIntegrationType::PUSHOVER => [
                'appToken' => '…',
                'userKey' => '…',
            ],
            ServiceIntegrationType::HTTP_WEBHOOK => [
                'url' => 'https://…',
                'method' => 'POST',
                'headers' => ['Authorization' => 'Bearer …'],
            ],
            default => [],
        };
    }

    /**
     * Exemple JSON « rempli » pour l’aide UI (données fictives).
     *
     * @return array<string, mixed>
     */
    public function exampleFilled(string $type): array
    {
        return match ($type) {
            ServiceIntegrationType::MAILJET => [
                'apiKeyPublic' => 'a1b2c3d4e5f6789012345678abcdef12',
                'apiKeyPrivate' => '9f8e7d6c5b4a39281716151413121109',
            ],
            ServiceIntegrationType::SLACK => [
                'webhookUrl' => 'https://example.invalid/slack-incoming-webhook-a-coller-depuis-l-app',
            ],
            ServiceIntegrationType::TEAMS => [
                'webhookUrl' => 'https://outlook.office.com/webhook/00000000-0000-0000-0000-000000000000@00000000-0000-0000-0000-000000000000/IncomingWebhook/xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx/00000000-0000-0000-0000-000000000000',
            ],
            ServiceIntegrationType::DISCORD => [
                'webhookUrl' => 'https://discord.com/api/webhooks/1234567890123456789/abcdefghijklmnopqrstuvwxyz1234567890ABCDEFGH',
            ],
            ServiceIntegrationType::GOOGLE_CHAT => [
                'webhookUrl' => 'https://chat.googleapis.com/v1/spaces/AAAAxxxxx/messages?key=AIzaSyDdI0hCZtE6vySjMm-WEfRq3CPzqKqqsHI&token=xxxxxxxxxxxx',
            ],
            ServiceIntegrationType::MATTERMOST => [
                'webhookUrl' => 'https://mattermost.example.com/hooks/abcdefghijklmnop',
            ],
            ServiceIntegrationType::PACFLOW => [
                'webhookUrl' => 'https://api.pacflow.fr/api/webhooks/1234567890123456789/abcdefghijklmnopqrstuvwxyz1234567890ABCDEFGH',
            ],
            ServiceIntegrationType::TWILIO_SMS => [
                'accountSid' => 'ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
                'authToken' => 'your_auth_token_here',
                'fromNumber' => '+33123456789',
            ],
            ServiceIntegrationType::VONAGE_SMS => [
                'apiKey' => 'a1b2c3d4',
                'apiSecret' => 'AbCdEfGhIjKlMnOp',
                'from' => 'Webhooky',
            ],
            ServiceIntegrationType::MESSAGEBIRD_SMS => [
                'accessKey' => 'live_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
                'originator' => '+33123456789',
            ],
            ServiceIntegrationType::SMSFACTOR_SMS => [
                'apiToken' => 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.example',
                'sender' => 'Webhooky',
            ],
            ServiceIntegrationType::TELEGRAM => [
                'botToken' => '123456789:AAHevxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
                'chatId' => '-1001234567890',
            ],
            ServiceIntegrationType::PUSHOVER => [
                'appToken' => 'azGDORePK8gMaC0QOYAMyEEuzJnyUi',
                'userKey' => 'uQiRzpo4DXghDmr9QzzQ9UzrEFaJohq7',
            ],
            ServiceIntegrationType::HTTP_WEBHOOK => [
                'url' => 'https://api.example.com/v1/inbound',
                'method' => 'POST',
                'headers' => [
                    'Authorization' => 'Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9…',
                    'Content-Type' => 'application/json',
                ],
            ],
            default => [],
        };
    }

    /**
     * @param array<string, mixed> $config
     * @param list<string>         $keys
     */
    private function nonEmptyStrings(array $config, array $keys): bool
    {
        foreach ($keys as $k) {
            $v = isset($config[$k]) ? trim((string) $config[$k]) : '';
            if ($v === '') {
                return false;
            }
        }

        return true;
    }
}
