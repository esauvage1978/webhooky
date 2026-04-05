<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AppController extends AbstractController
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    #[Route('/', name: 'app_spa_root', methods: ['GET'], priority: -1000)]
    public function root(): Response
    {
        return $this->spaResponse();
    }

    #[Route(
        '/{path}',
        name: 'app_spa',
        methods: ['GET'],
        requirements: ['path' => '^(?!(api|webhook|build|_profiler|_wdt)(/|$)).+'],
        priority: -1000,
    )]
    public function spa(): Response
    {
        return $this->spaResponse();
    }

    private function spaResponse(): Response
    {
        $viteDev = filter_var($_ENV['VITE_DEV'] ?? getenv('VITE_DEV') ?: '', FILTER_VALIDATE_BOOL);
        $assets = $this->resolveFrontendAssets($viteDev);

        return $this->render('app.html.twig', $assets + ['vite_dev' => $viteDev]);
    }

    /**
     * @return array{entryScript: string|null, entryCss: string|null, viteDevHost: string}
     */
    private function resolveFrontendAssets(bool $viteDev): array
    {
        $host = $_ENV['VITE_DEV_HOST'] ?? getenv('VITE_DEV_HOST') ?: 'http://127.0.0.1:5173';
        $host = is_string($host) ? $host : 'http://127.0.0.1:5173';

        if ($viteDev) {
            return [
                'entryScript' => $host.'/main.jsx',
                'entryCss' => null,
                'viteDevHost' => $host,
            ];
        }

        $baseDir = $this->projectDir.'/public/build';
        $manifestPath = $baseDir.'/.vite/manifest.json';
        if (!is_file($manifestPath)) {
            $manifestPath = $baseDir.'/manifest.json';
        }

        if (!is_file($manifestPath)) {
            return [
                'entryScript' => null,
                'entryCss' => null,
                'viteDevHost' => $host,
            ];
        }

        /** @var array<string, array{file?: string, isEntry?: bool, css?: list<string>}> $manifest */
        $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        $entry = null;
        foreach ($manifest as $key => $chunk) {
            if (!empty($chunk['isEntry']) && str_ends_with($key, 'main.jsx')) {
                $entry = $chunk;
                break;
            }
        }
        $entry ??= $manifest['main.jsx'] ?? null;

        if ($entry === null) {
            return [
                'entryScript' => null,
                'entryCss' => null,
                'viteDevHost' => $host,
            ];
        }

        $js = isset($entry['file']) ? '/build/'.$entry['file'] : null;
        $css = isset($entry['css'][0]) ? '/build/'.$entry['css'][0] : null;

        return [
            'entryScript' => $js,
            'entryCss' => $css,
            'viteDevHost' => $host,
        ];
    }
}
