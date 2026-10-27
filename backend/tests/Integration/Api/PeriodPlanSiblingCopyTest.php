<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\ConstraintPeriodOverride;
use App\Entity\ImplicitRuleSetting;
use App\Entity\Reservation;
use App\Entity\Schedule;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\Season;
use App\Entity\SharedTrainingBlock;
use App\Entity\SharedTrainingBlockTeam;
use App\Entity\TeamPeriodOverride;
use App\Entity\User;
use App\Entity\Venue;
use App\Entity\VenuePeriodOverride;
use App\Entity\VenueTrainingSlot;
use App\Enum\ImplicitRuleIntensity;
use App\Enum\ImplicitRuleKey;
use App\Enum\LockLevel;
use App\Enum\LockOrigin;
use App\Enum\ScheduleStatus;
use App\Enum\SeasonStatus;
use App\Enum\VenuePeriodMode;
use App\Service\OverlayManager;
use App\Service\PeriodPlanSiblingCopier;
use App\Service\SchedulePlanProvisioner;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\Security\PeriodCopyBirthTest;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * La COPIE SŒUR d'un plan de période (axe §7.1 *planning lifecycle*, fondateur 2026-10-27).
 *
 * Quand une fermeture prolongée enjambe des vacances, l'après-vacances (et chaque fin entamée)
 * n'est PAS une génération neuve : c'est une COPIE VALIDÉE d'office de la version en vigueur du
 * « milieu » source — deux runs ne partagent jamais un plan (inv. 4/C1), donc « même planning » = copie.
 *
 * CONTRAT ATTENDU (pour NEO) — `App\Service\PeriodPlanSiblingCopier` (patron `PeriodPlanTranscriber`) :
 *   copyInto(string $sourcePlanId, string $targetEntryId) : copie la version POINTÉE du plan SOURCE
 *   dans le plan de l'entrée cible, EN DUR (aucun solve), V1 COMPLETED portant un MARQUEUR de
 *   provenance (`Schedule::solverVersion`), slots identiques (verrous à ORIGINE vraie RESERVATION/MANUAL),
 *   pose le pointeur (`choose`) ET la source de verrous (`pointLockSource`), et copie les 8 familles
 *   ancrées-plan de {@see OverlayManager::purgePlanAnchoredSettings} ; la grille servie
 *   est celle du PLAN SOURCE (jamais celle de la saison). Source NON pointée → naissance VIDE annoncée
 *   (aucune version, le gestionnaire régénère).
 *
 * Doublon ASSUMÉ (domaine sensible) : la transcription depuis le SOCLE est gardée bloquante par
 * {@see PeriodCopyBirthTest} ; ici l'angle NOMMÉ est la copie SŒUR entre deux plans
 * de PÉRIODE (source = un plan de fermeture, pas le socle) — contresens que l'autre ne couvre pas.
 */
#[Group('phase1')]
#[Group('integration')]
final class PeriodPlanSiblingCopyTest extends WebTestCase
{
    use ChoosesPlanVersionTrait;
    use TenantGucTrait;

    /**
     * Les 8 familles ancrées-plan (OverlayManager::purgePlanAnchoredSettings), dans leur ordre.
     *
     * @var list<class-string>
     */
    private const array ANCHORED_FAMILIES = [
        TeamPeriodOverride::class,
        ConstraintPeriodOverride::class,
        VenueTrainingSlot::class,
        Reservation::class,
        VenuePeriodOverride::class,
        SharedTrainingBlock::class,
        SharedTrainingBlockTeam::class,
        ImplicitRuleSetting::class,
    ];

    private const string TEAM_ID = '88888888-8888-4888-8888-888888888888';

    private const string CONSTRAINT_ID = '66666666-6666-4666-8666-666666666666';

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    /** La copie sœur reproduit la version pointée + les 8 familles + la grille DU PLAN source. */
    public function testPointedSourceIsCopiedWholesaleIntoTheSiblingPlan(): void
    {
        [$user, $club, $season] = $this->createClubWithSeason();
        $venue = $this->venue($club, $season);
        // Un créneau SAISONNIER : le geste « Adapter » copie la grille de saison dans le plan (inv. 5).
        $this->seedSeasonGridSlot($club, $season, $venue->getId());

        // SOURCE : un plan de fermeture déjà VALIDÉ (version COMPLETED pointée) avec slots verrouillés,
        // sa grille propre (copiée de la saison à l'adaptation) et les familles ancrées.
        $sourceEntry = $this->adaptedPeriod($user, 'Barros en travaux (milieu)', '2026-03-02', '2026-03-15');
        $sourcePlanId = $this->planIdOf($club, $sourceEntry);
        $sourceVersionId = $this->seedPointedVersionWithLockedSlots($club, $season, $sourcePlanId, $venue->getId());
        $this->seedAnchoredFamilies($club, $season, $sourcePlanId, $venue->getId());
        $sourceGridDays = $this->gridDaysOf($club, $sourcePlanId);
        self::assertNotSame([], $sourceGridDays, 'la grille du plan source doit être non vide (sinon l’assertion serait creuse)');

        // CIBLE : la fenêtre sœur (après-vacances), avec son plan vierge.
        $targetEntry = $this->adaptedPeriod($user, 'Barros en travaux (après vacances)', '2026-03-30', '2026-04-05');
        $targetPlanId = $this->planIdOf($club, $targetEntry);

        self::getContainer()->get(PeriodPlanSiblingCopier::class)->copyInto($sourcePlanId, $targetEntry);

        // ── La V1 née : COMPLETED, pointée, source de verrous posée, marqueur de provenance. ──
        $this->scopeGucToClub($club->getId());
        $this->em->clear();
        $provisioner = self::getContainer()->get(SchedulePlanProvisioner::class);

        $chosen = $provisioner->chosenScheduleIdOfPlan($targetPlanId);
        self::assertNotNull($chosen, 'la copie sœur est VALIDÉE d’office (le plan cible pointe sa V1)');
        $born = $this->em->getRepository(Schedule::class)->find($chosen);
        self::assertInstanceOf(Schedule::class, $born);
        self::assertSame(ScheduleStatus::COMPLETED, $born->getStatus(), 'la copie est COMPLETED sans solve');
        self::assertNotNull($born->getSolverVersion(), 'la V1 copiée porte un marqueur de provenance');
        self::assertNotSame('', (string) $born->getSolverVersion());
        self::assertSame($chosen, $provisioner->lockSourceScheduleIdOfPlan($targetPlanId), 'la source de verrous pointe la V1 née');

        // ── Les slots : identiques à la source, verrou à ORIGINE vraie (jamais UNKNOWN). ──
        $sourceSlots = $this->slotSignature($club, $sourceVersionId);
        $copiedSlots = $this->slotSignature($club, $chosen);
        self::assertSame($sourceSlots, $copiedSlots, 'les placements copiés == ceux de la version source');
        self::assertContains(LockOrigin::MANUAL->value, $this->distinctLockOrigins($club, $chosen), 'les verrous MANUAL de la source sont conservés');
        self::assertContains(LockOrigin::RESERVATION->value, $this->distinctLockOrigins($club, $chosen), 'les verrous RESERVATION de la source sont conservés');

        // ── Les 8 familles ancrées-plan sont copiées sur le plan cible (grain par grain). ──
        foreach (self::ANCHORED_FAMILIES as $family) {
            self::assertSame(
                $this->countAnchored($club, $family, $sourcePlanId),
                $this->countAnchored($club, $family, $targetPlanId),
                \sprintf('la famille %s est copiée sur le plan sœur', $family),
            );
        }

        // ── La grille servie au plan cible est celle du PLAN SOURCE (jamais celle de la saison). ──
        self::assertSame($sourceGridDays, $this->gridDaysOf($club, $targetPlanId), 'la copie reprend la grille du PLAN source');
    }

    /** Source NON pointée (espace de travail) → naissance VIDE : le plan cible reste sans version. */
    public function testUnpointedSourceYieldsAnEmptyBirth(): void
    {
        [$user, $club] = $this->createClubWithSeason();

        // SOURCE adaptée mais JAMAIS validée (chosen null).
        $sourceEntry = $this->adaptedPeriod($user, 'Barros (jamais validé)', '2026-03-02', '2026-03-15');
        $sourcePlanId = $this->planIdOf($club, $sourceEntry);

        $targetEntry = $this->adaptedPeriod($user, 'Barros (après)', '2026-03-30', '2026-04-05');
        $targetPlanId = $this->planIdOf($club, $targetEntry);

        self::getContainer()->get(PeriodPlanSiblingCopier::class)->copyInto($sourcePlanId, $targetEntry);

        $this->scopeGucToClub($club->getId());
        self::assertSame(
            0,
            (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM schedule WHERE schedule_plan_id = :pid', ['pid' => $targetPlanId]),
            'une source non pointée ne descend aucune version : naissance VIDE (le gestionnaire régénère)',
        );
        self::assertNull(self::getContainer()->get(SchedulePlanProvisioner::class)->chosenScheduleIdOfPlan($targetPlanId), 'rien n’est pointé sur une naissance vide');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    private function adaptedPeriod(User $user, string $title, string $start, string $end): string
    {
        $this->client->request('POST', '/api/calendar_entries', [], [], $this->authHeaders($user) + [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['kind' => 'period', 'title' => $title, 'startDate' => $start, 'endDate' => $end, 'periodType' => 'closure'], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201, (string) $this->client->getResponse()->getContent());
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        $entryId = (string) $payload['id'];

        $this->client->request('POST', '/api/schedule_plans', [], [], $this->authHeaders($user) + [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['calendarEntryId' => $entryId], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);

        return $entryId;
    }

    private function planIdOf(Club $club, string $entryId): string
    {
        $this->scopeGucToClub($club->getId());
        $id = $this->em->getConnection()->fetchOne('SELECT id FROM schedule_plan WHERE calendar_entry_id = :eid', ['eid' => $entryId]);
        self::assertNotFalse($id, 'la période adaptée doit porter un plan');

        return (string) $id;
    }

    private function seedPointedVersionWithLockedSlots(Club $club, Season $season, string $planId, string $venueId): string
    {
        $this->scopeGucToClub($club->getId());
        $version = (new Schedule)
            ->setClubId($club->getId())
            ->setSeasonId($season->getId())
            ->setSchedulePlanId($planId)
            ->setName('V1')
            ->setStatus(ScheduleStatus::COMPLETED);
        $this->em->persist($version);
        self::getContainer()->get(SchedulePlanProvisioner::class)->linkSchedule($version);

        // Un slot MANUAL et un slot RESERVATION : les deux origines de verrou à préserver.
        foreach ([[1, '18:00', LockOrigin::MANUAL], [3, '19:00', LockOrigin::RESERVATION]] as $slot) {
            $tpl = (new ScheduleSlotTemplate)
                ->setClubId($club->getId())
                ->setSeasonId($season->getId())
                ->setScheduleId($version->getId())
                ->setTeamId(self::TEAM_ID)
                ->setVenueId($venueId)
                ->setDayOfWeek($slot[0])
                ->setStartTime(new DateTimeImmutable($slot[1]))
                ->setDurationMinutes(90)
                ->setLockLevel(LockLevel::HARD)
                ->setLockOrigin($slot[2]);
            $this->em->persist($tpl);
        }
        $this->em->flush();
        $this->choosePlanVersion($version);

        return $version->getId();
    }

    /**
     * Les familles ancrées que le geste « Adapter » ne crée PAS tout seul (la grille `VenueTrainingSlot`
     * et la copie des 5 `ImplicitRuleSetting` naissent déjà à l'adaptation ; on ne les re-seed pas,
     * sinon collision sur leur index d'unicité). On dévie une déviation spécifique sur une règle
     * bien-être pour prouver que la copie reprend la VALEUR, pas seulement le nombre.
     */
    private function seedAnchoredFamilies(Club $club, Season $season, string $planId, string $venueId): void
    {
        $this->scopeGucToClub($club->getId());
        $clubId = $club->getId();
        $seasonId = $season->getId();

        $this->em->persist((new TeamPeriodOverride)->setClubId($clubId)->setSeasonId($seasonId)->setSchedulePlanId($planId)->setTeamId(self::TEAM_ID)->setIsActive(false)->setSessionsPerWeek(null));
        $this->em->persist((new ConstraintPeriodOverride)->setClubId($clubId)->setSeasonId($seasonId)->setSchedulePlanId($planId)->setConstraintId(self::CONSTRAINT_ID)->setIsActive(false));
        $this->em->persist((new Reservation)->setClubId($clubId)->setSeasonId($seasonId)->setSchedulePlanId($planId)->setTeamId(self::TEAM_ID)->setVenueId($venueId)->setDayOfWeek(3)->setStartTime(new DateTimeImmutable('19:00'))->setDurationMinutes(90));
        $this->em->persist((new VenuePeriodOverride)->setClubId($clubId)->setSeasonId($seasonId)->setSchedulePlanId($planId)->setVenueId($venueId)->setMode(VenuePeriodMode::DISABLED));

        $block = (new SharedTrainingBlock)->setClubId($clubId)->setSeasonId($seasonId)->setSchedulePlanId($planId)->setCommonSessions(1);
        $this->em->persist($block);
        $this->em->flush();
        $this->em->persist((new SharedTrainingBlockTeam)->setClubId($clubId)->setSeasonId($seasonId)->setSchedulePlanId($planId)->setBlockId($block->getId())->setTeamId(self::TEAM_ID));
        $this->em->flush();

        // Déviation d'une règle bien-être DÉJÀ matérialisée par l'adaptation (pas d'insert : UPDATE).
        $rule = $this->em->getRepository(ImplicitRuleSetting::class)->findOneBy(['schedulePlanId' => $planId, 'ruleKey' => ImplicitRuleKey::COACH_REST_DAY]);
        if ($rule instanceof ImplicitRuleSetting) {
            $rule->setIntensity(ImplicitRuleIntensity::PREFERRED)->setParams(['minRestDays' => 2]);
            $this->em->flush();
        }
    }

    private function seedSeasonGridSlot(Club $club, Season $season, string $venueId): void
    {
        $this->scopeGucToClub($club->getId());
        $slot = (new VenueTrainingSlot)
            ->setClubId($club->getId())
            ->setSeasonId($season->getId())
            ->setVenueId($venueId)
            ->setDayOfWeek(4)
            ->setStartTime(new DateTimeImmutable('17:30'))
            ->setDurationMinutes(90)
            ->setCapacity(1)
            ->setSchedulePlanId(null);
        $this->em->persist($slot);
        $this->em->flush();
    }

    /** @param class-string $family */
    private function countAnchored(Club $club, string $family, string $planId): int
    {
        $this->scopeGucToClub($club->getId());
        $this->em->clear();

        return \count($this->em->getRepository($family)->findBy(['schedulePlanId' => $planId]));
    }

    /** @return list<int> les jours de la grille (VenueTrainingSlot) d'un plan, triés */
    private function gridDaysOf(Club $club, string $planId): array
    {
        $this->scopeGucToClub($club->getId());
        $rows = $this->em->getConnection()->fetchFirstColumn('SELECT day_of_week FROM venue_training_slot WHERE schedule_plan_id = :pid', ['pid' => $planId]);
        $days = array_map(static fn (mixed $v): int => (int) $v, $rows);
        sort($days);

        return $days;
    }

    /** @return list<string> la signature triée des placements d'une version (team:venue:jour:HH:MM:lock) */
    private function slotSignature(Club $club, string $scheduleId): array
    {
        $this->scopeGucToClub($club->getId());
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT team_id, venue_id, day_of_week, start_time, lock_level FROM schedule_slot_template WHERE schedule_id = :sid',
            ['sid' => $scheduleId],
        );
        $sig = array_map(
            static fn (array $r): string => \sprintf('%s:%s:%s:%s:%s', $r['team_id'], $r['venue_id'], $r['day_of_week'], mb_substr((string) $r['start_time'], 0, 5), $r['lock_level']),
            $rows,
        );
        sort($sig);

        return $sig;
    }

    /** @return list<string> les origines de verrou distinctes */
    private function distinctLockOrigins(Club $club, string $scheduleId): array
    {
        $this->scopeGucToClub($club->getId());
        $rows = $this->em->getConnection()->fetchFirstColumn('SELECT DISTINCT lock_origin FROM schedule_slot_template WHERE schedule_id = :sid AND lock_origin IS NOT NULL', ['sid' => $scheduleId]);

        return array_map(static fn (mixed $v): string => (string) $v, $rows);
    }

    /**
     * @return array{0: User, 1: Club, 2: Season}
     */
    private function createClubWithSeason(): array
    {
        $uid = uniqid('', true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $club = new Club;
        $club->setName('Club copie sœur');
        $club->setSlug('club-sib-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode('SIB' . strtoupper(substr(md5($uid), 0, 10)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('sib' . $uid . '@test.com');
        $user->setFirstName('Si');
        $user->setLastName('Bling');
        $user->setPasswordHash($hasher->hashPassword($user, 'pass'));
        $this->em->persist($user);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());

        $membership = new ClubUser;
        $membership->setClubId($club->getId());
        $membership->setUserId($user->getId());
        $membership->setRole('admin');
        $membership->setIsActive(true);
        $this->em->persist($membership);

        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName('2025-2026');
        $season->setStartDate(new DateTimeImmutable('2025-08-01'));
        $season->setEndDate(new DateTimeImmutable('2026-07-15'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $season->setTransitionData([]);
        $this->em->persist($season);
        $this->em->flush();

        self::getContainer()->get(SchedulePlanProvisioner::class)->ensureSeasonPlan($season);
        $this->em->flush();

        return [$user, $club, $season];
    }

    private function venue(Club $club, Season $season): Venue
    {
        $this->scopeGucToClub($club->getId());
        $venue = new Venue;
        $venue->setClubId($club->getId());
        $venue->setSeasonId($season->getId());
        $venue->setName('Gymnase de la copie');
        $venue->setSource('manual');
        $this->em->persist($venue);
        $this->em->flush();

        return $venue;
    }

    /**
     * @return array{HTTP_AUTHORIZATION: string}
     */
    private function authHeaders(User $user): array
    {
        $token = self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);

        return ['HTTP_AUTHORIZATION' => 'Bearer ' . $token];
    }
}
