<?php

declare(strict_types=1);

namespace App\Tests\CrossStack;

use App\Entity\Club;
use App\Entity\Season;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\TeamMatchHabit;
use App\Entity\Venue;
use App\Enum\MatchWeek;
use App\Enum\SeasonStatus;
use App\Service\MatchPlacementPayloadBuilder;
use App\Service\ScheduleConstraintBuilder;
use App\Service\SeasonResolver;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * NR BLOQUANT — axes backend↔engine contract + sémantique de contrainte (§7.1).
 *
 * P4-271 (ex-SlotRotationPayloadParityTest) : les créneaux de match partagés (rotations) ont
 * disparu. Ce que le club STOCKE (une {@see TeamMatchHabit} = le créneau idéal {jour, heure,
 * gymnase} d'une équipe, tagué semaine A/B) doit être EXACTEMENT ce que le payload
 * `/place-matches` émet dans `teams[].habits` — SANS le tag de semaine (aide visuelle qui ne
 * voyage JAMAIS au moteur) et SANS suppléance (la chaîne rotation est supprimée : toutes les
 * habitudes voyagent).
 *
 * Falsifié dans les DEUX sens :
 * - une habitude stockée DOIT apparaître (mêmes jour/heure/gymnase, jamais le tag `week`) ;
 * - une habitude d'un AUTRE club NE doit PAS fuir (RLS — un builder aveugle au tenant échoue) ;
 * - le `matchDay` émis au `/generate` == le jour ISO de l'habitude, repli champ déclaré
 *   `Team.matchDay` converti 0-based→ISO, sans habitude ni champ déclaré = null inchangé.
 */
#[Group('phase1')]
#[Group('integration')]
final class HabitPayloadParityTest extends KernelTestCase
{
    use TenantGucTrait;

    private EntityManagerInterface $em;

    private MatchPlacementPayloadBuilder $builder;

    private ScheduleConstraintBuilder $generateBuilder;

    /**
     * Sens 1 — l'habitude stockée est reflétée EXACTEMENT dans `teams[].habits`, et le tag de
     * semaine A/B ne VOYAGE PAS (un builder qui l'émettrait, ou qui ometterait l'habitude, échoue).
     */
    public function testStoredHabitIsEmittedWithoutTheWeekTag(): void
    {
        [$club, $season] = $this->seedClub();
        $venue = $this->venue($club, $season);
        $t1 = $this->team($club, $season);
        $this->habit($club, $season, $t1, 6, '20:30', MatchWeek::A, $venue);
        $this->em->flush();

        $payload = $this->builder->build($club, $season->getId())['payload'];

        $t1Row = $this->teamRow($payload, $t1->getId());
        self::assertSame(
            [['dayOfWeek' => 6, 'kickoff' => '20:30', 'venueId' => $venue->getId()]],
            $t1Row['habits'],
            'l\'habitude stockée est émise EXACTEMENT (jour/heure/gymnase) — jamais le tag `week`',
        );
    }

    /**
     * Sens 2 — l'habitude d'un AUTRE club ne fuit pas (RLS scopée par la GUC).
     */
    public function testHabitOfAnotherClubDoesNotLeak(): void
    {
        // Club B, avec sa propre habitude.
        [$clubB, $seasonB] = $this->seedClub();
        $venueB = $this->venue($clubB, $seasonB);
        $b1 = $this->team($clubB, $seasonB);
        $this->habit($clubB, $seasonB, $b1, 5, '18:00', MatchWeek::B, $venueB);
        $this->em->flush();

        // Club A, scopé après B.
        [$clubA, $seasonA] = $this->seedClub();
        $venueA = $this->venue($clubA, $seasonA);
        $a1 = $this->team($clubA, $seasonA);
        $this->habit($clubA, $seasonA, $a1, 6, '20:30', MatchWeek::A, $venueA);
        $this->em->flush();

        $payload = $this->builder->build($clubA, $seasonA->getId())['payload'];

        $emittedTeamIds = array_column($payload['teams'], 'id');
        self::assertContains($a1->getId(), $emittedTeamIds, 'A voit SA propre équipe');
        self::assertNotContains($b1->getId(), $emittedTeamIds, 'une équipe du club B ne doit pas fuir chez A');
        $a1Row = $this->teamRow($payload, $a1->getId());
        self::assertSame(
            [['dayOfWeek' => 6, 'kickoff' => '20:30', 'venueId' => $venueA->getId()]],
            $a1Row['habits'],
        );
    }

    // ───────────────────── VOLET DÉRIVATION (P4-271, /generate) ─────────────────────
    // Le matchDay ÉMIS au moteur d'entraînement (payload /generate, ScheduleConstraintBuilder)
    // est DÉRIVÉ de l'habitude : max(jours ISO des habitudes de l'équipe) — une équipe n'a plus
    // qu'UNE habitude (unicité club+saison+équipe). Le repos suit l'image (décision fondateur).

    /**
     * Habitude seule : le jour ISO de l'habitude gagne (max sur un seul élément).
     */
    public function testMatchDayDerivesTheHabitDay(): void
    {
        [$club, $season] = $this->seedClub();
        $tSat = $this->team($club, $season);
        $tSun = $this->team($club, $season);
        $this->habit($club, $season, $tSat, 6, '15:30', MatchWeek::A);
        $this->habit($club, $season, $tSun, 7, '11:00', MatchWeek::B);
        $this->em->flush();

        $payload = $this->generatePayload($club, $season);

        self::assertSame(6, $this->emittedMatchDay($payload, $tSat->getId()), 'habitude samedi → matchDay ISO 6');
        self::assertSame(7, $this->emittedMatchDay($payload, $tSun->getId()), 'habitude dimanche → matchDay ISO 7');
    }

    /**
     * Sans image : repli sur le champ déclaré `Team.matchDay`, 0-based (0 = lundi) CONVERTI en
     * ISO (+1). Un builder qui émettrait la valeur brute (5) au lieu de 6 échoue — c'est le bug
     * dormant (formule moteur `match_day % 7 + 1` juste en ISO seulement).
     */
    public function testDeclaredFieldFallbackIsConvertedZeroBasedToIso(): void
    {
        [$club, $season] = $this->seedClub();
        $team = $this->team($club, $season);
        $team->setMatchDay(5); // 0-based : 5 = samedi.
        $this->em->flush();

        $payload = $this->generatePayload($club, $season);

        self::assertSame(6, $this->emittedMatchDay($payload, $team->getId()), 'champ déclaré 5 (0-based samedi) → ISO 6');
    }

    /**
     * Ni habitude ni champ déclaré → null : le payload reste byte-identique au monde d'avant.
     */
    public function testNoImageNoDeclaredFieldEmitsNullUnchanged(): void
    {
        [$club, $season] = $this->seedClub();
        $team = $this->team($club, $season);
        $this->em->flush();

        $payload = $this->generatePayload($club, $season);

        self::assertNull(
            $this->emittedMatchDay($payload, $team->getId()),
            'aucune habitude ni champ déclaré ⇒ matchDay null (identique à avant)',
        );
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->builder = self::getContainer()->get(MatchPlacementPayloadBuilder::class);
        $this->generateBuilder = self::getContainer()->get(ScheduleConstraintBuilder::class);
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

    /**
     * Le payload /generate (ScheduleConstraintBuilder) — celui qui porte le `matchDay` dérivé.
     *
     * @return array<string, mixed>
     */
    private function generatePayload(Club $club, Season $season): array
    {
        return $this->generateBuilder->buildForClubSeason($club->getId(), $season->getId());
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function emittedMatchDay(array $payload, string $teamId): ?int
    {
        /** @var list<array{id: string, matchDay: int|null}> $teams */
        $teams = $payload['teams'];
        foreach ($teams as $row) {
            if ($row['id'] === $teamId) {
                return $row['matchDay'];
            }
        }
        self::fail('team row not found in /generate payload: ' . $teamId);
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

    private function habit(Club $club, Season $season, Team $team, int $dayOfWeek, string $kickoff, MatchWeek $week, ?Venue $venue = null): void
    {
        $habit = new TeamMatchHabit;
        $habit->setClubId($club->getId());
        $habit->setSeasonId($season->getId());
        $habit->setTeamId($team->getId());
        $habit->setDayOfWeek($dayOfWeek);
        $habit->setKickoffTime(new DateTimeImmutable($kickoff));
        $habit->setWeek($week);
        if ($venue instanceof Venue) {
            $habit->setVenueId($venue->getId());
        }
        $this->em->persist($habit);
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
        $club->setName('Habit Parity ' . $uid);
        $club->setSlug('habit-parity-' . $uid);
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
