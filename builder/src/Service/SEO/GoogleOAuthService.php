<?php

declare(strict_types=1);

namespace App\Service\SEO;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * OAuth2 Google (Search Console en lecture seule).
 */
final class GoogleOAuthService
{
    public const SCOPE_READONLY = 'https://www.googleapis.com/auth/webmasters.readonly';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $clientId,
        private readonly string $clientSecret,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->clientId !== '' && $this->clientSecret !== '';
    }

    public function buildAuthorizationUrl(string $redirectUri, string $state): string
    {
        $q = http_build_query([
            'client_id' => $this->clientId,
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
        return $this->postToken([
            'code' => $code,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'redirect_uri' => $redirectUri,
            'grant_type' => 'authorization_code',
        ]);
    }

    /**
     * @return array{access_token: string, expires_in: int, scope?: string}
     */
    public function refreshAccessToken(string $refreshToken): array
    {
        return $this->postToken([
            'refresh_token' => $refreshToken,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'grant_type' => 'refresh_token',
        ]);
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
