<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\CalendarEntry;
use App\Entity\ConstraintPeriodOverride;
use App\Entity\ImplicitRuleSetting;
use App\Entity\Reservation;
use App\Entity\SchedulePlan;
use App\Entity\SharedTrainingBlock;
use App\Entity\SharedTrainingBlockTeam;
use App\Entity\Team;
use App\Entity\TeamPeriodOverride;
use App\Entity\VenueOccupancy;
use App\Entity\VenuePeriodOverride;
use App\Entity\VenueTrainingSlot;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\CalendarEntryStatus;
use App\Enum\ConstraintFamily;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use App\Enum\ImplicitRuleIntensity;
use App\Enum\ImplicitRuleKey;
use App\Enum\VenuePeriodMode;
use DateTimeImmutable;
use PHPUnit\Framework\Assert;

/**
 * Ce que le lot 4 range dans le plan, posé sur le décor des demandes au moteur (références 11 à 15) :
 *
 *  11. le socle complet — les six familles qui portent `schedulePlanId` y ont une ligne de socle (grille
 *      avec libellé de groupe, occupation, réservation d'équipe et réservation libre, groupe mutualisé et
 *      ses deux membres, règle de bien-être réglée), et les séances et le plafond par jour d'une équipe
 *      diffèrent de leur défaut ;
 *  12. un plan de vacances réglé : semaine-enfant d'une période mère, U13 écartée, U15 à 1 séance, SM1 au
 *      plafond de 2 par jour, la règle d'équipe des U15 recochée, une règle de club décochée, le gymnase
 *      Sud hors service, le mercredi fermé au Nord par le masque, une contrainte datée sur la MÈRE, une
 *      équipe (U17) créée après la naissance ; plan ancien, sans copie des règles de bien-être (il suit
 *      la saison, qui en règle une) ; pré-sélection des équipes déjà faite ;
 *  13. un plan de fermeture réglé : groupe mutualisé hérité du socle à la naissance, règle des U13
 *      décochée, incident au Nord levé par « grille ressaisie », une contrainte et une équipe (U17)
 *      créées après la naissance ;
 *  14. le verdict d'un déplacement dans le socle ;
 *  15. le verdict d'un déplacement dans un plan de fermeture.
 *
 * Seuls les plans et ce que leur naissance copie ont un identifiant aléatoire ; la copie du groupe
 * mutualisé (référence 13) reçoit ensuite un identifiant fixe, parce que le sien part au moteur.
 *
 * S'emploie AVEC `EngineRequestDecorTrait` (il en lit les identifiants, `erConstraint` et `erVersion`).
 * Consommé par `Integration/EngineRequestReferenceTest`.
 */
trait EngineRequestPlanSettingsDecorTrait
{
    private const string ER_TEAM_U17_HOLIDAY = '7ea00000-0000-4000-8000-000000000031';
    private const string ER_TEAM_U17_CLOSURE = '7ea00000-0000-4000-8000-000000000041';
    private const string ER_HOLIDAY_MOTHER = 'e0770000-0000-4000-8000-000000000031';
    private const string ER_HOLIDAY_WEEK = 'e0770000-0000-4000-8000-000000000032';
    private const string ER_CLOSURE_SETTINGS = 'e0770000-0000-4000-8000-000000000041';
    private const string ER_HOLIDAY_V1 = '5c4e0000-0000-4000-8000-000000000031';
    private const string ER_CLOSURE_V1 = '5c4e0000-0000-4000-8000-000000000041';
    /** Règle de club permanente (référence 12), décochée dans les vacances. */
    private const string ER_RULE_CLUB = 'c0570000-0000-4000-8000-000000000031';
    /** Contrainte datée sur la mère des vacances (référence 12) : SM1 pas le jeudi. */
    private const string ER_RULE_MOTHER = 'c0570000-0000-4000-8000-000000000032';
    /** Incident de la fermeture réglée (référence 13) : le Nord fermé mardi et mercredi. */
    private const string ER_RULE_INCIDENT = 'c0570000-0000-4000-8000-000000000041';
    /** Contrainte permanente créée après la naissance de la fermeture réglée (référence 13). */
    private const string ER_RULE_AFTER_BIRTH = 'c0570000-0000-4000-8000-000000000042';
    private const string ER_BLOCK_SOCLE = '5b100000-0000-4000-8000-000000000031';
    private const string ER_BLOCK_CLOSURE = '5b100000-0000-4000-8000-000000000041';

    /** Référence 11 : chaque famille ancrée au plan a sa ligne de socle, et l'équipe ses réglages propres. */
    private function seedEngineRequestCompleteSocle(): void
    {
        $em = $this->erEm();
        $u15 = $em->getRepository(Team::class)->find(self::ER_TEAM_U15);
        $sm1 = $em->getRepository(Team::class)->find(self::ER_TEAM_SM1);
        $slot = $em->getRepository(VenueTrainingSlot::class)->find(self::erId('a7a1', 3));
        Assert::assertInstanceOf(Team::class, $u15);
        Assert::assertInstanceOf(Team::class, $sm1);
        Assert::assertInstanceOf(VenueTrainingSlot::class, $slot);
        $u15->setSessionsPerWeek(3);
        $sm1->setMaxSessionsPerDay(2);
        $slot->setGroupLabel('Groupe U15 + SM1');

        $this->erSocleBlock(self::ER_BLOCK_SOCLE, 31);
        // Réservation libre (sans équipe) : elle retire une place à la case du Nord, lundi 19:30.
        $em->persist((new Reservation)->setId(self::erId('e5e0', 31))->setClubId(self::ER_CLUB)->setSeasonId(self::ER_SEASON)
            ->setVenueId(self::ER_VENUE_NORD)->setDayOfWeek(1)->setStartTime(new DateTimeImmutable('19:30'))->setDurationMinutes(90)
            ->setSchedulePlanId($this->erSeasonPlanId()));
        $em->persist((new VenueOccupancy)->setClubId(self::ER_CLUB)->setSeasonId(self::ER_SEASON)->setVenueId(self::ER_VENUE_SUD)
            ->setDayOfWeek(4)->setStartTime(new DateTimeImmutable('19:30'))->setDurationMinutes(60)->setLabel('École')
            ->setSchedulePlanId($this->erSeasonPlanId()));
        $this->erSeasonImplicitRule(31);
        $em->flush();
    }

    /**
     * Référence 12 : la semaine-enfant d'une période de vacances mère et son plan réglé. Rend l'id du plan.
     */
    private function seedEngineRequestHolidaySettings(): string
    {
        $em = $this->erEm();
        $this->erSeasonImplicitRule(32);
        $this->erConstraint(31, ConstraintScope::CLUB, null, ConstraintFamily::TIME, ConstraintRuleType::PREFERRED, ['minStartTime' => '17:30']);
        $em->persist((new CalendarEntry)->setId(self::ER_HOLIDAY_MOTHER)->setClubId(self::ER_CLUB)->setSeasonId(self::ER_SEASON)
            ->setKind(CalendarEntryKind::PERIOD)->setPeriodType(CalendarEntryPeriodType::HOLIDAY)->setTitle('Vacances d\'hiver')
            ->setStartDate(new DateTimeImmutable('2026-02-16'))->setEndDate(new DateTimeImmutable('2026-03-01'))
            ->setStatus(CalendarEntryStatus::ACTIVE));
        $week = (new CalendarEntry)->setId(self::ER_HOLIDAY_WEEK)->setClubId(self::ER_CLUB)->setSeasonId(self::ER_SEASON)
            ->setKind(CalendarEntryKind::PERIOD)->setPeriodType(CalendarEntryPeriodType::HOLIDAY)->setTitle('Vacances d\'hiver, semaine 1')
            ->setStartDate(new DateTimeImmutable('2026-02-16'))->setEndDate(new DateTimeImmutable('2026-02-22'))
            ->setStatus(CalendarEntryStatus::ACTIVE)->setParentEntryId(self::ER_HOLIDAY_MOTHER);
        $em->persist($week);
        $this->erConstraint(32, ConstraintScope::TEAM, self::ER_TEAM_SM1, ConstraintFamily::DAY, ConstraintRuleType::PREFERRED, ['forbiddenDays' => [4]], self::ER_HOLIDAY_MOTHER);
        $planId = $this->planIdOf($week);

        // Plan ancien : sans copie des règles de bien-être, il lit celles de la saison.
        $em->getConnection()->executeStatement('DELETE FROM implicit_rule_setting WHERE schedule_plan_id = :p', ['p' => $planId]);
        $plan = $em->getRepository(SchedulePlan::class)->find($planId);
        Assert::assertInstanceOf(SchedulePlan::class, $plan);
        $plan->setTeamSelectionInitialized(true);

        $this->erTeamSetting(31, $planId, self::ER_TEAM_U13, false, null, null);
        $this->erTeamSetting(32, $planId, self::ER_TEAM_U15, true, 1, null);
        $this->erTeamSetting(33, $planId, self::ER_TEAM_SM1, true, null, 2);
        $this->erRuleSetting(31, $planId, self::erId('c057', 2), true);
        $this->erRuleSetting(32, $planId, self::ER_RULE_CLUB, false);
        $this->erVenueSetting(31, $planId, self::ER_VENUE_SUD, VenuePeriodMode::DISABLED, null);
        $this->erVenueSetting(32, $planId, self::ER_VENUE_NORD, null, [3 => 'CLOSED']);
        $em->flush();

        $this->erTeamAfterBirth(self::ER_TEAM_U17_HOLIDAY, 'U17 M');

        return $planId;
    }

    /**
     * Référence 13 : une fermeture née d'un socle qui mutualise U15 et SM1, puis réglée. Rend l'id du plan.
     */
    private function seedEngineRequestClosureSettings(): string
    {
        $em = $this->erEm();
        $this->erSocleBlock(self::ER_BLOCK_SOCLE, 41);
        $entry = (new CalendarEntry)->setId(self::ER_CLOSURE_SETTINGS)->setClubId(self::ER_CLUB)->setSeasonId(self::ER_SEASON)
            ->setKind(CalendarEntryKind::PERIOD)->setPeriodType(CalendarEntryPeriodType::CLOSURE)->setTitle('Travaux au gymnase Nord')
            ->setStartDate(new DateTimeImmutable('2026-03-09'))->setEndDate(new DateTimeImmutable('2026-03-15'))
            ->setStatus(CalendarEntryStatus::ACTIVE);
        $em->persist($entry);
        $this->erConstraint(41, ConstraintScope::FACILITY, self::ER_VENUE_NORD, ConstraintFamily::FACILITY, ConstraintRuleType::HARD, ['type' => 'venue_closed', 'startDate' => '2026-03-10', 'endDate' => '2026-03-11'], self::ER_CLOSURE_SETTINGS);
        $planId = $this->planIdOf($entry);
        $em->flush();

        // Le groupe hérité à la naissance porte un identifiant aléatoire, qui part au moteur : on le fixe.
        $connection = $em->getConnection();
        $copied = $connection->fetchFirstColumn('SELECT id FROM shared_training_block WHERE schedule_plan_id = :p', ['p' => $planId]);
        Assert::assertCount(1, $copied, 'la fermeture hérite du groupe mutualisé du socle à sa naissance');
        $connection->executeStatement('UPDATE shared_training_block SET id = :fixed WHERE id = :copy', ['fixed' => self::ER_BLOCK_CLOSURE, 'copy' => $copied[0]]);
        $connection->executeStatement('UPDATE shared_training_block_team SET block_id = :fixed WHERE block_id = :copy', ['fixed' => self::ER_BLOCK_CLOSURE, 'copy' => $copied[0]]);

        $this->erRuleSetting(41, $planId, self::erId('c057', 1), false);
        $this->erVenueSetting(41, $planId, self::ER_VENUE_NORD, VenuePeriodMode::BLANK, null);
        $em->flush();

        $this->erConstraint(42, ConstraintScope::TEAM, self::ER_TEAM_SM1, ConstraintFamily::TIME, ConstraintRuleType::PREFERRED, ['minStartTime' => '19:00']);
        $this->erTeamAfterBirth(self::ER_TEAM_U17_CLOSURE, 'U17 F');

        return $planId;
    }

    /** Un groupe mutualisé du socle : U15 et SM1, une séance commune. `$n` numérote ses deux membres. */
    private function erSocleBlock(string $blockId, int $n): void
    {
        $em = $this->erEm();
        $em->persist((new SharedTrainingBlock)->setId($blockId)->setClubId(self::ER_CLUB)->setSeasonId(self::ER_SEASON)
            ->setCommonSessions(1)->setLabel('U15 + SM1')->setSchedulePlanId($this->erSeasonPlanId()));
        foreach ([[$n, self::ER_TEAM_U15], [$n + 1, self::ER_TEAM_SM1]] as [$m, $teamId]) {
            $em->persist((new SharedTrainingBlockTeam)->setId(self::erId('5b17', $m))->setClubId(self::ER_CLUB)->setSeasonId(self::ER_SEASON)
                ->setBlockId($blockId)->setTeamId($teamId)->setSchedulePlanId($this->erSeasonPlanId()));
        }
        $em->flush();
    }

    /** Une règle de bien-être réglée à la saison : pas plus de 3 jours d'affilée, en préférence. */
    private function erSeasonImplicitRule(int $n): void
    {
        $this->erEm()->persist((new ImplicitRuleSetting)->setId(self::erId('a1e5', $n))->setClubId(self::ER_CLUB)->setSeasonId(self::ER_SEASON)
            ->setRuleKey(ImplicitRuleKey::MAX_CONSECUTIVE_DAYS)->setIntensity(ImplicitRuleIntensity::PREFERRED)->setParams(['maxConsecutiveDays' => 3])
            ->setSchedulePlanId($this->erSeasonPlanId()));
    }

    private function erTeamSetting(int $n, string $planId, string $teamId, bool $participates, ?int $sessions, ?int $maxPerDay): void
    {
        $this->erEm()->persist((new TeamPeriodOverride)->setId(self::erId('d7e0', $n))->setClubId(self::ER_CLUB)->setSeasonId(self::ER_SEASON)
            ->setSchedulePlanId($planId)->setTeamId($teamId)->setIsActive($participates)->setSessionsPerWeek($sessions)->setMaxSessionsPerDay($maxPerDay));
    }

    private function erRuleSetting(int $n, string $planId, string $constraintId, bool $applies): void
    {
        $this->erEm()->persist((new ConstraintPeriodOverride)->setId(self::erId('dc00', $n))->setClubId(self::ER_CLUB)->setSeasonId(self::ER_SEASON)
            ->setSchedulePlanId($planId)->setConstraintId($constraintId)->setIsActive($applies));
    }

    /** @param array<int, string>|null $dayOverrides */
    private function erVenueSetting(int $n, string $planId, string $venueId, ?VenuePeriodMode $mode, ?array $dayOverrides): void
    {
        $this->erEm()->persist((new VenuePeriodOverride)->setId(self::erId('dbe0', $n))->setClubId(self::ER_CLUB)->setSeasonId(self::ER_SEASON)
            ->setSchedulePlanId($planId)->setVenueId($venueId)->setMode($mode)->setDayOverrides($dayOverrides));
    }

    /** Une équipe créée après la naissance du plan de période, comme une saisie. */
    private function erTeamAfterBirth(string $teamId, string $name): void
    {
        $em = $this->erEm();
        $em->persist((new Team)->setId($teamId)->setClubId(self::ER_CLUB)->setSeasonId(self::ER_SEASON)
            ->setSportCategoryId(self::ER_CATEGORY)->setPriorityTierId(2)->setName($name)
            ->setSessionsPerWeek(2)->setIsActive(true));
        $em->flush();
    }
}
