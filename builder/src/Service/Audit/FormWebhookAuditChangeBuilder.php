<?php

declare(strict_types=1);

namespace App\Service\Audit;

/**
 * Construit un diff lisible entre deux snapshots de workflow (persisté en JSON dans resource_audit_log).
 */
final class FormWebhookAuditChangeBuilder
{
    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     *
     * @return array{changes: list<array<string, mixed>>}
     */
    public static function build(array $before, array $after): array
    {
        $changedKeys = FormWebhookAuditSnapshot::changedTopLevelKeys($before, $after);
        $changes = [];
        foreach ($changedKeys as $k) {
            if ('actions' === $k) {
                $items = self::diffActionLists(
                    self::normalizeActionList($before['actions'] ?? null),
                    self::normalizeActionList($after['actions'] ?? null),
                );
                if ($items !== []) {
                    $changes[] = [
                        'key' => 'actions',
                        'kind' => 'actions',
                        'items' => $items,
                    ];
                }
            } else {
                $changes[] = [
                    'key' => $k,
                    'kind' => 'scalar',
                    'before' => $before[$k] ?? null,
                    'after' => $after[$k] ?? null,
                ];
            }
        }

        return ['changes' => $changes];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function normalizeActionList(mixed $actions): array
    {
        if (!\is_array($actions)) {
            return [];
        }
        $out = [];
        foreach ($actions as $row) {
            if (\is_array($row)) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $before
     * @param list<array<string, mixed>> $after
     *
     * @return list<array<string, mixed>>
     */
    private static function diffActionLists(array $before, array $after): array
    {
        $max = max(\count($before), \count($after));
        $out = [];
        for ($i = 0; $i < $max; ++$i) {
            $b = $before[$i] ?? null;
            $a = $after[$i] ?? null;
            if ($b === null && \is_array($a)) {
                $out[] = [
                    'slot' => $i + 1,
                    'changeType' => 'added',
                    'fieldChanges' => self::fieldsNewAction($a),
                ];
                continue;
            }
            if (\is_array($b) && $a === null) {
                $out[] = [
                    'slot' => $i + 1,
                    'changeType' => 'removed',
                    'fieldChanges' => self::fieldsRemovedAction($b),
                ];
                continue;
            }
            if (\is_array($b) && \is_array($a)) {
                $fc = self::diffActionRow($b, $a);
                if ($fc !== []) {
                    $out[] = [
                        'slot' => $i + 1,
                        'changeType' => 'modified',
                        'fieldChanges' => $fc,
                    ];
                }
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return list<array{key: string, before: null, after: mixed}>
     */
    private static function fieldsNewAction(array $row): array
    {
        $keys = array_keys($row);
        sort($keys);
        $fc = [];
        foreach ($keys as $k) {
            $fc[] = ['key' => (string) $k, 'before' => null, 'after' => $row[$k]];
        }

        return $fc;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return list<array{key: string, before: mixed, after: null}>
     */
    private static function fieldsRemovedAction(array $row): array
    {
        $keys = array_keys($row);
        sort($keys);
        $fc = [];
        foreach ($keys as $k) {
            $fc[] = ['key' => (string) $k, 'before' => $row[$k], 'after' => null];
        }

        return $fc;
    }

    /**
     * @param array<string, mixed> $b
     * @param array<string, mixed> $a
     *
     * @return list<array{key: string, before: mixed, after: mixed}>
     */
    private static function diffActionRow(array $b, array $a): array
    {
        $keys = array_values(array_unique([...array_keys($b), ...array_keys($a)]));
        sort($keys);
        $fc = [];
        foreach ($keys as $k) {
            $vb = $b[$k] ?? null;
            $va = $a[$k] ?? null;
            if (json_encode($vb) === json_encode($va)) {
                continue;
            }
            $fc[] = ['key' => $k, 'before' => $vb, 'after' => $va];
        }

        return $fc;
    }
}
