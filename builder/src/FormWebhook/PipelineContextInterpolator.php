<?php

declare(strict_types=1);

namespace App\FormWebhook;

/**
 * Interpolation {{clé}} : variables explicites, payload aplati, puis données du pipeline (préfixe data.).
 */
final class PipelineContextInterpolator
{
    public function __construct(
        private readonly IntegrationPayloadInterpolator $baseInterpolator,
    ) {
    }

    /**
     * @param array<string, string>        $parsed   payload ingress aplati
     * @param array<string, mixed>        $data     accumulateur pipeline
     * @param array<string, string>       $variables mapping explicite (ex. variableMapping)
     */
    public function interpolateTemplate(string $template, array $parsed, array $data, array $variables = []): string
    {
        $flat = $this->mergeFlat($parsed, $data);

        return $this->baseInterpolator->interpolate($template, $variables, $flat);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, string>
     */
    public function mergeFlat(array $parsed, array $data): array
    {
        $out = $parsed;
        foreach ($this->flattenMixed($data, 'data') as $k => $v) {
            $out[$k] = $v;
        }

        return $out;
    }

    /**
     * @param array<mixed> $node
     *
     * @return array<string, string>
     */
    private function flattenMixed(array $node, string $prefix): array
    {
        $out = [];
        foreach ($node as $key => $value) {
            $path = $prefix.'.'.$key;
            if (\is_array($value) && $this->isAssoc($value)) {
                foreach ($this->flattenMixed($value, $path) as $sk => $sv) {
                    $out[$sk] = $sv;
                }
            } elseif (\is_array($value)) {
                $out[$path] = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            } elseif (\is_scalar($value) || $value === null) {
                $out[$path] = $value === null ? '' : (string) $value;
            } else {
                $out[$path] = '';
            }
        }

        return $out;
    }

    /**
     * @param array<mixed> $arr
     */
    private function isAssoc(array $arr): bool
    {
        if ($arr === []) {
            return true;
        }

        return array_keys($arr) !== range(0, \count($arr) - 1);
    }
}
