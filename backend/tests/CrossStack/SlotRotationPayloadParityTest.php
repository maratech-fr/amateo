<?php

declare(strict_types=1);

namespace App\Tests\CrossStack;

use App\Entity\Club;
use App\Entity\MatchSlotRotation;
use App\Entity\MatchSlotRotationTeam;
use App\Entity\Season;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\TeamMatchHabit;
use App\Entity\Venue;
use App\Enum\SeasonStatus;
use App\Service\MatchPlacementPayloadBuilder;
use App\Service\SeasonResolver;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * NR BLOQUANT — axes backend↔engine contract + sémantique de contrainte (§7.1).
 *
 * RMM-5 (P2-49) rotation A/B PR-2 : ce que le club STOCKE (une {@see MatchSlotRotation} =
 * créneau physique {venue, jour, heure} + ses membres) doit être EXACTEMENT le bloc
 * `slotRotations` que le payload `/place-matches` émet au solveur, ET la SUPPLÉANCE
 * (tranchage 5) doit retirer l'habitude d'un membre le MÊME jour que sa rotation — jamais
 * les autres jours.
 *
 * Falsifié dans les DEUX sens :
 * - une rotation stockée DOIT apparaître (mêmes venue/jour/heure/teamIds ; un builder émettant
 *   [] échoue) ;
 * - une rotation d'un AUTRE club NE doit PAS fuir (RLS — un builder aveugle au tenant échoue) ;
 * - l'habitude même-jour d'un membre DOIT être supplantée (un builder qui n'applique pas la
 *   suppléance échoue) MAIS l'habitude d'un autre jour DOIT survivre (un builder qui sur-supprime
 *   échoue) ;
 * - sans rotation, le bloc est [] ET les habitudes restent intactes (le NR anti-régression du
 *   monde existant).
 */
#[Group('phase1')]
#[Group('integration')]
final class SlotRotationPayloadParityTest extends KernelTestCase
{
    use TenantGucTrait;

    private EntityManagerInterface $em;

    private MatchPlacementPayloadBuilder $builder;

    /**
     * Sens 1 — la rotation stockée est reflétée EXACTEMENT (un builder émettant [] échoue).
     */
    public function testStoredRotationIsEmitted(): void
    {
        [$club, $season] = $this->seedClub();
        $venue = $this->venue($club, $season);
        $t1 = $this->team($club, $season);
        $t2 = $this->team($club, $season);
        $this->rotation($club, $season, $venue, 6, '20:30', [$t1, $t2]);
        $this->em->flush();

        $payload = $this->builder->build($club, $season->getId())['payload'];

        self::assertSame(
            [[
                'venueId' => $venue->getId(),
                'dayOfWeek' => 6,
                'kickoff' => '20:30',
                'teamIds' => $this->sorted([$t1->getId(), $t2->getId()]),
            ]],
            $payload['slotRotations'],
            'la rotation stockée est émise EXACTEMENT (venue/jour/heure/teamIds triés)',
        );
    }

    /**
     * Sens 2 — la rotation d'un AUTRE club ne fuit pas (RLS scopée par la GUC).
     */
    public function testRotationOfAnotherClubDoesNotLeak(): void
    {
        // Club B, avec sa propre rotation.
        [$clubB, $seasonB] = $this->seedClub();
        $venueB = $this->venue($clubB, $seasonB);
        $b1 = $this->team($clubB, $seasonB);
        $b2 = $this->team($clubB, $seasonB);
        $this->rotation($clubB, $seasonB, $venueB, 5, '18:00', [$b1, $b2]);
        $this->em->flush();

        // Club A, scopé après B.
        [$clubA, $seasonA] = $this->seedClub();
        $venueA = $this->venue($clubA, $seasonA);
        $a1 = $this->team($clubA, $seasonA);
        $a2 = $this->team($clubA, $seasonA);
        $this->rotation($clubA, $seasonA, $venueA, 6, '20:30', [$a1, $a2]);
        $this->em->flush();

        $payload = $this->builder->build($clubA, $seasonA->getId())['payload'];

        self::assertCount(1, $payload['slotRotations'], 'A ne voit que SA rotation');
        $emittedTeamIds = array_merge(...array_column($payload['slotRotations'], 'teamIds'));
        self::assertNotContains($b1->getId(), $emittedTeamIds, 'un membre du club B ne doit pas fuir chez A');
        self::assertNotContains($b2->getId(), $emittedTeamIds);
    }

    /**
     * Suppléance : l'habitude même-jour d'un membre est SUPPLANTÉE, celle d'un autre jour survit.
     */
    public function testSameDayMemberHabitIsSupplantedButOtherDaysSurvive(): void
    {
        [$club, $season] = $this->seedClub();
        $venue = $this->venue($club, $season);
        $t1 = $this->team($club, $season);
        $t2 = $this->team($club, $season);
        // t1 membre d'une rotation le samedi (jour 6).
        $this->rotation($club, $season, $venue, 6, '20:30', [$t1, $t2]);
        // t1 a une habitude le samedi (SUPPLANTÉE) et une le dimanche (jour 7, CONSERVÉE).
        $this->habit($club, $season, $t1, 6, '15:30');
        $this->habit($club, $season, $t1, 7, '11:00');
        $this->em->flush();

        $payload = $this->builder->build($club, $season->getId())['payload'];

        $t1Row = $this->teamRow($payload, $t1->getId());
        $emittedDays = array_column($t1Row['habits'], 'dayOfWeek');
        self::assertNotContains(6, $emittedDays, 'l\'habitude du jour de la rotation est supplantée');
        self::assertContains(7, $emittedDays, 'l\'habitude d\'un AUTRE jour reste émise');
        self::assertCount(1, $t1Row['habits'], 'seule l\'habitude non-rotation survit');
        // Et la rotation, elle, est bien émise.
        self::assertCount(1, $payload['slotRotations']);
    }

    /**
     * Sans rotation : bloc [] ET habitudes intactes (le monde d'avant, byte-identique).
     */
    public function testNoRotationEmitsEmptyBlockAndLeavesHabitsIntact(): void
    {
        [$club, $season] = $this->seedClub();
        $this->venue($club, $season);
        $t1 = $this->team($club, $season);
        $this->habit($club, $season, $t1, 6, '15:30');
        $this->em->flush();

        $payload = $this->builder->build($club, $season->getId())['payload'];

        self::assertSame([], $payload['slotRotations'], 'aucune rotation ⇒ bloc []');
        $t1Row = $this->teamRow($payload, $t1->getId());
        self::assertSame(
            [['dayOfWeek' => 6, 'kickoff' => '15:30', 'venueId' => null]],
            $t1Row['habits'],
            'sans rotation, aucune habitude n\'est supplantée',
        );
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->builder = self::getContainer()->get(MatchPlacementPayloadBuilder::class);
    }

    /**
     * @param list<string> $ids
     *
     * @return list<string>
     */
    private function sorted(array $ids): array
    {
        sort($ids);

        return $ids;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{id: string, name: string, leagueWindows: list<mixed>, habits: list<array{dayOfWeek: int, kickoff: string, venueId: string|null}>, coaches: list<mixed>}
     */
    private function teamRow(array $payload, string $teamId): array
    {
        /** @var list<array{id: string, name: string, leagueWindows: list<mixed>, habits: list<array{dayOfWeek: int, kickoff: string, venueId: string|null}>, coaches: list<mixed>}> $teams */
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

    private function venue(Club $club, Season $season): Venue
    {
        $venue = new Venue;
        $venue->setClubId($club->getId());
        $venue->setSeasonId($season->getId());
        $venue->setName('V' . substr($this->uuid(), 0, 6));
        $venue->setSource('manual');
        $this->em->persist($venue);

        return $venue;
    }

    private function habit(Club $club, Season $season, Team $team, int $dayOfWeek, string $kickoff): void
    {
        $habit = new TeamMatchHabit;
        $habit->setClubId($club->getId());
        $habit->setSeasonId($season->getId());
        $habit->setTeamId($team->getId());
        $habit->setDayOfWeek($dayOfWeek);
        $habit->setKickoffTime(new DateTimeImmutable($kickoff));
        $this->em->persist($habit);
    }

    /**
     * @param list<Team> $teams
     */
    private function rotation(Club $club, Season $season, Venue $venue, int $dayOfWeek, string $kickoff, array $teams): MatchSlotRotation
    {
        $rotation = new MatchSlotRotation;
        $rotation->setClubId($club->getId());
        $rotation->setSeasonId($season->getId());
        $rotation->setVenueId($venue->getId());
        $rotation->setDayOfWeek($dayOfWeek);
        $rotation->setKickoffTime(new DateTimeImmutable($kickoff));
        $this->em->persist($rotation);

        $position = 0;
        foreach ($teams as $team) {
            $member = new MatchSlotRotationTeam;
            $member->setClubId($club->getId());
            $member->setSeasonId($season->getId());
            $member->setRotationId($rotation->getId());
            $member->setTeamId($team->getId());
            $member->setPosition($position++);
            $this->em->persist($member);
        }

        return $rotation;
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
        $club->setName('Rotation Parity ' . $uid);
        $club->setSlug('rotation-parity-' . $uid);
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

        // Un sport actif est requis par le résolveur d'enveloppe même si nos équipes
        // ne mappent aucune fenêtre (envelope = [] + diagnostic INFO, comme le test contrat).
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
