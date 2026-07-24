<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\RetryFormWebhookActionMessage;
use App\Monitoring\FormWebhookRetryService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class RetryFormWebhookActionHandler
{
    public function __construct(
        private readonly FormWebhookRetryService $retryService,
    ) {
    }

    public function __invoke(RetryFormWebhookActionMessage $message): void
    {
        $this->retryService->executeRetry($message->actionLogId, $message->attempt);
    }
}
