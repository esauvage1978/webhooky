<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\FormWebhookActionLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FormWebhookActionLog>
 */
class FormWebhookActionLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FormWebhookActionLog::class);
    }
}
