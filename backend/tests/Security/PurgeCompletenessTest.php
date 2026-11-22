<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\CoachWish;
use App\Entity\HolidayReminderLog;
use App\Entity\Person;
use App\Entity\Season;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\TeamCoach;
use App\Entity\TenantOwnedInterface;
use App\Entity\Venue;
use App\Entity\VenueTrainingSlot;
use App\Enum\AuditAction;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\SeasonStatus;
use App\Enum\TeamCoachRole;
use App\Service\ErasedClubPurger;
use App\Service\SeasonDataPurger;
use App\Structure\Family;
use App\Structure\FamilyCatalog;
use App\Tests\CapturesDeletedTablesTrait;
use App\Tests\CreatesPeopleAndPlacesTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use ReflectionClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * BCK-24 — la purge de saison (rétention / reset) ET l'effacement RGPD d'un club
 * doivent couvrir TOUTE donnée tenant. Le trou est INVISIBLE : une entité tenant
 * oubliée survit en silence à un « réinitialiser la saison » ou à un effacement —
 * pour l'effacement, c'est une donnée personnelle qui reste alors qu'on l'a promise
 * détruite. `opponent_travel` (P2-54) était exactement dans ce cas au 2026-09-18.
 *
 * Ce test ne recopie aucune liste — il DÉRIVE de la déclaration des familles (`FamilyCatalog`,
 * lot conception 1 PR 4b) et exige que toute entité tenant soit, pour chaque chemin, purgée ou
 * déclarée épargnée AVEC sa raison. Une entité tenant nouvelle tombe donc en échec par défaut, ce
 * qui est le bon défaut pour une purge légale (patron {@see RgpdExportCompletenessTest}).
 *
 * Il exige aussi que les purges FASSENT ce que le catalogue dit (les `DELETE` réellement exécutés)
 * et qu'aucun purgeur ne tienne plus sa propre liste. Le compte rendu de l'effacement
 * (`CLUB_PURGED`, `rowsDeleted`) compte chaque ligne effacée.
 *
 * Angle jumeau, cité : `Security/FamilyDeclarationSweepTest` (la déclaration elle-même) et
 * `Integration/PurgeOrderRespectsForeignKeysTest` (l'ordre exécuté contre les clés réelles).
 */
#[Group('phase1')]
final class PurgeCompletenessTest extends KernelTestCase
{
    use CapturesDeletedTablesTrait;
    use CreatesPeopleAndPlacesTrait;
    use TenantGucTrait;

    /**
     * Effacée par la saison seule, hors du catalogue : sans `club_id` (clé par saison, uuid propre
     * au club), elle n'entre pas dans `FamilyCatalog::purgedWithSeason()` (`SeasonDataPurger::purge`).
     */
    private const string PURGED_BY_SEASON_ALONE = HolidayReminderLog::class;

    /** Les listes que les purgeurs tenaient à la main avant la déclaration des familles. */
    private const array RETIRED_LISTS = [
        SeasonDataPurger::class => ['PURGED_VIA_PARENT', 'PURGED_BY_CLUB_SEASON', 'EXCLUDED_FROM_SEASON_PURGE'],
        ErasedClubPurger::class => ['PURGED_BY_CLUB', 'EXCLUDED_FROM_ERASURE'],
    ];

    /**
     * Purge de SAISON (SeasonDataPurger) : toute entité tenant doit être purgée via
     * son propriétaire, par club+saison, traitée à part, ou déclarée épargnée avec sa raison.
     */
    public function testEveryTenantOwnedEntityIsSeasonPurgedOrExcludedOnPurpose(): void
    {
        $covered = array_flip(array_merge(
            $this->seasonPurgeCoverage(),
            $this->keptTables(static fn (Family $family): ?string => $family->keptAtSeasonPurge),
        ));

        $unaccounted = [];
        foreach ($this->tenantOwnedTables() as $table) {
            if (isset($covered[$table])) {
                continue;
            }
            $unaccounted[] = $table;
        }

        self::assertSame([], $unaccounted, \sprintf(
            "Ces tables tenant ne sont NI purgées par SeasonDataPurger NI exclues nommément :\n  - %s\n"
            . "Une purge de saison (rétention / reset) les laisserait EN SILENCE — la commande rend un succès.\n"
            . "Deux issues, jamais l'oubli : déclarer la famille de sorte que le catalogue la purge, ou la\n"
            . 'déclarer épargnée (#[Family(keptAtSeasonPurge: …)]) AVEC la raison qui le justifie.',
            implode("\n  - ", $unaccounted),
        ));
    }

    /**
     * Effacement RGPD (ErasedClubPurger) : toute entité tenant doit être purgée par la
     * couverture de saison (itérée sur chaque saison), par club, ou déclarée épargnée.
     */
    public function testEveryTenantOwnedEntityIsErasedOrExcludedOnPurpose(): void
    {
        $covered = array_flip(array_merge(
            $this->seasonPurgeCoverage(),
            $this->tablesOf($this->catalog()->purgedWithClub()),
            $this->keptTables(static fn (Family $family): ?string => $family->keptAtErasure),
        ));

        $unaccounted = [];
        foreach ($this->tenantOwnedTables() as $table) {
            if (isset($covered[$table])) {
                continue;
            }
            $unaccounted[] = $table;
        }

        self::assertSame([], $unaccounted, \sprintf(
            "Ces tables tenant ne sont NI effacées par ErasedClubPurger NI exclues nommément :\n  - %s\n"
            . "L'effacement RGPD (art. 17) les GARDERAIT en base — donnée personnelle promise détruite.\n"
            . 'Issue : les purger (par saison ou par club), ou déclarer la famille épargnée (#[Family(keptAtErasure: …)]) AVEC la raison.',
            implode("\n  - ", $unaccounted),
        ));
    }

    /**
     * Une seule maison : les listes que les purgeurs tenaient à la main ont disparu, la purge lit
     * la déclaration des familles. Une liste revenue dériverait du catalogue en silence.
     */
    public function testThePurgersNoLongerHoldTheirOwnLists(): void
    {
        $stillThere = [];
        foreach (self::RETIRED_LISTS as $purger => $constants) {
            foreach ($constants as $constant) {
                if (new ReflectionClass($purger)->hasConstant($constant)) {
                    $stillThere[] = $purger . '::' . $constant;
                }
            }
        }

        self::assertSame([], $stillThere, "Ces listes doublent la déclaration des familles (FamilyCatalog) :\n  - " . implode("\n  - ", $stillThere));
    }

    /**
     * « Réinitialiser la saison » efface exactement les familles que le catalogue range dans la
     * purge de saison, plus la seule effacée par la saison seule — ni plus, ni moins — et garde la
     * ligne de saison.
     */
    public function testTheSeasonResetDeletesExactlyTheCatalogFamilies(): void
    {
        [$club, $season] = $this->clubWithSeason('Remise');
        $purger = self::getContainer()->get(SeasonDataPurger::class);

        $executed = $this->deletedTablesDuring(static fn (): int => $purger->purge($club->getId(), $season->getId()));

        self::assertSame($this->sortedUnique($this->seasonPurgeTables()), $this->sortedUnique($executed));
    }

    /**
     * L'effacement d'un club efface exactement, pour chaque saison, les familles de la purge de
     * saison et la ligne de saison, puis les familles que le catalogue efface avec le club ; son
     * compte rendu `CLUB_PURGED` compte chaque ligne effacée (13 sur ce décor : 2 saisons, 8 lignes
     * de saison dont une doléance purgée par son entrée de calendrier, 1 catégorie du club, 2 fiches
     * de personne dont une sans rôle, gardée après la suppression de son coach).
     */
    public function testTheErasureDeletesExactlyTheCatalogFamiliesAndCountsEveryRow(): void
    {
        [$club, $season] = $this->clubWithSeason('Effacement');
        $this->decorOfThirteenRows($club, $season);
        $purger = self::getContainer()->get(ErasedClubPurger::class);

        $deleted = 0;
        $executed = $this->deletedTablesDuring(static function () use ($purger, $club, &$deleted): void {
            $deleted = $purger->purge($club);
        });

        $expected = [...$this->seasonPurgeTables(), 'season', ...$this->tablesOf($this->catalog()->purgedWithClub())];
        self::assertSame($this->sortedUnique($expected), $this->sortedUnique($executed));

        self::assertSame(13, $deleted, 'le nombre rendu compte chaque ligne effacée');
        $people = (int) self::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM person WHERE club_id = :club',
            ['club' => $club->getId()],
        );
        self::assertSame(0, $people, 'l\'effacement du club emporte toutes ses fiches de personne, celle sans rôle comprise');
        $details = self::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchOne(
            'SELECT details FROM audit_log WHERE club_id = :club AND action = :action',
            ['club' => $club->getId(), 'action' => AuditAction::CLUB_PURGED->value],
        );
        self::assertIsString($details, 'l\'effacement écrit son compte rendu');
        self::assertSame(['rowsDeleted' => 13], json_decode($details, true, 512, \JSON_THROW_ON_ERROR));
    }

    /**
     * L'effacement emporte aussi les lignes de saison dont la saison n'existe plus : `season_id` n'a
     * aucune clé étrangère, un coach (et son équipe, leur lien) d'une saison disparue n'est vu par
     * aucune purge de saison. Sans cela, la fiche de personne qu'il pointe ne s'efface pas (clé
     * `fk_coach_person_club`, ON DELETE RESTRICT) et le club ne s'efface jamais ; sans la clé, la
     * donnée survivrait en silence. Règle lue sur toutes les familles de la purge de saison.
     */
    public function testTheErasureAlsoRemovesSeasonRowsWhoseSeasonIsGone(): void
    {
        [$club] = $this->clubWithSeason('Orpheline');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $clubId = $club->getId();
        $vanishedSeasonId = '66666666-6666-4666-8666-666666666666';
        $team = $this->team($clubId, $vanishedSeasonId);
        $coach = $this->newCoach($clubId, $vanishedSeasonId, 'Saison', 'Disparue');
        $em->persist($team);
        $em->flush();
        $em->persist((new TeamCoach)->setClubId($clubId)->setSeasonId($vanishedSeasonId)->setTeamId($team->getId())
            ->setCoachId($coach->getId())->setRole(TeamCoachRole::MAIN));
        $em->flush();
        $em->clear();
        $connection = $em->getConnection();
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM coach WHERE club_id = :club', ['club' => $clubId]), 'témoin : le coach orphelin de saison est en base');

        self::getContainer()->get(ErasedClubPurger::class)->purge($club);

        $left = [];
        foreach ([...$this->tablesOf($this->catalog()->purgedWithSeason()), 'person'] as $table) {
            $count = (int) $connection->fetchOne(\sprintf('SELECT COUNT(*) FROM %s WHERE club_id = :club', $connection->quoteIdentifier($table)), ['club' => $clubId]);
            if ($count > 0) {
                $left[] = \sprintf('%s (%d)', $table, $count);
            }
        }
        self::assertSame([], $left, "L'effacement du club laisse des lignes de saison dont la saison n'existe plus :\n  - " . implode("\n  - ", $left));
    }

    protected function setUp(): void
    {
        self::bootKernel();
    }

    /**
     * Les tables couvertes par une purge de saison : via le propriétaire, par club+saison,
     * ou traitées à part (avec raison).
     *
     * @return list<string>
     */
    private function seasonPurgeCoverage(): array
    {
        $catalog = $this->catalog();

        return array_merge(
            $this->tablesOf(array_keys($catalog->purgedViaOwner())),
            $this->tablesOf($catalog->purgedWithSeason()),
            array_keys(new ReflectionClass(SeasonDataPurger::class)->getConstant('HANDLED_APART')),
        );
    }

    /**
     * Les tables qu'une purge de saison efface : via le propriétaire, par club+saison, et la seule
     * effacée par la saison seule.
     *
     * @return list<string>
     */
    private function seasonPurgeTables(): array
    {
        $catalog = $this->catalog();

        return [
            ...$this->tablesOf(array_keys($catalog->purgedViaOwner())),
            ...$this->tablesOf($catalog->purgedWithSeason()),
            ...$this->tablesOf([self::PURGED_BY_SEASON_ALONE]),
        ];
    }

    /**
     * Les tables des familles déclarées épargnées, chacune avec une raison non vide.
     *
     * @param callable(Family): ?string $reasonOf
     *
     * @return list<string>
     */
    private function keptTables(callable $reasonOf): array
    {
        $catalog = $this->catalog();
        $kept = [];
        foreach ($catalog->all() as $class) {
            $reason = $reasonOf($catalog->of($class));
            if (null === $reason) {
                continue;
            }
            self::assertNotSame('', trim($reason), \sprintf('L\'exclusion de « %s » doit porter sa raison.', $class));
            $kept[] = $class;
        }

        return $this->tablesOf($kept);
    }

    /**
     * @param list<string> $tables
     *
     * @return list<string>
     */
    private function sortedUnique(array $tables): array
    {
        $tables = array_values(array_unique($tables));
        sort($tables);

        return $tables;
    }

    private function catalog(): FamilyCatalog
    {
        return self::getContainer()->get(FamilyCatalog::class);
    }

    /** @return array{0: Club, 1: Season} */
    private function clubWithSeason(string $name): array
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $uid = uniqid('', true);
        $club = (new Club)->setName($name . ' ' . $uid)->setSlug('purge-' . $uid)->setTimezone('Europe/Paris')->setLocale('fr')
            ->setOnboardingCompleted(true)->setFfbbClubCode('PCT' . strtoupper(substr(md5($uid), 0, 9)));
        $em->persist($club);
        $em->flush();
        $this->scopeGucToClub($club->getId());

        $season = $this->season($club, 2025);
        $em->flush();

        return [$club, $season];
    }

    private function season(Club $club, int $startYear): Season
    {
        $season = (new Season)->setClubId($club->getId())->setName($startYear . '-' . ($startYear + 1))
            ->setStartDate(new DateTimeImmutable($startYear . '-09-01'))->setEndDate(new DateTimeImmutable(($startYear + 1) . '-06-30'))
            ->setStatus(SeasonStatus::ACTIVE);
        self::getContainer()->get(EntityManagerInterface::class)->persist($season);

        return $season;
    }

    /**
     * Onze lignes de plus que la saison déjà là : une seconde saison et son équipe ; dans la
     * première, une équipe, un coach (sa fiche de personne comprise) et leur lien, un gymnase et son
     * créneau de saison, une entrée de calendrier et la doléance qui en dépend ; une catégorie du
     * club ; la fiche sans rôle d'un ancien coach. Aucune ligne qu'une clé étrangère emporterait
     * d'elle-même : le compte ne dépend pas de l'ordre de purge.
     */
    private function decorOfThirteenRows(Club $club, Season $season): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $clubId = $club->getId();
        $seasonId = $season->getId();

        $previous = $this->season($club, 2024);
        $em->flush();
        $em->persist($this->team($clubId, $previous->getId()));

        $team = $this->team($clubId, $seasonId);
        $coach = $this->newCoach($clubId, $seasonId, 'Alex', 'Martin');
        $em->persist((new Person)->setClubId($clubId)->setFirstName('Ancien')->setLastName('Coach'));
        $venue = (new Venue)->setClubId($clubId)->setSeasonId($seasonId)->setName('Coubertin')->setSource('manual');
        $entry = (new CalendarEntry)->setClubId($clubId)->setSeasonId($seasonId)->setKind(CalendarEntryKind::PERIOD)
            ->setPeriodType(CalendarEntryPeriodType::HOLIDAY)->setTitle('Pâques')
            ->setStartDate(new DateTimeImmutable('2026-04-06'))->setEndDate(new DateTimeImmutable('2026-04-19'));
        foreach ([$team, $coach, $venue, $entry] as $row) {
            $em->persist($row);
        }
        $em->flush();

        $em->persist((new TeamCoach)->setClubId($clubId)->setSeasonId($seasonId)->setTeamId($team->getId())
            ->setCoachId($coach->getId())->setRole(TeamCoachRole::MAIN));
        $em->persist((new VenueTrainingSlot)->setClubId($clubId)->setSeasonId($seasonId)->setVenueId($venue->getId())
            ->setDayOfWeek(1)->setStartTime(new DateTimeImmutable('18:00'))->setDurationMinutes(90)->setCapacity(1));
        $em->persist((new CoachWish)->setClubId($clubId)->setSeasonId($seasonId)->setCalendarEntryId($entry->getId())
            ->setWeekStart(new DateTimeImmutable('2026-04-06'))->setTeamId($team->getId())->setSlotsWanted(1));

        $sport = $em->getRepository(Sport::class)->findOneBy([]) ?? (new Sport)->setName('Basket ' . $clubId)->setSlug('basket-' . $clubId)->setIsActive(true);
        $em->persist($sport);
        $em->persist((new SportCategory)->setClubId($clubId)->setSportId($sport->getId())->setName('U13 ' . $clubId));
        $em->flush();
        $em->clear();
    }

    private function team(string $clubId, string $seasonId): Team
    {
        return (new Team)->setClubId($clubId)->setSeasonId($seasonId)->setName('U13 F1')
            ->setSportCategoryId('99999999-9999-4999-8999-999999999999')->setPriorityTierId(1)->setSessionsPerWeek(1);
    }

    /**
     * @param list<class-string> $entityClasses
     *
     * @return list<string>
     */
    private function tablesOf(array $entityClasses): array
    {
        $factory = self::getContainer()->get('doctrine')->getManager()->getMetadataFactory();

        return array_map(
            static fn (string $entityClass): string => $factory->getMetadataFor($entityClass)->getTableName(),
            $entityClasses,
        );
    }

    /** @return list<string> */
    private function tenantOwnedTables(): array
    {
        $tables = [];

        $factory = self::getContainer()->get('doctrine')->getManager()->getMetadataFactory();
        foreach ($factory->getAllMetadata() as $metadata) {
            if (is_a($metadata->getName(), TenantOwnedInterface::class, true)) {
                $tables[] = $metadata->getTableName();
            }
        }

        self::assertNotEmpty($tables, 'Aucune entité tenant trouvée — le marqueur a-t-il changé de nom ?');

        return $tables;
    }
}
