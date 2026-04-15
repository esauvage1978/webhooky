<?php

declare(strict_types=1);

namespace App\Service\AI;

/**
 * Prompts versionnés pour les agents SEO.
 */
final class SeoPromptRegistry
{
    public const SEO_CORRECTOR_V3 = 'seo_corrector_v3';

    public const IMPROVEMENT_FOLLOWUP = 'seo_improvement_followup';

    public function buildPrompt(string $promptId, array $vars): string
    {
        return match ($promptId) {
            self::SEO_CORRECTOR_V3 => $this->seoCorrectorV3($vars),
            self::IMPROVEMENT_FOLLOWUP => $this->improvementFollowup($vars),
            default => throw new \InvalidArgumentException('Prompt inconnu : '.$promptId),
        };
    }

    /**
     * @param array<string, string> $vars
     */
    private function seoCorrectorV3(array $vars): string
    {
        $keyword = $vars['keyword'] ?? '';
        $gsc = $vars['gsc_keywords'] ?? '';
        $content = $vars['content'] ?? '';

        return <<<PROMPT
Tu es un expert SEO.

Mot clé principal : {$keyword}

Données Google Search Console :
{$gsc}

Instructions :
- intégrer les requêtes réelles
- améliorer le CTR
- enrichir le champ lexical
- corriger la structure

Retour JSON strict (sans markdown, sans texte hors JSON) :
{
  "optimized_article": "",
  "seo_score": 0,
  "improvements": [],
  "keywords_used": [],
  "meta": {
    "title": "",
    "description": ""
  }
}

Article :
{$content}
PROMPT;
    }

    /**
     * @param array<string, string> $vars
     */
    private function improvementFollowup(array $vars): string
    {
        $prev = $vars['previous_json'] ?? '';

        return <<<PROMPT
Tu es un expert SEO. Le score SEO précédent est insuffisant. Améliore encore le contenu.

Contexte JSON précédent :
{$prev}

Retourne uniquement un JSON valide au même schéma :
{
  "optimized_article": "",
  "seo_score": 0,
  "improvements": [],
  "keywords_used": [],
  "meta": { "title": "", "description": "" }
}
PROMPT;
    }
}
