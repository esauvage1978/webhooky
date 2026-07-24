<?php

declare(strict_types=1);

namespace App\Monitoring;

final class MonitoringMetricKeys
{
    public const WEBHOOK_RECEIVED = 'webhook.received.count';
    public const WEBHOOK_RUN_SUCCESS = 'webhook.run.success.count';
    public const WEBHOOK_RUN_ERROR = 'webhook.run.error.count';
    public const WEBHOOK_RUN_SKIPPED = 'webhook.run.skipped.count';
    public const WEBHOOK_DURATION_MS = 'webhook.processing.duration_ms';
    public const WEBHOOK_ACTION_SUCCESS = 'webhook.action.success.count';
    public const WEBHOOK_ACTION_ERROR = 'webhook.action.error.count';
    public const WEBHOOK_ACTION_DURATION_MS = 'webhook.action.duration_ms';
    public const WEBHOOK_RATE_LIMITED = 'webhook.rate_limited.count';
    public const WEBHOOK_RETRY_SCHEDULED = 'webhook.retry_scheduled.count';
    public const WEBHOOK_DEAD_LETTER = 'webhook.dead_letter.count';
    public const WEBHOOK_HTTP_STATUS = 'webhook.action.http_status';
}
