<?php

declare(strict_types=1);

namespace App\ServiceIntegration;

/**
 * Types stockés dans {@see \App\Entity\ServiceConnection}.
 * Les actions de déclencheur utilisent la même chaîne pour actionType.
 */
final class ServiceIntegrationType
{
    public const MAILJET = 'mailjet';

    public const SLACK = 'slack';

    public const TEAMS = 'teams';

    public const DISCORD = 'discord';

    public const GOOGLE_CHAT = 'google_chat';

    public const MATTERMOST = 'mattermost';

    public const TWILIO_SMS = 'twilio_sms';

    /** Vonage (ex-Nexmo) — API REST SMS */
    public const VONAGE_SMS = 'vonage_sms';

    /** MessageBird — API REST SMS */
    public const MESSAGEBIRD_SMS = 'messagebird_sms';

    /** SMSFactor — API REST SMS (https://www.smsfactor.com/) */
    public const SMSFACTOR_SMS = 'smsfactor_sms';

    public const TELEGRAM = 'telegram';

    public const HTTP_WEBHOOK = 'http_webhook';

    public const PUSHOVER = 'pushover';

    public const PACFLOW = 'pacflow';

    /**
     * @return list<string>
     */
    public static function connectionTypes(): array
    {
        return [
            self::MAILJET,
            self::SLACK,
            self::TEAMS,
            self::DISCORD,
            self::GOOGLE_CHAT,
            self::MATTERMOST,
            self::TWILIO_SMS,
            self::VONAGE_SMS,
            self::MESSAGEBIRD_SMS,
            self::SMSFACTOR_SMS,
            self::TELEGRAM,
            self::HTTP_WEBHOOK,
            self::PUSHOVER,
            self::PACFLOW,
        ];
    }

    /**
     * @return array<string, string>
     */
    /**
     * Page d’accueil ou documentation officielle du fournisseur (lien UI).
     *
     * @return array<string, string>
     */
    public static function vendorUrls(): array
    {
        return [
            self::MAILJET => 'https://www.mailjet.com/',
            self::SLACK => 'https://slack.com/',
            self::TEAMS => 'https://www.microsoft.com/microsoft-teams',
            self::DISCORD => 'https://discord.com/',
            self::GOOGLE_CHAT => 'https://workspace.google.com/products/chat/',
            self::MATTERMOST => 'https://mattermost.com/',
            self::TWILIO_SMS => 'https://www.twilio.com/',
            self::VONAGE_SMS => 'https://www.vonage.com/',
            self::MESSAGEBIRD_SMS => 'https://bird.com/',
            self::SMSFACTOR_SMS => 'https://www.smsfactor.com/',
            self::TELEGRAM => 'https://telegram.org/',
            self::PUSHOVER => 'https://pushover.net/',
            self::PACFLOW => 'https://api.pacflow.fr/',
        ];
    }

    public static function vendorUrl(string $type): ?string
    {
        return self::vendorUrls()[$type] ?? null;
    }

    public static function labels(): array
    {
        return [
            self::MAILJET => 'Mailjet (e-mail transactionnel)',
            self::SLACK => 'Slack — Webhook entrant',
            self::TEAMS => 'Microsoft Teams — Connecteur entrant',
            self::DISCORD => 'Discord — Webhook',
            self::GOOGLE_CHAT => 'Google Chat — Webhook',
            self::MATTERMOST => 'Mattermost — Webhook entrant',
            self::TWILIO_SMS => 'Twilio — SMS',
            self::VONAGE_SMS => 'Vonage — SMS',
            self::MESSAGEBIRD_SMS => 'MessageBird — SMS',
            self::SMSFACTOR_SMS => 'SMSFactor — SMS',
            self::TELEGRAM => 'Telegram — Bot',
            self::HTTP_WEBHOOK => 'HTTP — URL personnalisée',
            self::PUSHOVER => 'Pushover — Notifications push',
            self::PACFLOW => 'Pacflow — Webhook entrant',
        ];
    }

    public static function isConnectionType(string $type): bool
    {
        return \in_array($type, self::connectionTypes(), true);
    }
}
