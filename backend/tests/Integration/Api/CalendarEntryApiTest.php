<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Constraint;
use App\Entity\ConstraintPeriodOverride;
use App\Entity\Schedule;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\SchoolHolidayPeriod;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\ScheduleStatus;
use App\Enum\SeasonStatus;
use App\Service\SchedulePlanProvisioner;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * CalendarEntry CRUD, validation shape, window filtering, and tenant isolation
 * (NR — a club must never see another club's calendar entries).
 */
#[Group('phase1')]
#[Group('integration')]
final class CalendarEntryApiTest extends WebTestCase
{
    use ChoosesPlanVersionTrait;
    use TenantGucTrait;

    private EntityManagerInterface $em;

    private KernelBrowser $client;

    private UserPasswordHasherInterface $hasher;

    private JWTTokenManagerInterface $jwt;

    public function testCreateEvent(): void
    {
        [$user, $club] = $this->seed('CE1');

        $this->post($user, $club, [
            'kind' => 'event',
            'title' => 'AG du club',
            'startDate' => '2026-05-12',
            'endDate' => '2026-05-12',
            'isDisruptive' => false,
        ]);

        self::assertResponseStatusCodeSame(201);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('event', $data['kind']);
        self::assertSame('2026-05-12', $data['startDate']);
        self::assertSame('active', $data['status']);
    }

    public function testCreatePeriodClosure(): void
    {
        [$user, $club] = $this->seed('CE2');

        $this->post($user, $club, [
            'kind' => 'period',
            'title' => 'Gym Barros fermé',
            'startDate' => '2026-05-04',
            'endDate' => '2026-05-10',
            'periodType' => 'closure',
        ]);

        self::assertResponseStatusCodeSame(201);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('closure', $data['periodType']);
    }

    public function testEndDateBeforeStartDateIsRejected(): void
    {
        [$user, $club] = $this->seed('CE3');

        $this->post($user, $club, [
            'kind' => 'event',
            'title' => 'Bad',
            'startDate' => '2026-05-12',
            'endDate' => '2026-05-01',
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testEventWithPeriodTypeIsRejected(): void
    {
        [$user, $club] = $this->seed('CE4');

        $this->post($user, $club, [
            'kind' => 'event',
            'title' => 'Bad',
            'startDate' => '2026-05-12',
            'endDate' => '2026-05-12',
            'periodType' => 'closure',
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testPeriodWithoutPeriodTypeIsRejected(): void
    {
        [$user, $club] = $this->seed('CE5');

        $this->post($user, $club, [
            'kind' => 'period',
            'title' => 'Bad',
            'startDate' => '2026-05-04',
            'endDate' => '2026-05-10',
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testWindowFilterReturnsOverlappingEntries(): void
    {
        [$user, $club] = $this->seed('CE6');

        // Entry straddling the May/June boundary → overlaps a May window.
        $this->post($user, $club, ['kind' => 'period', 'title' => 'Straddle', 'startDate' => '2026-05-28', 'endDate' => '2026-06-03', 'periodType' => 'closure']);
        self::assertResponseStatusCodeSame(201);
        // Entry fully in July → outside a May window.
        $this->post($user, $club, ['kind' => 'event', 'title' => 'July', 'startDate' => '2026-07-15', 'endDate' => '2026-07-15']);
        self::assertResponseStatusCodeSame(201);

        $this->client->request('GET', '/api/calendar_entries?from=2026-05-01&to=2026-05-31', [], [], $this->authHeaders($user, $club));
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $titles = array_map(static fn (array $e): string => $e['title'], $data['member']);
        self::assertContains('Straddle', $titles);
        self::assertNotContains('July', $titles);
    }

    public function testKindFilter(): void
    {
        [$user, $club] = $this->seed('CE7');

        $this->post($user, $club, ['kind' => 'event', 'title' => 'Evt', 'startDate' => '2026-05-12', 'endDate' => '2026-05-12']);
        $this->post($user, $club, ['kind' => 'period', 'title' => 'Per', 'startDate' => '2026-05-04', 'endDate' => '2026-05-10', 'periodType' => 'closure']);

        $this->client->request('GET', '/api/calendar_entries?kind=period', [], [], $this->authHeaders($user, $club));
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $kinds = array_map(static fn (array $e): string => $e['kind'], $data['member']);
        self::assertNotContains('event', $kinds);
        self::assertContains('period', $kinds);
    }

    public function testPeriodAcceptsFalseIsDisruptive(): void
    {
        [$user, $club] = $this->seed('CE10');

        $this->post($user, $club, [
            'kind' => 'period',
            'title' => 'Fermeture',
            'startDate' => '2026-05-04',
            'endDate' => '2026-05-10',
            'periodType' => 'closure',
            'isDisruptive' => false,
        ]);

        self::assertResponseStatusCodeSame(201);
    }

    public function testMalformedDateWindowReturns400(): void
    {
        [$user, $club] = $this->seed('CE11');

        $this->client->request('GET', '/api/calendar_entries?from=notadate', [], [], $this->authHeaders($user, $club));

        self::assertResponseStatusCodeSame(400);
    }

    public function testConvertingPeriodToEventClearsPeriodType(): void
    {
        [$user, $club] = $this->seed('CE12');

        // Un `cutoff` : il ne porte PAS de plan (inv. 9), son identité reste donc libre.
        // Depuis le lot C, une closure/holiday a toujours un plan et voit son identité
        // GELÉE (422) — la convertir en event orphelinerait ce plan ; on la supprime et on
        // la recrée, ce que l'UI impose déjà (elle n'expose aucun PUT). Le type de période
        // était accessoire ici : le sujet du test est le NETTOYAGE du periodType.
        $this->post($user, $club, ['kind' => 'period', 'title' => 'Per', 'startDate' => '2026-05-04', 'endDate' => '2026-05-10', 'periodType' => 'cutoff']);
        self::assertResponseStatusCodeSame(201);
        $id = json_decode((string) $this->client->getResponse()->getContent(), true)['id'];

        // Full PUT (resource replacement) that flips kind to event and omits periodType.
        $this->client->request('PUT', "/api/calendar_entries/{$id}", [], [], [
            ...$this->authHeaders($user, $club),
            'CONTENT_TYPE' => 'application/ld+json',
        ], json_encode(['kind' => 'event', 'title' => 'Per', 'startDate' => '2026-05-04', 'endDate' => '2026-05-10'], \JSON_THROW_ON_ERROR));

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('event', $data['kind']);
        // null periodType is omitted from the serialized payload → cleared.
        self::assertNull($data['periodType'] ?? null, 'converting a period to an event must clear periodType');
    }

    public function testDeletingPeriodCascadesDatedConstraints(): void
    {
        [$user, $club] = $this->seed('CE13');

        $this->post($user, $club, ['kind' => 'period', 'title' => 'Barros off', 'startDate' => '2026-05-04', 'endDate' => '2026-05-10', 'periodType' => 'closure']);
        $entryId = json_decode((string) $this->client->getResponse()->getContent(), true)['id'];

        // Attach a dated constraint to the period.
        $this->client->request('POST', '/api/constraints', [], [], [
            ...$this->authHeaders($user, $club),
            'CONTENT_TYPE' => 'application/ld+json',
        ], json_encode([
            'name' => 'Barros fermé',
            'scope' => 'FACILITY',
            'family' => 'FACILITY',
            'ruleType' => 'HARD',
            'calendarEntryId' => $entryId,
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);
        $constraintId = json_decode((string) $this->client->getResponse()->getContent(), true)['id'];

        // Delete the period.
        $this->client->request('DELETE', "/api/calendar_entries/{$entryId}", [], [], $this->authHeaders($user, $club));
        self::assertResponseStatusCodeSame(204);

        // The dated constraint is gone.
        $this->em->clear();
        self::assertNull($this->em->getRepository(Constraint::class)->find($constraintId));
    }

    public function testDeletingPeriodCascadesConstraintPeriodOverrides(): void
    {
        [$user, $club] = $this->seed('CE13b');

        $this->post($user, $club, ['kind' => 'period', 'title' => 'Fermeture', 'startDate' => '2026-05-04', 'endDate' => '2026-05-10', 'periodType' => 'closure']);
        $entryId = json_decode((string) $this->client->getResponse()->getContent(), true)['id'];

        // A permanent constraint (no calendarEntryId) the period disables.
        $this->client->request('POST', '/api/constraints', [], [], [
            ...$this->authHeaders($user, $club),
            'CONTENT_TYPE' => 'application/ld+json',
        ], json_encode(['name' => 'Perm', 'scope' => 'CLUB', 'family' => 'TIME', 'ruleType' => 'HARD'], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);
        $constraintId = json_decode((string) $this->client->getResponse()->getContent(), true)['id'];

        // Disable it for the period (sparse override). Ancré au PLAN depuis le lot C2
        // (inv. 5) — le plan naît du geste Adapter (ADR-0002 amendé 2026-07-24) :
        // on le crée explicitement avant d'y accrocher le réglage.
        $this->client->request('POST', '/api/schedule_plans', [], [], [
            ...$this->authHeaders($user, $club),
            'CONTENT_TYPE' => 'application/ld+json',
        ], json_encode(['calendarEntryId' => $entryId], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);
        $planId = self::getContainer()->get(SchedulePlanProvisioner::class)->periodPlanId($entryId);
        self::assertIsString($planId);
        $this->client->request('POST', '/api/constraint_period_overrides', [], [], [
            ...$this->authHeaders($user, $club),
            'CONTENT_TYPE' => 'application/ld+json',
        ], json_encode(['schedulePlanId' => $planId, 'constraintId' => $constraintId, 'isActive' => false], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);
        $overrideId = json_decode((string) $this->client->getResponse()->getContent(), true)['id'];

        // Delete the period → the override keyed on it must go too (else it orphans).
        $this->client->request('DELETE', "/api/calendar_entries/{$entryId}", [], [], $this->authHeaders($user, $club));
        self::assertResponseStatusCodeSame(204);

        $this->em->clear();
        self::assertNull($this->em->getRepository(ConstraintPeriodOverride::class)->find($overrideId), 'a period constraint override must not survive its period');
    }

    /**
     * P4-126 — le doublon d'override de période rend un 422 PARLANT : le motif voyage jusque
     * dans le corps que le front lit (un `new ValidationException('chaîne')` nu l'aurait laissé
     * vide). On réutilise les fixtures période de cette classe (entrée → contrainte → plan).
     */
    public function testDuplicateConstraintPeriodOverrideCarriesFrenchViolation(): void
    {
        [$user, $club] = $this->seed('CE13c');

        $this->post($user, $club, ['kind' => 'period', 'title' => 'Fermeture', 'startDate' => '2026-05-04', 'endDate' => '2026-05-10', 'periodType' => 'closure']);
        $entryId = json_decode((string) $this->client->getResponse()->getContent(), true)['id'];

        $this->client->request('POST', '/api/constraints', [], [], [
            ...$this->authHeaders($user, $club),
            'CONTENT_TYPE' => 'application/ld+json',
        ], json_encode(['name' => 'Perm', 'scope' => 'CLUB', 'family' => 'TIME', 'ruleType' => 'HARD'], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);
        $constraintId = json_decode((string) $this->client->getResponse()->getContent(), true)['id'];

        $this->client->request('POST', '/api/schedule_plans', [], [], [
            ...$this->authHeaders($user, $club),
            'CONTENT_TYPE' => 'application/ld+json',
        ], json_encode(['calendarEntryId' => $entryId], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);
        $planId = self::getContainer()->get(SchedulePlanProvisioner::class)->periodPlanId($entryId);
        self::assertIsString($planId);

        $payload = json_encode(['schedulePlanId' => $planId, 'constraintId' => $constraintId, 'isActive' => false], \JSON_THROW_ON_ERROR);
        $this->client->request('POST', '/api/constraint_period_overrides', [], [], [
            ...$this->authHeaders($user, $club),
            'CONTENT_TYPE' => 'application/ld+json',
        ], $payload);
        self::assertResponseStatusCodeSame(201);

        // Second réglage sur le même (période, contrainte) → 422 propre et PARLANT.
        $this->client->request('POST', '/api/constraint_period_overrides', [], [], [
            ...$this->authHeaders($user, $club),
            'CONTENT_TYPE' => 'application/ld+json',
        ], $payload);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Cette contrainte a déjà un réglage pour cette période — modifiez-le.', (string) $this->client->getResponse()->getContent());
    }

    public function testCollectionScopedToActiveSeason(): void
    {
        [$user, $club] = $this->seed('CE14');

        // Entry in the active season (via API).
        $this->post($user, $club, ['kind' => 'event', 'title' => 'Current', 'startDate' => '2026-05-12', 'endDate' => '2026-05-12']);
        self::assertResponseStatusCodeSame(201);

        // A past (archived) season with its own entry.
        $this->scopeGucToClub($club->getId());
        $old = new Season;
        $old->setClubId($club->getId());
        $old->setName('2024-2025');
        $old->setStartDate(new DateTimeImmutable('2024-09-01'));
        $old->setEndDate(new DateTimeImmutable('2025-06-30'));
        $old->setStatus(SeasonStatus::ARCHIVED);
        $this->em->persist($old);
        $oldEntry = new CalendarEntry;
        $oldEntry->setClubId($club->getId());
        $oldEntry->setSeasonId($old->getId());
        $oldEntry->setKind(CalendarEntryKind::EVENT);
        $oldEntry->setTitle('Stale');
        $oldEntry->setStartDate(new DateTimeImmutable('2025-05-12'));
        $oldEntry->setEndDate(new DateTimeImmutable('2025-05-12'));
        $this->em->persist($oldEntry);
        $this->em->flush();

        $this->client->request('GET', '/api/calendar_entries', [], [], $this->authHeaders($user, $club));
        self::assertResponseIsSuccessful();
        $titles = array_map(static fn (array $e): string => $e['title'], json_decode((string) $this->client->getResponse()->getContent(), true)['member']);
        self::assertContains('Current', $titles);
        self::assertNotContains('Stale', $titles, 'the collection must be scoped to the active season');
    }

    public function testDeletingPeriodCascadesOverlaySchedule(): void
    {
        [$user, $club, $season] = $this->seed('CE15');

        // A period entry with a generated overlay (schedule + one slot).
        $this->scopeGucToClub($club->getId());
        // L'entrée d'abord : le plan de la période naît d'elle (ADR-0002 lot C, rejoué ici
        // car l'entrée est fabriquée à la main plutôt que par le POST).
        $entry = new CalendarEntry;
        $entry->setClubId($club->getId());
        $entry->setSeasonId($season->getId());
        $entry->setKind(CalendarEntryKind::PERIOD);
        $entry->setPeriodType(CalendarEntryPeriodType::CLOSURE);
        $entry->setTitle('Gym fermé');
        $entry->setStartDate(new DateTimeImmutable('2026-05-04'));
        $entry->setEndDate(new DateTimeImmutable('2026-05-10'));
        $this->em->persist($entry);
        $this->em->flush();

        // Depuis C4 l'overlay pend au PLAN de la période (plus de calendarEntryId) : la
        // cascade de suppression le retrouve PAR son plan. lot D : le plan est posé AVANT
        // le flush (schedule_plan_id NOT NULL).
        $planId = self::getContainer()->get(SchedulePlanProvisioner::class)->provisionPeriodPlan($entry->getId());
        $overlay = new Schedule;
        $overlay->setClubId($club->getId());
        $overlay->setSeasonId($season->getId());
        $overlay->setSchedulePlanId($planId);
        $overlay->setName('Overlay');
        $overlay->setStatus(ScheduleStatus::COMPLETED);
        $this->em->persist($overlay);
        $this->em->flush();
        // lot D-b : la cascade retrouve l'overlay PAR son plan (schedulePlanId) — plus de
        // pointeur inverse à poser sur l'entrée.

        $slot = new ScheduleSlotTemplate;
        $slot->setClubId($club->getId());
        $slot->setSeasonId($season->getId());
        $slot->setScheduleId($overlay->getId());
        $slot->setTeamId('44444444-4444-4444-8444-444444444444');
        $slot->setVenueId('55555555-5555-4555-8555-555555555555');
        $slot->setDayOfWeek(1);
        $slot->setStartTime(new DateTimeImmutable('18:00'));
        $slot->setDurationMinutes(90);
        $this->em->persist($slot);
        $this->em->flush();

        $overlayId = $overlay->getId();
        $slotId = $slot->getId();

        $this->client->request('DELETE', "/api/calendar_entries/{$entry->getId()}", [], [], $this->authHeaders($user, $club));
        self::assertResponseStatusCodeSame(204);

        $this->em->clear();
        $this->scopeGucToClub($club->getId());
        self::assertNull($this->em->getRepository(Schedule::class)->find($overlayId), 'overlay schedule must be purged');
        self::assertNull($this->em->getRepository(ScheduleSlotTemplate::class)->find($slotId), 'overlay slots must be purged');
    }

    public function testDeletingPeriodWhoseOverlayIsChosenIsRefused(): void
    {
        [$user, $club, $season] = $this->seed('CE17');

        // The period entry first: its plan is keyed on the entry, so the overlay
        // must carry calendarEntryId to belong to it.
        $this->scopeGucToClub($club->getId());
        $entry = new CalendarEntry;
        $entry->setClubId($club->getId());
        $entry->setSeasonId($season->getId());
        $entry->setKind(CalendarEntryKind::PERIOD);
        $entry->setPeriodType(CalendarEntryPeriodType::CLOSURE);
        $entry->setTitle('Gym fermé');
        $entry->setStartDate(new DateTimeImmutable('2026-05-04'));
        $entry->setEndDate(new DateTimeImmutable('2026-05-10'));
        $this->em->persist($entry);
        $this->em->flush();
        // ADR-0002 lot C : le plan naît du geste. Rejoué ici — sans lui, l'overlay ne
        // se rattacherait à aucun plan et ne pourrait pas être pointé.
        $provisioner = self::getContainer()->get(SchedulePlanProvisioner::class);
        $planId = $provisioner->provisionPeriodPlan($entry->getId());

        $overlay = new Schedule;
        $overlay->setClubId($club->getId());
        $overlay->setSeasonId($season->getId());
        $overlay->setSchedulePlanId($planId);
        $overlay->setName('Overlay en vigueur');
        $overlay->setStatus(ScheduleStatus::COMPLETED);
        $this->em->persist($overlay);
        // lot D : numéroter SOUS le plan de la période (linkSchedule lit le plan déjà posé,
        // sans flush préalable — il fait l'INSERT unique avec version ≥ 1) AVANT de pointer.
        // choosePlanVersion ne re-lie alors plus (version ≠ 0) : il pointe le plan de la
        // période sur cet overlay — c'est ce pointeur qui le met « en vigueur ».
        $provisioner->linkSchedule($overlay);
        $this->choosePlanVersion($overlay); // le plan de la période POINTE l'overlay → en vigueur (lot D-b)

        // The entry-delete cascade must not bypass the read-only guard: the period's
        // plan POINTS at this overlay, so it is in force.
        $this->client->request('DELETE', "/api/calendar_entries/{$entry->getId()}", [], [], $this->authHeaders($user, $club));
        self::assertResponseStatusCodeSame(409);

        $this->em->clear();
        $this->scopeGucToClub($club->getId());
        self::assertNotNull($this->em->getRepository(Schedule::class)->find($overlay->getId()), 'the overlay in force must survive');
        self::assertNotNull($this->em->getRepository(CalendarEntry::class)->find($entry->getId()), 'the entry must survive');
    }

    public function testPeriodWithOverlayCannotMutateIdentity(): void
    {
        [$user, $club, $season] = $this->seed('CE16');

        // Period entry carrying a generated overlay.
        $this->scopeGucToClub($club->getId());
        // L'entrée d'abord : le plan de la période naît d'elle (rejoué à la main).
        $entry = new CalendarEntry;
        $entry->setClubId($club->getId());
        $entry->setSeasonId($season->getId());
        $entry->setKind(CalendarEntryKind::PERIOD);
        $entry->setPeriodType(CalendarEntryPeriodType::CLOSURE);
        $entry->setTitle('Gym fermé');
        $entry->setStartDate(new DateTimeImmutable('2026-05-04'));
        $entry->setEndDate(new DateTimeImmutable('2026-05-10'));
        $this->em->persist($entry);
        $this->em->flush();
        $id = $entry->getId();

        // L'overlay pend au PLAN de la période. lot D : le plan est posé AVANT le flush
        // (schedule_plan_id NOT NULL).
        $planId = self::getContainer()->get(SchedulePlanProvisioner::class)->provisionPeriodPlan($id);
        $overlay = new Schedule;
        $overlay->setClubId($club->getId());
        $overlay->setSeasonId($season->getId());
        $overlay->setSchedulePlanId($planId);
        $overlay->setName('Overlay');
        $overlay->setStatus(ScheduleStatus::COMPLETED);
        $this->em->persist($overlay);
        $this->em->flush();
        // lot D-b : le gel d'identité s'arme sur « la période a un PLAN » (periodPlanExists),
        // plus sur un pointeur inverse — l'overlay pend au plan (schedulePlanId) qui suffit.

        $base = ['title' => 'Gym fermé', 'startDate' => '2026-05-04', 'endDate' => '2026-05-10'];

        // periodType change → 422 (the overlay was generated for closure semantics).
        $this->put($user, $club, $id, [...$base, 'kind' => 'period', 'periodType' => 'holiday']);
        self::assertResponseStatusCodeSame(422, 'changing periodType under an overlay must be rejected');

        // Window change → 422 (the overlay covers the old window).
        $this->put($user, $club, $id, [...$base, 'kind' => 'period', 'periodType' => 'closure', 'endDate' => '2026-05-17']);
        self::assertResponseStatusCodeSame(422, 'changing dates under an overlay must be rejected');

        // kind → event → 422 (would orphan the overlay).
        $this->put($user, $club, $id, [...$base, 'kind' => 'event']);
        self::assertResponseStatusCodeSame(422, 'converting to event under an overlay must be rejected');

        // Same identity, new title → allowed (cosmetic edits stay possible).
        $this->put($user, $club, $id, [...$base, 'kind' => 'period', 'periodType' => 'closure', 'title' => 'Gym Barros fermé']);
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('Gym Barros fermé', $data['title']);
    }

    public function testForeignEntryIsInvisible(): void
    {
        [$userA, $clubA] = $this->seed('CE8');
        [, $clubB] = $this->seed('CE9');

        // Create an entry in club B (scope the GUC to B first).
        $this->scopeGucToClub($clubB->getId());
        $entryB = new CalendarEntry;
        $entryB->setClubId($clubB->getId());
        $entryB->setSeasonId($this->activeSeasonId($clubB));
        $entryB->setKind(CalendarEntryKind::EVENT);
        $entryB->setTitle('Secret B');
        $entryB->setStartDate(new DateTimeImmutable('2026-05-12'));
        $entryB->setEndDate(new DateTimeImmutable('2026-05-12'));
        $this->em->persist($entryB);
        $this->em->flush();

        // Club A must not see it.
        $this->client->request('GET', '/api/calendar_entries', [], [], $this->authHeaders($userA, $clubA));
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        $titles = array_map(static fn (array $e): string => $e['title'], $data['member']);
        self::assertNotContains('Secret B', $titles);
    }

    /**
     * Défaut 4(b) — une entrée VACANCES racine créée SANS lien dont la fenêtre chevauche une
     * vacance scolaire de la ZONE du club reçoit le lien automatiquement (une seule vérité
     * serveur). Le radar dédoublonne alors le feed scolaire et cette entrée.
     */
    public function testHolidayEntryWithoutLinkAutoLinksToOverlappingSchoolHoliday(): void
    {
        [$user, $club] = $this->seed('CE20');
        $holidayId = $this->seedSchoolHoliday($club, 'A', 'hiver', '2026-02-07', '2026-02-22', '2025-2026');

        $this->post($user, $club, [
            'kind' => 'period',
            'title' => 'Vacances d\'hiver',
            'startDate' => '2026-02-09',
            'endDate' => '2026-02-20',
            'periodType' => 'holiday',
        ]);

        self::assertResponseStatusCodeSame(201);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame($holidayId, $data['schoolHolidayId'] ?? null, 'une vacance sans lien reçoit celui de la vacance scolaire chevauchante de sa zone');
    }

    public function testHolidayEntryWithoutOverlapStaysUnlinked(): void
    {
        [$user, $club] = $this->seed('CE21');
        $this->seedSchoolHoliday($club, 'A', 'hiver', '2026-02-07', '2026-02-22', '2025-2026');

        // Fenêtre de mai : ne chevauche AUCUNE vacance scolaire de la zone.
        $this->post($user, $club, [
            'kind' => 'period',
            'title' => 'Stage de mai',
            'startDate' => '2026-05-04',
            'endDate' => '2026-05-10',
            'periodType' => 'holiday',
        ]);

        self::assertResponseStatusCodeSame(201);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertNull($data['schoolHolidayId'] ?? null, 'sans vacance scolaire chevauchante, aucun lien n\'est posé');
    }

    public function testExplicitSchoolHolidayLinkIsRespected(): void
    {
        [$user, $club] = $this->seed('CE22');
        $overlapping = $this->seedSchoolHoliday($club, 'A', 'hiver', '2026-02-07', '2026-02-22', '2025-2026');
        $explicit = $this->seedSchoolHoliday($club, 'A', 'printemps', '2026-04-04', '2026-04-19', '2025-2026');

        // Fenêtre chevauchant « hiver », mais lien EXPLICITE vers « printemps » : le client fait foi.
        $this->post($user, $club, [
            'kind' => 'period',
            'title' => 'Vacances',
            'startDate' => '2026-02-09',
            'endDate' => '2026-02-20',
            'periodType' => 'holiday',
            'schoolHolidayId' => $explicit,
        ]);

        self::assertResponseStatusCodeSame(201);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame($explicit, $data['schoolHolidayId'] ?? null, 'un lien explicite n\'est jamais écrasé par l\'auto-résolution');
        self::assertNotSame($overlapping, $data['schoolHolidayId'] ?? null);
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->hasher = $container->get('security.user_password_hasher');
        $this->jwt = $container->get(JWTTokenManagerInterface::class);
    }

    /**
     * The api firewall is stateless JWT — every request carries a Bearer token
     * (loginUser only authenticates a single following request).
     *
     * @return array<string, string>
     */
    private function authHeaders(User $user, Club $club): array
    {
        return [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->jwt->create($user),
            'HTTP_X-Club-Id' => $club->getId(),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function put(User $user, Club $club, string $id, array $payload): void
    {
        $this->client->request('PUT', "/api/calendar_entries/{$id}", [], [], [
            ...$this->authHeaders($user, $club),
            'CONTENT_TYPE' => 'application/ld+json',
        ], json_encode($payload, \JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function post(User $user, Club $club, array $payload): void
    {
        $this->client->request('POST', '/api/calendar_entries', [], [], [
            ...$this->authHeaders($user, $club),
            'CONTENT_TYPE' => 'application/ld+json',
        ], json_encode($payload, \JSON_THROW_ON_ERROR));
    }

    /**
     * Pose la ZONE scolaire du club ET une ligne de référence `school_holiday_period` (globale,
     * sans RLS) pour cette zone — de quoi éprouver l'auto-résolution du lien à la création.
     */
    private function seedSchoolHoliday(Club $club, string $zone, string $type, string $start, string $end, string $year): string
    {
        $this->scopeGucToClub($club->getId());
        $managed = $this->em->getRepository(Club::class)->find($club->getId());
        self::assertInstanceOf(Club::class, $managed);
        $managed->setSchoolZone($zone);

        $holiday = new SchoolHolidayPeriod;
        $holiday->setZone($zone)->setLabel('Vacances')->setHolidayType($type)
            ->setStartDate(new DateTimeImmutable($start))->setEndDate(new DateTimeImmutable($end))->setSchoolYear($year);
        $this->em->persist($holiday);
        $this->em->flush();

        return $holiday->getId();
    }

    private function activeSeasonId(Club $club): string
    {
        $season = $this->em->getRepository(Season::class)->findOneBy(['clubId' => $club->getId(), 'status' => 'active']);
        self::assertNotNull($season);

        return $season->getId();
    }

    /**
     * @return array{0: User, 1: Club, 2: Season}
     */
    private function seed(string $tag): array
    {
        $uid = uniqid('', true);

        $club = new Club;
        $club->setName('Club ' . $tag);
        $club->setSlug('club-' . $tag . '-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode($tag . strtoupper(substr(md5($uid), 0, 8)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('user-' . $tag . '-' . $uid . '@test.com');
        $user->setFirstName('C');
        $user->setLastName('E');
        $user->setPasswordHash($this->hasher->hashPassword($user, 'pass'));
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

        return [$user, $club, $season];
    }
}
