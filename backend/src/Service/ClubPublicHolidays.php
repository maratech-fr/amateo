<?php

declare(strict_types=1);

namespace App\Service;

use App\Controller\PublicHolidaysController;
use App\Entity\PublicHoliday;
use App\Repository\ClubRepository;
use App\Repository\PublicHolidayRepository;
use DateTimeImmutable;

/**
 * MAISON UNIQUE de « les jours fériés applicables à un club » : les fériés NATIONAUX
 * UNION ceux spécifiques au territoire du club (`Club::$schoolZone`), dans une fenêtre
 * [from, to]. Une zone nulle renvoie quand même les NATIONAUX (ils s'appliquent à tout club).
 *
 * Extrait du patron de {@see PublicHolidaysController} (feed d'affichage du
 * cockpit) pour être RÉUTILISÉ sans duplication par le radar de conflits des matchs
 * ({@see ConflictRadarLoader}) et le payload de placement ({@see MatchPlacementPayloadBuilder}) :
 * depuis PR2, un match un jour férié se juge/place différemment (décision fondateur 2026-10-27).
 */
final class ClubPublicHolidays
{
    public function __construct(
        private readonly PublicHolidayRepository $holidayRepository,
        private readonly ClubRepository $clubRepository,
    ) {}

    /**
     * Les fériés (entités) applicables au club dans [from, to], chronologiques, AVEC la zone
     * résolue du club (pour les appelants qui l'exposent, comme le feed cockpit).
     *
     * @return array{zone: string|null, holidays: list<PublicHoliday>}
     */
    public function inWindow(string $clubId, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $zone = $this->clubRepository->find($clubId)?->getSchoolZone();

        return [
            'zone' => $zone,
            'holidays' => $this->holidayRepository->findNationalAndZoneInWindow($zone, $from, $to),
        ];
    }

    /**
     * Les DATES fériées (Y-m-d) applicables au club dans [from, to] — ce que le radar et le
     * payload de placement consomment (un simple ensemble de dates à comparer au jour d'un match).
     *
     * @return list<string>
     */
    public function datesInWindow(string $clubId, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return array_map(
            static fn (PublicHoliday $h): string => $h->getDate()->format('Y-m-d'),
            $this->inWindow($clubId, $from, $to)['holidays'],
        );
    }
}
