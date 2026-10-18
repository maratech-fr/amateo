<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\SuperAdmin;
use App\Enum\DeploymentStatus;
use App\EventListener\AdminCsrfListener;
use App\Security\AdminSessionCsrf;
use App\Service\GitHubDeployClient;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Exception;
use JsonException;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Console superadmin — « mise en prod programmée » : lister, programmer, annuler, et lister les
 * versions déployables. Un geste de PROD cross-tenant, la surface la plus sensible.
 *
 * Programmer EXIGE une version = tag `^v` RÉEL, une CI verte ET une heure H ≥ maintenant +10 min :
 * toute version forgée / CI rouge / H hors bornes → 422, et JAMAIS de dispatch (programmer n'est
 * pas déployer — le dispatch est le seul fait de `app:deployment:tick`). Un seul déploiement
 * actif à la fois (409). Annuler efface un `scheduled`, mais REFUSE un `dispatched` (409 : le
 * train est parti).
 *
 * Gardes = patron {@see AdminDemoController} : contexte d'audit posé AVANT toute garde, CSRF de
 * session (doublé par {@see AdminCsrfListener}), identité SuperAdmin. AUCUN
 * club_id posé (contrat SA0) : `scheduled_deployment` est GLOBALE et s'écrit par la connexion
 * `admin` (le bandeau runtime, lui, la lit par la connexion applicative). Textes français, aucun
 * identifiant interne, jamais le jeton GitHub dans une réponse.
 */
#[Route('/api/admin/deployments')]
final readonly class AdminDeploymentController
{
    private const string UUID_PATTERN = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/';

    private const int MIN_LEAD_MINUTES = 10;

    private const int MIN_WINDOW_MINUTES = 1;

    private const int MAX_WINDOW_MINUTES = 240;

    public function __construct(
        private GitHubDeployClient $client,
        private AdminSessionCsrf $csrf,
        private TokenStorageInterface $tokens,
        private ManagerRegistry $managerRegistry,
        private ClockInterface $clock,
    ) {}

    #[Route('', methods: ['GET'])]
    public function list(): JsonResponse
    {
        // Le firewall admin gate déjà la lecture. On ne sert jamais le jeton (il n'est pas stocké
        // en base de toute façon) ; on indique seulement si la fonctionnalité est configurée.
        $rows = $this->connection()->fetchAllAssociative(
            'SELECT id, version, status, scheduled_at, expected_return_at, dispatched_at, github_run_id, failure_reason, created_at'
            . ' FROM scheduled_deployment ORDER BY created_at DESC LIMIT 20',
        );

        return new JsonResponse([
            'items' => array_map($this->serialize(...), $rows),
            'configured' => $this->client->isConfigured(),
        ]);
    }

    #[Route('/tags', methods: ['GET'])]
    public function tags(): JsonResponse
    {
        return new JsonResponse(['items' => $this->client->tags()]);
    }

    #[Route('', methods: ['POST'])]
    public function schedule(Request $request): JsonResponse
    {
        $request->attributes->set('_admin_audit_context', ['action' => 'deployment.schedule']);
        if (($denied = $this->guard($request)) instanceof JsonResponse) {
            return $denied;
        }

        // Jeton/dépôt absent → fonctionnalité inerte : on refuse explicitement (jamais un dispatch
        // à vide), 422.
        if (!$this->client->isConfigured()) {
            return new JsonResponse(['error' => 'La mise en production programmée n’est pas configurée.'], 422);
        }

        $body = $this->decode($request);
        if (null === $body) {
            return new JsonResponse(['error' => 'Corps de requête invalide.'], 422);
        }

        $version = \is_string($body['version'] ?? null) ? $body['version'] : '';
        $rawScheduledAt = \is_string($body['scheduledAt'] ?? null) ? $body['scheduledAt'] : '';
        $windowMinutes = \is_int($body['windowMinutes'] ?? null) ? $body['windowMinutes'] : 0;

        $scheduledAt = $this->parseInstant($rawScheduledAt);
        if (!$scheduledAt instanceof DateTimeImmutable) {
            return new JsonResponse(['error' => 'La date de mise en production est invalide.'], 422);
        }
        if ($scheduledAt < $this->clock->now()->modify(\sprintf('+%d minutes', self::MIN_LEAD_MINUTES))) {
            return new JsonResponse(['error' => \sprintf('La mise en production doit être programmée au moins %d minutes à l’avance.', self::MIN_LEAD_MINUTES)], 422);
        }
        if ($windowMinutes < self::MIN_WINDOW_MINUTES || $windowMinutes > self::MAX_WINDOW_MINUTES) {
            return new JsonResponse(['error' => 'La durée de maintenance annoncée est hors des bornes autorisées.'], 422);
        }

        // La version DOIT être un tag déployable réel (`^v`) — une version forgée/inexistante
        // n'atteint jamais le dispatch. Puis la CI de ce tag doit être verte.
        if (!\in_array($version, $this->client->tags(), true)) {
            return new JsonResponse(['error' => 'La version demandée n’est pas un tag déployable.'], 422);
        }
        if (!$this->client->isCiGreen($version)) {
            return new JsonResponse(['error' => 'L’intégration continue de cette version n’est pas au vert.'], 422);
        }

        // Un seul déploiement actif à la fois (l'index unique partiel est le garde-fou ultime).
        if ($this->activeCount() > 0) {
            return new JsonResponse(['error' => 'Une mise en production est déjà programmée.'], 409);
        }

        $id = Uuid::v4()->toRfc4122();
        $admin = $this->tokens->getToken()?->getUser();
        \assert($admin instanceof SuperAdmin);
        $expectedReturnAt = $scheduledAt->modify(\sprintf('+%d minutes', $windowMinutes));
        $this->connection()->executeStatement(
            'INSERT INTO scheduled_deployment (id, version, scheduled_at, expected_return_at, status, created_by, created_at, updated_at)'
            . ' VALUES (:id, :version, :scheduled, :ret, :status, :by, NOW(), NOW())',
            [
                'id' => $id,
                'version' => $version,
                'scheduled' => $scheduledAt->format('Y-m-d H:i:sP'),
                'ret' => $expectedReturnAt->format('Y-m-d H:i:sP'),
                'status' => DeploymentStatus::SCHEDULED->value,
                'by' => $admin->getId(),
            ],
        );

        return new JsonResponse([
            'id' => $id,
            'version' => $version,
            'status' => DeploymentStatus::SCHEDULED->value,
            'scheduledAt' => $scheduledAt->format(DateTimeInterface::ATOM),
            'expectedReturnAt' => $expectedReturnAt->format(DateTimeInterface::ATOM),
        ], 201);
    }

    #[Route('/{id}', methods: ['DELETE'])]
    public function cancel(string $id, Request $request): JsonResponse
    {
        $request->attributes->set('_admin_audit_context', ['action' => 'deployment.cancel', 'id' => $id]);
        if (($denied = $this->guard($request)) instanceof JsonResponse) {
            return $denied;
        }

        if (1 !== preg_match(self::UUID_PATTERN, $id)) {
            return new JsonResponse(['error' => 'Mise en production introuvable.'], 404);
        }

        $status = $this->connection()->fetchOne('SELECT status FROM scheduled_deployment WHERE id = :id', ['id' => $id]);
        if (!\is_string($status)) {
            return new JsonResponse(['error' => 'Mise en production introuvable.'], 404);
        }
        if (DeploymentStatus::SCHEDULED->value !== $status) {
            // Un `dispatched` (le train est parti) ou un déploiement déjà terminé ne s'annule plus.
            return new JsonResponse(['error' => 'Cette mise en production ne peut plus être annulée.'], 409);
        }

        $this->connection()->executeStatement(
            'UPDATE scheduled_deployment SET status = :status, updated_at = NOW() WHERE id = :id',
            ['status' => DeploymentStatus::CANCELLED->value, 'id' => $id],
        );

        return new JsonResponse(['id' => $id, 'status' => DeploymentStatus::CANCELLED->value]);
    }

    /** Contexte d'audit déjà posé ; CSRF + identité SuperAdmin (l'acteur est posé si la garde passe). */
    private function guard(Request $request): ?JsonResponse
    {
        if (!$this->csrf->isValid($request)) {
            return new JsonResponse(['error' => 'Invalid CSRF token.'], 403);
        }
        $admin = $this->tokens->getToken()?->getUser();
        if (!$admin instanceof SuperAdmin) {
            return new JsonResponse(['error' => 'Unauthorized.'], 401);
        }
        $request->attributes->set('_admin_audit_actor_id', $admin->getId());

        return null;
    }

    private function activeCount(): int
    {
        return (int) $this->connection()->fetchOne(
            'SELECT COUNT(*) FROM scheduled_deployment WHERE status IN (:active)',
            ['active' => DeploymentStatus::activeValues()],
            ['active' => ArrayParameterType::STRING],
        );
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function serialize(array $row): array
    {
        $runId = $row['github_run_id'] ?? null;

        return [
            'id' => (string) $row['id'],
            'version' => (string) $row['version'],
            'status' => (string) $row['status'],
            'scheduledAt' => $this->isoOrNull($row['scheduled_at'] ?? null),
            'expectedReturnAt' => $this->isoOrNull($row['expected_return_at'] ?? null),
            'dispatchedAt' => $this->isoOrNull($row['dispatched_at'] ?? null),
            'githubRunId' => null === $runId ? null : (int) $runId,
            'failureReason' => \is_string($row['failure_reason'] ?? null) ? $row['failure_reason'] : null,
            'createdAt' => $this->isoOrNull($row['created_at'] ?? null),
        ];
    }

    private function isoOrNull(mixed $value): ?string
    {
        return \is_string($value) && '' !== $value ? new DateTimeImmutable($value)->format(DateTimeInterface::ATOM) : null;
    }

    private function parseInstant(string $value): ?DateTimeImmutable
    {
        if ('' === $value) {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Exception) {
            return null;
        }
    }

    /** @return array<array-key, mixed>|null */
    private function decode(Request $request): ?array
    {
        $raw = trim($request->getContent());
        if ('' === $raw) {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 8, \JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return \is_array($decoded) ? $decoded : null;
    }

    private function connection(): Connection
    {
        $connection = $this->managerRegistry->getConnection('admin');
        \assert($connection instanceof Connection);

        return $connection;
    }
}
