<?php

declare(strict_types=1);

namespace App\Service\Audit;

use App\Entity\Organization;
use App\Entity\ResourceAuditLog;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

final class ResourceAuditLogger
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param array<string, mixed>|null $details
     */
    public function persist(
        Request $request,
        User $actor,
        string $resourceType,
        string $action,
        int $resourceId,
        ?Organization $organization,
        ?array $details = null,
    ): void {
        $log = new ResourceAuditLog();
        $log->setResourceType($resourceType);
        $log->setAction($action);
        $log->setResourceId($resourceId);
        $log->setOrganization($organization);
        $log->setActorUser($actor);
        $log->setDetails($details);
        $log->setClientIp($request->getClientIp());

        $this->entityManager->persist($log);
    }
}
