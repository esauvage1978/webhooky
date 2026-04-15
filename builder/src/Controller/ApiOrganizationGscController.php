<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Entity\WebhookProject;
use App\Repository\OrganizationIntegrationRepository;
use App\Repository\WebhookProjectRepository;
use App\Service\SEO\GoogleSearchConsoleService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/webhook-projects')]
final class ApiOrganizationGscController extends AbstractController
{
    public function __construct(
        private readonly WebhookProjectRepository $webhookProjectRepository,
        private readonly OrganizationIntegrationRepository $organizationIntegrationRepository,
        private readonly GoogleSearchConsoleService $googleSearchConsoleService,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/{id}/gsc', name: 'api_project_gsc_status', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function status(int $id): JsonResponse
    {
        $project = $this->resolveProject($id);
        if ($project instanceof JsonResponse) {
            return $project;
        }
        $int = $this->organizationIntegrationRepository->findGscForProject($project);

        return new JsonResponse([
            'connected' => $int !== null,
            'integrationId' => $int?->getId(),
            'siteUrl' => $int?->getSiteUrl(),
            'expiresAt' => $int?->getExpiresAt()?->format(\DateTimeInterface::ATOM),
        ]);
    }

    #[Route('/{id}/gsc/sites', name: 'api_project_gsc_sites', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function sites(int $id): JsonResponse
    {
        $project = $this->resolveProject($id);
        if ($project instanceof JsonResponse) {
            return $project;
        }
        try {
            $list = $this->googleSearchConsoleService->listSitesForProject($project);
        } catch (\Throwable) {
            $list = [];
        }

        return new JsonResponse(['items' => $list]);
    }

    #[Route('/{id}/gsc/site', name: 'api_project_gsc_select_site', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function selectSite(int $id, Request $request): JsonResponse
    {
        $project = $this->resolveProject($id);
        if ($project instanceof JsonResponse) {
            return $project;
        }
        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            return new JsonResponse(['error' => 'JSON invalide'], Response::HTTP_BAD_REQUEST);
        }
        $siteUrl = isset($data['siteUrl']) ? trim((string) $data['siteUrl']) : '';
        if ($siteUrl === '') {
            return new JsonResponse(['error' => 'siteUrl requis'], Response::HTTP_BAD_REQUEST);
        }
        $int = $this->organizationIntegrationRepository->findGscForProject($project);
        if ($int === null) {
            return new JsonResponse(['error' => 'Aucune intégration GSC'], Response::HTTP_BAD_REQUEST);
        }
        $int->setSiteUrl($siteUrl);
        $this->entityManager->flush();

        return new JsonResponse(['ok' => true, 'siteUrl' => $int->getSiteUrl()]);
    }

    #[Route('/{id}/gsc', name: 'api_project_gsc_disconnect', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function disconnect(int $id): JsonResponse
    {
        $project = $this->resolveProject($id);
        if ($project instanceof JsonResponse) {
            return $project;
        }
        $this->organizationIntegrationRepository->removeGscForProject($project);
        $this->entityManager->flush();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    private function resolveProject(int $id): WebhookProject|JsonResponse
    {
        $project = $this->webhookProjectRepository->find($id);
        if (!$project instanceof WebhookProject) {
            return new JsonResponse(['error' => 'Projet introuvable'], Response::HTTP_NOT_FOUND);
        }
        $user = $this->getUser();
        $org = $project->getOrganization();
        if (!$user instanceof User || $org === null || !$user->hasMembershipInOrganization($org)) {
            return new JsonResponse(['error' => 'Accès refusé'], Response::HTTP_FORBIDDEN);
        }

        return $project;
    }
}
