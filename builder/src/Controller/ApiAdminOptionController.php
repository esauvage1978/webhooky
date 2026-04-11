<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Option;
use App\Repository\OptionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/admin/options')]
#[IsGranted('ROLE_ADMIN')]
final class ApiAdminOptionController extends AbstractController
{
    private const META_CATEGORY = 'Options';

    private const META_OPTION_CATEGORY_LIST = 'option_category';

    private const META_OPTION_DOMAIN_LIST = 'option_domaine';

    public function __construct(
        private readonly OptionRepository $optionRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly ValidatorInterface $validator,
    ) {
    }

    /**
     * Valeurs des listes CRUD : contenu des options option_category et option_domaine (séparateur « ; »).
     */
    #[Route('/meta/choices', name: 'api_admin_options_meta_choices', methods: ['GET'])]
    public function metaChoices(): JsonResponse
    {
        $catRow = $this->optionRepository->findFirstByOptionName(self::META_OPTION_CATEGORY_LIST)
            ?? $this->optionRepository->findOneByCategoryAndOptionName(self::META_CATEGORY, self::META_OPTION_CATEGORY_LIST);
        $domRow = $this->optionRepository->findFirstByOptionName(self::META_OPTION_DOMAIN_LIST)
            ?? $this->optionRepository->findOneByCategoryAndOptionName(self::META_CATEGORY, self::META_OPTION_DOMAIN_LIST);

        return new JsonResponse([
            'domains' => $this->splitChoiceList($domRow),
            'categories' => $this->splitChoiceList($catRow),
        ]);
    }

    #[Route('', name: 'api_admin_options_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $limit = max(1, min(200, (int) $request->query->get('limit', '50')));
        $page = max(1, (int) $request->query->get('page', '1'));
        $offset = ($page - 1) * $limit;

        $category = $request->query->get('category');
        $domain = $request->query->get('domain');
        $name = $request->query->get('optionName');

        $data = $this->optionRepository->findFilteredPaginatedForAdmin(
            \is_string($category) && '' !== $category ? $category : null,
            \is_string($domain) && '' !== $domain ? $domain : null,
            \is_string($name) && '' !== $name ? $name : null,
            $offset,
            $limit,
        );

        return new JsonResponse([
            'items' => array_map(fn (Option $o) => $this->serialize($o), $data['items']),
            'total' => $data['total'],
            'page' => $page,
            'perPage' => $limit,
        ]);
    }

    #[Route('/{id}', name: 'api_admin_options_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id): JsonResponse
    {
        $o = $this->optionRepository->find($id);
        if (!$o instanceof Option) {
            return new JsonResponse(['error' => 'Option introuvable'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse($this->serialize($o));
    }

    #[Route('', name: 'api_admin_options_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $payload = $this->decodeJson($request);
        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        $o = $this->applyPayload(new Option(), $payload);
        $errors = $this->validator->validate($o);
        if (\count($errors) > 0) {
            return new JsonResponse(['error' => (string) $errors[0]->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->entityManager->persist($o);
        $this->entityManager->flush();

        return new JsonResponse($this->serialize($o), Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'api_admin_options_update', methods: ['PUT', 'PATCH'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $o = $this->optionRepository->find($id);
        if (!$o instanceof Option) {
            return new JsonResponse(['error' => 'Option introuvable'], Response::HTTP_NOT_FOUND);
        }

        $payload = $this->decodeJson($request);
        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        $o = $this->applyPayload($o, $payload, $request->isMethod('PATCH'));
        $errors = $this->validator->validate($o);
        if (\count($errors) > 0) {
            return new JsonResponse(['error' => (string) $errors[0]->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->entityManager->flush();

        return new JsonResponse($this->serialize($o));
    }

    #[Route('/{id}', name: 'api_admin_options_delete', methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        $o = $this->optionRepository->find($id);
        if (!$o instanceof Option) {
            return new JsonResponse(['error' => 'Option introuvable'], Response::HTTP_NOT_FOUND);
        }

        $this->entityManager->remove($o);
        $this->entityManager->flush();

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    /**
     * @return array<string, mixed>|JsonResponse
     */
    private function decodeJson(Request $request): array|JsonResponse
    {
        $raw = $request->getContent();
        if ('' === $raw) {
            return [];
        }
        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return new JsonResponse(['error' => 'JSON invalide'], Response::HTTP_BAD_REQUEST);
        }

        return \is_array($data) ? $data : [];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function applyPayload(Option $o, array $payload, bool $patch = false): Option
    {
        $takeString = static function (string $key) use ($payload): ?string {
            if (!\array_key_exists($key, $payload)) {
                return null;
            }
            $v = $payload[$key];
            if (null === $v) {
                return null;
            }

            return \is_string($v) || \is_int($v) || \is_float($v) ? (string) $v : null;
        };

        if (!$patch || \array_key_exists('optionName', $payload)) {
            $v = $takeString('optionName');
            if (null !== $v) {
                $o->setOptionName($v);
            }
        }
        if (!$patch || \array_key_exists('optionValue', $payload)) {
            $v = $takeString('optionValue');
            if (null !== $v) {
                $o->setOptionValue($v);
            }
        }

        if (\array_key_exists('domain', $payload)) {
            $d = $payload['domain'];
            if (null === $d || '' === $d) {
                $o->setDomain(null);
            } elseif (\is_string($d)) {
                $o->setDomain($d);
            }
        } elseif (!$patch) {
            $o->setDomain(null);
        }

        if (!$patch || \array_key_exists('category', $payload)) {
            $v = $takeString('category');
            if (null !== $v) {
                $o->setCategory($v);
            }
        }

        if (\array_key_exists('comment', $payload)) {
            $c = $payload['comment'];
            if (null === $c || '' === $c) {
                $o->setComment(null);
            } elseif (\is_string($c)) {
                $o->setComment($c);
            }
        } elseif (!$patch) {
            $o->setComment(null);
        }

        return $o;
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(Option $o): array
    {
        return [
            'id' => $o->getId(),
            'optionName' => $o->getOptionName(),
            'optionValue' => $o->getOptionValue(),
            'domain' => $o->getDomain(),
            'category' => $o->getCategory(),
            'comment' => $o->getComment(),
        ];
    }

    /**
     * @return list<string>
     */
    private function splitChoiceList(?Option $o): array
    {
        if (!$o instanceof Option) {
            return [];
        }
        $raw = trim($o->getOptionValue());
        if ('' === $raw) {
            return [];
        }

        // Séparateur « ; » ASCII ou pleine chasse (saisie bureautique).
        $normalized = str_replace("\u{FF1B}", ';', $raw);
        $segments = preg_split('/\s*;\s*/', $normalized, -1, \PREG_SPLIT_NO_EMPTY);
        if ($segments === false) {
            return [];
        }

        $parts = [];
        foreach ($segments as $segment) {
            $t = trim((string) $segment);
            if ('' !== $t) {
                $parts[] = $t;
            }
        }

        return array_values(array_unique($parts));
    }
}
