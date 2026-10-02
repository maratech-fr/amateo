<?php

declare(strict_types=1);

namespace App\Service;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Clock\ClockInterface;

/** Read-only cross-tenant aggregates, deliberately executed through the admin connection. */
final readonly class AdminMonitoringService
{
    private const METRICS_WINDOW_DAYS = 30;

    public function __construct(
        private ManagerRegistry $registry,
        private ClockInterface $clock,
    ) {}

    /**
     * @return array{
     *     clubs: array{total: int, active7d: int, active30d: int, new7d: int, unsubscribed: int},
     *     solver: array{windowDays: int, generations: int, completed: int, failed: int, infeasible: int, infeasibleRate: float, p50WallTimeMs: ?int, p95WallTimeMs: ?int, daily: list<array{date: string, generations: int, infeasible: int, p50WallTimeMs: ?int, p95WallTimeMs: ?int}>},
     *     usage: array{
     *         plansByType: list<array{type: string, total: int, validated: int}>,
     *         timeToFirstValidation: array{season: array{count: int, p50Minutes: ?int, p95Minutes: ?int}, period: array{count: int, p50Minutes: ?int, p95Minutes: ?int}},
     *         solverByPlanType: list<array{planType: string, generations: int, p50WallTimeMs: ?int, p95WallTimeMs: ?int}>,
     *         clubSizes: list<array{bucket: string, clubs: int, medianVenues: ?int}>
     *     }
     * }
     */
    public function overview(): array
    {
        $clubs = $this->connection()->fetchAssociative(<<<'SQL'
            SELECT
                COUNT(*) FILTER (WHERE unsubscribed_at IS NULL) AS total,
                COUNT(*) FILTER (WHERE unsubscribed_at IS NULL AND last_activity_at >= NOW() - INTERVAL '7 days') AS active_7d,
                COUNT(*) FILTER (WHERE unsubscribed_at IS NULL AND last_activity_at >= NOW() - INTERVAL '30 days') AS active_30d,
                COUNT(*) FILTER (WHERE unsubscribed_at IS NULL AND created_at >= NOW() - INTERVAL '7 days') AS new_7d,
                COUNT(*) FILTER (WHERE unsubscribed_at IS NOT NULL) AS unsubscribed
            FROM club
            -- P2-4 : un club de démonstration n'est pas un client — le compter
            -- gonflerait chaque KPI d'adoption à chaque rendez-vous commercial.
            WHERE is_demo = FALSE
            SQL);
        $solver = $this->connection()->fetchAssociative(<<<'SQL'
            SELECT
                COUNT(*) AS generations,
                COUNT(*) FILTER (WHERE status = 'COMPLETED') AS completed,
                COUNT(*) FILTER (WHERE status = 'FAILED') AS failed,
                COUNT(*) FILTER (WHERE status = 'INFEASIBLE') AS infeasible,
                percentile_cont(0.5) WITHIN GROUP (ORDER BY wall_time_ms) FILTER (WHERE wall_time_ms IS NOT NULL) AS p50,
                percentile_cont(0.95) WITHIN GROUP (ORDER BY wall_time_ms) FILTER (WHERE wall_time_ms IS NOT NULL) AS p95
            FROM solver_metrics
            WHERE created_at >= NOW() - INTERVAL '30 days'
            SQL);
        $dailyRows = $this->connection()->fetchAllAssociative(<<<'SQL'
            SELECT
                created_at::date AS day,
                COUNT(*) AS generations,
                COUNT(*) FILTER (WHERE status = 'INFEASIBLE') AS infeasible,
                percentile_cont(0.5) WITHIN GROUP (ORDER BY wall_time_ms) FILTER (WHERE wall_time_ms IS NOT NULL) AS p50,
                percentile_cont(0.95) WITHIN GROUP (ORDER BY wall_time_ms) FILTER (WHERE wall_time_ms IS NOT NULL) AS p95
            FROM solver_metrics
            WHERE created_at >= NOW() - INTERVAL '30 days'
            GROUP BY created_at::date
            ORDER BY day
            SQL);

        // --- Usage (stats fondateur 2026-07-18) : « l'app est-elle utilisée, à quel volume ? »

        // Plans par type + validés (chosen_schedule_id posé) — répond « combien de plans
        // overlay/holidays » et « combien vont jusqu'à la validation ». SÉMANTIQUE ASSUMÉE :
        // c'est le PARC ACTUEL (table vivante — un reset de saison ou un effacement RGPD
        // retire ses plans), PAS la télémétrie append-only du bloc solverByPlanType. Les
        // deux blocs peuvent donc diverger après un reset : l'un dit « ce qui existe »,
        // l'autre « ce qui s'est produit ». Voulu — ne pas les réconcilier.
        $planRows = $this->connection()->fetchAllAssociative(<<<'SQL'
            SELECT type, COUNT(*) AS total, COUNT(*) FILTER (WHERE chosen_schedule_id IS NOT NULL) AS validated
            FROM schedule_plan
            GROUP BY type
            ORDER BY type
            SQL);

        // Temps de clôture = création du plan → PREMIÈRE validation (first_chosen_at,
        // posé une fois). SEASON (le calendrier de la saison) séparé des périodes.
        $closeRows = $this->connection()->fetchAllAssociative(<<<'SQL'
            SELECT
                (type = 'SEASON') AS is_season,
                COUNT(*) AS validated,
                percentile_cont(0.5) WITHIN GROUP (ORDER BY EXTRACT(EPOCH FROM (first_chosen_at - created_at))) AS p50_seconds,
                percentile_cont(0.95) WITHIN GROUP (ORDER BY EXTRACT(EPOCH FROM (first_chosen_at - created_at))) AS p95_seconds
            FROM schedule_plan
            WHERE first_chosen_at IS NOT NULL
            GROUP BY (type = 'SEASON')
            SQL);

        // Charge solveur par type de plan (dimension dénormalisée à la capture,
        // append-only — l'historique des TENTATIVES, versions supprimées incluses).
        // plan_type null (historique pré-migration, plan disparu à la capture) est
        // regroupé en UNKNOWN plutôt qu'exclu : la somme des lignes doit égaler le
        // total du bloc solveur voisin — un écart silencieux fausserait la lecture.
        $solverTypeRows = $this->connection()->fetchAllAssociative(<<<'SQL'
            SELECT
                COALESCE(plan_type, 'UNKNOWN') AS plan_type,
                COUNT(*) AS generations,
                percentile_cont(0.5) WITHIN GROUP (ORDER BY wall_time_ms) FILTER (WHERE wall_time_ms IS NOT NULL) AS p50,
                percentile_cont(0.95) WITHIN GROUP (ORDER BY wall_time_ms) FILTER (WHERE wall_time_ms IS NOT NULL) AS p95
            FROM solver_metrics
            WHERE created_at >= NOW() - INTERVAL '30 days'
            GROUP BY COALESCE(plan_type, 'UNKNOWN')
            ORDER BY plan_type
            SQL);

        // Profil du parc : tranches d'équipes actives (saison courante) + gymnases médians —
        // « quelle taille de clubs utilise l'app ». Parc non désinscrit entier.
        $sizeRows = $this->connection()->fetchAllAssociative(<<<'SQL'
            WITH club_sizes AS (
                SELECT
                    c.id,
                    COALESCE((SELECT COUNT(*) FROM team t WHERE t.season_id = cs.id AND t.is_active = TRUE), 0) AS teams,
                    COALESCE((SELECT COUNT(*) FROM venue v WHERE v.season_id = cs.id AND v.is_active = TRUE), 0) AS venues
                FROM club c
                LEFT JOIN LATERAL (
                    -- Creux d'été : aucune saison ne contient CURRENT_DATE. Préférer alors la
                    -- saison DÉJÀ COMMENCÉE (peuplée) à la future encore vide — sinon tout le
                    -- parc tombe dans la tranche « 0 équipe » chaque début juillet.
                    SELECT s.id
                    FROM season s
                    WHERE s.club_id = c.id
                    ORDER BY (CURRENT_DATE BETWEEN s.start_date AND s.end_date) DESC, (s.start_date <= CURRENT_DATE) DESC, s.start_date DESC
                    LIMIT 1
                ) cs ON TRUE
                WHERE c.unsubscribed_at IS NULL
            )
            SELECT
                CASE
                    WHEN teams = 0 THEN '0'
                    WHEN teams <= 5 THEN '1-5'
                    WHEN teams <= 10 THEN '6-10'
                    WHEN teams <= 20 THEN '11-20'
                    WHEN teams <= 40 THEN '21-40'
                    ELSE '40+'
                END AS bucket,
                MIN(CASE WHEN teams = 0 THEN 0 WHEN teams <= 5 THEN 1 WHEN teams <= 10 THEN 2 WHEN teams <= 20 THEN 3 WHEN teams <= 40 THEN 4 ELSE 5 END) AS bucket_order,
                COUNT(*) AS clubs,
                percentile_cont(0.5) WITHIN GROUP (ORDER BY venues) AS median_venues
            FROM club_sizes
            GROUP BY 1
            ORDER BY bucket_order
            SQL);

        $clubs = false === $clubs ? [] : $clubs;
        $solver = false === $solver ? [] : $solver;
        $generations = $this->integer($solver, 'generations');

        $closeByScope = ['season' => ['count' => 0, 'p50Minutes' => null, 'p95Minutes' => null], 'period' => ['count' => 0, 'p50Minutes' => null, 'p95Minutes' => null]];
        foreach ($closeRows as $row) {
            $scope = $this->boolean($row, 'is_season') ? 'season' : 'period';
            $closeByScope[$scope] = [
                'count' => $this->integer($row, 'validated'),
                'p50Minutes' => $this->secondsToMinutes($row, 'p50_seconds'),
                'p95Minutes' => $this->secondsToMinutes($row, 'p95_seconds'),
            ];
        }

        return [
            'clubs' => [
                'total' => $this->integer($clubs, 'total'),
                'active7d' => $this->integer($clubs, 'active_7d'),
                'active30d' => $this->integer($clubs, 'active_30d'),
                'new7d' => $this->integer($clubs, 'new_7d'),
                'unsubscribed' => $this->integer($clubs, 'unsubscribed'),
            ],
            'solver' => [
                'windowDays' => self::METRICS_WINDOW_DAYS,
                'generations' => $generations,
                'completed' => $this->integer($solver, 'completed'),
                'failed' => $this->integer($solver, 'failed'),
                'infeasible' => $this->integer($solver, 'infeasible'),
                'infeasibleRate' => $this->rate($this->integer($solver, 'infeasible'), $generations),
                'p50WallTimeMs' => $this->nullableInteger($solver, 'p50'),
                'p95WallTimeMs' => $this->nullableInteger($solver, 'p95'),
                'daily' => array_map(fn (array $row): array => [
                    'date' => (string) $row['day'],
                    'generations' => $this->integer($row, 'generations'),
                    'infeasible' => $this->integer($row, 'infeasible'),
                    'p50WallTimeMs' => $this->nullableInteger($row, 'p50'),
                    'p95WallTimeMs' => $this->nullableInteger($row, 'p95'),
                ], $dailyRows),
            ],
            'usage' => [
                'plansByType' => array_map(fn (array $row): array => [
                    'type' => (string) $row['type'],
                    'total' => $this->integer($row, 'total'),
                    'validated' => $this->integer($row, 'validated'),
                ], $planRows),
                'timeToFirstValidation' => $closeByScope,
                'solverByPlanType' => array_map(fn (array $row): array => [
                    'planType' => (string) $row['plan_type'],
                    'generations' => $this->integer($row, 'generations'),
                    'p50WallTimeMs' => $this->nullableInteger($row, 'p50'),
                    'p95WallTimeMs' => $this->nullableInteger($row, 'p95'),
                ], $solverTypeRows),
                'clubSizes' => array_map(fn (array $row): array => [
                    'bucket' => (string) $row['bucket'],
                    'clubs' => $this->integer($row, 'clubs'),
                    'medianVenues' => $this->nullableInteger($row, 'median_venues'),
                ], $sizeRows),
            ],
        ];
    }

    /**
     * @return array{items: list<array<string, bool|float|int|string|array<string, int|string|null>|null>>, pagination: array{page: int, limit: int, total: int, pages: int}, metricsWindowDays: int}
     */
    public function clubs(int $page, int $limit, ?string $query): array
    {
        $query = null !== $query && '' !== trim($query) ? trim($query) : null;
        $where = null === $query ? '' : 'WHERE c.name ILIKE :query OR c.slug ILIKE :query OR c.ffbb_club_code ILIKE :query';
        $parameters = null === $query ? [] : ['query' => '%' . $query . '%'];
        $total = (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM club c ' . $where, $parameters);
        $parameters['limit'] = $limit;
        $parameters['offset'] = ($page - 1) * $limit;

        $rows = $this->connection()->fetchAllAssociative(<<<SQL
            WITH selected_clubs AS (
                SELECT c.*
                FROM club c
                $where
                ORDER BY COALESCE(c.last_activity_at, c.created_at) DESC, c.id
                LIMIT :limit OFFSET :offset
            )
            SELECT
                c.id,
                c.name,
                c.slug,
                c.ffbb_club_code,
                c.is_demo,
                c.paid_season_year,
                c.billing_cycle,
                c.generation_count_season,
                c.created_at,
                c.last_activity_at,
                c.unsubscribed_at,
                stored_plan.code AS plan_code,
                stored_plan.name AS plan_name,
                current_season.id AS season_id,
                current_season.name AS season_name,
                current_season.status AS season_status,
                current_season.start_date AS season_start_date,
                COALESCE(volumes.teams, 0) AS teams,
                COALESCE(volumes.venues, 0) AS venues,
                COALESCE(volumes.coaches, 0) AS coaches,
                COALESCE(volumes.constraints, 0) AS constraints,
                COALESCE(metric_agg.generations, 0) AS generations,
                COALESCE(metric_agg.infeasible, 0) AS infeasible,
                metric_agg.p50,
                metric_agg.p95,
                latest_metric.status AS latest_status,
                latest_metric.created_at AS latest_created_at
            FROM selected_clubs c
            LEFT JOIN subscription_plan stored_plan ON stored_plan.id = c.plan_id
            LEFT JOIN LATERAL (
                -- Même règle que clubSizes : dans le creux d'été, la saison déjà commencée
                -- (peuplée) plutôt que la future vide.
                SELECT s.id, s.name, s.status, s.start_date
                FROM season s
                WHERE s.club_id = c.id
                ORDER BY (CURRENT_DATE BETWEEN s.start_date AND s.end_date) DESC, (s.start_date <= CURRENT_DATE) DESC, s.start_date DESC
                LIMIT 1
            ) current_season ON TRUE
            LEFT JOIN LATERAL (
                SELECT
                    (SELECT COUNT(*) FROM team t WHERE t.season_id = current_season.id AND t.is_active = TRUE) AS teams,
                    (SELECT COUNT(*) FROM venue v WHERE v.season_id = current_season.id AND v.is_active = TRUE) AS venues,
                    (SELECT COUNT(*) FROM coach co WHERE co.season_id = current_season.id AND co.is_active = TRUE) AS coaches,
                    (SELECT COUNT(*) FROM "constraint" cn WHERE cn.season_id = current_season.id AND cn.is_active = TRUE) AS constraints
            ) volumes ON current_season.id IS NOT NULL
            LEFT JOIN LATERAL (
                SELECT
                    COUNT(*) AS generations,
                    COUNT(*) FILTER (WHERE sm.status = 'INFEASIBLE') AS infeasible,
                    percentile_cont(0.5) WITHIN GROUP (ORDER BY sm.wall_time_ms) FILTER (WHERE sm.wall_time_ms IS NOT NULL) AS p50,
                    percentile_cont(0.95) WITHIN GROUP (ORDER BY sm.wall_time_ms) FILTER (WHERE sm.wall_time_ms IS NOT NULL) AS p95
                FROM solver_metrics sm
                WHERE sm.club_id = c.id AND sm.created_at >= NOW() - INTERVAL '30 days'
            ) metric_agg ON TRUE
            LEFT JOIN LATERAL (
                SELECT sm.status, sm.created_at
                FROM solver_metrics sm
                WHERE sm.club_id = c.id
                ORDER BY sm.created_at DESC
                LIMIT 1
            ) latest_metric ON TRUE
            ORDER BY COALESCE(c.last_activity_at, c.created_at) DESC, c.id
            SQL, $parameters);

        // Le socle Découverte, lu UNE fois (pas de requête par ligne) : c'est le nom affiché
        // quand l'offre effective d'un club retombe sur Découverte (absente ou expirée).
        $decouverteRow = $this->connection()->fetchAssociative('SELECT code, name FROM subscription_plan WHERE code = \'decouverte\'');
        $decouverte = false === $decouverteRow
            ? ['code' => 'decouverte', 'name' => 'Découverte']
            : ['code' => (string) $decouverteRow['code'], 'name' => (string) $decouverteRow['name']];

        return [
            'items' => array_map(fn (array $row): array => $this->club($row, $decouverte), $rows),
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => 0 === $total ? 0 : (int) ceil($total / $limit),
            ],
            'metricsWindowDays' => self::METRICS_WINDOW_DAYS,
        ];
    }

    /**
     * @param array<string, mixed>              $row
     * @param array{code: string, name: string} $decouverte socle Découverte (nom affiché en repli)
     *
     * @return array<string, mixed>
     */
    private function club(array $row, array $decouverte): array
    {
        $generations = $this->integer($row, 'generations');
        $infeasible = $this->integer($row, 'infeasible');
        $storedCode = $this->nullableString($row, 'plan_code');
        $storedName = $this->nullableString($row, 'plan_name');

        return [
            'id' => (string) $row['id'],
            'name' => (string) $row['name'],
            'slug' => (string) $row['slug'],
            'ffbbClubCode' => $this->nullableString($row, 'ffbb_club_code'),
            'isDemo' => (bool) ($row['is_demo'] ?? false),
            // Offre STOCKÉE (plan_id résolu) vs paidSeasonYear vs offre EFFECTIVE calculée
            // ci-dessous — la console affiche l'effective, jamais un binaire sur le stocké.
            'plan' => null === $storedCode ? null : ['code' => $storedCode, 'name' => (string) $storedName],
            'paidSeasonYear' => $this->nullableInteger($row, 'paid_season_year'),
            'effectivePlan' => $this->effectivePlan($row, $storedCode, $storedName, $decouverte),
            'billingCycle' => $this->nullableString($row, 'billing_cycle'),
            'generationCountSeason' => $this->integer($row, 'generation_count_season'),
            'createdAt' => (string) $row['created_at'],
            'lastActivityAt' => $this->nullableString($row, 'last_activity_at'),
            'unsubscribed' => null !== $row['unsubscribed_at'],
            'currentSeason' => null === $row['season_id'] ? null : [
                'id' => (string) $row['season_id'],
                'name' => (string) $row['season_name'],
                'status' => (string) $row['season_status'],
            ],
            'volumes' => [
                'teams' => $this->integer($row, 'teams'),
                'venues' => $this->integer($row, 'venues'),
                'coaches' => $this->integer($row, 'coaches'),
                'constraints' => $this->integer($row, 'constraints'),
            ],
            'solver' => [
                'generations' => $generations,
                'infeasible' => $infeasible,
                'infeasibleRate' => $this->rate($infeasible, $generations),
                'p50WallTimeMs' => $this->nullableInteger($row, 'p50'),
                'p95WallTimeMs' => $this->nullableInteger($row, 'p95'),
                'latestStatus' => $this->nullableString($row, 'latest_status'),
                'latestAt' => $this->nullableString($row, 'latest_created_at'),
            ],
        ];
    }

    /**
     * L'offre EFFECTIVE d'une ligne, via la MAISON UNIQUE `PlanEntitlements::effectivePlanCode`
     * (miroir SQL interdit — la règle pivot vit en un seul endroit ; `AdminMonitoringClubsPlanTest`
     * casse si les deux divergent). Année-pivot depuis la saison courante ; club SANS saison → pivot
     * sur l'horloge réelle (D1). AUCUN cas démo (D2) : le badge dit la vérité comptable, le chip
     * « Démo » voisin porte déjà « droits pleins ».
     *
     * @param array<string, mixed>              $row
     * @param array{code: string, name: string} $decouverte
     *
     * @return array{code: string, name: string}
     */
    private function effectivePlan(array $row, ?string $storedCode, ?string $storedName, array $decouverte): array
    {
        $seasonStart = $this->nullableString($row, 'season_start_date');
        $pivotDate = null === $seasonStart ? $this->clock->now() : new DateTimeImmutable($seasonStart);
        $pivotYear = SeasonResolver::seasonYear($pivotDate);

        $effectiveCode = PlanEntitlements::effectivePlanCode($storedCode, $this->nullableInteger($row, 'paid_season_year'), $pivotYear);

        return null !== $storedCode && $effectiveCode === $storedCode
            ? ['code' => $storedCode, 'name' => (string) $storedName]
            : $decouverte;
    }

    private function connection(): Connection
    {
        $connection = $this->registry->getConnection('admin');
        \assert($connection instanceof Connection);

        return $connection;
    }

    /** @param array<string, mixed> $row */
    private function integer(array $row, string $key): int
    {
        return (int) ($row[$key] ?? 0);
    }

    /** @param array<string, mixed> $row */
    private function nullableInteger(array $row, string $key): ?int
    {
        $value = $row[$key] ?? null;

        return null === $value ? null : (int) round((float) $value);
    }

    /** @param array<string, mixed> $row */
    private function nullableString(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;

        return null === $value ? null : (string) $value;
    }

    private function rate(int $count, int $total): float
    {
        return 0 === $total ? 0.0 : round($count / $total, 4);
    }

    /** @param array<string, mixed> $row */
    private function boolean(array $row, string $key): bool
    {
        return filter_var($row[$key] ?? false, \FILTER_VALIDATE_BOOL);
    }

    /** @param array<string, mixed> $row Percentile en secondes → MINUTES arrondies : une
     *  clôture de période en 20 min est routinière, l'arrondir à « 0 h » la faisait
     *  disparaître. Le front choisit l'unité d'affichage (min/h/j). */
    private function secondsToMinutes(array $row, string $key): ?int
    {
        $value = $row[$key] ?? null;

        return null === $value ? null : (int) round(((float) $value) / 60);
    }
}
