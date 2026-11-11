<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Schedule;
use App\Entity\SchedulePlan;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\CalendarEntryStatus;
use App\Enum\SchedulePlanType;
use App\Enum\ScheduleStatus;
use App\Enum\SeasonStatus;
use App\Service\SchedulePlanProvisioner;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Assert;

/**
 * Le décor commun de la validation automatique d'un planning de période : un club (réel ou
 * démo, avec son fuseau), ses saisons, une période (fermeture ou vacances) qui porte son plan,
 * et les versions de ce plan — semés comme la production les pose (le plan naît du geste via
 * `provisionPeriodPlan`, chaque version est numérotée par `linkSchedule`).
 *
 * Partagé par `Security/PeriodPlanAutoValidationTest` (NR), `Command/PeriodPlanAutoValidateCommandTest`,
 * `Integration/Api/ClubClockSweepTest` et `Integration/Api/SchedulePlanAlertSeenTest`.
 *
 * Seed sous RLS : chaque écriture re-scope le GUC sur le club semé ({@see TenantGucTrait}).
 */
trait PeriodPlanAutoValidationDecorTrait
{
    use ChoosesPlanVersionTrait;
    use TenantGucTrait;

    /**
     * Un club avec un gestionnaire actif et ses saisons (mono-saison par défaut : elle est alors
     * la saison courante, quel que soit le jour). `$simulatedToday` fait du club une DÉMO à
     * l'horloge posée (la contrainte CHECK refuse une date simulée sur un vrai club).
     *
     * @param list<array{0: string, 1: string}> $seasons [début, fin] par saison, dans l'ordre
     *
     * @return array{club: Club, seasons: list<Season>, manager: User}
     */
    private function seedDecorClub(
        string $tag,
        string $timezone = 'Europe/Paris',
        ?string $simulatedToday = null,
        array $seasons = [['2026-07-15', '2027-07-14']],
        bool $demo = false,
    ): array {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $uid = substr(md5(uniqid('', true)), 0, 10);

        $club = new Club;
        $club->setName('Club ' . $tag . ' ' . $uid);
        $club->setSlug('club-' . strtolower($tag) . '-' . $uid);
        $club->setTimezone($timezone);
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode(strtoupper(substr($tag, 0, 3)) . strtoupper(substr($uid, 0, 7)));
        $club->setIsDemo($demo || null !== $simulatedToday);
        $club->setSimulatedToday(null === $simulatedToday ? null : new DateTimeImmutable($simulatedToday));
        $em->persist($club);
        $em->flush();

        $manager = $this->seedDecorMember($club, 'admin');

        $seeded = [];
        foreach ($seasons as [$start, $end]) {
            $this->scopeGucToClub($club->getId());
            $season = new Season;
            $season->setClubId($club->getId());
            $season->setName(substr($start, 0, 4) . '-' . substr($end, 0, 4) . ' ' . $uid);
            $season->setStartDate(new DateTimeImmutable($start));
            $season->setEndDate(new DateTimeImmutable($end));
            $season->setStatus(SeasonStatus::ACTIVE);
            $em->persist($season);
            $em->flush();
            $this->provisionSeasonPlan($season);
            $seeded[] = $season;
        }

        return ['club' => $club, 'seasons' => $seeded, 'manager' => $manager];
    }

    /** Un membre actif du club, au rôle donné (`admin` = gestionnaire, `member` = lecture). */
    private function seedDecorMember(Club $club, string $role): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get('security.user_password_hasher');

        $user = new User;
        $user->setEmail($role . '-' . substr(md5(uniqid('', true)), 0, 12) . '@auto-validation.test');
        $user->setFirstName('Gestion');
        $user->setLastName('Période');
        $user->setPasswordHash($hasher->hashPassword($user, 'Password123!'));
        $em->persist($user);
        $em->flush();

        $this->scopeGucToClub($club->getId());
        $membership = new ClubUser;
        $membership->setClubId($club->getId());
        $membership->setUserId($user->getId());
        $membership->setRole($role);
        $membership->setIsActive(true);
        $em->persist($membership);
        $em->flush();

        return $user;
    }

    private function decorToken(User $user): string
    {
        return static::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
    }

    /**
     * Une période (racine par défaut, ou semaine-enfant d'une mère découpée) et SON plan, né du
     * geste. Dates civiles `Y-m-d`.
     *
     * @return array{entryId: string, planId: string}
     */
    private function seedDecorPeriod(
        Season $season,
        string $start,
        string $end,
        CalendarEntryPeriodType $type = CalendarEntryPeriodType::HOLIDAY,
        CalendarEntryStatus $status = CalendarEntryStatus::ACTIVE,
        ?string $parentEntryId = null,
        string $title = 'Vacances de Noël',
    ): array {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->scopeGucToClub($season->getClubId());

        $entry = new CalendarEntry;
        $entry->setClubId($season->getClubId());
        $entry->setSeasonId($season->getId());
        $entry->setKind(CalendarEntryKind::PERIOD);
        $entry->setPeriodType($type);
        $entry->setStatus($status);
        $entry->setTitle($title);
        $entry->setStartDate(new DateTimeImmutable($start));
        $entry->setEndDate(new DateTimeImmutable($end));
        $entry->setParentEntryId($parentEntryId);
        $em->persist($entry);
        $em->flush();

        $planId = static::getContainer()->get(SchedulePlanProvisioner::class)->provisionPeriodPlan($entry->getId());
        Assert::assertIsString($planId, 'décor : la période doit porter son plan');

        return ['entryId' => $entry->getId(), 'planId' => $planId];
    }

    /**
     * Même décor (période + plan + UNE version terminée) SANS jamais prendre le verrou de
     * plan-scope de l'entrée (`schedule-plan-link:<entryId>`) : `provisionPeriodPlan` et
     * `linkSchedule` le prennent en verrou de TRANSACTION, que la transaction DAMA du test garderait
     * jusqu'à la fin — une seconde session ne pourrait alors plus le tenir pour provoquer l'échec
     * du balayage DANS la transaction du club (elle attendrait le test, qui l'attend : interblocage).
     * Le plan et la version sont donc posés à l'EntityManager, version déjà numérotée.
     *
     * @return array{entryId: string, planId: string, scheduleId: string}
     */
    private function seedDecorPeriodWithoutPlanScopeLock(Season $season, string $start, string $end): array
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->scopeGucToClub($season->getClubId());

        $entry = new CalendarEntry;
        $entry->setClubId($season->getClubId());
        $entry->setSeasonId($season->getId());
        $entry->setKind(CalendarEntryKind::PERIOD);
        $entry->setPeriodType(CalendarEntryPeriodType::HOLIDAY);
        $entry->setStatus(CalendarEntryStatus::ACTIVE);
        $entry->setTitle('Vacances verrouillées par un concurrent');
        $entry->setStartDate(new DateTimeImmutable($start));
        $entry->setEndDate(new DateTimeImmutable($end));
        $em->persist($entry);

        $plan = new SchedulePlan;
        $plan->setClubId($season->getClubId());
        $plan->setSeasonId($season->getId());
        $plan->setType(SchedulePlanType::HOLIDAY);
        $plan->setName('Vacances verrouillées par un concurrent');
        $plan->setStartDate(new DateTimeImmutable($start));
        $plan->setEndDate(new DateTimeImmutable($end));
        $plan->setCalendarEntryId($entry->getId());
        $em->persist($plan);
        $em->flush();

        $schedule = (new Schedule)
            ->setClubId($season->getClubId())
            ->setSeasonId($season->getId())
            ->setName('Version terminée')
            ->setStatus(ScheduleStatus::COMPLETED)
            ->setSchedulePlanId($plan->getId())
            ->setVersionNumber(1);
        $em->persist($schedule);
        $em->flush();

        return ['entryId' => $entry->getId(), 'planId' => $plan->getId(), 'scheduleId' => $schedule->getId()];
    }

    /** Une version du plan de la période, au statut donné ; rend son identifiant. */
    private function seedDecorVersion(Season $season, string $entryId, ScheduleStatus $status = ScheduleStatus::COMPLETED, ?string $snapshotHash = null): string
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->scopeGucToClub($season->getClubId());

        $schedule = (new Schedule)
            ->setClubId($season->getClubId())
            ->setSeasonId($season->getId())
            ->setName('Version ' . $status->value)
            ->setStatus($status)
            ->setSnapshotHash($snapshotHash);
        $this->linkSeededSchedule($schedule, $entryId);
        $em->flush();

        return $schedule->getId();
    }

    /** Le pointeur du plan (la version validée), lu en SQL sous le GUC du club. */
    private function decorChosenOf(string $clubId, string $planId): ?string
    {
        $this->scopeGucToClub($clubId);
        $value = static::getContainer()->get(EntityManagerInterface::class)->getConnection()
            ->fetchOne('SELECT chosen_schedule_id FROM schedule_plan WHERE id = :id', ['id' => $planId]);

        return \is_string($value) ? $value : null;
    }

    /** La première validation HUMAINE du plan (stat « temps de clôture ») — jamais posée par l'auto. */
    private function decorFirstChosenAtOf(string $clubId, string $planId): ?string
    {
        $this->scopeGucToClub($clubId);
        $value = static::getContainer()->get(EntityManagerInterface::class)->getConnection()
            ->fetchOne('SELECT first_chosen_at FROM schedule_plan WHERE id = :id', ['id' => $planId]);

        return \is_string($value) ? $value : null;
    }

    private function decorVersionExists(string $clubId, string $scheduleId): bool
    {
        $this->scopeGucToClub($clubId);

        return 1 === (int) static::getContainer()->get(EntityManagerInterface::class)->getConnection()
            ->fetchOne('SELECT COUNT(*) FROM schedule WHERE id = :id', ['id' => $scheduleId]);
    }

    /** La date civile `Y-m-d` d'aujourd'hui décalée de `$days` jours (horloge du test). */
    private function decorDay(int $days): string
    {
        return new DateTimeImmutable('today')->modify(\sprintf('%+d days', $days))->format('Y-m-d');
    }
}
