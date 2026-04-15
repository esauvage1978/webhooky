<?php

declare(strict_types=1);

namespace App\Service\SEO;

use App\Entity\OrganizationIntegration;
use App\Entity\WebhookProject;
use App\Integration\OrganizationIntegrationType;
use App\Repository\OrganizationIntegrationRepository;
use App\Security\SensitiveStringEncryptor;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Appels multi-tenant à l’API Search Console (requêtes / mots-clés).
 */
final class GoogleSearchConsoleService
{
    private const CACHE_VERSION = 1;

    public function __construct(
        private readonly OrganizationIntegrationRepository $integrationRepository,
        private readonly SensitiveStringEncryptor $encryptor,
        private readonly HttpClientInterface $httpClient,
        private readonly GoogleOAuthService $googleOAuth,
        private readonly EntityManagerInterface $entityManager,
        #[Autowire(service: 'cache.gsc_queries')]
        private readonly CacheItemPoolInterface $gscCache,
        #[Autowire(service: 'limiter.gsc_organization')]
        private readonly RateLimiterFactory $gscLimiter,
    ) {
    }

    /**
     * Top requêtes sur la propriété GSC de l’organisation (28 derniers jours).
     * $pageUrlFilter : URL complète de page pour filtrer (optionnel).
     *
     * @return array{keywords: list<array{query: string, clicks: int, impressions: int, ctr: float, position: float}>}
     */
    public function getTopQueries(WebhookProject $project, string $pageUrlFilter = ''): array
    {
        $integration = $this->integrationRepository->findGscForProject($project);
        if (!$integration instanceof OrganizationIntegration) {
            throw new \RuntimeException('Google Search Console n’est pas connecté pour ce projet.');
        }
        $siteUrl = $integration->getSiteUrl();
        if ($siteUrl === null || $siteUrl === '') {
            throw new \RuntimeException('Sélectionnez une propriété Search Console (site) pour cette organisation.');
        }

        $orgId = (int) ($project->getOrganization()?->getId() ?? 0);
        $limiter = $this->gscLimiter->create('org_'.$orgId);
        if (!$limiter->consume()->isAccepted()) {
            throw new \RuntimeException('Quota d’appels Search Console atteint pour cette organisation. Réessayez plus tard.');
        }

        $cacheKey = $this->cacheKey((int) $project->getId(), $siteUrl, $pageUrlFilter);
        $item = $this->gscCache->getItem($cacheKey);
        if ($item->isHit()) {
            /** @var array{keywords: list<array<string, mixed>>} $cached */
            $cached = $item->get();

            return $cached;
        }

        $accessToken = $this->getValidAccessToken($integration);
        $rows = $this->fetchSearchAnalyticsRows($accessToken, $siteUrl, $pageUrlFilter);
        $keywords = [];
        foreach ($rows as $r) {
            $keys = $r['keys'] ?? [];
            $q = \is_array($keys) && isset($keys[0]) ? (string) $keys[0] : '';
            $keywords[] = [
                'query' => $q,
                'clicks' => (int) ($r['clicks'] ?? 0),
                'impressions' => (int) ($r['impressions'] ?? 0),
                'ctr' => (float) ($r['ctr'] ?? 0.0),
                'position' => (float) ($r['position'] ?? 0.0),
            ];
        }
        $out = ['keywords' => $keywords];
        $item->set($out);
        $item->expiresAfter(86400);
        $this->gscCache->save($item);

        return $out;
    }

    private function cacheKey(int $projectId, string $siteUrl, string $pageUrl): string
    {
        return 'gsc_v'.self::CACHE_VERSION.'_p'.$projectId.'_'.hash('sha256', $siteUrl."\n".$pageUrl);
    }

    private function getValidAccessToken(OrganizationIntegration $integration): string
    {
        $exp = $integration->getExpiresAt();
        $refreshPlain = $this->encryptor->decrypt($integration->getRefreshTokenCipher());
        if ($exp !== null && $exp->getTimestamp() > time() + 90) {
            return $this->encryptor->decrypt($integration->getAccessTokenCipher());
        }
        $project = $integration->getProject();
        if (!$project instanceof WebhookProject) {
            throw new \RuntimeException('Intégration GSC sans projet associé.');
        }
        if (!$this->googleOAuth->isConfiguredForProject($project)) {
            throw new \RuntimeException(
                'Identifiants OAuth Google manquants pour ce projet. Renseignez le Client ID et le secret dans la fiche du projet.',
            );
        }
        $tokens = $this->googleOAuth->refreshAccessToken($project, $refreshPlain);
        $access = (string) ($tokens['access_token'] ?? '');
        if ($access === '') {
            throw new \RuntimeException('Rafraîchissement du jeton Google invalide.');
        }
        $integration->setAccessTokenCipher($this->encryptor->encrypt($access));
        $ttl = (int) ($tokens['expires_in'] ?? 3600);
        $integration->setExpiresAt(new \DateTimeImmutable('+'.$ttl.' seconds'));
        if (isset($tokens['scope']) && \is_string($tokens['scope'])) {
            $integration->setScope($tokens['scope']);
        }
        $this->entityManager->flush();

        return $access;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listSitesForProject(WebhookProject $project): array
    {
        $integration = $this->integrationRepository->findGscForProject($project);
        if (!$integration instanceof OrganizationIntegration) {
            return [];
        }
        $access = $this->getValidAccessToken($integration);
        $resp = $this->httpClient->request('GET', 'https://www.googleapis.com/webmasters/v3/sites', [
            'headers' => ['Authorization' => 'Bearer '.$access],
        ]);
        if ($resp->getStatusCode() >= 400) {
            return [];
        }
        $json = json_decode($resp->getContent(false), true);
        if (!\is_array($json) || !isset($json['siteEntry']) || !\is_array($json['siteEntry'])) {
            return [];
        }
        $out = [];
        foreach ($json['siteEntry'] as $e) {
            if (!\is_array($e)) {
                continue;
            }
            $out[] = [
                'siteUrl' => (string) ($e['siteUrl'] ?? ''),
                'permissionLevel' => (string) ($e['permissionLevel'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchSearchAnalyticsRows(string $accessToken, string $siteUrl, string $pageUrlFilter): array
    {
        $end = new \DateTimeImmutable('yesterday');
        $start = $end->modify('-27 days');
        $body = [
            'startDate' => $start->format('Y-m-d'),
            'endDate' => $end->format('Y-m-d'),
            'dimensions' => ['query'],
            'rowLimit' => 100,
        ];
        if ($pageUrlFilter !== '') {
            $body['dimensionFilterGroups'] = [[
                'filters' => [[
                    'dimension' => 'page',
                    'operator' => 'equals',
                    'expression' => $pageUrlFilter,
                ]],
            ]];
        }
        $encSite = rawurlencode($siteUrl);
        $url = 'https://searchconsole.googleapis.com/webmasters/v3/sites/'.$encSite.'/searchAnalytics/query';
        $resp = $this->httpClient->request('POST', $url, [
            'headers' => [
                'Authorization' => 'Bearer '.$accessToken,
                'Content-Type' => 'application/json',
            ],
            'json' => $body,
        ]);
        if ($resp->getStatusCode() >= 400) {
            throw new \RuntimeException('Erreur API Search Console (HTTP '.$resp->getStatusCode().').');
        }
        $json = json_decode($resp->getContent(false), true);
        if (!\is_array($json)) {
            return [];
        }
        $rows = $json['rows'] ?? [];

        return \is_array($rows) ? $rows : [];
    }
}
