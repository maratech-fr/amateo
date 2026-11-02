<?php

declare(strict_types=1);

namespace App\Tests\CrossStack;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Coach;
use App\Entity\CoachWish;
use App\Entity\Reservation;
use App\Entity\Season;
use App\Entity\SharedTrainingBlock;
use App\Entity\SharedTrainingBlockTeam;
use App\Entity\Team;
use App\Entity\TeamCoach;
use App\Entity\User;
use App\Entity\Venue;
use App\Entity\VenueTrainingSlot;
use App\Entity\VenueTravelTime;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\SeasonStatus;
use App\Enum\TeamCoachRole;
use App\Service\CoachWishTransferService;
use App\Service\ScheduleConstraintBuilder;
use App\Tests\ProvisionsPeriodPlanTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * NR BLOQUANT (axe §7.1 « constraint semantics ») : une disponibilité saisie dans une doléance puis
 * TRANSFÉRÉE est honorée par le VRAI moteur. Chaîne complète : doléance → vrai
 * `CoachWishTransferService` → vrai `ScheduleConstraintBuilder::buildForPeriodPlan` → vrai `/generate`.
 *
 * Cinq variantes, sur le plan de période d'une semaine de vacances :
 *  (1) LIBRE — Karim absent jeudi : Emma tient la séance, placée jeudi ; les deux absents : pas de jeudi ;
 *  (2) RÉSERVÉE — réservation jeudi : Emma la tient, aucun écart ; les deux absents : séance PRODUITE,
 *      écart nommé (« la réservation gagne ») ;
 *  (3) BLOC — U15A (Karim) + U15B (Emma) : Karim absent jeudi, le bloc reste possible jeudi ;
 *  (4) BLOC FERMÉ — U15A ferme jeudi, seule case commune : `failed`, le diagnostic nomme le groupe ;
 *      U15B n'a reçu aucune contrainte (aucune propagation) ;
 *  (5) RELAIS — l'absence de l'ADJOINT ferme le relais d'arrivée tardive : la séance que le principal
 *      quittait pour rejoindre en retard n'est plus plaçable ; TÉMOIN sans absence : elle est placée.
 *
 * Doublon assumé (point sensible solveur) : {@see OneCoachHoldsTheSessionGateTest},
 * {@see SharedBlockCoachRelayGateTest} et {@see CoachLateToleranceSemanticsGateTest} prouvent ces règles
 * sur un payload ou une saison ; l'angle propre ici est le CHEMIN doléance → transfert → moteur.
 *
 * Étape NOMMÉE du job `blocking-tests` de `.github/workflows/ci.yml` et ligne de
 * `docs/testing/blocking-tests.md`. Skip propre si le moteur est absent.
 */
#[Group('phase1')]
#[Group('contract')]
final class CoachWishUnavailabilityGateTest extends KernelTestCase
{
    use ProvisionsPeriodPlanTrait;
    use TenantGucTrait;

    private const string ENGINE_URL = 'http://engine:8000/generate';

    private const string SPORT_CATEGORY_ID = '99999999-9999-4999-8999-999999999999';

    private const string WEEK = '2026-02-16';

    private const int THURSDAY = 4;

    private const int TUESDAY = 2;

    private EntityManagerInterface $em;

    private Club $club;

    private Season $season;

    private CalendarEntry $entry;

    private string $planId;

    public function testOneMainCoachAbsentLeavesTheDayOpenAndBothAbsentCloseIt(): void
    {
        // (1) Le gymnase n'ouvre QUE le jeudi : la séance n'a que cette case.
        $venue = $this->venue('Matéo');
        $this->slot($venue, self::THURSDAY, '18:00');
        $team = $this->team('U15');
        $karim = $this->coach('Karim', 'Benali');
        $emma = $this->coach('Emma', 'Morel');
        $this->link($team, $karim, TeamCoachRole::MAIN);
        $this->link($team, $emma, TeamCoachRole::MAIN);

        $wish = $this->wish($team, $karim, staff: [['coachId' => $karim->getId(), 'days' => [self::THURSDAY]]]);
        $this->transfer([$wish]);

        $result = $this->solve($this->build());
        self::assertSame('completed', $result['status']);
        self::assertSame(self::THURSDAY, $this->dayOf($result, $team), 'Karim absent, Emma libre : un coach suffit, la séance reste jeudi');

        // Les deux absents : le jeudi est fermé de fait.
        $this->rewrite($wish, staff: [['coachId' => $karim->getId(), 'days' => [self::THURSDAY]], ['coachId' => $emma->getId(), 'days' => [self::THURSDAY]]]);
        $this->transfer([$wish]);

        self::assertNull($this->dayOf($this->solve($this->build()), $team), 'les deux principaux absents : aucune séance ce jeudi');
    }

    public function testAReservedSessionIsHeldByTheOtherCoachElseProducedWithANamedGap(): void
    {
        // (2) Réservation HARD du plan, jeudi : « la réservation gagne ».
        $venue = $this->venue('Matéo');
        $this->slot($venue, self::THURSDAY, '18:00');
        $team = $this->team('U15');
        $karim = $this->coach('Karim', 'Benali');
        $emma = $this->coach('Emma', 'Morel');
        $this->link($team, $karim, TeamCoachRole::MAIN);
        $this->link($team, $emma, TeamCoachRole::MAIN);
        $this->reservation($team, $venue, self::THURSDAY, '18:00');

        $wish = $this->wish($team, $karim, staff: [['coachId' => $karim->getId(), 'days' => [self::THURSDAY]]]);
        $this->transfer([$wish]);

        $held = $this->solve($this->build());
        self::assertSame('completed', $held['status']);
        self::assertSame(self::THURSDAY, $this->dayOf($held, $team));
        self::assertSame([], $this->sacrificedRulesOf($held, $team), 'Emma tient la séance : aucun écart');

        $this->rewrite($wish, staff: [['coachId' => $karim->getId(), 'days' => [self::THURSDAY]], ['coachId' => $emma->getId(), 'days' => [self::THURSDAY]]]);
        $this->transfer([$wish]);

        $produced = $this->solve($this->build());
        self::assertSame('completed', $produced['status'], 'la réservation gagne : le planning est produit');
        self::assertSame(self::THURSDAY, $this->dayOf($produced, $team));
        self::assertNotSame([], $this->sacrificedRulesOf($produced, $team), 'les deux absents : l\'écart est nommé');
    }

    public function testASharedBlockStaysPossibleWhenOneCoachOfTheUnionIsAbsent(): void
    {
        // (3) Bloc déclaré sur le plan, seule case jeudi : Emma, de l'union, le tient.
        $venue = $this->venue('Matéo');
        $this->slot($venue, self::THURSDAY, '18:00');
        $a = $this->team('U15A');
        $b = $this->team('U15B');
        $karim = $this->coach('Karim', 'Benali');
        $emma = $this->coach('Emma', 'Morel');
        $this->link($a, $karim, TeamCoachRole::MAIN);
        $this->link($b, $emma, TeamCoachRole::MAIN);
        $this->block($a, $b);

        $wishA = $this->wish($a, $karim, staff: [['coachId' => $karim->getId(), 'days' => [self::THURSDAY]]]);
        $wishB = $this->wish($b, $emma);
        $this->transfer([$wishA, $wishB]);

        $result = $this->solve($this->build());
        self::assertSame('completed', $result['status']);
        self::assertSame(self::THURSDAY, $this->dayOf($result, $a));
        self::assertSame(self::THURSDAY, $this->dayOf($result, $b));
    }

    public function testATeamClosingTheOnlyCommonDayFailsAndNamesTheGroupWithoutTouchingThePartner(): void
    {
        // (4) U15A ferme jeudi, U15B ne dit rien, jeudi est la seule case commune.
        $venue = $this->venue('Matéo');
        $this->slot($venue, self::THURSDAY, '18:00');
        $a = $this->team('U15A');
        $b = $this->team('U15B');
        $karim = $this->coach('Karim', 'Benali');
        $emma = $this->coach('Emma', 'Morel');
        $this->link($a, $karim, TeamCoachRole::MAIN);
        $this->link($b, $emma, TeamCoachRole::MAIN);
        $this->block($a, $b);

        $wishA = $this->wish($a, $karim, closedDays: [self::THURSDAY]);
        $wishB = $this->wish($b, $emma);
        $this->transfer([$wishA, $wishB]);

        $payload = $this->build();
        $teamConstraintTargets = array_column(
            array_filter($payload['constraints'], static fn (array $c): bool => ($c['config']['forbiddenDays'] ?? null) === [self::THURSDAY]),
            'scopeTargetId',
        );
        self::assertSame([$a->getId()], $teamConstraintTargets, 'la fermeture vaut pour l\'équipe déclarante seule, jamais propagée au groupe');

        $result = $this->solve($payload);
        self::assertSame('failed', $result['status'], 'la seule case commune est fermée : la génération échoue');
        $messages = implode(' | ', array_map(static fn (array $d): string => (string) ($d['message'] ?? ''), $result['diagnostics'] ?? []));
        // L'ordre des membres dans le message suit le moteur (constaté « U15B + U15A ») : seul le groupe compte.
        self::assertMatchesRegularExpression('/groupe U15[AB] \+ U15[AB]/', $messages, 'l\'échec nomme le groupe');
    }

    public function testAnAssistantsAbsenceClosesTheLateArrivalRelayOnly(): void
    {
        // (5) Décor de CoachLateToleranceSemanticsGateTest, plan de période : A n'a que mardi 18:45 au
        // gymnase 1 ; B est réservée mardi 20:00 au gymnase 2, tolère 30 min de retard ; Sébastien est
        // principal des deux, Benoît adjoint de B. Benoît libre : il ouvre B, Sébastien coache A puis rejoint.
        [$teamA, $teamB, $sebastien, $benoit] = $this->seedRelayScenario();

        $witnessWish = $this->wish($teamB, $sebastien);
        $this->transfer([$witnessWish]);
        $witness = $this->solve($this->build());
        self::assertSame(self::TUESDAY, $this->dayOf($witness, $teamA), 'témoin : sans absence, l\'adjoint ouvre B et la séance de A est placée');
        self::assertSame(self::TUESDAY, $this->dayOf($witness, $teamB));

        // Benoît absent mardi, via la doléance transférée : B reste tenue, le relais est fermé.
        $this->rewrite($witnessWish, staff: [['coachId' => $benoit->getId(), 'days' => [self::TUESDAY]]]);
        $this->transfer([$witnessWish]);
        $closed = $this->solve($this->build());
        self::assertSame(self::TUESDAY, $this->dayOf($closed, $teamB), 'l\'absence d\'un adjoint ne ferme aucune séance');
        self::assertNull($this->dayOf($closed, $teamA), 'mais le relais est fermé : la séance de A n\'est plus plaçable');
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $uid = uniqid('', true);
        $hasher = self::getContainer()->get('security.user_password_hasher');
        $this->club = (new Club)->setName('Doléances Gate Club')->setSlug('dol-gate-' . $uid)->setTimezone('Europe/Paris')->setLocale('fr')->setOnboardingCompleted(true);
        $this->em->persist($this->club);
        $user = (new User)->setEmail('dol-gate-' . $uid . '@test.com')->setFirstName('D')->setLastName('G');
        $user->setPasswordHash($hasher->hashPassword($user, 'pass'));
        $this->em->persist($user);
        $this->em->flush();

        $this->scopeGucToClub($this->club->getId());
        $this->em->persist((new ClubUser)->setClubId($this->club->getId())->setUserId($user->getId())->setRole('admin')->setIsActive(true));
        $this->season = (new Season)->setClubId($this->club->getId())->setName('2025-2026')
            ->setStartDate(new DateTimeImmutable('2025-09-01'))->setEndDate(new DateTimeImmutable('2026-06-30'))->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($this->season);
        $this->entry = (new CalendarEntry)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setKind(CalendarEntryKind::PERIOD)->setPeriodType(CalendarEntryPeriodType::HOLIDAY)->setTitle('Vacances')
            ->setStartDate(new DateTimeImmutable(self::WEEK))->setEndDate(new DateTimeImmutable('2026-02-22'));
        $this->em->persist($this->entry);
        $this->em->flush();
        $this->planId = $this->planIdOf($this->entry);
    }

    /** @return array{0: Team, 1: Team, 2: Coach, 3: Coach} */
    private function seedRelayScenario(): array
    {
        $gym1 = $this->venue('Mont Blanc');
        $gym2 = $this->venue('Louison Bobet');
        $this->slot($gym1, self::TUESDAY, '18:45');

        $teamA = $this->team('A');
        $teamB = $this->team('B');
        $sebastien = (new Coach)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setFirstName('Sébastien')->setLastName('Test')->setIsActive(true)->setIsVehicled(false);
        $benoit = (new Coach)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setFirstName('Benoît')->setLastName('Roux')->setIsActive(true)->setIsVehicled(false);
        $this->em->persist($sebastien);
        $this->em->persist($benoit);
        $this->em->flush();
        $this->link($teamA, $sebastien, TeamCoachRole::MAIN);
        $this->link($teamB, $sebastien, TeamCoachRole::MAIN);
        $this->link($teamB, $benoit, TeamCoachRole::ASSISTANT);

        [$low, $high] = $gym1->getId() < $gym2->getId() ? [$gym1->getId(), $gym2->getId()] : [$gym2->getId(), $gym1->getId()];
        $this->em->persist((new VenueTravelTime)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setVenueAId($low)->setVenueBId($high)->setWalkingMinutes(15));

        $this->reservation($teamB, $gym2, self::TUESDAY, '20:00', tolerance: 30);

        // A n'a pas de doléance propre : on l'active sur le plan par sa propre doléance neutre.
        $this->transfer([$this->wish($teamA, $sebastien)]);

        return [$teamA, $teamB, $sebastien, $benoit];
    }

    private function venue(string $name): Venue
    {
        $venue = (new Venue)->setClubId($this->club->getId())->setSeasonId($this->season->getId())->setName($name)->setSource('manual');
        $this->em->persist($venue);
        $this->em->flush();

        return $venue;
    }

    /** Un créneau de la grille POSSÉDÉE par le plan de période (ADR-0002). */
    private function slot(Venue $venue, int $day, string $start): void
    {
        $this->em->persist((new VenueTrainingSlot)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setSchedulePlanId($this->planId)->setVenueId($venue->getId())
            ->setDayOfWeek($day)->setStartTime(new DateTimeImmutable($start))->setDurationMinutes(90)->setCapacity(1));
        $this->em->flush();
    }

    private function team(string $name): Team
    {
        $team = (new Team)->setClubId($this->club->getId())->setSeasonId($this->season->getId())->setName($name)
            ->setSportCategoryId(self::SPORT_CATEGORY_ID)->setPriorityTierId(1)->setSessionsPerWeek(1);
        $this->em->persist($team);
        $this->em->flush();

        return $team;
    }

    private function coach(string $firstName, string $lastName): Coach
    {
        $coach = (new Coach)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setFirstName($firstName)->setLastName($lastName)->setIsActive(true);
        $this->em->persist($coach);
        $this->em->flush();

        return $coach;
    }

    private function link(Team $team, Coach $coach, TeamCoachRole $role): void
    {
        $this->em->persist((new TeamCoach)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setTeamId($team->getId())->setCoachId($coach->getId())->setRole($role));
        $this->em->flush();
    }

    private function reservation(Team $team, Venue $venue, int $day, string $start, int $tolerance = 0): void
    {
        $this->em->persist((new Reservation)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setSchedulePlanId($this->planId)->setTeamId($team->getId())->setVenueId($venue->getId())
            ->setDayOfWeek($day)->setStartTime(new DateTimeImmutable($start))->setDurationMinutes(90)
            ->setCoachLateToleranceMinutes($tolerance));
        $this->em->flush();
    }

    private function block(Team $a, Team $b): void
    {
        $block = (new SharedTrainingBlock)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setSchedulePlanId($this->planId)->setCommonSessions(1);
        $this->em->persist($block);
        $this->em->flush();
        foreach ([$a, $b] as $team) {
            $this->em->persist((new SharedTrainingBlockTeam)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
                ->setBlockId($block->getId())->setTeamId($team->getId()));
        }
        $this->em->flush();
    }

    /**
     * @param list<array{coachId: string, days: list<int>}> $staff
     * @param list<int>                                     $closedDays
     */
    private function wish(Team $team, Coach $coach, array $staff = [], array $closedDays = []): CoachWish
    {
        $wish = (new CoachWish)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setCalendarEntryId($this->entry->getId())->setWeekStart(new DateTimeImmutable(self::WEEK))
            ->setTeamId($team->getId())->setCoachId($coach->getId())->setSlotsWanted(1)
            ->setUnavailableDays($closedDays)->setStaffUnavailability($staff);
        $this->em->persist($wish);
        $this->em->flush();

        return $wish;
    }

    /** @param list<array{coachId: string, days: list<int>}> $staff */
    private function rewrite(CoachWish $wish, array $staff): void
    {
        $fresh = $this->em->getRepository(CoachWish::class)->find($wish->getId());
        self::assertInstanceOf(CoachWish::class, $fresh);
        $fresh->setStaffUnavailability($staff);
        $this->em->flush();
    }

    /** @param list<CoachWish> $wishes */
    private function transfer(array $wishes): void
    {
        $recap = self::getContainer()->get(CoachWishTransferService::class)->transfer(
            $this->club->getId(),
            $this->season->getId(),
            array_map(static fn (CoachWish $w): string => $w->getId(), $wishes),
        );
        self::assertSame(\count($wishes), $recap['converted'], 'toutes les doléances du décor doivent être transférées');
        $this->em->flush();
    }

    /** @return array<string, mixed> */
    private function build(): array
    {
        return self::getContainer()->get(ScheduleConstraintBuilder::class)
            ->buildForPeriodPlan($this->club->getId(), $this->season->getId(), $this->planId, $this->entry);
    }

    /**
     * @param array<string, mixed> $result
     */
    private function dayOf(array $result, Team $team): ?int
    {
        foreach ($result['slots'] ?? [] as $slot) {
            if (($slot['teamId'] ?? null) === $team->getId()) {
                return (int) $slot['dayOfWeek'];
            }
        }

        return null;
    }

    /**
     * Les écarts nommés sur cette équipe (`rule_sacrificed` : séance fixée maintenue malgré une indisponibilité).
     *
     * @param array<string, mixed> $result
     *
     * @return list<array<string, mixed>>
     */
    private function sacrificedRulesOf(array $result, Team $team): array
    {
        return array_values(array_filter(
            $result['diagnostics'] ?? [],
            static fn (array $d): bool => 'rule_sacrificed' === ($d['type'] ?? null) && ($d['teamId'] ?? null) === $team->getId(),
        ));
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function solve(array $payload): array
    {
        $client = HttpClient::create(['timeout' => 60]);

        try {
            $response = $client->request('POST', self::ENGINE_URL, ['json' => $payload]);
            self::assertSame(200, $response->getStatusCode(), (string) $response->getContent(false));

            return $response->toArray(false);
        } catch (TransportExceptionInterface $exception) {
            self::markTestSkipped('Engine not available: ' . $exception->getMessage());
        }
    }
}
