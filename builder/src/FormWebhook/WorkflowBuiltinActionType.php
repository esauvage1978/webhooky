<?php

declare(strict_types=1);

namespace App\FormWebhook;

/**
 * Actions exécutées côté plateforme (hors connecteurs tiers).
 */
final class WorkflowBuiltinActionType
{
    public const GSC_FETCH = 'gsc_fetch';

    public const AI_ACTION = 'ai_action';

    public const PARSE_JSON = 'parse_json';

    public const IF = 'if';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::GSC_FETCH, self::AI_ACTION, self::PARSE_JSON, self::IF];
    }

    public static function isBuiltin(string $actionType): bool
    {
        return \in_array($actionType, self::all(), true);
    }

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::GSC_FETCH => 'Google Search Console — requêtes',
            self::AI_ACTION => 'Agent IA',
            self::PARSE_JSON => 'Parse JSON',
            self::IF => 'Condition (saut d’étapes)',
        ];
    }
}
