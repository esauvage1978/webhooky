<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Organization;
use App\Entity\User;
use App\Entity\WebhookProject;
use App\Repository\OrganizationRepository;
use App\Repository\WebhookProjectRepository;
use App\WebhookProject\DefaultWebhookProjectService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/webhook-projects')]
final class ApiWebhookProjectController extends AbstractController
{
    public function __construct(
        private readonly WebhookProjectRepository $webhookProjectRepository,
        private readonly OrganizationRepository $organizationRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly ValidatorInterface $validator,
        private readonly DefaultWebhookProjectService $defaultWebhookProjectService,
    ) {
    }

    #[Route('', name: 'api_webhook_projects_list', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function list(): JsonResponse
    {
        $user = $this->requireUser();
        if ($this->isAdmin($user)) {
            $items = $this->webhookProjectRepository->findAllOrderedForAdmin();
        } else {
            $org = $user->getOrganization();
            if ($org === null) {
                return new JsonResponse([]);
            }
            if (!$user->hasMembershipInOrganization($org)) {
                return new JsonResponse(['error' => 'Contexte organisation invalide'], Response::HTTP_FORBIDDEN);
            }
            $items = $this->webhookProjectRepository->findByOrganizationOrdered($org);
        }

        return new JsonResponse(array_map(fn (WebhookProject $p) => $this->serialize($p, $this->isAdmin($user)), $items));
    }

    #[Route('', name: 'api_webhook_projects_create', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function create(Request $request): JsonResponse
    {
        $user = $this->requireUser();
        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            return new JsonResponse(['error' => 'JSON invalide'], Response::HTTP_BAD_REQUEST);
        }

        $orgRes = $this->resolveOrganizationForWrite($user, $data);
        if ($orgRes instanceof JsonResponse) {
            return $orgRes;
        }
        /** @var Organization $org */
        $org = $orgRes;

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            return new JsonResponse(['error' => 'Le nom du projet est obligatoire.'], Response::HTTP_BAD_REQUEST);
        }

        if ($this->webhookProjectRepository->findOneByOrganizationAndName($org, $name) !== null) {
            return new JsonResponse(['error' => 'Un projet porte déjà ce nom dans cette organisation.'], Response::HTTP_CONFLICT);
        }

        $p = new WebhookProject();
        $p->setOrganization($org);
        $p->setName($name);
        $p->setDescription(isset($data['description']) && $data['description'] !== null ? trim((string) $data['description']) : null);

        $v = $this->validator->validate($p);
        if (\count($v) > 0) {
            return $this->validationErrorResponse($v);
        }

        $this->entityManager->persist($p);
        $this->entityManager->flush();

        return new JsonResponse(
            $this->serialize($p, $this->isAdmin($user)),
            Response::HTTP_CREATED,
        );
    }

    #[Route('/{id}', name: 'api_webhook_projects_update', methods: ['PUT'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function update(int $id, Request $request): JsonResponse
    {
        $user = $this->requireUser();
        $p = $this->webhookProjectRepository->find($id);
        if (!$p instanceof WebhookProject) {
            return new JsonResponse(['error' => 'Projet introuvable'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->canAccess($user, $p)) {
            return new JsonResponse(['error' => 'Accès refusé'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            return new JsonResponse(['error' => 'JSON invalide'], Response::HTTP_BAD_REQUEST);
        }

        if (\array_key_exists('name', $data)) {
            $name = trim((string) $data['name']);
            if ($name === '') {
                return new JsonResponse(['error' => 'Le nom du projet est obligatoire.'], Response::HTTP_BAD_REQUEST);
            }
            if ($p->isDefault() && $name !== $p->getName()) {
                return new JsonResponse(
                    ['error' => 'Le nom du projet par défaut « Général » ne peut pas être modifié.'],
                    Response::HTTP_BAD_REQUEST,
                );
            }
            $existing = $this->webhookProjectRepository->findOneByOrganizationAndName($p->getOrganization(), $name);
            if ($existing !== null && $existing->getId() !== $p->getId()) {
                return new JsonResponse(['error' => 'Un projet porte déjà ce nom dans cette organisation.'], Response::HTTP_CONFLICT);
            }
            $p->setName($name);
        }

        if (\array_key_exists('description', $data)) {
            $p->setDescription($data['description'] !== null ? trim((string) $data['description']) : null);
        }

        if ($this->isAdmin($user) && \array_key_exists('organizationId', $data)) {
            $o = $this->organizationRepository->find((int) $data['organizationId']);
            if (!$o instanceof Organization) {
                return new JsonResponse(['error' => 'Organisation introuvable'], Response::HTTP_BAD_REQUEST);
            }
            $p->setOrganization($o);
        }

        $v = $this->validator->validate($p);
        if (\count($v) > 0) {
            return $this->validationErrorResponse($v);
        }

        $this->entityManager->flush();

        return new JsonResponse($this->serialize($p, $this->isAdmin($user)));
    }

    #[Route('/{id}', name: 'api_webhook_projects_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function delete(int $id): Response
    {
        $user = $this->requireUser();
        $p = $this->webhookProjectRepository->find($id);
        if (!$p instanceof WebhookProject) {
            return new JsonResponse(['error' => 'Projet introuvable'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->canAccess($user, $p)) {
            return new JsonResponse(['error' => 'Accès refusé'], Response::HTTP_FORBIDDEN);
        }

        if ($p->isDefault()) {
            return new JsonResponse(
                ['error' => 'Le projet « Général » par défaut ne peut pas être supprimé.'],
                Response::HTTP_FORBIDDEN,
            );
        }

        $org = $p->getOrganization();
        if (!$org instanceof Organization) {
            return new JsonResponse(['error' => 'Organisation manquante'], Response::HTTP_BAD_REQUEST);
        }

        $def = $this->defaultWebhookProjectService->ensureDefaultForOrganization($org);
        $this->entityManager->flush();

        foreach ($p->getWebhooks()->toArray() as $w) {
            $w->setProject($def);
        }

        $this->entityManager->remove($p);
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

    private function canAccess(User $user, WebhookProject $p): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }
        $org = $user->getOrganization();
        if ($org === null || !$user->hasMembershipInOrganization($org)) {
            return false;
        }

        return $p->getOrganization()?->getId() === $org->getId();
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
     * @return array<string, mixed>
     */
    private function serialize(WebhookProject $p, bool $forAdmin): array
    {
        $row = [
            'id' => $p->getId(),
            'name' => $p->getName(),
            'description' => $p->getDescription(),
            'isDefault' => $p->isDefault(),
            'webhookCount' => $this->webhookProjectRepository->countWebhooks($p),
        ];
        if ($p->getOrganization() instanceof Organization) {
            $o = $p->getOrganization();
            $row['organizationId'] = $o->getId();
            if ($forAdmin) {
                $row['organizationName'] = $o->getName();
            }
        }

        return $row;
    }

    private function validationErrorResponse(\Symfony\Component\Validator\ConstraintViolationListInterface $violations): JsonResponse
    {
        $fields = [];
        foreach ($violations as $v) {
            $fields[$v->getPropertyPath()] = (string) $v->getMessage();
        }

        return new JsonResponse(['error' => 'Données invalides', 'fields' => $fields], Response::HTTP_BAD_REQUEST);
    }
}
