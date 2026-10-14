<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\Club;
use App\Entity\ClubLeagueWindow;
use App\Entity\Competition;
use App\Entity\Fixture;
use App\Entity\Season;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Enum\CompetitionType;
use App\Enum\FixtureHomeAway;
use App\Enum\SeasonStatus;
use App\Enum\TeamLevel;
use App\Service\ConflictRadarLoader;
use App\Service\LeagueEnvelopeResolver;
use App\Service\SeasonResolver;
use App\Tests\Integration\Api\LeagueMatchWindowsApiTest;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
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
 */
#[Group('integration')]
final class ConflictRadarLeagueWindowTest extends WebTestCase
{
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
        $this->team->setSessionsPerWeek(2);
        $this->team->setIsActive(true);
        $this->em->persist($this->team);
        $this->em->flush();

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

    private function homeCompetitionFixture(string $kickoff): Fixture
    {
        $competition = new Competition;
        $competition->setClubId($this->club->getId());
        $competition->setSeasonId($this->season->getId());
        $competition->setTeamId($this->team->getId());
        $competition->setName('Coupe du Rhône ' . uniqid('', true));
        $competition->setCompetitionType(CompetitionType::CUP);
        $this->em->persist($competition);
        $this->em->flush();

        $fixture = new Fixture;
        $fixture->setClubId($this->club->getId());
        $fixture->setSeasonId($this->season->getId());
        $fixture->setTeamId($this->team->getId());
        $fixture->setCompetitionId($competition->getId());
        $fixture->setMatchDate(new DateTimeImmutable($this->saturday));
        $fixture->setHomeAway(FixtureHomeAway::HOME);
        $fixture->setOpponentLabel('Adversaire coupe');
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
}
