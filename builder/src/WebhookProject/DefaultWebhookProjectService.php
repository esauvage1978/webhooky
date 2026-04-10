<?php

declare(strict_types=1);

namespace App\WebhookProject;

use App\Entity\FormWebhook;
use App\Entity\Organization;
use App\Entity\WebhookProject;
use App\Repository\FormWebhookRepository;
use App\Repository\WebhookProjectRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Projet « Général » par organisation et rattachement des workflows sans projet.
 */
final class DefaultWebhookProjectService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly WebhookProjectRepository $webhookProjectRepository,
        private readonly FormWebhookRepository $formWebhookRepository,
    ) {
    }

    /**
     * Crée le projet par défaut s’il n’existe pas (persist uniquement ; flush à la charge de l’appelant si besoin).
     */
    public function ensureDefaultForOrganization(Organization $organization): WebhookProject
    {
        $existing = $this->webhookProjectRepository->findDefaultForOrganization($organization);
        if ($existing instanceof WebhookProject) {
            if (!$existing->isDefault() && $existing->getName() === WebhookProject::DEFAULT_NAME) {
                $existing->setIsDefault(true);
            }

            return $existing;
        }

        $p = new WebhookProject();
        $p->setOrganization($organization);
        $p->setName(WebhookProject::DEFAULT_NAME);
        $p->setDescription('Projet par défaut — regroupe les workflows sans autre classement.');
        $p->setIsDefault(true);
        $this->entityManager->persist($p);

        return $p;
    }

    /**
     * Idempotent : pour chaque organisation, assure le projet Général et rattache les webhooks sans projet.
     *
     * Corrige aussi les lignes dont {@see FormWebhook::$project} pointe vers un id absent de
     * {@see WebhookProject} (sinon `doctrine:schema:update` échoue sur la FK).
     *
     * @return array{organizations: int, defaultsCreated: int, webhooksAttached: int, danglingProjectIdsFixed: int}
     */
    public function ensureAllOrganizationsHaveDefaultAndAttachWebhooks(): array
    {
        $organizationsProcessed = 0;
        $defaultsCreated = 0;
        $webhooksAttached = 0;
        $danglingProjectIdsFixed = 0;

        /**
         * Ne pas utiliser OrganizationRepository::findAll() : en prod le schéma SQL peut être en retard sur les
         * propriétés de l’entité (ex. colonne subscription_exempt absente) et hydrater Organization échoue alors.
         * Les seuls identifiants suffisent pour les clés étrangères et les requêtes par organisation.
         */
        $conn = $this->entityManager->getConnection();
        $organizationIds = array_map('intval', $conn->fetchFirstColumn('SELECT id FROM organization ORDER BY id'));

        foreach ($organizationIds as $organizationId) {
            ++$organizationsProcessed;
            $organization = $this->entityManager->getReference(Organization::class, $organizationId);

            $had = $this->webhookProjectRepository->findDefaultForOrganization($organization);
            $def = $this->ensureDefaultForOrganization($organization);
            if ($had === null) {
                ++$defaultsCreated;
            }
            $this->entityManager->flush();

            $danglingProjectIdsFixed += $this->repairFormWebhooksDanglingProjectForOrganization($organizationId, $def);

            /** @var list<FormWebhook> $orphans */
            $orphans = $this->formWebhookRepository->createQueryBuilder('w')
                ->andWhere('w.organization = :o')
                ->andWhere('w.project IS NULL')
                ->setParameter('o', $organization)
                ->getQuery()
                ->getResult();

            foreach ($orphans as $w) {
                $w->setProject($def);
                ++$webhooksAttached;
            }
        }

        $this->entityManager->flush();

        return [
            'organizations' => $organizationsProcessed,
            'defaultsCreated' => $defaultsCreated,
            'webhooksAttached' => $webhooksAttached,
            'danglingProjectIdsFixed' => $danglingProjectIdsFixed,
        ];
    }

    /**
     * Réassigne au projet par défaut les workflows dont project_id est NULL ou ne référence aucune ligne webhook_project.
     */
    private function repairFormWebhooksDanglingProjectForOrganization(int $organizationId, WebhookProject $default): int
    {
        $defId = $default->getId();
        if ($defId === null) {
            return 0;
        }

        $conn = $this->entityManager->getConnection();

        return $conn->executeStatement(
            'UPDATE form_webhook fw
            LEFT JOIN webhook_project wp ON wp.id = fw.project_id
            SET fw.project_id = ?
            WHERE fw.organization_id = ? AND wp.id IS NULL',
            [$defId, $organizationId],
        );
    }
}
