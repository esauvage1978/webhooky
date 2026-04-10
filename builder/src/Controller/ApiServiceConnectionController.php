<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Organization;
use App\Entity\ResourceAuditLog;
use App\Entity\ServiceConnection;
use App\Entity\User;
use App\Repository\FormWebhookActionRepository;
use App\Repository\OrganizationRepository;
use App\Repository\ResourceAuditLogRepository;
use App\Repository\ServiceConnectionRepository;
use App\Service\Audit\ResourceAuditLogger;
use App\Service\Audit\ServiceConnectionAuditSnapshot;
use App\ServiceIntegration\ServiceIntegrationType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/service-connections')]
final class ApiServiceConnectionController extends AbstractController
{
    public function __construct(
        private readonly ServiceConnectionRepository $serviceConnectionRepository,
        private readonly FormWebhookActionRepository $formWebhookActionRepository,
        private readonly OrganizationRepository $organizationRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly ValidatorInterface $validator,
        private readonly ResourceAuditLogger $resourceAuditLogger,
        private readonly ResourceAuditLogRepository $resourceAuditLogRepository,
    ) {
    }

    #[Route('/types', name: 'api_service_connection_types', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function types(): JsonResponse
    {
        $types = [];
        foreach (ServiceIntegrationType::connectionTypes() as $t) {
            $types[] = [
                'id' => $t,
                'label' => ServiceIntegrationType::labels()[$t] ?? $t,
                'vendorUrl' => ServiceIntegrationType::vendorUrl($t),
                'configSchema' => $this->configSchemaHint($t),
                'configExampleFilled' => $this->configExampleFilled($t),
            ];
        }

        return new JsonResponse(['types' => $types]);
    }

    #[Route('', name: 'api_service_connections_list', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function list(): JsonResponse
    {
        $user = $this->requireUser();
        if ($this->isAdmin($user)) {
            $items = $this->serviceConnectionRepository->findAllOrderedForAdmin();
        } else {
            $org = $user->getOrganization();
            if ($org === null) {
                return new JsonResponse([]);
            }
            if (!$user->hasMembershipInOrganization($org)) {
                return new JsonResponse(['error' => 'Contexte organisation invalide'], Response::HTTP_FORBIDDEN);
            }
            $items = $this->serviceConnectionRepository->findByOrganizationOrdered($org);
        }

        $ids = [];
        foreach ($items as $s) {
            $sid = $s->getId();
            if ($sid !== null) {
                $ids[] = $sid;
            }
        }
        $usageById = $this->formWebhookActionRepository->aggregateUsageByServiceConnectionIds($ids);

        return new JsonResponse(array_map(function (ServiceConnection $s) use ($usageById, $user) {
            $id = $s->getId();

            return $this->serialize(
                $s,
                $this->isAdmin($user),
                $id !== null ? ($usageById[$id] ?? null) : null,
            );
        }, $items));
    }

    #[Route('/{id}/audit', name: 'api_service_connections_audit', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function audit(int $id): JsonResponse
    {
        $user = $this->requireUser();
        $s = $this->serviceConnectionRepository->find($id);
        if (!$s instanceof ServiceConnection) {
            return new JsonResponse(['error' => 'Connecteur introuvable'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->canAccess($user, $s)) {
            return new JsonResponse(['error' => 'Accès refusé'], Response::HTTP_FORBIDDEN);
        }

        $logs = $this->resourceAuditLogRepository->findForResource(ResourceAuditLog::RESOURCE_SERVICE_CONNECTION, $id, 200);

        return new JsonResponse([
            'serviceConnectionId' => $id,
            'items' => array_map(fn (ResourceAuditLog $log) => $this->serializeResourceAudit($log), $logs),
        ]);
    }

    #[Route('', name: 'api_service_connections_create', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function create(Request $request): JsonResponse
    {
        $user = $this->requireUser();
        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            return new JsonResponse(['error' => 'JSON invalide'], Response::HTTP_BAD_REQUEST);
        }

        $type = isset($data['type']) ? trim((string) $data['type']) : '';
        if (!ServiceIntegrationType::isConnectionType($type)) {
            return new JsonResponse(['error' => 'Type de connecteur invalide.'], Response::HTTP_BAD_REQUEST);
        }

        $configRaw = $data['config'] ?? null;
        if (!\is_array($configRaw)) {
            return new JsonResponse(['error' => '`config` doit être un objet JSON.'], Response::HTTP_BAD_REQUEST);
        }

        /** @var array<string, mixed> $config */
        $config = $configRaw;
        $cfgErr = $this->validateConfigShape($type, $config);
        if ($cfgErr !== null) {
            return new JsonResponse(['error' => $cfgErr], Response::HTTP_BAD_REQUEST);
        }

        $orgErr = $this->resolveOrganizationForWrite($user, $data);
        if ($orgErr instanceof JsonResponse) {
            return $orgErr;
        }
        /** @var Organization $org */
        $org = $orgErr;

        $s = new ServiceConnection();
        $s->setOrganization($org);
        $s->setCreatedBy($user);
        $s->setType($type);
        $s->setName(trim((string) ($data['name'] ?? '')));
        $s->setConfig($config);

        $v = $this->validator->validate($s);
        if (\count($v) > 0) {
            return $this->validationErrorResponse($v);
        }

        $this->entityManager->persist($s);
        $this->entityManager->flush();

        $this->resourceAuditLogger->persist(
            $request,
            $user,
            ResourceAuditLog::RESOURCE_SERVICE_CONNECTION,
            ResourceAuditLog::ACTION_CREATED,
            $s->getId() ?? 0,
            $s->getOrganization(),
            ['snapshot' => ServiceConnectionAuditSnapshot::fromConnection($s)],
        );
        $this->entityManager->flush();

        return new JsonResponse($this->serialize($s, $this->isAdmin($user)), Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'api_service_connections_update', methods: ['PUT'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function update(int $id, Request $request): JsonResponse
    {
        $user = $this->requireUser();
        $s = $this->serviceConnectionRepository->find($id);
        if (!$s instanceof ServiceConnection) {
            return new JsonResponse(['error' => 'Connecteur introuvable'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->canAccess($user, $s)) {
            return new JsonResponse(['error' => 'Accès refusé'], Response::HTTP_FORBIDDEN);
        }

        $beforeSnap = ServiceConnectionAuditSnapshot::fromConnection($s);

        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            return new JsonResponse(['error' => 'JSON invalide'], Response::HTTP_BAD_REQUEST);
        }

        if (isset($data['type'])) {
            $type = trim((string) $data['type']);
            if (!ServiceIntegrationType::isConnectionType($type)) {
                return new JsonResponse(['error' => 'Type de connecteur invalide.'], Response::HTTP_BAD_REQUEST);
            }
            $s->setType($type);
        }

        if (\array_key_exists('name', $data)) {
            $s->setName(trim((string) $data['name']));
        }

        if (\array_key_exists('config', $data)) {
            $configRaw = $data['config'];
            if (!\is_array($configRaw)) {
                return new JsonResponse(['error' => '`config` doit être un objet JSON.'], Response::HTTP_BAD_REQUEST);
            }
            /** @var array<string, mixed> $config */
            $config = $configRaw;
            if ($s->getType() === ServiceIntegrationType::MAILJET) {
                $config = $this->mergeMailjetConfigPreservingMaskedPrivate($s->getConfig(), $config);
            }
            $cfgErr = $this->validateConfigShape($s->getType(), $config);
            if ($cfgErr !== null) {
                return new JsonResponse(['error' => $cfgErr], Response::HTTP_BAD_REQUEST);
            }
            $s->setConfig($config);
        }

        if ($this->isAdmin($user) && \array_key_exists('organizationId', $data)) {
            $oid = $data['organizationId'];
            $o = $this->organizationRepository->find((int) $oid);
            if (!$o instanceof Organization) {
                return new JsonResponse(['error' => 'Organisation introuvable'], Response::HTTP_BAD_REQUEST);
            }
            $s->setOrganization($o);
        }

        $v = $this->validator->validate($s);
        if (\count($v) > 0) {
            return $this->validationErrorResponse($v);
        }

        $afterSnap = ServiceConnectionAuditSnapshot::fromConnection($s);
        if (json_encode($beforeSnap) !== json_encode($afterSnap)) {
            $this->resourceAuditLogger->persist(
                $request,
                $user,
                ResourceAuditLog::RESOURCE_SERVICE_CONNECTION,
                ResourceAuditLog::ACTION_UPDATED,
                $s->getId() ?? 0,
                $s->getOrganization(),
                [
                    'snapshot' => $afterSnap,
                    'requestKeys' => array_keys($data),
                ],
            );
        }

        $this->entityManager->flush();

        return new JsonResponse($this->serialize($s, $this->isAdmin($user)));
    }

    #[Route('/{id}', name: 'api_service_connections_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function delete(int $id, Request $request): Response
    {
        $user = $this->requireUser();
        $s = $this->serviceConnectionRepository->find($id);
        if (!$s instanceof ServiceConnection) {
            return new JsonResponse(['error' => 'Connecteur introuvable'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->canAccess($user, $s)) {
            return new JsonResponse(['error' => 'Accès refusé'], Response::HTTP_FORBIDDEN);
        }

        $n = $this->serviceConnectionRepository->countUsagesInWebhookActions($id);
        if ($n > 0) {
            return new JsonResponse(
                ['error' => 'Ce connecteur est utilisé par au moins une action de déclencheur. Retirez-le d’abord des workflows.'],
                Response::HTTP_CONFLICT,
            );
        }

        $this->resourceAuditLogger->persist(
            $request,
            $user,
            ResourceAuditLog::RESOURCE_SERVICE_CONNECTION,
            ResourceAuditLog::ACTION_DELETED,
            $s->getId() ?? 0,
            $s->getOrganization(),
            ['snapshot' => ServiceConnectionAuditSnapshot::fromConnection($s)],
        );
        $this->entityManager->remove($s);
        $this->entityManager->flush();

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    private function requireUser(): User
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

    private function canAccess(User $user, ServiceConnection $s): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }
        $org = $user->getOrganization();
        if ($org === null || !$user->hasMembershipInOrganization($org)) {
            return false;
        }

        return $s->getOrganization()?->getId() === $org->getId();
    }

    /**
     * @param array<string, mixed> $data
     */
    private function resolveOrganizationForWrite(User $user, array $data): Organization|JsonResponse
    {
        if ($this->isAdmin($user)) {
            $orgId = $data['organizationId'] ?? null;
            if ($orgId === null || $orgId === '') {
                return new JsonResponse(['error' => 'organizationId requis pour un administrateur.'], Response::HTTP_BAD_REQUEST);
            }
            $org = $this->organizationRepository->find((int) $orgId);
            if (!$org instanceof Organization) {
                return new JsonResponse(['error' => 'Organisation introuvable'], Response::HTTP_BAD_REQUEST);
            }

            return $org;
        }

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

        return $org;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function validateConfigShape(string $type, array $config): ?string
    {
        $httpsUrl = static function (mixed $v): bool {
            $u = \is_string($v) ? trim($v) : '';

            return str_starts_with(strtolower($u), 'https://') && filter_var($u, FILTER_VALIDATE_URL) !== false;
        };

        return match ($type) {
            ServiceIntegrationType::MAILJET => $this->nonEmptyStrings($config, ['apiKeyPublic', 'apiKeyPrivate'])
                ? null
                : 'Mailjet : apiKeyPublic et apiKeyPrivate sont requis.',
            ServiceIntegrationType::SLACK,
            ServiceIntegrationType::TEAMS,
            ServiceIntegrationType::DISCORD,
            ServiceIntegrationType::GOOGLE_CHAT,
            ServiceIntegrationType::MATTERMOST => $httpsUrl($config['webhookUrl'] ?? '') ? null : 'Renseignez une webhookUrl HTTPS valide.',
            ServiceIntegrationType::TWILIO_SMS => $this->nonEmptyStrings($config, ['accountSid', 'authToken', 'fromNumber'])
                ? null
                : 'Twilio : accountSid, authToken et fromNumber sont requis.',
            ServiceIntegrationType::VONAGE_SMS => $this->nonEmptyStrings($config, ['apiKey', 'apiSecret', 'from'])
                ? null
                : 'Vonage : apiKey, apiSecret et from sont requis.',
            ServiceIntegrationType::MESSAGEBIRD_SMS => $this->nonEmptyStrings($config, ['accessKey', 'originator'])
                ? null
                : 'MessageBird : accessKey et originator sont requis.',
            ServiceIntegrationType::TELEGRAM => $this->nonEmptyStrings($config, ['botToken', 'chatId'])
                ? null
                : 'Telegram : botToken et chatId sont requis.',
            ServiceIntegrationType::PUSHOVER => $this->nonEmptyStrings($config, ['appToken', 'userKey'])
                ? null
                : 'Pushover : appToken et userKey sont requis.',
            ServiceIntegrationType::HTTP_WEBHOOK => $httpsUrl($config['url'] ?? '') ? null : 'Renseignez une url HTTPS valide.',
            default => 'Type inconnu.',
        };
    }

    /**
     * @param array<string, mixed> $config
     * @param list<string>         $keys
     */
    private function nonEmptyStrings(array $config, array $keys): bool
    {
        foreach ($keys as $k) {
            $v = isset($config[$k]) ? trim((string) $config[$k]) : '';
            if ($v === '') {
                return false;
            }
        }

        return true;
    }

    private function configSchemaHint(string $type): array
    {
        return match ($type) {
            ServiceIntegrationType::MAILJET => [
                'apiKeyPublic' => 'string (clé API REST publique Mailjet)',
                'apiKeyPrivate' => 'string (clé API secrète)',
            ],
            ServiceIntegrationType::SLACK,
            ServiceIntegrationType::TEAMS,
            ServiceIntegrationType::DISCORD,
            ServiceIntegrationType::GOOGLE_CHAT,
            ServiceIntegrationType::MATTERMOST => [
                'webhookUrl' => 'https://…',
            ],
            ServiceIntegrationType::TWILIO_SMS => [
                'accountSid' => 'AC…',
                'authToken' => '…',
                'fromNumber' => '+33…',
            ],
            ServiceIntegrationType::VONAGE_SMS => [
                'apiKey' => 'xxxxxxxx',
                'apiSecret' => 'xxxxxxxxXXXXXXXX',
                'from' => 'Acme ou +33123456789',
            ],
            ServiceIntegrationType::MESSAGEBIRD_SMS => [
                'accessKey' => 'live_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
                'originator' => 'Webhooky ou +33123456789',
            ],
            ServiceIntegrationType::TELEGRAM => [
                'botToken' => '123456:ABC…',
                'chatId' => '123456789',
            ],
            ServiceIntegrationType::PUSHOVER => [
                'appToken' => '…',
                'userKey' => '…',
            ],
            ServiceIntegrationType::HTTP_WEBHOOK => [
                'url' => 'https://…',
                'method' => 'POST',
                'headers' => ['Authorization' => 'Bearer …'],
            ],
            default => [],
        };
    }

    /**
     * Exemple JSON « rempli » pour l’aide UI (données fictives).
     *
     * @return array<string, mixed>
     */
    private function configExampleFilled(string $type): array
    {
        return match ($type) {
            ServiceIntegrationType::MAILJET => [
                'apiKeyPublic' => 'a1b2c3d4e5f6789012345678abcdef12',
                'apiKeyPrivate' => '9f8e7d6c5b4a39281716151413121109',
            ],
            ServiceIntegrationType::SLACK => [
                'webhookUrl' => 'https://example.invalid/slack-incoming-webhook-a-coller-depuis-l-app',
            ],
            ServiceIntegrationType::TEAMS => [
                'webhookUrl' => 'https://outlook.office.com/webhook/00000000-0000-0000-0000-000000000000@00000000-0000-0000-0000-000000000000/IncomingWebhook/xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx/00000000-0000-0000-0000-000000000000',
            ],
            ServiceIntegrationType::DISCORD => [
                'webhookUrl' => 'https://discord.com/api/webhooks/1234567890123456789/abcdefghijklmnopqrstuvwxyz1234567890ABCDEFGH',
            ],
            ServiceIntegrationType::GOOGLE_CHAT => [
                'webhookUrl' => 'https://chat.googleapis.com/v1/spaces/AAAAxxxxx/messages?key=AIzaSyDdI0hCZtE6vySjMm-WEfRq3CPzqKqqsHI&token=xxxxxxxxxxxx',
            ],
            ServiceIntegrationType::MATTERMOST => [
                'webhookUrl' => 'https://mattermost.example.com/hooks/abcdefghijklmnop',
            ],
            ServiceIntegrationType::TWILIO_SMS => [
                'accountSid' => 'ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
                'authToken' => 'your_auth_token_here',
                'fromNumber' => '+33123456789',
            ],
            ServiceIntegrationType::VONAGE_SMS => [
                'apiKey' => 'a1b2c3d4',
                'apiSecret' => 'AbCdEfGhIjKlMnOp',
                'from' => 'Webhooky',
            ],
            ServiceIntegrationType::MESSAGEBIRD_SMS => [
                'accessKey' => 'live_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
                'originator' => '+33123456789',
            ],
            ServiceIntegrationType::TELEGRAM => [
                'botToken' => '123456789:AAHevxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
                'chatId' => '-1001234567890',
            ],
            ServiceIntegrationType::PUSHOVER => [
                'appToken' => 'azGDORePK8gMaC0QOYAMyEEuzJnyUi',
                'userKey' => 'uQiRzpo4DXghDmr9QzzQ9UzrEFaJohq7',
            ],
            ServiceIntegrationType::HTTP_WEBHOOK => [
                'url' => 'https://api.example.com/v1/inbound',
                'method' => 'POST',
                'headers' => [
                    'Authorization' => 'Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9…',
                    'Content-Type' => 'application/json',
                ],
            ],
            default => [],
        };
    }

    /**
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $incoming
     *
     * @return array<string, mixed>
     */
    private function mergeMailjetConfigPreservingMaskedPrivate(array $existing, array $incoming): array
    {
        $out = array_merge($existing, $incoming);
        if (!\array_key_exists('apiKeyPrivate', $incoming)) {
            return $out;
        }
        $priv = trim((string) $incoming['apiKeyPrivate']);
        if ($priv === '' || str_starts_with($priv, '•') || $priv === '********') {
            $out['apiKeyPrivate'] = isset($existing['apiKeyPrivate']) ? (string) $existing['apiKeyPrivate'] : '';
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function maskSensitiveConfigForApi(string $type, array $config): array
    {
        if ($type !== ServiceIntegrationType::MAILJET) {
            return $config;
        }
        $out = $config;
        if (isset($out['apiKeyPrivate']) && \is_string($out['apiKeyPrivate']) && $out['apiKeyPrivate'] !== '') {
            $out['apiKeyPrivate'] = '••••••••';
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(ServiceConnection $s, bool $admin, ?array $usage = null): array
    {
        $connId = $s->getId();
        if ($usage === null && $connId !== null) {
            $map = $this->formWebhookActionRepository->aggregateUsageByServiceConnectionIds([$connId]);
            $usage = $map[$connId] ?? ['workflowCount' => 0, 'actionCount' => 0];
        }
        $usage ??= ['workflowCount' => 0, 'actionCount' => 0];

        $row = [
            'id' => $s->getId(),
            'type' => $s->getType(),
            'typeLabel' => ServiceIntegrationType::labels()[$s->getType()] ?? $s->getType(),
            'name' => $s->getName(),
            'config' => $this->maskSensitiveConfigForApi($s->getType(), $s->getConfig()),
            'createdAt' => $s->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'organizationId' => $s->getOrganization()?->getId(),
            'workflowCount' => $usage['workflowCount'],
            'actionCount' => $usage['actionCount'],
        ];
        if ($admin && $s->getOrganization() instanceof Organization) {
            $o = $s->getOrganization();
            $row['organization'] = ['id' => $o->getId(), 'name' => $o->getName()];
        }
        if ($s->getCreatedBy() instanceof User) {
            $row['createdByEmail'] = $s->getCreatedBy()->getEmail();
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

    /**
     * @return array<string, mixed>
     */
    private function serializeResourceAudit(ResourceAuditLog $log): array
    {
        return [
            'id' => $log->getId(),
            'occurredAt' => $log->getOccurredAt()->format(\DateTimeInterface::ATOM),
            'action' => $log->getAction(),
            'resourceType' => $log->getResourceType(),
            'resourceId' => $log->getResourceId(),
            'actorEmail' => $log->getActorUser()?->getEmail(),
            'details' => $log->getDetails(),
        ];
    }
}
