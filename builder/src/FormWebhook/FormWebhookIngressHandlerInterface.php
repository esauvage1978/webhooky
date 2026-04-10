<?php

declare(strict_types=1);

namespace App\FormWebhook;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

interface FormWebhookIngressHandlerInterface
{
    public function handle(Request $request, string $publicToken): JsonResponse;
}
