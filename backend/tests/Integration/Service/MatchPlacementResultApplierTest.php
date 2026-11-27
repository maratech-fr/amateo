<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\Club;
use App\Entity\Fixture;
use App\Entity\Place;
use App\Entity\Season;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\Venue;
use App\Enum\CompetitionType;
use App\Enum\FixtureHomeAway;
use App\Enum\FixturePlacementSource;
use App\Enum\FixtureStatus;
use App\Enum\SeasonStatus;
use App\Enum\TeamLevel;
use App\Service\MatchPlacementResultApplier;
use App\Service\SeasonResolver;
use App\Tests\CreatesCompetitionsTrait;
use App\Tests\StagesFixturesTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Le retour du moteur n'écrit jamais sur une coupe ni sur un amical (lot 7, Q6 et Q7) : même s'il en rend une pose —
 * la demande a été bâtie avant que la compétition devienne coupe —, le match garde sa place (ou son absence de place),
 * seul un match de championnat est posé. Côté demande, le jumeau de bout en bout est
 * `features/placement-des-matchs.feature`, scénario « « Placer » ne propose jamais une coupe… » ; il ne peut pas rougir
 * sur ce garde-ci, puisque le moteur n'y reçoit jamais la coupe ({@see MatchPlacementResultApplier::solverMayWrite}).
 */
#[Group('integration')]
final class MatchPlacementResultApplierTest extends WebTestCase
{
    use CreatesCompetitionsTrait;
    use StagesFixturesTrait;
    use TenantGucTrait;

    private EntityManagerInterface $em;

    private Team $team;

    private Venue $venue;

    private string $saturday;

    /** @return iterable<string, array{0: CompetitionType}> */
    public static function handPlacedTypes(): iterable
    {
        yield 'une coupe' => [CompetitionType::CUP];
        yield 'un amical' => [CompetitionType::FRIENDLY];
    }

    #[DataProvider('handPlacedTypes')]
    public function testTheEngineReturnNeverPlacesAnUnplacedHandPlacedMatch(CompetitionType $type): void
    {
        $match = $this->homeFixture($type);

        $result = $this->apply($match, '18:00');

        self::assertSame(['applied' => 0, 'skipped' => 1], $result);
        $reloaded = $this->reload($match);
        self::assertSame(FixtureStatus::UNPLACED, $this->stageOfFixture($reloaded, $this->em), 'le match placé à la main reste sans créneau');
        self::assertNull($reloaded->getVenueId());
    }

    #[DataProvider('handPlacedTypes')]
    public function testTheEngineReturnNeverMovesAHandPlacedMatchAlreadyPlacedByTheSystem(CompetitionType $type): void
    {
        $match = $this->homeFixture($type);
        $match->setVenueId($this->venue->getId());
        $match->setKickoffTime(new DateTimeImmutable('20:00'));
        $this->stageFixture($match, FixtureStatus::PLACED, new DateTimeImmutable, $this->em);
        $match->setPlacementSource(FixturePlacementSource::SOLVER);
        $this->em->flush();

        $result = $this->apply($match, '15:00');

        self::assertSame(['applied' => 0, 'skipped' => 1], $result);
        self::assertSame('20:00', $this->reload($match)->getKickoffTime()?->format('H:i'), 'le match déjà posé par le système ne bouge pas');
    }

    /** Témoin : le même retour pose un match de championnat — le refus vient de la nature du match. */
    public function testTheEngineReturnPlacesAChampionshipMatch(): void
    {
        $match = $this->homeFixture(CompetitionType::CHAMPIONSHIP);

        $result = $this->apply($match, '18:00');

        self::assertSame(['applied' => 1, 'skipped' => 0], $result);
        $reloaded = $this->reload($match);
        self::assertSame(FixtureStatus::PLACED, $this->stageOfFixture($reloaded, $this->em));
        self::assertSame('18:00', $reloaded->getKickoffTime()?->format('H:i'));
    }

    protected function setUp(): void
    {
        self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $uid = uniqid('', true);
        $club = new Club;
        $club->setName('BC Retour Moteur ' . $uid);
        $club->setSlug('bc-retour-moteur-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $this->em->persist($club);
        $this->em->flush();
        $this->scopeGucToClub($club->getId());

        $year = SeasonResolver::seasonYear(new DateTimeImmutable('today'));
        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName((string) $year);
        $season->setStartDate(new DateTimeImmutable($year . '-08-01'));
        $season->setEndDate(new DateTimeImmutable(($year + 1) . '-07-15'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $season->setTransitionData([]);
        $this->em->persist($season);

        $sport = $this->em->getRepository(Sport::class)->findOneBy(['isActive' => true]);
        if (!$sport instanceof Sport) {
            $sport = (new Sport)->setName('Basket ' . $uid)->setSlug('basket-' . $uid)->setIsActive(true);
            $this->em->persist($sport);
        }
        $category = new SportCategory;
        $category->setClubId($club->getId());
        $category->setSportId($sport->getId());
        $category->setName('U17M-' . $uid);
        $this->em->persist($category);

        $this->team = new Team;
        $this->team->setClubId($club->getId());
        $this->team->setSeasonId($season->getId());
        $this->team->setSportCategoryId($category->getId());
        $this->team->setPriorityTierId(3);
        $this->team->setName('U17M1');
        $this->team->setLevel(TeamLevel::REGIONAL);
        $this->team->setIsActive(true);
        $this->em->persist($this->team);

        $place = (new Place)->setClubId($club->getId());
        $this->em->persist($place);
        $this->venue = new Venue($place);
        $this->venue->setClubId($club->getId());
        $this->venue->setSeasonId($season->getId());
        $this->venue->setName('Gymnase Retour');
        $this->venue->setSource('manual');
        $this->em->persist($this->venue);
        $this->em->flush();

        $this->saturday = new DateTimeImmutable('today')->modify('next saturday')->format('Y-m-d');
    }

    private function homeFixture(CompetitionType $type): Fixture
    {
        $competitionId = CompetitionType::FRIENDLY === $type
            ? $this->friendlyCompetitionIdFor($this->team->getId())
            : $this->competitionFor($this->team->getId(), (CompetitionType::CUP === $type ? 'Coupe du Rhône ' : 'Régionale U17 ') . uniqid('', true), $type)->getId();

        $fixture = new Fixture;
        $fixture->setClubId($this->team->getClubId());
        $fixture->setSeasonId($this->team->getSeasonId());
        $fixture->setTeamId($this->team->getId());
        $fixture->setCompetitionId($competitionId);
        $fixture->setMatchDate(new DateTimeImmutable($this->saturday));
        $fixture->setHomeAway(FixtureHomeAway::HOME);
        $fixture->setOpponentLabel('Adversaire');
        $this->em->persist($fixture);
        $this->em->flush();

        return $fixture;
    }

    /** @return array{applied: int, skipped: int} */
    private function apply(Fixture $match, string $kickoff): array
    {
        return self::getContainer()->get(MatchPlacementResultApplier::class)->apply([[
            'matchId' => $match->getId(),
            'venueId' => $this->venue->getId(),
            'kickoff' => $this->saturday . 'T' . $kickoff . ':00',
        ]]);
    }

    private function reload(Fixture $match): Fixture
    {
        $this->em->clear();
        $reloaded = $this->em->find(Fixture::class, $match->getId());
        self::assertInstanceOf(Fixture::class, $reloaded);

        return $reloaded;
    }
}
