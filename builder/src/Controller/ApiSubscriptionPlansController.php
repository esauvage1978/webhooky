<?php

declare(strict_types=1);

namespace App\Controller;

use App\Subscription\SubscriptionPlanCatalog;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/subscription')]
final class ApiSubscriptionPlansController extends AbstractController
{
    #[Route('/plans', name: 'api_subscription_plans', methods: ['GET'])]
    public function plans(): JsonResponse
    {
        return new JsonResponse([
            'plans' => SubscriptionPlanCatalog::allPlans(),
            'eventPacks' => SubscriptionPlanCatalog::eventPacks(),
            'currency' => 'EUR',
            'pricingNote' => SubscriptionPlanCatalog::PRICING_NOTE_FR,
        ]);
    }
}
