<?php

declare(strict_types=1);

namespace App\Service;

/**
 * P2-44 PR-5 (ADR-0004) — les ÉCARTS NOMMÉS entre une version affichée d'un plan de PÉRIODE de type
 * FERMETURE et la version POINTÉE du socle. Le SOUS-PRODUIT servi tel quel au front par la route de
 * LECTURE {@see App\Controller\SocleDeviationController} (`GET /api/schedules/{id}/socle-deviation`) :
 * le front ne redérive RIEN, il PRÉSENTE ce que le backend a calculé.
 *
 * Le DÉTAIL ligne à ligne tient en deux catégories (décision fondateur) : les séances DÉPLACÉES
 * (`moved`, appariées positionnellement du socle vers la période) et les séances NON REPLACÉES
 * (`unplaced`, le reliquat du socle) — jamais les nouvelles, jamais les inchangées. Le BILAN par
 * équipe (`teams`) et par rang (`ranks`) COMPTE à partir de ces seules lignes (une source) :
 * `keptSessions + lost + reducedByChoice = socleSessions` par équipe ; les nouvelles sont comptées,
 * jamais listées. `changedSlotIds` = séances de PÉRIODE déplacées ou nouvelles (le filtre de la grille).
 *
 * `sharedWith` (sur `from`, `to`, `unplaced` et la ligne d'équipe) = IDS des équipes avec qui la
 * séance est commune sur sa case (bloc DÉCLARÉ du périmètre) ; le nom se lit côté écran.
 *
 * Type TS miroir tenu à la main : `frontend/src/features/planning/api.ts` (`SocleDeviation`).
 */
final readonly class SocleDeviationResult
{
    /**
     * @param array{startDate: string, endDate: string}                                                                                                                                                                                                                                                                            $window         la fenêtre de la période (Y-m-d)
     * @param list<array{teamId: string, from: array{dayOfWeek: int, startTime: string, venueId: string, sharedWith: list<string>}, to: array{dayOfWeek: int, startTime: string, venueId: string, slotId: string, sharedWith: list<string>}}>                                                                                      $moved          séance du socle → séance de la période (appariement chronologique) ; `to` porte le `slotId` du créneau de PÉRIODE (celui que la grille affiche), `from` n'en a pas (socle, non affiché)
     * @param list<array{teamId: string, dayOfWeek: int, startTime: string, venueId: string, reason: string|null, sharedWith: list<string>}>                                                                                                                                                                                       $unplaced       reliquat du socle sans contrepartie ; `reason` NULL quand la sélection ne l'explique pas (jamais fabriquée)
     * @param list<string>                                                                                                                                                                                                                                                                                                         $changedSlotIds ids des séances de PÉRIODE déplacées ou nouvelles
     * @param list<array{teamId: string, tierLabel: string, tierWeight: int, tierOrder: int, paused: bool, socleSessions: int, keptSessions: int, lost: int, reducedByChoice: int, movedSessions: int, newSessions: int, chosenSessions: int|null, daysKept: bool|null, changed: bool, sharedKept: int, sharedWith: list<string>}> $teams          une ligne par équipe ayant au moins une séance d'un côté (pauses comprises), triée rang puis tierOrder puis nom
     * @param list<array{tierLabel: string, tierWeight: int, teams: int, socleSessions: int, keptSessions: int, lost: int, chosenReductions: int, newSessions: int, paused: int, unchanged: int}>                                                                                                                                  $ranks          une ligne par rang, poids décroissant puis libellé ; sommes HORS équipes en pause
     */
    public function __construct(
        public string $socleScheduleId,
        public array $window,
        public array $moved,
        public array $unplaced,
        public array $changedSlotIds,
        public array $teams,
        public array $ranks,
    ) {}
}
