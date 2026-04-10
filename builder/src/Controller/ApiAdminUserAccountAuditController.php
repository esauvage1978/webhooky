<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Organization;
use App\Entity\User;
use App\Entity\UserAccountAuditLog;
use App\Repository\UserAccountAuditLogRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin/user-account-audit-logs')]
#[IsGranted('ROLE_ADMIN')]
final class ApiAdminUserAccountAuditController extends AbstractController
{
    public function __construct(
        private readonly UserAccountAuditLogRepository $userAccountAuditLogRepository,
    ) {
    }

    #[Route('', name: 'api_admin_user_account_audit_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $limit = max(1, min(200, (int) $request->query->get('limit', '50')));
        $page = max(1, (int) $request->query->get('page', '1'));
        $offset = ($page - 1) * $limit;

        $filters = $this->parseFilters($request);
        $data = $this->userAccountAuditLogRepository->findFilteredPaginatedForAdmin($filters, $offset, $limit);

        return new JsonResponse([
            'items' => array_map(fn (UserAccountAuditLog $r) => $this->serializeForList($r), $data['items']),
            'total' => $data['total'],
            'page' => $page,
            'perPage' => $limit,
        ]);
    }

    #[Route('/{id}', name: 'api_admin_user_account_audit_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id): JsonResponse
    {
        $log = $this->userAccountAuditLogRepository->find($id);
        if (!$log instanceof UserAccountAuditLog) {
            return new JsonResponse(['error' => 'Entrée introuvable'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse($this->serializeFull($log));
    }

    /**
     * Query : organizationId, action, actorEmail, targetEmail (contient), dateFrom, dateTo (YYYY-MM-DD ou ISO).
     *
     * @return array<string, mixed>
     */
    private function parseFilters(Request $request): array
    {
        $filters = [];

        $oid = $request->query->get('organizationId');
        if (null !== $oid && $oid !== '' && ctype_digit((string) $oid)) {
            $filters['organizationId'] = (int) $oid;
        }

        $act = $request->query->get('action');
        if (\is_string($act) && $act !== '') {
            $filters['action'] = $act;
        }

        $aem = $request->query->get('actorEmail');
        if (\is_string($aem) && '' !== trim($aem)) {
            $filters['actorEmailContains'] = trim($aem);
        }

        $tem = $request->query->get('targetEmail');
        if (\is_string($tem) && '' !== trim($tem)) {
            $filters['targetEmailContains'] = trim($tem);
        }

        $df = $request->query->get('dateFrom');
        if (\is_string($df) && $df !== '') {
            try {
                $filters['dateFrom'] = new \DateTimeImmutable($df);
            } catch (\Exception) {
            }
        }

        $dt = $request->query->get('dateTo');
        if (\is_string($dt) && $dt !== '') {
            try {
                if (1 === preg_match('/^\d{4}-\d{2}-\d{2}$/', $dt)) {
                    $filters['dateTo'] = new \DateTimeImmutable($dt.' 23:59:59');
                } else {
                    $filters['dateTo'] = new \DateTimeImmutable($dt);
                }
            } catch (\Exception) {
            }
        }

        return $filters;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeForList(UserAccountAuditLog $log): array
    {
        $row = $this->serializeFull($log);
        unset($row['details']);

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeFull(UserAccountAuditLog $log): array
    {
        $actor = $log->getActorUser();
        $target = $log->getTargetUser();
        $org = $log->getOrganization();

        return [
            'id' => $log->getId(),
            'occurredAt' => $log->getOccurredAt()->format(\DateTimeInterface::ATOM),
            'action' => $log->getAction(),
            'actor' => $actor instanceof User
                ? ['id' => $actor->getId(), 'email' => $actor->getEmail(), 'displayName' => $actor->getDisplayName()]
                : null,
            'targetEmail' => $log->getTargetEmail(),
            'targetUserId' => $target instanceof User ? $target->getId() : null,
            'organization' => $org instanceof Organization
                ? ['id' => $org->getId(), 'name' => $org->getName()]
                : null,
            'details' => $log->getDetails(),
            'clientIp' => $log->getClientIp(),
        ];
    }
}
