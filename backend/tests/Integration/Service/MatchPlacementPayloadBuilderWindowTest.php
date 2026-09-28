<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\Club;
use App\Entity\Competition;
use App\Entity\Fixture;
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
use App\Service\MatchPlacementPayloadBuilder;
use App\Service\SeasonResolver;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * P4-240 ④ — la fenêtre optionnelle du builder de placement (« Placer ce week-end »).
 *
 * Trois domiciles de compétition, un DANS la fenêtre et deux HORS :
 *   - dans la fenêtre → TO_PLACE (à placer) ;
 *   - hors fenêtre, DÉJÀ posé par le solveur (PLACED+SOLVER) → FIXED (ancre : sa salle
 *     reste protégée, il ne bouge pas — le moteur ne le renvoie jamais, l'applier ne le
 *     réécrit pas) ; c'est le cœur du besoin : un match d'un AUTRE week-end ne se re-résout
 *     plus ;
 *   - hors fenêtre, NON posé (UNPLACED) → absent du payload.
 *
 * Et le témoin de non-régression : SANS fenêtre, les trois repartent TO_PLACE — le
 * comportement d'avant, à l'octet.
 */
#[Group('integration')]
final class MatchPlacementPayloadBuilderWindowTest extends WebTestCase
{
    use TenantGucTrait;

    private EntityManagerInterface $em;

    private MatchPlacementPayloadBuilder $builder;

    public function testWindowKeepsInWindowToPlaceFreezesOutOfWindowSolverAndDropsOutOfWindowUnplaced(): void
    {
        [$club, $season, $team] = $this->seedClubAndTeam();
        $venue = $this->createVenue($club->getId(), $season->getId());

        $inWindow = $this->createFixture($club->getId(), $season->getId(), $team->getId(), '2026-11-07');
        $outSolver = $this->createFixture($club->getId(), $season->getId(), $team->getId(), '2026-11-21');
        $outSolver->setVenueId($venue->getId());
        $outSolver->setKickoffTime(new DateTimeImmutable('16:00'));
        $outSolver->setStatus(FixtureStatus::PLACED, new DateTimeImmutable);
        $outSolver->setPlacementSource(FixturePlacementSource::SOLVER);
        $outUnplaced = $this->createFixture($club->getId(), $season->getId(), $team->getId(), '2026-11-28');
        $this->em->flush();

        // ── Avec fenêtre Lun 2 → Dim 8 novembre ──────────────────────────────────
        $windowed = $this->matchesById($this->builder->build($club, $season->getId(), ['from' => '2026-11-02', 'to' => '2026-11-08']));

        self::assertArrayHasKey($inWindow->getId(), $windowed, 'le match DANS la fenêtre doit être émis');
        self::assertSame('TO_PLACE', $windowed[$inWindow->getId()]['kind'], 'un domicile dans la fenêtre est à placer');

        self::assertArrayHasKey($outSolver->getId(), $windowed, 'un domicile posé par le solveur hors fenêtre reste émis (ancre)');
        self::assertSame('FIXED', $windowed[$outSolver->getId()]['kind'], 'un PLACED+SOLVER hors fenêtre devient une ANCRE, il n\'est pas re-résolu');
        self::assertSame($venue->getId(), $windowed[$outSolver->getId()]['venueId'], 'l\'ancre garde sa salle');
        self::assertSame('16:00', $windowed[$outSolver->getId()]['kickoff'], 'l\'ancre garde son coup d\'envoi');

        self::assertArrayNotHasKey($outUnplaced->getId(), $windowed, 'un domicile NON posé hors fenêtre disparaît du payload');

        // ── Sans fenêtre : les trois repartent TO_PLACE (comportement d'avant) ─────
        $wide = $this->matchesById($this->builder->build($club, $season->getId(), null));

        self::assertSame('TO_PLACE', $wide[$inWindow->getId()]['kind']);
        self::assertSame('TO_PLACE', $wide[$outSolver->getId()]['kind'], 'sans fenêtre, un PLACED+SOLVER est re-résolu (TO_PLACE) — comme avant');
        self::assertSame($venue->getId(), $wide[$outSolver->getId()]['currentVenueId'], 'le TO_PLACE porte son placement courant en indice');
        self::assertSame('TO_PLACE', $wide[$outUnplaced->getId()]['kind'], 'sans fenêtre, un UNPLACED est bien à placer');
    }

    protected function setUp(): void
    {
        self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $this->em = $em;
        $builder = self::getContainer()->get(MatchPlacementPayloadBuilder::class);
        \assert($builder instanceof MatchPlacementPayloadBuilder);
        $this->builder = $builder;
    }

    /**
     * @param array{payload: array<string, mixed>, toPlaceCount: int, infoDiagnostics: list<array<string, mixed>>} $built
     *
     * @return array<string, array<string, mixed>>
     */
    private function matchesById(array $built): array
    {
        $byId = [];
        /** @var list<array<string, mixed>> $matches */
        $matches = $built['payload']['matches'];
        foreach ($matches as $match) {
            /** @var string $id */
            $id = $match['id'];
            $byId[$id] = $match;
        }

        return $byId;
    }

    /**
     * @return array{0: Club, 1: Season, 2: Team}
     */
    private function seedClubAndTeam(): array
    {
        $uid = uniqid('', true);
        $club = new Club;
        $club->setName('BC Window ' . $uid);
        $club->setSlug('bc-window-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $this->em->persist($club);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());

        $year = SeasonResolver::seasonYear(new DateTimeImmutable('2026-11-07'));
        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName((string) $year);
        $season->setStartDate(new DateTimeImmutable($year . '-08-01'));
        $season->setEndDate(new DateTimeImmutable(($year + 1) . '-07-15'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $season->setTransitionData([]);
        $this->em->persist($season);
        $this->em->flush();

        $sport = $this->em->getRepository(Sport::class)->findOneBy(['isActive' => true]);
        if (!$sport instanceof Sport) {
            $sport = (new Sport)->setName('Basket ' . $uid)->setSlug('basket-' . $uid)->setIsActive(true);
            $this->em->persist($sport);
        }
        $category = new SportCategory;
        $category->setClubId($club->getId());
        $category->setSportId($sport->getId());
        $category->setName('U13-' . $uid);
        $this->em->persist($category);

        $team = new Team;
        $team->setClubId($club->getId());
        $team->setSeasonId($season->getId());
        $team->setSportCategoryId($category->getId());
        $team->setPriorityTierId(3);
        $team->setName('SF3');
        $team->setSessionsPerWeek(2);
        $team->setIsActive(true);
        $this->em->persist($team);
        $this->em->flush();

        return [$club, $season, $team];
    }

    private function createVenue(string $clubId, string $seasonId): Venue
    {
        $venue = new Venue;
        $venue->setClubId($clubId);
        $venue->setSeasonId($seasonId);
        $venue->setName('Mateo');
        $venue->setSource('manual');
        $this->em->persist($venue);
        $this->em->flush();

        return $venue;
    }

    private function createFixture(string $clubId, string $seasonId, string $teamId, string $date): Fixture
    {
        $competition = new Competition;
        $competition->setClubId($clubId);
        $competition->setSeasonId($seasonId);
        $competition->setTeamId($teamId);
        $competition->setName('D2-' . uniqid('', true));
        $competition->setCompetitionType(CompetitionType::CHAMPIONSHIP);
        $this->em->persist($competition);
        $this->em->flush();

        $fixture = new Fixture;
        $fixture->setClubId($clubId);
        $fixture->setSeasonId($seasonId);
        $fixture->setTeamId($teamId);
        $fixture->setCompetitionId($competition->getId());
        $fixture->setMatchDate(new DateTimeImmutable($date));
        $fixture->setHomeAway(FixtureHomeAway::HOME);
        $fixture->setOpponentLabel('Adv');
        $this->em->persist($fixture);
        $this->em->flush();

        return $fixture;
    }
}
