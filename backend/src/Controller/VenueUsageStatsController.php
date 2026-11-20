<?php

declare(strict_types=1);

namespace App\Controller;

use App\Access\Audience;
use App\Access\ClubFunction;
use App\Access\Module;
use App\Entity\CalendarEntry;
use App\Entity\SchedulePlan;
use App\Entity\SchoolHolidayPeriod;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryStatus;
use App\Enum\SchedulePlanType;
use App\Enum\TeamLevel;
use App\Repository\CalendarEntryRepository;
use App\Repository\ClubRepository;
use App\Repository\ScheduleSlotTemplateRepository;
use App\Repository\SchoolHolidayPeriodRepository;
use App\Repository\TeamRepository;
use App\Repository\VenueRepository;
use App\Service\ClubDay;
use App\Service\SeasonResolver;
use App\Service\VenueUsage\HolidayRange;
use App\Service\VenueUsage\PeriodOverlay;
use App\Service\VenueUsage\UsageSlot;
use App\Service\VenueUsage\VenueUsageCalculator;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Stats d'utilisation des gymnases (P3-22) : par gymnase ET par niveau, les heures
 * ventilées PAR JOUR de la semaine (Réalisé / À venir), pour NÉGOCIER les créneaux
 * avec la mairie (« le lundi, on a 8 h, dont 3 h de régional »). Lecture PURE :
 * ce contrôleur charge les données du club/saison et délègue tout le calcul à
 * VenueUsageCalculator. Défaut de plage = la saison entière ; from/to invalides
 * → 400 (patron de SchoolHolidaysController).
 */
#[ClubFunction(module: Module::PLANNING, audience: Audience::MEMBER)]
final class VenueUsageStatsController extends AbstractController
{
    use ResolvesCurrentClubTrait;

    public function __construct(
        private readonly ClubRepository $clubRepository,
        private readonly SeasonResolver $seasonResolver,
        private readonly CalendarEntryRepository $calendarEntryRepository,
        private readonly ScheduleSlotTemplateRepository $slotRepository,
        private readonly SchoolHolidayPeriodRepository $holidayRepository,
        private readonly TeamRepository $teamRepository,
        private readonly VenueRepository $venueRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClubDay $clubDay,
        private readonly VenueUsageCalculator $calculator,
        private readonly RequestStack $requestStack,
    ) {}

    #[Route('/api/venue-usage-stats', name: 'api_venue_usage_stats', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $clubId = $this->resolveCurrentClubId($this->requestStack);
        if (null === $clubId) {
            return $this->json(['error' => 'No club in context.'], Response::HTTP_BAD_REQUEST);
        }

        $club = $this->clubRepository->find($clubId);
        if (null === $club) {
            return $this->json(['error' => 'No club in context.'], Response::HTTP_BAD_REQUEST);
        }

        $request = $this->requestStack->getCurrentRequest();
        $season = $this->seasonResolver->selectedOrCurrent($request, $clubId);

        // A provided-but-invalid from/to is a client error, not a silent fallback
        // to the season window (identical to SchoolHolidaysController).
        $from = $this->parseDate($request?->query->get('from')) ?? $season?->getStartDate();
        $to = $this->parseDate($request?->query->get('to')) ?? $season?->getEndDate();
        if (false === $from || false === $to) {
            return $this->json(['error' => 'Query params from/to must be valid dates (YYYY-MM-DD).'], Response::HTTP_BAD_REQUEST);
        }
        if (!$from instanceof DateTimeImmutable || !$to instanceof DateTimeImmutable) {
            return $this->json(['error' => 'No window: pass from/to or have an active season.'], Response::HTTP_BAD_REQUEST);
        }

        $today = $this->clubDay->todayFor($club);
        $zone = $club->getSchoolZone();
        $zone = null === $zone || '' === $zone ? null : $zone;
        $seasonId = $season?->getId();

        $seasonScheduleId = null;
        $overlays = [];
        if (null !== $seasonId) {
            $seasonScheduleId = $this->seasonPlanScheduleId($clubId, $seasonId);
            $overlays = $this->activeOverlays($clubId, $seasonId, $from, $to);
        }

        $scheduleIds = array_values(array_unique(array_filter(array_merge(
            [$seasonScheduleId],
            array_map(static fn (PeriodOverlay $o): string => $o->scheduleId, $overlays),
        ))));

        [$slotsByScheduleId, $teamIds, $venueIds] = $this->loadSlots($scheduleIds);

        $result = $this->calculator->calculate(
            $from,
            $to,
            $today,
            $zone,
            $seasonScheduleId,
            $overlays,
            $this->loadHolidays($zone, $from, $to),
            $slotsByScheduleId,
            $this->loadVenueNames($venueIds),
            $this->loadTeamLevels($teamIds),
        );

        return $this->json($result);
    }

    /** The SEASON base plan's validated version pointer (null = still a workspace). */
    private function seasonPlanScheduleId(string $clubId, string $seasonId): ?string
    {
        $plan = $this->entityManager->getRepository(SchedulePlan::class)->findOneBy([
            'clubId' => $clubId,
            'seasonId' => $seasonId,
            'type' => SchedulePlanType::SEASON,
        ]);

        return $plan?->getChosenScheduleId();
    }

    /**
     * Active period entries overlapping [from, to] whose plan carries a validated
     * version — the only ones that override the season grid (P3-22 rule 1).
     *
     * @return list<PeriodOverlay>
     */
    private function activeOverlays(string $clubId, string $seasonId, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        /** @var list<CalendarEntry> $entries */
        $entries = $this->calendarEntryRepository->findByClubSeason($clubId, $seasonId);
        $fromYmd = $from->format('Y-m-d');
        $toYmd = $to->format('Y-m-d');

        $periods = array_filter(
            $entries,
            static fn (CalendarEntry $e): bool => CalendarEntryKind::PERIOD === $e->getKind()
                && CalendarEntryStatus::ACTIVE === $e->getStatus()
                && $e->getStartDate()->format('Y-m-d') <= $toYmd
                && $e->getEndDate()->format('Y-m-d') >= $fromYmd,
        );
        if ([] === $periods) {
            return [];
        }

        $chosenByEntry = [];
        $plans = $this->entityManager->getRepository(SchedulePlan::class)->findBy([
            'calendarEntryId' => array_values(array_map(static fn (CalendarEntry $e): string => $e->getId(), $periods)),
        ]);
        foreach ($plans as $plan) {
            $entryId = $plan->getCalendarEntryId();
            $chosen = $plan->getChosenScheduleId();
            if (null !== $entryId && null !== $chosen) {
                $chosenByEntry[$entryId] = $chosen;
            }
        }

        $overlays = [];
        foreach ($periods as $entry) {
            $chosen = $chosenByEntry[$entry->getId()] ?? null;
            if (null !== $chosen) {
                $overlays[] = new PeriodOverlay($entry->getStartDate(), $entry->getEndDate(), $entry->getParentEntryId(), $chosen);
            }
        }

        return $overlays;
    }

    /**
     * Slots of every applicable schedule, grouped by scheduleId, plus the sets of
     * team and venue ids they reference (to load names/levels once).
     *
     * @param list<string> $scheduleIds
     *
     * @return array{0: array<string, list<UsageSlot>>, 1: list<string>, 2: list<string>}
     */
    private function loadSlots(array $scheduleIds): array
    {
        $slotsByScheduleId = [];
        $teamIds = [];
        $venueIds = [];
        if ([] === $scheduleIds) {
            return [[], [], []];
        }

        foreach ($this->slotRepository->findBy(['scheduleId' => $scheduleIds]) as $slot) {
            $slotsByScheduleId[$slot->getScheduleId()][] = new UsageSlot(
                $slot->getVenueId(),
                $slot->getDayOfWeek(),
                $slot->getDurationMinutes(),
                $slot->getTeamId(),
            );
            $teamIds[$slot->getTeamId()] = true;
            $venueIds[$slot->getVenueId()] = true;
        }

        return [$slotsByScheduleId, array_keys($teamIds), array_keys($venueIds)];
    }

    /**
     * @param list<string> $teamIds
     *
     * @return array<string, TeamLevel|null>
     */
    private function loadTeamLevels(array $teamIds): array
    {
        if ([] === $teamIds) {
            return [];
        }
        $levels = [];
        foreach ($this->teamRepository->findBy(['id' => $teamIds]) as $team) {
            $levels[$team->getId()] = $team->getLevel();
        }

        return $levels;
    }

    /**
     * @param list<string> $venueIds
     *
     * @return array<string, string>
     */
    private function loadVenueNames(array $venueIds): array
    {
        if ([] === $venueIds) {
            return [];
        }
        $names = [];
        foreach ($this->venueRepository->findBy(['id' => $venueIds]) as $venue) {
            $names[$venue->getId()] = $venue->getName();
        }

        return $names;
    }

    /**
     * School holidays of the club's zone within the window (empty when zone is
     * unset → no neutralisation, per the spec).
     *
     * @return list<HolidayRange>
     */
    private function loadHolidays(?string $zone, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        if (null === $zone) {
            return [];
        }

        return array_map(
            static fn (SchoolHolidayPeriod $h): HolidayRange => new HolidayRange($h->getStartDate(), $h->getEndDate()),
            $this->holidayRepository->findByZoneAndWindow($zone, $from, $to),
        );
    }

    /**
     * null = param absent (use the season default); false = provided but invalid
     * (400); DateTimeImmutable = a real calendar date. Strict: rejects malformed
     * strings and rollovers like 2026-02-30 / 2026-13-01.
     */
    private function parseDate(mixed $value): DateTimeImmutable|false|null
    {
        if (null === $value || '' === $value) {
            return null;
        }
        if (!\is_string($value)) {
            return false;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();
        if (false === $date || (false !== $errors && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return false;
        }

        return $date;
    }
}
