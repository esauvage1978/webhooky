<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\FormWebhook;
use App\Entity\FormWebhookAction;
use App\Entity\Mailjet;
use App\Entity\Organization;
use App\Entity\User;
use App\Repository\FormWebhookLogRepository;
use App\Repository\FormWebhookRepository;
use App\Repository\MailjetRepository;
use App\Repository\OrganizationRepository;
use App\Subscription\SubscriptionEntitlementService;
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
        private readonly OrganizationRepository $organizationRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly ValidatorInterface $validator,
        private readonly SubscriptionEntitlementService $subscriptionEntitlement,
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

            return new JsonResponse(array_map(fn (FormWebhook $w) => $this->serialize($w, $base, $counts[$w->getId() ?? 0] ?? 0), $all));
        }

        $org = $user->getOrganization();
        if ($org === null) {
            return new JsonResponse([]);
        }

        $items = $this->formWebhookRepository->findByOrganizationOrdered($org);
        $counts = $this->logCountsForWebhooks($items);

        return new JsonResponse(array_map(fn (FormWebhook $w) => $this->serialize($w, $base, $counts[$w->getId() ?? 0] ?? 0), $items));
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

        return new JsonResponse($this->serialize($webhook, $this->publicOrigin($request), $c));
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

        return new JsonResponse(
            $this->serialize($webhook, $this->publicOrigin($request), 0),
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

        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            return new JsonResponse(['error' => 'JSON invalide'], Response::HTTP_BAD_REQUEST);
        }

        $err = $this->applyPayload($webhook, $data, $user, allowPartial: true);
        if ($err !== null) {
            return $err;
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

        $this->entityManager->flush();

        $c = $this->formWebhookLogRepository->countByWebhook($webhook);

        return new JsonResponse($this->serialize($webhook, $this->publicOrigin($request), $c));
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

        $this->entityManager->remove($webhook);
        $this->entityManager->flush();

        return new Response('', Response::HTTP_NO_CONTENT);
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
            $webhook->setNotifyOnSuccess(\array_key_exists('notifyOnSuccess', $data) ? (bool) $data['notifyOnSuccess'] : false);
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
            if (\array_key_exists('notifyOnSuccess', $data)) {
                $webhook->setNotifyOnSuccess((bool) $data['notifyOnSuccess']);
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

        return null;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<array<string, mixed>>|null
     */
    private function normalizeActionsInputCreate(array $data): ?array
    {
        if (isset($data['actions']) && \is_array($data['actions']) && $data['actions'] !== []) {
            return $data['actions'];
        }

        if (!isset($data['mailjetId'])) {
            return null;
        }

        return [[
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

            $mailjetId = isset($row['mailjetId']) ? (int) $row['mailjetId'] : 0;
            if ($mailjetId < 1) {
                return new JsonResponse(['error' => 'mailjetId invalide pour une action.'], Response::HTTP_BAD_REQUEST);
            }
            $mj = $this->mailjetRepository->find($mailjetId);
            if (!$mj instanceof Mailjet) {
                return new JsonResponse(['error' => 'Configuration Mailjet introuvable pour une action.'], Response::HTTP_BAD_REQUEST);
            }
            if ($mj->getOrganization()?->getId() !== $org->getId()) {
                return new JsonResponse(['error' => 'Chaque compte Mailjet doit appartenir à la même organisation.'], Response::HTTP_BAD_REQUEST);
            }

            $templateId = isset($row['mailjetTemplateId']) ? (int) $row['mailjetTemplateId'] : 0;
            if ($templateId < 1) {
                return new JsonResponse(['error' => 'mailjetTemplateId requis pour chaque action.'], Response::HTTP_BAD_REQUEST);
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
            $action->setMailjet($mj);
            $action->setMailjetTemplateId($templateId);
            $action->setTemplateLanguage(\array_key_exists('templateLanguage', $row) ? (bool) $row['templateLanguage'] : true);
            $action->setVariableMapping($map);
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
            $action->setActive(\array_key_exists('active', $row) ? (bool) $row['active'] : true);
            $action->setSortOrder(isset($row['sortOrder']) ? (int) $row['sortOrder'] : $idx);

            $webhook->addAction($action);
        }

        if ($webhook->getActions()->isEmpty()) {
            return new JsonResponse(['error' => 'Au moins une action est requise.'], Response::HTTP_BAD_REQUEST);
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
     * @return array<string, mixed>
     */
    private function serializeFormWebhookAction(FormWebhookAction $a): array
    {
        return [
            'id' => $a->getId(),
            'sortOrder' => $a->getSortOrder(),
            'active' => $a->isActive(),
            'mailjetId' => $a->getMailjet()?->getId(),
            'mailjetTemplateId' => $a->getMailjetTemplateId(),
            'templateLanguage' => $a->isTemplateLanguage(),
            'variableMapping' => $a->getVariableMapping(),
            'recipientEmailPostKey' => $a->getRecipientEmailPostKey(),
            'recipientNamePostKey' => $a->getRecipientNamePostKey(),
            'defaultRecipientEmail' => $a->getDefaultRecipientEmail(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(FormWebhook $w, string $origin, int $logsCount = 0): array
    {
        $path = '/webhook/form/'.$w->getPublicToken();
        $row = [
            'id' => $w->getId(),
            'publicToken' => $w->getPublicToken(),
            'ingressUrl' => $origin.$path,
            'logsCount' => $logsCount,
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
            'notifyOnSuccess' => $w->isNotifyOnSuccess(),
            'createdById' => $w->getCreatedBy()?->getId(),
            'createdByEmail' => $w->getCreatedBy()?->getEmail(),
        ];

        if ($w->getOrganization() instanceof Organization) {
            $o = $w->getOrganization();
            $row['organization'] = ['id' => $o->getId(), 'name' => $o->getName()];
        }

        return $row;
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
