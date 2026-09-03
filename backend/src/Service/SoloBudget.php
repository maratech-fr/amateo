<?php

declare(strict_types=1);

namespace App\Service;

/**
 * P2-60 — le budget de réservation INDIVIDUELLE d'une équipe, sur UNE portée (socle ou plan de
 * période, jamais d'union — ADR-0002). Valeur pure calculée par {@see SoloReservationBudget} :
 *
 *  - {@see $effective} S(T) : séances hebdomadaires EFFECTIVES ({@see EffectiveTeamSessions},
 *    override de période inclus) ;
 *  - {@see $block} B(T) : Σ des séances communes des blocs de la portée contenant T ;
 *  - {@see $residual} R(T) = S(T) − B(T), jamais négatif (la garde centrale Σ≤S le garantit) : le
 *    nombre maximal de réservations INDIVIDUELLES que T peut poser ;
 *  - {@see $individualUsed} : ses réservations INDIVIDUELLES déjà posées (celles qui ne sont PAS
 *    sur une case bloc-complète — même discernement que
 *    {@see ReservationGroupOccupancy::reservationsOnGroupCompleteCases}) ;
 *  - {@see $inBlock} : T est membre d'au moins un bloc de la portée.
 *
 * Invariant gardé aux deux portes (réservation individuelle, déclaration/édition de bloc) :
 * {@see $individualUsed} ≤ {@see $residual}.
 */
final readonly class SoloBudget
{
    public function __construct(
        public string $teamId,
        public int $effective,
        public int $block,
        public int $residual,
        public int $individualUsed,
        public bool $inBlock,
    ) {}
}
