<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Organization;
use App\Entity\User;
use App\Onboarding\ProfileAvatarCatalog;
use App\Onboarding\UserOnboardingEvaluator;
use App\Subscription\SubscriptionPlan;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/onboarding')]
#[IsGranted('ROLE_USER')]
final class ApiOnboardingController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserOnboardingEvaluator $onboardingEvaluator,
        private readonly ValidatorInterface $validator,
    ) {
    }

    #[Route('/profile', name: 'api_onboarding_profile', methods: ['POST'])]
    public function saveProfile(Request $request): JsonResponse
    {
        $user = $this->requireUser();
        $steps = $this->onboardingEvaluator->pendingSteps($user);
        if (!\in_array('profile', $steps, true)) {
            return new JsonResponse(['error' => 'Cette étape n’est pas requise pour votre compte.'], Response::HTTP_CONFLICT);
        }

        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            return new JsonResponse(['error' => 'JSON invalide'], Response::HTTP_BAD_REQUEST);
        }

        $displayName = isset($data['displayName']) ? trim((string) $data['displayName']) : '';
        $avatarKey = isset($data['avatarKey']) ? trim((string) $data['avatarKey']) : '';

        $fields = [];
        foreach ($this->validator->validate($displayName, [
            new Assert\NotBlank(message: 'Nom d’affichage requis'),
            new Assert\Length(max: 120, maxMessage: 'Trop long'),
        ]) as $v) {
            $fields['displayName'] = $v->getMessage();
            break;
        }
        if ($avatarKey === '' || !ProfileAvatarCatalog::isAllowed($avatarKey)) {
            $fields['avatarKey'] = 'Choisissez un avatar dans la liste proposée.';
        }
        if ($fields !== []) {
            return new JsonResponse(['error' => 'Validation', 'fields' => $fields], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $user->setDisplayName($displayName);
        $user->setAvatarKey($avatarKey);
        $user->setProfileCompletedAt(new \DateTimeImmutable());
        $this->entityManager->flush();

        return new JsonResponse(['ok' => true]);
    }

    #[Route('/plan', name: 'api_onboarding_plan', methods: ['POST'])]
    public function choosePlan(Request $request): JsonResponse
    {
        $user = $this->requireUser();
        if (!$user->isAppManager()) {
            return new JsonResponse(['error' => 'Réservé aux gestionnaires.'], Response::HTTP_FORBIDDEN);
        }

        $steps = $this->onboardingEvaluator->pendingSteps($user);
        if (!\in_array('plan', $steps, true)) {
            return new JsonResponse(['error' => 'Cette étape n’est pas requise pour votre compte.'], Response::HTTP_CONFLICT);
        }

        $org = $user->getOrganization();
        if (!$org instanceof Organization) {
            return new JsonResponse(['error' => 'Organisation active requise.'], Response::HTTP_BAD_REQUEST);
        }
        if (!$user->hasMembershipInOrganization($org)) {
            return new JsonResponse(['error' => 'Organisation invalide.'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            return new JsonResponse(['error' => 'JSON invalide'], Response::HTTP_BAD_REQUEST);
        }

        $planRaw = isset($data['plan']) ? (string) $data['plan'] : '';
        $plan = SubscriptionPlan::tryFrom($planRaw);
        if ($plan === null) {
            return new JsonResponse(['error' => 'Forfait inconnu (free, starter, pro).'], Response::HTTP_BAD_REQUEST);
        }

        if ($plan === SubscriptionPlan::Free) {
            $org->applyFreePlan();
        } else {
            $org->applyPaidPlan($plan, null);
        }

        $user->setPlanOnboardingCompleted(true);
        $this->entityManager->flush();

        return new JsonResponse(['ok' => true]);
    }

    private function requireUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException();
        }

        return $user;
    }
}
