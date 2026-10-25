<?php

declare(strict_types=1);

namespace App\Tests\CrossStack;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Coach;
use App\Entity\Reservation;
use App\Entity\Season;
use App\Entity\Team;
use App\Entity\TeamCoach;
use App\Entity\User;
use App\Entity\Venue;
use App\Entity\VenueTrainingSlot;
use App\Entity\VenueTravelTime;
use App\Enum\SeasonStatus;
use App\Enum\TeamCoachRole;
use App\Service\ScheduleConstraintBuilder;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * Chantier H — NR BLOQUANT (axe §7.1 « constraint semantics ») : « le relais de l'adjoint tolère un
 * retard » est HONORÉ par le VRAI moteur `/generate` (contrat 1.6). La tolérance saisie par le
 * gestionnaire (`Reservation.coachLateToleranceMinutes`, portée sur `slotTemplates`) autorise le
 * coach principal à ARRIVER EN RETARD à la séance qu'il rejoint, À CONDITION qu'un adjoint
 * disponible ouvre la séance — l'adjoint devient l'occupant de la fenêtre `[début, début + retard[`
 * côté moteur. Le cas réel Rillieux : Sébastien finit à 20:15 au Mont Blanc et rejoint le Louison
 * Bobet (séance 20:00) ; trajet 15 min, chevauchement 15 min → retard 30, toléré.
 *
 * TROIS promesses, chacune falsifiable contre le moteur réel :
 *  (1) TÉMOIN — sans tolérance (0), le coach principal est VERROUILLÉ sur la séance réservée : le
 *      solveur refuse de le placer AILLEURS au même moment (gymnases différents), l'autre séance
 *      reste non placée.
 *  (2) TOLÉRANCE + ADJOINT — la tolérance 30 (retard calculé = fin(A) + barème − début(B) =
 *      20:15 + 15 − 20:00 = 30) + un adjoint ASSISTANT disponible sur la séance réservée libèrent
 *      le coach pendant `[20:00, 20:30[` : l'autre séance EST placée.
 *  (3) CONTRE-TÉMOIN — la MÊME tolérance 30 SANS adjoint ne libère rien (l'adjoint doit être
 *      VÉRIFIÉ) : l'autre séance reste non placée. La tolérance seule ne suffit jamais.
 *
 * Preuve de sérialisation (contrat 1.6) pliée ici : le payload réel émis par le builder porte
 * `coachLateToleranceMinutes` sur le slotTemplate de la réservation tolérée (sinon la tolérance ne
 * voyagerait jamais jusqu'au moteur, et (2)/(3) seraient vrais pour la mauvaise raison).
 *
 * Étape NOMMÉE du job `blocking-tests` de `.github/workflows/ci.yml` (step `engine-semantics`,
 * groupe `contract`) ET ligne de `docs/testing/blocking-tests.md` — gardé par
 * `BlockingTestsListMatchesCiTest`. Groupe `phase1` (--group phase1) + `contract`. Skip propre si
 * le moteur est absent. Patron : {@see SeasonSlotKeepSemanticsGateTest}.
 */
#[Group('phase1')]
#[Group('contract')]
final class CoachLateToleranceSemanticsGateTest extends KernelTestCase
{
    use TenantGucTrait;

    private const string ENGINE_URL = 'http://engine:8000/generate';

    private const string SPORT_CATEGORY_ID = '99999999-9999-4999-8999-999999999999';

    private EntityManagerInterface $em;

    private ScheduleConstraintBuilder $builder;

    private ?CacheItemPoolInterface $scheduleCache = null;

    public function testAnAvailableAdjointLetsTheLateCoachJoinWhileWithoutToleranceHeCannot(): void
    {
        [$club, $season, $teamA] = $this->seedRelayScenario(tolerance: 30, withAdjoint: true);

        // Le payload réel PORTE la tolérance sur le slotTemplate de la réservation (contrat 1.6).
        $payload = $this->build($club, $season);
        self::assertSame(
            30,
            $this->toleranceOfReservedSlot($payload),
            'contrat 1.6 : la tolérance doit voyager sur le slotTemplate de la réservation, sinon le moteur ne la voit jamais',
        );

        // (1) TÉMOIN — sans tolérance : le coach verrouillé ne peut coacher ailleurs en même temps,
        // la séance de l'équipe A reste non placée.
        $witness = $this->solve($this->stripTolerance($payload));
        self::assertNull(
            $this->dayOfTeam($witness, $teamA->getId()),
            'témoin cassé : sans tolérance, le coach verrouillé interdit la séance de A (gymnases différents, même moment)',
        );

        // (2) TOLÉRANCE + ADJOINT — l'adjoint ouvre la séance réservée, le coach rejoint en retard :
        // la séance de A est placée son jour (mardi).
        $tolerated = $this->solve($payload);
        self::assertSame(
            2,
            $this->dayOfTeam($tolerated, $teamA->getId()),
            'avec tolérance + adjoint disponible, la séance de A doit être placée (le coach la rejoint en retard)',
        );
    }

    public function testToleranceWithoutAnAvailableAdjointStillBlocks(): void
    {
        // (3) CONTRE-TÉMOIN — même tolérance 30, mais AUCUN adjoint sur la séance réservée : rien ne
        // l'ouvre, le coach reste verrouillé, la séance de A reste non placée. L'adjoint est VÉRIFIÉ.
        [$club, $season, $teamA] = $this->seedRelayScenario(tolerance: 30, withAdjoint: false);

        $result = $this->solve($this->build($club, $season));
        self::assertNull(
            $this->dayOfTeam($result, $teamA->getId()),
            'la tolérance sans adjoint disponible ne libère rien : la séance de A ne doit PAS être placée',
        );
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->builder = self::getContainer()->get(ScheduleConstraintBuilder::class);
        $pool = self::getContainer()->get('cache.schedule');
        $this->scheduleCache = $pool instanceof CacheItemPoolInterface ? $pool : null;
    }

    /** @return array<string, mixed> */
    private function build(Club $club, Season $season): array
    {
        $this->scheduleCache?->clear();

        return $this->builder->buildForClubSeason($club->getId(), $season->getId());
    }

    /**
     * La tolérance portée par le slotTemplate issu de la réservation tolérée (l'unique créneau de
     * 20:00). Rend null si le champ n'existe pas encore — ce qui fait rougir l'assertion de contrat.
     *
     * @param array<string, mixed> $payload
     */
    private function toleranceOfReservedSlot(array $payload): ?int
    {
        foreach ($payload['slotTemplates'] ?? [] as $slot) {
            if (str_starts_with((string) ($slot['startTime'] ?? ''), '20:00')) {
                return $slot['coachLateToleranceMinutes'] ?? null;
            }
        }

        return null;
    }

    /**
     * Met la tolérance à 0 sur tous les slotTemplates — le TÉMOIN « sans tolérance ».
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function stripTolerance(array $payload): array
    {
        $payload['slotTemplates'] = array_map(static function (array $slot): array {
            $slot['coachLateToleranceMinutes'] = 0;

            return $slot;
        }, $payload['slotTemplates'] ?? []);

        return $payload;
    }

    /**
     * @param array<string, mixed> $result
     */
    private function dayOfTeam(array $result, string $teamId): ?int
    {
        foreach ($result['slots'] as $slot) {
            if (($slot['teamId'] ?? null) === $teamId) {
                return (int) $slot['dayOfWeek'];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function solve(array $payload): array
    {
        $client = HttpClient::create(['timeout' => 30]);

        try {
            $response = $client->request('POST', self::ENGINE_URL, ['json' => $payload]);
            self::assertSame(200, $response->getStatusCode());
            $result = $response->toArray(false);
            self::assertSame('completed', $result['status'], 'le scénario de génération doit rester résoluble');

            return $result;
        } catch (TransportExceptionInterface $exception) {
            self::markTestSkipped('Engine not available: ' . $exception->getMessage());
        }
    }

    /**
     * Le décor du relais : équipe A (séance libre, seul créneau mardi 18:45-20:15 au gymnase 1),
     * équipe B (réservation HARD mardi 20:00-21:30 au gymnase 2, porteuse de la tolérance), coach C
     * principal des DEUX, adjoint J (ASSISTANT) sur B (optionnel), barème de trajet gym1↔gym2 = 15
     * min à pied (C non véhiculé) → retard = 20:15 + 15 − 20:00 = 30.
     *
     * @return array{0: Club, 1: Season, 2: Team}
     */
    private function seedRelayScenario(int $tolerance, bool $withAdjoint): array
    {
        [$club, $season] = $this->seed('relay-' . ($withAdjoint ? 'adjoint' : 'solo'));
        $gym1 = $this->venue($club, $season, 'Mont Blanc');
        $gym2 = $this->venue($club, $season, 'Louison Bobet');
        // Seul créneau de l'équipe A : mardi 18:45-20:15 au gymnase 1.
        $this->trainingSlot($club, $season, $gym1, 2, '18:45');

        $teamA = $this->team($club, $season, 'A');
        $teamB = $this->team($club, $season, 'B');

        $coachC = $this->coach($club, $season, 'Sébastien', vehicled: false);
        $this->teamCoach($club, $season, $teamA->getId(), $coachC->getId(), TeamCoachRole::MAIN);
        $this->teamCoach($club, $season, $teamB->getId(), $coachC->getId(), TeamCoachRole::MAIN);
        if ($withAdjoint) {
            $coachJ = $this->coach($club, $season, 'Jules', vehicled: false);
            $this->teamCoach($club, $season, $teamB->getId(), $coachJ->getId(), TeamCoachRole::ASSISTANT);
        }

        // Barème de trajet gym1↔gym2 : 15 min à pied (couple trié venueAId < venueBId).
        $this->travelTime($club, $season, $gym1->getId(), $gym2->getId(), walkingMinutes: 15);

        // Réservation HARD de l'équipe B (mardi 20:00-21:30 au gymnase 2), porteuse de la tolérance.
        $reservation = (new Reservation)
            ->setClubId($club->getId())->setSeasonId($season->getId())
            ->setTeamId($teamB->getId())->setVenueId($gym2->getId())
            ->setDayOfWeek(2)->setStartTime(new DateTimeImmutable('20:00'))->setDurationMinutes(90)
            ->setCoachLateToleranceMinutes($tolerance);
        $this->em->persist($reservation);
        $this->em->flush();

        return [$club, $season, $teamA];
    }

    private function venue(Club $club, Season $season, string $name): Venue
    {
        $venue = (new Venue)->setClubId($club->getId())->setSeasonId($season->getId())->setName($name)->setSource('manual');
        $this->em->persist($venue);

        return $venue;
    }

    private function trainingSlot(Club $club, Season $season, Venue $venue, int $day, string $start): void
    {
        $slot = (new VenueTrainingSlot)
            ->setClubId($club->getId())->setSeasonId($season->getId())->setVenueId($venue->getId())
            ->setDayOfWeek($day)->setStartTime(new DateTimeImmutable($start))->setDurationMinutes(90)->setCapacity(1);
        $this->em->persist($slot);
    }

    private function team(Club $club, Season $season, string $name): Team
    {
        $team = (new Team)->setClubId($club->getId())->setSeasonId($season->getId())->setName($name)
            ->setSportCategoryId(self::SPORT_CATEGORY_ID)->setPriorityTierId(1)->setSessionsPerWeek(1);
        $this->em->persist($team);

        return $team;
    }

    private function coach(Club $club, Season $season, string $firstName, bool $vehicled): Coach
    {
        $coach = (new Coach)
            ->setClubId($club->getId())->setSeasonId($season->getId())
            ->setFirstName($firstName)->setLastName('Test')->setIsActive(true)->setIsVehicled($vehicled);
        $this->em->persist($coach);

        return $coach;
    }

    private function teamCoach(Club $club, Season $season, string $teamId, string $coachId, TeamCoachRole $role): void
    {
        $link = (new TeamCoach)
            ->setClubId($club->getId())->setSeasonId($season->getId())
            ->setTeamId($teamId)->setCoachId($coachId)->setRole($role)->setIsRequired(true);
        $this->em->persist($link);
    }

    private function travelTime(Club $club, Season $season, string $venueIdA, string $venueIdB, int $walkingMinutes): void
    {
        [$a, $b] = $venueIdA < $venueIdB ? [$venueIdA, $venueIdB] : [$venueIdB, $venueIdA];
        $row = (new VenueTravelTime)
            ->setClubId($club->getId())->setSeasonId($season->getId())
            ->setVenueAId($a)->setVenueBId($b)->setWalkingMinutes($walkingMinutes);
        $this->em->persist($row);
    }

    /**
     * @return array{0: Club, 1: Season}
     */
    private function seed(string $prefix): array
    {
        $uid = uniqid('', true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $club = new Club;
        $club->setName('Coach Late Tolerance Gate Club');
        $club->setSlug($prefix . '-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode('CLT' . strtoupper(substr(md5($uid), 0, 8)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('coach-late-gate-' . $uid . '@test.com');
        $user->setFirstName('C');
        $user->setLastName('L');
        $user->setPasswordHash($hasher->hashPassword($user, 'pass'));
        $this->em->persist($user);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());

        $cu = new ClubUser;
        $cu->setClubId($club->getId());
        $cu->setUserId($user->getId());
        $cu->setRole('admin');
        $cu->setIsActive(true);
        $this->em->persist($cu);

        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName('2025-2026');
        $season->setStartDate(new DateTimeImmutable('2025-09-01'));
        $season->setEndDate(new DateTimeImmutable('2026-06-30'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($season);
        $this->em->flush();

        return [$club, $season];
    }
}
