<?php

declare(strict_types=1);

namespace App\Service;

/**
 * P2-44 PR-5 (ADR-0004) — les ÉCARTS NOMMÉS entre une version affichée d'un plan de PÉRIODE de type
 * FERMETURE et la version POINTÉE du socle. Le SOUS-PRODUIT servi tel quel au front par la route de
 * LECTURE {@see App\Controller\SocleDeviationController} (`GET /api/schedules/{id}/socle-deviation`) :
 * le front ne redérive RIEN, il NOMME (agrégat + ligne à ligne) ce que le backend a calculé.
 *
 * Deux catégories SEULEMENT (décision fondateur) : les séances DÉPLACÉES (`moved`, appariées
 * positionnellement du socle vers la période) et les séances NON REPLACÉES (`unplaced`, le reliquat
 * du socle) — jamais les nouvelles, jamais les inchangées. Il n'y a PAS de champ compteur :
 * « N déplacées, M à replacer » est une longueur de liste côté présentation, pas une règle servie.
 */
final readonly class SocleDeviationResult
{
    /**
     * @param list<array{teamId: string, from: array{dayOfWeek: int, startTime: string, venueId: string}, to: array{dayOfWeek: int, startTime: string, venueId: string, slotId: string}}> $moved    séance du socle → séance de la période (appariement chronologique) ; `to` porte le `slotId` du créneau de PÉRIODE (celui que la grille affiche), `from` n'en a pas (socle, non affiché)
     * @param list<array{teamId: string, dayOfWeek: int, startTime: string, venueId: string, reason: string|null}>                                                                        $unplaced reliquat du socle sans contrepartie ; `reason` NULL quand la sélection ne l'explique pas (jamais fabriquée)
     */
    public function __construct(
        public string $socleScheduleId,
        public array $moved,
        public array $unplaced,
    ) {}
}
