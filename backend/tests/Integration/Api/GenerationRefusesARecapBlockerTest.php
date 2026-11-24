<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Coach;
use App\Entity\Constraint;
use App\Entity\Reservation;
use App\Entity\Schedule;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\Season;
use App\Entity\SharedTrainingBlock;
use App\Entity\SharedTrainingBlockTeam;
use App\Entity\Team;
use App\Entity\TeamCoach;
use App\Entity\User;
use App\Entity\VenueTrainingSlot;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\ConstraintFamily;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use App\Enum\LockLevel;
use App\Enum\LockOrigin;
use App\Enum\ScheduleStatus;
use App\Enum\SeasonStatus;
use App\Enum\TeamCoachRole;
use App\Plans\PlanComposition;
use App\Plans\SoclePlan;
use App\Service\GenerationBlockersGuard;
use App\Service\ScheduleConstraintBuilder;
use App\Service\SchedulePlanProvisioner;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\CreatesPeopleAndPlacesTrait;
use App\Tests\CrossStack\SharedBlockPreSolveMirrorParityTest;
use App\Tests\SeasonPlanOf;
use App\Tests\Security\RecapReservedGroupIsNotAFailureTest;
use App\Tests\TenantGucTrait;
use Closure;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use ReflectionClass;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * NR (axes generation pipeline et constraint semantics) : ce que le Récap dit bloquant, les guichets `/generate`,
 * `/regenerate` et `/fill` le refusent aussi, sans passer par l'écran : 422 `{error, blocker: true}`, le motif du Récap tel
 * quel, rien en file. Bloc inservable pris en exemple parce que sa parité avec le moteur est prouvée ailleurs. Une séance
 * verrouillée de la version chargée compte comme une réservation, sauf au comblement, qui épingle déjà tout le placé de sa source.
 *
 * Jumeaux VOULUS (point sensible génération) : {@see SharedBlockPreSolveMirrorParityTest} (ce qui bloque, le moteur l'aurait
 * refusé) ; {@see GenerationRefusesACoachInTwoPlacesTest} (l'autre famille de blocage, la garde voisine) ;
 * {@see RecapReservedGroupIsNotAFailureTest} (ce qui ne bloque pas).
 */
#[Group('phase1')]
#[Group('integration')]
final class GenerationRefusesARecapBlockerTest extends WebTestCase
{
    use ChoosesPlanVersionTrait;
    use CreatesPeopleAndPlacesTrait;
    use TenantGucTrait;

    private const string UNSERVABLE = 'aucun créneau où au moins un coach du groupe n\'est disponible pour une séance commune — la génération échouera.';

    private EntityManagerInterface $em;

    private KernelBrowser $client;

    /** @return iterable<string, array{string, ScheduleStatus}> */
    public static function seasonCounters(): iterable
    {
        yield '/generate' => ['generate', ScheduleStatus::DRAFT];
        yield '/regenerate' => ['regenerate', ScheduleStatus::COMPLETED];
    }

    /** @return iterable<string, array{LockOrigin, int}> */
    public static function lockOnItsOwnReservation(): iterable
    {
        // Dernière colonne : les épingles de l'équipe qui partent au solveur (le payload de saison saute le verrou d'origine réservation).
        yield 'verrou posé à la main sur la case de la réservation' => [LockOrigin::MANUAL, 2];
        yield 'verrou d\'origine réservation sur sa case' => [LockOrigin::RESERVATION, 1];
    }

    /** Groupe sans aucun coach disponible : refusé avant le moteur, le motif du Récap tel quel, le statut n'a pas bougé. */
    #[DataProvider('seasonCounters')]
    public function testASeasonCounterRefusesAndNothingIsQueued(string $counter, ScheduleStatus $status): void
    {
        $ctx = $this->seedGroup(coachesAvailable: false);
        $schedule = $this->version($ctx, $status);
        $before = $this->em->getRepository(Schedule::class)->count(['seasonId' => $ctx['seasonId']]);

        $this->post($ctx, $schedule->getId(), $counter);

        $this->assertRefusedNamingTheGroup();
        $this->em->clear();
        self::assertSame($status, $this->em->getRepository(Schedule::class)->find($schedule->getId())?->getStatus(), 'rien n\'est mis en file');
        self::assertSame($before, $this->em->getRepository(Schedule::class)->count(['seasonId' => $ctx['seasonId']]), 'aucune version créée');
    }

    /** TÉMOIN : même décor, les coachs présents, les guichets partent. C'est donc bien le blocage qui refusait. */
    #[DataProvider('seasonCounters')]
    public function testTheSameDecorWithCoachesAvailableStarts(string $counter, ScheduleStatus $status): void
    {
        $ctx = $this->seedGroup(coachesAvailable: true);

        $this->post($ctx, $this->version($ctx, $status)->getId(), $counter);

        self::assertResponseStatusCodeSame(202);
    }

    /** Le guichet dit mot pour mot ce que dit le Récap : une seule vérité, pas de seconde phrase. */
    public function testTheRefusalIsTheRecapSentence(): void
    {
        $ctx = $this->seedGroup(coachesAvailable: false);
        $scheduleId = $this->version($ctx, ScheduleStatus::DRAFT)->getId();
        $bearer = ['HTTP_AUTHORIZATION' => 'Bearer ' . self::getContainer()->get(JWTTokenManagerInterface::class)->create($ctx['user'])];

        $this->client->request('POST', '/api/constraints/validate', [], [], $bearer);
        $recap = json_decode((string) $this->client->getResponse()->getContent(), true);
        $this->client->request('POST', "/api/schedules/{$scheduleId}/generate", [], [], $bearer);

        self::assertResponseStatusCodeSame(422);
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame($recap['blockers'][0] ?? '(le Récap ne bloque pas)', $body['error'] ?? null);
        self::assertStringNotContainsString('cette période', (string) $body['error'], 'Récap de saison : aucune mention de période');
    }

    /**
     * Une équipe à 2 séances par jour réservée deux fois le mardi sur des cases qui se chevauchent : les guichets de saison refusent
     * avec le motif du Récap (T2d), mot pour mot, et rien ne part en file. Le comblement n'est pas concerné : ce blocage est de saison
     * seulement ({@see RecapRefusesASecondSessionSameDayTest::testFillingAPeriodDoesNotBlock}).
     */
    #[DataProvider('seasonCounters')]
    public function testASeasonCounterRefusesTwoOverlappingReservationsUnderACapOfTwo(string $counter, ScheduleStatus $status): void
    {
        $ctx = $this->seedGroup(coachesAvailable: true);
        $other = $this->newVenue($ctx['clubId'], $ctx['seasonId'], 'Gymnase Deux');
        $this->em->persist($other);
        $this->em->flush();
        $this->em->persist((new VenueTrainingSlot)->setClubId($ctx['clubId'])->setSeasonId($ctx['seasonId'])->setSchedulePlanId(SeasonPlanOf::id(self::getContainer(), $ctx['seasonId']))->setVenueId($other->getId())
            ->setDayOfWeek(2)->setStartTime(new DateTimeImmutable('18:30'))->setDurationMinutes(90)->setCapacity(2));
        $team = (new Team)->setClubId($ctx['clubId'])->setSeasonId($ctx['seasonId'])->setName('U13')
            ->setSportCategoryId('cccccccc-cccc-4ccc-8ccc-cccccccccccc')->setPriorityTierId(1);
        $this->em->persist($team);
        $this->em->flush();
        self::getContainer()->get(PlanComposition::class)->ensureLines($team->getSeasonId(), [$team->getId() => ['sessionsPerWeek' => 2, 'maxSessionsPerDay' => 2]]);
        $this->em->flush();
        foreach ([[$ctx['venueId'], '18:00'], [$other->getId(), '18:30']] as [$venueId, $start]) {
            $this->em->persist((new Reservation)->setClubId($ctx['clubId'])->setSeasonId($ctx['seasonId'])->setSchedulePlanId(SeasonPlanOf::id(self::getContainer(), $ctx['seasonId']))->setTeamId($team->getId())
                ->setVenueId($venueId)->setDayOfWeek(2)->setStartTime(new DateTimeImmutable($start))->setDurationMinutes(90));
        }
        $this->em->flush();
        $schedule = $this->version($ctx, $status);

        $this->post($ctx, $schedule->getId(), $counter);

        self::assertResponseStatusCodeSame(422);
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertTrue($body['blocker'] ?? false);
        self::assertSame(
            'U13 est réservée deux fois le mardi sur des créneaux qui se chevauchent : 18:00-19:30 à Gymnase Un et 18:30-20:00 à Gymnase Deux. Une équipe ne peut pas être à deux séances en même temps : retirez l\'une des deux au récapitulatif.',
            $body['error'] ?? null,
        );
        $this->em->clear();
        self::assertSame($status, $this->em->getRepository(Schedule::class)->find($schedule->getId())?->getStatus(), 'rien n\'est mis en file');
    }

    /** Comblement d'une période : même refus, aucune version V+1. */
    public function testFillingAPeriodRefusesAndCreatesNoVersion(): void
    {
        $ctx = $this->seedPeriod($this->seedGroup(coachesAvailable: false), planGroup: true);

        $this->post($ctx, $ctx['scheduleId'], 'fill');

        $this->assertRefusedNamingTheGroup();
        $this->em->clear();
        self::assertCount(1, $this->em->getRepository(Schedule::class)->findBy(['schedulePlanId' => $ctx['planId']]), 'aucune version créée');
    }

    /** Une réservation sans créneau bloque aussi sur la régénération : la garde des épingles ne regarde que les périodes. */
    public function testAnOrphanReservationRefusesRegeneration(): void
    {
        $ctx = $this->seedGroup(coachesAvailable: true);
        $this->em->persist((new Reservation)->setClubId($ctx['clubId'])->setSeasonId($ctx['seasonId'])->setSchedulePlanId(SeasonPlanOf::id(self::getContainer(), $ctx['seasonId']))->setTeamId($ctx['teamA'])
            ->setVenueId($ctx['venueId'])->setDayOfWeek(2)->setStartTime(new DateTimeImmutable('21:00'))->setDurationMinutes(90));
        $this->em->flush();

        $this->post($ctx, $this->version($ctx, ScheduleStatus::COMPLETED)->getId(), 'regenerate');

        self::assertResponseStatusCodeSame(422);
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertTrue($body['blocker'] ?? false);
        self::assertStringContainsString('ne correspond à aucun créneau', (string) ($body['error'] ?? ''));
    }

    /**
     * Fermetures de gymnase (correction SMITH passe 3, C) — une réservation sur un gymnase fermé toute la période par une
     * fermeture DÉCLARÉE : le motif NOMME la fermeture (son titre), au lieu de « ce créneau a été déplacé ou supprimé »
     * qui enverrait le gestionnaire chercher un créneau qui n'a jamais bougé. TÉMOIN : sans la fermeture, la même
     * réservation retombe sur la grille et ne bloque rien. Le motif grille, lui, reste celui de
     * {@see testAnOrphanReservationRefusesRegeneration}.
     */
    public function testAReservationOnAVenueClosedAllThePeriodNamesTheClosure(): void
    {
        $ctx = $this->seedPeriod($this->seedGroup(coachesAvailable: true), planGroup: false);
        $this->em->persist((new Reservation)->setClubId($ctx['clubId'])->setSeasonId($ctx['seasonId'])->setSchedulePlanId($ctx['planId'] ?? SeasonPlanOf::id(self::getContainer(), $ctx['seasonId']))
            ->setTeamId($ctx['teamA'])->setVenueId($ctx['venueId'])->setDayOfWeek(2)->setStartTime(new DateTimeImmutable('18:00'))->setDurationMinutes(90));
        $this->em->flush();
        $schedule = $this->em->getRepository(Schedule::class)->find($ctx['scheduleId']);
        self::assertInstanceOf(Schedule::class, $schedule);
        $guard = self::getContainer()->get(GenerationBlockersGuard::class);

        self::assertNull($guard->firstBlockerMessage($schedule), 'témoin : sans fermeture, la réservation retombe sur la grille');

        $closure = new Constraint;
        $closure->setClubId($ctx['clubId'])->setSeasonId($ctx['seasonId']);
        $closure->setName('Gymnase Un en travaux')->setScope(ConstraintScope::FACILITY)->setScopeTargetId($ctx['venueId']);
        $closure->setFamily(ConstraintFamily::FACILITY)->setRuleType(ConstraintRuleType::HARD);
        $closure->setCalendarEntryId((string) $this->em->getConnection()->fetchOne('SELECT calendar_entry_id FROM schedule_plan WHERE id = :pid', ['pid' => $ctx['planId']]));
        $closure->setConfig(['type' => 'venue_closed', 'startDate' => '2026-10-19', 'endDate' => '2026-11-01']);
        $this->em->persist($closure);
        $this->em->flush();

        $message = (string) $guard->firstBlockerMessage($schedule);
        self::assertStringContainsString('Gymnase Un en travaux', $message, 'le motif nomme la fermeture');
        self::assertStringNotContainsString('a été déplacé ou supprimé', $message, 'jamais le motif grille pour une fermeture');
    }

    /** Un créneau libre sur la dernière place d'une case : la case reste occupée, ni le Récap ni le guichet ne s'en plaignent. */
    public function testAFreeSlotOnTheLastPlaceOfACaseIsNotAnOrphan(): void
    {
        $ctx = $this->seedGroup(coachesAvailable: true);
        $this->em->persist((new VenueTrainingSlot)->setClubId($ctx['clubId'])->setSeasonId($ctx['seasonId'])->setSchedulePlanId(SeasonPlanOf::id(self::getContainer(), $ctx['seasonId']))->setVenueId($ctx['venueId'])
            ->setDayOfWeek(4)->setStartTime(new DateTimeImmutable('18:00'))->setDurationMinutes(90)->setCapacity(1));
        $this->em->persist((new Reservation)->setClubId($ctx['clubId'])->setSeasonId($ctx['seasonId'])->setSchedulePlanId(SeasonPlanOf::id(self::getContainer(), $ctx['seasonId']))->setLabel('Loto du club')
            ->setVenueId($ctx['venueId'])->setDayOfWeek(4)->setStartTime(new DateTimeImmutable('18:00'))->setDurationMinutes(90));
        $this->em->flush();
        $version = $this->version($ctx, ScheduleStatus::COMPLETED)->getId();

        $this->post($ctx, $version, 'regenerate');
        self::assertResponseStatusCodeSame(202);

        $payload = self::getContainer()->get(ScheduleConstraintBuilder::class)->buildForPlan(self::getContainer()->get(SoclePlan::class)->idOf($ctx['seasonId']));
        $days = array_column($payload['venues'][0]['trainingSlots'], 'dayOfWeek');
        self::assertNotContains(4, $days, 'la case reste occupée : le solveur ne peut rien y poser');
    }

    /**
     * La sur-réservation se lit sur le volume qui part au solveur : la période réduit l'équipe à 1 séance, 2 réservations
     * bloquent. TÉMOIN : au volume de saison (2), les mêmes réservations passent, côté saison.
     */
    public function testOverbookingIsReadOnThePeriodVolume(): void
    {
        $ctx = $this->seedGroup(coachesAvailable: true);
        self::getContainer()->get(PlanComposition::class)->socleLinesOf([$ctx['teamA']])[$ctx['teamA']]->setSessionsPerWeek(2);
        $this->em->flush();
        $ctx = $this->seedPeriod($ctx, planGroup: false);

        $override = self::getContainer()->get(PlanComposition::class)->lineOf($ctx['planId'], $ctx['teamA']);
        $override->setParticipating(true);
        $override->setSessionsPerWeek(1);
        foreach ([2, 3] as $day) {
            $this->em->persist((new Reservation)->setClubId($ctx['clubId'])->setSeasonId($ctx['seasonId'])->setSchedulePlanId($ctx['planId'] ?? SeasonPlanOf::id(self::getContainer(), $ctx['seasonId']))->setTeamId($ctx['teamA'])
                ->setVenueId($ctx['venueId'])->setDayOfWeek($day)->setStartTime(new DateTimeImmutable('18:00'))->setDurationMinutes(90));
        }
        $this->em->flush();

        $this->post($ctx, $ctx['scheduleId'], 'fill');

        self::assertResponseStatusCodeSame(422);
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertTrue($body['blocker'] ?? false);
        self::assertStringContainsString('U11 A a 2 réservations pour 1 séance par semaine', (string) ($body['error'] ?? ''));
    }

    /**
     * Récap vert, mais les placements de la version source que le comblement épingle se contredisent : groupe sur 2 cases
     * pour 1 séance commune. Le comblement est refusé, c'est le seul guichet qui voit ces épingles.
     */
    public function testFillingWithContradictoryPinsIsRefused(): void
    {
        $ctx = $this->seedPeriod($this->seedGroup(coachesAvailable: true), planGroup: true);
        $this->placeTogether($ctx, [2, 3]);

        $this->post($ctx, $ctx['scheduleId'], 'fill');

        self::assertResponseStatusCodeSame(422);
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertTrue($body['blocker'] ?? false);
        self::assertStringContainsString('placé sur 2 cases pour 1 séance commune', (string) ($body['error'] ?? ''));
        self::assertStringNotContainsString('Retirez une réservation', (string) $body['error'], 'aucune réservation ici : le geste nommé est celui du comblement');
    }

    /** TÉMOIN des épingles : le groupe réuni sur une seule case, le comblement part. */
    public function testFillingWithConsistentPinsStarts(): void
    {
        $ctx = $this->seedPeriod($this->seedGroup(coachesAvailable: true), planGroup: true);
        $this->placeTogether($ctx, [2]);

        $this->post($ctx, $ctx['scheduleId'], 'fill');

        self::assertResponseStatusCodeSame(202);
    }

    /** Un build qui lève ne ferme pas les guichets : le moteur tranche, comme le Récap reste ouvert quand son volet capacité tombe. */
    public function testABuildFailureLetsTheEngineDecide(): void
    {
        $ctx = $this->seedGroup(coachesAvailable: false);
        $schedule = $this->version($ctx, ScheduleStatus::DRAFT);

        $working = self::getContainer()->get(GenerationBlockersGuard::class);
        $broken = new ReflectionClass(ScheduleConstraintBuilder::class)->newInstanceWithoutConstructor();
        $guard = new ReflectionClass(GenerationBlockersGuard::class)->newInstanceWithoutConstructor();
        Closure::bind(function () use ($working, $broken): void {
            foreach (get_object_vars($working) as $name => $value) {
                $this->{$name} = $value instanceof ScheduleConstraintBuilder ? $broken : $value;
            }
        }, $guard, GenerationBlockersGuard::class)();

        self::assertNull($guard->firstBlockerMessage($schedule));
    }

    /** Sans payload, le Récap garde la sur-réservation : elle se lit alors sur le volume de l'équipe. */
    public function testOverbookingStaysVisibleOnEntitiesWithoutAPayload(): void
    {
        $ctx = $this->seedGroup(coachesAvailable: true);
        $reservations = array_map(fn (int $day): Reservation => (new Reservation)->setClubId($ctx['clubId'])->setSeasonId($ctx['seasonId'])->setSchedulePlanId(SeasonPlanOf::id(self::getContainer(), $ctx['seasonId']))->setTeamId($ctx['teamA'])
            ->setVenueId($ctx['venueId'])->setDayOfWeek($day)->setStartTime(new DateTimeImmutable('18:00'))->setDurationMinutes(90), [2, 3]);

        $found = self::getContainer()->get(GenerationBlockersGuard::class)->overBookedOnEntities($reservations, $ctx['clubId'], $ctx['seasonId']);

        self::assertCount(1, $found);
        self::assertStringContainsString('U11 A a 2 réservations pour 1 séance par semaine', $found[0]);
    }

    /**
     * Un verrou compte comme une séance : U11 A, 1 séance par semaine, réservée le mardi et verrouillée le mercredi dans
     * la version chargée. Les guichets de saison refusent avec le motif du Récap tel quel, qui nomme la séance verrouillée du
     * mercredi (jour, heures, gymnase) et pas la réservation ; rien ne part en file, aucune version n'est créée.
     */
    #[DataProvider('seasonCounters')]
    public function testALockedSessionBeyondTheWeeklyCountRefusesWithTheRecapReason(string $counter, ScheduleStatus $status): void
    {
        $ctx = $this->seedSolo(sessionsPerWeek: 1);
        $loaded = $this->version($ctx, ScheduleStatus::COMPLETED);
        $this->reserve($ctx, $ctx['teamA'], 2);
        $this->pin($ctx, $loaded->getId(), $ctx['teamA'], 3, LockLevel::HARD, LockOrigin::MANUAL);
        $schedule = 'regenerate' === $counter ? $loaded : $this->version($ctx, ScheduleStatus::DRAFT);
        $before = $this->em->getRepository(Schedule::class)->count(['seasonId' => $ctx['seasonId']]);
        $this->assertPinnedForTheSolver($ctx, $ctx['teamA'], 2);

        $recap = $this->recapBlockers($ctx);
        $this->post($ctx, $schedule->getId(), $counter);

        self::assertResponseStatusCodeSame(422, 'une réservation et un verrou pour 1 séance par semaine : le guichet doit refuser');
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertTrue($body['blocker'] ?? false);
        self::assertSame($recap[0] ?? '(le Récap ne bloque pas)', $body['error'] ?? null, 'le guichet dit mot pour mot le motif du Récap');
        $this->assertNamesTheSessions((string) $body['error'], 'U11 A', ['mercredi'], ['mardi']);
        $this->em->clear();
        self::assertSame($status, $this->em->getRepository(Schedule::class)->find($schedule->getId())?->getStatus(), 'rien n\'est mis en file');
        self::assertSame($before, $this->em->getRepository(Schedule::class)->count(['seasonId' => $ctx['seasonId']]), 'aucune version créée');
    }

    /** TÉMOIN : même décor, la séance du mercredi déverrouillée, les guichets partent. C'est donc le verrou qui refusait. */
    #[DataProvider('seasonCounters')]
    public function testTheSameDecorWithTheSessionUnlockedStarts(string $counter, ScheduleStatus $status): void
    {
        $ctx = $this->seedSolo(sessionsPerWeek: 1);
        $loaded = $this->version($ctx, ScheduleStatus::COMPLETED);
        $this->reserve($ctx, $ctx['teamA'], 2);
        $this->pin($ctx, $loaded->getId(), $ctx['teamA'], 3, LockLevel::NONE, null);
        $schedule = 'regenerate' === $counter ? $loaded : $this->version($ctx, $status);

        $this->post($ctx, $schedule->getId(), $counter);

        self::assertResponseStatusCodeSame(202);
    }

    /**
     * Un verrou posé sur la case d'une réservation de la même équipe compte une fois, pas deux : U11 A, 1 séance par semaine,
     * réservée et verrouillée le mardi 18:00, n'est pas bloquée.
     */
    #[DataProvider('lockOnItsOwnReservation')]
    public function testALockOnItsOwnReservationCountsOnce(LockOrigin $origin, int $pinsSent): void
    {
        $ctx = $this->seedSolo(sessionsPerWeek: 1);
        $loaded = $this->version($ctx, ScheduleStatus::COMPLETED);
        $this->reserve($ctx, $ctx['teamA'], 2);
        $this->pin($ctx, $loaded->getId(), $ctx['teamA'], 2, LockLevel::HARD, $origin);
        $this->assertPinnedForTheSolver($ctx, $ctx['teamA'], $pinsSent);

        self::assertNull($this->guard()->firstBlockerMessage($this->version($ctx, ScheduleStatus::DRAFT)));
    }

    /**
     * Verrous seuls, sans aucune réservation : U11 A, 1 séance par semaine, verrouillée le mardi et le mercredi
     * dans la version chargée, est bloquée et ses deux séances sont nommées. Jumeau : {@see testATeamOfAGroupKeepsTodaysCount}.
     */
    public function testTwoLockedSessionsForOneWeeklySessionBlock(): void
    {
        $ctx = $this->seedSolo(sessionsPerWeek: 1);
        $loaded = $this->version($ctx, ScheduleStatus::COMPLETED);
        foreach ([2, 3] as $day) {
            $this->pin($ctx, $loaded->getId(), $ctx['teamA'], $day, LockLevel::HARD, LockOrigin::MANUAL);
        }
        $this->assertPinnedForTheSolver($ctx, $ctx['teamA'], 2);

        $message = $this->guard()->firstBlockerMessage($this->version($ctx, ScheduleStatus::DRAFT));

        self::assertNotNull($message, 'deux séances verrouillées pour 1 séance par semaine : le Récap doit bloquer');
        $this->assertNamesTheSessions($message, 'U11 A', ['mardi', 'mercredi'], []);
    }

    /**
     * Une équipe d'un groupe du socle garde le comptage d'aujourd'hui : même décor que
     * {@see testTwoLockedSessionsForOneWeeklySessionBlock}, U11 A en groupe avec U11 B, aucun blocage.
     */
    public function testATeamOfAGroupKeepsTodaysCount(): void
    {
        $ctx = $this->seedGroup(coachesAvailable: true);
        $loaded = $this->version($ctx, ScheduleStatus::COMPLETED);
        foreach ([2, 3] as $day) {
            $this->pin($ctx, $loaded->getId(), $ctx['teamA'], $day, LockLevel::HARD, LockOrigin::MANUAL);
        }
        $this->assertPinnedForTheSolver($ctx, $ctx['teamA'], 2);

        self::assertNull($this->guard()->firstBlockerMessage($this->version($ctx, ScheduleStatus::DRAFT)));
    }

    /**
     * Période, groupe du plan dont le partenaire est en pause : U11 A reste membre du groupe de la portée, son comptage est
     * celui d'aujourd'hui, ses deux verrous pour 1 séance ne bloquent pas. Jumeau qui bloque sans groupe :
     * {@see testThePeriodCountIsReadOnThePeriodSessions}.
     */
    public function testAPeriodGroupWithAPausedPartnerKeepsTodaysCount(): void
    {
        $ctx = $this->seedPeriod($this->seedSolo(sessionsPerWeek: 1), planGroup: false);
        $this->declareGroup($ctx['clubId'], $ctx['seasonId'], $ctx['planId'], [$ctx['teamA'], $ctx['teamB']]);
        $paused = self::getContainer()->get(PlanComposition::class)->lineOf($ctx['planId'], $ctx['teamB']);
        $paused->setParticipating(false);
        foreach ([2, 3] as $day) {
            $this->pin($ctx, $ctx['scheduleId'], $ctx['teamA'], $day, LockLevel::HARD, LockOrigin::MANUAL);
        }
        $this->assertPinnedForTheSolver($ctx, $ctx['teamA'], 2);

        self::assertNull($this->guard()->firstBlockerMessage($this->periodSchedule($ctx)));
    }

    /**
     * Un groupe ne vaut que dans sa portée : U11 A, en groupe avec U11 B pour la seule période, verrouillée le mardi et le
     * mercredi dans la version chargée de la saison pour 1 séance. Le Récap de saison bloque et nomme les deux séances.
     * Jumeau dans la période : {@see testAPeriodGroupWithAPausedPartnerKeepsTodaysCount}.
     */
    public function testAGroupOfAPeriodDoesNotSpareTheSeasonCount(): void
    {
        $ctx = $this->seedPeriod($this->seedSolo(sessionsPerWeek: 1), planGroup: false);
        $this->declareGroup($ctx['clubId'], $ctx['seasonId'], $ctx['planId'], [$ctx['teamA'], $ctx['teamB']]);
        $seasonLoaded = (string) self::getContainer()->get(SchedulePlanProvisioner::class)->loadedVersionIdOfSeason($ctx['seasonId']);
        foreach ([2, 3] as $day) {
            $this->pin($ctx, $seasonLoaded, $ctx['teamA'], $day, LockLevel::HARD, LockOrigin::MANUAL);
        }
        $season = $this->em->getRepository(Schedule::class)->find($seasonLoaded);
        self::assertInstanceOf(Schedule::class, $season);
        $this->assertPinnedForTheSolver(['clubId' => $ctx['clubId'], 'seasonId' => $ctx['seasonId']], $ctx['teamA'], 2);

        $message = $this->guard()->firstBlockerMessage($season);

        self::assertNotNull($message, 'le groupe de la période ne vaut pas pour la saison : le Récap de saison doit bloquer');
        $this->assertNamesTheSessions($message, 'U11 A', ['mardi', 'mercredi'], []);
    }

    /**
     * Le comblement ne compte que les réservations (exception écrite à l'invariant des trois guichets) : U11 A, 2 séances,
     * sa version source porte 3 séances verrouillées. `/generate` d'une version de la période refuse en nommant les trois,
     * `/fill` part (il épingle déjà tout le placé de sa source).
     */
    public function testFillingCountsReservationsOnlyWhileGeneratingCountsLocks(): void
    {
        $ctx = $this->seedPeriod($this->seedSolo(sessionsPerWeek: 2), planGroup: false);
        foreach ([1, 2, 3] as $day) {
            $this->pin($ctx, $ctx['scheduleId'], $ctx['teamA'], $day, LockLevel::HARD, LockOrigin::MANUAL);
        }
        $draft = (new Schedule)->setClubId($ctx['clubId'])->setSeasonId($ctx['seasonId'])->setName('Brouillon de période')->setStatus(ScheduleStatus::DRAFT);
        $this->linkSeededSchedule($draft, $this->entryIdOf($ctx['planId']));
        $this->em->flush();
        $this->assertPinnedForTheSolver($ctx, $ctx['teamA'], 3);

        $this->post($ctx, $ctx['scheduleId'], 'fill');
        self::assertResponseStatusCodeSame(202, 'le comblement ne compte pas les verrous de sa source');

        $this->post($ctx, $draft->getId(), 'generate');
        self::assertResponseStatusCodeSame(422, 'générer la période compte les trois séances verrouillées pour 2 séances');
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertTrue($body['blocker'] ?? false);
        $this->assertNamesTheSessions((string) $body['error'], 'U11 A', ['lundi', 'mardi', 'mercredi'], []);
    }

    /**
     * Le compte se lit sur les séances de la période : U11 A, 2 séances en saison réduites à 1 pour la période, deux séances
     * verrouillées dans chaque version chargée. La saison ne bloque pas, la période bloque et nomme les deux séances.
     */
    public function testThePeriodCountIsReadOnThePeriodSessions(): void
    {
        $ctx = $this->seedPeriod($this->seedSolo(sessionsPerWeek: 2), planGroup: false);
        $seasonLoaded = (string) self::getContainer()->get(SchedulePlanProvisioner::class)->loadedVersionIdOfSeason($ctx['seasonId']);
        $reduced = self::getContainer()->get(PlanComposition::class)->lineOf($ctx['planId'], $ctx['teamA']);
        $reduced->setParticipating(true);
        $reduced->setSessionsPerWeek(1);
        foreach ([2, 3] as $day) {
            $this->pin($ctx, $seasonLoaded, $ctx['teamA'], $day, LockLevel::HARD, LockOrigin::MANUAL);
            $this->pin($ctx, $ctx['scheduleId'], $ctx['teamA'], $day, LockLevel::HARD, LockOrigin::MANUAL);
        }
        $season = $this->em->getRepository(Schedule::class)->find($seasonLoaded);
        self::assertInstanceOf(Schedule::class, $season);
        $this->assertPinnedForTheSolver(['clubId' => $ctx['clubId'], 'seasonId' => $ctx['seasonId']], $ctx['teamA'], 2);
        $this->assertPinnedForTheSolver($ctx, $ctx['teamA'], 2);

        self::assertNull($this->guard()->firstBlockerMessage($season), 'en saison, 2 séances verrouillées pour 2 séances : rien à dire');
        $message = $this->guard()->firstBlockerMessage($this->periodSchedule($ctx));
        self::assertNotNull($message, 'en période, 2 séances verrouillées pour 1 séance : le Récap de la période doit bloquer');
        $this->assertNamesTheSessions($message, 'U11 A', ['mardi', 'mercredi'], []);
    }

    /**
     * Le cadenas qui ne sert à rien, par l'API du cadenas : U11 A, 2 séances, réservée le jeudi ; la version chargée porte le jeudi (cadenas
     * d'origine réservation), le lundi et le mercredi verrouillés à la main. Le Récap nomme lundi et mercredi, jamais le jeudi.
     * Ôter le cadenas du jeudi ne change rien (même motif) ; ôter celui du lundi lève le blocage.
     */
    public function testUnlockingTheReservedCaseChangesNothingAndUnlockingANamedSessionLifts(): void
    {
        $ctx = $this->seedSolo(sessionsPerWeek: 2);
        $loaded = $this->version($ctx, ScheduleStatus::COMPLETED);
        $this->reserve($ctx, $ctx['teamA'], 4);
        $thursday = $this->pin($ctx, $loaded->getId(), $ctx['teamA'], 4, LockLevel::HARD, LockOrigin::RESERVATION);
        $monday = $this->pin($ctx, $loaded->getId(), $ctx['teamA'], 1, LockLevel::HARD, LockOrigin::MANUAL);
        $this->pin($ctx, $loaded->getId(), $ctx['teamA'], 3, LockLevel::HARD, LockOrigin::MANUAL);
        $this->assertPinnedForTheSolver($ctx, $ctx['teamA'], 3);

        $first = $this->recapBlockers($ctx);
        self::assertCount(1, $first, 'une réservation et deux séances verrouillées hors de sa case pour 2 séances : le Récap doit bloquer');
        $this->assertNamesTheSessions($first[0], 'U11 A', ['lundi', 'mercredi'], ['jeudi']);

        $this->unlock($ctx, $thursday);
        self::assertSame($first, $this->recapBlockers($ctx), 'le cadenas de la case réservée ne compte pas : même motif');

        $this->unlock($ctx, $monday);
        self::assertSame([], $this->recapBlockers($ctx), 'une séance nommée déverrouillée : le blocage est levé');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    private function assertRefusedNamingTheGroup(): void
    {
        self::assertResponseStatusCodeSame(422);
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertTrue($body['blocker'] ?? false, 'le front reconnaît un blocage à ce drapeau');
        self::assertStringContainsString(self::UNSERVABLE, (string) ($body['error'] ?? ''));
        self::assertStringContainsString('U11 A', (string) $body['error']);
        self::assertStringContainsString('U11 B', (string) $body['error']);
    }

    /**
     * Bearer plutôt que `loginUser` : un test qui enchaîne plusieurs requêtes garde son identité au redémarrage du noyau.
     *
     * @param array{user: User} $ctx
     */
    private function post(array $ctx, string $scheduleId, string $counter): void
    {
        $this->client->request('POST', "/api/schedules/{$scheduleId}/{$counter}", [], [], $this->bearer($ctx));
    }

    /**
     * @param array{user: User} $ctx
     *
     * @return array{HTTP_AUTHORIZATION: string}
     */
    private function bearer(array $ctx): array
    {
        return ['HTTP_AUTHORIZATION' => 'Bearer ' . self::getContainer()->get(JWTTokenManagerInterface::class)->create($ctx['user'])];
    }

    /**
     * Un club : U11 A (Ana) et U11 B (Bea) en groupe, un gymnase ouvert mardi et mercredi 18:00, capacité 2.
     *
     * @return array{user: User, clubId: string, seasonId: string, venueId: string, teamA: string, teamB: string, coachIds: list<string>}
     */
    private function seedGroup(bool $coachesAvailable): array
    {
        $uid = uniqid('', true);
        $club = (new Club)->setName('Club bloqué')->setSlug('recap-' . $uid)->setTimezone('Europe/Paris')->setLocale('fr')
            ->setOnboardingCompleted(true)->setFfbbClubCode('RCP' . strtoupper(substr(md5($uid), 0, 8)));
        $this->em->persist($club);
        $user = (new User)->setEmail('recap-' . $uid . '@test.com')->setFirstName('Gest')->setLastName('Ion')
            ->setPasswordHash('x')->setEmailVerifiedAt(new DateTimeImmutable);
        $this->em->persist($user);
        $this->em->flush();
        $clubId = $club->getId();
        $this->scopeGucToClub($clubId);
        $this->em->persist((new ClubUser)->setClubId($clubId)->setUserId($user->getId())->setRole('admin')->setIsActive(true));

        $season = (new Season)->setClubId($clubId)->setName('2026-2027')->setStartDate(new DateTimeImmutable('2026-09-01'))
            ->setEndDate(new DateTimeImmutable('2027-06-30'))->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($season);
        $this->em->flush();
        $seasonId = $season->getId();

        $venue = $this->newVenue($clubId, $seasonId, 'Gymnase Un');
        $this->em->persist($venue);
        $this->em->flush();
        foreach ([2, 3] as $day) {
            $this->em->persist((new VenueTrainingSlot)->setClubId($clubId)->setSeasonId($seasonId)->setSchedulePlanId(SeasonPlanOf::id(self::getContainer(), $seasonId))->setVenueId($venue->getId())
                ->setDayOfWeek($day)->setStartTime(new DateTimeImmutable('18:00'))->setDurationMinutes(90)->setCapacity(2));
        }

        $teamIds = [];
        $coachIds = [];
        foreach (['U11 A' => 'Ana', 'U11 B' => 'Bea'] as $teamName => $coachName) {
            $team = (new Team)->setClubId($clubId)->setSeasonId($seasonId)->setName($teamName)
                ->setSportCategoryId('cccccccc-cccc-4ccc-8ccc-cccccccccccc')->setPriorityTierId(1);
            $coach = $this->newCoach($clubId, $seasonId, $coachName, 'Berger');
            $this->em->persist($team);
            $this->em->flush();
            self::getContainer()->get(PlanComposition::class)->ensureLines($team->getSeasonId(), [$team->getId() => ['sessionsPerWeek' => 1]]);
            $this->em->persist($coach);
            $this->em->flush();
            $this->em->persist((new TeamCoach)->setClubId($clubId)->setSeasonId($seasonId)->setTeamId($team->getId())->setCoachId($coach->getId())->setRole(TeamCoachRole::MAIN));
            if (!$coachesAvailable) {
                $off = new Constraint;
                $off->setClubId($clubId)->setSeasonId($seasonId);
                $off->setName('Indispo ' . $coachName)->setScope(ConstraintScope::COACH)->setScopeTargetId($coach->getId());
                $off->setFamily(ConstraintFamily::COACH_AVAILABILITY)->setRuleType(ConstraintRuleType::HARD);
                $off->setConfig(['unavailableDays' => [1, 2, 3, 4, 5, 6, 7]]);
                $off->setIsActive(true);
                $this->em->persist($off);
            }
            $teamIds[] = $team->getId();
            $coachIds[] = $coach->getId();
        }
        $this->em->flush();
        $this->declareGroup($clubId, $seasonId, null, $teamIds);

        return ['user' => $user, 'clubId' => $clubId, 'seasonId' => $seasonId, 'venueId' => $venue->getId(), 'teamA' => $teamIds[0], 'teamB' => $teamIds[1], 'coachIds' => $coachIds];
    }

    /** @param list<string> $teamIds */
    private function declareGroup(string $clubId, string $seasonId, ?string $planId, array $teamIds): void
    {
        $block = (new SharedTrainingBlock)->setClubId($clubId)->setSeasonId($seasonId)->setSchedulePlanId($planId ?? SeasonPlanOf::id(self::getContainer(), $seasonId))->setCommonSessions(1);
        $this->em->persist($block);
        $this->em->flush();
        foreach ($teamIds as $teamId) {
            $this->em->persist((new SharedTrainingBlockTeam)->setClubId($clubId)->setSeasonId($seasonId)->setSchedulePlanId($planId ?? SeasonPlanOf::id(self::getContainer(), $seasonId))->setBlockId($block->getId())->setTeamId($teamId));
        }
        $this->em->flush();
    }

    /**
     * Le socle en vigueur, une FERMETURE (elle garde les règles coach et hérite du groupe), sa version terminée.
     *
     * @param array{user: User, clubId: string, seasonId: string, venueId: string, teamA: string, teamB: string, coachIds: list<string>} $ctx
     *
     * @return array{user: User, clubId: string, seasonId: string, venueId: string, teamA: string, teamB: string, coachIds: list<string>, scheduleId: string, planId: string}
     */
    private function seedPeriod(array $ctx, bool $planGroup): array
    {
        $season = $this->em->getRepository(Season::class)->find($ctx['seasonId']);
        self::assertInstanceOf(Season::class, $season);
        $this->settleSeasonPlan($season);

        if (!$planGroup) {
            foreach ($this->em->getRepository(SharedTrainingBlockTeam::class)->findBy(['seasonId' => $ctx['seasonId']]) as $member) {
                $this->em->remove($member);
            }
            foreach ($this->em->getRepository(SharedTrainingBlock::class)->findBy(['seasonId' => $ctx['seasonId']]) as $block) {
                $this->em->remove($block);
            }
            $this->em->flush();
        }

        $entry = (new CalendarEntry)->setClubId($ctx['clubId'])->setSeasonId($ctx['seasonId'])->setTitle('Fermeture')
            ->setKind(CalendarEntryKind::PERIOD)->setPeriodType(CalendarEntryPeriodType::CLOSURE)
            ->setStartDate(new DateTimeImmutable('2026-10-19'))->setEndDate(new DateTimeImmutable('2026-11-01'));
        $this->em->persist($entry);
        $this->em->flush();

        $schedule = (new Schedule)->setClubId($ctx['clubId'])->setSeasonId($ctx['seasonId'])->setName('Période')->setStatus(ScheduleStatus::COMPLETED);
        $this->linkSeededSchedule($schedule, $entry->getId());
        $this->em->flush();

        return [...$ctx, 'scheduleId' => $schedule->getId(), 'planId' => (string) $schedule->getSchedulePlanId()];
    }

    /**
     * Les deux équipes placées ensemble sur la version source, ces jours-là.
     *
     * @param array{clubId: string, seasonId: string, venueId: string, teamA: string, teamB: string, scheduleId: string} $ctx
     * @param list<int>                                                                                                  $days
     */
    private function placeTogether(array $ctx, array $days): void
    {
        foreach ($days as $day) {
            foreach ([$ctx['teamA'], $ctx['teamB']] as $teamId) {
                $this->em->persist((new ScheduleSlotTemplate)->setClubId($ctx['clubId'])->setSeasonId($ctx['seasonId'])->setScheduleId($ctx['scheduleId'])
                    ->setTeamId($teamId)->setVenueId($ctx['venueId'])->setDayOfWeek($day)->setStartTime(new DateTimeImmutable('18:00'))->setDurationMinutes(90));
            }
        }
        $this->em->flush();
    }

    /** @param array{clubId: string, seasonId: string} $ctx */
    private function version(array $ctx, ScheduleStatus $status): Schedule
    {
        $schedule = (new Schedule)->setClubId($ctx['clubId'])->setSeasonId($ctx['seasonId'])->setName('Version')->setStatus($status);
        $this->linkSeededSchedule($schedule);
        $this->em->flush();

        return $schedule;
    }

    /**
     * Le club de {@see seedGroup}, sans groupe, ouvert du lundi au jeudi à 18:00 : U11 A joue seule.
     *
     * @return array{user: User, clubId: string, seasonId: string, venueId: string, teamA: string, teamB: string, coachIds: list<string>}
     */
    private function seedSolo(int $sessionsPerWeek): array
    {
        $ctx = $this->seedGroup(coachesAvailable: true);
        foreach ($this->em->getRepository(SharedTrainingBlockTeam::class)->findBy(['seasonId' => $ctx['seasonId']]) as $member) {
            $this->em->remove($member);
        }
        foreach ($this->em->getRepository(SharedTrainingBlock::class)->findBy(['seasonId' => $ctx['seasonId']]) as $block) {
            $this->em->remove($block);
        }
        foreach ([1, 4] as $day) {
            $this->em->persist((new VenueTrainingSlot)->setClubId($ctx['clubId'])->setSeasonId($ctx['seasonId'])->setSchedulePlanId(SeasonPlanOf::id(self::getContainer(), $ctx['seasonId']))->setVenueId($ctx['venueId'])
                ->setDayOfWeek($day)->setStartTime(new DateTimeImmutable('18:00'))->setDurationMinutes(90)->setCapacity(2));
        }
        self::getContainer()->get(PlanComposition::class)->socleLinesOf([$ctx['teamA']])[$ctx['teamA']]->setSessionsPerWeek($sessionsPerWeek);
        $this->em->flush();

        return $ctx;
    }

    /**
     * Une réservation de saison de l'équipe à Gymnase Un, ce jour-là à 18:00.
     *
     * @param array{clubId: string, seasonId: string, venueId: string} $ctx
     */
    private function reserve(array $ctx, string $teamId, int $day): void
    {
        $reservation = (new Reservation)->setClubId($ctx['clubId'])->setSeasonId($ctx['seasonId'])->setSchedulePlanId(SeasonPlanOf::id(self::getContainer(), $ctx['seasonId']))->setVenueId($ctx['venueId'])
            ->setDayOfWeek($day)->setStartTime(new DateTimeImmutable('18:00'))->setDurationMinutes(90)->setTeamId($teamId);
        $this->em->persist($reservation);
        $this->em->flush();
    }

    /**
     * Une séance de la version, à Gymnase Un ce jour-là 18:00-19:30, au niveau de verrou donné. Rend son id.
     *
     * @param array{clubId: string, seasonId: string, venueId: string} $ctx
     */
    private function pin(array $ctx, string $scheduleId, string $teamId, int $day, LockLevel $level, ?LockOrigin $origin): string
    {
        $slot = (new ScheduleSlotTemplate)->setClubId($ctx['clubId'])->setSeasonId($ctx['seasonId'])->setScheduleId($scheduleId)
            ->setTeamId($teamId)->setVenueId($ctx['venueId'])->setDayOfWeek($day)->setStartTime(new DateTimeImmutable('18:00'))->setDurationMinutes(90)
            ->setLockLevel($level)->setLockOrigin($origin);
        $this->em->persist($slot);
        $this->em->flush();

        return $slot->getId();
    }

    /**
     * Le motif nomme l'équipe et les jours de ses séances verrouillées comptées, avec leurs heures et leur gymnase ; jamais les
     * jours à taire (une réservation, un verrou posé sur sa propre réservation).
     *
     * @param list<string> $named
     * @param list<string> $silent
     */
    private function assertNamesTheSessions(string $message, string $team, array $named, array $silent): void
    {
        self::assertStringContainsString($team, $message);
        foreach ($named as $day) {
            self::assertStringContainsString($day, $message, "la séance verrouillée du {$day} est nommée");
        }
        self::assertStringContainsString('18:00-19:30', $message, 'les heures de la séance sont dites');
        self::assertStringContainsString('Gymnase Un', $message, 'son gymnase est dit');
        foreach ($silent as $day) {
            self::assertStringNotContainsString($day, $message, "le {$day} n'est pas une séance verrouillée comptée");
        }
    }

    /**
     * Décor : ces épingles HARD de l'équipe partent bien au solveur (réservations et séances verrouillées de la version chargée).
     * Un rouge du comptage ne peut alors venir que de la règle, jamais d'un verrou absent du payload.
     *
     * @param array{clubId: string, seasonId: string, planId?: string} $ctx
     */
    private function assertPinnedForTheSolver(array $ctx, string $teamId, int $expected): void
    {
        $builder = self::getContainer()->get(ScheduleConstraintBuilder::class);
        if (isset($ctx['planId'])) {
            $entry = $this->em->getRepository(CalendarEntry::class)->find($this->entryIdOf($ctx['planId']));
            self::assertInstanceOf(CalendarEntry::class, $entry);
            $payload = $builder->buildForPlan($ctx['planId']);
        } else {
            $payload = $builder->buildForPlan(self::getContainer()->get(SoclePlan::class)->idOf($ctx['seasonId']));
        }
        $pins = array_filter(
            $payload['slotTemplates'] ?? [],
            static fn (mixed $pin): bool => \is_array($pin) && $teamId === ($pin['teamId'] ?? null) && LockLevel::HARD->value === ($pin['lockLevel'] ?? null),
        );
        self::assertCount($expected, $pins, 'décor : épingles HARD de l\'équipe envoyées au solveur');
    }

    private function guard(): GenerationBlockersGuard
    {
        return self::getContainer()->get(GenerationBlockersGuard::class);
    }

    /**
     * Les blocages du Récap de saison.
     *
     * @param array{user: User} $ctx
     *
     * @return list<string>
     */
    private function recapBlockers(array $ctx): array
    {
        $this->client->request('POST', '/api/constraints/validate', [], [], $this->bearer($ctx));
        $recap = json_decode((string) $this->client->getResponse()->getContent(), true);

        return array_values($recap['blockers'] ?? []);
    }

    /**
     * Le geste du cadenas : la séance repasse sans verrou.
     *
     * @param array{user: User} $ctx
     */
    private function unlock(array $ctx, string $slotId): void
    {
        $this->client->request('POST', "/api/schedule-slots/{$slotId}/manual-edit/lock", [], [], [...$this->bearer($ctx), 'CONTENT_TYPE' => 'application/json'], (string) json_encode(['lockLevel' => 'NONE']));
        self::assertResponseIsSuccessful('le cadenas s\'ôte');
    }

    /** @param array{scheduleId: string} $ctx */
    private function periodSchedule(array $ctx): Schedule
    {
        $schedule = $this->em->getRepository(Schedule::class)->find($ctx['scheduleId']);
        self::assertInstanceOf(Schedule::class, $schedule);

        return $schedule;
    }

    private function entryIdOf(string $planId): string
    {
        return (string) $this->em->getConnection()->fetchOne('SELECT calendar_entry_id FROM schedule_plan WHERE id = :pid', ['pid' => $planId]);
    }
}
