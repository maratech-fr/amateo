<?php

declare(strict_types=1);

namespace App\Tests;

use App\Clock\DevClockStore;
use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Reservation;
use App\Entity\Schedule;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\Season;
use App\Entity\Team;
use App\Entity\User;
use App\Entity\Venue;
use App\Entity\VenueTrainingSlot;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\CalendarEntryStatus;
use App\Enum\LockLevel;
use App\Enum\LockOrigin;
use App\Enum\ScheduleStatus;
use App\Enum\SeasonStatus;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Assert;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Décor PARTAGÉ du chantier « Fermetures de gymnase » (« Enregistrer vaut geste ») : le parcours G du
 * plan, réduit à ce qui décide. Un club, une saison 2025-2026, deux gymnases (Mistral, Brel), deux
 * équipes (SM1, U13), un socle POINTÉ qui porte lui-même un verrou HARD à Mistral le mardi, et des
 * vacances « Vacances de mars » (semaine +8 depuis l'horloge pinnée) dont la version V1 est VALIDÉE :
 *
 *   - U13 mardi 18:00 à Mistral, HARD manuel      → doit partir quand Mistral ferme le mardi 3/3 ;
 *   - U13 jeudi 18:00 à Mistral, HARD manuel      → doit rester (autre jour) ;
 *   - SM1 mardi 18:00 à Brel, HARD manuel         → doit rester (autre gymnase) ;
 *   - SM1 mardi 19:30 à Mistral, placement NONE   → n'est pas un verrou (rien à retirer) ;
 *   - réservation SM1 mardi 19:30 à Mistral       → doit partir.
 *
 * L'horloge du club est PINNÉE sur le lundi 2026-01-05 (`DevClockStore`) : toutes les dates se
 * dérivent de là, jamais du calendrier réel (pas de bombe à date). Le test qui `use` ce trait
 * appelle `pinDecorClock()` en setUp et `unpinDecorClock()` en tearDown.
 *
 * Consommé par `Security/ClosureRemovesPinsAtomicallyTest`, `Security/StalePeriodPlanRegenerationTest`,
 * `Security/ClosureImpactSaysTheSameEverywhereTest` et `Integration/Service/ClosurePinRemoverTest`.
 */
trait ClosurePinDecorTrait
{
    use ChoosesPlanVersionTrait;
    use ProvisionsPeriodPlanTrait;
    use TenantGucTrait;

    /** Lundi de référence de l'horloge pinnée. */
    private static string $decorToday = '2026-01-05T12:00:00+00:00';

    private function pinDecorClock(): void
    {
        static::getContainer()->get(DevClockStore::class)->set(new DateTimeImmutable(self::$decorToday));
    }

    private function unpinDecorClock(): void
    {
        static::getContainer()->get(DevClockStore::class)->set(null);
    }

    /** Lundi de la semaine +n depuis l'horloge pinnée, Y-m-d. */
    private function decorMonday(int $n): string
    {
        return new DateTimeImmutable(self::$decorToday)->modify(\sprintf('%+d days', 7 * $n))->format('Y-m-d');
    }

    /** Jour ISO `$isoDay` (1 = lundi … 7 = dimanche) de la semaine +n, Y-m-d. */
    private function decorDay(int $n, int $isoDay): string
    {
        return new DateTimeImmutable(self::$decorToday)->modify(\sprintf('%+d days', 7 * $n + $isoDay - 1))->format('Y-m-d');
    }

    /**
     * @return array{
     *   club: Club, user: User, season: Season, mistral: string, brel: string, sm1: string, u13: string,
     *   socleVersionId: string, socleLockTueMistral: string,
     *   holidayEntryId: string, holidayPlanId: string, holidayVersionId: string,
     *   lockTueMistral: string, lockThuMistral: string, lockTueBrel: string, placementTueMistral: string,
     *   reservationTueMistral: string, closedTuesday: string
     * }
     */
    private function seedClosurePinDecor(string $label = 'CPD'): array
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get('security.user_password_hasher');
        $uid = uniqid('', true);

        $club = (new Club)->setName($label . ' ' . $uid)->setSlug(strtolower($label) . '-' . str_replace('.', '', $uid))
            ->setTimezone('Europe/Paris')->setLocale('fr')->setOnboardingCompleted(true);
        $em->persist($club);
        $user = (new User)->setEmail(strtolower($label) . str_replace('.', '', $uid) . '@test.com')->setFirstName('Ges')->setLastName('Tion');
        $user->setPasswordHash($hasher->hashPassword($user, 'Password123!'));
        $em->persist($user);
        $em->flush();

        $this->scopeGucToClub($club->getId());
        $em->persist((new ClubUser)->setClubId($club->getId())->setUserId($user->getId())->setRole('admin')->setIsActive(true));
        $season = (new Season)->setClubId($club->getId())->setName('2025-2026')
            ->setStartDate(new DateTimeImmutable('2025-08-01'))->setEndDate(new DateTimeImmutable('2026-07-15'))
            ->setStatus(SeasonStatus::ACTIVE)->setTransitionData([]);
        $em->persist($season);
        $em->flush();
        $this->provisionSeasonPlan($season);

        $mistral = (new Venue)->setClubId($club->getId())->setSeasonId($season->getId())->setName('Mistral')->setSource('manual')->setIsActive(true);
        $brel = (new Venue)->setClubId($club->getId())->setSeasonId($season->getId())->setName('Brel')->setSource('manual')->setIsActive(true);
        $em->persist($mistral);
        $em->persist($brel);
        $sm1 = $this->decorTeam($club, $season, 'SM1', 1);
        $u13 = $this->decorTeam($club, $season, 'U13', 3);
        $em->flush();

        // Grille de SAISON — la période en prend COPIE à la naissance de son plan (ADR-0002).
        foreach ([[$mistral, 2, '18:00'], [$mistral, 2, '19:30'], [$mistral, 4, '18:00'], [$brel, 2, '18:00']] as [$venue, $day, $time]) {
            $em->persist((new VenueTrainingSlot)->setClubId($club->getId())->setSeasonId($season->getId())
                ->setVenueId($venue->getId())->setDayOfWeek($day)
                ->setStartTime(new DateTimeImmutable($time))->setDurationMinutes(90)->setCapacity(1));
        }
        $em->flush();

        // Socle POINTÉ, qui porte lui-même un verrou HARD à Mistral le mardi : il ne doit JAMAIS bouger.
        $socle = $this->settleSeasonPlan($season);
        $socleLock = $this->decorTemplate($club, $season, $socle->getId(), $u13, $mistral->getId(), 2, '18:00', LockLevel::HARD);
        $this->decorTemplate($club, $season, $socle->getId(), $sm1, $mistral->getId(), 2, '19:30', LockLevel::NONE);
        $this->decorTemplate($club, $season, $socle->getId(), $u13, $mistral->getId(), 4, '18:00', LockLevel::NONE);
        $em->flush();

        // « Vacances de mars » : semaine +8, plan né, V1 VALIDÉE (pointée).
        $holiday = (new CalendarEntry)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setKind(CalendarEntryKind::PERIOD)->setPeriodType(CalendarEntryPeriodType::HOLIDAY)->setTitle('Vacances de mars')
            ->setStartDate(new DateTimeImmutable($this->decorMonday(8)))->setEndDate(new DateTimeImmutable($this->decorDay(8, 7)))
            ->setStatus(CalendarEntryStatus::ACTIVE);
        $em->persist($holiday);
        $holidayPlanId = $this->planIdOf($holiday);

        $version = (new Schedule)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setName('Vacances de mars V1')->setStatus(ScheduleStatus::COMPLETED);
        $this->linkSeededSchedule($version, $holiday->getId());
        $em->flush();
        $lockTueMistral = $this->decorTemplate($club, $season, $version->getId(), $u13, $mistral->getId(), 2, '18:00', LockLevel::HARD);
        $lockThuMistral = $this->decorTemplate($club, $season, $version->getId(), $u13, $mistral->getId(), 4, '18:00', LockLevel::HARD);
        $lockTueBrel = $this->decorTemplate($club, $season, $version->getId(), $sm1, $brel->getId(), 2, '18:00', LockLevel::HARD);
        $placement = $this->decorTemplate($club, $season, $version->getId(), $sm1, $mistral->getId(), 2, '19:30', LockLevel::NONE);
        $reservation = (new Reservation)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setSchedulePlanId($holidayPlanId)->setTeamId($sm1)->setVenueId($mistral->getId())
            ->setDayOfWeek(2)->setStartTime(new DateTimeImmutable('19:30'))->setDurationMinutes(90);
        $em->persist($reservation);
        $em->flush();
        $this->choosePlanVersion($version);

        return [
            'club' => $club, 'user' => $user, 'season' => $season,
            'mistral' => $mistral->getId(), 'brel' => $brel->getId(), 'sm1' => $sm1, 'u13' => $u13,
            'socleVersionId' => $socle->getId(), 'socleLockTueMistral' => $socleLock,
            'holidayEntryId' => $holiday->getId(), 'holidayPlanId' => $holidayPlanId, 'holidayVersionId' => $version->getId(),
            'lockTueMistral' => $lockTueMistral, 'lockThuMistral' => $lockThuMistral, 'lockTueBrel' => $lockTueBrel,
            'placementTueMistral' => $placement, 'reservationTueMistral' => $reservation->getId(),
            'closedTuesday' => $this->decorDay(8, 2),
        ];
    }

    private function decorTeam(Club $club, Season $season, string $name, int $tier): string
    {
        $team = (new Team)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setSportCategoryId('99999999-9999-4999-8999-999999999999')->setPriorityTierId($tier)
            ->setName($name)->setSessionsPerWeek(2)->setIsActive(true);
        static::getContainer()->get(EntityManagerInterface::class)->persist($team);

        return $team->getId();
    }

    private function decorTemplate(Club $club, Season $season, string $scheduleId, string $teamId, string $venueId, int $day, string $time, LockLevel $level): string
    {
        $template = (new ScheduleSlotTemplate)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setScheduleId($scheduleId)->setTeamId($teamId)->setVenueId($venueId)
            ->setDayOfWeek($day)->setStartTime(new DateTimeImmutable($time))->setDurationMinutes(90)
            ->setLockLevel($level)->setLockOrigin(LockLevel::HARD === $level ? LockOrigin::MANUAL : null);
        static::getContainer()->get(EntityManagerInterface::class)->persist($template);

        return $template->getId();
    }

    /** @return array<string, string> */
    private function decorHeaders(User $user): array
    {
        return [
            'HTTP_AUTHORIZATION' => 'Bearer ' . static::getContainer()->get(JWTTokenManagerInterface::class)->create($user),
            'CONTENT_TYPE' => 'application/json',
        ];
    }

    /**
     * Le geste RÉEL du formulaire (`cockpit/queries.ts::useCreateVenueClosure`) : l'entrée `closure`
     * PUIS la contrainte FACILITY `venue_closed` datée qui lui est attachée. Rend l'id de l'entrée et
     * la réponse de la CONTRAINTE (c'est elle qui complète la fermeture, et elle qui retire).
     *
     * @return array{entryId: string, status: int, body: array<string, mixed>}
     */
    private function declareClosure(KernelBrowser $client, User $user, string $venueId, string $start, string $end, string $title = 'Mistral indisponible'): array
    {
        $client->request('POST', '/api/calendar_entries', [], [], $this->decorHeaders($user), json_encode([
            'kind' => 'period', 'periodType' => 'closure', 'title' => $title, 'startDate' => $start, 'endDate' => $end,
        ], \JSON_THROW_ON_ERROR));
        Assert::assertSame(201, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $entry = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        Assert::assertIsArray($entry);
        $entryId = (string) $entry['id'];

        $client->request('POST', '/api/constraints', [], [], $this->decorHeaders($user), json_encode([
            'name' => $title, 'scope' => 'FACILITY', 'scopeTargetId' => $venueId, 'family' => 'FACILITY', 'ruleType' => 'HARD',
            'config' => ['type' => 'venue_closed', 'startDate' => $start, 'endDate' => $end],
            'calendarEntryId' => $entryId,
        ], \JSON_THROW_ON_ERROR));
        $body = json_decode((string) $client->getResponse()->getContent(), true) ?? [];

        return ['entryId' => $entryId, 'status' => $client->getResponse()->getStatusCode(), 'body' => \is_array($body) ? $body : []];
    }

    private function decorLockLevel(string $clubId, string $templateId): ?string
    {
        $this->scopeGucToClub($clubId);
        $level = static::getContainer()->get(EntityManagerInterface::class)->getConnection()
            ->fetchOne('SELECT lock_level FROM schedule_slot_template WHERE id = :id', ['id' => $templateId]);

        return false === $level ? null : (string) $level;
    }

    private function decorLockOrigin(string $clubId, string $templateId): ?string
    {
        $this->scopeGucToClub($clubId);
        $origin = static::getContainer()->get(EntityManagerInterface::class)->getConnection()
            ->fetchOne('SELECT lock_origin FROM schedule_slot_template WHERE id = :id', ['id' => $templateId]);

        return false === $origin || null === $origin ? null : (string) $origin;
    }

    private function decorReservationExists(string $clubId, string $reservationId): bool
    {
        $this->scopeGucToClub($clubId);

        return false !== static::getContainer()->get(EntityManagerInterface::class)->getConnection()
            ->fetchOne('SELECT 1 FROM reservation WHERE id = :id', ['id' => $reservationId]);
    }

    private function decorConstraintCount(string $clubId, string $entryId): int
    {
        $this->scopeGucToClub($clubId);

        return (int) static::getContainer()->get(EntityManagerInterface::class)->getConnection()
            ->fetchOne('SELECT COUNT(*) FROM "constraint" WHERE calendar_entry_id = :eid', ['eid' => $entryId]);
    }

    /** @return list<array<string, mixed>> les lignes d'audit d'une action sur une entité, détails décodés */
    private function decorAuditRows(string $action, string $entityId): array
    {
        $rows = static::getContainer()->get(EntityManagerInterface::class)->getConnection()
            ->fetchAllAssociative('SELECT action, details FROM audit_log WHERE action = :a AND entity_id = :e', ['a' => $action, 'e' => $entityId]);

        return array_map(static function (array $row): array {
            $details = json_decode((string) $row['details'], true);

            return \is_array($details) ? $details : [];
        }, $rows);
    }
}
