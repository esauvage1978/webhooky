<?php

declare(strict_types=1);

namespace App\Service\SEO;

use App\Repository\OptionRepository;
use App\Security\SensitiveStringEncryptor;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * OAuth2 Google (Search Console en lecture seule).
 */
final class GoogleOAuthService
{
    public const SCOPE_READONLY = 'https://www.googleapis.com/auth/webmasters.readonly';

    private const OPT_CLIENT_ID = 'google_oauth_client_id';

    private const OPT_CLIENT_SECRET_CIPHER = 'google_oauth_client_secret_cipher';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly OptionRepository $optionRepository,
        private readonly SensitiveStringEncryptor $encryptor,
    ) {
    }

    public function isConfigured(): bool
    {
        [$id, $secret] = $this->resolveClientCredentials();

        return $id !== '' && $secret !== '';
    }

    public function buildAuthorizationUrl(string $redirectUri, string $state): string
    {
        [$clientId] = $this->resolveClientCredentials();
        $q = http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => self::SCOPE_READONLY,
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);

        return 'https://accounts.google.com/o/oauth2/v2/auth?'.$q;
    }

    /**
     * @return array{access_token: string, refresh_token?: string, expires_in: int, scope?: string}
     */
    public function exchangeAuthorizationCode(string $code, string $redirectUri): array
    {
        [$clientId, $clientSecret] = $this->resolveClientCredentials(true);
        return $this->postToken([
            'code' => $code,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri' => $redirectUri,
            'grant_type' => 'authorization_code',
        ]);
    }

    /**
     * @return array{access_token: string, expires_in: int, scope?: string}
     */
    public function refreshAccessToken(string $refreshToken): array
    {
        [$clientId, $clientSecret] = $this->resolveClientCredentials(true);
        return $this->postToken([
            'refresh_token' => $refreshToken,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'grant_type' => 'refresh_token',
        ]);
    }

    /**
     * @return array{0: string, 1: string} clientId, clientSecret
     */
    private function resolveClientCredentials(bool $requireConfigured = false): array
    {
        $id = trim((string) ($this->optionRepository->findFirstByOptionName(self::OPT_CLIENT_ID)?->getOptionValue() ?? ''));
        $cipher = trim((string) ($this->optionRepository->findFirstByOptionName(self::OPT_CLIENT_SECRET_CIPHER)?->getOptionValue() ?? ''));
        $secret = '';
        if ($cipher !== '') {
            try {
                $secret = $this->encryptor->decrypt($cipher);
            } catch (\Throwable) {
                $secret = '';
            }
        }
        if ($requireConfigured && ($id === '' || $secret === '')) {
            throw new \RuntimeException('OAuth Google non configuré (options plateforme).');
        }

        return [$id, $secret];
    }

    /**
     * @param array<string, string> $fields
     *
     * @return array<string, mixed>
     */
    private function postToken(array $fields): array
    {
        $resp = $this->httpClient->request('POST', 'https://oauth2.googleapis.com/token', [
            'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
            'body' => $fields,
        ]);
        $status = $resp->getStatusCode();
        $json = json_decode($resp->getContent(false), true);
        if ($status >= 400 || !\is_array($json)) {
            $hint = \is_array($json) && isset($json['error_description']) ? (string) $json['error_description'] : 'Réponse OAuth invalide.';

            throw new \RuntimeException('Échec OAuth Google ('.$status.') : '.$hint);
        }

        return $json;
    }
}
