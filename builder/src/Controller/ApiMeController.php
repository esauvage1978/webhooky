<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Subscription\SubscriptionEntitlementService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class ApiMeController extends AbstractController
{
    public function __construct(
        private readonly SubscriptionEntitlementService $subscriptionEntitlement,
    ) {
    }

    #[Route('/api/me', name: 'api_me', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function __invoke(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException();
        }

        $org = $user->getOrganization();

        $row = [
            'email' => $user->getUserIdentifier(),
            'roles' => $user->getRoles(),
            'accountEnabled' => $user->isAccountEnabled(),
            'invitePending' => $user->hasPendingInvite(),
            'organization' => $org !== null
                ? ['id' => $org->getId(), 'name' => $org->getName()]
                : null,
        ];

        if ($org !== null) {
            $row['subscription'] = $this->subscriptionEntitlement->buildSnapshot($org);
        }

        return new JsonResponse($row);
    }
}
