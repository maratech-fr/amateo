<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\Club;
use App\Entity\ClubLeagueWindow;
use App\Entity\Fixture;
use App\Entity\PublicHoliday;
use App\Entity\Season;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Enum\CompetitionType;
use App\Enum\FixtureHomeAway;
use App\Enum\SeasonStatus;
use App\Enum\TeamLevel;
use App\Plans\PlanComposition;
use App\Service\ConflictFingerprinter;
use App\Service\ConflictRadarLoader;
use App\Service\LeagueEnvelopeResolver;
use App\Service\SeasonResolver;
use App\Tests\CreatesCompetitionsTrait;
use App\Tests\Integration\Api\LeagueMatchWindowsApiTest;
use App\Tests\SeasonPlanOf;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * P4-272 ① — filet d'intégration de la JONCTION « la copie club de la fenêtre de ligue
 * (`club_league_window`) est LUE par le radar de conflits » ({@see ConflictRadarLoader}
 * charge `ClubLeagueWindow::findBy([])` puis {@see LeagueEnvelopeResolver}).
 *
 * La promesse (une phrase) : une fenêtre corrigée par le club est honorée par le radar —
 * un domicile de compétition hors de CETTE fenêtre crie une violation, un domicile dans la
 * fenêtre ne crie rien, et SANS copie posée aucune violation ne naît (la copie, pas le
 * catalogue fédéral, pilote la détection).
 *
 * C'est le filet au plus bas qui remplace le scénario Behat `les-conflits…:130`
 * (« Une fenêtre de ligue corrigée par le club est honorée »). Le chemin d'écriture
 * gestionnaire → copie via l'API `club_league_windows` reste prouvé par
 * {@see LeagueMatchWindowsApiTest} ; ici on prouve la LECTURE
 * de cette copie par le radar.
 *
 * Lot 7, PR 4b (axe *sens des contraintes*) : la fenêtre de ligue borne un domicile de CHAMPIONNAT, jamais une coupe.
 * Une coupe obéit à sa seule règle : un coup d'envoi entre 08:00 et 21:00, bornes comprises, quel que soit le jour
 * (férié compris) ; hors de ces bornes, le radar porte « coupe hors horaires » (`CUP_KICKOFF_OUT_OF_HOURS`, gravité 3,
 * bornes servies, empreinte par match). Jumeau de bout en bout, par l'API :
 * `features/les-conflits-d-un-match-disent-la-verite.feature`, plan « Une coupe obéit à son horaire, jamais à la
 * fenêtre de ligue ». Ce filet-ci est au step bloquant (`ci.yml`, `docs/testing/blocking-tests.md`) : le « Lot Gate »
 * ne joue pas Behat.
 */
#[Group('phase1')]
#[Group('integration')]
final class ConflictRadarLeagueWindowTest extends WebTestCase
{
    use CreatesCompetitionsTrait;
    use TenantGucTrait;

    private EntityManagerInterface $em;

    private ConflictRadarLoader $radar;

    private Club $club;

    private Season $season;

    private Team $team;

    /** Un samedi (ISO 6) à venir, pour échapper au drop « déjà joué » (D1 règle 3). */
    private string $saturday;

    public function testCorrectedClubWindowOutsideKickoffRaisesLeagueWindowViolation(): void
    {
        // Copie club : samedi matin 10:00-10:30, de la catégorie et du niveau de l'équipe.
        $this->clubWindow('10:00', '10:30');
        // Domicile de compétition posé le samedi à 18:00 → hors de la fenêtre.
        $fixture = $this->homeCompetitionFixture('18:00');

        self::assertTrue(
            $this->hasLeagueWindowViolation($fixture->getId()),
            'un domicile de compétition hors de la fenêtre de ligue corrigée doit crier LEAGUE_WINDOW_VIOLATION',
        );
    }

    public function testKickoffInsideCorrectedClubWindowIsSilent(): void
    {
        // Même équipe, même match, mais la copie couvre toute la journée (10:00-20:00) :
        // le coup d'envoi 18:00 est DANS la fenêtre → aucune violation. Prouve que le radar
        // honore la VALEUR de la copie (il ne crie pas par construction).
        $this->clubWindow('10:00', '20:00');
        $fixture = $this->homeCompetitionFixture('18:00');

        self::assertFalse(
            $this->hasLeagueWindowViolation($fixture->getId()),
            'un coup d\'envoi DANS la fenêtre corrigée ne doit crier aucune violation de ligue',
        );
    }

    public function testWithoutAnyClubWindowNoLeagueWindowViolation(): void
    {
        // AUCUNE copie posée : l'équipe n'est pas cadrée (enveloppe vide) → le radar reste
        // muet, même pour le coup d'envoi du soir. Le contre-exemple de la copie : retire la
        // ligne, la violation disparaît — c'est bien la copie DB que le radar lit, pas autre chose.
        $fixture = $this->homeCompetitionFixture('18:00');

        self::assertFalse(
            $this->hasLeagueWindowViolation($fixture->getId()),
            'sans copie `club_league_window`, aucune LEAGUE_WINDOW_VIOLATION ne doit naître',
        );
    }

    /** Hors de la fenêtre de ligue du samedi, dans ses horaires : une coupe ne porte aucun conflit de règle. */
    public function testACupOutsideTheLeagueWindowButWithinItsHoursRaisesNothing(): void
    {
        $this->clubWindow('10:00', '10:30');
        $cup = $this->homeCompetitionFixture('18:00', CompetitionType::CUP);

        self::assertFalse($this->hasLeagueWindowViolation($cup->getId()), 'une coupe n\'est jamais jugée contre la fenêtre de ligue');
        self::assertSame([], $this->cupKickoffItems($cup->getId()), 'une coupe à 18:00 est dans ses horaires');
    }

    /**
     * Les bornes 08:00 et 21:00 sont comprises, un mercredi sans fenêtre de ligue comme un samedi.
     *
     * @return iterable<string, array{0: string, 1: string, 2: bool}>
     */
    public static function cupKickoffs(): iterable
    {
        yield 'mercredi 20:00, dedans' => ['wednesday', '20:00', false];
        yield 'samedi 08:00, borne basse comprise' => ['saturday', '08:00', false];
        yield 'samedi 21:00, borne haute comprise' => ['saturday', '21:00', false];
        yield 'dimanche 07:45, trop tôt' => ['sunday', '07:45', true];
        yield 'samedi 21:01, trop tard' => ['saturday', '21:01', true];
        yield 'mercredi 21:30, trop tard' => ['wednesday', '21:30', true];
    }

    #[DataProvider('cupKickoffs')]
    public function testACupIsJudgedOnItsKickoffHoursOnly(string $day, string $kickoff, bool $flagged): void
    {
        $this->clubWindow('10:00', '10:30');
        $date = new DateTimeImmutable($this->saturday)->modify(match ($day) {
            'wednesday' => '+4 days',
            'sunday' => '+1 day',
            default => '+0 day',
        })->format('Y-m-d');
        $cup = $this->homeCompetitionFixture($kickoff, CompetitionType::CUP, $date);

        self::assertFalse($this->hasLeagueWindowViolation($cup->getId()), 'une coupe n\'est jamais jugée contre la fenêtre de ligue');
        $items = $this->cupKickoffItems($cup->getId());
        if (!$flagged) {
            self::assertSame([], $items, \sprintf('une coupe à %s est dans ses horaires', $kickoff));

            return;
        }
        self::assertCount(1, $items, \sprintf('une coupe à %s est « coupe hors horaires »', $kickoff));
        self::assertSame(3, $items[0]['severity'] ?? null, 'gravité 3');
        self::assertSame('08:00', $items[0]['kickoffMin'] ?? null, 'la borne basse est servie');
        self::assertSame('21:00', $items[0]['kickoffMax'] ?? null, 'la borne haute est servie');
        self::assertSame('CUP_KICKOFF_OUT_OF_HOURS:' . $cup->getId(), new ConflictFingerprinter()->fingerprint($items[0]), 'empreinte : le type et le match');
    }

    /** Un jour férié, la fenêtre de ligue s'efface, la borne des coupes non. */
    public function testTheCupBoundHoldsOnAPublicHoliday(): void
    {
        $this->clubWindow('10:00', '10:30');
        $holiday = $this->em->getRepository(PublicHoliday::class)->findOneBy(['zone' => 'NATIONAL', 'date' => new DateTimeImmutable($this->saturday)]);
        if (!$holiday instanceof PublicHoliday) {
            $this->em->persist(new PublicHoliday()->setZone('NATIONAL')->setDate(new DateTimeImmutable($this->saturday))->setLabel('Férié de test'));
            $this->em->flush();
        }
        $championship = $this->homeCompetitionFixture('18:00');
        $cup = $this->homeCompetitionFixture('21:30', CompetitionType::CUP);

        self::assertFalse($this->hasLeagueWindowViolation($championship->getId()), 'témoin : le radar voit le férié (la fenêtre de ligue du samedi s\'efface)');
        self::assertCount(1, $this->cupKickoffItems($cup->getId()), 'la borne des coupes vaut aussi un jour férié');
    }

    /** Le championnat du même décor reste borné par la fenêtre de ligue : la règle des coupes ne déborde pas. */
    public function testAChampionshipIsNeverJudgedOnTheCupHours(): void
    {
        $this->clubWindow('10:00', '10:30');
        $championship = $this->homeCompetitionFixture('21:30');

        self::assertTrue($this->hasLeagueWindowViolation($championship->getId()), 'un championnat hors fenêtre de ligue reste signalé');
        self::assertSame([], $this->cupKickoffItems($championship->getId()), 'un championnat ne porte jamais « coupe hors horaires »');
    }

    protected function setUp(): void
    {
        self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->radar = self::getContainer()->get(ConflictRadarLoader::class);

        $uid = uniqid('', true);
        $this->club = new Club;
        $this->club->setName('BC Radar Ligue ' . $uid);
        $this->club->setSlug('bc-radar-ligue-' . $uid);
        $this->club->setTimezone('Europe/Paris');
        $this->club->setLocale('fr');
        $this->club->setOnboardingCompleted(true);
        $this->em->persist($this->club);
        $this->em->flush();

        $this->scopeGucToClub($this->club->getId());

        $year = SeasonResolver::seasonYear(new DateTimeImmutable('today'));
        $this->season = new Season;
        $this->season->setClubId($this->club->getId());
        $this->season->setName((string) $year);
        $this->season->setStartDate(new DateTimeImmutable($year . '-08-01'));
        $this->season->setEndDate(new DateTimeImmutable(($year + 1) . '-07-15'));
        $this->season->setStatus(SeasonStatus::ACTIVE);
        $this->season->setTransitionData([]);
        $this->em->persist($this->season);

        $sport = $this->em->getRepository(Sport::class)->findOneBy(['isActive' => true]);
        if (!$sport instanceof Sport) {
            $sport = (new Sport)->setName('Basket ' . $uid)->setSlug('basket-' . $uid)->setIsActive(true);
            $this->em->persist($sport);
        }
        $category = new SportCategory;
        $category->setClubId($this->club->getId());
        $category->setSportId($sport->getId());
        $category->setName('U17M-' . $uid);
        $this->em->persist($category);

        $this->team = new Team;
        $this->team->setClubId($this->club->getId());
        $this->team->setSeasonId($this->season->getId());
        $this->team->setSportCategoryId($category->getId());
        $this->team->setPriorityTierId(3);
        $this->team->setName('U17M1');
        $this->team->setLevel(TeamLevel::REGIONAL);
        $this->team->setIsActive(true);
        $this->em->persist($this->team);
        $this->em->flush();
        self::getContainer()->get(PlanComposition::class)->ensureLines($this->team->getSeasonId(), [$this->team->getId() => ['sessionsPerWeek' => 2]]);
        $this->em->flush();
        SeasonPlanOf::id(self::getContainer(), $this->season->getId());

        $this->saturday = new DateTimeImmutable('today')->modify('next saturday')->format('Y-m-d');
    }

    private function categoryName(): string
    {
        $category = $this->em->getRepository(SportCategory::class)->find($this->team->getSportCategoryId());
        \assert($category instanceof SportCategory);

        return $category->getName();
    }

    private function clubWindow(string $kickoffMin, string $kickoffMax): void
    {
        $window = new ClubLeagueWindow;
        $window->setClubId($this->club->getId());
        $window->setSeasonId($this->season->getId());
        $window->setLeague('AURA');
        $window->setCategory($this->categoryName());
        $window->setLevel('REGIONAL');
        $window->setGender(null);
        $window->setDayOfWeek(6);
        $window->setKickoffMin(new DateTimeImmutable($kickoffMin));
        $window->setKickoffMax(new DateTimeImmutable($kickoffMax));
        $this->em->persist($window);
        $this->em->flush();
    }

    private function homeCompetitionFixture(string $kickoff, CompetitionType $type = CompetitionType::CHAMPIONSHIP, ?string $date = null): Fixture
    {
        $competition = $this->competitionFor($this->team->getId(), (CompetitionType::CUP === $type ? 'Coupe du Rhône ' : 'Régionale U17 ') . uniqid('', true), $type);

        $fixture = new Fixture;
        $fixture->setClubId($this->club->getId());
        $fixture->setSeasonId($this->season->getId());
        $fixture->setTeamId($this->team->getId());
        $fixture->setCompetitionId($competition->getId());
        $fixture->setMatchDate(new DateTimeImmutable($date ?? $this->saturday));
        $fixture->setHomeAway(FixtureHomeAway::HOME);
        $fixture->setOpponentLabel('Adversaire');
        $fixture->setKickoffTime(new DateTimeImmutable($kickoff));
        $this->em->persist($fixture);
        $this->em->flush();

        return $fixture;
    }

    private function hasLeagueWindowViolation(string $fixtureId): bool
    {
        $radar = $this->radar->conflicts($this->club->getId(), $this->season->getId());
        foreach ($radar['conflicts'] as $conflict) {
            if ('LEAGUE_WINDOW_VIOLATION' !== ($conflict['type'] ?? null)) {
                continue;
            }
            /** @var array<string, mixed> $fixture */
            $fixture = $conflict['fixture'] ?? [];
            if (($fixture['fixtureId'] ?? null) === $fixtureId) {
                return true;
            }
        }

        return false;
    }

    /** @return list<array<string, mixed>> les « coupe hors horaires » que le radar porte sur ce match */
    private function cupKickoffItems(string $fixtureId): array
    {
        $radar = $this->radar->conflicts($this->club->getId(), $this->season->getId());
        $items = [];
        foreach ($radar['conflicts'] as $conflict) {
            /** @var array<string, mixed> $fixture */
            $fixture = $conflict['fixture'] ?? [];
            if ('CUP_KICKOFF_OUT_OF_HOURS' === ($conflict['type'] ?? null) && ($fixture['fixtureId'] ?? null) === $fixtureId) {
                $items[] = $conflict;
            }
        }

        return $items;
    }
}
