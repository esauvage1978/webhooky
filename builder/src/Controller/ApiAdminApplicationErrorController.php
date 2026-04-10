<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ApplicationErrorLog;
use App\Entity\User;
use App\Repository\ApplicationErrorLogRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin/application-errors')]
#[IsGranted('ROLE_ADMIN')]
final class ApiAdminApplicationErrorController extends AbstractController
{
    public function __construct(
        private readonly ApplicationErrorLogRepository $applicationErrorLogRepository,
    ) {
    }

    #[Route('', name: 'api_admin_application_errors_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $limit = max(1, min(100, (int) $request->query->get('limit', '50')));
        $page = max(1, (int) $request->query->get('page', '1'));
        $offset = ($page - 1) * $limit;

        $dateFrom = null;
        $dateTo = null;
        $df = $request->query->get('dateFrom');
        if (\is_string($df) && $df !== '') {
            try {
                $dateFrom = new \DateTimeImmutable($df);
            } catch (\Exception) {
            }
        }
        $dt = $request->query->get('dateTo');
        if (\is_string($dt) && $dt !== '') {
            try {
                if (1 === preg_match('/^\d{4}-\d{2}-\d{2}$/', $dt)) {
                    $dateTo = new \DateTimeImmutable($dt.' 23:59:59');
                } else {
                    $dateTo = new \DateTimeImmutable($dt);
                }
            } catch (\Exception) {
            }
        }

        $data = $this->applicationErrorLogRepository->findPaginatedForAdmin($offset, $limit, $dateFrom, $dateTo);

        return new JsonResponse([
            'items' => array_map(fn (ApplicationErrorLog $e) => $this->serializeList($e), $data['items']),
            'total' => $data['total'],
            'page' => $page,
            'perPage' => $limit,
        ]);
    }

    #[Route('/{id}', name: 'api_admin_application_errors_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id): JsonResponse
    {
        $row = $this->applicationErrorLogRepository->find($id);
        if (!$row instanceof ApplicationErrorLog) {
            return new JsonResponse(['error' => 'Entrée introuvable'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse($this->serializeFull($row));
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeList(ApplicationErrorLog $e): array
    {
        $user = $e->getUser();

        return [
            'id' => $e->getId(),
            'createdAt' => $e->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'level' => $e->getLevel(),
            'source' => $e->getSource(),
            'message' => $e->getMessage(),
            'exceptionClass' => $e->getExceptionClass(),
            'httpMethod' => $e->getHttpMethod(),
            'requestUri' => $e->getRequestUri(),
            'userEmail' => $user instanceof User ? $user->getEmail() : null,
            'organizationName' => $e->getOrganization()?->getName(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeFull(ApplicationErrorLog $e): array
    {
        $user = $e->getUser();
        $org = $e->getOrganization();

        return [
            'id' => $e->getId(),
            'createdAt' => $e->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'level' => $e->getLevel(),
            'source' => $e->getSource(),
            'message' => $e->getMessage(),
            'exceptionClass' => $e->getExceptionClass(),
            'exceptionCode' => $e->getExceptionCode(),
            'detail' => $e->getDetail(),
            'trace' => $e->getTrace(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'httpMethod' => $e->getHttpMethod(),
            'requestUri' => $e->getRequestUri(),
            'clientIp' => $e->getClientIp(),
            'userAgent' => $e->getUserAgent(),
            'context' => $e->getContext(),
            'user' => $user instanceof User
                ? ['id' => $user->getId(), 'email' => $user->getEmail()]
                : null,
            'organization' => $org !== null
                ? ['id' => $org->getId(), 'name' => $org->getName()]
                : null,
        ];
    }
}
