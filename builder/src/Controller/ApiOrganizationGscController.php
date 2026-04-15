<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Organization;
use App\Entity\User;
use App\Repository\OrganizationIntegrationRepository;
use App\Repository\OrganizationRepository;
use App\Service\SEO\GoogleSearchConsoleService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/organizations')]
final class ApiOrganizationGscController extends AbstractController
{
    public function __construct(
        private readonly OrganizationRepository $organizationRepository,
        private readonly OrganizationIntegrationRepository $organizationIntegrationRepository,
        private readonly GoogleSearchConsoleService $googleSearchConsoleService,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/{id}/gsc', name: 'api_organization_gsc_status', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function status(int $id): JsonResponse
    {
        $org = $this->resolveOrganization($id);
        if ($org instanceof JsonResponse) {
            return $org;
        }
        $int = $this->organizationIntegrationRepository->findLatestGscForOrganization($org);

        return new JsonResponse([
            'connected' => $int !== null,
            'integrationId' => $int?->getId(),
            'siteUrl' => $int?->getSiteUrl(),
            'expiresAt' => $int?->getExpiresAt()?->format(\DateTimeInterface::ATOM),
        ]);
    }

    #[Route('/{id}/gsc/sites', name: 'api_organization_gsc_sites', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function sites(int $id): JsonResponse
    {
        $org = $this->resolveOrganization($id);
        if ($org instanceof JsonResponse) {
            return $org;
        }
        try {
            $list = $this->googleSearchConsoleService->listSitesForOrganization($org);
        } catch (\Throwable) {
            $list = [];
        }

        return new JsonResponse(['items' => $list]);
    }

    #[Route('/{id}/gsc/site', name: 'api_organization_gsc_select_site', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function selectSite(int $id, Request $request): JsonResponse
    {
        $org = $this->resolveOrganization($id);
        if ($org instanceof JsonResponse) {
            return $org;
        }
        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            return new JsonResponse(['error' => 'JSON invalide'], Response::HTTP_BAD_REQUEST);
        }
        $siteUrl = isset($data['siteUrl']) ? trim((string) $data['siteUrl']) : '';
        if ($siteUrl === '') {
            return new JsonResponse(['error' => 'siteUrl requis'], Response::HTTP_BAD_REQUEST);
        }
        $int = $this->organizationIntegrationRepository->findLatestGscForOrganization($org);
        if ($int === null) {
            return new JsonResponse(['error' => 'Aucune intégration GSC'], Response::HTTP_BAD_REQUEST);
        }
        $int->setSiteUrl($siteUrl);
        $this->entityManager->flush();

        return new JsonResponse(['ok' => true, 'siteUrl' => $int->getSiteUrl()]);
    }

    #[Route('/{id}/gsc', name: 'api_organization_gsc_disconnect', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function disconnect(int $id): JsonResponse
    {
        $org = $this->resolveOrganization($id);
        if ($org instanceof JsonResponse) {
            return $org;
        }
        foreach ($this->organizationIntegrationRepository->findGscIntegrationsForOrganization($org) as $i) {
            $this->entityManager->remove($i);
        }
        $this->entityManager->flush();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    private function resolveOrganization(int $id): Organization|JsonResponse
    {
        $org = $this->organizationRepository->find($id);
        if (!$org instanceof Organization) {
            return new JsonResponse(['error' => 'Organisation introuvable'], Response::HTTP_NOT_FOUND);
        }
        $user = $this->getUser();
        if (!$user instanceof User || !$user->hasMembershipInOrganization($org)) {
            return new JsonResponse(['error' => 'Accès refusé'], Response::HTTP_FORBIDDEN);
        }

        return $org;
    }
}
