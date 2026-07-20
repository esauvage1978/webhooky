<?php

declare(strict_types=1);

namespace App\Service\AI;

use App\Entity\Organization;
use App\Security\OutboundUrlGuard;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Appels LLM multi-tenant (configuration par organisation + repli global .env).
 */
final class AIActionService
{
    private const MAX_PROMPT_CHARS = 100000;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly SeoPromptRegistry $seoPromptRegistry,
        private readonly OutboundUrlGuard $outboundUrlGuard,
        private readonly string $defaultOllamaBaseUrl,
        private readonly string $defaultOllamaModel,
    ) {
    }

    /**
     * @param array<string, string> $templateVariables déjà interpolées (ex. keyword, content…)
     */
    public function runPrompt(Organization $organization, string $promptId, array $templateVariables): string
    {
        $prompt = $this->seoPromptRegistry->buildPrompt($promptId, $templateVariables);

        return $this->runRawPrompt($organization, $prompt);
    }

    public function runRawPrompt(Organization $organization, string $prompt): string
    {
        if (mb_strlen($prompt) > self::MAX_PROMPT_CHARS) {
            $prompt = mb_substr($prompt, 0, self::MAX_PROMPT_CHARS);
        }

        [$baseUrl, $model] = $this->resolveOllamaConfig($organization);

        return $this->callOllamaGenerate($baseUrl, $model, $prompt);
    }

    /**
     * @return array{0: string, 1: string} baseUrl, model
     */
    private function resolveOllamaConfig(Organization $organization): array
    {
        $cfg = $organization->getAiSettings() ?? [];
        $provider = isset($cfg['provider']) ? (string) $cfg['provider'] : 'ollama';
        if ($provider !== '' && $provider !== 'ollama') {
            throw new \RuntimeException('Provider IA non supporté pour le moment : '.$provider);
        }
        $fromOrg = isset($cfg['baseUrl']) && trim((string) $cfg['baseUrl']) !== '';
        $base = $fromOrg ? trim((string) $cfg['baseUrl']) : trim($this->defaultOllamaBaseUrl);
        if ($base === '') {
            $base = 'http://127.0.0.1:11434';
            $fromOrg = false;
        }
        // Config org : anti-SSRF strict. Défaut plateforme (.env) : réseaux privés autorisés (Ollama local).
        $this->outboundUrlGuard->assertSafe($base, allowPrivateNetworks: !$fromOrg, requireHttps: false);

        $model = isset($cfg['model']) ? trim((string) $cfg['model']) : '';
        if ($model === '') {
            $model = trim($this->defaultOllamaModel);
        }
        if ($model === '') {
            $model = 'mistral';
        }

        return [rtrim($base, '/'), $model];
    }

    private function callOllamaGenerate(string $baseUrl, string $model, string $prompt): string
    {
        $url = $baseUrl.'/api/generate';
        $resp = $this->httpClient->request('POST', $url, [
            'timeout' => 120,
            'json' => [
                'model' => $model,
                'prompt' => $prompt,
                'stream' => false,
            ],
        ]);
        if ($resp->getStatusCode() >= 400) {
            throw new \RuntimeException('Erreur du serveur IA (HTTP '.$resp->getStatusCode().').');
        }
        $json = json_decode($resp->getContent(false), true);
        if (!\is_array($json) || !isset($json['response'])) {
            throw new \RuntimeException('Réponse IA inattendue.');
        }

        return (string) $json['response'];
    }
}
