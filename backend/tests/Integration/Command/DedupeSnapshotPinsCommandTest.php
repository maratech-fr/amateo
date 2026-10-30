<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Command\DedupeSnapshotPinsCommand;
use App\Entity\Club;
use App\Entity\Reservation;
use App\Entity\Schedule;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\Season;
use App\Entity\Team;
use App\Entity\Venue;
use App\Enum\LockLevel;
use App\Enum\ScheduleStatus;
use App\Enum\SeasonStatus;
use App\Service\ScheduleConstraintBuilder;
use App\Service\SchedulePlanProvisioner;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\Integration\ReservationPinsNotDoubledTest;
use App\Tests\ProvisionsPeriodPlanTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * NR de nettoyage de données (axe generation pipeline) : `app:schedule:dedupe-snapshot-pins`
 * répare les snapshots déjà DOUBLÉS en base par le défaut corrigé par ailleurs (l'import écrivait
 * une épingle HARD/RESERVATION redondante avec la réservation durable, cf.
 * {@see ReservationPinsNotDoubledTest}).
 *
 * La commande dédoublonne `snapshotData['slotTemplates']` sur la clé de placement
 * (teamId, venueId, dayOfWeek, startTime H:i, durationMinutes) des entrées HARD, en GARDANT
 * l'entrée de forme réservation (id ∈ réservations du périmètre) et en laissant tomber le
 * template redondant, puis recalcule `snapshot_hash = sha256(json_encode(snapshotData))` — mais
 * SEULEMENT si un doublon est tombé. Sa sortie est le nombre de schedules retouchés. Elle est
 * idempotente et ne touche JAMAIS un snapshot sain.
 *
 * Patron de garde migration de données (charte tests-par-parcours §2) : ce test protège le run
 * réel tant que la commande n'a pas tourné en prod. La RÈGLE « une réservation n'est épinglée
 * qu'une fois » vit, elle, dans le test de code vivant ci-dessus — jamais garantie par ce seul test.
 *
 * Étendu le 2026-10-30 : l'empreinte ne compte plus les séances `NONE` du solveur. Une version née
 * d'une génération antérieure porte un `snapshotHash` qui les comptait : la commande le recalcule,
 * sinon toutes ces versions paraîtraient périmées après le correctif. `snapshotData` n'est pas touché,
 * et une version dont la structure a VRAIMENT changé reste périmée.
 */
#[Group('phase1')]
#[Group('integration')]
final class DedupeSnapshotPinsCommandTest extends KernelTestCase
{
    use ChoosesPlanVersionTrait;
    use ProvisionsPeriodPlanTrait;
    use TenantGucTrait;

    private const string VENUE_ID = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    private const string TEAM_ID = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
    private const string TEMPLATE_PIN_ID = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd';
    private const string HEALTHY_PIN_ID = 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee';

    private EntityManagerInterface $em;

    /**
     * Un snapshot DOUBLÉ (réservation + son template HARD redondant au même placement) est
     * réparé : le template tombe, la réservation et l'épingle saine restent, et le hash est
     * recalculé sur le snapshot nettoyé. Un snapshot SAIN du même club n'est pas compté ni touché.
     */
    public function testDedupesDoubledSnapshotAndRecomputesHashLeavingHealthyOnesUntouched(): void
    {
        $f = $this->seed();

        $tester = $this->runCommand([]);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('1', $tester->getDisplay(), 'la sortie annonce le nombre de schedules retouchés (1)');

        $this->em->clear();
        $this->scopeGucToClub($f['clubId']);

        $doubled = $this->em->getRepository(Schedule::class)->find($f['doubled']);
        self::assertNotNull($doubled);
        $pins = $doubled->getSnapshotData()['slotTemplates'];
        self::assertCount(2, $pins, 'le template HARD/RESERVATION redondant est tombé ; la réservation et l’épingle saine restent');
        $ids = array_map(static fn (array $row): string => (string) $row['id'], $pins);
        self::assertContains($f['reservationId'], $ids, 'l’entrée de forme réservation est GARDÉE (id ∈ réservations du périmètre)');
        self::assertContains(self::HEALTHY_PIN_ID, $ids, 'l’épingle saine (placement distinct) survit');
        self::assertNotContains(self::TEMPLATE_PIN_ID, $ids, 'le template redondant au placement de la réservation est retiré');
        self::assertSame(
            hash('sha256', (string) json_encode($doubled->getSnapshotData(), \JSON_THROW_ON_ERROR)),
            $doubled->getSnapshotHash(),
            'le hash est recalculé sur le snapshot nettoyé',
        );

        // Le snapshot sain n'a pas bougé (ni data ni hash).
        $healthy = $this->em->getRepository(Schedule::class)->find($f['healthy']);
        self::assertNotNull($healthy);
        self::assertCount(1, $healthy->getSnapshotData()['slotTemplates']);
        self::assertSame($f['healthyHash'], $healthy->getSnapshotHash(), 'un snapshot sain n’est jamais retouché');
    }

    /** Idempotente : un second passage ne retouche plus rien. */
    public function testIsIdempotent(): void
    {
        $f = $this->seed();

        $this->runCommand([])->assertCommandIsSuccessful();
        $this->em->clear();
        $this->scopeGucToClub($f['clubId']);
        $afterFirst = $this->em->getRepository(Schedule::class)->find($f['doubled'])?->getSnapshotHash();

        $second = $this->runCommand([]);
        $second->assertCommandIsSuccessful();
        self::assertStringContainsString('0', $second->getDisplay(), 'le second passage ne retouche aucun schedule');

        $this->em->clear();
        $this->scopeGucToClub($f['clubId']);
        self::assertSame($afterFirst, $this->em->getRepository(Schedule::class)->find($f['doubled'])?->getSnapshotHash());
    }

    /** `--dry-run` compte sans rien écrire. */
    public function testDryRunWritesNothing(): void
    {
        $f = $this->seed();

        $tester = $this->runCommand(['--dry-run' => true]);
        $tester->assertCommandIsSuccessful();

        $this->em->clear();
        $this->scopeGucToClub($f['clubId']);
        $doubled = $this->em->getRepository(Schedule::class)->find($f['doubled']);
        self::assertNotNull($doubled);
        self::assertCount(3, $doubled->getSnapshotData()['slotTemplates'], 'en dry-run le snapshot doublé n’est pas nettoyé');
        self::assertSame($f['doubledHash'], $doubled->getSnapshotHash(), 'en dry-run le hash n’est pas recalculé');
    }

    /** Une version d'avant le correctif, structure inchangée, n'est plus vue comme périmée après passage. */
    public function testALegacyVersionCountingSolverSessionsIsRehashedAndNoLongerStale(): void
    {
        $f = $this->seedLegacyVersion();

        $this->runCommand([])->assertCommandIsSuccessful();

        $legacy = $this->reloadLegacy($f);
        self::assertSame($f['snapshot'], $legacy->getSnapshotData(), 'snapshotData reste tel quel, seule l\'empreinte change');
        self::assertNotSame($f['legacyHash'], $legacy->getSnapshotHash(), 'l\'ancienne empreinte comptait les séances NONE');
        $this->purgeCache($f);
        self::assertSame(
            self::getContainer()->get(SchedulePlanProvisioner::class)->structureHashOfPlan($f['planId']),
            $legacy->getSnapshotHash(),
            'structure inchangée : la version n\'est plus vue comme périmée',
        );

        // Idempotente.
        $rehashed = $legacy->getSnapshotHash();
        $this->runCommand([])->assertCommandIsSuccessful();
        self::assertSame($rehashed, $this->reloadLegacy($f)->getSnapshotHash());
    }

    /** `--dry-run` ne recalcule rien non plus pour ce cas. */
    public function testDryRunDoesNotRehashALegacyVersion(): void
    {
        $f = $this->seedLegacyVersion();

        $this->runCommand(['--dry-run' => true])->assertCommandIsSuccessful();

        self::assertSame($f['legacyHash'], $this->reloadLegacy($f)->getSnapshotHash());
    }

    /** Une structure VRAIMENT changée reste périmée : on ne recopie jamais l'empreinte courante. */
    public function testAVersionWhoseStructureReallyChangedStaysStale(): void
    {
        $f = $this->seedLegacyVersion();
        $this->scopeGucToClub($f['clubId']);
        $this->seedTeam($f['clubId'], $f['seasonId'], '99999999-9999-4999-8999-999999999999', 'Nouvelle');
        $this->em->flush();

        $this->runCommand([])->assertCommandIsSuccessful();

        $legacy = $this->reloadLegacy($f);
        $this->purgeCache($f);
        self::assertNotSame(
            self::getContainer()->get(SchedulePlanProvisioner::class)->structureHashOfPlan($f['planId']),
            $legacy->getSnapshotHash(),
            'une équipe ajoutée depuis la génération : le planning reste périmé',
        );
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function runCommand(array $options): CommandTester
    {
        $tester = new CommandTester(self::getContainer()->get(DedupeSnapshotPinsCommand::class));
        $tester->execute($options);

        return $tester;
    }

    /**
     * Une 2ᵉ version née avant le correctif : son snapshot porte la séance NONE de la 1ʳᵉ version, et
     * son hash la compte. Snapshot pris par le VRAI builder, sur la structure qui existe encore.
     *
     * @return array{clubId: string, seasonId: string, planId: string, scheduleId: string, legacyHash: string, snapshot: array<string, mixed>}
     */
    private function seedLegacyVersion(): array
    {
        $uid = uniqid('', true);
        $club = (new Club)->setName('Legacy Club')->setSlug('legacy-' . $uid)
            ->setTimezone('Europe/Paris')->setLocale('fr')->setOnboardingCompleted(true)
            ->setFfbbClubCode('LGC' . strtoupper(substr(md5($uid), 0, 8)));
        $this->em->persist($club);
        $this->em->flush();
        $this->scopeGucToClub($club->getId());

        $season = (new Season)->setClubId($club->getId())->setName('2025-2026')
            ->setStartDate(new DateTimeImmutable('2025-09-01'))->setEndDate(new DateTimeImmutable('2026-06-30'))
            ->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($season);
        $this->em->flush();

        $this->seedTeam($club->getId(), $season->getId(), self::TEAM_ID, 'Seniors A');
        $venue = (new Venue)->setClubId($club->getId())->setSeasonId($season->getId())->setName('Platane')
            ->setCanSplit(false)->setSource('manual');
        $venue->setId(self::VENUE_ID);
        $this->em->persist($venue);

        $planId = $this->seasonPlanIdOf($season);
        $first = (new Schedule)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setName('V1')->setStatus(ScheduleStatus::COMPLETED);
        $this->linkSeededSchedule($first);
        $this->em->persist((new ScheduleSlotTemplate)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setScheduleId($first->getId())->setTeamId(self::TEAM_ID)->setVenueId(self::VENUE_ID)
            ->setDayOfWeek(4)->setStartTime(new DateTimeImmutable('18:00'))->setDurationMinutes(90)
            ->setLockLevel(LockLevel::NONE));
        $this->em->flush();

        $f = ['clubId' => $club->getId(), 'seasonId' => $season->getId(), 'planId' => $planId];
        $this->purgeCache($f);
        $snapshot = self::getContainer()->get(ScheduleConstraintBuilder::class)->buildForClubSeason($club->getId(), $season->getId());
        self::assertContains('NONE', array_column($snapshot['slotTemplates'], 'lockLevel'), 'témoin : le snapshot d\'avant le correctif compte la séance du solveur');
        $legacyHash = hash('sha256', json_encode($snapshot, \JSON_THROW_ON_ERROR));

        $second = (new Schedule)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setName('V2')->setStatus(ScheduleStatus::COMPLETED)
            ->setSnapshotData($snapshot)->setSnapshotHash($legacyHash);
        $this->linkSeededSchedule($second);
        $this->em->flush();

        return [...$f, 'scheduleId' => $second->getId(), 'legacyHash' => $legacyHash, 'snapshot' => $snapshot];
    }

    private function seedTeam(string $clubId, string $seasonId, string $id, string $name): void
    {
        $team = (new Team)->setClubId($clubId)->setSeasonId($seasonId)->setName($name)
            ->setSportCategoryId('cccccccc-cccc-4ccc-8ccc-cccccccccccc')->setPriorityTierId(1);
        $team->setId($id);
        $this->em->persist($team);
    }

    /** @param array{clubId: string, seasonId: string, scheduleId: string} $f */
    private function reloadLegacy(array $f): Schedule
    {
        $this->em->clear();
        $this->scopeGucToClub($f['clubId']);
        $schedule = $this->em->getRepository(Schedule::class)->find($f['scheduleId']);
        self::assertInstanceOf(Schedule::class, $schedule);

        return $schedule;
    }

    /** @param array{clubId: string, seasonId: string} $f */
    private function purgeCache(array $f): void
    {
        self::getContainer()->get('cache.schedule')->deleteItem(ScheduleConstraintBuilder::cacheKey($f['clubId'], $f['seasonId']));
    }

    /**
     * Un club avec : (a) un schedule dont le snapshot est DOUBLÉ (réservation + template HARD
     * redondant au même placement + une épingle saine ailleurs) ; (b) un schedule SAIN.
     *
     * @return array{clubId: string, doubled: string, healthy: string, reservationId: string, doubledHash: string, healthyHash: string}
     */
    private function seed(): array
    {
        $uid = uniqid('', true);

        $club = (new Club)->setName('Dedupe Club')->setSlug('dedupe-' . $uid)
            ->setTimezone('Europe/Paris')->setLocale('fr')->setOnboardingCompleted(true)
            ->setFfbbClubCode('DDP' . strtoupper(substr(md5($uid), 0, 8)));
        $this->em->persist($club);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());

        $season = (new Season)->setClubId($club->getId())->setName('2025-2026')
            ->setStartDate(new DateTimeImmutable('2025-09-01'))->setEndDate(new DateTimeImmutable('2026-06-30'))
            ->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($season);
        $this->em->flush();

        // La réservation durable dont le template HARD/RESERVATION est né à l'import.
        $reservation = (new Reservation)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setSchedulePlanId(null)->setTeamId(self::TEAM_ID)->setVenueId(self::VENUE_ID)
            ->setDayOfWeek(2)->setStartTime(new DateTimeImmutable('18:00'))->setDurationMinutes(90);
        $this->em->persist($reservation);
        $this->em->flush();
        $reservationId = $reservation->getId();

        $reservationEntry = $this->pin($reservationId, 2, '18:00');            // forme réservation
        $templateEntry = $this->pin(self::TEMPLATE_PIN_ID, 2, '18:00');        // doublon né de l'import
        $healthyEntry = $this->pin(self::HEALTHY_PIN_ID, 4, '20:00');          // épingle saine, placement distinct

        $doubled = $this->schedule($club, $season, [$reservationEntry, $templateEntry, $healthyEntry]);
        $healthy = $this->schedule($club, $season, [$this->pin(self::HEALTHY_PIN_ID, 4, '20:00')]);
        $this->em->flush();

        return [
            'clubId' => $club->getId(),
            'doubled' => $doubled->getId(),
            'healthy' => $healthy->getId(),
            'reservationId' => $reservationId,
            'doubledHash' => (string) $doubled->getSnapshotHash(),
            'healthyHash' => (string) $healthy->getSnapshotHash(),
        ];
    }

    /**
     * @param list<array<string, mixed>> $pins
     */
    private function schedule(Club $club, Season $season, array $pins): Schedule
    {
        $snapshot = ['slotTemplates' => $pins, 'venues' => [], 'teams' => []];
        $schedule = (new Schedule)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setName('Version')->setStatus(ScheduleStatus::COMPLETED)
            ->setSnapshotData($snapshot)
            ->setSnapshotHash(hash('sha256', (string) json_encode($snapshot, \JSON_THROW_ON_ERROR)));
        $this->linkSeededSchedule($schedule);

        return $schedule;
    }

    /**
     * Une entrée `slotTemplates` telle que la pose la sérialisation du payload (forme identique
     * pour une réservation et pour un template HARD — seul l'`id` les distingue).
     *
     * @return array<string, mixed>
     */
    private function pin(string $id, int $dayOfWeek, string $startTime): array
    {
        return [
            'id' => $id,
            'teamId' => self::TEAM_ID,
            'venueId' => self::VENUE_ID,
            'coachId' => null,
            'dayOfWeek' => $dayOfWeek,
            'startTime' => $startTime,
            'durationMinutes' => 90,
            'lockLevel' => 'HARD',
            'coachLateToleranceMinutes' => 0,
            'pendingConstraintSuggestion' => null,
        ];
    }
}
