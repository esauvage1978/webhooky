<?php

declare(strict_types=1);

namespace App\Controller\Integration;

use App\Entity\Organization;
use App\Entity\OrganizationIntegration;
use App\Entity\WebhookProject;
use App\Entity\User;
use App\Integration\OrganizationIntegrationType;
use App\Repository\OrganizationIntegrationRepository;
use App\Repository\OrganizationRepository;
use App\Repository\WebhookProjectRepository;
use App\Security\SensitiveStringEncryptor;
use App\Service\SEO\GoogleOAuthService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class GoogleController extends AbstractController
{
    private const SESSION_STATE = 'google_gsc_oauth_state';

    private const SESSION_PROJECT = 'google_gsc_oauth_project_id';

    public function __construct(
        private readonly GoogleOAuthService $googleOAuthService,
        private readonly OrganizationRepository $organizationRepository,
        private readonly WebhookProjectRepository $webhookProjectRepository,
        private readonly OrganizationIntegrationRepository $organizationIntegrationRepository,
        private readonly SensitiveStringEncryptor $encryptor,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/auth/google', name: 'integration_google_start', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function start(Request $request): Response
    {
        if (!$this->googleOAuthService->isConfigured()) {
            return new Response(
                '<html><body><p>OAuth Google non configuré. Un administrateur doit renseigner les options plateforme : <code>google_oauth_client_id</code> et <code>google_oauth_client_secret_cipher</code> (via Administration → Options plateforme).</p></body></html>',
                Response::HTTP_SERVICE_UNAVAILABLE,
                ['Content-Type' => 'text/html; charset=UTF-8'],
            );
        }

        $user = $this->getUser();
        if (!$user instanceof User) {
            return new Response('Non authentifié.', Response::HTTP_UNAUTHORIZED);
        }

        $projectId = (int) $request->query->get('projectId', 0);
        if ($projectId < 1) {
            return new Response('Paramètre projectId manquant.', Response::HTTP_BAD_REQUEST);
        }
        $project = $this->webhookProjectRepository->find($projectId);
        if (!$project instanceof WebhookProject) {
            return new Response('Projet introuvable.', Response::HTTP_NOT_FOUND);
        }
        $org = $project->getOrganization();
        if (!$org instanceof Organization || !$user->hasMembershipInOrganization($org)) {
            return new Response('Accès refusé à ce projet.', Response::HTTP_FORBIDDEN);
        }

        $state = bin2hex(random_bytes(16));
        $session = $request->getSession();
        $session->set(self::SESSION_STATE, $state);
        $session->set(self::SESSION_PROJECT, $projectId);

        $redirectUri = $this->generateUrl('integration_google_callback', [], UrlGeneratorInterface::ABSOLUTE_URL);
        $url = $this->googleOAuthService->buildAuthorizationUrl($redirectUri, $state);

        return $this->redirect($url);
    }

    #[Route('/auth/google/callback', name: 'integration_google_callback', methods: ['GET'])]
    public function callback(Request $request): Response
    {
        if (!$this->googleOAuthService->isConfigured()) {
            return new Response('OAuth Google non configuré.', Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $session = $request->getSession();
        $expected = $session->get(self::SESSION_STATE);
        $projectId = (int) $session->get(self::SESSION_PROJECT, 0);
        $state = (string) $request->query->get('state', '');
        if (!\is_string($expected) || $expected === '' || !hash_equals($expected, $state)) {
            return new Response('Session OAuth invalide ou expirée.', Response::HTTP_BAD_REQUEST);
        }
        $session->remove(self::SESSION_STATE);
        $session->remove(self::SESSION_PROJECT);

        $code = (string) $request->query->get('code', '');
        if ($code === '') {
            $err = (string) $request->query->get('error', 'unknown');

            return new Response('Connexion annulée ou refusée : '.htmlspecialchars($err, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), Response::HTTP_BAD_REQUEST);
        }

        $user = $this->getUser();
        if (!$user instanceof User) {
            return new Response('Veuillez vous reconnecter puis relancer la connexion Google.', Response::HTTP_UNAUTHORIZED);
        }

        $project = $this->webhookProjectRepository->find($projectId);
        if (!$project instanceof WebhookProject) {
            return new Response('Projet invalide pour ce jeton OAuth.', Response::HTTP_FORBIDDEN);
        }
        $org = $project->getOrganization();
        if (!$org instanceof Organization || !$user->hasMembershipInOrganization($org)) {
            return new Response('Accès refusé à ce projet.', Response::HTTP_FORBIDDEN);
        }

        $redirectUri = $this->generateUrl('integration_google_callback', [], UrlGeneratorInterface::ABSOLUTE_URL);
        try {
            $tokens = $this->googleOAuthService->exchangeAuthorizationCode($code, $redirectUri);
        } catch (\Throwable $e) {
            return new Response('Échange de jeton impossible : '.htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), Response::HTTP_BAD_GATEWAY);
        }

        $access = (string) ($tokens['access_token'] ?? '');
        $refresh = (string) ($tokens['refresh_token'] ?? '');
        if ($access === '' || $refresh === '') {
            return new Response('Réponse Google incomplète (refresh token manquant — réessayez avec prompt=consent).', Response::HTTP_BAD_GATEWAY);
        }

        $this->organizationIntegrationRepository->removeGscForProject($project);

        $integration = new OrganizationIntegration();
        $integration->setProject($project);
        $integration->setType(OrganizationIntegrationType::GSC);
        $integration->setAccessTokenCipher($this->encryptor->encrypt($access));
        $integration->setRefreshTokenCipher($this->encryptor->encrypt($refresh));
        $ttl = (int) ($tokens['expires_in'] ?? 3600);
        $integration->setExpiresAt(new \DateTimeImmutable('+'.$ttl.' seconds'));
        if (isset($tokens['scope']) && \is_string($tokens['scope'])) {
            $integration->setScope($tokens['scope']);
        }
        $this->entityManager->persist($integration);
        $this->entityManager->flush();

        return $this->redirect('/integrations?tab=gsc&gsc=connected&projectId='.$projectId);
    }
}
