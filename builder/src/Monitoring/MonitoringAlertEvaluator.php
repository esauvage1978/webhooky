<?php

declare(strict_types=1);

namespace App\Monitoring;

use App\Entity\MonitoringAlert;
use App\Entity\MonitoringIncident;
use App\FormWebhook\FormWebhookLogStatus;
use App\Repository\FormWebhookLogRepository;
use App\Repository\MonitoringAlertRepository;
use App\Repository\MonitoringIncidentRepository;
use App\Repository\MonitoringMetricAggRepository;
use Doctrine\ORM\EntityManagerInterface;

final class MonitoringAlertEvaluator
{
    public function __construct(
        private readonly FormWebhookLogRepository $logRepository,
        private readonly MonitoringAlertRepository $alertRepository,
        private readonly MonitoringIncidentRepository $incidentRepository,
        private readonly MonitoringMetricAggRepository $metricAggRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function evaluate(): int
    {
        $touched = 0;
        $now = new \DateTimeImmutable();
        $from15 = $now->modify('-15 minutes');
        $from1h = $now->modify('-1 hour');

        $counts = $this->logRepository->countByStatusBetween($from15, $now, null);
        $success = (int) ($counts[FormWebhookLogStatus::SENT] ?? 0);
        $error = (int) ($counts[FormWebhookLogStatus::ERROR] ?? 0) + (int) ($counts[FormWebhookLogStatus::DEAD_LETTER] ?? 0);
        $total = $success + $error;
        if ($total >= 5 && ($error / $total) > 0.15) {
            $touched += $this->upsertAlert(
                'high_error_rate',
                'reliability',
                MonitoringAlert::SEVERITY_CRITICAL,
                'Taux d’erreur élevé',
                sprintf('%.0f%% d’échecs sur %d runs (15 min).', ($error / $total) * 100, $total),
                null,
                ['errorRate' => $error / $total, 'sample' => $total],
            );
        }

        $dead = (int) ($this->logRepository->countByStatusBetween($from1h, $now, null)[FormWebhookLogStatus::DEAD_LETTER] ?? 0);
        if ($dead > 0) {
            $touched += $this->upsertAlert(
                'dead_letter',
                'retry',
                MonitoringAlert::SEVERITY_CRITICAL,
                'Messages en dead letter',
                sprintf('%d run(s) en dead letter sur la dernière heure.', $dead),
                null,
                ['count' => $dead],
            );
        }

        $rateLimited = 0;
        foreach ($this->metricAggRepository->findSeries(
            'hour',
            MonitoringMetricKeys::WEBHOOK_RATE_LIMITED,
            $from1h->setTime((int) $from1h->format('H'), 0),
            $now->modify('+1 hour'),
            null,
        ) as $agg) {
            $rateLimited += (int) $agg->getValueSum();
        }
        if ($rateLimited > 50) {
            $touched += $this->upsertAlert(
                'rate_limited_burst',
                'reception',
                MonitoringAlert::SEVERITY_WARNING,
                'Pic de rate limiting',
                sprintf('%d requêtes rate-limitées sur la dernière heure.', $rateLimited),
                null,
                ['count' => $rateLimited],
            );
        }

        $this->maybeOpenIncident();
        $this->entityManager->flush();

        return $touched;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function upsertAlert(
        string $code,
        string $domain,
        string $severity,
        string $title,
        string $message,
        ?int $organizationId,
        array $context,
    ): int {
        $fp = sha1($code.'|'.$domain.'|'.($organizationId ?? 'g').'|'.(new \DateTimeImmutable())->format('Y-m-d-H'));
        $alert = $this->alertRepository->findByFingerprint($fp);
        if ($alert === null) {
            $alert = new MonitoringAlert();
            $alert->setCode($code);
            $alert->setDomain($domain);
            $alert->setSeverity($severity);
            $alert->setTitle($title);
            $alert->setMessage($message);
            $alert->setOrganizationId($organizationId);
            $alert->setFingerprint($fp);
            $alert->setContext($context);
            $this->entityManager->persist($alert);

            return 1;
        }
        $alert->bumpOccurrence();
        $alert->setMessage($message);
        $alert->setContext($context);
        $alert->setSeverity($severity);

        return 1;
    }

    private function maybeOpenIncident(): void
    {
        $openCritical = $this->alertRepository->findRecentOpen(20, null);
        $critical = array_values(array_filter(
            $openCritical,
            static fn (MonitoringAlert $a) => $a->getSeverity() === MonitoringAlert::SEVERITY_CRITICAL,
        ));
        if (\count($critical) < 2) {
            return;
        }
        $ids = array_map(static fn (MonitoringAlert $a) => (int) $a->getId(), $critical);
        $existing = $this->incidentRepository->findOneBy(['status' => MonitoringIncident::STATUS_OPEN]);
        if ($existing !== null) {
            $existing->setAlertIds($ids);
            $existing->setSummary(sprintf('%d alertes critiques ouvertes.', \count($ids)));

            return;
        }
        $inc = new MonitoringIncident();
        $inc->setTitle('Incident plateforme — alertes critiques');
        $inc->setSeverity(MonitoringAlert::SEVERITY_CRITICAL);
        $inc->setAlertIds($ids);
        $inc->setSummary(sprintf('%d alertes critiques ouvertes.', \count($ids)));
        $this->entityManager->persist($inc);
    }
}
