<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ResourceAuditLog;
use App\Repository\ResourceAuditLogRepository;
use App\Service\Audit\AdminResourceAuditPresenter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin/resource-audit-logs')]
#[IsGranted('ROLE_ADMIN')]
final class ApiAdminResourceAuditController extends AbstractController
{
    public function __construct(
        private readonly ResourceAuditLogRepository $resourceAuditLogRepository,
        private readonly AdminResourceAuditPresenter $adminResourceAuditPresenter,
    ) {
    }

    #[Route('', name: 'api_admin_resource_audit_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $limit = max(1, min(200, (int) $request->query->get('limit', '50')));
        $page = max(1, (int) $request->query->get('page', '1'));
        $offset = ($page - 1) * $limit;

        $filters = $this->parseFilters($request);
        $data = $this->resourceAuditLogRepository->findFilteredPaginatedForAdmin($filters, $offset, $limit);

        return new JsonResponse([
            'items' => array_map(
                fn (ResourceAuditLog $r) => $this->adminResourceAuditPresenter->presentForList($r),
                $data['items'],
            ),
            'total' => $data['total'],
            'page' => $page,
            'perPage' => $limit,
        ]);
    }

    #[Route('/{id}', name: 'api_admin_resource_audit_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id): JsonResponse
    {
        $log = $this->resourceAuditLogRepository->find($id);
        if (!$log instanceof ResourceAuditLog) {
            return new JsonResponse(['error' => 'Entrée introuvable'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse($this->adminResourceAuditPresenter->present($log));
    }

    /**
     * Query : resourceType, action, organizationId, actorUserId, actorEmail, resourceId, dateFrom, dateTo (ISO ou YYYY-MM-DD).
     *
     * @return array<string, mixed>
     */
    private function parseFilters(Request $request): array
    {
        $filters = [];

        $rt = $request->query->get('resourceType');
        if (\is_string($rt) && $rt !== '') {
            $filters['resourceType'] = $rt;
        }

        $act = $request->query->get('action');
        if (\is_string($act) && $act !== '') {
            $filters['action'] = $act;
        }

        $oid = $request->query->get('organizationId');
        if (null !== $oid && $oid !== '' && ctype_digit((string) $oid)) {
            $filters['organizationId'] = (int) $oid;
        }

        $aid = $request->query->get('actorUserId');
        if (null !== $aid && $aid !== '' && ctype_digit((string) $aid)) {
            $filters['actorUserId'] = (int) $aid;
        }

        $aem = $request->query->get('actorEmail');
        if (\is_string($aem) && '' !== trim($aem)) {
            $filters['actorEmailContains'] = trim($aem);
        }

        $rid = $request->query->get('resourceId');
        if (null !== $rid && $rid !== '' && ctype_digit((string) $rid)) {
            $filters['resourceId'] = (int) $rid;
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
}
