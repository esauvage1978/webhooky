<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ApplicationErrorLogRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Erreurs et exceptions tracées pour supervision administrateur.
 */
#[ORM\Entity(repositoryClass: ApplicationErrorLogRepository::class)]
#[ORM\Table(name: 'application_error_log')]
#[ORM\Index(name: 'IDX_app_err_created', columns: ['created_at'])]
#[ORM\Index(name: 'IDX_app_err_level_created', columns: ['level', 'created_at'])]
class ApplicationErrorLog
{
    public const LEVEL_ERROR = 'error';

    public const LEVEL_WARNING = 'warning';

    public const LEVEL_CRITICAL = 'critical';

    public const SOURCE_EXCEPTION = 'exception';

    /** Exception attrapée dans le code (try/catch) sans remonter au noyau. */
    public const SOURCE_HANDLED = 'handled';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(length: 16)]
    private string $level = self::LEVEL_ERROR;

    #[ORM\Column(length: 16)]
    private string $source = self::SOURCE_EXCEPTION;

    /** Résumé court (sujet affiché en liste). */
    #[ORM\Column(length: 512)]
    private string $message = '';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $exceptionClass = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $exceptionCode = null;

    /** Chaîne d’exceptions, messages fichier/ligne. */
    #[ORM\Column(type: Types::TEXT)]
    private string $detail = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $trace = null;

    #[ORM\Column(length: 512, nullable: true)]
    private ?string $file = null;

    #[ORM\Column(nullable: true)]
    private ?int $line = null;

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $httpMethod = null;

    #[ORM\Column(length: 2048, nullable: true)]
    private ?string $requestUri = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $user = null;

    #[ORM\ManyToOne(targetEntity: Organization::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Organization $organization = null;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $clientIp = null;

    #[ORM\Column(length: 512, nullable: true)]
    private ?string $userAgent = null;

    /**
     * Contexte JSON (route, extra, code HTTP, etc.).
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $context = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getLevel(): string
    {
        return $this->level;
    }

    public function setLevel(string $level): static
    {
        $this->level = $level;

        return $this;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function setSource(string $source): static
    {
        $this->source = $source;

        return $this;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function setMessage(string $message): static
    {
        $this->message = $message;

        return $this;
    }

    public function getExceptionClass(): ?string
    {
        return $this->exceptionClass;
    }

    public function setExceptionClass(?string $exceptionClass): static
    {
        $this->exceptionClass = $exceptionClass;

        return $this;
    }

    public function getExceptionCode(): ?string
    {
        return $this->exceptionCode;
    }

    public function setExceptionCode(?string $exceptionCode): static
    {
        $this->exceptionCode = $exceptionCode;

        return $this;
    }

    public function getDetail(): string
    {
        return $this->detail;
    }

    public function setDetail(string $detail): static
    {
        $this->detail = $detail;

        return $this;
    }

    public function getTrace(): ?string
    {
        return $this->trace;
    }

    public function setTrace(?string $trace): static
    {
        $this->trace = $trace;

        return $this;
    }

    public function getFile(): ?string
    {
        return $this->file;
    }

    public function setFile(?string $file): static
    {
        $this->file = $file;

        return $this;
    }

    public function getLine(): ?int
    {
        return $this->line;
    }

    public function setLine(?int $line): static
    {
        $this->line = $line;

        return $this;
    }

    public function getHttpMethod(): ?string
    {
        return $this->httpMethod;
    }

    public function setHttpMethod(?string $httpMethod): static
    {
        $this->httpMethod = $httpMethod;

        return $this;
    }

    public function getRequestUri(): ?string
    {
        return $this->requestUri;
    }

    public function setRequestUri(?string $requestUri): static
    {
        $this->requestUri = $requestUri;

        return $this;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getOrganization(): ?Organization
    {
        return $this->organization;
    }

    public function setOrganization(?Organization $organization): static
    {
        $this->organization = $organization;

        return $this;
    }

    public function getClientIp(): ?string
    {
        return $this->clientIp;
    }

    public function setClientIp(?string $clientIp): static
    {
        $this->clientIp = $clientIp;

        return $this;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function setUserAgent(?string $userAgent): static
    {
        $this->userAgent = $userAgent;

        return $this;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getContext(): ?array
    {
        return $this->context;
    }

    /**
     * @param array<string, mixed>|null $context
     */
    public function setContext(?array $context): static
    {
        $this->context = $context;

        return $this;
    }
}
