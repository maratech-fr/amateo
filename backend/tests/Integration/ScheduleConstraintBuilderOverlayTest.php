<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Constraint;
use App\Entity\ConstraintPeriodOverride;
use App\Entity\Schedule;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\Season;
use App\Entity\Team;
use App\Entity\TeamPeriodOverride;
use App\Entity\TeamTag;
use App\Entity\TeamTagAssignment;
use App\Entity\User;
use App\Entity\Venue;
use App\Entity\VenuePeriodOverride;
use App\Entity\VenueTrainingSlot;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\ConstraintFamily;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use App\Enum\Gender;
use App\Enum\ScheduleStatus;
use App\Enum\SeasonStatus;
use App\Enum\VenuePeriodMode;
use App\Service\PeriodConstraintSelector;
use App\Service\ScheduleConstraintBuilder;
use App\Tests\ProvisionsPeriodPlanTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Constraint semantics (NR): a closure overlay expands each closed venue into
 * per-team FACILITY HARD forbiddenVenueId constraints (the shape the engine
 * honors), reuses permanent + dated constraints, and never writes the base
 * schedule-input cache.
 */
#[Group('phase1')]
#[Group('integration')]
final class ScheduleConstraintBuilderOverlayTest extends KernelTestCase
{
    use ProvisionsPeriodPlanTrait;

    use TenantGucTrait;

    private const VENUE_CLOSED = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    private const VENUE_OPEN = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';

    private EntityManagerInterface $em;

    private ScheduleConstraintBuilder $builder;

    public function testClosureOverlayRemovesTheClosedVenueSlots(): void
    {
        // P2-5 5b : un gymnase fermé sur TOUTE la fenêtre (closure Mon→Sun, config
        // sans dates → fallback tous-jours) perd TOUS ses créneaux du payload — plus
        // de forbiddenVenueId (l'engine ne le voit pas ; il ne peut simplement pas
        // l'utiliser, faute de créneau). Le forbid tous-jours est supprimé.
        [$club, $season] = $this->seed();
        $this->team($club, $season, 'U11');
        $this->team($club, $season, 'U13');
        $this->permanentConstraint($club, $season);
        $entry = $this->closurePeriod($club, $season);
        $schedule = $this->overlaySchedule($club, $season, $entry);
        $this->venue($club, $season, self::VENUE_CLOSED, 'Gym fermé');
        $this->datedClosedVenueConstraint($club, $season, $entry);
        $this->venueSlot($club, $season, self::VENUE_CLOSED, null, 1); // lundi
        $this->venueSlot($club, $season, self::VENUE_CLOSED, null, 4); // jeudi
        $this->em->flush();

        $payload = $this->builder->buildForOverlay($schedule, $entry);

        // Plus AUCUN forbiddenVenueId (mécanisme supprimé).
        $forbidden = array_filter($payload['constraints'], static fn (array $c): bool => isset($c['config']['forbiddenVenueId']));
        self::assertCount(0, $forbidden, 'le forbid tous-jours est remplacé par le retrait de créneaux');
        // Fenêtre entière fermée → le gym n'a plus aucun créneau.
        self::assertSame([], $this->closedVenueWeekdays($schedule, $entry), 'un gym fermé toute la semaine perd tous ses créneaux');

        // Closure additive : la permanente reste.
        $names = array_map(static fn (array $c): string => $c['name'] ?? '', $payload['constraints']);
        self::assertContains('Contrainte permanente', $names);
    }

    public function testWeekClosureRemovesSlotsOnClosedDaysOnly(): void
    {
        // P2-5 5b — LE cœur : fermeture datée jeudi→dimanche sur une semaine pleine
        // lun→dim. Le gym GARDE ses créneaux lun/mar/mer (ouvert), PERD jeu/ven/sam/dim.
        [$club, $season] = $this->seed();
        $this->team($club, $season, 'U11');
        // Semaine Mon 2026-05-04 → Sun 2026-05-10 ; incident Thu 05-07 → Sun 05-10.
        // Entrée SANS la datée auto de closurePeriod (elle serait sans dates = tous-jours).
        // #8 : la grille de la période est une COPIE du modèle de saison, faite à la
        // naissance du plan — les créneaux de saison doivent donc exister AVANT.
        $this->venue($club, $season, self::VENUE_CLOSED, 'Gym fermé jeu-dim');
        foreach ([1, 2, 3, 4, 5, 6, 7] as $day) {
            $this->venueSlot($club, $season, self::VENUE_CLOSED, null, $day);
        }
        $this->em->flush();
        $entry = $this->bareClosurePeriod($club, $season, '2026-05-04', '2026-05-10');
        $schedule = $this->overlaySchedule($club, $season, $entry);
        $this->datedClosedVenueConstraintDated($club, $season, $entry, '2026-05-07', '2026-05-10');
        $this->em->flush();

        $kept = $this->closedVenueWeekdays($schedule, $entry);
        sort($kept);
        self::assertSame([1, 2, 3], $kept, 'le gym reste ouvert lun-mer (hors incident), fermé jeu-dim');
    }

    /**
     * P2-38 (axe constraint semantics) — LE TEST DU LOT : une fermeture portée par l'entrée A
     * retire les créneaux du PAYLOAD d'un plan de l'entrée B dont la fenêtre recoupe ses dates.
     * Le défaut réel (terrain BCCL) : « Matéo en travaux » sur la période racine ne fermait pas
     * le gymnase pendant la semaine de reprise — le solveur y plaçait des séances en silence.
     */
    public function testAClosureCarriedByAnotherEntryRemovesThisPlansSlots(): void
    {
        [$club, $season] = $this->seed();
        $this->team($club, $season, 'U11');
        $this->venue($club, $season, self::VENUE_CLOSED, 'Gym en travaux');
        // Grille de SAISON lun→dim, copiée à la naissance du plan B.
        foreach ([1, 2, 3, 4, 5, 6, 7] as $day) {
            $this->venueSlot($club, $season, self::VENUE_CLOSED, null, $day);
        }
        $this->em->flush();

        // Plan B : la semaine de reprise (lun 2026-05-04 → dim 2026-05-10).
        $entryB = $this->bareClosurePeriod($club, $season, '2026-05-04', '2026-05-10');
        $schedule = $this->overlaySchedule($club, $season, $entryB);

        // Entrée A : une AUTRE période (la racine), qui PORTE la fermeture jeu→dim 05-07→05-10.
        $entryA = $this->bareClosurePeriod($club, $season, '2026-05-01', '2026-05-31');
        $this->datedClosedVenueConstraintDated($club, $season, $entryA, '2026-05-07', '2026-05-10');
        $this->em->flush();

        $kept = $this->closedVenueWeekdays($schedule, $entryB);
        sort($kept);
        self::assertSame([1, 2, 3], $kept, 'la fermeture d’une AUTRE entrée retire les créneaux du plan aux bons jours (jeu-dim), garde lun-mer');

        // R5 — la fermeture transversale n'entre PAS dans la sélection de période (le sélecteur
        // ne charge que les datées de l'entrée du plan) : elle retire des créneaux SANS ajouter
        // le moindre warning au gate. Les trois sources de warning de sélection sont vides.
        $selection = self::getContainer()->get(PeriodConstraintSelector::class)
            ->selectForPeriodPlan($club->getId(), $season->getId(), $this->planIdOf($entryB), $entryB);
        self::assertSame([], $selection->droppedForDisabledVenue, 'aucun warning « gymnase fermé » (R5)');
        self::assertSame([], $selection->partiallyAppliedForDisabledVenue, 'aucun warning « appliquée partiellement » (R5)');
        self::assertSame([], $selection->droppedForInertTag, 'aucun warning « tag inerte » (R5)');
    }

    /**
     * P2-38 — falsification : une fermeture d'une autre entrée ENTIÈREMENT hors de la fenêtre du
     * plan ne retire AUCUN créneau (la transversalité est bornée par le recoupement des dates).
     */
    public function testAClosureOutsideThisPlansWindowLeavesTheSlotsIntact(): void
    {
        [$club, $season] = $this->seed();
        $this->team($club, $season, 'U11');
        $this->venue($club, $season, self::VENUE_CLOSED, 'Gym lointain');
        foreach ([1, 2, 3, 4, 5, 6, 7] as $day) {
            $this->venueSlot($club, $season, self::VENUE_CLOSED, null, $day);
        }
        $this->em->flush();

        $entryB = $this->bareClosurePeriod($club, $season, '2026-05-04', '2026-05-10');
        $schedule = $this->overlaySchedule($club, $season, $entryB);

        // Fermeture d'une autre entrée, en JUIN : hors de la fenêtre de mai de B.
        $entryA = $this->bareClosurePeriod($club, $season, '2026-06-01', '2026-06-30');
        $this->datedClosedVenueConstraintDated($club, $season, $entryA, '2026-06-10', '2026-06-20');
        $this->em->flush();

        $kept = $this->closedVenueWeekdays($schedule, $entryB);
        sort($kept);
        self::assertSame([1, 2, 3, 4, 5, 6, 7], $kept, 'une fermeture hors fenêtre ne retire aucun créneau');
    }

    public function testHolidayOverlayRemovesTheClosedVenueSlots(): void
    {
        // Même mécanisme côté reprise (holiday) : le gym fermé perd ses créneaux.
        [$club, $season] = $this->seed();
        $this->team($club, $season, 'U11');
        $this->permanentConstraint($club, $season);
        $entry = $this->holidayPeriod($club, $season);
        $schedule = $this->overlaySchedule($club, $season, $entry);
        $this->venue($club, $season, self::VENUE_CLOSED, 'Gym fermé');
        $this->datedClosedVenueConstraint($club, $season, $entry);
        $this->venueSlot($club, $season, self::VENUE_CLOSED, null, 2);
        $this->em->flush();

        $names = array_map(static fn (array $c): string => $c['name'] ?? '', $this->builder->buildForOverlay($schedule, $entry)['constraints']);
        self::assertContains('Contrainte permanente', $names, 'a CLUB permanent is inherited in a reprise');
        self::assertSame([], $this->closedVenueWeekdays($schedule, $entry), 'le gym fermé n’a plus de créneau côté reprise');
    }

    public function testOverlayBuildDoesNotWriteBaseCache(): void
    {
        [$club, $season] = $this->seed();
        $this->team($club, $season, 'U11');
        $entry = $this->closurePeriod($club, $season);
        $schedule = $this->overlaySchedule($club, $season, $entry);
        $this->em->flush();

        /** @var CacheItemPoolInterface&CacheInterface $pool */
        $pool = self::getContainer()->get('cache.schedule');
        $pool->deleteItem(ScheduleConstraintBuilder::cacheKey($club->getId(), $season->getId()));

        $this->builder->buildForOverlay($schedule, $entry);

        self::assertFalse(
            $pool->getItem(ScheduleConstraintBuilder::cacheKey($club->getId(), $season->getId()))->isHit(),
            'overlay build must not populate the base schedule-input cache',
        );
    }

    public function testBaseBuildExcludesOverlaySlots(): void
    {
        [$club, $season] = $this->seed();
        // A base schedule with one slot, and an overlay schedule with one slot.
        $base = $this->overlaySchedule($club, $season, null, 'baaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
        $entry = $this->closurePeriod($club, $season);
        $overlay = $this->overlaySchedule($club, $season, $entry, 'ceeeeeee-eeee-4eee-8eee-eeeeeeeeeeee');
        $baseVenue = '10000000-0000-4000-8000-000000000001';
        $overlayVenue = '20000000-0000-4000-8000-000000000002';
        $this->slot($base, $baseVenue);
        $this->slot($overlay, $overlayVenue);
        $this->em->flush();

        $payload = $this->builder->buildForClubSeason($club->getId(), $season->getId());

        $venues = array_map(static fn (array $s): string => $s['venueId'], $payload['slotTemplates']);
        self::assertContains($baseVenue, $venues, 'base slots feed the base build');
        self::assertNotContains($overlayVenue, $venues, 'overlay slots must NOT leak into the base build');
    }

    public function testOverlayExcludesDeactivatedTeam(): void
    {
        [$club, $season] = $this->seed();
        $teamA = $this->team($club, $season, 'U11');
        $teamB = $this->team($club, $season, 'U13');
        $entry = $this->holidayPeriod($club, $season);
        $this->teamOverride($club, $season, $entry, $teamB, false, null); // B off for the period
        $schedule = $this->overlaySchedule($club, $season, $entry);
        $this->em->flush();

        $ids = array_map(static fn (array $t): string => $t['id'], $this->builder->buildForOverlay($schedule, $entry)['teams']);

        self::assertContains($teamA->getId(), $ids, 'an active team is placed');
        self::assertNotContains($teamB->getId(), $ids, 'a team deactivated for the period gets no sessions');
    }

    public function testOverlayHonorsSessionsPerWeekOverride(): void
    {
        [$club, $season] = $this->seed();
        $team = $this->team($club, $season, 'U11'); // seasonal sessionsPerWeek defaults to 2
        $entry = $this->holidayPeriod($club, $season);
        $this->teamOverride($club, $season, $entry, $team, true, 1); // reduced to 1 for the period
        $schedule = $this->overlaySchedule($club, $season, $entry);
        $this->em->flush();

        $teams = $this->builder->buildForOverlay($schedule, $entry)['teams'];
        $serialized = array_values(array_filter($teams, static fn (array $t): bool => $t['id'] === $team->getId()))[0];

        self::assertSame(1, $serialized['sessionsPerWeek'], 'the period override replaces the seasonal volume');
    }

    public function testOverlaySlotsAreAdditiveAndBaseExcludesPeriodSlots(): void
    {
        [$club, $season] = $this->seed();
        $this->team($club, $season, 'U11');
        $entry = $this->holidayPeriod($club, $season);
        $seasonalVenue = $this->venue($club, $season, '10000000-0000-4000-8000-000000000011', 'Gym socle');
        $cityVenue = $this->venue($club, $season, '20000000-0000-4000-8000-000000000022', 'Gym mairie');
        $this->venueSlot($club, $season, $seasonalVenue->getId(), null); // seasonal
        $this->venueSlot($club, $season, $cityVenue->getId(), $this->planIdOf($entry)); // prêté à CE plan
        $schedule = $this->overlaySchedule($club, $season, $entry);
        $this->em->flush();

        // Overlay build: seasonal ∪ period (additive).
        $overlayVenues = $this->venuesBySlotCount($this->builder->buildForOverlay($schedule, $entry)['venues']);
        self::assertSame(1, $overlayVenues[$seasonalVenue->getId()], 'seasonal slot kept in the overlay');
        self::assertSame(1, $overlayVenues[$cityVenue->getId()], 'the city-lent period slot is added');

        // Base build: the period slot must NOT leak in.
        $baseVenues = $this->venuesBySlotCount($this->builder->buildForClubSeason($club->getId(), $season->getId())['venues']);
        self::assertSame(1, $baseVenues[$seasonalVenue->getId()], 'seasonal slot feeds the base');
        self::assertSame(0, $baseVenues[$cityVenue->getId()], 'period slot never leaks into the base plan');
    }

    public function testSessionOverrideDoesNotLeakIntoASubsequentBaseBuild(): void
    {
        [$club, $season] = $this->seed();
        $team = $this->team($club, $season, 'U11'); // seasonal sessionsPerWeek = 2
        $entry = $this->holidayPeriod($club, $season);
        $this->teamOverride($club, $season, $entry, $team, true, 1);
        $schedule = $this->overlaySchedule($club, $season, $entry);
        $this->em->flush();

        // Overlay build sets the session-override map on the shared builder instance…
        $this->builder->buildForOverlay($schedule, $entry);
        // …a later base build on the SAME instance must NOT inherit it.
        $baseTeams = $this->builder->buildForClubSeason($club->getId(), $season->getId())['teams'];
        $serialized = array_values(array_filter($baseTeams, static fn (array $t): bool => $t['id'] === $team->getId()))[0];

        self::assertSame(2, $serialized['sessionsPerWeek'], 'the base plan keeps the seasonal volume — no override leak');
    }

    /**
     * NR #8 (fondateur 2026-07-24) — UNE PÉRIODE POSSÈDE SA GRILLE : à la naissance du
     * plan, les créneaux de saison sont COPIÉS. La période ne lit ensuite QUE les siens —
     * un créneau de saison ajouté APRÈS n'y entre pas, et la saison n'est jamais modifiée.
     */
    public function testPeriodGridIsACopyTakenAtPlanBirth(): void
    {
        [$club, $season] = $this->seed();
        $this->team($club, $season, 'U11');
        $venue = $this->venue($club, $season, '10000000-0000-4000-8000-0000000000f1', 'Gym Barros');
        $this->venueSlot($club, $season, $venue->getId(), null, 2); // saison, AVANT la période
        $this->em->flush();

        $entry = $this->holidayPeriod($club, $season); // ← naissance du plan : copie
        $schedule = $this->overlaySchedule($club, $season, $entry);
        // Un créneau de saison ajouté APRÈS coup : la période ne le voit pas.
        $this->venueSlot($club, $season, $venue->getId(), null, 4);
        $this->em->flush();

        $counts = $this->venuesBySlotCount($this->builder->buildForOverlay($schedule, $entry)['venues']);
        self::assertSame(1, $counts[$venue->getId()], 'la période porte la copie prise à sa naissance, et rien d’autre');

        // Le planning principal garde ses deux créneaux : la copie ne lui a rien pris.
        $base = $this->venuesBySlotCount($this->builder->buildForClubSeason($club->getId(), $season->getId())['venues']);
        self::assertSame(2, $base[$venue->getId()], 'le planning principal n’est jamais modifié');
    }

    /**
     * NR #8 — gymnase DÉSACTIVÉ : il sort entièrement du payload (créneaux, gymnase,
     * verrous, réservations) et aucune contrainte ne peut plus le nommer — un id fantôme
     * rendrait le solve INFEASIBLE. Rien n'est détruit pour autant.
     */
    public function testDisabledVenueLeavesThePayloadEntirely(): void
    {
        [$club, $season] = $this->seed();
        $this->team($club, $season, 'U11');
        $kept = $this->venue($club, $season, '10000000-0000-4000-8000-0000000000a1', 'Gym gardé');
        $off = $this->venue($club, $season, '10000000-0000-4000-8000-0000000000a2', 'Gym désactivé');
        $this->venueSlot($club, $season, $kept->getId(), null, 1);
        $this->venueSlot($club, $season, $off->getId(), null, 2);
        $this->em->flush();

        $entry = $this->holidayPeriod($club, $season);
        $schedule = $this->overlaySchedule($club, $season, $entry);
        $facility = new Constraint;
        $facility->setClubId($club->getId());
        $facility->setSeasonId($season->getId());
        $facility->setName('Gym désactivé interdit');
        $facility->setScope(ConstraintScope::FACILITY);
        $facility->setFamily(ConstraintFamily::FACILITY);
        $facility->setRuleType(ConstraintRuleType::HARD);
        $facility->setScopeTargetId($off->getId());
        $facility->setCalendarEntryId($entry->getId());
        $this->em->persist($facility);
        $this->venueMode($club, $season, $entry, $off->getId(), VenuePeriodMode::DISABLED);
        $this->em->flush();

        $payload = $this->builder->buildForOverlay($schedule, $entry);
        $venueIds = array_map(static fn (array $v): string => $v['id'], $payload['venues']);

        self::assertContains($kept->getId(), $venueIds, 'un gymnase normal reste dans le payload');
        self::assertNotContains($off->getId(), $venueIds, 'le gymnase désactivé sort du payload');
        foreach ($payload['constraints'] as $row) {
            if (\is_array($row)) {
                self::assertNotSame($off->getId(), $row['scopeTargetId'] ?? null, 'aucune contrainte ne nomme un gymnase absent du payload');
            }
        }

        // Rien n'a été détruit : les créneaux de la période existent toujours en base.
        $this->em->clear();
        self::assertCount(
            1,
            $this->em->getRepository(VenueTrainingSlot::class)->findBy(['schedulePlanId' => $this->planIdOf($entry), 'venueId' => $off->getId()]),
            'désactiver n’est pas supprimer — les créneaux de la période survivent',
        );
    }

    /**
     * NR indispo informative (§7.1 constraint semantics) — un gymnase DÉSACTIVÉ court-circuite
     * son masque : il sort entièrement du payload, un masque OPEN ne le ressuscite pas (dormant).
     */
    public function testDisabledVenueShortCircuitsItsMask(): void
    {
        [$club, $season] = $this->seed();
        $this->team($club, $season, 'U11');
        $this->venue($club, $season, self::VENUE_CLOSED, 'Gym désactivé');
        foreach ([1, 2, 3, 4, 5, 6, 7] as $day) {
            $this->venueSlot($club, $season, self::VENUE_CLOSED, null, $day);
        }
        $this->em->flush();
        $entry = $this->holidayPeriod($club, $season);
        $schedule = $this->overlaySchedule($club, $season, $entry);
        // DÉSACTIVÉ + masque {lundi: OPEN} : le masque est DORMANT, le gymnase reste hors payload.
        $this->venueOverride($club, $season, $entry, self::VENUE_CLOSED, VenuePeriodMode::DISABLED, [1 => 'OPEN']);
        $this->em->flush();

        $venueIds = array_map(static fn (array $v): string => $v['id'], $this->builder->buildForOverlay($schedule, $entry)['venues']);
        self::assertNotContains(self::VENUE_CLOSED, $venueIds, 'un gymnase désactivé sort du payload — son masque OPEN ne le ressuscite pas');
    }

    /**
     * NR indispo informative — BLANK compose avec le masque : l'incident redevient informatif
     * (jour fermé rouvert), le masque CLOSED ferme son jour. Incident jeudi + BLANK + masque
     * {samedi: CLOSED} → le gymnase garde tous ses jours SAUF le samedi (le jeudi revient).
     */
    public function testBlankComposesWithTheMask(): void
    {
        [$club, $season] = $this->seed();
        $this->team($club, $season, 'U11');
        $this->venue($club, $season, self::VENUE_CLOSED, 'Gym BLANK + masque');
        foreach ([1, 2, 3, 4, 5, 6, 7] as $day) {
            $this->venueSlot($club, $season, self::VENUE_CLOSED, null, $day);
        }
        $this->em->flush();
        $entry = $this->bareClosurePeriod($club, $season, '2026-05-04', '2026-05-10');
        $schedule = $this->overlaySchedule($club, $season, $entry);
        $this->datedClosedVenueConstraintDated($club, $season, $entry, '2026-05-07', '2026-05-07'); // incident jeudi
        $this->venueOverride($club, $season, $entry, self::VENUE_CLOSED, VenuePeriodMode::BLANK, [6 => 'CLOSED']);
        $this->em->flush();

        $kept = $this->closedVenueWeekdays($schedule, $entry);
        sort($kept);
        self::assertSame([1, 2, 3, 4, 5, 7], $kept, 'BLANK rouvre le jeudi (incident informatif), le masque ferme le samedi');
    }

    /**
     * NR indispo informative — construire le payload NE MODIFIE PAS le masque d'un gymnase
     * désactivé : il reste DORMANT et STOCKÉ, prêt à revenir à la réactivation.
     */
    public function testDisabledVenueMaskStaysStoredAfterBuild(): void
    {
        [$club, $season] = $this->seed();
        $this->team($club, $season, 'U11');
        $this->venue($club, $season, self::VENUE_CLOSED, 'Gym dormant');
        $this->venueSlot($club, $season, self::VENUE_CLOSED, null, 2);
        $this->em->flush();
        $entry = $this->holidayPeriod($club, $season);
        $schedule = $this->overlaySchedule($club, $season, $entry);
        $override = $this->venueOverride($club, $season, $entry, self::VENUE_CLOSED, VenuePeriodMode::DISABLED, [6 => 'CLOSED']);
        $this->em->flush();
        $overrideId = $override->getId();

        $this->builder->buildForOverlay($schedule, $entry);

        $this->em->clear();
        self::assertSame([6 => 'CLOSED'], $this->em->getRepository(VenuePeriodOverride::class)->find($overrideId)?->getDayOverrides(), 'le masque dormant survit intact à un build (lecture seule)');
    }

    public function testOverlayDropsConstraintDisabledForPeriod(): void
    {
        [$club, $season] = $this->seed();
        $this->team($club, $season, 'U11');
        $permanent = $this->permanentConstraint($club, $season);
        $entry = $this->closurePeriod($club, $season);
        $this->constraintOverride($club, $season, $entry, $permanent, false); // disabled for this period
        $schedule = $this->overlaySchedule($club, $season, $entry);
        $this->em->flush();

        // Base plan still carries the permanent constraint (sparse diff — base untouched)…
        $baseNames = array_map(static fn (array $c): string => $c['name'] ?? '', $this->builder->buildForClubSeason($club->getId(), $season->getId())['constraints']);
        self::assertContains('Contrainte permanente', $baseNames, 'the base plan keeps the constraint');

        // …but the overlay drops the constraint the manager disabled for the period.
        $overlayNames = array_map(static fn (array $c): string => $c['name'] ?? '', $this->builder->buildForOverlay($schedule, $entry)['constraints']);
        self::assertNotContains('Contrainte permanente', $overlayNames, 'a constraint disabled for the period is not honored in the overlay');
    }

    public function testOverlayKeepsConstraintWithActiveOverride(): void
    {
        [$club, $season] = $this->seed();
        $this->team($club, $season, 'U11');
        $permanent = $this->permanentConstraint($club, $season);
        $entry = $this->closurePeriod($club, $season);
        $this->constraintOverride($club, $season, $entry, $permanent, true); // explicit active = no-op
        $schedule = $this->overlaySchedule($club, $season, $entry);
        $this->em->flush();

        $overlayNames = array_map(static fn (array $c): string => $c['name'] ?? '', $this->builder->buildForOverlay($schedule, $entry)['constraints']);
        self::assertContains('Contrainte permanente', $overlayNames, 'only isActive=false drops the constraint');
    }

    public function testRepriseInheritsPermanentsWithSmartDefault(): void
    {
        [$club, $season] = $this->seed();
        $active = $this->team($club, $season, 'SM1'); // reprend (Fanion, actif)
        $paused = $this->team($club, $season, 'U11'); // en pause pour la période
        $entry = $this->holidayPeriod($club, $season);
        $this->teamOverride($club, $season, $entry, $paused, false, null);
        $this->permanentScoped($club, $season, ConstraintScope::CLUB, null, 'Club perm');
        $this->permanentScoped($club, $season, ConstraintScope::TEAM, $active->getId(), 'Team active perm');
        $this->permanentScoped($club, $season, ConstraintScope::TEAM, $paused->getId(), 'Team paused perm');
        $this->permanentScoped($club, $season, ConstraintScope::FACILITY, 'ffffffff-ffff-4fff-8fff-ffffffffffff', 'Facility perm');
        $schedule = $this->overlaySchedule($club, $season, $entry);
        $this->em->flush();

        $names = array_map(static fn (array $c): string => $c['name'] ?? '', $this->builder->buildForOverlay($schedule, $entry)['constraints']);
        self::assertContains('Club perm', $names, 'CLUB kept by default in a reprise');
        self::assertContains('Team active perm', $names, 'a TEAM constraint of a team that reprend is kept');
        self::assertNotContains('Team paused perm', $names, 'a TEAM constraint of a paused team is dropped by default');
        self::assertNotContains('Facility perm', $names, 'a FACILITY constraint is dropped by default in a reprise');
    }

    public function testRepriseOverrideDeviatesFromDefault(): void
    {
        [$club, $season] = $this->seed();
        $this->team($club, $season, 'SM1');
        $entry = $this->holidayPeriod($club, $season);
        $clubC = $this->permanentScoped($club, $season, ConstraintScope::CLUB, null, 'Club perm');
        $facilityC = $this->permanentScoped($club, $season, ConstraintScope::FACILITY, 'ffffffff-ffff-4fff-8fff-ffffffffffff', 'Facility perm');
        $this->constraintOverride($club, $season, $entry, $clubC, false); // drop a CLUB kept by default
        $this->constraintOverride($club, $season, $entry, $facilityC, true); // keep a FACILITY dropped by default
        $schedule = $this->overlaySchedule($club, $season, $entry);
        $this->em->flush();

        $names = array_map(static fn (array $c): string => $c['name'] ?? '', $this->builder->buildForOverlay($schedule, $entry)['constraints']);
        self::assertNotContains('Club perm', $names, 'an explicit isActive=false drops a CLUB kept by default');
        self::assertContains('Facility perm', $names, 'an explicit isActive=true keeps a FACILITY dropped by default');
    }

    public function testRepriseDropsTeamConstraintForPausedTeamEvenIfKept(): void
    {
        [$club, $season] = $this->seed();
        $paused = $this->team($club, $season, 'U11');
        $entry = $this->holidayPeriod($club, $season);
        $this->teamOverride($club, $season, $entry, $paused, false, null); // en pause
        $c = $this->permanentScoped($club, $season, ConstraintScope::TEAM, $paused->getId(), 'Paused team perm');
        $this->constraintOverride($club, $season, $entry, $c, true); // le gestionnaire la GARDE explicitement
        $schedule = $this->overlaySchedule($club, $season, $entry);
        $this->em->flush();

        $names = array_map(static fn (array $c): string => $c['name'] ?? '', $this->builder->buildForOverlay($schedule, $entry)['constraints']);
        self::assertNotContains('Paused team perm', $names, 'a TEAM constraint targeting a paused team never ships (no ghost teamId), even if explicitly kept');
    }

    /**
     * P2-9ter NR-1 — LE garde du lot : construire un payload ne DOIT rien écrire.
     *
     * Le builder appelait `TeamTagService::syncTeamTags` en pleine sérialisation : il
     * SUPPRIMAIT les `TeamTagAssignment` de l'équipe puis les recréait, avec un `flush()`
     * intermédiaire qui rendait les suppressions définitives. Ouvrir le récap (qui ne
     * flushe jamais) effaçait donc les tags de la dernière équipe — la perte de données
     * qui a fait échouer la 3e tentative du volet capacité.
     *
     * ⚠ L'assertion porte sur les **id**, pas sur un décompte : une suppression suivie
     * d'une recréation laisse le compte à 1 tout en changeant l'UUID. Un `assertCount`
     * serait donc vert alors que la donnée d'origine a bien été détruite.
     */
    public function testBuildNeverWritesTeamTagAssignments(): void
    {
        [$club, $season] = $this->seed();
        $team = $this->team($club, $season, 'SM1');
        $this->em->flush();

        // Un tag que `determineTagNames` ne dériverait JAMAIS de cette équipe : s'il
        // survit, c'est que personne ne l'a resynchronisé.
        // ⚠ Le fixture s'appuie sur le fait que `team()` ne pose ni genre ni niveau et
        // pointe une SportCategory inexistante — l'équipe ne dérive donc AUCUN tag
        // système, et l'assertion exacte plus bas est légitime. Ajouter un genre au
        // helper ferait tomber ce test pour une raison sans rapport avec la règle gardée.
        // ⚠ On RÉUTILISE le tag U13 que le listener vient de créer, on n'en sème pas un
        // second. `getOrCreateSystemTags` pose les 22 tags système à CHAQUE écriture d'équipe
        // — créer un tag n'est pas l'assigner. Ce fixture en créait donc un DOUBLON sans le
        // savoir ; l'index unique de P4-64 l'a révélé. La règle gardée ici est inchangée :
        // c'est l'ASSIGNATION ci-dessous que `determineTagNames` ne dériverait jamais.
        $tag = $this->em->getRepository(TeamTag::class)->findOneBy(['clubId' => $club->getId(), 'name' => 'U13']);
        // ⚠ Pas de repli `?? new TeamTag` (revue #356) : il serait INATTEIGNABLE, et le jour
        // où le listener cesserait de semer U13 il créerait le tag à la main — le test
        // resterait vert en n'exerçant plus un tag semé par le listener, c'est-à-dire le
        // « fixture qui vérifie ses propres fixtures » que ce fichier dénonce plus bas.
        self::assertInstanceOf(TeamTag::class, $tag, 'garde du fixture : le listener doit avoir semé U13');
        // BCK-11 : tenant + RLS, une ligne sans club_id est refusée en base.
        $this->em->persist((new TeamTagAssignment)->setClubId($team->getClubId())->setTagId($tag->getId())->setTeamId($team->getId())->setSeasonId($season->getId()));
        $this->em->flush();

        $before = $this->assignmentIdsOf($team->getId(), $season->getId());
        self::assertCount(1, $before, 'garde du test lui-même : l’assignation semée doit exister');

        // Le cache est servi AVANT tout calcul : sans ce purge, un hit rendrait le test
        // creux — vert sans avoir jamais exécuté serializeTeam.
        self::getContainer()->get('cache.schedule')->deleteItem(ScheduleConstraintBuilder::cacheKey($club->getId(), $season->getId()));

        $payload = $this->builder->buildForClubSeason($club->getId(), $season->getId());

        // Reproduit le flush que fait GenerateScheduleHandler après le build : si le
        // build avait laissé des remove/persist en attente, ils partiraient ICI.
        $this->em->flush();

        self::assertSame($before, $this->assignmentIdsOf($team->getId(), $season->getId()), 'construire un payload ne doit RIEN écrire');

        $tags = [];
        foreach ($payload['teams'] ?? [] as $row) {
            if (($row['id'] ?? null) === $team->getId()) {
                $tags = $row['tags'] ?? [];
            }
        }
        self::assertSame(['U13'], $tags, 'le payload LIT les tags en base au lieu de les réécrire');
    }

    /**
     * P2-9ter NR-1ter — l'ordre des `tags` du payload est déterministe.
     *
     * `snapshotHash` (gelé dans la version) et `currentStructureHash` (recalculé à chaque
     * `/api/me`) sont deux sha256 du payload sérialisé : une simple PERMUTATION des tags
     * les fait diverger, et le cockpit annonce « structure modifiée » sur un planning
     * pourtant intact. ⚠ Trier la requête sur l'`id` de l'assignation ne suffirait PAS —
     * c'est un UUID v4 tiré à la construction, et `TeamTagSyncListener` recrée les lignes
     * à chaque écriture sur l'équipe. Seul le tri sur le NOM est stable.
     */
    public function testPayloadTagOrderIsDeterministicAcrossReinsertion(): void
    {
        [$club, $season] = $this->seed();
        $team = $this->team($club, $season, 'SM1');
        $this->em->flush();

        // Trois tags insérés dans un ordre volontairement anti-alphabétique.
        foreach (['ZULU', 'ALPHA', 'MIKE'] as $name) {
            $tag = (new TeamTag)->setClubId($club->getId())->setName($name)->setIsSystem(true);
            $this->em->persist($tag);
            $this->em->flush();
            $this->em->persist((new TeamTagAssignment)->setClubId($team->getClubId())->setTagId($tag->getId())->setTeamId($team->getId())->setSeasonId($season->getId()));
        }
        $this->em->flush();

        self::getContainer()->get('cache.schedule')->deleteItem(ScheduleConstraintBuilder::cacheKey($club->getId(), $season->getId()));
        $tags = $this->payloadTagsOf($this->builder->buildForClubSeason($club->getId(), $season->getId()), $team->getId());

        self::assertSame(['ALPHA', 'MIKE', 'ZULU'], $tags, 'les tags du payload sortent triés par NOM, quel que soit l’ordre des lignes');
    }

    /**
     * P2-9ter NR-1bis — le write-path écrit VRAIMENT les tags.
     *
     * ⚠ Ce test est le pendant obligatoire de NR-1. Rendre le build en lecture seule
     * fait de `TeamTagSyncListener` le SEUL writer des `TeamTagAssignment` : si ce
     * listener n'écrit pas, plus aucune équipe n'a de tags et toute contrainte CLUB
     * ciblant un tag cesse silencieusement de viser qui que ce soit (axe constraint
     * semantics). Le défaut existait réellement — `syncTeamTags` ne flushe pas ses
     * `persist`, et en `postFlush` le flush appelant est déjà terminé : créer une équipe
     * produisait 21 `team_tag` et 0 `team_tag_assignment`. Il était masqué parce que le
     * builder rappelait `syncTeamTags` à chaque génération.
     */
    public function testCreatingATeamActuallyWritesItsTagAssignments(): void
    {
        [$club, $season] = $this->seed();
        $team = $this->team($club, $season, 'U13F');
        $team->setGender(Gender::F);
        $this->em->flush();

        self::assertNotSame(
            [],
            $this->assignmentIdsOf($team->getId(), $season->getId()),
            'créer une équipe doit lui poser ses tags système — sinon le build en lecture seule n’a plus rien à lire',
        );
    }

    /**
     * P2-9ter NR-2 — le chemin scalaire et `buildForOverlay` sont UNE SEULE source.
     *
     * C'est la garantie qui manquait : trois tentatives ont recopié à la main ce que le
     * builder calcule, et ont divergé. Si quelqu'un ajoute un filtre d'un seul côté (ou
     * en oublie un en déplaçant le corps), l'égalité stricte le dit.
     */
    public function testScalarPathMatchesBuildForOverlay(): void
    {
        [$club, $season] = $this->seed();
        $active = $this->team($club, $season, 'SM1');
        $paused = $this->team($club, $season, 'U11');
        $entry = $this->closurePeriod($club, $season);
        $this->teamOverride($club, $season, $entry, $paused, false, null);
        $this->permanentConstraint($club, $season);
        $this->venue($club, $season, self::VENUE_CLOSED, 'Gym désactivé');
        $this->venueMode($club, $season, $entry, self::VENUE_CLOSED, VenuePeriodMode::DISABLED);
        $this->venueSlot($club, $season, self::VENUE_CLOSED, null, 2);
        // Un gymnase ACTIF, pour y poser le verrou de version. ⚠ Le poser sur
        // VENUE_CLOSED (désactivé juste au-dessus) le ferait filtrer par
        // `disabledVenueIds` : les deux `slotTemplates` seraient vides et l'assertion du
        // court-circuit `scheduleId null` serait satisfaite POUR UNE AUTRE RAISON.
        $this->venue($club, $season, self::VENUE_OPEN, 'Gym actif');
        $this->venueSlot($club, $season, self::VENUE_OPEN, null, 2);
        $schedule = $this->overlaySchedule($club, $season, $entry);
        $this->slot($schedule, self::VENUE_OPEN);
        $this->em->flush();

        $viaSchedule = $this->builder->buildForOverlay($schedule, $entry);
        $viaScalars = $this->builder->buildForPeriodPlan(
            $schedule->getClubId(),
            $schedule->getSeasonId(),
            $this->planIdOf($entry),
            $entry,
            $schedule->getSolverSeed(),
            $schedule->getId(),
        );
        self::assertSame($viaSchedule, $viaScalars, 'un seul chemin de calcul : l’adaptateur ne doit rien ajouter');
        self::assertNotSame([], $viaScalars['teams'] ?? [], 'garde : le seed doit produire un payload non vide');
        self::assertContains($active->getId(), array_map(static fn (array $t): string => $t['id'], $viaScalars['teams']));

        // Avant génération : aucun `Schedule`, donc aucun ScheduleSlotTemplate — et TOUT
        // le reste du payload est identique. C'est ce qui rend le récap calculable.
        $preGeneration = $this->builder->buildForPeriodPlan(
            $schedule->getClubId(),
            $schedule->getSeasonId(),
            $this->planIdOf($entry),
            $entry,
            $schedule->getSolverSeed(),
        );
        // Garde réelle : le verrou DOIT survivre au filtre gymnases quand on passe un
        // scheduleId. C'est CETTE assertion qui a du mordant — la poser sur un gymnase
        // désactivé (première version de ce test) donnait deux tableaux vides et une
        // comparaison satisfaite pour une tout autre raison.
        self::assertCount(1, $viaScalars['slotTemplates'] ?? [], 'le verrou de version doit survivre au filtre gymnases');
        // Celle-ci DOCUMENTE le mode pré-génération sans pouvoir tomber : `scheduleId`
        // étant NOT NULL sur l'entité, la requête rendrait `[]` même sans court-circuit.
        self::assertSame([], $preGeneration['slotTemplates'] ?? null, 'pas de version ⇒ pas de verrou de version');

        unset($viaScalars['slotTemplates'], $preGeneration['slotTemplates']);
        self::assertSame($viaScalars, $preGeneration, 'seuls les slotTemplates distinguent le mode pré-génération');
    }

    /**
     * P2-9ter NR-3 — le cache du payload est scopé PAR SAISON.
     *
     * La clé ne portait que le `clubId` alors que `buildForClubSeason` reçoit un
     * `seasonId` : un club à deux saisons se voyait resservir le payload de l'autre.
     */
    public function testCacheIsScopedPerSeason(): void
    {
        [$club, $seasonA] = $this->seed();
        $this->team($club, $seasonA, 'SM1');
        $this->em->flush();

        $seasonB = new Season;
        $seasonB->setClubId($club->getId());
        $seasonB->setName('2026-2027');
        $seasonB->setStartDate(new DateTimeImmutable('2026-09-01'));
        $seasonB->setEndDate(new DateTimeImmutable('2027-06-30'));
        $seasonB->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($seasonB);
        $this->em->flush();
        $this->team($club, $seasonB, 'U11');
        $this->team($club, $seasonB, 'U13');
        $this->em->flush();

        $pool = self::getContainer()->get('cache.schedule');
        $pool->deleteItem(ScheduleConstraintBuilder::cacheKey($club->getId(), $seasonA->getId()));
        $pool->deleteItem(ScheduleConstraintBuilder::cacheKey($club->getId(), $seasonB->getId()));

        $payloadA = $this->builder->buildForClubSeason($club->getId(), $seasonA->getId());
        $payloadB = $this->builder->buildForClubSeason($club->getId(), $seasonB->getId());

        self::assertSame($seasonA->getId(), $payloadA['seasonId'] ?? null);
        self::assertSame($seasonB->getId(), $payloadB['seasonId'] ?? null, 'la 2e saison ne doit pas resservir le payload de la 1re');
        self::assertCount(1, $payloadA['teams'] ?? [], 'saison A : une équipe');
        self::assertCount(2, $payloadB['teams'] ?? [], 'saison B : deux équipes');

        // Le BUILDER doit taguer ce qu'il écrit — sinon `CacheInvalidationListener`, qui
        // ne purge plus que par tag, ne pourrait plus rien invalider et tous les tests
        // resteraient verts. NR-4 pose les tags à la main : lui ne garde que le listener.
        self::assertTrue($pool->hasItem(ScheduleConstraintBuilder::cacheKey($club->getId(), $seasonA->getId())), 'garde : le build doit avoir peuplé le cache');
        $pool->invalidateTags([ScheduleConstraintBuilder::cacheTag($club->getId())]);
        self::assertFalse(
            $pool->hasItem(ScheduleConstraintBuilder::cacheKey($club->getId(), $seasonA->getId())),
            'les entrées écrites par le builder doivent porter le tag du club',
        );
        self::assertFalse(
            $pool->hasItem(ScheduleConstraintBuilder::cacheKey($club->getId(), $seasonB->getId())),
            'toutes les saisons du club partent avec le tag',
        );
    }

    public function testRepriseDropsTagExpandedRowForPausedTeam(): void
    {
        [$club, $season] = $this->seed();
        $active = $this->team($club, $season, 'SM1');
        $paused = $this->team($club, $season, 'U11');
        $entry = $this->holidayPeriod($club, $season);
        $this->teamOverride($club, $season, $entry, $paused, false, null); // en pause

        // A club-wide constraint targeting a tag BOTH teams carry → expands per-team.
        $tag = (new TeamTag)->setClubId($club->getId())->setName('loisir')->setIsSystem(false);
        $this->em->persist($tag);
        $this->em->flush();
        foreach ([$active, $paused] as $t) {
            $this->em->persist((new TeamTagAssignment)->setClubId($t->getClubId())->setTagId($tag->getId())->setTeamId($t->getId())->setSeasonId($season->getId()));
        }
        $c = new Constraint;
        $c->setClubId($club->getId());
        $c->setSeasonId($season->getId());
        $c->setName('Tag rule');
        $c->setScope(ConstraintScope::CLUB);
        $c->setFamily(ConstraintFamily::TIME);
        $c->setRuleType(ConstraintRuleType::HARD);
        $c->setConfig(['targetTag' => 'loisir']);
        $this->em->persist($c);
        $schedule = $this->overlaySchedule($club, $season, $entry);
        $this->em->flush();

        $rows = $this->builder->buildForOverlay($schedule, $entry)['constraints'];
        $targets = array_map(static fn (array $r): string => $r['scopeTargetId'] ?? '', $rows);
        self::assertContains($active->getId(), $targets, 'the tag rule ships for the active team');
        self::assertNotContains($paused->getId(), $targets, 'the tag-expanded row for a paused team is dropped (no ghost teamId)');
    }

    public function testClosureKeepsFacilityPermanentByDefault(): void
    {
        [$club, $season] = $this->seed();
        $this->team($club, $season, 'SM1');
        $entry = $this->closurePeriod($club, $season);
        $this->permanentScoped($club, $season, ConstraintScope::FACILITY, 'ffffffff-ffff-4fff-8fff-ffffffffffff', 'Facility perm');
        $schedule = $this->overlaySchedule($club, $season, $entry);
        $this->em->flush();

        // Fermeture unchanged: the smart default is reprise-only; closure keeps all permanents.
        $names = array_map(static fn (array $c): string => $c['name'] ?? '', $this->builder->buildForOverlay($schedule, $entry)['constraints']);
        self::assertContains('Facility perm', $names, 'a closure keeps all permanent constraints by default (B3+F2 unchanged)');
    }

    /**
     * P2-59 (axes constraint semantics + planning lifecycle) — LE TEST DU LOT : le modèle
     * FAIT/GENÈSE au payload.
     *
     * Une GENÈSE pendue à l'entrée-ENFANT A (calendar_entry_id = A) part dans le payload du plan
     * de A et SEULEMENT lui — la semaine sœur B ne la voit jamais (plans indépendants). Un FAIT
     * de la MÈRE (calendar_entry_id = la mère) est hérité par les DEUX plans-enfants. Une entrée
     * RACINE reste inchangée : elle ne lit que ses propres datées (byte-identique à l'ancien
     * modèle).
     *
     * Falsifiable : sous l'ancien modèle (une datée d'enfant vit sur la mère), la genèse de A
     * serait attachée à la mère et fuiterait donc vers B — l'assertion « B ne voit pas la genèse
     * de A » tombe ROUGE.
     */
    public function testGenesisShipsToItsOwnPlanOnlyWhileAMotherFactIsInheritedByEverySister(): void
    {
        [$club, $season] = $this->seed();
        $team = $this->team($club, $season, 'SM1');

        // Mère (racine holiday) — porte un FAIT ; deux semaines sœurs A et B nées d'elle.
        $mother = $this->holidayPeriod($club, $season); // 2026-05-04 → 2026-05-10
        $this->em->flush();
        $entryA = $this->childHolidayWeek($club, $season, $mother, '2026-05-04', '2026-05-10');
        $entryB = $this->childHolidayWeek($club, $season, $mother, '2026-05-11', '2026-05-17');
        $this->em->flush();

        $this->datedClubTimeConstraint($club, $season, $entryA, 'Genèse semaine A');
        $this->datedClubTimeConstraint($club, $season, $mother, 'Fait de la mère');
        $this->em->flush();

        $namesA = $this->payloadConstraintNames($this->builder->buildForPeriodPlan($club->getId(), $season->getId(), $this->planIdOf($entryA), $entryA));
        $namesB = $this->payloadConstraintNames($this->builder->buildForPeriodPlan($club->getId(), $season->getId(), $this->planIdOf($entryB), $entryB));
        $namesMother = $this->payloadConstraintNames($this->builder->buildForPeriodPlan($club->getId(), $season->getId(), $this->planIdOf($mother), $mother));

        // Plan de A : sa genèse + le fait de la mère.
        self::assertContains('Genèse semaine A', $namesA, 'la genèse pendue à A part dans le payload de A');
        self::assertContains('Fait de la mère', $namesA, 'le fait de la mère est hérité par le plan de A');

        // Plan de B (sœur) : le fait de la mère, JAMAIS la genèse de A.
        self::assertContains('Fait de la mère', $namesB, 'le fait de la mère est hérité par le plan de B');
        self::assertNotContains('Genèse semaine A', $namesB, 'une sœur ne voit jamais la genèse d’une autre semaine');

        // Racine (la mère) : inchangée — elle ne lit que SES propres datées (byte-identique).
        self::assertContains('Fait de la mère', $namesMother, 'une racine lit ses propres datées');
        self::assertNotContains('Genèse semaine A', $namesMother, 'une racine ne lit pas les genèses de ses enfants');
        self::assertNotSame('', $team->getId(), 'garde : l’équipe existe (le club/saison est peuplé)');
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->builder = self::getContainer()->get(ScheduleConstraintBuilder::class);
    }

    /** Une semaine-enfant (holiday) née d'une mère — parentEntryId posé. */
    private function childHolidayWeek(Club $club, Season $season, CalendarEntry $mother, string $start, string $end): CalendarEntry
    {
        $entry = new CalendarEntry;
        $entry->setClubId($club->getId());
        $entry->setSeasonId($season->getId());
        $entry->setKind(CalendarEntryKind::PERIOD);
        $entry->setPeriodType(CalendarEntryPeriodType::HOLIDAY);
        $entry->setTitle('Semaine ' . $start);
        $entry->setStartDate(new DateTimeImmutable($start));
        $entry->setEndDate(new DateTimeImmutable($end));
        $entry->setParentEntryId($mother->getId());
        $this->em->persist($entry);

        return $entry;
    }

    /** Une contrainte DATÉE (CLUB/TIME/HARD) pendue à une entrée — genèse (enfant) ou fait (mère). */
    private function datedClubTimeConstraint(Club $club, Season $season, CalendarEntry $entry, string $name): void
    {
        $c = new Constraint;
        $c->setClubId($club->getId());
        $c->setSeasonId($season->getId());
        $c->setCalendarEntryId($entry->getId());
        $c->setName($name);
        $c->setScope(ConstraintScope::CLUB);
        $c->setFamily(ConstraintFamily::TIME);
        $c->setRuleType(ConstraintRuleType::HARD);
        $c->setConfig(['minStartTime' => '20:00']);
        $this->em->persist($c);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<string>
     */
    private function payloadConstraintNames(array $payload): array
    {
        /** @var list<array<string, mixed>> $constraints */
        $constraints = $payload['constraints'] ?? [];

        return array_map(static fn (array $c): string => (string) ($c['name'] ?? ''), $constraints);
    }

    /**
     * @param list<array{id: string, trainingSlots: list<mixed>}> $venues
     *
     * @return array<string, int> venueId → number of training slots in the payload
     */
    private function venuesBySlotCount(array $venues): array
    {
        $counts = [];
        foreach ($venues as $venue) {
            $counts[$venue['id']] = \count($venue['trainingSlots']);
        }

        return $counts;
    }

    private function holidayPeriod(Club $club, Season $season): CalendarEntry
    {
        $entry = new CalendarEntry;
        $entry->setClubId($club->getId());
        $entry->setSeasonId($season->getId());
        $entry->setKind(CalendarEntryKind::PERIOD);
        $entry->setPeriodType(CalendarEntryPeriodType::HOLIDAY);
        $entry->setTitle('Reprise');
        $entry->setStartDate(new DateTimeImmutable('2026-05-04'));
        $entry->setEndDate(new DateTimeImmutable('2026-05-10'));
        $this->em->persist($entry);

        return $entry;
    }

    private function teamOverride(Club $club, Season $season, CalendarEntry $entry, Team $team, bool $isActive, ?int $sessions): void
    {
        $o = new TeamPeriodOverride;
        $o->setClubId($club->getId());
        $o->setSeasonId($season->getId());
        $o->setSchedulePlanId($this->planIdOf($entry));
        $o->setTeamId($team->getId());
        $o->setIsActive($isActive);
        $o->setSessionsPerWeek($sessions);
        $this->em->persist($o);
    }

    private function venue(Club $club, Season $season, string $id, string $name): Venue
    {
        $venue = new Venue;
        $venue->setId($id);
        $venue->setClubId($club->getId());
        $venue->setSeasonId($season->getId());
        $venue->setName($name);
        $venue->setCanSplit(false);
        $venue->setSource('manual');
        $this->em->persist($venue);

        return $venue;
    }

    /** $schedulePlanId : null = créneau saisonnier (base) ; set = prêté à ce plan (lot C3). */
    /** #8 — mode d'un gymnase POUR la période (sparse : pas de ligne = hériter). */
    private function venueMode(Club $club, Season $season, CalendarEntry $entry, string $venueId, VenuePeriodMode $mode): void
    {
        $o = new VenuePeriodOverride;
        $o->setClubId($club->getId());
        $o->setSeasonId($season->getId());
        $o->setSchedulePlanId($this->planIdOf($entry));
        $o->setVenueId($venueId);
        $o->setMode($mode);
        $this->em->persist($o);
    }

    /**
     * #8 + indispo informative — un réglage de période avec mode ET/OU masque jour tri-état.
     *
     * @param array<int, string>|null $dayOverrides
     */
    private function venueOverride(Club $club, Season $season, CalendarEntry $entry, string $venueId, ?VenuePeriodMode $mode, ?array $dayOverrides): VenuePeriodOverride
    {
        $o = new VenuePeriodOverride;
        $o->setClubId($club->getId());
        $o->setSeasonId($season->getId());
        $o->setSchedulePlanId($this->planIdOf($entry));
        $o->setVenueId($venueId);
        $o->setMode($mode);
        $o->setDayOverrides($dayOverrides);
        $this->em->persist($o);

        return $o;
    }

    private function venueSlot(Club $club, Season $season, string $venueId, ?string $schedulePlanId, int $dayOfWeek = 1): void
    {
        $slot = new VenueTrainingSlot;
        $slot->setClubId($club->getId());
        $slot->setSeasonId($season->getId());
        $slot->setVenueId($venueId);
        $slot->setDayOfWeek($dayOfWeek);
        $slot->setStartTime(new DateTimeImmutable('18:00'));
        $slot->setDurationMinutes(90);
        $slot->setCapacity(1);
        $slot->setSchedulePlanId($schedulePlanId);
        $this->em->persist($slot);
    }

    /** Créneaux (jours ISO) du gymnase VENUE_CLOSED effectivement présents dans le payload overlay. */
    private function closedVenueWeekdays(Schedule $schedule, CalendarEntry $entry): array
    {
        $payload = $this->builder->buildForOverlay($schedule, $entry);
        foreach ($payload['venues'] as $venue) {
            if (self::VENUE_CLOSED === $venue['id']) {
                return array_map(static fn (array $s): int => $s['dayOfWeek'], $venue['trainingSlots']);
            }
        }

        return [];
    }

    private function datedClosedVenueConstraint(Club $club, Season $season, CalendarEntry $entry): Constraint
    {
        $constraint = new Constraint;
        $constraint->setClubId($club->getId());
        $constraint->setSeasonId($season->getId());
        $constraint->setCalendarEntryId($entry->getId());
        $constraint->setScope(ConstraintScope::FACILITY);
        $constraint->setScopeTargetId(self::VENUE_CLOSED);
        $constraint->setFamily(ConstraintFamily::FACILITY);
        $constraint->setRuleType(ConstraintRuleType::HARD);
        $constraint->setName('Salle fermée');
        $constraint->setConfig(['type' => 'venue_closed']);
        $this->em->persist($constraint);

        return $constraint;
    }

    /** Closure SANS contrainte datée auto — pour piloter la fenêtre datée à la main (5b). */
    private function bareClosurePeriod(Club $club, Season $season, string $start, string $end): CalendarEntry
    {
        $entry = new CalendarEntry;
        $entry->setClubId($club->getId());
        $entry->setSeasonId($season->getId());
        $entry->setKind(CalendarEntryKind::PERIOD);
        $entry->setPeriodType(CalendarEntryPeriodType::CLOSURE);
        $entry->setTitle('Gym fermé (fenêtre pilotée)');
        $entry->setStartDate(new DateTimeImmutable($start));
        $entry->setEndDate(new DateTimeImmutable($end));
        $this->em->persist($entry);

        return $entry;
    }

    /** venue_closed AVEC dates (l'incident réel) — pour la granularité jour 5b. */
    private function datedClosedVenueConstraintDated(Club $club, Season $season, CalendarEntry $entry, string $start, string $end): void
    {
        $constraint = new Constraint;
        $constraint->setClubId($club->getId());
        $constraint->setSeasonId($season->getId());
        $constraint->setCalendarEntryId($entry->getId());
        $constraint->setScope(ConstraintScope::FACILITY);
        $constraint->setScopeTargetId(self::VENUE_CLOSED);
        $constraint->setFamily(ConstraintFamily::FACILITY);
        $constraint->setRuleType(ConstraintRuleType::HARD);
        $constraint->setName('Salle fermée');
        $constraint->setConfig(['type' => 'venue_closed', 'startDate' => $start, 'endDate' => $end]);
        $this->em->persist($constraint);
    }

    private function slot(Schedule $schedule, string $venueId): void
    {
        $slot = new ScheduleSlotTemplate;
        $slot->setClubId($schedule->getClubId());
        $slot->setSeasonId($schedule->getSeasonId());
        $slot->setScheduleId($schedule->getId());
        $slot->setTeamId('99999999-9999-4999-8999-999999999999');
        $slot->setVenueId($venueId);
        $slot->setDayOfWeek(1);
        $slot->setStartTime(new DateTimeImmutable('18:00'));
        $slot->setDurationMinutes(90);
        $this->em->persist($slot);
    }

    /**
     * Les id des assignations de tags d'une équipe, en SQL brut et triés.
     *
     * Lecture hors ORM délibérée : l'UnitOfWork rendrait des entités déjà chargées, donc
     * un test vert alors que la base a bougé. `team_tag_assignment` ne porte pas de
     * policy RLS, la lecture est directe.
     *
     * @return list<string>
     */
    private function assignmentIdsOf(string $teamId, string $seasonId): array
    {
        /** @var list<string> $ids */
        $ids = $this->em->getConnection()->executeQuery(
            'SELECT id FROM team_tag_assignment WHERE team_id = :team AND season_id = :season ORDER BY id',
            ['team' => $teamId, 'season' => $seasonId],
        )->fetchFirstColumn();

        return $ids;
    }

    /**
     * Les `tags` d'une équipe dans un payload construit.
     *
     * @param array<string, mixed> $payload
     *
     * @return list<string>
     */
    private function payloadTagsOf(array $payload, string $teamId): array
    {
        foreach ($payload['teams'] ?? [] as $row) {
            if (($row['id'] ?? null) === $teamId) {
                /** @var list<string> $tags */
                $tags = $row['tags'] ?? [];

                return $tags;
            }
        }

        return [];
    }

    private function team(Club $club, Season $season, string $name): Team
    {
        $team = new Team;
        $team->setClubId($club->getId());
        $team->setSeasonId($season->getId());
        $team->setName($name);
        $team->setSportCategoryId('cccccccc-cccc-4ccc-8ccc-cccccccccccc');
        $team->setPriorityTierId(1);
        $this->em->persist($team);

        return $team;
    }

    private function permanentConstraint(Club $club, Season $season): Constraint
    {
        $c = new Constraint;
        $c->setClubId($club->getId());
        $c->setSeasonId($season->getId());
        $c->setName('Contrainte permanente');
        $c->setScope(ConstraintScope::CLUB);
        $c->setFamily(ConstraintFamily::TIME);
        $c->setRuleType(ConstraintRuleType::HARD);
        $this->em->persist($c);

        return $c;
    }

    private function constraintOverride(Club $club, Season $season, CalendarEntry $entry, Constraint $constraint, bool $isActive): void
    {
        $o = new ConstraintPeriodOverride;
        $o->setClubId($club->getId());
        $o->setSeasonId($season->getId());
        $o->setSchedulePlanId($this->planIdOf($entry));
        $o->setConstraintId($constraint->getId());
        $o->setIsActive($isActive);
        $this->em->persist($o);
    }

    private function permanentScoped(Club $club, Season $season, ConstraintScope $scope, ?string $targetId, string $name): Constraint
    {
        $c = new Constraint;
        $c->setClubId($club->getId());
        $c->setSeasonId($season->getId());
        $c->setName($name);
        $c->setScope($scope);
        $c->setScopeTargetId($targetId);
        $c->setFamily(ConstraintScope::FACILITY === $scope ? ConstraintFamily::FACILITY : ConstraintFamily::TIME);
        $c->setRuleType(ConstraintRuleType::HARD);
        $c->setConfig([]);
        $this->em->persist($c);

        return $c;
    }

    private function closurePeriod(Club $club, Season $season): CalendarEntry
    {
        $entry = new CalendarEntry;
        $entry->setClubId($club->getId());
        $entry->setSeasonId($season->getId());
        $entry->setKind(CalendarEntryKind::PERIOD);
        $entry->setPeriodType(CalendarEntryPeriodType::CLOSURE);
        $entry->setTitle('Gym fermé');
        $entry->setStartDate(new DateTimeImmutable('2026-05-04'));
        $entry->setEndDate(new DateTimeImmutable('2026-05-10'));
        $this->em->persist($entry);

        $dated = new Constraint;
        $dated->setClubId($club->getId());
        $dated->setSeasonId($season->getId());
        $dated->setName('Salle fermée');
        $dated->setScope(ConstraintScope::FACILITY);
        $dated->setScopeTargetId(self::VENUE_CLOSED);
        $dated->setFamily(ConstraintFamily::FACILITY);
        $dated->setRuleType(ConstraintRuleType::HARD);
        $dated->setCalendarEntryId($entry->getId());
        $this->em->persist($dated);

        return $entry;
    }

    private function overlaySchedule(Club $club, Season $season, ?CalendarEntry $entry, ?string $id = null): Schedule
    {
        $schedule = new Schedule;
        if (null !== $id) {
            $schedule->setId($id);
        }
        $schedule->setClubId($club->getId());
        $schedule->setSeasonId($season->getId());
        $schedule->setName('Overlay');
        $schedule->setStatus(ScheduleStatus::DRAFT);
        // ⚠ Graine NON DÉFAUT, délibérément. `Schedule::$solverSeed` vaut 42, exactement
        // la valeur par défaut de `buildForPeriodPlan` : avec elle, un adaptateur qui
        // OUBLIERAIT de passer la graine produirait le même payload et le test de parité
        // resterait vert. 4242 rend cet oubli visible.
        $schedule->setSolverSeed(4242);
        // Toute version est liée à son plan en prod (linkSchedule, au POST) : un overlay au
        // plan de sa période, une version de BASE au plan SEASON (le socle). buildForOverlay
        // l'exige (C2) et findBaseSlotTemplates ne prend QUE les versions du plan SEASON (C4).
        $schedule->setSchedulePlanId($entry instanceof CalendarEntry ? $this->planIdOf($entry) : $this->seasonPlanIdOf($season));
        $this->em->persist($schedule);

        return $schedule;
    }

    /**
     * @return array{0: Club, 1: Season}
     */
    private function seed(): array
    {
        $uid = uniqid('', true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $club = new Club;
        $club->setName('OVB Club');
        $club->setSlug('ovb-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode('OVB' . strtoupper(substr(md5($uid), 0, 8)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('ovb-' . $uid . '@test.com');
        $user->setFirstName('O');
        $user->setLastName('B');
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
