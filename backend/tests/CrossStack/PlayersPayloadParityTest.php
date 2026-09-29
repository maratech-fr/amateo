<?php

declare(strict_types=1);

namespace App\Tests\CrossStack;

use App\Entity\Club;
use App\Entity\CoachPlayerMembership;
use App\Entity\Season;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\TeamCoach;
use App\Enum\SeasonStatus;
use App\Enum\TeamCoachRole;
use App\Service\MatchConflictDetector;
use App\Service\MatchPlacementPayloadBuilder;
use App\Service\SeasonResolver;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * NR BLOQUANT — axe backend↔engine contract + auth & memberships (§7.1).
 *
 * P4-240 ③ (décision A) : ce que le club STOCKE (les {@see CoachPlayerMembership} ACTIFS)
 * doit être EXACTEMENT le bloc `teams[].players` que le payload `/place-matches` émet au
 * solveur, avec la règle du radar ({@see MatchConflictDetector}) : le rôle
 * COACH gagne sur PLAYER pour la même équipe (une personne coach ET joueuse de la même
 * équipe est émise UNE fois, côté coach, jamais en double malus).
 *
 * Falsifié dans les DEUX sens :
 * - un membership ACTIF stocké DOIT apparaître dans `teams[].players` (un builder l'oubliant
 *   échoue) ;
 * - un membership INACTIF NE doit PAS apparaître (un builder aveugle au drapeau actif échoue) ;
 * - une personne qui COACHE la même équipe est ABSENTE des players (le coach gagne — un
 *   builder qui l'émettrait en double échoue) ;
 * - les players d'un AUTRE club NE fuient PAS (RLS — un builder aveugle au tenant échoue).
 */
#[Group('phase1')]
#[Group('integration')]
final class PlayersPayloadParityTest extends KernelTestCase
{
    use TenantGucTrait;

    private EntityManagerInterface $em;

    private MatchPlacementPayloadBuilder $builder;

    /**
     * Sens 1 — les memberships ACTIFS sont émis EXACTEMENT (person ids triés) ; un
     * builder qui n'émet pas les joueurs échoue.
     */
    public function testActiveMembershipsAreEmittedAsPlayers(): void
    {
        [$club, $season] = $this->seedClub();
        $team = $this->team($club, $season);
        $p1 = $this->membership($club, $season, $team, true);
        $p2 = $this->membership($club, $season, $team, true);
        $this->em->flush();

        $expected = [$p1, $p2];
        sort($expected);
        self::assertSame(
            $expected,
            $this->teamRow($this->builder->build($club, $season->getId())['payload'], $team->getId())['players'],
        );
    }

    /**
     * Sens 2 — un membership INACTIF est exclu (un builder aveugle au drapeau actif échoue).
     */
    public function testInactiveMembershipIsExcluded(): void
    {
        [$club, $season] = $this->seedClub();
        $team = $this->team($club, $season);
        $active = $this->membership($club, $season, $team, true);
        $this->membership($club, $season, $team, false); // inactive → excluded
        $this->em->flush();

        self::assertSame(
            [$active],
            $this->teamRow($this->builder->build($club, $season->getId())['payload'], $team->getId())['players'],
        );
    }

    /**
     * Le coach gagne : une personne qui COACHE la même équipe est ABSENTE des players (émise
     * une seule fois, côté coach — jamais en double malus). Un builder qui la doublerait échoue.
     */
    public function testAPersonCoachingTheSameTeamIsExcludedFromPlayers(): void
    {
        [$club, $season] = $this->seedClub();
        $team = $this->team($club, $season);
        // La même personne COACHE l'équipe ET est listée comme joueuse → exclue des players.
        $coachPerson = $this->uuid();
        $this->teamCoach($club, $season, $team, $coachPerson);
        $this->membership($club, $season, $team, true, $coachPerson);
        // Une joueuse pure → présente.
        $player = $this->membership($club, $season, $team, true);
        $this->em->flush();

        $teamRow = $this->teamRow($this->builder->build($club, $season->getId())['payload'], $team->getId());
        self::assertSame([$player], $teamRow['players'], 'le coach de la même équipe ne double pas côté players');
        // …et reste bien émis côté coaches (la personne n'est pas perdue).
        $coachIds = array_column($teamRow['coaches'], 'coachId');
        self::assertContains($coachPerson, $coachIds);
    }

    /**
     * Sens 4 — les players d'un AUTRE club ne fuient pas (RLS scopée par la GUC).
     */
    public function testPlayersOfAnotherClubDoNotLeak(): void
    {
        // Club B, avec sa propre joueuse.
        [$clubB, $seasonB] = $this->seedClub();
        $teamB = $this->team($clubB, $seasonB);
        $playerB = $this->membership($clubB, $seasonB, $teamB, true);
        $this->em->flush();

        // Club A, scopé après B.
        [$clubA, $seasonA] = $this->seedClub();
        $teamA = $this->team($clubA, $seasonA);
        $playerA = $this->membership($clubA, $seasonA, $teamA, true);
        $this->em->flush();

        $payload = $this->builder->build($clubA, $seasonA->getId())['payload'];

        $allPlayers = array_merge([], ...array_column($payload['teams'], 'players'));
        self::assertContains($playerA, $allPlayers, 'A voit SA joueuse');
        self::assertNotContains($playerB, $allPlayers, 'une joueuse du club B ne doit pas fuir chez A');
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->builder = self::getContainer()->get(MatchPlacementPayloadBuilder::class);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{id: string, players: list<string>, coaches: list<array{coachId: string, role: string}>}
     */
    private function teamRow(array $payload, string $teamId): array
    {
        /** @var list<array{id: string, players: list<string>, coaches: list<array{coachId: string, role: string}>}> $teams */
        $teams = $payload['teams'];
        foreach ($teams as $row) {
            if ($row['id'] === $teamId) {
                return $row;
            }
        }
        self::fail('team row not found in payload: ' . $teamId);
    }

    private function team(Club $club, Season $season): Team
    {
        $team = new Team;
        $team->setClubId($club->getId());
        $team->setSeasonId($season->getId());
        $team->setSportCategoryId($this->uuid());
        $team->setPriorityTierId(3);
        $team->setName('T' . substr($this->uuid(), 0, 6));
        $team->setSessionsPerWeek(2);
        $team->setIsActive(true);
        $this->em->persist($team);

        return $team;
    }

    /** Persists a membership and returns the person (coach) id — a real UUID (the column is a guid). */
    private function membership(Club $club, Season $season, Team $team, bool $active, ?string $personId = null): string
    {
        $personId ??= $this->uuid();
        $membership = new CoachPlayerMembership;
        $membership->setClubId($club->getId());
        $membership->setSeasonId($season->getId());
        $membership->setTeamId($team->getId());
        $membership->setCoachId($personId);
        $membership->setIsActive($active);
        $this->em->persist($membership);

        return $personId;
    }

    private function teamCoach(Club $club, Season $season, Team $team, string $personId): void
    {
        $link = new TeamCoach;
        $link->setClubId($club->getId());
        $link->setSeasonId($season->getId());
        $link->setTeamId($team->getId());
        $link->setCoachId($personId);
        $link->setRole(TeamCoachRole::MAIN);
        $this->em->persist($link);
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /**
     * @return array{0: Club, 1: Season}
     */
    private function seedClub(): array
    {
        $uid = uniqid('', true);

        $club = new Club;
        $club->setName('Players Parity ' . $uid);
        $club->setSlug('players-parity-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode('ARA' . strtoupper(substr(md5($uid), 0, 8)));
        $this->em->persist($club);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());

        $season = new Season;
        $season->setClubId($club->getId());
        $year = SeasonResolver::seasonYear(new DateTimeImmutable('today'));
        $season->setName((string) $year);
        $season->setStartDate(new DateTimeImmutable($year . '-08-01'));
        $season->setEndDate(new DateTimeImmutable(($year + 1) . '-07-15'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $season->setTransitionData([]);
        $this->em->persist($season);

        // Un sport actif est requis par le résolveur d'enveloppe (même rituel que
        // HabitPayloadParityTest) même si nos équipes ne mappent aucune fenêtre.
        $sport = $this->em->getRepository(Sport::class)->findOneBy(['isActive' => true]);
        if (!$sport instanceof Sport) {
            $sport = new Sport;
            $sport->setName('Basket ' . $uid);
            $sport->setSlug('basket-' . $uid);
            $sport->setIsActive(true);
            $this->em->persist($sport);
        }
        $category = new SportCategory;
        $category->setClubId($club->getId());
        $category->setSportId($sport->getId());
        $category->setName('U13-' . $uid);
        $this->em->persist($category);
        $this->em->flush();

        return [$club, $season];
    }
}
