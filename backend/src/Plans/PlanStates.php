<?php

declare(strict_types=1);

namespace App\Plans;

use App\Entity\Schedule;
use App\Entity\VenueTravelRuleSetting;
use App\Enum\SchedulePlanType;
use App\Service\ScheduleConstraintBuilder;
use App\Structure\Family;
use App\Structure\FamilyCatalog;
use App\Structure\Level;
use BackedEnum;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use RuntimeException;

/**
 * La copie du froid d'une version (lot 5), maison unique : `capture` lit l'état froid d'un plan (les familles que
 * {@see FamilyCatalog::coldOf} rend pour sa nature, leurs seuls champs froids), `store` l'écrit pour une version,
 * `of` la relit. Lecture et écriture en SQL direct, hors UnitOfWork : la copie dit ce qui est en base, jamais une
 * entité modifiée en mémoire, et son écriture ne ferme pas le gestionnaire d'entités.
 *
 * Toute version terminée porte sa copie, écrite à sa naissance (génération de saison ou de période, transcription
 * du socle, copie d'un plan sœur) ; une écriture qui échoue lève, et la naissance échoue avec elle. Une version sans
 * copie n'est jamais lue comme vide : {@see self::of} lève.
 */
final class PlanStates
{
    /** Identité, club, saison, plan et horodatage : jamais du froid, même pour une famille toute froide. */
    private const array NEVER_COLD = ['id', 'version', 'createdAt', 'updatedAt', 'clubId', 'seasonId', 'schedulePlanId'];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FamilyCatalog $catalog,
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
     *
     * @return array<string, array<string, mixed>>
     */
    private function rows(string $class, Family $family, array $where): array
    {
        $connection = $this->entityManager->getConnection();
        $platform = $connection->getDatabasePlatform();
        $metadata = $this->entityManager->getClassMetadata($class);
        $quote = $this->entityManager->getConfiguration()->getQuoteStrategy();

        $fields = Family::ALL === $family->cold
            ? array_values(array_diff($metadata->getFieldNames(), self::NEVER_COLD))
            : (array) $family->cold;
        sort($fields);

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
