<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Club;
use App\Entity\Competition;
use App\Entity\Fixture;
use App\Entity\Season;
use App\Enum\CompetitionType;
use App\Enum\FixtureHomeAway;
use App\Enum\SeasonStatus;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20260912120000;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * NR — la migration P4-199 : le suffixe FFBB « (n) » est retiré des libellés déjà
 * stockés (fixture.opponent_label + competition.fbi_team_label). On exécute le VRAI
 * SQL de la migration (via getSql(), jamais une copie), sous GUC de club — la RLS
 * borne alors les UPDATE globaux au seul club de test — et on la rejoue pour prouver
 * l'idempotence. Un « (6) » en milieu de libellé et une chaîne sans suffixe sont
 * laissés intacts.
 */
#[Group('integration')]
final class MigrationFixtureTeamLabelSuffixTest extends KernelTestCase
{
    use TenantGucTrait;

    private EntityManagerInterface $em;

    public function testTheSuffixIsStrippedFromStoredLabelsAndTheMigrationIsIdempotent(): void
    {
        [$club, $season] = $this->makeClubAndSeason();
        $teamId = Uuid::v4()->toRfc4122();

        $suffixed = $this->makeFixture($club->getId(), $season->getId(), $teamId, 'AL CALUIRE ET CUIRE - 3 (6)');
        $middle = $this->makeFixture($club->getId(), $season->getId(), $teamId, 'AS (6) VOISINS');
        $plain = $this->makeFixture($club->getId(), $season->getId(), $teamId, 'AS Voisins');
        $competition = $this->makeCompetition($club->getId(), $season->getId(), $teamId, 'BC TESTVILLE - 1 (2)');

        $suffixedId = $suffixed->getId();
        $middleId = $middle->getId();
        $plainId = $plain->getId();
        $competitionId = $competition->getId();

        $this->runMigration();
        $this->runMigration(); // rejouée : idempotente, aucune erreur, mêmes valeurs
        $this->em->clear();

        self::assertSame('AL CALUIRE ET CUIRE - 3', $this->em->getRepository(Fixture::class)->find($suffixedId)?->getOpponentLabel(), 'le suffixe « (6) » de fin est retiré');
        self::assertSame('AS (6) VOISINS', $this->em->getRepository(Fixture::class)->find($middleId)?->getOpponentLabel(), 'un « (6) » en milieu de chaîne est intact');
        self::assertSame('AS Voisins', $this->em->getRepository(Fixture::class)->find($plainId)?->getOpponentLabel(), 'une chaîne sans suffixe est inchangée');
        self::assertSame('BC TESTVILLE - 1', $this->em->getRepository(Competition::class)->find($competitionId)?->getFbiTeamLabel(), 'la clé de rapprochement fbi_team_label est nettoyée');
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    /** @return array{0: Club, 1: Season} */
    private function makeClubAndSeason(): array
    {
        $uid = uniqid('', true);
        $club = new Club;
        $club->setName('Suffix Migration Club');
        $club->setSlug('suffix-mig-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $this->em->persist($club);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());

        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName('2026-2027');
        $season->setStartDate(new DateTimeImmutable('2026-08-01'));
        $season->setEndDate(new DateTimeImmutable('2027-07-15'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($season);
        $this->em->flush();

        return [$club, $season];
    }

    private function makeFixture(string $clubId, string $seasonId, string $teamId, string $opponentLabel): Fixture
    {
        $fixture = new Fixture;
        $fixture->setClubId($clubId);
        $fixture->setSeasonId($seasonId);
        $fixture->setTeamId($teamId);
        $fixture->setMatchDate(new DateTimeImmutable('2026-11-07'));
        $fixture->setHomeAway(FixtureHomeAway::AWAY);
        $fixture->setOpponentLabel($opponentLabel);
        $this->em->persist($fixture);
        $this->em->flush();

        return $fixture;
    }

    private function makeCompetition(string $clubId, string $seasonId, string $teamId, string $fbiTeamLabel): Competition
    {
        $competition = new Competition;
        $competition->setClubId($clubId);
        $competition->setSeasonId($seasonId);
        $competition->setTeamId($teamId);
        $competition->setName('D2');
        $competition->setCompetitionType(CompetitionType::CHAMPIONSHIP);
        $competition->setFbiTeamLabel($fbiTeamLabel);
        $this->em->persist($competition);
        $this->em->flush();

        return $competition;
    }

    private function runMigration(): void
    {
        $migration = new Version20260912120000($this->em->getConnection(), new NullLogger);
        $migration->up(new Schema);
        $connection = $this->em->getConnection();
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }
}
