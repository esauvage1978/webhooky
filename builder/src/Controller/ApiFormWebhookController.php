<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\FormWebhook;
use App\Entity\FormWebhookAction;
use App\Entity\Mailjet;
use App\Entity\Organization;
use App\Entity\ServiceConnection;
use App\Entity\ResourceAuditLog;
use App\Entity\User;
use App\Entity\WebhookProject;
use App\FormWebhook\FormWebhookErrorNotifyPlatformInfo;
use App\FormWebhook\FormWebhookNotificationRecipientResolver;
use App\Repository\FormWebhookLogRepository;
use App\Repository\FormWebhookRepository;
use App\Repository\MailjetRepository;
use App\Repository\OrganizationRepository;
use App\Repository\ResourceAuditLogRepository;
use App\Repository\ServiceConnectionRepository;
use App\Repository\WebhookProjectRepository;
use App\Service\Audit\FormWebhookAuditChangeBuilder;
use App\Service\Audit\FormWebhookAuditDetailsPresenter;
use App\Service\Audit\FormWebhookAuditSnapshot;
use App\Service\Audit\ResourceAuditLogger;
use App\ServiceIntegration\ServiceIntegrationType;
use App\Subscription\SubscriptionEntitlementService;
use App\WebhookProject\DefaultWebhookProjectService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/form-webhooks')]
final class ApiFormWebhookController extends AbstractController
{
    public function __construct(
        private readonly FormWebhookRepository $formWebhookRepository,
        private readonly FormWebhookLogRepository $formWebhookLogRepository,
        private readonly MailjetRepository $mailjetRepository,
        private readonly ServiceConnectionRepository $serviceConnectionRepository,
        private readonly WebhookProjectRepository $webhookProjectRepository,
        private readonly OrganizationRepository $organizationRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly ValidatorInterface $validator,
        private readonly SubscriptionEntitlementService $subscriptionEntitlement,
        private readonly FormWebhookErrorNotifyPlatformInfo $errorNotifyPlatformInfo,
        private readonly DefaultWebhookProjectService $defaultWebhookProjectService,
        private readonly ResourceAuditLogger $resourceAuditLogger,
        private readonly ResourceAuditLogRepository $resourceAuditLogRepository,
        #[Autowire('%env(APP_PUBLIC_URL)%')]
        private readonly string $publicIngressBaseUrl = '',
    ) {
    }

    #[Route('', name: 'api_form_webhooks_list', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function list(Request $request): JsonResponse
    {
        $user = $this->currentUser();
        $base = $this->publicOrigin($request);

        if ($this->isAdmin($user)) {
            $all = $this->formWebhookRepository->findAllOrderedWithActions();
            $counts = $this->logCountsForWebhooks($all);
            $lastLogs = $this->lastLogSummariesForWebhooks($all);

            return new JsonResponse(array_map(
                fn (FormWebhook $w) => $this->serialize(
                    $w,
                    $base,
                    $counts[$w->getId() ?? 0] ?? 0,
                    $lastLogs[$w->getId() ?? 0] ?? null,
                ),
                $all,
            ));
        }

        $org = $user->getOrganization();
        if ($org === null) {
            return new JsonResponse([]);
        }
        if (!$user->hasMembershipInOrganization($org)) {
            return new JsonResponse(['error' => 'Contexte organisation invalide'], Response::HTTP_FORBIDDEN);
        }

        $items = $this->formWebhookRepository->findByOrganizationOrdered($org);
        $counts = $this->logCountsForWebhooks($items);
        $lastLogs = $this->lastLogSummariesForWebhooks($items);

        return new JsonResponse(array_map(
            fn (FormWebhook $w) => $this->serialize(
                $w,
                $base,
                $counts[$w->getId() ?? 0] ?? 0,
                $lastLogs[$w->getId() ?? 0] ?? null,
            ),
            $items,
        ));
    }

    #[Route('/{id}', name: 'api_form_webhooks_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function show(int $id, Request $request): JsonResponse
    {
        $webhook = $this->formWebhookRepository->findOneWithActionsById($id);
        if (!$webhook instanceof FormWebhook) {
            return new JsonResponse(['error' => 'Webhook introuvable'], Response::HTTP_NOT_FOUND);
        }

        $user = $this->currentUser();
        if (!$this->canAccessWebhook($user, $webhook)) {
            return new JsonResponse(['error' => 'Accès refusé'], Response::HTTP_FORBIDDEN);
        }

        $c = $this->formWebhookLogRepository->countByWebhook($webhook);
        $wid = $webhook->getId();
        $lastLog = null !== $wid && $wid > 0 ? $this->lastLogSummaryForWebhookId($wid) : null;

        return new JsonResponse($this->serialize($webhook, $this->publicOrigin($request), $c, $lastLog));
    }

    #[Route('/{id}/audit', name: 'api_form_webhooks_audit', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function audit(int $id): JsonResponse
    {
        $webhook = $this->formWebhookRepository->find($id);
        if (!$webhook instanceof FormWebhook) {
            return new JsonResponse(['error' => 'Webhook introuvable'], Response::HTTP_NOT_FOUND);
        }

        $user = $this->currentUser();
        if (!$this->canAccessWebhook($user, $webhook)) {
            return new JsonResponse(['error' => 'Accès refusé'], Response::HTTP_FORBIDDEN);
        }

        $logs = $this->resourceAuditLogRepository->findForResource(ResourceAuditLog::RESOURCE_FORM_WEBHOOK, $id, 200);

        return new JsonResponse([
            'webhookId' => $id,
            'currentVersion' => $webhook->getVersion(),
            'items' => array_map(fn (ResourceAuditLog $log) => $this->serializeResourceAudit($log), $logs),
        ]);
    }

    #[Route('', name: 'api_form_webhooks_create', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function create(Request $request): JsonResponse
    {
        $user = $this->currentUser();
        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            return new JsonResponse(['error' => 'JSON invalide'], Response::HTTP_BAD_REQUEST);
        }

        $webhook = new FormWebhook();
        $err = $this->applyPayload($webhook, $data, $user);
        if ($err !== null) {
            return $err;
        }

        $limitErr = $this->assertSubscriptionAllowsWebhook($webhook, isNew: true);
        if ($limitErr !== null) {
            return $limitErr;
        }

        $violations = $this->validator->validate($webhook);
        if (\count($violations) > 0) {
            return $this->validationErrorResponse($violations);
        }

        foreach ($webhook->getActions() as $action) {
            $v = $this->validator->validate($action);
            if (\count($v) > 0) {
                return $this->validationErrorResponse($v);
            }
        }

        $webhook->setCreatedBy($user);

        $this->entityManager->persist($webhook);
        $this->entityManager->flush();

        $this->resourceAuditLogger->persist(
            $request,
            $user,
            ResourceAuditLog::RESOURCE_FORM_WEBHOOK,
            ResourceAuditLog::ACTION_CREATED,
            $webhook->getId() ?? 0,
            $webhook->getOrganization(),
            [
                'version' => $webhook->getVersion(),
                'snapshot' => FormWebhookAuditSnapshot::fromWebhook($webhook),
            ],
        );
        $this->entityManager->flush();

        return new JsonResponse(
            $this->serialize($webhook, $this->publicOrigin($request), 0, null),
            Response::HTTP_CREATED,
        );
    }

    #[Route('/{id}', name: 'api_form_webhooks_update', methods: ['PUT'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function update(int $id, Request $request): JsonResponse
    {
        $webhook = $this->formWebhookRepository->find($id);
        if (!$webhook instanceof FormWebhook) {
            return new JsonResponse(['error' => 'Webhook introuvable'], Response::HTTP_NOT_FOUND);
        }

        $user = $this->currentUser();
        if (!$this->canAccessWebhook($user, $webhook)) {
            return new JsonResponse(['error' => 'Accès refusé'], Response::HTTP_FORBIDDEN);
        }

        $beforeSnap = FormWebhookAuditSnapshot::fromWebhook($webhook);
        $beforeVersion = $webhook->getVersion();

        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            return new JsonResponse(['error' => 'JSON invalide'], Response::HTTP_BAD_REQUEST);
        }

        $err = $this->applyPayload($webhook, $data, $user, allowPartial: true);
        if ($err !== null) {
            return $err;
        }

        if ($webhook->getActions()->isEmpty()) {
            $webhook->setActive(false);
        }

        if ($webhook->getCreatedBy() === null) {
            $webhook->setCreatedBy($user);
        }

        $limitErr = $this->assertSubscriptionAllowsWebhook($webhook, isNew: false);
        if ($limitErr !== null) {
            return $limitErr;
        }

        $violations = $this->validator->validate($webhook);
        if (\count($violations) > 0) {
            return $this->validationErrorResponse($violations);
        }

        foreach ($webhook->getActions() as $action) {
            $v = $this->validator->validate($action);
            if (\count($v) > 0) {
                return $this->validationErrorResponse($v);
            }
        }

        $afterSnap = FormWebhookAuditSnapshot::fromWebhook($webhook);
        if (json_encode($beforeSnap) !== json_encode($afterSnap)) {
            $webhook->setVersion($beforeVersion + 1);
            $this->resourceAuditLogger->persist(
                $request,
                $user,
                ResourceAuditLog::RESOURCE_FORM_WEBHOOK,
                ResourceAuditLog::ACTION_UPDATED,
                $webhook->getId() ?? 0,
                $webhook->getOrganization(),
                [
                    'version' => $webhook->getVersion(),
                    'previousVersion' => $beforeVersion,
                    'changedKeys' => FormWebhookAuditSnapshot::changedTopLevelKeys($beforeSnap, $afterSnap),
                    'diff' => FormWebhookAuditChangeBuilder::build($beforeSnap, $afterSnap),
                ],
            );
        }

        $this->entityManager->flush();

        /** Recharge avec jointures actions : évite une collection vide / incohérente en mémoire après flush (réponse PUT). */
        $reloaded = $this->formWebhookRepository->findOneWithActionsById($id);
        if ($reloaded instanceof FormWebhook) {
            $webhook = $reloaded;
        }

        $c = $this->formWebhookLogRepository->countByWebhook($webhook);
        $wid = $webhook->getId();
        $lastLog = null !== $wid && $wid > 0 ? $this->lastLogSummaryForWebhookId($wid) : null;

        return new JsonResponse($this->serialize($webhook, $this->publicOrigin($request), $c, $lastLog));
    }

    #[Route('/{id}', name: 'api_form_webhooks_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function delete(int $id): Response
    {
        $webhook = $this->formWebhookRepository->find($id);
        if (!$webhook instanceof FormWebhook) {
            return new JsonResponse(['error' => 'Webhook introuvable'], Response::HTTP_NOT_FOUND);
        }

        $user = $this->currentUser();
        if (!$this->canAccessWebhook($user, $webhook)) {
            return new JsonResponse(['error' => 'Accès refusé'], Response::HTTP_FORBIDDEN);
        }

        $wid = $webhook->getId() ?? 0;
        $org = $webhook->getOrganization();
        $this->resourceAuditLogger->persist(
            $request,
            $user,
            ResourceAuditLog::RESOURCE_FORM_WEBHOOK,
            ResourceAuditLog::ACTION_DELETED,
            $wid,
            $org,
            [
                'version' => $webhook->getVersion(),
                'name' => $webhook->getName(),
                'lastSnapshot' => FormWebhookAuditSnapshot::fromWebhook($webhook),
            ],
        );
        $this->entityManager->remove($webhook);
        $this->entityManager->flush();

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    #[Route('/{id}/duplicate', name: 'api_form_webhooks_duplicate', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function duplicate(int $id, Request $request): JsonResponse
    {
        $source = $this->formWebhookRepository->findOneWithActionsById($id);
        if (!$source instanceof FormWebhook) {
            return new JsonResponse(['error' => 'Webhook introuvable'], Response::HTTP_NOT_FOUND);
        }

        $user = $this->currentUser();
        if (!$this->canAccessWebhook($user, $source)) {
            return new JsonResponse(['error' => 'Accès refusé'], Response::HTTP_FORBIDDEN);
        }

        if ($source->getActions()->isEmpty()) {
            return new JsonResponse(['error' => 'Ce workflow n’a aucune action à dupliquer.'], Response::HTTP_BAD_REQUEST);
        }

        $copy = new FormWebhook();
        $org = $source->getOrganization();
        if (!$org instanceof Organization) {
            return new JsonResponse(['error' => 'Organisation manquante'], Response::HTTP_BAD_REQUEST);
        }

        $copy->setOrganization($org);
        $copy->setName($this->duplicateWebhookDisplayName($source->getName()));
        $copy->setDescription($source->getDescription());
        $copy->setMetadata($source->getMetadata());
        $copy->setActive($source->isActive());
        $srcProj = $source->getProject();
        if (
            !$srcProj instanceof WebhookProject
            || $srcProj->getOrganization()?->getId() !== $org->getId()
        ) {
            $srcProj = $this->defaultWebhookProjectService->ensureDefaultForOrganization($org);
            $this->entityManager->flush();
        }
        $copy->setProject($srcProj);

        $actionErr = $this->replaceWebhookActions($copy, $this->actionRowsFromWebhook($source));
        if ($actionErr !== null) {
            return $actionErr;
        }

        $notifErr = $this->applyNotificationFields($copy, [
            'notificationEmailSource' => $source->getNotificationEmailSource(),
            'notificationCustomEmail' => $source->getNotificationCustomEmail(),
            'notifyOnError' => $source->isNotifyOnError(),
        ], false);
        if ($notifErr !== null) {
            return $notifErr;
        }

        $limitErr = $this->assertSubscriptionAllowsWebhook($copy, true);
        if ($limitErr !== null) {
            return $limitErr;
        }

        $violations = $this->validator->validate($copy);
        if (\count($violations) > 0) {
            return $this->validationErrorResponse($violations);
        }

        foreach ($copy->getActions() as $action) {
            $v = $this->validator->validate($action);
            if (\count($v) > 0) {
                return $this->validationErrorResponse($v);
            }
        }

        $copy->setCreatedBy($user);

        $this->entityManager->persist($copy);
        $this->entityManager->flush();

        $this->resourceAuditLogger->persist(
            $request,
            $user,
            ResourceAuditLog::RESOURCE_FORM_WEBHOOK,
            ResourceAuditLog::ACTION_CREATED,
            $copy->getId() ?? 0,
            $org,
            [
                'version' => $copy->getVersion(),
                'duplicateOfWebhookId' => $source->getId(),
                'duplicateOfName' => $source->getName(),
                'snapshot' => FormWebhookAuditSnapshot::fromWebhook($copy),
            ],
        );
        $this->entityManager->flush();

        return new JsonResponse(
            $this->serialize($copy, $this->publicOrigin($request), 0, null),
            Response::HTTP_CREATED,
        );
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

    /**
     * Accès au webhook : administrateurs tout voir ; les autres utilisateurs uniquement les webhooks de leur organisation.
     */
    private function canAccessWebhook(User $user, FormWebhook $webhook): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }

        $org = $user->getOrganization();
        $webhookOrgId = $webhook->getOrganization()?->getId();
        if ($org === null || $webhookOrgId === null || !$user->hasMembershipInOrganization($org)) {
            return false;
        }

        return $org->getId() === $webhookOrgId;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function applyPayload(FormWebhook $webhook, array $data, User $user, bool $allowPartial = false): ?JsonResponse
    {
        $isAdmin = $this->isAdmin($user);

        if (!$allowPartial || \array_key_exists('organizationId', $data)) {
            if ($allowPartial && !\array_key_exists('organizationId', $data)) {
                // conserve l’organisation existante
            } else {
                if ($isAdmin) {
                    $orgId = $data['organizationId'] ?? null;
                    if ($orgId === null || $orgId === '') {
                        return new JsonResponse(['error' => 'organizationId requis pour un administrateur'], Response::HTTP_BAD_REQUEST);
                    }
                    $org = $this->organizationRepository->find((int) $orgId);
                    if (!$org instanceof Organization) {
                        return new JsonResponse(['error' => 'Organisation introuvable'], Response::HTTP_BAD_REQUEST);
                    }
                } else {
                    $org = $user->getOrganization();
                    if ($org === null) {
                        return new JsonResponse(['error' => 'Aucune organisation'], Response::HTTP_BAD_REQUEST);
                    }
                    if (!$user->hasMembershipInOrganization($org)) {
                        return new JsonResponse(['error' => 'Organisation non autorisée'], Response::HTTP_FORBIDDEN);
                    }
                    if (isset($data['organizationId']) && (int) $data['organizationId'] !== (int) $org->getId()) {
                        return new JsonResponse(['error' => 'Organisation non autorisée'], Response::HTTP_FORBIDDEN);
                    }
                }
                $webhook->setOrganization($org);
            }
        }

        if (!$allowPartial || isset($data['name'])) {
            if (isset($data['name'])) {
                $webhook->setName(trim((string) $data['name']));
            }
        }

        if (isset($data['description'])) {
            $webhook->setDescription($data['description'] !== null ? (string) $data['description'] : null);
        }

        if (isset($data['metadata']) && \is_array($data['metadata'])) {
            $webhook->setMetadata($data['metadata']);
        }

        if (\array_key_exists('active', $data)) {
            $webhook->setActive((bool) $data['active']);
        }

        $projErr = $this->applyProjectFromPayload($webhook, $data, $allowPartial);
        if ($projErr !== null) {
            return $projErr;
        }

        $notifErr = $this->applyNotificationFields($webhook, $data, $allowPartial);
        if ($notifErr !== null) {
            return $notifErr;
        }

        if (!$allowPartial) {
            $list = $this->normalizeActionsInputCreate($data);
            if ($list === null) {
                return new JsonResponse(
                    ['error' => 'Fournissez un tableau `actions` (au moins une entrée) ou les champs Mailjet legacy (mailjetId, mailjetTemplateId, …).'],
                    Response::HTTP_BAD_REQUEST,
                );
            }

            return $this->replaceWebhookActions($webhook, $list);
        }

        if (\array_key_exists('actions', $data)) {
            if (!\is_array($data['actions'])) {
                return new JsonResponse(['error' => '`actions` doit être un tableau.'], Response::HTTP_BAD_REQUEST);
            }

            return $this->replaceWebhookActions($webhook, $data['actions']);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function applyProjectFromPayload(FormWebhook $webhook, array $data, bool $allowPartial): ?JsonResponse
    {
        $org = $webhook->getOrganization();
        if (!$org instanceof Organization) {
            return new JsonResponse(['error' => 'Organisation manquante pour affecter un projet.'], Response::HTTP_BAD_REQUEST);
        }

        if ($allowPartial && !\array_key_exists('projectId', $data)) {
            $proj = $webhook->getProject();
            if ($proj === null || $proj->getOrganization()?->getId() !== $org->getId()) {
                $webhook->setProject($this->defaultWebhookProjectService->ensureDefaultForOrganization($org));
            }

            return null;
        }

        $raw = $data['projectId'] ?? null;
        if ($raw === null || $raw === '') {
            $webhook->setProject($this->defaultWebhookProjectService->ensureDefaultForOrganization($org));

            return null;
        }

        $proj = $this->webhookProjectRepository->find((int) $raw);
        if (!$proj instanceof WebhookProject || $proj->getOrganization()?->getId() !== $org->getId()) {
            return new JsonResponse(
                ['error' => 'Projet introuvable ou non rattaché à la même organisation que le workflow.'],
                Response::HTTP_BAD_REQUEST,
            );
        }
        $webhook->setProject($proj);

        return null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function applyNotificationFields(FormWebhook $webhook, array $data, bool $allowPartial): ?JsonResponse
    {
        if (!$allowPartial) {
            $src = isset($data['notificationEmailSource']) ? (string) $data['notificationEmailSource'] : FormWebhook::NOTIFICATION_EMAIL_CREATOR;
            if (!\in_array($src, [FormWebhook::NOTIFICATION_EMAIL_CREATOR, FormWebhook::NOTIFICATION_EMAIL_CUSTOM], true)) {
                return new JsonResponse(['error' => 'notificationEmailSource doit être « creator » ou « custom ».'], Response::HTTP_BAD_REQUEST);
            }
            $webhook->setNotificationEmailSource($src);
            if (\array_key_exists('notificationCustomEmail', $data)) {
                $v = $data['notificationCustomEmail'];
                $webhook->setNotificationCustomEmail($v !== null && $v !== '' ? trim((string) $v) : null);
            } else {
                $webhook->setNotificationCustomEmail(null);
            }
            $webhook->setNotifyOnError(\array_key_exists('notifyOnError', $data) ? (bool) $data['notifyOnError'] : true);
        } else {
            if (\array_key_exists('notificationEmailSource', $data)) {
                $src = (string) $data['notificationEmailSource'];
                if (!\in_array($src, [FormWebhook::NOTIFICATION_EMAIL_CREATOR, FormWebhook::NOTIFICATION_EMAIL_CUSTOM], true)) {
                    return new JsonResponse(['error' => 'notificationEmailSource doit être « creator » ou « custom ».'], Response::HTTP_BAD_REQUEST);
                }
                $webhook->setNotificationEmailSource($src);
            }
            if (\array_key_exists('notificationCustomEmail', $data)) {
                $v = $data['notificationCustomEmail'];
                $webhook->setNotificationCustomEmail($v !== null && $v !== '' ? trim((string) $v) : null);
            }
            if (\array_key_exists('notifyOnError', $data)) {
                $webhook->setNotifyOnError((bool) $data['notifyOnError']);
            }
        }

        if ($webhook->getNotificationEmailSource() === FormWebhook::NOTIFICATION_EMAIL_CUSTOM) {
            $ce = $webhook->getNotificationCustomEmail();
            if ($ce === null || trim($ce) === '' || !filter_var(trim($ce), FILTER_VALIDATE_EMAIL)) {
                return new JsonResponse(
                    ['error' => 'Une adresse e-mail valide est requise lorsque la notification est envoyée à une adresse personnalisée.'],
                    Response::HTTP_BAD_REQUEST,
                );
            }
        }

        $webhook->setNotifyOnSuccess(false);

        return null;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<array<string, mixed>>|null
     */
    private function normalizeActionsInputCreate(array $data): ?array
    {
        if (\array_key_exists('actions', $data)) {
            if (!\is_array($data['actions'])) {
                return null;
            }

            return $data['actions'];
        }

        if (!isset($data['mailjetId'])) {
            return null;
        }

        return [[
            'actionType' => ServiceIntegrationType::MAILJET,
            'mailjetId' => $data['mailjetId'],
            'mailjetTemplateId' => $data['mailjetTemplateId'] ?? null,
            'templateLanguage' => $data['templateLanguage'] ?? true,
            'variableMapping' => $data['variableMapping'] ?? [],
            'recipientEmailPostKey' => $data['recipientEmailPostKey'] ?? null,
            'recipientNamePostKey' => $data['recipientNamePostKey'] ?? null,
            'defaultRecipientEmail' => $data['defaultRecipientEmail'] ?? null,
            'active' => true,
            'sortOrder' => 0,
        ]];
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function replaceWebhookActions(FormWebhook $webhook, array $rows): ?JsonResponse
    {
        $org = $webhook->getOrganization();
        if (!$org instanceof Organization) {
            return new JsonResponse(['error' => 'Organisation manquante'], Response::HTTP_BAD_REQUEST);
        }

        foreach ($webhook->getActions()->toArray() as $old) {
            $webhook->removeAction($old);
        }

        foreach ($rows as $idx => $row) {
            if (!\is_array($row)) {
                return new JsonResponse(['error' => 'Chaque action doit être un objet.'], Response::HTTP_BAD_REQUEST);
            }

            $actionType = isset($row['actionType']) ? trim((string) $row['actionType']) : ServiceIntegrationType::MAILJET;
            if ($actionType === '') {
                $actionType = ServiceIntegrationType::MAILJET;
            }

            $mapRaw = $row['variableMapping'] ?? [];
            if (!\is_array($mapRaw)) {
                return new JsonResponse(['error' => 'variableMapping doit être un objet pour chaque action.'], Response::HTTP_BAD_REQUEST);
            }
            /** @var array<string, string> $map */
            $map = [];
            foreach ($mapRaw as $mk => $sk) {
                $map[(string) $mk] = (string) $sk;
            }

            $action = new FormWebhookAction();
            $action->setActionType($actionType);
            $action->setVariableMapping($map);
            $action->setActive(\array_key_exists('active', $row) ? (bool) $row['active'] : true);
            $action->setSortOrder(isset($row['sortOrder']) ? (int) $row['sortOrder'] : $idx);

            if (\array_key_exists('comment', $row)) {
                $c = $row['comment'];
                $action->setComment($c !== null && $c !== '' ? trim((string) $c) : null);
            }

            if (\array_key_exists('payloadTemplate', $row)) {
                $pt = $row['payloadTemplate'];
                $action->setPayloadTemplate($pt !== null && $pt !== '' ? (string) $pt : null);
            }
            if (\array_key_exists('smsToPostKey', $row)) {
                $v = $row['smsToPostKey'];
                $action->setSmsToPostKey($v !== null && $v !== '' ? (string) $v : null);
            }
            if (\array_key_exists('smsToDefault', $row)) {
                $v = $row['smsToDefault'];
                $action->setSmsToDefault($v !== null && $v !== '' ? (string) $v : null);
            }

            if ($actionType === ServiceIntegrationType::MAILJET) {
                $connId = isset($row['serviceConnectionId']) ? (int) $row['serviceConnectionId'] : 0;
                $legacyMailjetId = isset($row['mailjetId']) ? (int) $row['mailjetId'] : 0;

                if ($connId >= 1) {
                    $conn = $this->serviceConnectionRepository->find($connId);
                    if (!$conn instanceof ServiceConnection) {
                        return new JsonResponse(['error' => 'Connecteur Mailjet introuvable.'], Response::HTTP_BAD_REQUEST);
                    }
                    if ($conn->getOrganization()?->getId() !== $org->getId()) {
                        return new JsonResponse(['error' => 'Le connecteur doit appartenir à la même organisation que le déclencheur.'], Response::HTTP_BAD_REQUEST);
                    }
                    if ($conn->getType() !== ServiceIntegrationType::MAILJET) {
                        return new JsonResponse(['error' => 'Le connecteur doit être de type Mailjet pour une action Mailjet.'], Response::HTTP_BAD_REQUEST);
                    }
                    $action->setServiceConnection($conn);
                    $action->setMailjet(null);
                } elseif ($legacyMailjetId >= 1) {
                    $mj = $this->mailjetRepository->find($legacyMailjetId);
                    if (!$mj instanceof Mailjet) {
                        return new JsonResponse(['error' => 'Configuration Mailjet introuvable pour une action.'], Response::HTTP_BAD_REQUEST);
                    }
                    if ($mj->getOrganization()?->getId() !== $org->getId()) {
                        return new JsonResponse(['error' => 'Chaque compte Mailjet doit appartenir à la même organisation.'], Response::HTTP_BAD_REQUEST);
                    }
                    $action->setMailjet($mj);
                    $action->setServiceConnection(null);
                } else {
                    return new JsonResponse(
                        ['error' => 'serviceConnectionId requis pour une action Mailjet (compte Mailjet dans Intégrations).'],
                        Response::HTTP_BAD_REQUEST,
                    );
                }

                $templateId = isset($row['mailjetTemplateId']) ? (int) $row['mailjetTemplateId'] : 0;
                if ($templateId < 1) {
                    return new JsonResponse(['error' => 'mailjetTemplateId requis pour une action Mailjet.'], Response::HTTP_BAD_REQUEST);
                }

                $action->setMailjetTemplateId($templateId);
                $action->setTemplateLanguage(\array_key_exists('templateLanguage', $row) ? (bool) $row['templateLanguage'] : true);
                if (\array_key_exists('recipientEmailPostKey', $row)) {
                    $v = $row['recipientEmailPostKey'];
                    $action->setRecipientEmailPostKey($v !== null && $v !== '' ? (string) $v : null);
                }
                if (\array_key_exists('recipientNamePostKey', $row)) {
                    $v = $row['recipientNamePostKey'];
                    $action->setRecipientNamePostKey($v !== null && $v !== '' ? (string) $v : null);
                }
                if (\array_key_exists('defaultRecipientEmail', $row)) {
                    $v = $row['defaultRecipientEmail'];
                    $action->setDefaultRecipientEmail($v !== null && $v !== '' ? (string) $v : null);
                }
            } else {
                if (!ServiceIntegrationType::isConnectionType($actionType)) {
                    return new JsonResponse(['error' => 'actionType de connecteur invalide.'], Response::HTTP_BAD_REQUEST);
                }

                $connId = isset($row['serviceConnectionId']) ? (int) $row['serviceConnectionId'] : 0;
                if ($connId < 1) {
                    return new JsonResponse(['error' => 'serviceConnectionId requis pour une action hors Mailjet.'], Response::HTTP_BAD_REQUEST);
                }
                $conn = $this->serviceConnectionRepository->find($connId);
                if (!$conn instanceof \App\Entity\ServiceConnection) {
                    return new JsonResponse(['error' => 'Connecteur introuvable.'], Response::HTTP_BAD_REQUEST);
                }
                if ($conn->getOrganization()?->getId() !== $org->getId()) {
                    return new JsonResponse(['error' => 'Le connecteur doit appartenir à la même organisation que le déclencheur.'], Response::HTTP_BAD_REQUEST);
                }
                if ($conn->getType() !== $actionType) {
                    return new JsonResponse(['error' => 'Le connecteur choisi ne correspond pas au type d’action.'], Response::HTTP_BAD_REQUEST);
                }

                $action->setMailjet(null);
                $action->setServiceConnection($conn);
                $action->setMailjetTemplateId(0);
                $action->setTemplateLanguage(true);
                $action->setRecipientEmailPostKey(null);
                $action->setRecipientNamePostKey(null);
                $action->setDefaultRecipientEmail(null);
            }

            $webhook->addAction($action);
        }

        return null;
    }

    private function publicOrigin(Request $request): string
    {
        $base = trim($this->publicIngressBaseUrl);
        if ($base !== '') {
            return rtrim($base, '/');
        }

        return $request->getSchemeAndHttpHost();
    }

    /**
     * @param list<FormWebhook> $webhooks
     *
     * @return array<int, int>
     */
    private function logCountsForWebhooks(array $webhooks): array
    {
        $ids = [];
        foreach ($webhooks as $w) {
            $id = $w->getId();
            if ($id !== null) {
                $ids[] = $id;
            }
        }

        return $this->formWebhookLogRepository->countGroupedByWebhookIds($ids);
    }

    /**
     * @param list<FormWebhook> $webhooks
     *
     * @return array<int, array{status: string, receivedAt: string|null, errorDetail: string|null}>
     */
    private function lastLogSummariesForWebhooks(array $webhooks): array
    {
        $ids = [];
        foreach ($webhooks as $w) {
            $id = $w->getId();
            if (null !== $id && $id > 0) {
                $ids[] = $id;
            }
        }

        return $this->formWebhookLogRepository->lastLogSummaryByWebhookIds($ids);
    }

    /**
     * @return array{status: string, receivedAt: string|null, errorDetail: string|null}|null
     */
    private function lastLogSummaryForWebhookId(int $webhookId): ?array
    {
        if ($webhookId <= 0) {
            return null;
        }
        $map = $this->formWebhookLogRepository->lastLogSummaryByWebhookIds([$webhookId]);

        return $map[$webhookId] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeFormWebhookAction(FormWebhookAction $a): array
    {
        $row = [
            'id' => $a->getId(),
            'sortOrder' => $a->getSortOrder(),
            'active' => $a->isActive(),
            'actionType' => $a->getActionType(),
            'actionTypeLabel' => ServiceIntegrationType::labels()[$a->getActionType()] ?? $a->getActionType(),
            'serviceConnectionId' => $a->getServiceConnection()?->getId(),
            'serviceConnectionName' => $a->getServiceConnection()?->getName(),
            'payloadTemplate' => $a->getPayloadTemplate(),
            'smsToPostKey' => $a->getSmsToPostKey(),
            'smsToDefault' => $a->getSmsToDefault(),
            'mailjetId' => $a->getMailjet()?->getId(),
            'mailjetTemplateId' => $a->getMailjetTemplateId(),
            'templateLanguage' => $a->isTemplateLanguage(),
            'variableMapping' => $a->getVariableMapping(),
            'recipientEmailPostKey' => $a->getRecipientEmailPostKey(),
            'recipientNamePostKey' => $a->getRecipientNamePostKey(),
            'defaultRecipientEmail' => $a->getDefaultRecipientEmail(),
            'comment' => $a->getComment(),
        ];

        return $row;
    }

    /**
     * @param array{status: string, receivedAt: string|null, errorDetail: string|null}|null $lastLogSummary
     *
     * @return array<string, mixed>
     */
    private function serialize(FormWebhook $w, string $origin, int $logsCount = 0, ?array $lastLogSummary = null): array
    {
        $path = '/webhook/form/'.$w->getPublicToken();
        $row = [
            'id' => $w->getId(),
            'publicToken' => $w->getPublicToken(),
            'ingressUrl' => $origin.$path,
            'logsCount' => $logsCount,
            'version' => $w->getVersion(),
            'name' => $w->getName(),
            'description' => $w->getDescription(),
            'actions' => array_map(fn (FormWebhookAction $a) => $this->serializeFormWebhookAction($a), $w->getActions()->toArray()),
            'metadata' => $w->getMetadata(),
            'active' => $w->isActive(),
            'createdAt' => $w->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'updatedAt' => $w->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
            'organizationId' => $w->getOrganization()?->getId(),
            'notificationEmailSource' => $w->getNotificationEmailSource(),
            'notificationCustomEmail' => $w->getNotificationCustomEmail(),
            'notifyOnError' => $w->isNotifyOnError(),
            'notifyOnSuccess' => false,
            'projectId' => $w->getProject()?->getId(),
            'project' => $w->getProject() !== null
                ? ['id' => $w->getProject()->getId(), 'name' => $w->getProject()->getName()]
                : null,
            'createdById' => $w->getCreatedBy()?->getId(),
            'createdByEmail' => $w->getCreatedBy()?->getEmail(),
        ];

        if ($w->getOrganization() instanceof Organization) {
            $o = $w->getOrganization();
            $row['organization'] = ['id' => $o->getId(), 'name' => $o->getName()];
        }

        $row['notificationDiagnostics'] = $this->buildNotificationDiagnostics($w);

        if (null !== $lastLogSummary) {
            $err = $lastLogSummary['errorDetail'] ?? null;
            if (null !== $err && '' !== $err && \strlen($err) > 180) {
                $err = mb_substr($err, 0, 177).'…';
            }
            $row['lastLogStatus'] = $lastLogSummary['status'];
            $row['lastLogReceivedAt'] = $lastLogSummary['receivedAt'];
            $row['lastLogErrorDetail'] = $err;
        } else {
            $row['lastLogStatus'] = null;
            $row['lastLogReceivedAt'] = null;
            $row['lastLogErrorDetail'] = null;
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildNotificationDiagnostics(FormWebhook $w): array
    {
        $platform = $this->errorNotifyPlatformInfo->snapshot();
        $recipient = FormWebhookNotificationRecipientResolver::resolve($w);
        $willSendOnError = $w->isNotifyOnError() && $recipient !== null;

        return [
            'effectiveRecipientEmail' => $recipient,
            'recipientBlockedReason' => $recipient === null ? FormWebhookNotificationRecipientResolver::explainMissingRecipient($w) : null,
            'notifyOnError' => $w->isNotifyOnError(),
            'notifyOnSuccess' => false,
            'notificationEmailSource' => $w->getNotificationEmailSource(),
            'willSendErrorEmailWhenRecipientOk' => $willSendOnError,
            'willSendSuccessEmailWhenRecipientOk' => false,
            'platform' => [
                'primaryChannelForErrors' => $platform['primaryChannelForErrors'],
                'errorNotifyWebhookConfigured' => $platform['errorNotifyWebhookConfigured'] ?? false,
                'smtpFallbackAfterWebhookFailure' => true,
                'mailjetApiKeysConfigured' => $platform['mailjetApiKeysConfigured'],
                'resolvedMailjetTemplateId' => $platform['resolvedMailjetTemplateId'],
            ],
            'errorNotifySummary' => $this->buildErrorNotifyHumanSummary($w, $recipient, $platform),
        ];
    }

    /**
     * @param array<string, mixed> $platform
     */
    private function buildErrorNotifyHumanSummary(FormWebhook $w, ?string $recipient, array $platform): string
    {
        if (!$w->isNotifyOnError()) {
            return 'Les notifications d’erreur sont désactivées pour ce workflow.';
        }
        if ($recipient === null) {
            return 'Les notifications d’erreur sont activées, mais aucun e-mail ne sera envoyé : '
                .FormWebhookNotificationRecipientResolver::explainMissingRecipient($w);
        }

        $viaWebhook = ($platform['errorNotifyWebhookConfigured'] ?? false) === true;
        $channel = $viaWebhook
            ? 'un webhook formulaire plateforme (puis e-mail SMTP Symfony si cet envoi échoue)'
            : 'le transport SMTP Symfony uniquement (URL d’alerte webhook non configurée ou invalide)';

        return sprintf(
            'En cas d’échec (parsing ou action), un e-mail récapitulatif est envoyé à %s via %s.',
            $recipient,
            $channel,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function actionRowsFromWebhook(FormWebhook $source): array
    {
        $rows = [];
        foreach ($source->getActions()->toArray() as $a) {
            if (!$a instanceof FormWebhookAction) {
                continue;
            }
            $mj = $a->getMailjet();
            $rows[] = [
                'actionType' => $a->getActionType(),
                'serviceConnectionId' => $a->getServiceConnection()?->getId(),
                'payloadTemplate' => $a->getPayloadTemplate(),
                'smsToPostKey' => $a->getSmsToPostKey(),
                'smsToDefault' => $a->getSmsToDefault(),
                'mailjetId' => $mj?->getId() ?? 0,
                'mailjetTemplateId' => $a->getMailjetTemplateId(),
                'templateLanguage' => $a->isTemplateLanguage(),
                'variableMapping' => $a->getVariableMapping(),
                'recipientEmailPostKey' => $a->getRecipientEmailPostKey(),
                'recipientNamePostKey' => $a->getRecipientNamePostKey(),
                'defaultRecipientEmail' => $a->getDefaultRecipientEmail(),
                'comment' => $a->getComment(),
                'active' => $a->isActive(),
                'sortOrder' => $a->getSortOrder(),
            ];
        }

        return $rows;
    }

    private function duplicateWebhookDisplayName(string $name): string
    {
        $suffix = ' copy';
        $base = trim($name);
        if ($base === '') {
            $base = 'Workflow';
        }
        $newName = $base.$suffix;
        $max = 180;
        if (mb_strlen($newName) > $max) {
            $maxBase = $max - mb_strlen($suffix);
            $newName = ($maxBase < 1 ? '' : mb_substr($base, 0, $maxBase)).$suffix;
        }

        return $newName;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeResourceAudit(ResourceAuditLog $log): array
    {
        $details = $log->getDetails();
        if (ResourceAuditLog::RESOURCE_FORM_WEBHOOK === $log->getResourceType() && \is_array($details)) {
            $details = FormWebhookAuditDetailsPresenter::forApi($details, $log->getAction());
        }

        return [
            'id' => $log->getId(),
            'occurredAt' => $log->getOccurredAt()->format(\DateTimeInterface::ATOM),
            'action' => $log->getAction(),
            'resourceType' => $log->getResourceType(),
            'resourceId' => $log->getResourceId(),
            'actorEmail' => $log->getActorUser()?->getEmail(),
            'details' => $details,
        ];
    }

    private function validationErrorResponse(ConstraintViolationListInterface $errors): JsonResponse
    {
        $messages = [];
        foreach ($errors as $error) {
            $messages[$error->getPropertyPath()] = $error->getMessage();
        }

        return new JsonResponse(['error' => 'Validation', 'fields' => $messages], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    private function assertSubscriptionAllowsWebhook(FormWebhook $webhook, bool $isNew): ?JsonResponse
    {
        if ($this->currentUser()->ignoresPlatformSubscriptionLimits()) {
            return null;
        }

        $org = $webhook->getOrganization();
        if (!$org instanceof Organization) {
            return null;
        }

        if (!$this->subscriptionEntitlement->isEntitledToWebhooks($org)) {
            return new JsonResponse([
                'error' => 'L’abonnement de l’organisation ne permet pas de modifier les webhooks tant que le forfait n’est pas actif.',
                'code' => 'subscription_inactive',
                'subscription' => $this->subscriptionEntitlement->buildSnapshot($org),
            ], Response::HTTP_PAYMENT_REQUIRED);
        }

        $max = $this->subscriptionEntitlement->getMaxWebhooks($org);
        if ($max === null) {
            return null;
        }

        $count = $this->subscriptionEntitlement->countWebhooksExcluding($org, $isNew ? null : $webhook);
        if ($count >= $max) {
            return new JsonResponse([
                'error' => 'Nombre maximal de webhooks atteint pour ce forfait. Passez à l’offre supérieure ou supprimez un webhook existant.',
                'code' => 'subscription_webhook_limit',
                'subscription' => $this->subscriptionEntitlement->buildSnapshot($org),
            ], Response::HTTP_FORBIDDEN);
        }

        return null;
    }
}
