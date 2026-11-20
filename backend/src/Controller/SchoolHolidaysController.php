<?php

declare(strict_types=1);

namespace App\Controller;

use App\Access\Audience;
use App\Access\ClubFunction;
use App\Access\Module;
use App\Entity\SchoolHolidayPeriod;
use App\Repository\ClubRepository;
use App\Repository\SchoolHolidayPeriodRepository;
use App\Service\SeasonResolver;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Cockpit feed of the club's school holidays (accueil-cockpit-temporel §4bis).
 * Reads the global reference filtered by the club's academic zone and a window
 * (from/to query, defaulting to the active season). zone null → empty items
 * (the frontend shows a "renseigner la zone" CTA).
 */
#[ClubFunction(module: Module::PLANNING, audience: Audience::MEMBER)]
final class SchoolHolidaysController extends AbstractController
{
    use ResolvesCurrentClubTrait;

    public function __construct(
        private readonly SchoolHolidayPeriodRepository $holidayRepository,
        private readonly ClubRepository $clubRepository,
        private readonly SeasonResolver $seasonResolver,
        private readonly RequestStack $requestStack,
    ) {}

    #[Route('/api/school-holidays', name: 'api_school_holidays', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $clubId = $this->resolveCurrentClubId($this->requestStack);
        if (null === $clubId) {
            return $this->json(['error' => 'No club in context.'], Response::HTTP_BAD_REQUEST);
        }

        $request = $this->requestStack->getCurrentRequest();
        $club = $this->clubRepository->find($clubId);
        $zone = $club?->getSchoolZone();
        if (null === $zone || '' === $zone) {
            return $this->json(['zone' => null, 'items' => []]);
        }

        // Default window = the SELECTED season, else the current one.
        $season = $this->seasonResolver->selectedOrCurrent($request, $clubId);

        // A provided-but-invalid from/to is a client error, not a silent
        // fallback to the season window.
        $from = $this->parseDate($request?->query->get('from')) ?? $season?->getStartDate();
        $to = $this->parseDate($request?->query->get('to')) ?? $season?->getEndDate();
        if (false === $from || false === $to) {
            return $this->json(['error' => 'Query params from/to must be valid dates (YYYY-MM-DD).'], Response::HTTP_BAD_REQUEST);
        }
        if (!$from instanceof DateTimeImmutable || !$to instanceof DateTimeImmutable) {
            return $this->json(['error' => 'No window: pass from/to or have an active season.'], Response::HTTP_BAD_REQUEST);
        }

        $items = array_map(
            static fn (SchoolHolidayPeriod $h): array => [
                'id' => $h->getId(),
                'label' => $h->getLabel(),
                'holidayType' => $h->getHolidayType(),
                'startDate' => $h->getStartDate()->format('Y-m-d'),
                'endDate' => $h->getEndDate()->format('Y-m-d'),
                'schoolYear' => $h->getSchoolYear(),
            ],
            $this->holidayRepository->findByZoneAndWindow($zone, $from, $to),
        );

        return $this->json(['zone' => $zone, 'items' => $items]);
    }

    /**
     * null = param absent (use the season default); false = provided but invalid
     * (400); DateTimeImmutable = a real calendar date. Strict: rejects both
     * malformed strings and rollovers like 2026-02-30 / 2026-13-01.
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
