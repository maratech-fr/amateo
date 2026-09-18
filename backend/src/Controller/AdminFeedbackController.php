<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Feedback;
use App\Entity\SuperAdmin;
use App\Security\AdminSessionCsrf;
use App\Service\FeedbackMailBuilder;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Throwable;

/**
 * P5-6 PR 2 — le rail superadmin du canal de signalement : lister (cross-tenant),
 * lire le détail (contexte lourd inclus), marquer traité / non-traité.
 *
 * `feedback` est TENANT (FORCE RLS) : l'EM par défaut sous le filtre tenant ne
 * verrait qu'un club. La supervision passe donc par la connexion `admin` (porte
 * `admin_all` du rôle propriétaire) en SQL paramétré — même patron
 * qu'AdminCapacityService pour la lecture, et pour l'écriture (treat/untreat).
 *
 * Gardes = patron AdminReleaseNoteController : firewall admin (session) + CSRF sur
 * les ÉCRITURES + identité SuperAdmin ; contexte d'audit posé AVANT toute garde
 * (une tentative refusée doit tracer sa cible). L'audit SA0 capte tout seul
 * (AdminAuditSubscriber). Textes d'erreur français, sans identifiant interne.
 */
#[Route('/api/admin/feedback')]
final readonly class AdminFeedbackController
{
    private const string UUID_PATTERN = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/';

    public function __construct(
        private ManagerRegistry $registry,
        private AdminSessionCsrf $csrf,
        private TokenStorageInterface $tokens,
        private ClockInterface $clock,
        private MailerInterface $mailer,
        private FeedbackMailBuilder $mailBuilder,
        private LoggerInterface $logger,
    ) {}

    private static function toHours(mixed $seconds): ?float
    {
        return null === $seconds ? null : round(((float) $seconds) / 3600, 2);
    }

    #[Route('', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $page = $request->query->getInt('page', 1);
        $limit = $request->query->getInt('limit', 25);
        $status = $request->query->get('status');

        if ($page < 1 || $limit < 1 || $limit > 100) {
            return new JsonResponse(['error' => 'Paramètres de pagination invalides.'], 400);
        }
        if (null !== $status && !\in_array($status, [Feedback::STATUS_UNTREATED, Feedback::STATUS_TREATED], true)) {
            return new JsonResponse(['error' => 'Filtre de statut invalide.'], 400);
        }

        $connection = $this->connection();
        $where = null === $status ? '' : 'WHERE f.status = :status';
        $params = null === $status ? [] : ['status' => $status];

        $total = (int) $connection->fetchOne(
            \sprintf('SELECT COUNT(*) FROM feedback f %s', $where),
            $params,
        );

        $rows = $connection->fetchAllAssociative(
            \sprintf(<<<'SQL'
                SELECT f.id, f.club_id, c.name AS club_name, f.topic, f.message,
                       f.created_at, f.status, f.treated_at,
                       COALESCE(jsonb_exists(f.context::jsonb, 'snapshot'), false) AS has_heavy_context
                FROM feedback f
                LEFT JOIN club c ON c.id = f.club_id
                %s
                ORDER BY f.created_at DESC, f.id DESC
                LIMIT :limit OFFSET :offset
                SQL, $where),
            [...$params, 'limit' => $limit, 'offset' => ($page - 1) * $limit],
            ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );

        return new JsonResponse([
            'items' => array_map($this->serializeRow(...), $rows),
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => (int) ceil($total / $limit),
            ],
            'qos' => $this->qos($connection),
        ]);
    }

    #[Route('/{id}', methods: ['GET'])]
    public function detail(string $id): JsonResponse
    {
        if (1 !== preg_match(self::UUID_PATTERN, $id)) {
            return new JsonResponse(['error' => 'Signalement introuvable.'], 404);
        }

        $row = $this->connection()->fetchAssociative(<<<'SQL'
            SELECT f.id, f.club_id, c.name AS club_name, f.topic, f.message, f.context,
                   f.created_at, f.status, f.treated_at,
                   COALESCE(jsonb_exists(f.context::jsonb, 'snapshot'), false) AS has_heavy_context
            FROM feedback f
            LEFT JOIN club c ON c.id = f.club_id
            WHERE f.id = :id
            SQL, ['id' => $id]);

        if (false === $row) {
            return new JsonResponse(['error' => 'Signalement introuvable.'], 404);
        }

        $item = $this->serializeRow($row);
        $rawContext = $row['context'];
        $item['context'] = \is_string($rawContext) ? json_decode($rawContext, true) : null;

        return new JsonResponse($item);
    }

    #[Route('/{id}/treat', methods: ['POST'])]
    public function treat(string $id, Request $request): JsonResponse
    {
        $request->attributes->set('_admin_audit_context', ['action' => 'feedback.treat', 'id' => $id]);
        $guard = $this->guard($request);
        if ($guard instanceof JsonResponse) {
            return $guard;
        }

        $connection = $this->connection();
        $row = $this->findForWrite($connection, $id);
        if (false === $row) {
            return new JsonResponse(['error' => 'Signalement introuvable.'], 404);
        }
        if (Feedback::STATUS_TREATED === $row['status']) {
            return new JsonResponse(['error' => 'Ce signalement est déjà traité.'], 409);
        }

        $treatedAt = $this->clock->now();
        // UPDATE conditionné au statut : deux treat concurrents passeraient tous
        // deux le check lu plus haut et enverraient deux mercis (revue sécurité,
        // TOCTOU) — le WHERE rend la transition atomique, le perdant ne fait rien.
        $updated = $connection->executeStatement(
            'UPDATE feedback SET status = :status, treated_at = :treated_at, version = version + 1 WHERE id = :id AND status = :expected',
            ['status' => Feedback::STATUS_TREATED, 'treated_at' => $treatedAt->format(DateTimeImmutable::ATOM), 'id' => $id, 'expected' => Feedback::STATUS_UNTREATED],
        );
        if (0 === $updated) {
            return new JsonResponse(['error' => 'Ce signalement est déjà traité.'], 409);
        }

        // « Traité + merci » à l'auteur — best-effort. Auteur effacé/introuvable
        // (RGPD) → skip silencieux : le signalement reste traité, aucune erreur.
        $email = $connection->fetchOne('SELECT email FROM app_user WHERE id = :uid', ['uid' => $row['user_id']]);
        if (\is_string($email) && '' !== $email) {
            $this->sendTreatedAcknowledgement($email);
        }

        return new JsonResponse([
            'id' => $id,
            'status' => Feedback::STATUS_TREATED,
            'treatedAt' => $treatedAt->format(DateTimeImmutable::ATOM),
        ]);
    }

    #[Route('/{id}/untreat', methods: ['POST'])]
    public function untreat(string $id, Request $request): JsonResponse
    {
        $request->attributes->set('_admin_audit_context', ['action' => 'feedback.untreat', 'id' => $id]);
        $guard = $this->guard($request);
        if ($guard instanceof JsonResponse) {
            return $guard;
        }

        $connection = $this->connection();
        $row = $this->findForWrite($connection, $id);
        if (false === $row) {
            return new JsonResponse(['error' => 'Signalement introuvable.'], 404);
        }
        if (Feedback::STATUS_UNTREATED === $row['status']) {
            return new JsonResponse(['error' => 'Ce signalement n\'est pas traité.'], 409);
        }

        // Retour à non-traité : AUCUN e-mail (seule la clôture remercie l'auteur).
        $connection->executeStatement(
            'UPDATE feedback SET status = :status, treated_at = NULL, version = version + 1 WHERE id = :id',
            ['status' => Feedback::STATUS_UNTREATED, 'id' => $id],
        );

        return new JsonResponse([
            'id' => $id,
            'status' => Feedback::STATUS_UNTREATED,
            'treatedAt' => null,
        ]);
    }

    /**
     * @return array<string, scalar>|false
     */
    private function findForWrite(Connection $connection, string $id): array|false
    {
        if (1 !== preg_match(self::UUID_PATTERN, $id)) {
            return false;
        }

        return $connection->fetchAssociative('SELECT id, status, user_id FROM feedback WHERE id = :id', ['id' => $id]);
    }

    /**
     * Qualité de service du rail : délai dépôt→traité (moyenne + p95) par mois,
     * volume par topic et par mois, part traitée globale, âge du plus vieux non
     * traité. SQL pur cross-tenant (connexion admin), tout null-safe.
     *
     * @return array{
     *     treatDelayByMonth: list<array{month: string, avgHours: ?float, p95Hours: ?float}>,
     *     volumeByTopicMonth: list<array{month: string, topic: string, count: int}>,
     *     treatedShare: float,
     *     oldestUntreatedAgeHours: ?float
     * }
     */
    private function qos(Connection $connection): array
    {
        $delayRows = $connection->fetchAllAssociative(<<<'SQL'
            SELECT to_char(date_trunc('month', created_at), 'YYYY-MM') AS month,
                   AVG(EXTRACT(EPOCH FROM (treated_at - created_at))) AS avg_s,
                   percentile_cont(0.95) WITHIN GROUP (ORDER BY EXTRACT(EPOCH FROM (treated_at - created_at))) AS p95_s
            FROM feedback
            WHERE treated_at IS NOT NULL
            GROUP BY 1
            ORDER BY 1 DESC
            SQL);

        $volumeRows = $connection->fetchAllAssociative(<<<'SQL'
            SELECT to_char(date_trunc('month', created_at), 'YYYY-MM') AS month, topic, COUNT(*) AS c
            FROM feedback
            GROUP BY 1, 2
            ORDER BY 1 DESC, 2
            SQL);

        $treatedShare = $connection->fetchOne(<<<'SQL'
            SELECT COUNT(*) FILTER (WHERE status = 'treated')::float / NULLIF(COUNT(*), 0) FROM feedback
            SQL);

        $oldestAgeSeconds = $connection->fetchOne(<<<'SQL'
            SELECT EXTRACT(EPOCH FROM (NOW() - MIN(created_at) FILTER (WHERE status = 'untreated'))) FROM feedback
            SQL);

        return [
            'treatDelayByMonth' => array_map(static fn (array $row): array => [
                'month' => (string) $row['month'],
                'avgHours' => self::toHours($row['avg_s']),
                'p95Hours' => self::toHours($row['p95_s']),
            ], $delayRows),
            'volumeByTopicMonth' => array_map(static fn (array $row): array => [
                'month' => (string) $row['month'],
                'topic' => (string) $row['topic'],
                'count' => (int) $row['c'],
            ], $volumeRows),
            'treatedShare' => false === $treatedShare || null === $treatedShare ? 0.0 : round((float) $treatedShare, 4),
            'oldestUntreatedAgeHours' => self::toHours(false === $oldestAgeSeconds ? null : $oldestAgeSeconds),
        ];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function serializeRow(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'clubId' => (string) $row['club_id'],
            'clubName' => null === $row['club_name'] ? null : (string) $row['club_name'],
            'topic' => (string) $row['topic'],
            'message' => (string) $row['message'],
            'createdAt' => $this->atom($row['created_at']),
            'status' => (string) $row['status'],
            'treatedAt' => null === $row['treated_at'] ? null : $this->atom($row['treated_at']),
            'hasHeavyContext' => (bool) $row['has_heavy_context'],
        ];
    }

    private function atom(mixed $value): string
    {
        return new DateTimeImmutable((string) $value)->format(DateTimeImmutable::ATOM);
    }

    private function sendTreatedAcknowledgement(string $to): void
    {
        // mailer->send n'enfile qu'un SendEmailMessage sur le bus ; seul un échec de
        // DISPATCH (Redis down) tomberait ici — avalé, le signalement est déjà traité.
        try {
            $this->mailer->send($this->mailBuilder->buildTreated($to));
        } catch (Throwable $e) {
            // Mail avalé (le signalement est déjà traité) mais l'échec de DISPATCH est
            // tracé — sinon une panne du bus (Redis) resterait muette.
            $this->logger->warning('Feedback acknowledgement mail dispatch failed', ['error' => $e->getMessage()]);
        }
    }

    private function connection(): Connection
    {
        $connection = $this->registry->getConnection('admin');
        \assert($connection instanceof Connection);

        return $connection;
    }

    /** CSRF + identité SuperAdmin — l'appelant a posé le contexte d'audit AVANT. */
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
}
