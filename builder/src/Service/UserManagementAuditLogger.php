<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Organization;
use App\Entity\User;
use App\Entity\UserAccountAuditLog;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

final class UserManagementAuditLogger
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param array<string, mixed>|null $details
     */
    public function persist(
        string $action,
        ?User $actor,
        ?User $target,
        ?Organization $organization,
        Request $request,
        ?array $details = null,
    ): void {
        $log = new UserAccountAuditLog();
        $log->setAction($action);
        $log->setActorUser($actor);
        if ($target !== null) {
            $log->setTargetUser($target);
            $log->setTargetEmail($target->getEmail());
        }
        $log->setOrganization($organization);
        $log->setDetails($details);
        $log->setClientIp($request->getClientIp());

        $this->entityManager->persist($log);
    }
}
