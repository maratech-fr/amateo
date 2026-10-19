<?php

declare(strict_types=1);

namespace App\Controller;

use App\Enum\AdminJobStatus;
use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * System errors from two sources:
 *  1. admin_job_run with status failed|interrupted
 *  2. audit_log with action auth.login_failed (audit_log has no status column)
 *
 * Deduplication is by (message_hash, hour_bucket) — same error message in the
 * same hour is collapsed to the most recent occurrence, regardless of source.
 */
#[Route('/api/admin/system-errors')]
final readonly class AdminSystemErrorsController
{
    private const int DEFAULT_LIMIT = 50;

    private const int MAX_LIMIT = 200;

    public function __construct(
        private ManagerRegistry $registry,
    ) {}

    #[Route('', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        try {
            $page = $this->positiveInt($request->query->get('page', '1'), 'page');
            $limit = $this->clampLimit($this->positiveInt($request->query->get('limit', (string) self::DEFAULT_LIMIT), 'limit'));
        } catch (InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }

        $since = $request->query->get('since');
        if (null !== $since) {
            $sinceDate = DateTimeImmutable::createFromFormat('Y-m-d', $since);
            if (false === $sinceDate || $sinceDate->format('Y-m-d') !== $since) {
                return new JsonResponse(['error' => 'Invalid since date. Expected ISO date (YYYY-MM-DD).'], 400);
            }
        }

        $connection = $this->registry->getConnection('admin');
        \assert($connection instanceof Connection);

        $params = ['faulty' => AdminJobStatus::faultyValues()];
        $paramTypes = ['faulty' => ArrayParameterType::STRING];
        // `created_at` est un alias de SELECT, invisible dans le WHERE : chaque branche doit filtrer
        // sa colonne réelle (admin_job_run.started_at, audit_log.occurred_at), d'où deux fragments.
        $sinceJobSql = '';
        $sinceAuditSql = '';
        if (null !== $since) {
            $sinceJobSql = 'AND started_at >= :since';
            $sinceAuditSql = 'AND occurred_at >= :since';
            $params['since'] = $since . ' 00:00:00';
        }

        $cte = "WITH combined AS (
            SELECT
                'job' AS source,
                command_name AS message,
                'error' AS severity,
                started_at AS created_at,
                md5(command_name) AS message_hash,
                date_trunc('hour', started_at) AS hour_bucket
            FROM admin_job_run
            WHERE status IN (:faulty)
                {$sinceJobSql}
            UNION ALL
            SELECT
                'audit' AS source,
                action AS message,
                'error' AS severity,
                occurred_at AS created_at,
                md5(action) AS message_hash,
                date_trunc('hour', occurred_at) AS hour_bucket
            FROM audit_log
            WHERE action = 'auth.login_failed'
                {$sinceAuditSql}
        ),
        deduped AS (
            SELECT
                source,
                message,
                severity,
                created_at,
                row_number() OVER (PARTITION BY message_hash, hour_bucket ORDER BY created_at DESC) AS rn
            FROM combined
        )";

        $count = (int) $connection->fetchOne(
            $cte . ' SELECT COUNT(*) FROM deduped WHERE rn = 1',
            $params,
            $paramTypes,
        );

        $offset = ($page - 1) * $limit;

        $rows = $connection->fetchAllAssociative(
            $cte
            . ' SELECT source, message, severity, created_at'
            . ' FROM deduped'
            . ' WHERE rn = 1'
            . ' ORDER BY created_at DESC'
            . ' LIMIT :limit OFFSET :offset',
            [...$params, 'limit' => $limit, 'offset' => $offset],
            [...$paramTypes, 'limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );

        $items = array_map(static fn (array $row): array => [
            'source' => (string) $row['source'],
            'message' => (string) $row['message'],
            'severity' => (string) $row['severity'],
            'createdAt' => (string) $row['created_at'],
        ], $rows);

        return new JsonResponse([
            'items' => $items,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $count,
                'pages' => (int) ceil($count / $limit),
            ],
        ]);
    }

    private function positiveInt(string $value, string $name): int
    {
        if (!ctype_digit($value) || (int) $value < 1) {
            throw new InvalidArgumentException("Invalid {$name}: must be a positive integer.");
        }

        return (int) $value;
    }

    private function clampLimit(int $limit): int
    {
        return min($limit, self::MAX_LIMIT);
    }
}
