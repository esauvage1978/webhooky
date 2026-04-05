<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Mailjet;
use App\Entity\Organization;
use App\Entity\User;
use App\Repository\MailjetRepository;
use App\Repository\OrganizationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/mailjets')]
final class ApiMailjetController extends AbstractController
{
    public function __construct(
        private readonly MailjetRepository $mailjetRepository,
        private readonly OrganizationRepository $organizationRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly ValidatorInterface $validator,
    ) {
    }

    #[Route('', name: 'api_mailjets_list', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function list(): JsonResponse
    {
        $user = $this->currentUser();
        if ($this->isAdmin($user)) {
            $items = $this->mailjetRepository->findAllOrderedForAdmin();
            $payload = array_map(fn (Mailjet $m) => $this->serialize($m, true, true), $items);

            return new JsonResponse($payload);
        }

        $org = $user->getOrganization();
        if ($org === null) {
            return new JsonResponse([]);
        }

        $items = $this->mailjetRepository->findByOrganizationOrdered($org);

        return new JsonResponse(array_map(fn (Mailjet $m) => $this->serialize($m, true, false), $items));
    }

    #[Route('/{id}', name: 'api_mailjets_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function show(int $id): JsonResponse
    {
        $mailjet = $this->mailjetRepository->find($id);
        if (!$mailjet instanceof Mailjet) {
            return new JsonResponse(['error' => 'Configuration introuvable'], Response::HTTP_NOT_FOUND);
        }

        $user = $this->currentUser();
        if (!$this->canAccessMailjet($user, $mailjet)) {
            return new JsonResponse(['error' => 'Accès refusé'], Response::HTTP_FORBIDDEN);
        }

        return new JsonResponse($this->serialize($mailjet, false, $this->isAdmin($user)));
    }

    #[Route('', name: 'api_mailjets_create', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function create(Request $request): JsonResponse
    {
        $user = $this->currentUser();
        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            return new JsonResponse(['error' => 'JSON invalide'], Response::HTTP_BAD_REQUEST);
        }

        if ($this->isAdmin($user)) {
            if (!isset($data['organizationId']) || $data['organizationId'] === '' || $data['organizationId'] === null) {
                return new JsonResponse(['error' => 'organizationId requis pour un administrateur.'], Response::HTTP_BAD_REQUEST);
            }
            $organization = $this->organizationRepository->find((int) $data['organizationId']);
            if (!$organization instanceof Organization) {
                return new JsonResponse(['error' => 'Organisation introuvable'], Response::HTTP_BAD_REQUEST);
            }
        } else {
            $organization = $user->getOrganization();
            if ($organization === null) {
                return new JsonResponse(
                    ['error' => 'Aucune organisation : votre compte doit être rattaché à une organisation.'],
                    Response::HTTP_BAD_REQUEST,
                );
            }
        }

        $mailjet = new Mailjet();
        $mailjet->setOrganization($organization);
        $mailjet->setName(trim((string) ($data['name'] ?? '')));
        $mailjet->setApiKeyPublic(trim((string) ($data['apiKeyPublic'] ?? '')));
        $mailjet->setApiKeyPrivate(trim((string) ($data['apiKeyPrivate'] ?? '')));
        $mailjet->setCreatedAt(new \DateTimeImmutable());
        $mailjet->setCreatedBy($user);

        $errors = $this->validator->validate($mailjet);
        if (\count($errors) > 0) {
            return $this->validationErrorResponse($errors);
        }

        $this->entityManager->persist($mailjet);
        $this->entityManager->flush();

        return new JsonResponse($this->serialize($mailjet, false, $this->isAdmin($user)), Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'api_mailjets_update', methods: ['PUT'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function update(int $id, Request $request): JsonResponse
    {
        $mailjet = $this->mailjetRepository->find($id);
        if (!$mailjet instanceof Mailjet) {
            return new JsonResponse(['error' => 'Configuration introuvable'], Response::HTTP_NOT_FOUND);
        }

        $user = $this->currentUser();
        if (!$this->canAccessMailjet($user, $mailjet)) {
            return new JsonResponse(['error' => 'Accès refusé'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            return new JsonResponse(['error' => 'JSON invalide'], Response::HTTP_BAD_REQUEST);
        }

        if (\array_key_exists('name', $data)) {
            $mailjet->setName(trim((string) $data['name']));
        }
        if (\array_key_exists('apiKeyPublic', $data)) {
            $mailjet->setApiKeyPublic(trim((string) $data['apiKeyPublic']));
        }
        if (\array_key_exists('apiKeyPrivate', $data)) {
            $mailjet->setApiKeyPrivate(trim((string) $data['apiKeyPrivate']));
        }

        if ($this->isAdmin($user) && \array_key_exists('organizationId', $data)) {
            $orgId = $data['organizationId'];
            if ($orgId === null || $orgId === '') {
                return new JsonResponse(['error' => 'organizationId invalide'], Response::HTTP_BAD_REQUEST);
            }
            $org = $this->organizationRepository->find((int) $orgId);
            if (!$org instanceof Organization) {
                return new JsonResponse(['error' => 'Organisation introuvable'], Response::HTTP_BAD_REQUEST);
            }
            $mailjet->setOrganization($org);
        }

        $errors = $this->validator->validate($mailjet);
        if (\count($errors) > 0) {
            return $this->validationErrorResponse($errors);
        }

        $this->entityManager->flush();

        return new JsonResponse($this->serialize($mailjet, false, $this->isAdmin($user)));
    }

    #[Route('/{id}', name: 'api_mailjets_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function delete(int $id): JsonResponse
    {
        $mailjet = $this->mailjetRepository->find($id);
        if (!$mailjet instanceof Mailjet) {
            return new JsonResponse(['error' => 'Configuration introuvable'], Response::HTTP_NOT_FOUND);
        }

        $user = $this->currentUser();
        if (!$this->canAccessMailjet($user, $mailjet)) {
            return new JsonResponse(['error' => 'Accès refusé'], Response::HTTP_FORBIDDEN);
        }

        $this->entityManager->remove($mailjet);
        $this->entityManager->flush();

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    private function currentUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('Utilisateur attendu.');
        }

        return $user;
    }

    private function isAdmin(User $user): bool
    {
        return \in_array('ROLE_ADMIN', $user->getRoles(), true);
    }

    private function canAccessMailjet(User $user, Mailjet $mailjet): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }

        $org = $user->getOrganization();

        return $org !== null && $org->getId() === $mailjet->getOrganization()?->getId();
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(Mailjet $mailjet, bool $maskPrivateKey, bool $includeOrganization): array
    {
        $private = $mailjet->getApiKeyPrivate();
        $createdBy = $mailjet->getCreatedBy();

        $row = [
            'id' => $mailjet->getId(),
            'name' => $mailjet->getName(),
            'apiKeyPublic' => $mailjet->getApiKeyPublic(),
            'apiKeyPrivate' => $maskPrivateKey ? $this->maskSecret($private) : $private,
            'createdAt' => $mailjet->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'createdBy' => $createdBy instanceof User
                ? ['id' => $createdBy->getId(), 'email' => $createdBy->getEmail()]
                : null,
        ];

        if ($includeOrganization) {
            $o = $mailjet->getOrganization();
            $row['organization'] = $o instanceof Organization
                ? ['id' => $o->getId(), 'name' => $o->getName()]
                : null;
        }

        return $row;
    }

    private function maskSecret(string $value): string
    {
        $len = \strlen($value);
        if ($len <= 8) {
            return str_repeat('•', $len);
        }

        return substr($value, 0, 4).'…'.substr($value, -4);
    }

    private function validationErrorResponse(ConstraintViolationListInterface $errors): JsonResponse
    {
        $messages = [];
        foreach ($errors as $error) {
            $messages[$error->getPropertyPath()] = $error->getMessage();
        }

        return new JsonResponse(['error' => 'Validation', 'fields' => $messages], Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
