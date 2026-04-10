<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\ApplicationErrorLog;
use App\Logging\ApplicationErrorLogger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Enregistre chaque exception non gérée pour la supervision administrateur.
 */
final class LogApplicationExceptionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly ApplicationErrorLogger $applicationErrorLogger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => ['onKernelException', -80]];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $path = $request->getPathInfo();

        if (str_starts_with($path, '/_')) {
            return;
        }

        if ($request->attributes->get('_application_error_logged')) {
            return;
        }
        $request->attributes->set('_application_error_logged', true);

        $this->applicationErrorLogger->logThrowable(
            $event->getThrowable(),
            $request,
            ApplicationErrorLog::SOURCE_EXCEPTION,
            ['pathInfo' => $path],
        );
    }
}
