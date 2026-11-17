<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Constraint;

/**
 * P2-14 — le résultat de LA sélection : quelles contraintes (entités) partent au solveur
 * pour un plan de période, et pourquoi les autres n'y partent pas. Produit par
 * {@see PeriodConstraintSelector}, consommé par les DEUX côtés qui répondaient chacun à
 * cette question dans leur coin : le payload (`ScheduleConstraintBuilder::buildForPeriodPlan`)
 * et le gate pré-solve (`ValidateConstraintsController`).
 *
 * Les cartes annexes (gymnases désactivés, équipes en pause, séances surchargées) sont
 * exposées parce que le payload en a besoin pour filtrer créneaux/équipes/réservations —
 * les recharger à côté referait exactement le doublon que cette classe supprime.
 */
final readonly class PeriodConstraintSelection
{
    /**
     * @param list<Constraint>                                     $kept                             les entités qui partent au solveur — LE jeu que le gate valide
     * @param list<array{constraint: Constraint, venueId: string}> $droppedForDisabledVenue          sorties car elles nomment un gymnase désactivé — le gate en fait ses warnings
     * @param list<Constraint>                                     $droppedForInertTag               DATÉES CLUB+tag sorties car leur tag ne vise plus aucune équipe active — un geste
     *                                                                                               explicite du gestionnaire POUR la période ne disparaît jamais en silence (#8)
     * @param list<array{constraint: Constraint, venueId: string}> $partiallyAppliedForDisabledVenue CLUB+tag GARDÉES dont les lignes PAR ÉQUIPE meurent (clé de config sur gymnase
     *                                                                                               désactivé) : seule l'exclusivité du gymnase dédié survit — annoncé, pas muet
     * @param list<Constraint>                                     $dated                            les datées BRUTES de la période (avant sélection). P2-38 : la granularité
     *                                                                                               JOUR des fermetures ne se calcule PLUS ici mais dans `PlanVenueClosures`
     *                                                                                               (transversale, toutes entrées confondues) — ce champ reste le jeu daté de
     *                                                                                               CETTE entrée, à disposition des consommateurs de la sélection
     * @param array<string, true>                                  $disabledVenueIds                 gymnases DISABLED pour ce plan (sparse)
     * @param array<string, array<int, true>>                      $effectiveClosedWeekdaysByVenue   jours ISO effectivement fermés par gymnase (incident × masque) — la
     *                                                                                               MAISON UNIQUE `PlanVenueClosures` compose, gate et payload la partagent
     * @param array<string, true>                                  $deactivatedTeamIds               équipes désactivées pour ce plan (sparse)
     * @param array<string, int>                                   $sessionOverrides                 teamId → séances/semaine surchargées pour la période
     * @param array<string, int>                                   $maxPerDayOverrides               teamId → séances max par jour surchargées pour la période
     */
    public function __construct(
        public string $schedulePlanId,
        public array $kept,
        public array $droppedForDisabledVenue,
        public array $droppedForInertTag,
        public array $partiallyAppliedForDisabledVenue,
        public array $dated,
        public array $disabledVenueIds,
        public array $effectiveClosedWeekdaysByVenue,
        public array $deactivatedTeamIds,
        public array $sessionOverrides,
        public array $maxPerDayOverrides = [],
    ) {}
}
