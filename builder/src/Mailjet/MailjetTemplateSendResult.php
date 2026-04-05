<?php

declare(strict_types=1);

namespace App\Mailjet;

/**
 * Résultat normalisé d’un envoi template (indépendant du client HTTP).
 */
final class MailjetTemplateSendResult
{
    public function __construct(
        private readonly bool $success,
        private readonly int $httpStatus,
        private readonly ?string $rawResponseBody,
        private readonly ?string $messageId,
        private readonly ?string $errorMessage,
    ) {
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    public function getRawResponseBody(): ?string
    {
        return $this->rawResponseBody;
    }

    public function getMessageId(): ?string
    {
        return $this->messageId;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }
}
