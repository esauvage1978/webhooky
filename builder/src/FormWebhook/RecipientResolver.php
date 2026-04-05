<?php

declare(strict_types=1);

namespace App\FormWebhook;

use App\Entity\FormWebhookAction;

final class RecipientResolver
{
    /**
     * @param array<string, string> $flatInput
     *
     * @return array{0: string, 1: ?string} [email, name]
     */
    public function resolve(FormWebhookAction $action, array $flatInput): array
    {
        $emailKey = $action->getRecipientEmailPostKey();
        $email = null;
        if ($emailKey !== null && $emailKey !== '') {
            $email = $flatInput[$emailKey] ?? null;
        }
        if ($email === null || $email === '') {
            $email = $action->getDefaultRecipientEmail();
        }
        $email = $email !== null ? trim($email) : '';

        $name = null;
        $nameKey = $action->getRecipientNamePostKey();
        if ($nameKey !== null && $nameKey !== '') {
            $name = $flatInput[$nameKey] ?? null;
            $name = $name !== null && $name !== '' ? trim($name) : null;
        }

        return [$email, $name];
    }
}
