<?php

declare(strict_types=1);

namespace App\Plans;

use App\Entity\Constraint;
use App\Entity\PlanConstraint;
use App\Entity\PlanTeam;
use App\Entity\PlanVenue;
use App\Entity\Schedule;
use App\Entity\Team;
use App\Entity\TeamCoach;
use App\Entity\Venue;
use App\Entity\VenueTravelRuleSetting;
use App\Enum\SchedulePlanType;
use App\Service\CoachWishCampaignTokenSync;
use App\Service\ScheduleConstraintBuilder;
use App\Structure\Family;
use App\Structure\FamilyCatalog;
use App\Structure\Level;
use App\Structure\OnDelete;
use BackedEnum;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

/**
 * La copie du froid d'une version (lot 5), maison unique : `capture` lit l'état froid d'un plan (les familles que
 * {@see FamilyCatalog::coldOf} rend pour sa nature, leurs seuls champs froids), `store` l'écrit pour une version,
 * `of` la relit. Lecture et écriture en SQL direct, hors UnitOfWork : la copie dit ce qui est en base, jamais une
 * entité modifiée en mémoire, et son écriture ne ferme pas le gestionnaire d'entités.
 *
 * Toute version terminée porte sa copie, écrite à sa naissance (génération de saison ou de période, transcription
 * du socle, copie d'un plan sœur) ; une écriture qui échoue lève, et la naissance échoue avec elle. Une version sans
 * copie n'est jamais lue comme vide : {@see self::of} lève.
 *
 * « Charger » une version, c'est {@see self::restore} sa copie ; une retouche de planning qui écrit des lignes du plan
 * les reporte dans la copie de la version chargée ({@see self::syncRows}).
 */
final class PlanStates
{
    /** Identité, club, saison, plan et horodatage : jamais du froid, même pour une famille toute froide. */
    private const array NEVER_COLD = ['id', 'version', 'createdAt', 'updatedAt', 'clubId', 'seasonId', 'schedulePlanId'];

    /** Les identités du club : « Charger » remet leurs champs froids, sans jamais en créer ni en supprimer une. */
    private const array UPDATED_ONLY = [Team::class, Venue::class];

    /**
     * Une ligne par équipe, gymnase ou règle dans chaque plan : absente de la copie, elle appartient à ce qui est né
     * depuis la version, et « Charger » la garde.
     */
    private const array NEVER_REMOVED = [PlanTeam::class, PlanVenue::class, PlanConstraint::class];

    /**
     * Le nom d'une règle que « Charger » fait renaître : la copie ne porte pas le nom (libre), la règle reçoit celui de
     * sa nature.
     */
    private const array RULE_NAMES = [
        'TIME' => 'Règle d\'horaire',
        'DAY' => 'Règle de jours',
        'FACILITY' => 'Règle de gymnase',
        'COACH_AVAILABILITY' => 'Disponibilité de coach',
        'SEASON_SLOTS' => 'Garder ses créneaux de saison',
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FamilyCatalog $catalog,
        private readonly PlanComposition $planComposition,
        private readonly CoachWishCampaignTokenSync $coachWishTokenSync,
        #[Autowire(service: 'cache.schedule')]
        private readonly TagAwareCacheInterface $scheduleCache,
    ) {}

    /** L'état froid du plan, tel qu'en base à cet instant. */
    public function capture(string $planId): PlanState
    {
        $plan = $this->entityManager->getConnection()->fetchAssociative(
            'SELECT club_id, season_id, type FROM schedule_plan WHERE id = ?',
            [$planId],
        );
        if (false === $plan) {
            throw new RuntimeException(\sprintf('Le plan %s est introuvable : son état ne se copie pas.', $planId));
        }

        $families = [];
        foreach ($this->catalog->coldOf(SchedulePlanType::from((string) $plan['type'])) as $class) {
            $family = $this->catalog->of($class);
            $where = Level::PLAN === $family->level
                ? ['schedulePlanId' => $planId]
                : ['clubId' => (string) $plan['club_id'], 'seasonId' => (string) $plan['season_id']] + $family->coldRows;
            $families[$this->familyKey($class)] = $this->rows($class, $family, $where);
        }
        ksort($families);

        return new PlanState($families);
    }

    /** Écrit la copie de la version (la remplace si elle existe) ; lève si l'écriture échoue. */
    public function store(Schedule $version, PlanState $state): void
    {
        $now = new DateTimeImmutable;
        $this->entityManager->getConnection()->executeStatement(
            'INSERT INTO schedule_state (id, created_at, updated_at, club_id, season_id, schedule_id, data)
             VALUES (gen_random_uuid(), :now, :now, :clubId, :seasonId, :scheduleId, :data)
             ON CONFLICT (schedule_id) DO UPDATE SET data = EXCLUDED.data, updated_at = EXCLUDED.updated_at',
            [
                'now' => $now->format(\DATE_ATOM),
                'clubId' => $version->getClubId(),
                'seasonId' => $version->getSeasonId(),
                'scheduleId' => $version->getId(),
                'data' => json_encode($state->toArray(), \JSON_THROW_ON_ERROR),
            ],
        );
    }

    /** La version porte-t-elle une copie de son état ? */
    public function has(string $scheduleId): bool
    {
        return false !== $this->entityManager->getConnection()->fetchOne('SELECT 1 FROM schedule_state WHERE schedule_id = ?', [$scheduleId]);
    }

    /**
     * « Charger » (lot 5) : remet l'état de travail du plan d'après la copie. Les lignes absentes de la copie sont
     * retirées, les présentes mises à jour sur leurs seuls champs froids, les manquantes insérées. Une copie de
     * période n'écrit que dans son plan (jamais le froid du socle, ni la règle de trajet de la saison) ; une copie du
     * socle remet aussi le froid du socle et celui des équipes et des gymnases existants (rang, catégorie, niveau,
     * « divisible »…), sans créer ni supprimer d'équipe ou de gymnase, sans toucher aucun nom.
     *
     * Les références disparues depuis sont ôtées d'abord ({@see self::withoutDeadReferences}). Une ligne retirée
     * emporte ce qui meurt avec elle (les lignes de règle d'une règle retirée) ; une séance d'une version, elle, ne
     * meurt jamais d'un chargement : la séance d'un groupe retiré reste à sa place, sans son lien de groupe. Une règle
     * qui renaît sans ligne de période en reçoit une au défaut. Tout écrit en SQL, borné au club du plan, dans une
     * transaction ; le payload solveur du club est purgé.
     */
    public function restore(string $planId, PlanState $state): void
    {
        $plan = $this->planOf($planId);
        $type = SchedulePlanType::from($plan['type']);
        $families = $this->withoutDeadReferences($planId, $state)->toArray();
        $live = $this->capture($planId)->toArray();

        $connection = $this->entityManager->getConnection();
        $teamCoachesChanged = $connection->transactional(function () use ($plan, $planId, $type, $families, $live): bool {
            $teamCoachesChanged = false;
            foreach ($this->catalog->coldOf($type) as $class) {
                $family = $this->catalog->of($class);
                if (SchedulePlanType::SEASON !== $type && Level::PLAN !== $family->level) {
                    continue;
                }
                $key = $this->familyKey($class);
                if (!\array_key_exists($key, $families)) {
                    continue;
                }
                $changed = $this->restoreFamily($class, $family, $plan, $planId, $families[$key], $live[$key] ?? []);
                $teamCoachesChanged = $teamCoachesChanged || ($changed && TeamCoach::class === $class);
            }

            return $teamCoachesChanged;
        });

        // Une règle ramenée reçoit, dans chaque plan de période, sa ligne au défaut ; rien d'existant ne change.
        $this->planComposition->ensureLines($plan['season_id']);
        if ($teamCoachesChanged) {
            // La règle du destinataire des collectes ouvertes suit le coach principal, comme à toute écriture d'un lien.
            $this->coachWishTokenSync->syncSeason($plan['club_id'], $plan['season_id']);
        }
        $this->scheduleCache->invalidateTags([ScheduleConstraintBuilder::cacheTag($plan['club_id'])]);
    }

    /**
     * La copie sans ce qui a disparu depuis : une ligne dont l'équipe, le gymnase ou le coach (ou toute cible dont elle
     * meurt) n'existe plus dans le club est ôtée ; un champ qui désigne un gymnase ou une catégorie disparus prend la
     * valeur de l'état de travail (il n'est pas remis, et ne compte pas dans la différence de deux copies). Une
     * équipe ou un gymnase de la copie supprimé depuis est ôté. Une cible que la copie porte elle-même (un groupe, une
     * règle) est vivante si la copie la garde.
     */
    public function withoutDeadReferences(string $planId, PlanState $state): PlanState
    {
        $plan = $this->planOf($planId);
        $families = $state->toArray();
        $live = $this->capture($planId)->toArray();
        $classes = $this->classesByKey();
        $liveIds = [];

        do {
            $changed = false;
            foreach ($families as $key => $rows) {
                $class = $classes[$key] ?? null;
                if (null === $class) {
                    continue;
                }
                $references = [];
                foreach ($this->catalog->referencesFrom($class) as $reference) {
                    $references[$reference['field']][] = $reference;
                }
                foreach ($rows as $rowId => $row) {
                    $rowId = (string) $rowId;
                    if (\in_array($class, self::UPDATED_ONLY, true) && !isset($this->liveIdsOf($class, $plan, $liveIds)[$rowId])) {
                        unset($families[$key][$rowId]);
                        $changed = true;

                        continue;
                    }
                    foreach ($references as $field => $targets) {
                        $value = $row[$field] ?? null;
                        if (!\is_string($value) || $this->isAlive($value, $targets, $families, $plan, $liveIds)) {
                            continue;
                        }
                        if (\in_array($targets[0]['onDelete'], [OnDelete::CLEAR, OnDelete::KEEP], true)) {
                            $current = $live[$key][$rowId][$field] ?? null;
                            if ($current !== $value) {
                                $families[$key][$rowId][$field] = $current;
                                $changed = true;
                            }

                            continue;
                        }
                        unset($families[$key][$rowId]);
                        $changed = true;

                        continue 2;
                    }
                }
            }
        } while ($changed);

        return new PlanState($families);
    }

    /**
     * Une retouche de planning a écrit ces lignes du plan : la copie de la version chargée suit, ligne par ligne —
     * une ligne vivante y est écrite telle qu'en base, une ligne disparue en est retirée. Rien d'autre de la copie ne
     * bouge (une saisie faite ailleurs et jamais générée n'y entre pas). Une version sans copie n'a rien à suivre.
     *
     * @param class-string $familyClass
     * @param list<string> $rowIds
     */
    public function syncRows(Schedule $loaded, string $familyClass, array $rowIds): void
    {
        if ([] === $rowIds || !$this->has($loaded->getId())) {
            return;
        }
        $families = $this->of($loaded->getId())->toArray();
        $key = $this->familyKey($familyClass);
        if (!\array_key_exists($key, $families)) {
            return;
        }
        $family = $this->catalog->of($familyClass);
        $where = Level::PLAN === $family->level
            ? ['schedulePlanId' => $loaded->getSchedulePlanId()]
            : ['clubId' => $loaded->getClubId(), 'seasonId' => $loaded->getSeasonId()] + $family->coldRows;
        $current = $this->rows($familyClass, $family, $where, $rowIds);
        foreach ($rowIds as $rowId) {
            if (isset($current[$rowId])) {
                $families[$key][$rowId] = $current[$rowId];
            } else {
                unset($families[$key][$rowId]);
            }
        }
        ksort($families[$key], \SORT_STRING);
        $this->store($loaded, new PlanState($families));
    }

    /** La copie de la version ; lève si elle n'en a pas (jamais un état vide). */
    public function of(string $scheduleId): PlanState
    {
        $data = $this->entityManager->getConnection()->fetchOne('SELECT data FROM schedule_state WHERE schedule_id = ?', [$scheduleId]);
        if (!\is_string($data)) {
            throw new LogicException(\sprintf('La version %s n\'a pas de copie de son état.', $scheduleId));
        }
        $decoded = json_decode($data, true, 512, \JSON_THROW_ON_ERROR);
        if (!\is_array($decoded)) {
            throw new LogicException(\sprintf('La copie de la version %s est illisible.', $scheduleId));
        }

        /* @var array<string, array<string, array<string, mixed>>> $decoded */
        return new PlanState($decoded);
    }

    /**
     * La règle de trajet que porte la copie (ses défauts si la saison n'en réglait aucune), dans la forme de
     * {@see ScheduleConstraintBuilder::fingerprint}. Lève si la copie ne porte pas la famille.
     *
     * @return array{defaultMinutes: int, intensity: string, toleranceMinutes: int}
     */
    public function travelRuleIn(PlanState $state): array
    {
        $families = $state->toArray();
        $key = $this->familyKey(VenueTravelRuleSetting::class);
        if (!\array_key_exists($key, $families)) {
            throw new LogicException('La copie ne porte pas la règle de trajet.');
        }
        $rows = array_values($families[$key]);
        if ([] === $rows) {
            return ScheduleConstraintBuilder::travelRuleValues(new VenueTravelRuleSetting);
        }

        return [
            'defaultMinutes' => (int) $rows[0]['defaultMinutes'],
            'intensity' => (string) $rows[0]['intensity'],
            'toleranceMinutes' => (int) $rows[0]['toleranceMinutes'],
        ];
    }

    /**
     * La règle de trajet qu'a vue la version, lue dans sa copie du froid ; null si la version n'a pas de copie
     * (version échouée, ou ni validée ni chargée née avant le lot) : {@see ScheduleConstraintBuilder::fingerprint}
     * la compte alors aux défauts.
     *
     * @return array{defaultMinutes: int, intensity: string, toleranceMinutes: int}|null
     */
    public function travelRuleOfVersion(string $scheduleId): ?array
    {
        $exists = $this->entityManager->getConnection()->fetchOne('SELECT 1 FROM schedule_state WHERE schedule_id = ?', [$scheduleId]);

        return false === $exists ? null : $this->travelRuleIn($this->of($scheduleId));
    }

    /**
     * Les lignes froides de la famille qui répondent au filtre, par id, leurs seuls champs froids.
     *
     * @param class-string         $class
     * @param array<string, mixed> $where champ => valeur (null : vide)
     * @param list<string>|null    $ids   les seules lignes voulues (null : toutes)
     *
     * @return array<string, array<string, mixed>>
     */
    private function rows(string $class, Family $family, array $where, ?array $ids = null): array
    {
        $connection = $this->entityManager->getConnection();
        $platform = $connection->getDatabasePlatform();
        $metadata = $this->entityManager->getClassMetadata($class);
        $quote = $this->entityManager->getConfiguration()->getQuoteStrategy();

        $fields = $this->coldFields($class, $family);

        $columns = [$quote->getColumnName('id', $metadata, $platform) . ' AS row_id'];
        foreach ($fields as $index => $field) {
            $columns[] = $quote->getColumnName($field, $metadata, $platform) . ' AS f' . $index;
        }
        $conditions = [];
        $parameters = [];
        foreach ($where as $field => $value) {
            $column = $quote->getColumnName($field, $metadata, $platform);
            if (null === $value) {
                $conditions[] = $column . ' IS NULL';

                continue;
            }
            $conditions[] = $column . ' = ?';
            $parameters[] = $value instanceof BackedEnum ? $value->value : $value;
        }
        if (null !== $ids) {
            if ([] === $ids) {
                return [];
            }
            $conditions[] = $quote->getColumnName('id', $metadata, $platform) . ' IN (' . implode(', ', array_fill(0, \count($ids), '?')) . ')';
            array_push($parameters, ...$ids);
        }

        $sql = \sprintf(
            'SELECT %s FROM %s WHERE %s ORDER BY row_id',
            implode(', ', $columns),
            $quote->getTableName($metadata, $platform),
            implode(' AND ', $conditions),
        );

        $rows = [];
        foreach ($connection->fetchAllAssociative($sql, $parameters) as $raw) {
            $row = [];
            foreach ($fields as $index => $field) {
                $type = (string) $metadata->getTypeOfField($field);
                $row[$field] = $this->normalized(Type::getType($type)->convertToPHPValue($raw['f' . $index], $platform), $type);
            }
            $rows[(string) $raw['row_id']] = $row;
        }

        return $rows;
    }

    /**
     * Les champs froids de la famille, triés.
     *
     * @param class-string $class
     *
     * @return list<string>
     */
    private function coldFields(string $class, Family $family): array
    {
        $fields = Family::ALL === $family->cold
            ? array_values(array_diff($this->entityManager->getClassMetadata($class)->getFieldNames(), self::NEVER_COLD))
            : ($family->cold ?? []);
        sort($fields);

        return $fields;
    }

    /** @return array{club_id: string, season_id: string, type: string} */
    private function planOf(string $planId): array
    {
        $plan = $this->entityManager->getConnection()->fetchAssociative('SELECT club_id, season_id, type FROM schedule_plan WHERE id = ?', [$planId]);
        if (false === $plan) {
            throw new RuntimeException(\sprintf('Le plan %s est introuvable : son état ne se remet pas.', $planId));
        }

        return ['club_id' => (string) $plan['club_id'], 'season_id' => (string) $plan['season_id'], 'type' => (string) $plan['type']];
    }

    /** @return array<string, class-string> nom court => classe, pour toute famille qu'une copie peut porter */
    private function classesByKey(): array
    {
        $classes = [];
        foreach ($this->catalog->coldOf(SchedulePlanType::SEASON) as $class) {
            $classes[$this->familyKey($class)] = $class;
        }

        return $classes;
    }

    /**
     * Les identifiants vivants de la famille dans le club du plan (et sa saison, pour une famille de saison).
     *
     * @param class-string                                            $class
     * @param array{club_id: string, season_id: string, type: string} $plan
     * @param array<string, array<string, true>>                      $cache
     *
     * @return array<string, true>
     */
    private function liveIdsOf(string $class, array $plan, array &$cache): array
    {
        if (isset($cache[$class])) {
            return $cache[$class];
        }
        [$table, $conditions, $parameters] = $this->tenantScope($class, $plan);
        $ids = $this->entityManager->getConnection()->fetchFirstColumn(\sprintf('SELECT id FROM %s WHERE %s', $table, implode(' AND ', $conditions)), $parameters);

        return $cache[$class] = array_fill_keys(array_map(strval(...), $ids), true);
    }

    /**
     * @param list<array{field: string, family: class-string, onDelete: OnDelete}> $targets  les familles qu'une colonne peut désigner
     * @param array<string, array<string, array<string, mixed>>>                   $families
     * @param array{club_id: string, season_id: string, type: string}              $plan
     * @param array<string, array<string, true>>                                   $liveIds
     */
    private function isAlive(string $value, array $targets, array $families, array $plan, array &$liveIds): bool
    {
        foreach ($targets as $target) {
            $key = $this->familyKey($target['family']);
            if (isset($families[$key]) && !\in_array($target['family'], self::UPDATED_ONLY, true)) {
                if (isset($families[$key][$value])) {
                    return true;
                }

                continue;
            }
            if (isset($this->liveIdsOf($target['family'], $plan, $liveIds)[$value])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Remet une famille : retire, met à jour, insère. Rend vrai si une ligne a changé.
     *
     * @param class-string                                            $class
     * @param array{club_id: string, season_id: string, type: string} $plan
     * @param array<string, array<string, mixed>>                     $copy
     * @param array<string, array<string, mixed>>                     $current
     */
    private function restoreFamily(string $class, Family $family, array $plan, string $planId, array $copy, array $current): bool
    {
        $changed = false;
        $updatedOnly = \in_array($class, self::UPDATED_ONLY, true);
        if (!$updatedOnly && !\in_array($class, self::NEVER_REMOVED, true)) {
            $gone = array_map(strval(...), array_keys(array_diff_key($current, $copy)));
            if ([] !== $gone) {
                $this->releaseReferencesTo($class, $gone, $plan['club_id']);
                $this->deleteRows($class, $gone, $plan['club_id']);
                $changed = true;
            }
        }

        // Une ligne de la copie que l'état de travail ne lit pas (hors de son filtre) mais qui existe : mise à jour.
        $existing = $this->existingIds($class, $family, $plan, $planId, array_map(strval(...), array_keys(array_diff_key($copy, $current))));
        foreach ($copy as $rowId => $row) {
            $rowId = (string) $rowId;
            if (isset($current[$rowId])) {
                if ($current[$rowId] !== $row) {
                    $this->updateRow($class, $family, $plan, $planId, $rowId, $row);
                    $changed = true;
                }

                continue;
            }
            if (isset($existing[$rowId])) {
                $this->updateRow($class, $family, $plan, $planId, $rowId, $row);
                $changed = true;

                continue;
            }
            if (!$updatedOnly) {
                $this->insertRow($class, $family, $plan, $planId, $rowId, $row);
                $changed = true;
            }
        }

        return $changed;
    }

    /**
     * Ce qui désigne les lignes retirées : une ligne qui meurt avec elles part (les lignes de règle d'une règle), une
     * colonne effaçable est vidée. La séance d'une version ne meurt jamais d'un chargement — sa place appartient à la
     * version : elle perd seulement son lien.
     *
     * @param class-string $class
     * @param list<string> $ids
     */
    private function releaseReferencesTo(string $class, array $ids, string $clubId): void
    {
        $connection = $this->entityManager->getConnection();
        $platform = $connection->getDatabasePlatform();
        $quote = $this->entityManager->getConfiguration()->getQuoteStrategy();
        foreach ($this->catalog->referencesTo($class) as $reference) {
            if ($reference['class'] === $class) {
                continue;
            }
            $metadata = $this->entityManager->getClassMetadata($reference['class']);
            $table = $quote->getTableName($metadata, $platform);
            $column = $quote->getColumnName($reference['field'], $metadata, $platform);
            $conditions = [$column . ' IN (' . implode(', ', array_fill(0, \count($ids), '?')) . ')'];
            $parameters = $ids;
            if ($metadata->hasField('clubId')) {
                $conditions[] = $quote->getColumnName('clubId', $metadata, $platform) . ' = ?';
                $parameters[] = $clubId;
            }
            if (Level::VERSION === $this->catalog->of($reference['class'])->level || OnDelete::CLEAR === $reference['onDelete']) {
                $connection->executeStatement(\sprintf('UPDATE %s SET %s = NULL WHERE %s', $table, $column, implode(' AND ', $conditions)), $parameters);
            } elseif (OnDelete::DELETE === $reference['onDelete']) {
                $connection->executeStatement(\sprintf('DELETE FROM %s WHERE %s', $table, implode(' AND ', $conditions)), $parameters);
            }
        }
    }

    /**
     * @param class-string $class
     * @param list<string> $ids
     */
    private function deleteRows(string $class, array $ids, string $clubId): void
    {
        $metadata = $this->entityManager->getClassMetadata($class);
        $platform = $this->entityManager->getConnection()->getDatabasePlatform();
        $quote = $this->entityManager->getConfiguration()->getQuoteStrategy();
        $this->entityManager->getConnection()->executeStatement(
            \sprintf(
                'DELETE FROM %s WHERE %s = ? AND %s IN (%s)',
                $quote->getTableName($metadata, $platform),
                $quote->getColumnName('clubId', $metadata, $platform),
                $quote->getColumnName('id', $metadata, $platform),
                implode(', ', array_fill(0, \count($ids), '?')),
            ),
            [$clubId, ...$ids],
        );
    }

    /**
     * Parmi ces lignes, celles qui existent dans le club (et le plan, pour une famille de plan).
     *
     * @param class-string                                            $class
     * @param array{club_id: string, season_id: string, type: string} $plan
     * @param list<string>                                            $ids
     *
     * @return array<string, true>
     */
    private function existingIds(string $class, Family $family, array $plan, string $planId, array $ids): array
    {
        if ([] === $ids) {
            return [];
        }
        [$table, $conditions, $parameters] = $this->tenantScope($class, $plan, Level::PLAN === $family->level ? $planId : null);
        $conditions[] = 'id IN (' . implode(', ', array_fill(0, \count($ids), '?')) . ')';
        $found = $this->entityManager->getConnection()->fetchFirstColumn(\sprintf('SELECT id FROM %s WHERE %s', $table, implode(' AND ', $conditions)), [...$parameters, ...$ids]);

        return array_fill_keys(array_map(strval(...), $found), true);
    }

    /**
     * La table de la famille et ses conditions de club, de saison (famille de saison) et de plan (si donné).
     *
     * @param class-string                                            $class
     * @param array{club_id: string, season_id: string, type: string} $plan
     *
     * @return array{0: string, 1: list<string>, 2: list<string>}
     */
    private function tenantScope(string $class, array $plan, ?string $planId = null): array
    {
        $metadata = $this->entityManager->getClassMetadata($class);
        $platform = $this->entityManager->getConnection()->getDatabasePlatform();
        $quote = $this->entityManager->getConfiguration()->getQuoteStrategy();
        $conditions = ['1 = 1'];
        $parameters = [];
        foreach (['clubId' => $plan['club_id'], 'seasonId' => $plan['season_id'], 'schedulePlanId' => $planId] as $field => $value) {
            if (null !== $value && $metadata->hasField($field)) {
                $conditions[] = $quote->getColumnName($field, $metadata, $platform) . ' = ?';
                $parameters[] = $value;
            }
        }

        return [$quote->getTableName($metadata, $platform), $conditions, $parameters];
    }

    /**
     * Remet les champs froids d'une ligne existante (bornée au club, à la saison et au plan), sans toucher aux autres.
     *
     * @param class-string                                            $class
     * @param array{club_id: string, season_id: string, type: string} $plan
     * @param array<string, mixed>                                    $row
     */
    private function updateRow(string $class, Family $family, array $plan, string $planId, string $rowId, array $row): void
    {
        $metadata = $this->entityManager->getClassMetadata($class);
        $platform = $this->entityManager->getConnection()->getDatabasePlatform();
        $quote = $this->entityManager->getConfiguration()->getQuoteStrategy();

        $sets = [];
        $parameters = [];
        $types = [];
        foreach ([...$row, ...$family->coldRows] as $field => $value) {
            $sets[] = $quote->getColumnName($field, $metadata, $platform) . ' = ?';
            $parameters[] = $this->phpValue($class, $field, $value);
            $types[] = Type::getType((string) $metadata->getTypeOfField($field));
        }
        if ($metadata->isVersioned && null !== $metadata->versionField) {
            $column = $quote->getColumnName($metadata->versionField, $metadata, $platform);
            $sets[] = $column . ' = ' . $column . ' + 1';
        }
        if ($metadata->hasField('updatedAt')) {
            $sets[] = $quote->getColumnName('updatedAt', $metadata, $platform) . ' = ?';
            $parameters[] = new DateTimeImmutable;
            $types[] = Type::getType((string) $metadata->getTypeOfField('updatedAt'));
        }
        [, $conditions, $scope] = $this->tenantScope($class, $plan, Level::PLAN === $family->level ? $planId : null);
        $conditions[] = $quote->getColumnName('id', $metadata, $platform) . ' = ?';
        foreach ([...$scope, $rowId] as $value) {
            $parameters[] = $value;
            $types[] = Type::getType('string');
        }

        $this->entityManager->getConnection()->executeStatement(
            \sprintf('UPDATE %s SET %s WHERE %s', $quote->getTableName($metadata, $platform), implode(', ', $sets), implode(' AND ', $conditions)),
            $parameters,
            $types,
        );
    }

    /**
     * Fait renaître une ligne de la copie, sous son identifiant, dans le club, la saison et le plan : ses champs froids
     * viennent de la copie, les autres de leur valeur par défaut (une règle reçoit le nom de sa nature). Un
     * identifiant déjà pris ailleurs n'écrit rien.
     *
     * @param class-string                                            $class
     * @param array{club_id: string, season_id: string, type: string} $plan
     * @param array<string, mixed>                                    $row
     */
    private function insertRow(string $class, Family $family, array $plan, string $planId, string $rowId, array $row): void
    {
        $metadata = $this->entityManager->getClassMetadata($class);
        $platform = $this->entityManager->getConnection()->getDatabasePlatform();
        $quote = $this->entityManager->getConfiguration()->getQuoteStrategy();
        $blank = $metadata->getReflectionClass()->newInstanceWithoutConstructor();
        $now = new DateTimeImmutable;
        $given = [...$row, ...$family->coldRows, 'id' => $rowId, 'clubId' => $plan['club_id'], 'seasonId' => $plan['season_id'], 'schedulePlanId' => $planId, 'createdAt' => $now, 'updatedAt' => $now];

        $columns = [];
        $parameters = [];
        $types = [];
        foreach ($metadata->getFieldNames() as $field) {
            if (\array_key_exists($field, $given)) {
                $value = $this->phpValue($class, $field, $given[$field]);
            } else {
                $property = $metadata->getReflectionClass()->getProperty($field);
                if ($property->isInitialized($blank)) {
                    $value = $property->getValue($blank);
                    $value = $value instanceof BackedEnum ? $value->value : $value;
                } elseif (Constraint::class === $class && 'name' === $field) {
                    $value = self::RULE_NAMES[(string) ($row['family'] ?? '')] ?? 'Règle';
                } elseif ($metadata->isNullable($field)) {
                    $value = null;
                } else {
                    throw new LogicException(\sprintf('%s::%s n\'a ni valeur froide ni défaut : la ligne %s ne renaît pas.', $class, $field, $rowId));
                }
            }
            $columns[] = $quote->getColumnName($field, $metadata, $platform);
            $parameters[] = $value;
            $types[] = Type::getType((string) $metadata->getTypeOfField($field));
        }

        $this->entityManager->getConnection()->executeStatement(
            \sprintf(
                'INSERT INTO %s (%s) VALUES (%s) ON CONFLICT (%s) DO NOTHING',
                $quote->getTableName($metadata, $platform),
                implode(', ', $columns),
                implode(', ', array_fill(0, \count($columns), '?')),
                $quote->getColumnName('id', $metadata, $platform),
            ),
            $parameters,
            $types,
        );
    }

    /**
     * La valeur PHP d'un champ de la copie (heure, date et instant relus ; énumération par sa valeur).
     *
     * @param class-string $class
     */
    private function phpValue(string $class, string $field, mixed $value): mixed
    {
        if (!\is_string($value)) {
            return $value;
        }
        $type = (string) $this->entityManager->getClassMetadata($class)->getTypeOfField($field);

        return str_contains($type, 'date') || str_contains($type, 'time') ? new DateTimeImmutable($value) : $value;
    }

    private function normalized(mixed $value, string $type): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return match (true) {
                str_starts_with($type, 'time') => $value->format('H:i:s'),
                str_starts_with($type, 'date') && !str_contains($type, 'time') => $value->format('Y-m-d'),
                default => $value->format(\DATE_ATOM),
            };
        }
        if ($value instanceof BackedEnum) {
            return $value->value;
        }
        if (\is_array($value)) {
            return $this->canonical($value);
        }

        return $value;
    }

    /**
     * @param array<mixed> $value
     *
     * @return array<mixed>
     */
    private function canonical(array $value): array
    {
        if (!array_is_list($value)) {
            ksort($value, \SORT_STRING);
        }
        foreach ($value as $key => $item) {
            if (\is_array($item)) {
                $value[$key] = $this->canonical($item);
            }
        }

        return $value;
    }

    /** @param class-string $class */
    private function familyKey(string $class): string
    {
        $position = strrpos($class, '\\');

        return false === $position ? $class : substr($class, $position + 1);
    }
}
