<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\MonitoringAlert;
use App\Entity\MonitoringIncident;
use App\Entity\PricingRule;
use App\FormWebhook\FormWebhookLogStatus;
use App\Monitoring\FormWebhookRetryService;
use App\Monitoring\MonitoringOverviewBuilder;
use App\Monitoring\MonitoringPayloadMasker;
use App\Repository\FormWebhookLogRepository;
use App\Repository\MonitoringAlertRepository;
use App\Repository\MonitoringCostEntryRepository;
use App\Repository\MonitoringIncidentRepository;
use App\Repository\MonitoringSettingRepository;
use App\Repository\OrganizationRepository;
use App\Repository\PricingRuleRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin/monitoring')]
#[IsGranted('ROLE_ADMIN')]
final class ApiAdminMonitoringController extends AbstractController
{
    public function __construct(
        private readonly MonitoringOverviewBuilder $overviewBuilder,
        private readonly FormWebhookLogRepository $logRepository,
        private readonly MonitoringAlertRepository $alertRepository,
        private readonly MonitoringIncidentRepository $incidentRepository,
        private readonly MonitoringSettingRepository $settingRepository,
        private readonly PricingRuleRepository $pricingRuleRepository,
        private readonly MonitoringCostEntryRepository $costEntryRepository,
        private readonly OrganizationRepository $organizationRepository,
        private readonly MonitoringPayloadMasker $payloadMasker,
        private readonly FormWebhookRetryService $retryService,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/overview', name: 'api_admin_monitoring_overview', methods: ['GET'])]
    public function overview(Request $request): JsonResponse
    {
        $period = (string) $request->query->get('period', '24h');

        return new JsonResponse($this->overviewBuilder->buildAdmin($period));
    }

    #[Route('/events', name: 'api_admin_monitoring_events', methods: ['GET'])]
    public function events(Request $request): JsonResponse
    {
        $limit = max(1, min(100, (int) $request->query->get('limit', '50')));
        $page = max(1, (int) $request->query->get('page', '1'));
        $orgId = $request->query->get('organizationId');
        $data = $this->logRepository->findPaginatedForMonitoring(
            ($page - 1) * $limit,
            $limit,
            $orgId !== null && $orgId !== '' ? (int) $orgId : null,
            $request->query->get('status'),
            $request->query->get('correlationId'),
            $this->parseDate($request->query->get('dateFrom')),
            $this->parseDateTo($request->query->get('dateTo')),
        );

        return new JsonResponse([
            'items' => array_map(fn ($l) => $this->serializeLog($l, false), $data['items']),
            'total' => $data['total'],
            'page' => $page,
            'perPage' => $limit,
        ]);
    }

    #[Route('/events/{id}', name: 'api_admin_monitoring_event', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function event(int $id): JsonResponse
    {
        $log = $this->logRepository->findOneWithActionLogs($id);
        if ($log === null) {
            return new JsonResponse(['error' => 'Événement introuvable'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse($this->serializeLog($log, true));
    }

    #[Route('/events/{id}/retry', name: 'api_admin_monitoring_event_retry', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function retryEvent(int $id): JsonResponse
    {
        $log = $this->logRepository->findOneWithActionLogs($id);
        if ($log === null) {
            return new JsonResponse(['error' => 'Événement introuvable'], Response::HTTP_NOT_FOUND);
        }
        $scheduled = 0;
        foreach ($log->getActionLogs() as $al) {
            if ($this->retryService->isRetryable($al)) {
                if ($this->retryService->scheduleRetry($al, $al->getAttempt() + 1)) {
                    ++$scheduled;
                }
            }
        }

        return new JsonResponse(['ok' => true, 'scheduled' => $scheduled]);
    }

    #[Route('/alerts', name: 'api_admin_monitoring_alerts', methods: ['GET'])]
    public function alerts(Request $request): JsonResponse
    {
        $limit = max(1, min(100, (int) $request->query->get('limit', '50')));
        $page = max(1, (int) $request->query->get('page', '1'));
        $data = $this->alertRepository->findPaginated(
            ($page - 1) * $limit,
            $limit,
            $request->query->get('status'),
            null,
        );

        return new JsonResponse([
            'items' => array_map([$this, 'serializeAlert'], $data['items']),
            'total' => $data['total'],
            'page' => $page,
            'perPage' => $limit,
        ]);
    }

    #[Route('/alerts/{id}/acknowledge', name: 'api_admin_monitoring_alert_ack', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function acknowledgeAlert(int $id): JsonResponse
    {
        $alert = $this->alertRepository->find($id);
        if (!$alert instanceof MonitoringAlert) {
            return new JsonResponse(['error' => 'Alerte introuvable'], Response::HTTP_NOT_FOUND);
        }
        $alert->setStatus(MonitoringAlert::STATUS_ACKNOWLEDGED);
        $alert->setAcknowledgedAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        return new JsonResponse($this->serializeAlert($alert));
    }

    #[Route('/incidents', name: 'api_admin_monitoring_incidents', methods: ['GET'])]
    public function incidents(Request $request): JsonResponse
    {
        $limit = max(1, min(100, (int) $request->query->get('limit', '50')));
        $page = max(1, (int) $request->query->get('page', '1'));
        $data = $this->incidentRepository->findPaginated(($page - 1) * $limit, $limit, $request->query->get('status'));

        return new JsonResponse([
            'items' => array_map([$this, 'serializeIncident'], $data['items']),
            'total' => $data['total'],
            'page' => $page,
            'perPage' => $limit,
        ]);
    }

    #[Route('/incidents/{id}/resolve', name: 'api_admin_monitoring_incident_resolve', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function resolveIncident(int $id): JsonResponse
    {
        $inc = $this->incidentRepository->find($id);
        if (!$inc instanceof MonitoringIncident) {
            return new JsonResponse(['error' => 'Incident introuvable'], Response::HTTP_NOT_FOUND);
        }
        $inc->setStatus(MonitoringIncident::STATUS_RESOLVED);
        $inc->setResolvedAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        return new JsonResponse($this->serializeIncident($inc));
    }

    #[Route('/costs', name: 'api_admin_monitoring_costs', methods: ['GET'])]
    public function costs(Request $request): JsonResponse
    {
        $from = $this->parseDate($request->query->get('dateFrom')) ?? new \DateTimeImmutable('-30 days');
        $to = $this->parseDate($request->query->get('dateTo')) ?? new \DateTimeImmutable('today');
        $entries = $this->costEntryRepository->findForPeriod($from, $to, null);
        $rules = $this->pricingRuleRepository->findAll();

        return new JsonResponse([
            'configured' => $this->pricingRuleRepository->findActive() !== [],
            'totalCents' => $this->costEntryRepository->sumCostCents($from, $to, null),
            'entries' => array_map(static fn ($e) => [
                'id' => $e->getId(),
                'periodDay' => $e->getPeriodDay()->format('Y-m-d'),
                'organizationId' => $e->getOrganizationId(),
                'channel' => $e->getChannel(),
                'provider' => $e->getProvider(),
                'units' => $e->getUnits(),
                'costCents' => $e->getCostCents(),
                'currency' => $e->getCurrency(),
            ], $entries),
            'pricingRules' => array_map([$this, 'serializePricingRule'], $rules),
        ]);
    }

    #[Route('/pricing-rules', name: 'api_admin_monitoring_pricing_create', methods: ['POST'])]
    public function createPricingRule(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            return new JsonResponse(['error' => 'JSON invalide'], Response::HTTP_BAD_REQUEST);
        }
        $rule = new PricingRule();
        $rule->setProvider((string) ($data['provider'] ?? '*'));
        $rule->setChannel((string) ($data['channel'] ?? ''));
        $rule->setUnit((string) ($data['unit'] ?? 'message'));
        $rule->setUnitCostCents((int) ($data['unitCostCents'] ?? 0));
        $rule->setCurrency((string) ($data['currency'] ?? 'EUR'));
        $rule->setLabel((string) ($data['label'] ?? ''));
        $rule->setActive(($data['active'] ?? true) !== false);
        if ($rule->getChannel() === '' || $rule->getLabel() === '') {
            return new JsonResponse(['error' => 'channel et label requis'], Response::HTTP_BAD_REQUEST);
        }
        $this->entityManager->persist($rule);
        $this->entityManager->flush();

        return new JsonResponse($this->serializePricingRule($rule), Response::HTTP_CREATED);
    }

    #[Route('/accounts', name: 'api_admin_monitoring_accounts', methods: ['GET'])]
    public function accounts(): JsonResponse
    {
        $orgs = $this->organizationRepository->findBy([], ['name' => 'ASC'], 200);
        $items = [];
        foreach ($orgs as $org) {
            $from = new \DateTimeImmutable('-24 hours');
            $counts = $this->logRepository->countByStatusBetween($from, new \DateTimeImmutable('now'), $org->getId());
            $items[] = [
                'id' => $org->getId(),
                'name' => $org->getName(),
                'plan' => $org->getSubscriptionPlan()->value,
                'received24h' => array_sum($counts),
                'errors24h' => (int) ($counts[FormWebhookLogStatus::ERROR] ?? 0) + (int) ($counts[FormWebhookLogStatus::DEAD_LETTER] ?? 0),
            ];
        }

        return new JsonResponse(['items' => $items]);
    }

    #[Route('/settings', name: 'api_admin_monitoring_settings_get', methods: ['GET'])]
    public function getSettings(): JsonResponse
    {
        return new JsonResponse(['settings' => $this->settingRepository->allAsMap()]);
    }

    #[Route('/settings', name: 'api_admin_monitoring_settings_put', methods: ['PUT'])]
    public function putSettings(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (!\is_array($data) || !isset($data['settings']) || !\is_array($data['settings'])) {
            return new JsonResponse(['error' => 'Attendu { settings: { key: object } }'], Response::HTTP_BAD_REQUEST);
        }
        foreach ($data['settings'] as $key => $value) {
            if (!\is_string($key) || !\is_array($value)) {
                continue;
            }
            $this->settingRepository->upsert($key, $value);
        }

        return new JsonResponse(['settings' => $this->settingRepository->allAsMap()]);
    }

    #[Route('/flows', name: 'api_admin_monitoring_flows', methods: ['GET'])]
    public function flows(): JsonResponse
    {
        $overview = $this->overviewBuilder->buildAdmin('24h');

        return new JsonResponse([
            'pipeline' => $overview['pipeline'],
            'queue' => $overview['queue'],
            'domains' => $overview['domains'],
        ]);
    }

    #[Route('/timeline', name: 'api_admin_monitoring_timeline', methods: ['GET'])]
    public function timeline(Request $request): JsonResponse
    {
        $overview = $this->overviewBuilder->buildAdmin((string) $request->query->get('period', '24h'));

        return new JsonResponse([
            'series' => $overview['series'],
            'recentAlerts' => $overview['recentAlerts'],
            'recentIncidents' => $overview['recentIncidents'],
        ]);
    }

    private function parseDate(mixed $v): ?\DateTimeImmutable
    {
        if (!\is_string($v) || $v === '') {
            return null;
        }
        try {
            return new \DateTimeImmutable($v);
        } catch (\Exception) {
            return null;
        }
    }

    private function parseDateTo(mixed $v): ?\DateTimeImmutable
    {
        $d = $this->parseDate($v);
        if ($d === null) {
            return null;
        }
        if (1 === preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v)) {
            return $d->setTime(23, 59, 59);
        }

        return $d;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeLog(\App\Entity\FormWebhookLog $log, bool $detail): array
    {
        $w = $log->getFormWebhook();
        $row = [
            'id' => $log->getId(),
            'correlationId' => $log->getCorrelationId(),
            'status' => $log->getStatus(),
            'receivedAt' => $log->getReceivedAt()?->format(\DateTimeInterface::ATOM),
            'durationMs' => $log->getDurationMs(),
            'attemptCount' => $log->getAttemptCount(),
            'quotaUnitsConsumed' => $log->getQuotaUnitsConsumed(),
            'errorDetail' => $log->getErrorDetail(),
            'clientIp' => $this->payloadMasker->maskIp($log->getClientIp()),
            'webhook' => $w ? [
                'id' => $w->getId(),
                'name' => $w->getName(),
                'organizationId' => $w->getOrganization()?->getId(),
                'organizationName' => $w->getOrganization()?->getName(),
            ] : null,
            'actionsCount' => $log->getActionLogs()->count(),
        ];
        if ($detail) {
            $row['rawBody'] = $this->payloadMasker->maskSecrets($log->getRawBody());
            $row['parsedInput'] = $this->payloadMasker->maskSecrets($log->getParsedInput());
            $row['actions'] = [];
            foreach ($log->getActionLogs() as $al) {
                $row['actions'][] = [
                    'id' => $al->getId(),
                    'status' => $al->getStatus(),
                    'attempt' => $al->getAttempt(),
                    'sortOrder' => $al->getSortOrder(),
                    'actionType' => $al->getFormWebhookAction()?->getActionType(),
                    'recipient' => $this->payloadMasker->maskRecipient($al->getRecipient()),
                    'httpStatus' => $al->getHttpStatus(),
                    'errorDetail' => $al->getErrorDetail(),
                    'durationMs' => $al->getDurationMs(),
                    'variablesSent' => $this->payloadMasker->maskSecrets($al->getVariablesSent()),
                    'providerResponseBody' => $this->payloadMasker->maskSecrets($al->getProviderResponseBody()),
                ];
            }
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeAlert(MonitoringAlert $a): array
    {
        return [
            'id' => $a->getId(),
            'code' => $a->getCode(),
            'domain' => $a->getDomain(),
            'severity' => $a->getSeverity(),
            'title' => $a->getTitle(),
            'message' => $a->getMessage(),
            'status' => $a->getStatus(),
            'organizationId' => $a->getOrganizationId(),
            'occurrenceCount' => $a->getOccurrenceCount(),
            'firstSeenAt' => $a->getFirstSeenAt()->format(\DateTimeInterface::ATOM),
            'lastSeenAt' => $a->getLastSeenAt()->format(\DateTimeInterface::ATOM),
            'context' => $a->getContext(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeIncident(MonitoringIncident $i): array
    {
        return [
            'id' => $i->getId(),
            'title' => $i->getTitle(),
            'status' => $i->getStatus(),
            'severity' => $i->getSeverity(),
            'organizationId' => $i->getOrganizationId(),
            'openedAt' => $i->getOpenedAt()->format(\DateTimeInterface::ATOM),
            'resolvedAt' => $i->getResolvedAt()?->format(\DateTimeInterface::ATOM),
            'summary' => $i->getSummary(),
            'alertIds' => $i->getAlertIds(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializePricingRule(PricingRule $r): array
    {
        return [
            'id' => $r->getId(),
            'provider' => $r->getProvider(),
            'channel' => $r->getChannel(),
            'unit' => $r->getUnit(),
            'unitCostCents' => $r->getUnitCostCents(),
            'currency' => $r->getCurrency(),
            'label' => $r->getLabel(),
            'active' => $r->isActive(),
        ];
    }
}
