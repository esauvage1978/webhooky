<?php

declare(strict_types=1);

namespace App\Service\Audit;

use App\Entity\FormWebhook;
use App\Entity\FormWebhookAction;

/**
 * Représentation compacte et stable (hors identifiants d’actions DB) pour comparer deux états de workflow.
 */
final class FormWebhookAuditSnapshot
{
    /**
     * @return array<string, mixed>
     */
    public static function fromWebhook(FormWebhook $webhook): array
    {
        $actions = $webhook->getActions()->toArray();
        usort(
            $actions,
            static fn (FormWebhookAction $a, FormWebhookAction $b) => $a->getSortOrder() <=> $b->getSortOrder(),
        );

        return [
            'name' => $webhook->getName(),
            'description' => $webhook->getDescription(),
            'active' => $webhook->isActive(),
            'lifecycle' => $webhook->getLifecycle(),
            'organizationId' => $webhook->getOrganization()?->getId(),
            'projectId' => $webhook->getProject()?->getId(),
            'notificationEmailSource' => $webhook->getNotificationEmailSource(),
            'notificationCustomEmail' => $webhook->getNotificationCustomEmail(),
            'notifyOnError' => $webhook->isNotifyOnError(),
            'metadata' => $webhook->getMetadata(),
            'actions' => array_map(
                static fn (FormWebhookAction $a) => [
                    'sortOrder' => $a->getSortOrder(),
                    'active' => $a->isActive(),
                    'actionType' => $a->getActionType(),
                    'serviceConnectionId' => $a->getServiceConnection()?->getId(),
                    'mailjetId' => $a->getMailjet()?->getId(),
                    'mailjetTemplateId' => $a->getMailjetTemplateId(),
                    'templateLanguage' => $a->isTemplateLanguage(),
                    'variableMapping' => $a->getVariableMapping(),
                    'payloadTemplate' => $a->getPayloadTemplate(),
                    'smsToPostKey' => $a->getSmsToPostKey(),
                    'smsToDefault' => $a->getSmsToDefault(),
                    'recipientEmailPostKey' => $a->getRecipientEmailPostKey(),
                    'recipientNamePostKey' => $a->getRecipientNamePostKey(),
                    'defaultRecipientEmail' => $a->getDefaultRecipientEmail(),
                    'comment' => $a->getComment(),
                ],
                $actions,
            ),
        ];
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     *
     * @return list<string>
     */
    public static function changedTopLevelKeys(array $a, array $b): array
    {
        $keys = array_unique([...array_keys($a), ...array_keys($b)]);
        $out = [];
        foreach ($keys as $k) {
            if ($k === 'actions') {
                continue;
            }
            $va = $a[$k] ?? null;
            $vb = $b[$k] ?? null;
            if (json_encode($va) !== json_encode($vb)) {
                $out[] = (string) $k;
            }
        }
        if (json_encode($a['actions'] ?? null) !== json_encode($b['actions'] ?? null)) {
            $out[] = 'actions';
        }

        return $out;
    }
}
