<?php

declare(strict_types=1);

namespace App\Tests\Integration\Plans;

use App\Entity\Club;
use App\Entity\ImplicitRuleSetting;
use App\Entity\Place;
use App\Entity\Reservation;
use App\Entity\SchedulePlan;
use App\Entity\Season;
use App\Entity\SharedTrainingBlock;
use App\Entity\SharedTrainingBlockTeam;
use App\Entity\Venue;
use App\Entity\VenueTrainingSlot;
use App\Enum\ImplicitRuleIntensity;
use App\Enum\ImplicitRuleKey;
use App\Enum\SchedulePlanType;
use App\Enum\SeasonStatus;
use App\Plans\PlanBirth;
use App\Tests\SeasonPlanOf;
use App\Tests\Security\PeriodPlanBirthTest as PeriodPlanBirthGestureTest;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Promesse (lot 4, PR 5, ligne 36 du plan) : un plan reçoit à sa naissance, par une seule copie lue sur le catalogue
 * des familles (`PlanBirth::copy`), la grille de son plan source sans libellé de groupe, ses occupations (celles d'un
 * gymnase présent), ses règles de bien-être (les cinq, déviations comprises), et pour une fermeture ses groupes
 * mutualisés et leurs membres ; des vacances ne reçoivent aucun groupe, et aucun plan ne reçoit jamais les
 * réservations. « Reprendre la grille » d'un gymnase est la même copie bornée à ce gymnase : sa grille et ses
 * occupations, rien d'autre.
 *
 * Second angle voulu (axe *planning lifecycle*), jumeau cité : {@see PeriodPlanBirthGestureTest} prouve la même
 * naissance par le geste « Adapter » (blocs, règles de bien-être, libellé) ; ici la copie elle-même, sur un plan fait
 * à la main, pour chaque famille que le catalogue déclare copiée
 * (`FamilyDeclarationSweepTest::testEveryPlanFamilyDeclaresWhetherItIsCopiedAtPlanBirth`).
 */
#[Group('integration')]
final class PlanBirthTest extends KernelTestCase
{
    use TenantGucTrait;

    private const string TEAM_A = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    private const string TEAM_B = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';

    private EntityManagerInterface $em;

    private Club $club;

    private Season $season;

    private string $soclePlanId;

    private string $venueNord;

    private string $venueSud;

    /** Une fermeture naît avec grille, occupations, groupes mutualisés et règles de bien-être, jamais les réservations. */
    public function testAClosureIsBornWithGridOccupanciesSharedBlocksAndWellbeingRules(): void
    {
        $this->seedSocle();
        $closure = $this->handMadePlan(SchedulePlanType::CLOSURE, '2025-11-03', '2025-11-09');

        self::getContainer()->get(PlanBirth::class)->copy($this->soclePlanId, $closure);

        self::assertSame(
            [[$this->venueNord, 1, '18:00', 90, 2, null], [$this->venueSud, 3, '19:00', 90, 1, null]],
            $this->gridOf($closure),
            'la grille du socle, créneau par créneau, sans le libellé de groupe « CEC3 »',
        );
        self::assertSame([[$this->venueNord, 2, '14:00', 'École']], $this->occupanciesOf($closure), 'les occupations d\'un gymnase présent, jamais la dormante');
        self::assertSame([[2, 'Groupe U15', [self::TEAM_A, self::TEAM_B]]], $this->blocksOf($closure), 'le groupe du socle et ses deux membres, rattachés au groupe copié');
        $this->assertWellbeingRulesCopied($closure);
        self::assertSame(0, $this->rowCount(Reservation::class, $closure), 'une réservation du socle ne naît jamais dans un plan');
    }

    /** Des vacances naissent avec la grille, les occupations et les règles, sans aucun groupe mutualisé ni réservation. */
    public function testAHolidayIsBornWithoutSharedBlocks(): void
    {
        $this->seedSocle();
        $holiday = $this->handMadePlan(SchedulePlanType::HOLIDAY, '2025-12-22', '2025-12-28');

        self::getContainer()->get(PlanBirth::class)->copy($this->soclePlanId, $holiday);

        self::assertCount(2, $this->gridOf($holiday), 'témoin : la grille est copiée (sinon l\'absence de groupe serait creuse)');
        self::assertCount(1, $this->occupanciesOf($holiday));
        self::assertSame([], $this->blocksOf($holiday), 'des vacances ne reçoivent aucun groupe mutualisé');
        self::assertSame(0, $this->rowCount(SharedTrainingBlockTeam::class, $holiday), 'ni aucun membre de groupe');
        $this->assertWellbeingRulesCopied($holiday);
        self::assertSame(0, $this->rowCount(Reservation::class, $holiday), 'une réservation du socle ne naît jamais dans un plan');
    }

    /** « Reprendre la grille » d'un gymnase copie sa grille et ses occupations, et rien d'autre du plan source. */
    public function testTakingBackTheGridOfOneVenueCopiesOnlyThatVenue(): void
    {
        $this->seedSocle();
        $closure = $this->handMadePlan(SchedulePlanType::CLOSURE, '2025-11-03', '2025-11-09');

        self::getContainer()->get(PlanBirth::class)->copy($this->soclePlanId, $closure, $this->venueNord);

        self::assertSame([[$this->venueNord, 1, '18:00', 90, 2, null]], $this->gridOf($closure), 'la grille du seul gymnase repris');
        self::assertSame([[$this->venueNord, 2, '14:00', 'École']], $this->occupanciesOf($closure), 'ses occupations');
        self::assertSame([], $this->blocksOf($closure), 'aucun groupe : reprendre une grille ne touche pas la mutualisation');
        self::assertSame(0, $this->rowCount(ImplicitRuleSetting::class, $closure), 'aucune règle de bien-être');
        self::assertSame(0, $this->rowCount(Reservation::class, $closure), 'aucune réservation');
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $uid = uniqid('', true);
        $this->club = (new Club)->setName('Copie ' . $uid)->setSlug('copie-' . $uid)->setTimezone('Europe/Paris')
            ->setLocale('fr')->setOnboardingCompleted(true);
        $this->em->persist($this->club);
        $this->em->flush();
        $this->scopeGucToClub($this->club->getId());
        $this->season = (new Season)->setClubId($this->club->getId())->setName('2025-2026')
            ->setStartDate(new DateTimeImmutable('2025-09-01'))->setEndDate(new DateTimeImmutable('2026-06-30'))->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($this->season);
        $this->em->flush();
        $this->soclePlanId = SeasonPlanOf::id(self::getContainer(), $this->season->getId());
        $this->venueNord = $this->venue('Gymnase Nord');
        $this->venueSud = $this->venue('Gymnase Sud');
    }

    /**
     * Le socle : deux créneaux (l'un mutualisé et nommé « CEC3 »), une occupation par gymnase présent et une dormante
     * (gymnase absent), un groupe de deux équipes, une réservation, une règle de bien-être déviée du défaut.
     */
    private function seedSocle(): void
    {
        $clubId = $this->club->getId();
        $seasonId = $this->season->getId();
        foreach ([[$this->venueNord, 1, '18:00', 2, 'CEC3'], [$this->venueSud, 3, '19:00', 1, null]] as [$venueId, $day, $start, $capacity, $label]) {
            $this->em->persist((new VenueTrainingSlot)->setClubId($clubId)->setSeasonId($seasonId)->setSchedulePlanId($this->soclePlanId)
                ->setVenueId($venueId)->setDayOfWeek($day)->setStartTime(new DateTimeImmutable($start))->setDurationMinutes(90)
                ->setCapacity($capacity)->setGroupLabel($label));
        }
        $block = (new SharedTrainingBlock)->setClubId($clubId)->setSeasonId($seasonId)->setSchedulePlanId($this->soclePlanId)
            ->setCommonSessions(2)->setLabel('Groupe U15');
        $this->em->persist($block);
        $this->em->flush();
        foreach ([self::TEAM_A, self::TEAM_B] as $teamId) {
            $this->em->persist((new SharedTrainingBlockTeam)->setClubId($clubId)->setSeasonId($seasonId)->setSchedulePlanId($this->soclePlanId)
                ->setBlockId($block->getId())->setTeamId($teamId));
        }
        $this->em->persist((new Reservation)->setClubId($clubId)->setSeasonId($seasonId)->setSchedulePlanId($this->soclePlanId)
            ->setTeamId(self::TEAM_A)->setVenueId($this->venueSud)->setDayOfWeek(3)->setStartTime(new DateTimeImmutable('19:00'))->setDurationMinutes(90));
        $this->em->persist((new ImplicitRuleSetting)->setClubId($clubId)->setSeasonId($seasonId)->setSchedulePlanId($this->soclePlanId)
            ->setRuleKey(ImplicitRuleKey::COACH_REST_DAY)->setIntensity(ImplicitRuleIntensity::PREFERRED)->setParams(['minRestDays' => 3]));
        $this->em->flush();

        // Les occupations, en base sur les colonnes du contrat : celle du Gymnase Nord, et une dormante (gymnase absent).
        foreach ([[$this->venueNord, 2, '14:00', 'École'], [Uuid::v4()->toRfc4122(), 4, '10:00', 'Dormante']] as [$venueId, $day, $start, $label]) {
            $this->em->getConnection()->executeStatement(
                'INSERT INTO venue_occupancy (id, version, created_at, updated_at, club_id, season_id, venue_id, day_of_week, start_time, duration_minutes, label, schedule_plan_id)'
                . ' VALUES (:id, 1, now(), now(), :club, :season, :venue, :day, :start, 120, :label, :plan)',
                ['id' => Uuid::v4()->toRfc4122(), 'club' => $clubId, 'season' => $seasonId, 'venue' => $venueId, 'day' => $day, 'start' => $start, 'label' => $label, 'plan' => $this->soclePlanId],
            );
        }
    }

    /** Un plan de période fait à la main, vide : rien n'y est copié avant l'appel testé. */
    private function handMadePlan(SchedulePlanType $type, string $start, string $end): string
    {
        $plan = (new SchedulePlan)->setClubId($this->club->getId())->setSeasonId($this->season->getId())->setType($type)
            ->setName($type->value . ' ' . $start)->setStartDate(new DateTimeImmutable($start))->setEndDate(new DateTimeImmutable($end));
        $this->em->persist($plan);
        $this->em->flush();
        self::assertSame(0, $this->rowCount(VenueTrainingSlot::class, $plan->getId()), 'témoin : le plan fait à la main naît sans grille');

        return $plan->getId();
    }

    private function venue(string $name): string
    {
        $place = (new Place)->setClubId($this->club->getId());
        $this->em->persist($place);
        $venue = new Venue($place)->setClubId($this->club->getId())->setSeasonId($this->season->getId())->setName($name)->setSource('manual');
        $this->em->persist($venue);
        $this->em->flush();

        return $venue->getId();
    }

    /** Les cinq règles de bien-être : la déviation du socle copiée, l'opt-in à OFF, les autres au défaut. */
    private function assertWellbeingRulesCopied(string $planId): void
    {
        $byKey = [];
        foreach ($this->rows('SELECT rule_key, intensity, params FROM implicit_rule_setting WHERE schedule_plan_id = ? ORDER BY rule_key', $planId) as $row) {
            $byKey[(string) $row['rule_key']] = [(string) $row['intensity'], null === $row['params'] ? null : json_decode((string) $row['params'], true, 512, \JSON_THROW_ON_ERROR)];
        }
        self::assertSame(['ageAscending', 'coachRestDay', 'maxConsecutiveDays', 'maxConsecutiveSessions', 'salarieDistribution'], array_keys($byKey), 'les cinq règles de bien-être, copie totale');
        self::assertSame([ImplicitRuleIntensity::PREFERRED->value, ['minRestDays' => 3]], $byKey['coachRestDay'], 'la déviation du socle est copiée avec sa valeur');
        self::assertSame(ImplicitRuleIntensity::OFF->value, $byKey['maxConsecutiveDays'][0], 'la règle opt-in non réglée naît OFF');
        self::assertSame([ImplicitRuleIntensity::HARD->value, null], $byKey['ageAscending'], 'une règle non réglée naît au défaut');
    }

    /** @return list<array{0: string, 1: int, 2: string, 3: int, 4: int, 5: ?string}> gymnase, jour, début, durée, capacité, libellé */
    private function gridOf(string $planId): array
    {
        return array_map(
            static fn (array $row): array => [(string) $row['venue_id'], (int) $row['day_of_week'], substr((string) $row['start_time'], 0, 5), (int) $row['duration_minutes'], (int) $row['capacity'], null === $row['group_label'] ? null : (string) $row['group_label']],
            $this->rows('SELECT venue_id, day_of_week, start_time, duration_minutes, capacity, group_label FROM venue_training_slot WHERE schedule_plan_id = ? ORDER BY day_of_week', $planId),
        );
    }

    /** @return list<array{0: string, 1: int, 2: string, 3: string}> gymnase, jour, début, libellé */
    private function occupanciesOf(string $planId): array
    {
        return array_map(
            static fn (array $row): array => [(string) $row['venue_id'], (int) $row['day_of_week'], substr((string) $row['start_time'], 0, 5), (string) $row['label']],
            $this->rows('SELECT venue_id, day_of_week, start_time, label FROM venue_occupancy WHERE schedule_plan_id = ? ORDER BY day_of_week', $planId),
        );
    }

    /** @return list<array{0: int, 1: ?string, 2: list<string>}> séances communes, libellé, équipes membres rattachées au groupe copié */
    private function blocksOf(string $planId): array
    {
        $blocks = [];
        foreach ($this->rows('SELECT id, common_sessions, label FROM shared_training_block WHERE schedule_plan_id = ? ORDER BY common_sessions', $planId) as $row) {
            $members = array_map(
                static fn (array $member): string => (string) $member['team_id'],
                $this->rows('SELECT team_id FROM shared_training_block_team WHERE schedule_plan_id = ? AND block_id = ? ORDER BY team_id', $planId, (string) $row['id']),
            );
            $blocks[] = [(int) $row['common_sessions'], null === $row['label'] ? null : (string) $row['label'], $members];
        }

        return $blocks;
    }

    /** @param class-string $family */
    private function rowCount(string $family, string $planId): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM ' . $this->em->getClassMetadata($family)->getTableName() . ' WHERE schedule_plan_id = ?',
            [$planId],
        );
    }

    /** @return list<array<string, mixed>> */
    private function rows(string $sql, string ...$params): array
    {
        $this->em->flush();

        return $this->em->getConnection()->fetchAllAssociative($sql, $params);
    }
}
