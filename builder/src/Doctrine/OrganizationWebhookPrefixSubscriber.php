<?php

declare(strict_types=1);

namespace App\Doctrine;

use App\Entity\Organization;
use App\Repository\OrganizationRepository;
use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Events;

/**
 * Attribue un préfixe webhook unique à chaque nouvelle organisation.
 */
final class OrganizationWebhookPrefixSubscriber implements EventSubscriber
{
    public function __construct(
        private readonly OrganizationRepository $organizationRepository,
    ) {
    }

    public function getSubscribedEvents(): array
    {
        return [Events::prePersist];
    }

    public function prePersist(PrePersistEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof Organization) {
            return;
        }
        if ($entity->getWebhookPublicPrefix() !== '') {
            return;
        }
        $entity->setWebhookPublicPrefix($this->organizationRepository->allocateUniqueWebhookPublicPrefix());
    }
}
