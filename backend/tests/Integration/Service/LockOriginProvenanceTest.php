<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\Club;
use App\Entity\Reservation;
use App\Entity\Schedule;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\Season;
use App\Enum\LockLevel;
use App\Enum\LockOrigin;
use App\Enum\ScheduleStatus;
use App\Enum\SeasonStatus;
use App\Service\ManualEditService;
use App\Service\ScheduleResultImporter;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * NR BLOQUANT — PROVENANCE DU VERROU (axes §7.1 : planning lifecycle + constraint semantics).
 *
 * F1 répond à « pourquoi ce créneau est-il bloqué ? ». La valeur de la réponse tient à un
 * seul invariant : elle est VRAIE. Un verrou né d'une réservation de gymnase doit se lire
 * RESERVATION (on n'y touche pas) ; un épinglage manuel, MANUAL (on peut le retirer) ; et un
 * verrou dont l'origine est indécidable, UNKNOWN — JAMAIS deviné RESERVATION ni MANUAL, car
 * une devinette rendrait le gestionnaire confiant à tort.
 *
 * Ce test épingle les deux falsifications qui comptent :
 *   - faire rendre MANUAL (ou UNKNOWN) à un créneau né d'une réservation → rouge nommé ;
 *   - faire deviner RESERVATION (ou MANUAL) à un verrou sans réservation à l'origine → rouge.
 */
#[Group('phase1')]
#[Group('integration')]
final class LockOriginProvenanceTest extends KernelTestCase
{
    use ChoosesPlanVersionTrait;
    use TenantGucTrait;

    private const ENGINE_ID = '11111111-1111-4111-8111-111111111111';
    private const TEAM = '22222222-2222-4222-8222-222222222222';
    private const VENUE = '33333333-3333-4333-8333-333333333333';

    private EntityManagerInterface $em;

    private ScheduleResultImporter $importer;

    private ManualEditService $manualEdit;

    public function testAReservationBornLockReadsAsReservation(): void
    {
        [$club, $season] = $this->seed();
        $schedule = $this->schedule($club, $season);
        // Une réservation de base (structure partagée, schedulePlanId NULL) alimente le socle.
        $this->reservation($club, $season, null);
        $this->em->flush();

        $this->importer->import($schedule, ['slots' => [$this->engineSlot(LockLevel::HARD)]]);

        $this->em->clear();
        $slot = $this->onlySlot($schedule);
        self::assertSame(LockLevel::HARD, $slot->getLockLevel());
        self::assertSame(
            LockOrigin::RESERVATION,
            $slot->getLockOrigin(),
            'Un créneau HARD né d\'une réservation doit se lire RESERVATION — pas MANUAL ni UNKNOWN.',
        );
    }

    public function testAHardLockWithoutABackingReservationStaysUnknownNeverGuessed(): void
    {
        [$club, $season] = $this->seed();
        $schedule = $this->schedule($club, $season);
        // AUCUNE réservation. Le moteur émet malgré tout un HARD à ce placement : l'origine
        // est INDÉCIDABLE côté import.
        $this->em->flush();

        $this->importer->import($schedule, ['slots' => [$this->engineSlot(LockLevel::HARD)]]);

        $this->em->clear();
        $slot = $this->onlySlot($schedule);
        self::assertSame(LockLevel::HARD, $slot->getLockLevel());
        self::assertSame(
            LockOrigin::UNKNOWN,
            $slot->getLockOrigin(),
            'Sans réservation à l\'origine, l\'origine est indécidable → UNKNOWN, jamais devinée RESERVATION.',
        );
    }

    public function testAnUnlockedSlotHasNoOrigin(): void
    {
        [$club, $season] = $this->seed();
        $schedule = $this->schedule($club, $season);
        $this->reservation($club, $season, null);
        $this->em->flush();

        $this->importer->import($schedule, ['slots' => [$this->engineSlot(LockLevel::NONE)]]);

        $this->em->clear();
        $slot = $this->onlySlot($schedule);
        self::assertSame(LockLevel::NONE, $slot->getLockLevel());
        self::assertNull($slot->getLockOrigin(), 'Un créneau non verrouillé n\'a pas d\'origine (NULL).');
    }

    public function testAHardLockMatchingASourceManualLockReadsAsManualNotUnknown(): void
    {
        [$club, $season] = $this->seed();

        // V1 = la version SOURCE du plan : COMPLETED, portant un verrou MANUAL au placement P,
        // sans AUCUNE réservation à l'origine (un épinglage de work-loop, pas une réservation).
        $v1 = $this->schedule($club, $season);
        $sourceLock = $this->slot($club, $season, $v1, LockLevel::HARD);
        $sourceLock->setLockOrigin(LockOrigin::MANUAL);
        $this->em->flush();

        // V2 = une NOUVELLE version du MÊME plan, en cours de génération (nue). Le builder réépingle
        // le verrou MANUAL de la source ; le moteur le réémet HARD au même placement.
        $v2 = (new Schedule)->setClubId($club->getId())->setSeasonId($season->getId())->setName('S2')->setStatus(ScheduleStatus::GENERATING);
        $this->linkSeededSchedule($v2);
        $this->em->flush();

        $this->importer->import($v2, ['slots' => [$this->engineSlot(LockLevel::HARD)]]);

        $this->em->clear();
        $slots = $this->em->getRepository(ScheduleSlotTemplate::class)->findBy(['scheduleId' => $v2->getId()]);
        self::assertCount(1, $slots);
        self::assertSame(LockLevel::HARD, $slots[0]->getLockLevel());
        self::assertSame(
            LockOrigin::MANUAL,
            $slots[0]->getLockOrigin(),
            'Un verrou HARD réimporté qui coïncide avec un verrou MANUAL de la version SOURCE doit se lire MANUAL (V1 « les verrous survivent »), jamais UNKNOWN — sinon le compteur de verrous ment.',
        );
    }

    public function testManualPinReadsAsManualAndUnlockClearsTheOrigin(): void
    {
        [$club, $season] = $this->seed();
        $schedule = $this->schedule($club, $season);
        $slot = $this->slot($club, $season, $schedule, LockLevel::NONE);
        $this->em->flush();

        $this->manualEdit->applyLock($slot, LockLevel::HARD);
        self::assertSame(LockOrigin::MANUAL, $slot->getLockOrigin(), 'Un épinglage manuel se lit MANUAL.');

        $this->manualEdit->applyLock($slot, LockLevel::NONE);
        self::assertNull($slot->getLockOrigin(), 'Déverrouiller efface l\'origine (plus de verrou).');
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->importer = self::getContainer()->get(ScheduleResultImporter::class);
        $this->manualEdit = self::getContainer()->get(ManualEditService::class);
    }

    /** @return array{0: Club, 1: Season} */
    private function seed(): array
    {
        $uid = uniqid('', true);
        $club = (new Club)->setName('C ' . $uid)->setSlug('c-' . $uid)->setTimezone('Europe/Paris')->setLocale('fr')
            ->setOnboardingCompleted(true)->setFfbbClubCode('PSI' . strtoupper(substr(md5($uid), 0, 9)));
        $this->em->persist($club);
        $this->em->flush();
        $this->scopeGucToClub($club->getId());

        $season = (new Season)->setClubId($club->getId())->setName('2025-2026')
            ->setStartDate(new DateTimeImmutable('2025-09-01'))->setEndDate(new DateTimeImmutable('2026-06-30'))->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($season);
        $this->em->flush();

        return [$club, $season];
    }

    private function schedule(Club $club, Season $season): Schedule
    {
        $schedule = (new Schedule)->setClubId($club->getId())->setSeasonId($season->getId())->setName('S')->setStatus(ScheduleStatus::COMPLETED);
        // Résout et pose le plan SEASON (fed par les réservations de base, schedulePlanId NULL).
        $this->linkSeededSchedule($schedule);

        return $schedule;
    }

    private function reservation(Club $club, Season $season, ?string $schedulePlanId): void
    {
        $reservation = (new Reservation)
            ->setClubId($club->getId())
            ->setSeasonId($season->getId())
            ->setSchedulePlanId($schedulePlanId)
            ->setTeamId(self::TEAM)
            ->setVenueId(self::VENUE)
            ->setDayOfWeek(2)
            ->setStartTime(new DateTimeImmutable('18:00'))
            ->setDurationMinutes(90);
        $this->em->persist($reservation);
    }

    private function slot(Club $club, Season $season, Schedule $schedule, LockLevel $lock): ScheduleSlotTemplate
    {
        $slot = (new ScheduleSlotTemplate)
            ->setClubId($club->getId())
            ->setSeasonId($season->getId())
            ->setScheduleId($schedule->getId())
            ->setTeamId(self::TEAM)
            ->setVenueId(self::VENUE)
            ->setDayOfWeek(2)
            ->setStartTime(new DateTimeImmutable('18:00'))
            ->setDurationMinutes(90)
            ->setLockLevel($lock);
        $this->em->persist($slot);

        return $slot;
    }

    /** @return array<string, mixed> */
    private function engineSlot(LockLevel $lock): array
    {
        return [
            'id' => self::ENGINE_ID,
            'teamId' => self::TEAM,
            'venueId' => self::VENUE,
            'dayOfWeek' => 2,
            'startTime' => '18:00',
            'durationMinutes' => 90,
            'lockLevel' => $lock->value,
        ];
    }

    private function onlySlot(Schedule $schedule): ScheduleSlotTemplate
    {
        $slots = $this->em->getRepository(ScheduleSlotTemplate::class)->findBy(['scheduleId' => $schedule->getId()]);
        self::assertCount(1, $slots);

        return $slots[0];
    }
}
