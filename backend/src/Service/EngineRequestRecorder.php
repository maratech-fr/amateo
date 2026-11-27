<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Club;
use App\Entity\Schedule;
use App\Entity\Season;
use App\Enum\SchedulePlanType;
use App\Enum\ScheduleStatus;
use App\Exception\EngineRequestNotComposable;
use App\Plans\InForce;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\TagAwareCacheInterface;
use Throwable;

/**
 * Compose, pour un club, toutes les demandes que le moteur recevrait par les guichets — et seulement
 * elles —, sans rien écrire en base : le filet d'une migration (enregistrer avant, migrer, enregistrer
 * après, comparer).
 *
 * Les demandes atteignables, gardes d'ÉTAT ignorées (on veut la demande, pas l'autorisation du jour) :
 *   - `season-<s>/plan-<p>/regenerate` : plan de socle qui a une version terminée ou en échec ; la
 *     version neuve n'est pas persistée, la source est la version chargée (comme « Régénérer ») ;
 *   - `season-<s>/plan-<p>/version-<v>.generate` : socle, chaque version brouillon ou en échec ;
 *     période, la version chargée, à défaut chacune de ses versions non terminées ;
 *   - `season-<s>/plan-<p>/fill` : plan de période qui a une version chargée (« Combler ») ;
 *   - `season-<s>/placement/all`, puis `season-<s>/placement/<lundi>_<dimanche>` pour chaque semaine
 *     ISO qui porte un match à placer — seulement si la demande part (un match à placer au moins).
 * Sans socle en vigueur, aucune demande de période n'est composable (`SocleGuard`) : elles sont
 * sautées et nommées (`socle_not_in_force`), jamais tues.
 *
 * Le club est posé sur la connexion comme le font les workers ; le placement lit ses tables sans
 * filtre de club et compte sur la RLS pour le borner — d'où {@see connectionRefusal()}, à jouer
 * avant tout enregistrement. Le payload du socle est servi par le cache `cache.schedule`, qu'une
 * écriture SQL brute (une migration) n'invalide pas : le tag du club est purgé avant chaque
 * composition, et après le club pour ne pas laisser sa dernière demande dans le cache.
 * L'EntityManager est vidé après chaque club : rien d'un club ne reste pour le suivant.
 */
final readonly class EngineRequestRecorder
{
    public const string SKIP_SOCLE_NOT_IN_FORCE = 'socle_not_in_force';

    /**
     * La sonde lit, sans club posé, toutes les tables de club sous RLS (lues dans le catalogue) : elles
     * doivent paraître vides. Sauf ces tables, lisibles sans club par dérogation (lecture hybride :
     * bootstrap du club depuis les appartenances, jetons publics qui PORTENT le club —
     * `Security/RlsIsolationTest`).
     */
    private const array PROBE_EXEMPT_TABLES = ['club_user', 'coach_wish_token', 'club_invitation'];

    private const string CLUB_TABLES = <<<'SQL'
        SELECT c.relname FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
        WHERE n.nspname = 'public' AND c.relkind IN ('r', 'p') AND c.relrowsecurity
          AND EXISTS (SELECT 1 FROM pg_attribute a WHERE a.attrelid = c.oid AND a.attname = 'club_id' AND NOT a.attisdropped)
        ORDER BY c.relname
        SQL;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private EngineRequestComposer $composer,
        private MatchPlacementPayloadBuilder $placementPayloadBuilder,
        private SchedulePlanProvisioner $schedulePlanProvisioner,
        private TenantConnectionContext $tenantConnectionContext,
        #[Autowire(service: 'cache.schedule')]
        private TagAwareCacheInterface $scheduleCache,
        private InForce $inForce,
    ) {}

    /**
     * Pourquoi la sécurité par club n'est pas effective sur la connexion, ou null si elle l'est. À
     * appeler sans club posé. Sur une telle connexion, la demande de placement d'un club
     * embarquerait les données de tous les clubs de la base.
     */
    public function connectionRefusal(): ?string
    {
        $connection = $this->entityManager->getConnection();
        $role = $connection->fetchAssociative(
            'SELECT current_user AS name, rolsuper, rolbypassrls, '
            . 'CASE WHEN EXISTS (SELECT 1 FROM pg_roles WHERE rolname = \'amateo_owner\') '
            . 'THEN pg_has_role(current_user, \'amateo_owner\', \'USAGE\') ELSE false END AS owner '
            . 'FROM pg_roles WHERE rolname = current_user',
        );
        if (false === $role) {
            return 'le rôle courant de la connexion est introuvable';
        }
        $name = (string) $role['name'];
        if (true === $role['rolsuper']) {
            return \sprintf('le rôle %s est superuser : la sécurité par club ne s\'applique pas', $name);
        }
        if (true === $role['rolbypassrls']) {
            return \sprintf('le rôle %s porte l\'attribut bypassrls : la sécurité par club ne s\'applique pas', $name);
        }
        if (true === $role['owner']) {
            return \sprintf('le rôle %s est membre de amateo_owner : la sécurité par club ne s\'applique pas', $name);
        }
        foreach ($connection->fetchFirstColumn(self::CLUB_TABLES) as $table) {
            $table = (string) $table;
            if (\in_array($table, self::PROBE_EXEMPT_TABLES, true)) {
                continue;
            }
            if (false !== $connection->fetchOne(\sprintf('SELECT 1 FROM %s LIMIT 1', $connection->quoteSingleIdentifier($table)))) {
                return \sprintf('la sonde lit des lignes de la table %s sans club posé : la sécurité par club n\'est pas effective', $table);
            }
        }

        return null;
    }

    /**
     * @return array{
     *     requests: array<string, array<mixed>>,
     *     skipped: list<array{scope: string, reason: string}>,
     *     failed: list<array{request: string, reason: string}>
     * }
     */
    public function requestsOfClub(string $clubId): array
    {
        $this->tenantConnectionContext->setClubId($clubId);

        try {
            $recorded = ['requests' => [], 'skipped' => [], 'failed' => []];
            foreach ($this->seasonIdsOf($clubId) as $seasonId) {
                $this->recordPlans($clubId, $seasonId, $recorded);
                $this->recordPlacements($clubId, $seasonId, $recorded);
            }

            return $recorded;
        } finally {
            // Une purge qui échoue (Redis tombé) remonte, mais le club est relâché quand même.
            try {
                $this->purgeCache($clubId);
            } finally {
                $this->entityManager->clear();
                $this->tenantConnectionContext->clear();
            }
        }
    }

    /**
     * @param array{requests: array<string, array<mixed>>, skipped: list<array{scope: string, reason: string}>, failed: list<array{request: string, reason: string}>} $recorded
     */
    private function recordPlans(string $clubId, string $seasonId, array &$recorded): void
    {
        $plans = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT id, type FROM schedule_plan WHERE club_id = :club AND season_id = :season ORDER BY id',
            ['club' => $clubId, 'season' => $seasonId],
        );
        $socleInForce = null !== $this->inForce->socleVersionId($seasonId);

        foreach ($plans as $plan) {
            $planId = (string) $plan['id'];
            $scope = 'season-' . $seasonId . '/plan-' . $planId;
            $isSeason = SchedulePlanType::SEASON->value === $plan['type'];
            if (!$isSeason && !$socleInForce) {
                $recorded['skipped'][] = ['scope' => $scope, 'reason' => self::SKIP_SOCLE_NOT_IN_FORCE];

                continue;
            }

            $versions = $this->entityManager->getRepository(Schedule::class)->findBy(['schedulePlanId' => $planId], ['id' => 'ASC']);
            $loadedId = $this->schedulePlanProvisioner->lockSourceScheduleIdOfPlan($planId);

            if ($isSeason) {
                $regenerable = array_filter($versions, static fn (Schedule $v): bool => \in_array($v->getStatus(), [ScheduleStatus::COMPLETED, ScheduleStatus::FAILED], true));
                if ([] !== $regenerable) {
                    $this->compose($recorded, $scope . '/regenerate', $this->newVersionOf($clubId, $seasonId, $planId), $loadedId, null);
                }
                foreach ($versions as $version) {
                    if (\in_array($version->getStatus(), [ScheduleStatus::DRAFT, ScheduleStatus::FAILED], true)) {
                        $this->compose($recorded, $scope . '/version-' . $version->getId() . '.generate', $version, null, null);
                    }
                }

                continue;
            }

            $loaded = null === $loadedId ? [] : array_filter($versions, static fn (Schedule $v): bool => $v->getId() === $loadedId);
            $toGenerate = [] !== $loaded ? $loaded : array_filter($versions, static fn (Schedule $v): bool => ScheduleStatus::COMPLETED !== $v->getStatus());
            foreach ($toGenerate as $version) {
                $this->compose($recorded, $scope . '/version-' . $version->getId() . '.generate', $version, null, null);
            }
            if ([] !== $loaded) {
                $this->compose($recorded, $scope . '/fill', $this->newVersionOf($clubId, $seasonId, $planId), null, $loadedId);
            }
        }
    }

    /**
     * @param array{requests: array<string, array<mixed>>, skipped: list<array{scope: string, reason: string}>, failed: list<array{request: string, reason: string}>} $recorded
     */
    private function recordPlacements(string $clubId, string $seasonId, array &$recorded): void
    {
        $club = $this->entityManager->getRepository(Club::class)->find($clubId);
        if (!$club instanceof Club) {
            return;
        }
        $scope = 'season-' . $seasonId . '/placement';

        try {
            $all = $this->placementPayloadBuilder->build($club, $seasonId);
        } catch (Throwable $exception) {
            $recorded['failed'][] = ['request' => $scope . '/all', 'reason' => $exception::class];

            return;
        }
        if (0 === $all['toPlaceCount']) {
            return;
        }
        $recorded['requests'][$scope . '/all'] = $this->asSent($all['payload']);

        foreach ($this->weeksToPlace($all['payload']) as [$monday, $sunday]) {
            $key = $scope . '/' . $monday . '_' . $sunday;
            try {
                $week = $this->placementPayloadBuilder->build($club, $seasonId, ['from' => $monday, 'to' => $sunday]);
            } catch (Throwable $exception) {
                $recorded['failed'][] = ['request' => $key, 'reason' => $exception::class];

                continue;
            }
            if ($week['toPlaceCount'] > 0) {
                $recorded['requests'][$key] = $this->asSent($week['payload']);
            }
        }
    }

    /**
     * @param array{requests: array<string, array<mixed>>, skipped: list<array{scope: string, reason: string}>, failed: list<array{request: string, reason: string}>} $recorded
     */
    private function compose(array &$recorded, string $key, Schedule $schedule, ?string $sourceScheduleId, ?string $fillSourceScheduleId): void
    {
        $this->purgeCache($schedule->getClubId());
        try {
            $composed = $this->composer->compose($schedule, $sourceScheduleId, $fillSourceScheduleId);
        } catch (Throwable $exception) {
            $recorded['failed'][] = ['request' => $key, 'reason' => $exception instanceof EngineRequestNotComposable ? $exception->reason : $exception::class];

            return;
        }
        $recorded['requests'][$key] = $this->asSent($composed->input);
    }

    /** La version neuve que « Régénérer » ou « Combler » créerait : jamais persistée. */
    private function newVersionOf(string $clubId, string $seasonId, string $planId): Schedule
    {
        return (new Schedule)->setClubId($clubId)->setSeasonId($seasonId)->setSchedulePlanId($planId)->setStatus(ScheduleStatus::PENDING);
    }

    /**
     * Le corps tel que le moteur le reçoit : encodé comme le client HTTP l'encode, puis relu.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<mixed>
     */
    private function asSent(array $payload): array
    {
        $decoded = json_decode(json_encode($payload, \JSON_PRESERVE_ZERO_FRACTION | \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR);

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * Les semaines ISO (lundi, dimanche) qui portent un match à placer, dans l'ordre.
     *
     * @param array<string, mixed> $payload
     *
     * @return list<array{string, string}>
     */
    private function weeksToPlace(array $payload): array
    {
        $weeks = [];
        foreach (\is_array($payload['matches'] ?? null) ? $payload['matches'] : [] as $match) {
            if (!\is_array($match) || 'TO_PLACE' !== ($match['kind'] ?? null) || !\is_string($match['date'] ?? null)) {
                continue;
            }
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $match['date']);
            if (!$date instanceof DateTimeImmutable) {
                continue;
            }
            $monday = $date->modify(\sprintf('-%d days', (int) $date->format('N') - 1));
            $weeks[$monday->format('Y-m-d')] = [$monday->format('Y-m-d'), $monday->modify('+6 days')->format('Y-m-d')];
        }
        ksort($weeks);

        return array_values($weeks);
    }

    /** @return list<string> */
    private function seasonIdsOf(string $clubId): array
    {
        $seasons = $this->entityManager->getRepository(Season::class)->findBy(['clubId' => $clubId], ['id' => 'ASC']);

        return array_map(static fn (Season $season): string => $season->getId(), $seasons);
    }

    private function purgeCache(string $clubId): void
    {
        $this->scheduleCache->invalidateTags([ScheduleConstraintBuilder::cacheTag($clubId)]);
    }
}
