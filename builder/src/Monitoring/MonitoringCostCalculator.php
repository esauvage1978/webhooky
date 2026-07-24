<?php

declare(strict_types=1);

namespace App\Monitoring;

use App\FormWebhook\FormWebhookLogStatus;
use App\Repository\MonitoringCostEntryRepository;
use App\Repository\PricingRuleRepository;
use App\ServiceIntegration\ServiceIntegrationType;
use Doctrine\ORM\EntityManagerInterface;

final class MonitoringCostCalculator
{
    public function __construct(
        private readonly PricingRuleRepository $pricingRuleRepository,
        private readonly MonitoringCostEntryRepository $costEntryRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function calculateDay(\DateTimeImmutable $day): int
    {
        if ($this->pricingRuleRepository->findActive() === []) {
            return 0;
        }

        $from = $day->setTime(0, 0);
        $to = $from->modify('+1 day');
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT w.organization_id AS org_id, fa.action_type, COUNT(*) AS cnt
             FROM form_webhook_action_log al
             INNER JOIN form_webhook_log l ON l.id = al.form_webhook_log_id
             INNER JOIN form_webhook w ON w.id = l.form_webhook_id
             LEFT JOIN form_webhook_action fa ON fa.id = al.form_webhook_action_id
             WHERE l.received_at >= :from AND l.received_at < :to
               AND al.status = :sent
             GROUP BY w.organization_id, fa.action_type',
            [
                'from' => $from->format('Y-m-d H:i:s'),
                'to' => $to->format('Y-m-d H:i:s'),
                'sent' => FormWebhookLogStatus::SENT,
            ],
        );

        $written = 0;
        foreach ($rows as $row) {
            $actionType = (string) ($row['action_type'] ?? '');
            [$channel, $provider] = $this->mapChannel($actionType);
            if ($channel === null) {
                continue;
            }
            $rule = $this->pricingRuleRepository->findActiveFor($channel, $provider, $from);
            if ($rule === null) {
                continue;
            }
            $units = (float) $row['cnt'];
            $cost = (int) round($units * $rule->getUnitCostCents());
            $this->costEntryRepository->upsertDay(
                $from,
                $row['org_id'] !== null ? (int) $row['org_id'] : null,
                $channel,
                $provider,
                $units,
                $cost,
                $rule->getCurrency(),
                $rule->getId(),
            );
            ++$written;
        }
        $this->entityManager->flush();

        return $written;
    }

    /**
     * @return array{0: ?string, 1: string}
     */
    private function mapChannel(string $actionType): array
    {
        if ($actionType === ServiceIntegrationType::MAILJET || $actionType === 'mailjet') {
            return ['email', 'mailjet'];
        }
        if ($actionType === 'smsfactor_sms' || str_contains($actionType, 'sms')) {
            return ['sms', $actionType !== '' ? $actionType : 'sms'];
        }
        if ($actionType === 'http' || str_starts_with($actionType, 'http') || str_contains($actionType, 'webhook')) {
            return ['http', $actionType !== '' ? $actionType : 'http'];
        }
        if ($actionType === '') {
            return [null, ''];
        }

        return ['other', $actionType];
    }
}
