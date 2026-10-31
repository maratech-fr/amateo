<?php

declare(strict_types=1);

namespace App\Export;

use App\Entity\ScheduleSlotTemplate;

/**
 * Resolved data for one schedule export, shared by the PDF grid generator
 * and the Excel generator so the slot fetch + name/colour maps live in one place
 * (previously copy-pasted across both). Rendering diverges; the data does not.
 *
 * @phpstan-type VenueInfo array{name: string, color: string|null}
 * @phpstan-type TeamRankInfo array{label: string, name: string, tierRank: int, tierOrder: int}
 */
final readonly class ScheduleExportData
{
    /** Monday→Sunday; dayOfWeek is 1..7 ISO (the training week is usually 1..6). */
    public const DAY_LABELS = [1 => 'Lundi', 2 => 'Mardi', 3 => 'Mercredi', 4 => 'Jeudi', 5 => 'Vendredi', 6 => 'Samedi', 7 => 'Dimanche'];

    /**
     * @param list<ScheduleSlotTemplate>  $slots            scoped to a venue when the export is
     * @param array<string, string>       $teamNames
     * @param array<string, string>       $teamCategories   teamId → category name
     * @param array<string, VenueInfo>    $venues
     * @param array<string, string>       $coachNames
     * @param list<ExportEmptyWindow>     $emptySlots       defined-but-unfilled venue windows
     * @param array<string, TeamRankInfo> $teamRanks        teamId → its priority tier, for the PDF
     *                                                      "team × day" matrix grouped by rank
     * @param array<string, string>       $groupLabels      "venueId|day|H:i" → group label ("CEC3"),
     *                                                      only for the windows that carry one — the
     *                                                      slot layer of THIS export (venue+day+start
     *                                                      is the window's identity across layers)
     * @param list<ExportReservedWindow>  $freeSlots        lot 4bis — créneaux LIBRES réservés
     *                                                      (réservation sans équipe, nommée) de la
     *                                                      couche exportée : des cases occupées nommées
     * @param array<string, string>       $coachShortNames  coachId → nom COURT (« Karim B. ») — chantier E,
     *                                                      la GRILLE du PDF l'affiche quand le gestionnaire
     *                                                      coche « Afficher le nom des coachs ». Un coach
     *                                                      sans nom n'entre pas dans la map (ligne omise)
     * @param array<string, string>       $teamMainCoachIds teamId → coachId du 1er coach MAIN de l'équipe —
     *                                                      chantier E, le REPLI quand une séance n'a pas de
     *                                                      coach à elle, EXACTEMENT ce que montre l'écran
     *                                                      (`grid.ts::slotCoachId`). Une équipe sans coach MAIN
     *                                                      n'entre pas dans la map (« pas de coach → rien »)
     */
    public function __construct(
        public array $slots,
        public array $teamNames,
        public array $teamCategories,
        public array $venues,
        public array $coachNames,
        public array $emptySlots = [],
        public array $teamRanks = [],
        public array $groupLabels = [],
        public array $freeSlots = [],
        public array $coachShortNames = [],
        public array $teamMainCoachIds = [],
        /** @var array<string, list<string>> teamId → tous ses coachs principaux, dans l'ordre */
        public array $teamCoachIds = [],
    ) {}
}
