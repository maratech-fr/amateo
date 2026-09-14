<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Clock\DevClockStore;
use App\Command\CatchUpFixtureReviewCommand;
use App\Entity\Club;
use App\Entity\Fixture;
use App\Entity\Season;
use App\Enum\FixtureHomeAway;
use App\Enum\FixtureReviewState;
use App\Enum\FixtureStatus;
use App\Enum\SeasonStatus;
use App\Service\FbiFixtureImporter;
use App\Service\TenantConnectionContext;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;

/**
 * Le rattrapage hors ligne des rencontres restées « à traiter » (NEW) d'un dépôt
 * antérieur à la naissance-traitée : dry-run par défaut (compte, n'écrit rien),
 * --force écrit, chaque club calcule sa fenêtre dans SON fuseau, et un OUT_OF_SYNC
 * n'est jamais touché.
 */
#[Group('integration')]
final class CatchUpFixtureReviewCommandTest extends KernelTestCase
{
    use TenantGucTrait;

    private EntityManagerInterface $em;

    public function testDryRunCountsButWritesNothing(): void
    {
        $this->pinClock(new DateTimeImmutable('2026-09-16 10:00:00'));
        [$clubId, $seasonId] = $this->seedClub('DRY', 'Europe/Paris');
        $away = $this->newFixture($clubId, $seasonId, FixtureHomeAway::AWAY, '2026-12-25');

        $tester = $this->runCommand();

        self::assertStringContainsString('à traiter', $tester->getDisplay());
        $this->assertReviewState($clubId, $away, FixtureReviewState::NEW, 'dry-run n\'écrit rien');
    }

    public function testForcePersistsAndLeavesOutOfSyncIntact(): void
    {
        $this->pinClock(new DateTimeImmutable('2026-09-16 10:00:00'));
        [$clubId, $seasonId] = $this->seedClub('FORCE', 'Europe/Paris');
        $away = $this->newFixture($clubId, $seasonId, FixtureHomeAway::AWAY, '2026-12-25');
        $outOfSync = $this->newFixture($clubId, $seasonId, FixtureHomeAway::AWAY, '2026-12-25', FixtureReviewState::OUT_OF_SYNC);

        $this->runCommand(['--force' => true]);

        $this->assertReviewState($clubId, $away, FixtureReviewState::REVIEWED, '--force rattrape');
        $this->assertReviewState($clubId, $outOfSync, FixtureReviewState::OUT_OF_SYNC, 'un OUT_OF_SYNC n\'est jamais touché');
    }

    public function testEachClubUsesItsOwnTimezoneWindow(): void
    {
        // Un instant où la date civile — donc la semaine ISO — diffère selon le
        // fuseau : à 23:00 UTC un dimanche, Paris (UTC+2) est déjà lundi (semaine
        // suivante), Los Angeles (UTC−7) est encore ce dimanche. Un domicile au
        // samedi suivant tombe DANS la semaine de Paris, HORS de celle de LA.
        $this->pinClock(new DateTimeImmutable('2026-09-20 23:00:00'));
        [$parisId, $parisSeason] = $this->seedClub('PARIS', 'Europe/Paris');
        [$laId, $laSeason] = $this->seedClub('LA', 'America/Los_Angeles');
        $parisHome = $this->newFixture($parisId, $parisSeason, FixtureHomeAway::HOME, '2026-09-27');
        $laHome = $this->newFixture($laId, $laSeason, FixtureHomeAway::HOME, '2026-09-27');

        $this->runCommand(['--force' => true]);

        $this->assertReviewState($parisId, $parisHome, FixtureReviewState::REVIEWED, 'dans la semaine de Paris → rattrapé');
        $this->assertReviewState($laId, $laHome, FixtureReviewState::NEW, 'hors de la semaine de LA → intact');
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        self::getContainer()->get(DevClockStore::class)->set(null);
        parent::tearDown();
    }

    private function pinClock(DateTimeImmutable $at): void
    {
        self::getContainer()->get(DevClockStore::class)->set($at);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function runCommand(array $options = []): CommandTester
    {
        $container = self::getContainer();
        $command = new CatchUpFixtureReviewCommand(
            $this->em,
            $container->get(TenantConnectionContext::class),
            $container->get(FbiFixtureImporter::class),
            $container->get(ClockInterface::class),
        );

        $tester = new CommandTester($command);
        $tester->execute($options);
        $tester->assertCommandIsSuccessful();

        return $tester;
    }

    private function assertReviewState(string $clubId, string $fixtureId, FixtureReviewState $expected, string $message): void
    {
        $this->scopeGucToClub($clubId);
        $this->em->clear();
        $fixture = $this->em->getRepository(Fixture::class)->find($fixtureId);
        self::assertInstanceOf(Fixture::class, $fixture);
        self::assertSame($expected, $fixture->getReviewState(), $message);
    }

    private function newFixture(string $clubId, string $seasonId, FixtureHomeAway $homeAway, string $ymd, FixtureReviewState $state = FixtureReviewState::NEW): string
    {
        $this->scopeGucToClub($clubId);
        $fixture = new Fixture;
        $fixture->setClubId($clubId);
        $fixture->setSeasonId($seasonId);
        $fixture->setTeamId(Uuid::v4()->toRfc4122());
        $fixture->setMatchDate(new DateTimeImmutable($ymd));
        $fixture->setHomeAway($homeAway);
        $fixture->setOpponentLabel('Adversaire');
        $fixture->setStatus(FixtureStatus::UNPLACED, new DateTimeImmutable);
        $fixture->setReviewState($state);
        $this->em->persist($fixture);
        $this->em->flush();

        return $fixture->getId();
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function seedClub(string $tag, string $timezone): array
    {
        $uid = uniqid('', true);
        $club = new Club;
        $club->setName('Club ' . $tag);
        $club->setSlug('club-' . strtolower($tag) . '-' . $uid);
        $club->setTimezone($timezone);
        $club->setLocale('fr');
        $club->setFfbbClubCode('ARA' . strtoupper(substr(md5($uid), 0, 10)));
        $this->em->persist($club);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());
        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName('2026-2027');
        $season->setStartDate(new DateTimeImmutable('2026-08-01'));
        $season->setEndDate(new DateTimeImmutable('2027-07-15'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $season->setTransitionData([]);
        $this->em->persist($season);
        $this->em->flush();

        return [$club->getId(), $season->getId()];
    }
}
