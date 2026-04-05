<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\FormWebhook;
use App\Entity\FormWebhookActionLog;
use App\Entity\FormWebhookLog;
use App\Entity\User;
use App\FormWebhook\FormWebhookLogStatus;
use App\Repository\FormWebhookLogRepository;
use App\Repository\FormWebhookRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/form-webhooks')]
final class ApiFormWebhookLogController extends AbstractController
{
    public function __construct(
        private readonly FormWebhookRepository $formWebhookRepository,
        private readonly FormWebhookLogRepository $formWebhookLogRepository,
    ) {
    }

    #[Route('/{webhookId}/logs', name: 'api_form_webhook_logs_list', methods: ['GET'], requirements: ['webhookId' => '\d+'], priority: 10)]
    #[IsGranted('ROLE_USER')]
    public function list(int $webhookId, Request $request): JsonResponse
    {
        $webhook = $this->formWebhookRepository->find($webhookId);
        if (!$webhook instanceof FormWebhook) {
            return new JsonResponse(['error' => 'Webhook introuvable'], Response::HTTP_NOT_FOUND);
        }

        $user = $this->currentUser();
        if (!$this->canAccessWebhook($user, $webhook)) {
            return new JsonResponse(['error' => 'Accès refusé'], Response::HTTP_FORBIDDEN);
        }

        $page = max(1, (int) $request->query->get('page', 1));
        $limit = min(100, max(1, (int) $request->query->get('limit', 25)));

        $items = $this->formWebhookLogRepository->findByWebhookPaginated($webhook, $page, $limit);
        $total = $this->formWebhookLogRepository->countByWebhook($webhook);

        return new JsonResponse([
            'items' => array_map(fn (FormWebhookLog $l) => $this->serializeSummary($l), $items),
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
        ]);
    }

    #[Route('/logs/{logId}', name: 'api_form_webhook_logs_show', methods: ['GET'], requirements: ['logId' => '\d+'], priority: 10)]
    #[IsGranted('ROLE_USER')]
    public function show(int $logId): JsonResponse
    {
        $log = $this->formWebhookLogRepository->findOneWithActionLogs($logId);
        if (!$log instanceof FormWebhookLog) {
            return new JsonResponse(['error' => 'Journal introuvable'], Response::HTTP_NOT_FOUND);
        }

        $user = $this->currentUser();
        $webhook = $log->getFormWebhook();
        if ($webhook === null || !$this->canAccessWebhook($user, $webhook)) {
            return new JsonResponse(['error' => 'Accès refusé'], Response::HTTP_FORBIDDEN);
        }

        return new JsonResponse($this->serializeFull($log));
    }

    private function currentUser(): User
    {
        $u = $this->getUser();
        if (!$u instanceof User) {
            throw new \LogicException();
        }

        return $u;
    }

    private function isAdmin(User $user): bool
    {
        return \in_array('ROLE_ADMIN', $user->getRoles(), true);
    }

    private function canAccessWebhook(User $user, FormWebhook $webhook): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }

        $org = $user->getOrganization();

        return $org !== null && $org->getId() === $webhook->getOrganization()?->getId();
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeSummary(FormWebhookLog $l): array
    {
        $total = 0;
        $succeeded = 0;
        $toEmails = [];
        $messageIds = [];
        $firstErr = null;
        foreach ($l->getActionLogs() as $al) {
            ++$total;
            if ($al->getStatus() === FormWebhookLogStatus::SENT) {
                ++$succeeded;
            }
            if ($al->getToEmail() !== null && $al->getToEmail() !== '') {
                $toEmails[] = $al->getToEmail();
            }
            if ($al->getMailjetMessageId() !== null && $al->getMailjetMessageId() !== '') {
                $messageIds[] = $al->getMailjetMessageId();
            }
            if ($firstErr === null && $al->getErrorDetail() !== null && $al->getErrorDetail() !== '') {
                $firstErr = $al->getErrorDetail();
            }
        }

        $errOut = $l->getErrorDetail();
        if ($errOut === null && $firstErr !== null) {
            $errOut = mb_substr($firstErr, 0, 200);
        } elseif ($errOut !== null) {
            $errOut = mb_substr($errOut, 0, 200);
        }

        return [
            'id' => $l->getId(),
            'receivedAt' => $l->getReceivedAt()?->format(\DateTimeInterface::ATOM),
            'status' => $l->getStatus(),
            'actionsSummary' => ['total' => $total, 'succeeded' => $succeeded],
            'toEmail' => $toEmails[0] ?? null,
            'toEmails' => $toEmails,
            'clientIp' => $l->getClientIp(),
            'durationMs' => $l->getDurationMs(),
            'mailjetMessageId' => $messageIds[0] ?? null,
            'mailjetMessageIds' => $messageIds,
            'errorDetail' => $errOut,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeActionLog(FormWebhookActionLog $al): array
    {
        return [
            'id' => $al->getId(),
            'sortOrder' => $al->getSortOrder(),
            'formWebhookActionId' => $al->getFormWebhookAction()?->getId(),
            'variablesSent' => $al->getVariablesSent(),
            'toEmail' => $al->getToEmail(),
            'status' => $al->getStatus(),
            'mailjetHttpStatus' => $al->getMailjetHttpStatus(),
            'mailjetResponseBody' => $al->getMailjetResponseBody(),
            'mailjetMessageId' => $al->getMailjetMessageId(),
            'errorDetail' => $al->getErrorDetail(),
            'durationMs' => $al->getDurationMs(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeFull(FormWebhookLog $l): array
    {
        $w = $l->getFormWebhook();
        $actionLogs = [];
        foreach ($l->getActionLogs() as $al) {
            $actionLogs[] = $this->serializeActionLog($al);
        }

        return [
            'id' => $l->getId(),
            'formWebhookId' => $w?->getId(),
            'receivedAt' => $l->getReceivedAt()?->format(\DateTimeInterface::ATOM),
            'clientIp' => $l->getClientIp(),
            'userAgent' => $l->getUserAgent(),
            'contentType' => $l->getContentType(),
            'rawBody' => $l->getRawBody(),
            'parsedInput' => $l->getParsedInput(),
            'status' => $l->getStatus(),
            'errorDetail' => $l->getErrorDetail(),
            'durationMs' => $l->getDurationMs(),
            'actionLogs' => $actionLogs,
        ];
    }
}
