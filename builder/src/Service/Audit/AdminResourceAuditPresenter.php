<?php

declare(strict_types=1);

namespace App\Service\Audit;

use App\Entity\FormWebhook;
use App\Entity\ResourceAuditLog;
use App\Entity\ServiceConnection;
use App\Entity\User;
use App\Repository\FormWebhookRepository;
use App\Repository\ServiceConnectionRepository;

/**
 * Enrichit les entrées resource_audit_log pour la supervision administrateur (nom, lien SPA, diff lisible).
 */
final class AdminResourceAuditPresenter
{
    public function __construct(
        private readonly FormWebhookRepository $formWebhookRepository,
        private readonly ServiceConnectionRepository $serviceConnectionRepository,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function present(ResourceAuditLog $log): array
    {
        $actor = $log->getActorUser();
        $org = $log->getOrganization();
        $rawDetails = $log->getDetails();

        $detailsPresented = $this->presentDetails($log, $rawDetails);
        $resourceName = $this->resolveResourceName($log, \is_array($rawDetails) ? $rawDetails : null);
        $resourceStillExists = $this->resourceExistsInDb($log);

        [$spaPath, $spaPathHint] = $this->spaPathFor($log);

        return [
            'id' => $log->getId(),
            'occurredAt' => $log->getOccurredAt()->format(\DateTimeInterface::ATOM),
            'resourceType' => $log->getResourceType(),
            'resourceTypeLabel' => $this->resourceTypeLabel($log->getResourceType()),
            'action' => $log->getAction(),
            'actionLabel' => $this->actionLabel($log->getAction()),
            'resourceId' => $log->getResourceId(),
            'resourceName' => $resourceName,
            'resourceStillExists' => $resourceStillExists,
            'spaPath' => $spaPath,
            'spaOpenHint' => $spaPathHint,
            'clientIp' => $log->getClientIp(),
            'actor' => $this->serializeActor($actor),
            'organization' => $org !== null
                ? ['id' => $org->getId(), 'name' => $org->getName()]
                : null,
            'detailsPresented' => $detailsPresented,
            'details' => $rawDetails,
        ];
    }

    /**
     * Entrée allégée pour les listes paginées (résumé uniquement ; détail complet via GET /{id}).
     *
     * @return array<string, mixed>
     */
    public function presentForList(ResourceAuditLog $log): array
    {
        $row = $this->present($log);
        $preview = null;
        $dp = $row['detailsPresented'] ?? null;
        if (\is_array($dp) && isset($dp['auditSummary']) && \is_string($dp['auditSummary']) && $dp['auditSummary'] !== '') {
            $preview = $dp['auditSummary'];
        }
        unset($row['detailsPresented'], $row['details']);

        return $row + [
            'detailsPresentedPreview' => $preview,
        ];
    }

    /**
     * @param array<string, mixed>|null $rawDetails
     *
     * @return array<string, mixed>|null
     */
    private function presentDetails(ResourceAuditLog $log, ?array $rawDetails): ?array
    {
        if ($rawDetails === null) {
            return null;
        }

        if (ResourceAuditLog::RESOURCE_FORM_WEBHOOK === $log->getResourceType()) {
            $out = FormWebhookAuditDetailsPresenter::forApi($rawDetails, $log->getAction());
            if (ResourceAuditLog::ACTION_CREATED === $log->getAction()
                && isset($rawDetails['snapshot']['name'])) {
                $out['snapshotNameAtCreation'] = (string) $rawDetails['snapshot']['name'];
            }

            return $out;
        }

        if (ResourceAuditLog::RESOURCE_SERVICE_CONNECTION === $log->getResourceType()) {
            return $this->presentServiceConnectionDetails($rawDetails, $log->getAction());
        }

        return $rawDetails;
    }

    /**
     * @param array<string, mixed> $d
     *
     * @return array<string, mixed>
     */
    private function presentServiceConnectionDetails(array $d, string $action): array
    {
        $out = [
            'auditSummary' => null,
            'snapshotReadable' => null,
            'requestKeys' => null,
        ];

        if (isset($d['snapshot']) && \is_array($d['snapshot'])) {
            $snap = $d['snapshot'];
            $out['snapshotReadable'] = [
                'name' => $snap['name'] ?? null,
                'type' => $snap['type'] ?? null,
                'organizationId' => $snap['organizationId'] ?? null,
                'configFingerprint' => $snap['configFingerprint'] ?? null,
            ];
        }

        if (isset($d['requestKeys']) && \is_array($d['requestKeys'])) {
            /** @var list<string> $keys */
            $keys = array_values(array_filter($d['requestKeys'], static fn ($k) => \is_string($k) && $k !== ''));
            $out['requestKeys'] = $keys;
        }

        if (ResourceAuditLog::ACTION_UPDATED === $action && !empty($out['requestKeys'])) {
            $out['auditSummary'] = 'Champs envoyés dans la requête : '.implode(', ', $out['requestKeys'])
                .'. (La configuration complète n’est pas rejouée dans l’historique ; empreinte SHA-256 dans l’instantané.)';
        } elseif (ResourceAuditLog::ACTION_CREATED === $action) {
            $out['auditSummary'] = 'Connecteur créé — voir l’instantané (nom, type, empreinte de configuration).';
        } elseif (ResourceAuditLog::ACTION_DELETED === $action) {
            $out['auditSummary'] = 'Connecteur supprimé — dernier instantané conservé ci-dessous.';
        }

        return $out;
    }

    /**
     * @param array<string, mixed>|null $raw
     */
    private function resolveResourceName(ResourceAuditLog $log, ?array $raw): ?string
    {
        $type = $log->getResourceType();
        $action = $log->getAction();
        $id = $log->getResourceId();

        if (ResourceAuditLog::RESOURCE_FORM_WEBHOOK === $type) {
            if (ResourceAuditLog::ACTION_DELETED === $action && \is_array($raw) && isset($raw['name']) && \is_string($raw['name'])) {
                return $raw['name'];
            }
            $fw = $this->formWebhookRepository->find($id);
            if ($fw instanceof FormWebhook) {
                return $fw->getName();
            }
            if (\is_array($raw)) {
                if (isset($raw['snapshot']['name']) && \is_string($raw['snapshot']['name'])) {
                    return $raw['snapshot']['name'];
                }
                $fromDiff = $this->extractNameFromWebhookDiff($raw);
                if ($fromDiff !== null) {
                    return $fromDiff;
                }
            }

            return null;
        }

        if (ResourceAuditLog::RESOURCE_SERVICE_CONNECTION === $type && \is_array($raw)) {
            if (isset($raw['snapshot']['name']) && \is_string($raw['snapshot']['name'])) {
                return $raw['snapshot']['name'];
            }
            $sc = $this->serviceConnectionRepository->find($id);
            if ($sc instanceof ServiceConnection) {
                return $sc->getName();
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function extractNameFromWebhookDiff(array $raw): ?string
    {
        if (!isset($raw['diff']['changes']) || !\is_array($raw['diff']['changes'])) {
            return null;
        }
        foreach ($raw['diff']['changes'] as $ch) {
            if (!\is_array($ch)) {
                continue;
            }
            if (($ch['kind'] ?? '') === 'scalar' && ($ch['key'] ?? '') === 'name') {
                $v = $ch['after'] ?? $ch['before'] ?? null;
                if (\is_string($v) && $v !== '') {
                    return $v;
                }
            }
        }

        return null;
    }

    private function resourceExistsInDb(ResourceAuditLog $log): bool
    {
        $id = $log->getResourceId();
        if ($id <= 0) {
            return false;
        }
        if (ResourceAuditLog::RESOURCE_FORM_WEBHOOK === $log->getResourceType()) {
            return $this->formWebhookRepository->find($id) instanceof FormWebhook;
        }
        if (ResourceAuditLog::RESOURCE_SERVICE_CONNECTION === $log->getResourceType()) {
            return $this->serviceConnectionRepository->find($id) instanceof ServiceConnection;
        }

        return false;
    }

    /**
     * @return array{0: string, 1: string} [path, hint]
     */
    private function spaPathFor(ResourceAuditLog $log): array
    {
        $id = $log->getResourceId();
        if (ResourceAuditLog::RESOURCE_FORM_WEBHOOK === $log->getResourceType()) {
            return [
                '/workflows/'.$id.'/edit',
                'Ouvre l’éditeur du workflow (nouvel onglet). Si le workflow a été supprimé, l’écran affichera une erreur.',
            ];
        }
        if (ResourceAuditLog::RESOURCE_SERVICE_CONNECTION === $log->getResourceType()) {
            return [
                '/integrations?openConnection='.$id,
                'Ouvre l’écran Intégrations avec le connecteur #'.$id.' en édition (nouvel onglet).',
            ];
        }

        return ['/', '—'];
    }

    private function resourceTypeLabel(string $t): string
    {
        return match ($t) {
            ResourceAuditLog::RESOURCE_FORM_WEBHOOK => 'Workflow',
            ResourceAuditLog::RESOURCE_SERVICE_CONNECTION => 'Connecteur',
            default => $t,
        };
    }

    private function actionLabel(string $a): string
    {
        return match ($a) {
            ResourceAuditLog::ACTION_CREATED => 'Création',
            ResourceAuditLog::ACTION_UPDATED => 'Modification',
            ResourceAuditLog::ACTION_DELETED => 'Suppression',
            default => $a,
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    private function serializeActor(?User $actor): ?array
    {
        if (!$actor instanceof User) {
            return null;
        }

        $dn = $actor->getDisplayName();

        return [
            'id' => $actor->getId(),
            'email' => $actor->getEmail(),
            'displayName' => $dn !== null && $dn !== '' ? $dn : null,
        ];
    }
}
