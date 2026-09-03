<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Constraint;
use App\Entity\Reservation;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\Season;
use App\Entity\SharedTrainingBlock;
use App\Entity\SharedTrainingBlockTeam;
use App\Entity\Team;
use App\Entity\User;
use App\Entity\VenuePeriodOverride;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\ConstraintFamily;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use App\Enum\LockLevel;
use App\Enum\SeasonStatus;
use App\Tests\CreatesPeriodPlanTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('phase1')]
final class ReservationApiTest extends WebTestCase
{
    use CreatesPeriodPlanTrait;
    use TenantGucTrait;

    private const VENUE = '22222222-2222-4222-8222-222222222222';

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    private Club $club;

    private User $user;

    private Season $season;

    private string $token;

    public function testCreateListAndDeleteBaseReservation(): void
    {
        $id = $this->post(null);
        self::assertResponseStatusCodeSame(201);

        // Base listing (no schedulePlanId) returns the base reservation.
        $this->get(null);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->members());

        // A period-overlay listing excludes the base one.
        $this->get('44444444-4444-4444-8444-444444444444');
        self::assertCount(0, $this->members());

        // Delete → gone from the base listing.
        $this->client->request('DELETE', '/api/reservations/' . $id, [], [], $this->headers());
        self::assertResponseStatusCodeSame(204);
        $this->get(null);
        self::assertCount(0, $this->members());
    }

    public function testDeletingReservationPurgesItsMaterialisedHardTemplate(): void
    {
        // A reservation gets echoed HARD and materialised by ScheduleResultImporter
        // as a durable ScheduleSlotTemplate. Deleting the reservation must undo the
        // pin — else findBaseSlotTemplates re-injects it forever.
        $start = new DateTimeImmutable('20:30');
        $reservation = (new Reservation)
            ->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setTeamId('11111111-1111-4111-8111-111111111111')
            ->setVenueId('22222222-2222-4222-8222-222222222222')
            ->setDayOfWeek(2)->setStartTime($start)->setDurationMinutes(120);
        $template = (new ScheduleSlotTemplate)
            ->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setScheduleId($this->season->getId())
            ->setTeamId('11111111-1111-4111-8111-111111111111')
            ->setVenueId('22222222-2222-4222-8222-222222222222')
            ->setDayOfWeek(2)->setStartTime($start)->setDurationMinutes(120)
            ->setLockLevel(LockLevel::HARD);
        $this->em->persist($reservation);
        $this->em->persist($template);
        $this->em->flush();
        $templateId = $template->getId();

        $this->client->request('DELETE', '/api/reservations/' . $reservation->getId(), [], [], $this->headers());
        self::assertResponseStatusCodeSame(204);

        $this->em->clear();
        self::assertNull($this->em->getRepository(ScheduleSlotTemplate::class)->find($templateId), 'the materialised HARD pin must be purged with the reservation');
    }

    public function testOverlayReservationIsScopedToItsEntry(): void
    {
        $planId = $this->createPeriodPlan($this->club->getId(), $this->season->getId());
        $this->post($planId);
        self::assertResponseStatusCodeSame(201);

        $this->get($planId);
        self::assertCount(1, $this->members());

        // Not visible on the base plan (schedulePlanId IS NULL).
        $this->get(null);
        self::assertCount(0, $this->members());
    }

    // ── P2-37 D3 : on ne réserve pas un gymnase que la période rend indisponible ──

    public function testReservationOnAFullyClosedVenueIsRefused(): void
    {
        // Entrée lun 2026-10-19 → dim 2026-10-25, gymnase fermé sur TOUTE la fenêtre.
        $planId = $this->closedPeriodPlan('2026-10-19', '2026-10-25', '2026-10-19', '2026-10-25', 'Gymnase indisponible');

        $this->post($planId); // dayOfWeek 2 (mardi) — peu importe, tout est fermé
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Gymnase indisponible', (string) $this->client->getResponse()->getContent());
    }

    public function testReservationOnAClosedDayIsRefused(): void
    {
        // Fermeture du SEUL mardi 2026-10-20 (dayOfWeek 2) : le couple (gymnase, mardi) est fermé.
        $planId = $this->closedPeriodPlan('2026-10-19', '2026-10-25', '2026-10-20', '2026-10-20', 'Mardi fermé');

        $this->post($planId); // dayOfWeek 2 = mardi
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Mardi fermé', (string) $this->client->getResponse()->getContent());
    }

    public function testReservationOnAnOpenDayOfAPartiallyClosedVenueSucceeds(): void
    {
        // Même fermeture du mardi, mais on réserve le MERCREDI (dayOfWeek 3) : jour ouvert.
        $planId = $this->closedPeriodPlan('2026-10-19', '2026-10-25', '2026-10-20', '2026-10-20', 'Mardi fermé');

        $this->client->request('POST', '/api/reservations', [], [], $this->headers(), json_encode([
            'teamId' => '11111111-1111-4111-8111-111111111111',
            'venueId' => self::VENUE,
            'dayOfWeek' => 3,
            'startTime' => '20:30',
            'durationMinutes' => 120,
            'schedulePlanId' => $planId,
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);
    }

    // ── Indispo INFORMATIVE (fondateur 2026-08-18) : le masque du plan fait foi ──

    public function testReservationOnADayReopenedByTheMaskSucceeds(): void
    {
        // Le mardi est fermé par l'indisponibilité déclarée, mais le gestionnaire l'a ROUVERT
        // (masque OPEN) : la réservation du mardi passe — l'incident n'est plus qu'informatif.
        $planId = $this->closedPeriodPlan('2026-10-19', '2026-10-25', '2026-10-20', '2026-10-20', 'Mardi fermé');
        $this->mask($planId, [2 => 'OPEN']);

        $this->post($planId); // dayOfWeek 2 = mardi, rouvert
        self::assertResponseStatusCodeSame(201, 'un jour rouvert OPEN redevient réservable');
    }

    public function testReservationOnADayManuallyClosedByTheMaskIsRefused(): void
    {
        // Aucune indisponibilité déclarée, mais le gestionnaire a DÉCOCHÉ le mardi (masque CLOSED).
        $planId = $this->createPeriodPlan($this->club->getId(), $this->season->getId(), $this->openEntry('2026-10-19', '2026-10-25'));
        $this->mask($planId, [2 => 'CLOSED']);

        $this->post($planId); // dayOfWeek 2 = mardi, décoché
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('décoché', (string) $this->client->getResponse()->getContent(), 'la cause « jour décoché » est distinguée de l’indisponibilité déclarée');
    }

    // ── P2-60 : l'unité de placement est le bloc — garde de réservation INDIVIDUELLE (règle f) ──

    public function testIndividualReservationForATeamWithNoSoloResidualIsRefused(): void
    {
        // SF1+SF2 mutualisées, toutes les séances dans le bloc socle : R(SF1)=0 → réserver SF1
        // seule est un geste sans objet, refusé à la source (le moteur le rendrait INFEASIBLE).
        $t1 = $this->makeTeam(1);
        $t2 = $this->makeTeam(1);
        $this->makeBlock(null, [$t1, $t2], 1);

        self::assertSame(422, $this->postTeam($t1->getId(), 3, '18:00', null));
        self::assertStringContainsString('uniquement en groupe', (string) $this->client->getResponse()->getContent());
    }

    public function testIndividualReservationBeyondThePartialResidualIsRefused(): void
    {
        // R(SM3) = 2 − 1 = 1 : un créneau individuel passe, le second dépasse le résidu.
        $t1 = $this->makeTeam(2);
        $t2 = $this->makeTeam(2);
        $this->makeBlock(null, [$t1, $t2], 1);

        self::assertSame(201, $this->postTeam($t1->getId(), 3, '18:00', null));
        self::assertSame(422, $this->postTeam($t1->getId(), 4, '18:00', null));
        self::assertStringContainsString('possible(s)', (string) $this->client->getResponse()->getContent());
    }

    public function testAReservationCompletingABlockCaseIsAllowedEvenAtZeroResidual(): void
    {
        // t2 a R=0 (toutes ses séances en bloc), mais REJOINDRE t1 sur la même case COMPLÈTE le
        // bloc : la réservation n'est plus individuelle, elle n'est pas opposée au résidu.
        $t1 = $this->makeTeam(2); // R(t1)=1
        $t2 = $this->makeTeam(1); // R(t2)=0
        $this->makeBlock(null, [$t1, $t2], 1);

        self::assertSame(201, $this->postTeam($t1->getId(), 3, '18:00', null));
        self::assertSame(201, $this->postTeam($t2->getId(), 3, '18:00', null), 'la N-ième équipe complète la case bloc : autorisée');
    }

    public function testATeamOutsideAnyBlockIsCappedAtItsSessions(): void
    {
        // Hors bloc : R = S = 2. Deux créneaux passent, le troisième dépasse.
        $t = $this->makeTeam(2);

        self::assertSame(201, $this->postTeam($t->getId(), 3, '18:00', null));
        self::assertSame(201, $this->postTeam($t->getId(), 4, '18:00', null));
        self::assertSame(422, $this->postTeam($t->getId(), 5, '18:00', null));
        self::assertStringContainsString('possible(s)', (string) $this->client->getResponse()->getContent());
    }

    public function testSocleAndPeriodScopesAreIndependent(): void
    {
        // Bloc SOCLE {t1,t2}@1 (R socle(t1)=0), mais AUCUN bloc dans le plan de période : R(t1)=1.
        $t1 = $this->makeTeam(1);
        $t2 = $this->makeTeam(1);
        $this->makeBlock(null, [$t1, $t2], 1);

        self::assertSame(422, $this->postTeam($t1->getId(), 3, '18:00', null), 'socle : R=0, refusé');

        $planId = $this->createPeriodPlan($this->club->getId(), $this->season->getId());
        self::assertSame(201, $this->postTeam($t1->getId(), 3, '18:00', $planId), 'période sans bloc : R=1, passé');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get('security.user_password_hasher');

        $uid = uniqid('', true);

        $this->club = (new Club)
            ->setName('Res Club ' . $uid)
            ->setSlug('res-club-' . $uid)
            ->setTimezone('Europe/Paris')
            ->setLocale('fr')
            ->setOnboardingCompleted(true);
        $this->em->persist($this->club);

        $this->user = new User;
        $this->user->setEmail('res' . $uid . '@test.com');
        $this->user->setFirstName('Res');
        $this->user->setLastName('Tester');
        $this->user->setPasswordHash($hasher->hashPassword($this->user, 'Password123!'));
        $this->em->persist($this->user);
        $this->em->flush();

        $this->scopeGucToClub($this->club->getId());

        $cu = new ClubUser;
        $cu->setClubId($this->club->getId());
        $cu->setUserId($this->user->getId());
        $cu->setRole('admin');
        $cu->setIsActive(true);
        $this->em->persist($cu);

        $this->season = new Season;
        $this->season->setClubId($this->club->getId());
        $this->season->setName('2025-2026');
        $this->season->setStartDate(new DateTimeImmutable('2025-09-01'));
        $this->season->setEndDate(new DateTimeImmutable('2026-06-30'));
        $this->season->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($this->season);
        $this->em->flush();

        $this->token = $container->get(JWTTokenManagerInterface::class)->create($this->user);
    }

    /**
     * Un plan de période dont l'entrée porte une fermeture datée du gymnase self::VENUE.
     * Retourne le planId à ancrer sur la réservation.
     */
    private function closedPeriodPlan(string $entryStart, string $entryEnd, string $closureStart, string $closureEnd, string $title): string
    {
        $entry = (new CalendarEntry)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setKind(CalendarEntryKind::PERIOD)->setPeriodType(CalendarEntryPeriodType::HOLIDAY)->setTitle($title)
            ->setStartDate(new DateTimeImmutable($entryStart))->setEndDate(new DateTimeImmutable($entryEnd));
        $this->em->persist($entry);
        $constraint = (new Constraint)
            ->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setName($title)
            ->setScope(ConstraintScope::FACILITY)->setScopeTargetId(self::VENUE)
            ->setFamily(ConstraintFamily::FACILITY)->setRuleType(ConstraintRuleType::HARD)
            ->setCalendarEntryId($entry->getId());
        $constraint->setConfig(['type' => 'venue_closed', 'startDate' => $closureStart, 'endDate' => $closureEnd]);
        $this->em->persist($constraint);
        $this->em->flush();

        return $this->createPeriodPlan($this->club->getId(), $this->season->getId(), $entry->getId());
    }

    /** Une entrée de période SANS fermeture — pour éprouver le masque manuel seul. */
    private function openEntry(string $entryStart, string $entryEnd): string
    {
        $entry = (new CalendarEntry)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setKind(CalendarEntryKind::PERIOD)->setPeriodType(CalendarEntryPeriodType::HOLIDAY)->setTitle('Période ouverte')
            ->setStartDate(new DateTimeImmutable($entryStart))->setEndDate(new DateTimeImmutable($entryEnd));
        $this->em->persist($entry);
        $this->em->flush();

        return $entry->getId();
    }

    /**
     * Pose le masque manuel du gymnase self::VENUE sur ce plan (jour ISO → OPEN|CLOSED).
     *
     * @param array<int, string> $dayOverrides
     */
    private function mask(string $schedulePlanId, array $dayOverrides): void
    {
        $override = (new VenuePeriodOverride)
            ->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setSchedulePlanId($schedulePlanId)->setVenueId(self::VENUE)
            ->setDayOverrides($dayOverrides);
        $this->em->persist($override);
        $this->em->flush();
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return [
            'HTTP_X-Club-Id' => $this->club->getId(),
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token,
            'CONTENT_TYPE' => 'application/ld+json',
        ];
    }

    /** $schedulePlanId : null = réservation de BASE ; set = propre à ce plan (lot C3). */
    private function post(?string $schedulePlanId): string
    {
        $this->client->request('POST', '/api/reservations', [], [], $this->headers(), json_encode([
            'teamId' => '11111111-1111-4111-8111-111111111111',
            'venueId' => '22222222-2222-4222-8222-222222222222',
            'dayOfWeek' => 2,
            'startTime' => '20:30',
            'durationMinutes' => 120,
            'schedulePlanId' => $schedulePlanId,
        ], \JSON_THROW_ON_ERROR));

        return (string) (json_decode((string) $this->client->getResponse()->getContent(), true)['id'] ?? '');
    }

    private function get(?string $schedulePlanId): void
    {
        $query = null !== $schedulePlanId ? '?schedulePlanId=' . $schedulePlanId : '';
        $this->client->request('GET', '/api/reservations' . $query, [], [], $this->headers());
    }

    private function makeTeam(int $sessionsPerWeek): Team
    {
        $team = (new Team)
            ->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setSportCategoryId($this->makeUuid())->setPriorityTierId(3)
            ->setName('T' . substr($this->makeUuid(), 0, 6))
            ->setSessionsPerWeek($sessionsPerWeek)->setIsActive(true);
        $this->em->persist($team);
        $this->em->flush();

        return $team;
    }

    /**
     * @param list<Team> $teams
     */
    private function makeBlock(?string $planId, array $teams, int $commonSessions): void
    {
        $block = (new SharedTrainingBlock)
            ->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setSchedulePlanId($planId)->setCommonSessions($commonSessions);
        $this->em->persist($block);
        foreach ($teams as $team) {
            $member = (new SharedTrainingBlockTeam)
                ->setClubId($this->club->getId())->setSeasonId($this->season->getId())
                ->setSchedulePlanId($planId)->setBlockId($block->getId())->setTeamId($team->getId());
            $this->em->persist($member);
        }
        $this->em->flush();
    }

    private function postTeam(string $teamId, int $dayOfWeek, string $startTime, ?string $schedulePlanId): int
    {
        $this->client->request('POST', '/api/reservations', [], [], $this->headers(), json_encode([
            'teamId' => $teamId,
            'venueId' => self::VENUE,
            'dayOfWeek' => $dayOfWeek,
            'startTime' => $startTime,
            'durationMinutes' => 90,
            'schedulePlanId' => $schedulePlanId,
        ], \JSON_THROW_ON_ERROR));

        return $this->client->getResponse()->getStatusCode();
    }

    private function makeUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /** @return array<int, array<string, mixed>> */
    private function members(): array
    {
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);

        return $body['member'] ?? $body['hydra:member'] ?? [];
    }
}
