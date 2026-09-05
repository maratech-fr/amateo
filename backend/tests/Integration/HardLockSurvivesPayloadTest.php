<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Constraint;
use App\Entity\Schedule;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\Season;
use App\Entity\Team;
use App\Entity\User;
use App\Entity\Venue;
use App\Enum\ConstraintFamily;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use App\Enum\LockLevel;
use App\Enum\LockOrigin;
use App\Enum\ScheduleStatus;
use App\Enum\SeasonStatus;
use App\Service\ScheduleConstraintBuilder;
use App\Tests\ProvisionsPeriodPlanTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * « Un verrou est une vérité absolue » (axes constraint semantics + planning lifecycle).
 *
 * Décision fondateur (2026-09-05) : un verrou HARD GAGNE toujours ; les règles qu'il enfreint sont
 * SIGNALÉES, le créneau reste. Le moteur est conçu ainsi — il fixe le verrou HORS du solveur puis
 * diagnostique les règles violées (`engine/app/solver/model.py`). Encore faut-il que le rail réel de
 * (re)génération TRANSPORTE le verrou jusqu'au moteur : la relocalisation qu'un observateur avait
 * cru voir ne pouvait venir que d'un payload arrivant SANS le verrou.
 *
 * Ce test garde la composition du payload par `ScheduleConstraintBuilder::buildForClubSeason` (le
 * chemin d'une génération de socle) : face à un créneau verrouillé HARD ET une règle de jours HARD
 * qui le CONTREDIT, LES DEUX voyagent — le créneau sort avec `lockLevel: HARD`, la règle sort dans
 * les `constraints`. Le builder n'arbitre pas, ne dégrade pas le verrou, ne retire pas la règle en
 * amont : il émet la vérité, et c'est le moteur qui tranche (le verrou l'emporte, la règle est
 * diagnostiquée). Sans ce test, un filtre qui laisserait tomber le `lockLevel` — ou la règle —
 * repasserait en silence, et la relocalisation reviendrait.
 */
#[Group('phase1')]
#[Group('integration')]
final class HardLockSurvivesPayloadTest extends KernelTestCase
{
    use ProvisionsPeriodPlanTrait;

    use TenantGucTrait;

    private const string VENUE_ID = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    /** Mercredi (ISO) — le jour du verrou, et le jour que la règle interdit. */
    private const int LOCK_DAY = 3;

    private EntityManagerInterface $em;

    private ScheduleConstraintBuilder $builder;

    public function testHardLockAndTheDayRuleThatContradictsItBothReachTheEngine(): void
    {
        [$club, $season] = $this->seed();
        $team = $this->team($club, $season, 'SM1');
        $this->venue($club, $season, self::VENUE_ID, 'Gymnase');
        $base = $this->baseSchedule($club, $season);
        $this->hardLockedSlot($base, $team, self::VENUE_ID, self::LOCK_DAY);
        // Une règle HARD forbiddenDays qui interdit EXACTEMENT le jour du verrou : elle le contredit.
        $this->forbiddenDaysRule($club, $season, $team, [self::LOCK_DAY]);
        $this->em->flush();

        // Le payload est servi du cache AVANT tout calcul : sans ce purge, un hit rendrait le test
        // creux (vert sans jamais reconstruire le payload avec le verrou et la règle).
        self::getContainer()->get('cache.schedule')->deleteItem(ScheduleConstraintBuilder::cacheKey($club->getId(), $season->getId()));

        $payload = $this->builder->buildForClubSeason($club->getId(), $season->getId());

        // (1) Le verrou VOYAGE — le créneau de l'équipe sort avec lockLevel HARD. C'est CE fait qui
        // garantit que le moteur le fixera hors solveur : un lockLevel dégradé en amont, et le
        // solveur serait libre de relocaliser le créneau (le défaut soupçonné, ici falsifiable).
        $slotsForTeam = array_values(array_filter(
            $this->rows($payload, 'slotTemplates'),
            static fn (array $s): bool => ($s['teamId'] ?? null) === $team->getId(),
        ));
        self::assertCount(1, $slotsForTeam, 'le créneau verrouillé de l’équipe doit être émis au moteur');
        self::assertSame(LockLevel::HARD->value, $slotsForTeam[0]['lockLevel'] ?? null, 'le verrou HARD doit survivre au payload — sinon le solveur peut relocaliser le créneau');
        self::assertSame(self::LOCK_DAY, $slotsForTeam[0]['dayOfWeek'] ?? null, 'le créneau est émis à son jour, celui-là même que la règle interdit');

        // (2) La règle contraire VOYAGE elle aussi — le builder ne la retire pas parce qu'un verrou
        // la contredit. Les deux arrivent au moteur, qui arbitre (le verrou gagne, la règle est
        // diagnostiquée). Retirer la règle en amont priverait le gestionnaire de son signalement.
        $dayRulesForTeam = array_values(array_filter(
            $this->rows($payload, 'constraints'),
            fn (array $c): bool => ConstraintFamily::DAY->value === ($c['family'] ?? null)
                && ($c['scopeTargetId'] ?? null) === $team->getId()
                && \in_array(self::LOCK_DAY, $this->intList($c['config']['forbiddenDays'] ?? []), true),
        ));
        self::assertCount(1, $dayRulesForTeam, 'la règle de jours contraire doit voyager avec le verrou : le moteur arbitre, elle n’est jamais retirée en amont');
        self::assertSame(ConstraintRuleType::HARD->value, $dayRulesForTeam[0]['ruleType'] ?? null, 'la règle contraire garde son caractère HARD dans le payload');
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->builder = self::getContainer()->get(ScheduleConstraintBuilder::class);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<array<string, mixed>>
     */
    private function rows(array $payload, string $key): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = array_values(array_filter((array) ($payload[$key] ?? []), 'is_array'));

        return $rows;
    }

    /**
     * @return list<int>
     */
    private function intList(mixed $value): array
    {
        return array_map(intval(...), array_values((array) $value));
    }

    private function baseSchedule(Club $club, Season $season): Schedule
    {
        $schedule = new Schedule;
        $schedule->setClubId($club->getId());
        $schedule->setSeasonId($season->getId());
        $schedule->setName('Socle');
        $schedule->setStatus(ScheduleStatus::COMPLETED);
        // C4 : une version de BASE (le socle) est ancrée au plan SEASON de sa saison —
        // findBaseSlotTemplates ne prend QUE les créneaux des versions de ce plan.
        $schedule->setSchedulePlanId($this->seasonPlanIdOf($season));
        $this->em->persist($schedule);

        return $schedule;
    }

    private function hardLockedSlot(Schedule $schedule, Team $team, string $venueId, int $dayOfWeek): void
    {
        $slot = new ScheduleSlotTemplate;
        $slot->setClubId($schedule->getClubId());
        $slot->setSeasonId($schedule->getSeasonId());
        $slot->setScheduleId($schedule->getId());
        $slot->setTeamId($team->getId());
        $slot->setVenueId($venueId);
        $slot->setDayOfWeek($dayOfWeek);
        $slot->setStartTime(new DateTimeImmutable('18:00'));
        $slot->setDurationMinutes(90);
        $slot->setLockLevel(LockLevel::HARD);
        $slot->setLockOrigin(LockOrigin::MANUAL);
        $this->em->persist($slot);
    }

    /**
     * @param list<int> $forbiddenDays
     */
    private function forbiddenDaysRule(Club $club, Season $season, Team $team, array $forbiddenDays): void
    {
        $c = new Constraint;
        $c->setClubId($club->getId());
        $c->setSeasonId($season->getId());
        $c->setName('Jour interdit qui contredit le verrou');
        $c->setScope(ConstraintScope::TEAM);
        $c->setScopeTargetId($team->getId());
        $c->setFamily(ConstraintFamily::DAY);
        $c->setRuleType(ConstraintRuleType::HARD);
        $c->setConfig(['forbiddenDays' => $forbiddenDays]);
        $this->em->persist($c);
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

    private function venue(Club $club, Season $season, string $id, string $name): void
    {
        $venue = new Venue;
        $venue->setId($id);
        $venue->setClubId($club->getId());
        $venue->setSeasonId($season->getId());
        $venue->setName($name);
        $venue->setCanSplit(false);
        $venue->setSource('manual');
        $this->em->persist($venue);
    }

    /**
     * @return array{0: Club, 1: Season}
     */
    private function seed(): array
    {
        $uid = uniqid('', true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $club = new Club;
        $club->setName('Verrou Club');
        $club->setSlug('verrou-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode('VER' . strtoupper(substr(md5($uid), 0, 8)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('verrou-' . $uid . '@test.com');
        $user->setFirstName('V');
        $user->setLastName('R');
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
