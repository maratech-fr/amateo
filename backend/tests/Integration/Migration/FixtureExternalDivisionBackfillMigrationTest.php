<?php

declare(strict_types=1);

namespace App\Tests\Integration\Migration;

use App\Entity\Club;
use App\Entity\Competition;
use App\Entity\Fixture;
use App\Entity\Season;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Enum\CompetitionType;
use App\Enum\FixtureHomeAway;
use App\Enum\SeasonStatus;
use App\Service\SeasonResolver;
use App\Tests\Integration\Service\FbiFixtureImporterTest;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20261028120000;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * NR de migration de DONNÉES de {@see Version20261028120000} (M15 D1) —
 * OBLIGATOIRE tant que la migration n'a pas tourné en prod (charte
 * docs/testing/tests-par-parcours.md §2) : il protège le run réel qui va backfiller
 * `fixture.external_division` et élargir l'index partiel unique. Deux promesses :
 *   1. le backfill pose `external_division = competition.name` pour les rencontres FBI
 *      (external_ref NON nul) et NE TOUCHE PAS une rencontre du canal API (external_ref nul) ;
 *   2. l'index partiel unique `uniq_fixture_external_ref` couvre bien `external_division`.
 *
 * On rejoue le SQL de backfill de la migration sur des données semées en état PRÉ-migration
 * (external_division forcé à NULL). La RÈGLE d'identité VIVANTE (même numéro sous deux
 * divisions = deux rencontres, un vrai doublon refusé par l'index) vit, elle, dans
 * {@see FbiFixtureImporterTest} — ce test ne la garde pas.
 * Une fois la migration appliquée en prod, il part à la passe de réduction.
 */
#[Group('integration')]
final class FixtureExternalDivisionBackfillMigrationTest extends KernelTestCase
{
    use TenantGucTrait;

    private EntityManagerInterface $em;

    private Club $club;

    private Team $team;

    public function testBackfillFillsExternalDivisionFromCompetitionNameForFbiFixturesOnly(): void
    {
        $competition = $this->competition('DFU13-3');

        // Rencontre FBI (external_ref non nul) en état PRÉ-migration : external_division NULL.
        $fbi = $this->fixture($competition, externalRef: '22', ffbbRencontreId: null);
        // Rencontre du canal API (external_ref nul) : le backfill ne doit JAMAIS la toucher.
        $api = $this->fixture($competition, externalRef: null, ffbbRencontreId: 'RENC-API-1');
        $this->forcePreMigrationState($fbi->getId());
        $this->forcePreMigrationState($api->getId());

        // Le SQL de backfill de la migration, verbatim.
        $this->em->getConnection()->executeStatement(
            'UPDATE fixture f SET external_division = c.name FROM competition c WHERE f.competition_id = c.id AND f.external_ref IS NOT NULL',
        );

        $this->em->clear();
        $reloadedFbi = $this->em->getRepository(Fixture::class)->find($fbi->getId());
        $reloadedApi = $this->em->getRepository(Fixture::class)->find($api->getId());
        self::assertSame('DFU13-3', $reloadedFbi?->getExternalDivision(), 'le backfill pose le nom du championnat sur la rencontre FBI');
        self::assertNull($reloadedApi?->getExternalDivision(), 'une rencontre du canal API (external_ref nul) reste intacte');
    }

    public function testTheWidenedUniqueIndexCoversExternalDivision(): void
    {
        $indexDef = $this->em->getConnection()->fetchOne(
            'SELECT indexdef FROM pg_indexes WHERE indexname = \'uniq_fixture_external_ref\'',
        );

        self::assertIsString($indexDef, 'l\'index partiel unique existe');
        self::assertStringContainsString('external_division', $indexDef, 'l\'index unique est élargi à external_division');
        self::assertStringContainsString('external_ref', $indexDef);
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->club = $this->createClub();
        $this->scopeGucToClub($this->club->getId());
        $this->team = $this->createTeam();
    }

    /** Force l'état PRÉ-migration (external_division NULL) sur une rencontre déjà semée. */
    private function forcePreMigrationState(string $fixtureId): void
    {
        $this->em->getConnection()->executeStatement(
            'UPDATE fixture SET external_division = NULL WHERE id = :id',
            ['id' => $fixtureId],
        );
    }

    private function competition(string $name): Competition
    {
        $competition = new Competition;
        $competition->setClubId($this->club->getId());
        $competition->setSeasonId($this->team->getSeasonId());
        $competition->setTeamId($this->team->getId());
        $competition->setName($name);
        $competition->setCompetitionType(CompetitionType::CHAMPIONSHIP);
        $this->em->persist($competition);
        $this->em->flush();

        return $competition;
    }

    private function fixture(Competition $competition, ?string $externalRef, ?string $ffbbRencontreId): Fixture
    {
        $fixture = new Fixture;
        $fixture->setClubId($this->club->getId());
        $fixture->setSeasonId($this->team->getSeasonId());
        $fixture->setTeamId($this->team->getId());
        $fixture->setCompetitionId($competition->getId());
        $fixture->setMatchDate(new DateTimeImmutable('2026-11-01'));
        $fixture->setHomeAway(FixtureHomeAway::HOME);
        $fixture->setOpponentLabel('AS Voisins');
        $fixture->setExternalRef($externalRef);
        if (null !== $externalRef) {
            $fixture->setExternalDivision($competition->getName());
        }
        $fixture->setFfbbRencontreId($ffbbRencontreId);
        $this->em->persist($fixture);
        $this->em->flush();

        return $fixture;
    }

    private function createClub(): Club
    {
        $uid = uniqid('', true);
        $club = new Club;
        $club->setName('BC Migration');
        $club->setSlug('bc-migration-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setFfbbClubCode('ARA' . strtoupper(substr(md5($uid), 0, 10)));
        $this->em->persist($club);
        $this->em->flush();

        return $club;
    }

    private function createTeam(): Team
    {
        $season = new Season;
        $season->setClubId($this->club->getId());
        $year = SeasonResolver::seasonYear(new DateTimeImmutable('today'));
        $season->setName((string) $year);
        $season->setStartDate(new DateTimeImmutable($year . '-08-01'));
        $season->setEndDate(new DateTimeImmutable(($year + 1) . '-07-15'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $season->setTransitionData([]);
        $this->em->persist($season);

        $sport = $this->em->getRepository(Sport::class)->findOneBy(['isActive' => true]);
        if (!$sport instanceof Sport) {
            $uid = uniqid('', true);
            $sport = new Sport;
            $sport->setName('Basket ' . $uid);
            $sport->setSlug('basket-' . $uid);
            $sport->setIsActive(true);
            $this->em->persist($sport);
        }
        $category = new SportCategory;
        $category->setClubId($this->club->getId());
        $category->setSportId($sport->getId());
        $category->setName('U13-' . uniqid('', true));
        $this->em->persist($category);

        $team = new Team;
        $team->setClubId($this->club->getId());
        $team->setSeasonId($season->getId());
        $team->setSportCategoryId($category->getId());
        $team->setPriorityTierId(3);
        $team->setName('U13-1');
        $team->setSessionsPerWeek(2);
        $team->setIsActive(true);
        $this->em->persist($team);
        $this->em->flush();

        return $team;
    }
}
