<?php

declare(strict_types=1);

namespace App\Service\Audit;

use App\Entity\ResourceAuditLog;

/**
 * Prépare les détails d’audit workflow pour l’API (libellés lisibles, sans snapshots lourds).
 */
final class FormWebhookAuditDetailsPresenter
{
    private const DISPLAY_MAX_LEN = 4000;

    /** @var array<string, string> */
    private const KEY_LABELS = [
        'name' => 'Nom',
        'description' => 'Description',
        'active' => 'État actif / inactif',
        'organizationId' => 'Organisation',
        'projectId' => 'Projet',
        'notificationEmailSource' => 'Source de l’e-mail de notification',
        'notificationCustomEmail' => 'E-mail de notification (personnalisé)',
        'notifyOnError' => 'Notification en cas d’erreur',
        'metadata' => 'Métadonnées',
        'actions' => 'Actions du flux',
    ];

    /** @var array<string, string> */
    private const ACTION_FIELD_LABELS = [
        'sortOrder' => 'Ordre dans le flux',
        'active' => 'Exécution active',
        'actionType' => 'Type d’action',
        'serviceConnectionId' => 'Connecteur (ID)',
        'mailjetId' => 'Mailjet (ID compte legacy)',
        'mailjetTemplateId' => 'Modèle Mailjet (ID)',
        'templateLanguage' => 'Langage template Mailjet',
        'variableMapping' => 'Mapping variables → champs',
        'payloadTemplate' => 'Modèle de charge utile',
        'smsToPostKey' => 'Clé POST (numéro SMS)',
        'smsToDefault' => 'Numéro SMS par défaut',
        'recipientEmailPostKey' => 'Clé POST (e-mail destinataire)',
        'recipientNamePostKey' => 'Clé POST (nom destinataire)',
        'defaultRecipientEmail' => 'E-mail destinataire par défaut',
        'comment' => 'Commentaire / note',
    ];

    /** @var array<string, string> */
    private const ACTION_CHANGE_TYPE_LABELS = [
        'added' => 'Action ajoutée',
        'removed' => 'Action supprimée',
        'modified' => 'Action modifiée',
    ];

    /**
     * @param array<string, mixed> $details
     *
     * @return array<string, mixed>
     */
    public static function forApi(array $details, string $action): array
    {
        $out = $details;
        unset($out['after'], $out['snapshot'], $out['lastSnapshot']);

        if (isset($out['diff']) && \is_array($out['diff'])) {
            $out['diff'] = self::enrichDiffForApi($out['diff']);
        }

        if (ResourceAuditLog::ACTION_UPDATED === $action && isset($out['changedKeys']) && \is_array($out['changedKeys'])) {
            /** @var list<string> $keys */
            $keys = array_values(array_filter(
                $out['changedKeys'],
                static fn ($k) => \is_string($k) && $k !== '',
            ));
            $out['changedKeysLabels'] = self::labelsForKeys($keys);
            $prev = $out['previousVersion'] ?? null;
            $next = $out['version'] ?? null;
            if (\is_int($prev) || (is_string($prev) && ctype_digit((string) $prev))) {
                $prev = (int) $prev;
            } else {
                $prev = null;
            }
            if (\is_int($next) || (is_string($next) && ctype_digit((string) $next))) {
                $next = (int) $next;
            } else {
                $next = null;
            }
            if (null !== $prev && null !== $next) {
                $out['auditSummary'] = sprintf('Version %d → %d — champs modifiés ci-dessous.', $prev, $next);
            } elseif (null !== $next) {
                $out['auditSummary'] = sprintf('Nouvelle version enregistrée (v.%d).', $next);
            }
        }

        if (ResourceAuditLog::ACTION_CREATED === $action) {
            $dupId = $out['duplicateOfWebhookId'] ?? null;
            if (null !== $dupId && '' !== $dupId) {
                $dupName = $out['duplicateOfName'] ?? null;
                $label = \is_string($dupName) && $dupName !== ''
                    ? sprintf('« %s »', mb_substr($dupName, 0, 80))
                    : '#'.(string) $dupId;
                $out['auditSummary'] = sprintf('Workflow créé par duplication à partir de %s.', $label);
            } else {
                $out['auditSummary'] = 'Création du workflow — configuration initiale enregistrée.';
            }
        }

        if (ResourceAuditLog::ACTION_DELETED === $action) {
            $name = $out['name'] ?? null;
            $out['auditSummary'] = \is_string($name) && $name !== ''
                ? 'Suppression du workflow « '.mb_substr($name, 0, 120).' ».'
                : 'Suppression du workflow.';
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $diff
     *
     * @return array<string, mixed>
     */
    private static function enrichDiffForApi(array $diff): array
    {
        if (!isset($diff['changes']) || !\is_array($diff['changes'])) {
            return $diff;
        }
        $enriched = $diff;
        $enriched['changes'] = [];
        foreach ($diff['changes'] as $block) {
            if (!\is_array($block)) {
                continue;
            }
            $key = $block['key'] ?? '';
            $kind = $block['kind'] ?? '';
            $block['label'] = self::labelForKey(\is_string($key) ? $key : '');
            if ('scalar' === $kind) {
                $block['beforeDisplay'] = self::formatValueForDisplay($block['before'] ?? null);
                $block['afterDisplay'] = self::formatValueForDisplay($block['after'] ?? null);
            } elseif ('actions' === $kind && isset($block['items']) && \is_array($block['items'])) {
                $items = [];
                foreach ($block['items'] as $item) {
                    if (!\is_array($item)) {
                        continue;
                    }
                    $ct = $item['changeType'] ?? '';
                    if (\is_string($ct) && '' !== $ct) {
                        $item['changeTypeLabel'] = self::ACTION_CHANGE_TYPE_LABELS[$ct] ?? $ct;
                    }
                    if (isset($item['fieldChanges']) && \is_array($item['fieldChanges'])) {
                        $fcs = [];
                        foreach ($item['fieldChanges'] as $fc) {
                            if (!\is_array($fc)) {
                                continue;
                            }
                            $fk = $fc['key'] ?? '';
                            $fc['label'] = \is_string($fk) ? (self::ACTION_FIELD_LABELS[$fk] ?? $fk) : '';
                            $fc['beforeDisplay'] = self::formatValueForDisplay($fc['before'] ?? null);
                            $fc['afterDisplay'] = self::formatValueForDisplay($fc['after'] ?? null);
                            $fcs[] = $fc;
                        }
                        $item['fieldChanges'] = $fcs;
                    }
                    $items[] = $item;
                }
                $block['items'] = $items;
            }
            $enriched['changes'][] = $block;
        }

        return $enriched;
    }

    public static function labelForKey(string $key): string
    {
        return self::KEY_LABELS[$key] ?? $key;
    }

    private static function formatValueForDisplay(mixed $v): ?string
    {
        if ($v instanceof \JsonSerializable) {
            $v = $v->jsonSerialize();
        }
        if (\is_bool($v)) {
            return $v ? 'Oui' : 'Non';
        }
        if ($v === null) {
            return '—';
        }
        if (\is_int($v) || \is_float($v)) {
            if (\is_float($v) && \floor($v) === $v) {
                return (string) (int) $v;
            }

            return (string) $v;
        }
        if (\is_string($v)) {
            return self::truncateString($v);
        }
        if (\is_array($v)) {
            $json = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            if (false === $json) {
                return '[valeur non sérialisable]';
            }

            return self::truncateString($json);
        }

        return self::truncateString((string) $v);
    }

    private static function truncateString(string $s): string
    {
        if (mb_strlen($s) <= self::DISPLAY_MAX_LEN) {
            return $s;
        }

        return mb_substr($s, 0, self::DISPLAY_MAX_LEN - 1).'…';
    }

    /**
     * @param list<string> $keys
     *
     * @return list<string>
     */
    public static function labelsForKeys(array $keys): array
    {
        $labels = [];
        foreach ($keys as $k) {
            $labels[] = \is_string($k) ? self::labelForKey($k) : (string) $k;
        }

        return $labels;
    }
}
