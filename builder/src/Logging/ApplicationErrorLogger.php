<?php

declare(strict_types=1);

namespace App\Logging;

use App\Entity\ApplicationErrorLog;
use App\Entity\Organization;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Persistance sécurisée des erreurs (ne relance jamais d’exception).
 */
final class ApplicationErrorLogger
{
    private const TRACE_MAX_LEN = 62000;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        private readonly Security $security,
    ) {
    }

    /**
     * @param array<string, mixed> $context Enrichissement (_route, handler, etc.)
     */
    public function logThrowable(
        \Throwable $throwable,
        ?Request $request = null,
        string $source = ApplicationErrorLog::SOURCE_EXCEPTION,
        array $context = [],
    ): void {
        try {
            $row = $this->buildLog($throwable, $request, $source, $context);
            $this->entityManager->persist($row);
            $this->entityManager->flush();
        } catch (\Throwable $e) {
            $this->logger->critical('Échec de la persistance ApplicationErrorLog : '.$e->getMessage(), [
                'exception' => $e,
                'original' => $throwable::class.': '.$throwable->getMessage(),
            ]);
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    public function logMessage(
        string $level,
        string $message,
        ?Request $request = null,
        ?User $user = null,
        ?Organization $organization = null,
        array $context = [],
    ): void {
        try {
            $row = new ApplicationErrorLog();
            $row->setLevel($level);
            $row->setSource(ApplicationErrorLog::SOURCE_HANDLED);
            $row->setMessage(mb_substr($message, 0, 512));
            $row->setDetail($message);
            $row->setUser($user);
            $row->setOrganization($organization);
            if ($request instanceof Request) {
                $this->fillRequest($row, $request);
            }
            if ($user instanceof User) {
                $row->setUser($user);
                $row->setOrganization($organization ?? $user->getOrganization());
            } else {
                $this->attachCurrentUser($row);
            }
            if ($context !== []) {
                $row->setContext($context);
            }
            $this->entityManager->persist($row);
            $this->entityManager->flush();
        } catch (\Throwable $e) {
            $this->logger->critical('Échec logMessage ApplicationErrorLog : '.$e->getMessage(), ['exception' => $e]);
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private function buildLog(
        \Throwable $throwable,
        ?Request $request,
        string $source,
        array $context,
    ): ApplicationErrorLog {
        $row = new ApplicationErrorLog();
        $row->setSource($source);
        $row->setLevel($this->resolveLevel($throwable));
        $row->setExceptionClass($throwable::class);
        $code = $throwable->getCode();
        $row->setExceptionCode(\is_int($code) || \is_string($code) ? (string) $code : null);
        $row->setMessage(mb_substr($throwable->getMessage(), 0, 512));
        $row->setDetail($this->formatThrowableChain($throwable));
        $row->setFile($throwable->getFile() !== '' ? $this->truncate($throwable->getFile(), 512) : null);
        $line = $throwable->getLine();
        $row->setLine($line > 0 ? $line : null);

        $trace = $throwable->getTraceAsString();
        if ($trace !== '') {
            $row->setTrace($this->truncate($trace, self::TRACE_MAX_LEN));
        }

        if ($request instanceof Request) {
            $this->fillRequest($row, $request);
        }
        $this->attachCurrentUser($row);

        $mergedContext = $context;
        if ($request instanceof Request && $request->attributes->has('_route')) {
            $mergedContext['_route'] = $request->attributes->get('_route');
        }
        if ($mergedContext !== []) {
            $row->setContext($mergedContext);
        }

        return $row;
    }

    private function fillRequest(ApplicationErrorLog $row, Request $request): void
    {
        $row->setHttpMethod($request->getMethod());
        $uri = $request->getRequestUri();
        $row->setRequestUri($uri !== '' ? $this->truncate($uri, 2048) : null);
        $row->setClientIp($request->getClientIp());
        $ua = (string) $request->headers->get('User-Agent', '');
        $row->setUserAgent($ua !== '' ? mb_substr($ua, 0, 512) : null);
    }

    private function attachCurrentUser(ApplicationErrorLog $row): void
    {
        $u = $this->security->getUser();
        if ($u instanceof User) {
            $row->setUser($u);
            $row->setOrganization($u->getOrganization());
        }
    }

    private function resolveLevel(\Throwable $throwable): string
    {
        if ($throwable instanceof HttpExceptionInterface) {
            $code = $throwable->getStatusCode();
            if ($code >= 500) {
                return ApplicationErrorLog::LEVEL_CRITICAL;
            }
            if ($code >= 400) {
                return ApplicationErrorLog::LEVEL_WARNING;
            }
        }

        return ApplicationErrorLog::LEVEL_ERROR;
    }

    private function formatThrowableChain(\Throwable $throwable): string
    {
        $parts = [];
        $current = $throwable;
        $depth = 0;
        while ($current instanceof \Throwable && $depth < 12) {
            $parts[] = \sprintf(
                "[%s] %s\nFichier : %s:%d\nCode : %s",
                $current::class,
                $current->getMessage(),
                $current->getFile(),
                $current->getLine(),
                \is_scalar($current->getCode()) ? (string) $current->getCode() : '',
            );
            $current = $current->getPrevious();
            ++$depth;
        }

        return implode("\n\n——— Cause précédente ———\n\n", $parts);
    }

    private function truncate(string $s, int $max): string
    {
        if (mb_strlen($s) <= $max) {
            return $s;
        }

        return mb_substr($s, 0, $max - 12)."\n… [tronqué]";
    }
}
