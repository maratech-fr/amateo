<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Club;
use App\Entity\Season;
use App\Entity\Venue;
use App\Entity\VenueTrainingSlot;
use App\Enum\SeasonStatus;
use App\Tests\CreatesPeriodPlanTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20261011120000;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * NR — lot A « l'export ne ment plus » (décision fondateur 2026-10-11) : la migration qui FIGE les
 * données déjà nées. Le libellé de groupe appartient au planning de SAISON ; tout créneau rattaché
 * à un plan (schedule_plan_id IS NOT NULL = créneau de période) perd son libellé, pendant que la
 * grille de saison (schedule_plan_id IS NULL) garde le sien.
 *
 * On exécute le VRAI SQL de la migration (via `getSql()`, jamais une copie), sous GUC de club : la
 * RLS borne alors l'UPDATE au seul club de test.
 */
#[Group('integration')]
final class MigrationPeriodSlotLabelStripTest extends KernelTestCase
{
    use CreatesPeriodPlanTrait;
    use TenantGucTrait;

    private EntityManagerInterface $em;

    public function testPeriodSlotsLoseTheirLabelWhileTheSeasonGridKeepsIt(): void
    {
        [$club, $season] = $this->makeClubAndSeason();
        $venue = $this->makeVenue($club, $season);

        // Un créneau de SAISON nommé (ancre nulle) et un créneau de PÉRIODE nommé (ancre = un plan).
        $seasonal = $this->makeSlot($club, $season, $venue, 2, '18:00', null, 'SAISON');
        $planId = $this->createPeriodPlan($club->getId(), $season->getId());
        $period = $this->makeSlot($club, $season, $venue, 3, '20:00', $planId, 'PERIODE');
        $this->em->flush();

        $this->scopeGucToClub($club->getId());
        $this->runMigration();
        $this->em->clear();

        $periodAfter = $this->em->getRepository(VenueTrainingSlot::class)->find($period->getId());
        self::assertInstanceOf(VenueTrainingSlot::class, $periodAfter);
        self::assertNull($periodAfter->getGroupLabel(), 'le créneau de période a perdu son libellé');

        $seasonalAfter = $this->em->getRepository(VenueTrainingSlot::class)->find($seasonal->getId());
        self::assertInstanceOf(VenueTrainingSlot::class, $seasonalAfter);
        self::assertSame('SAISON', $seasonalAfter->getGroupLabel(), 'la grille de saison garde son libellé');
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    /** @return array{0: Club, 1: Season} */
    private function makeClubAndSeason(): array
    {
        $uid = uniqid('', true);
        $club = new Club;
        $club->setName('Club libellé ' . $uid);
        $club->setSlug('libelle-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode('LBL' . strtoupper(substr(md5($uid), 0, 10)));
        $this->em->persist($club);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());

        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName('2025-2026');
        $season->setStartDate(new DateTimeImmutable('2025-09-01'));
        $season->setEndDate(new DateTimeImmutable('2026-06-30'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $season->setTransitionData([]);
        $this->em->persist($season);
        $this->em->flush();

        return [$club, $season];
    }

    private function makeVenue(Club $club, Season $season): Venue
    {
        $venue = new Venue;
        $venue->setClubId($club->getId());
        $venue->setSeasonId($season->getId());
        $venue->setName('Gymnase du libellé');
        $venue->setSource('manual');
        $this->em->persist($venue);

        return $venue;
    }

    private function makeSlot(Club $club, Season $season, Venue $venue, int $day, string $start, ?string $planId, string $label): VenueTrainingSlot
    {
        $slot = new VenueTrainingSlot;
        $slot->setClubId($club->getId());
        $slot->setSeasonId($season->getId());
        $slot->setVenueId($venue->getId());
        $slot->setDayOfWeek($day);
        $slot->setStartTime(new DateTimeImmutable($start));
        $slot->setDurationMinutes(90);
        $slot->setCapacity(2);
        $slot->setGroupLabel($label);
        $slot->setSchedulePlanId($planId);
        $this->em->persist($slot);

        return $slot;
    }

    private function runMigration(): void
    {
        $migration = new Version20261011120000($this->em->getConnection(), new NullLogger);
        $migration->up(new Schema);
        $connection = $this->em->getConnection();
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }
}
