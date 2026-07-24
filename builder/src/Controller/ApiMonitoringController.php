<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\FormWebhookLog;
use App\Entity\User;
use App\Monitoring\FormWebhookRetryService;
use App\Monitoring\MonitoringOverviewBuilder;
use App\Monitoring\MonitoringPayloadMasker;
use App\Repository\FormWebhookLogRepository;
use App\Repository\MonitoringAlertRepository;
use App\Subscription\SubscriptionEntitlementService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/monitoring')]
#[IsGranted('ROLE_USER')]
final class ApiMonitoringController extends AbstractController
{
    public function __construct(
        private readonly MonitoringOverviewBuilder $overviewBuilder,
        private readonly FormWebhookLogRepository $logRepository,
        private readonly MonitoringAlertRepository $alertRepository,
        private readonly MonitoringPayloadMasker $payloadMasker,
        private readonly FormWebhookRetryService $retryService,
        private readonly SubscriptionEntitlementService $entitlementService,
    ) {
    }

    #[Route('/overview', name: 'api_monitoring_overview', methods: ['GET'])]
    public function overview(Request $request): JsonResponse
    {
        $orgId = $this->requireOrgId();
        if ($orgId instanceof JsonResponse) {
            return $orgId;
        }
        $period = (string) $request->query->get('period', '24h');

        return new JsonResponse($this->overviewBuilder->buildClient($orgId, $period));
    }

    #[Route('/events', name: 'api_monitoring_events', methods: ['GET'])]
    public function events(Request $request): JsonResponse
    {
        $orgId = $this->requireOrgId();
        if ($orgId instanceof JsonResponse) {
            return $orgId;
        }
        $limit = max(1, min(100, (int) $request->query->get('limit', '50')));
        $page = max(1, (int) $request->query->get('page', '1'));
        $data = $this->logRepository->findPaginatedForMonitoring(
            ($page - 1) * $limit,
            $limit,
            $orgId,
            $request->query->get('status'),
            $request->query->get('correlationId'),
        );

        return new JsonResponse([
            'items' => array_map(fn (FormWebhookLog $l) => $this->serializeLog($l, false), $data['items']),
            'total' => $data['total'],
            'page' => $page,
            'perPage' => $limit,
        ]);
    }

    #[Route('/events/{id}', name: 'api_monitoring_event', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function event(int $id): JsonResponse
    {
        $orgId = $this->requireOrgId();
        if ($orgId instanceof JsonResponse) {
            return $orgId;
        }
        $log = $this->logRepository->findOneWithActionLogs($id);
        if ($log === null || $log->getFormWebhook()?->getOrganization()?->getId() !== $orgId) {
            return new JsonResponse(['error' => 'Événement introuvable'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse($this->serializeLog($log, true));
    }

    #[Route('/events/{id}/retry', name: 'api_monitoring_event_retry', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function retry(int $id): JsonResponse
    {
        $orgId = $this->requireOrgId();
        if ($orgId instanceof JsonResponse) {
            return $orgId;
        }
        $log = $this->logRepository->findOneWithActionLogs($id);
        if ($log === null || $log->getFormWebhook()?->getOrganization()?->getId() !== $orgId) {
            return new JsonResponse(['error' => 'Événement introuvable'], Response::HTTP_NOT_FOUND);
        }
        $scheduled = 0;
        foreach ($log->getActionLogs() as $al) {
            if ($this->retryService->isRetryable($al) && $this->retryService->scheduleRetry($al, $al->getAttempt() + 1)) {
                ++$scheduled;
            }
        }

        return new JsonResponse(['ok' => true, 'scheduled' => $scheduled]);
    }

    #[Route('/consumption', name: 'api_monitoring_consumption', methods: ['GET'])]
    public function consumption(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User || $user->getOrganization() === null) {
            return new JsonResponse(['error' => 'Organisation requise'], Response::HTTP_BAD_REQUEST);
        }
        $snap = $this->entitlementService->buildSnapshot($user->getOrganization());

        return new JsonResponse(['subscription' => $snap]);
    }

    #[Route('/alerts', name: 'api_monitoring_alerts', methods: ['GET'])]
    public function alerts(Request $request): JsonResponse
    {
        $orgId = $this->requireOrgId();
        if ($orgId instanceof JsonResponse) {
            return $orgId;
        }
        $limit = max(1, min(100, (int) $request->query->get('limit', '50')));
        $page = max(1, (int) $request->query->get('page', '1'));
        $data = $this->alertRepository->findPaginated(($page - 1) * $limit, $limit, $request->query->get('status'), $orgId);

        return new JsonResponse([
            'items' => array_map(static fn ($a) => [
                'id' => $a->getId(),
                'code' => $a->getCode(),
                'severity' => $a->getSeverity(),
                'title' => $a->getTitle(),
                'message' => $a->getMessage(),
                'status' => $a->getStatus(),
                'lastSeenAt' => $a->getLastSeenAt()->format(\DateTimeInterface::ATOM),
                'occurrenceCount' => $a->getOccurrenceCount(),
            ], $data['items']),
            'total' => $data['total'],
            'page' => $page,
            'perPage' => $limit,
        ]);
    }

    private function requireOrgId(): int|JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'Non authentifié'], Response::HTTP_UNAUTHORIZED);
        }
        $org = $user->getOrganization();
        if ($org === null || $org->getId() === null) {
            return new JsonResponse(['error' => 'Organisation requise'], Response::HTTP_BAD_REQUEST);
        }

        return (int) $org->getId();
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeLog(FormWebhookLog $log, bool $detail): array
    {
        $w = $log->getFormWebhook();
        $row = [
            'id' => $log->getId(),
            'correlationId' => $log->getCorrelationId(),
            'status' => $log->getStatus(),
            'receivedAt' => $log->getReceivedAt()?->format(\DateTimeInterface::ATOM),
            'durationMs' => $log->getDurationMs(),
            'attemptCount' => $log->getAttemptCount(),
            'errorDetail' => $log->getErrorDetail(),
            'webhook' => $w ? ['id' => $w->getId(), 'name' => $w->getName()] : null,
            'actionsCount' => $log->getActionLogs()->count(),
        ];
        if ($detail) {
            $row['parsedInput'] = $this->payloadMasker->maskSecrets($log->getParsedInput());
            $row['rawBody'] = $this->payloadMasker->maskSecrets($log->getRawBody());
            $row['actions'] = [];
            foreach ($log->getActionLogs() as $al) {
                $row['actions'][] = [
                    'id' => $al->getId(),
                    'status' => $al->getStatus(),
                    'attempt' => $al->getAttempt(),
                    'actionType' => $al->getFormWebhookAction()?->getActionType(),
                    'recipient' => $this->payloadMasker->maskRecipient($al->getRecipient()),
                    'httpStatus' => $al->getHttpStatus(),
                    'errorDetail' => $al->getErrorDetail(),
                    'durationMs' => $al->getDurationMs(),
                ];
            }
        }

        return $row;
    }
}
