<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Constraint;
use App\Entity\Reservation;
use App\Entity\VenueTrainingSlot;
use LogicException;

/**
 * ADR-0002 — QUELLE ANCRE porte « cette ligne appartient-elle à une période ? ».
 *
 * Source UNIQUE du mapping. Depuis les lots C2-C3, trois entités voisines répondent à la
 * même question par DEUX champs différents, et c'est une décision, pas un accident :
 *
 * - `Reservation`, `VenueTrainingSlot` → **`schedulePlanId`**. Ce sont des RÉPONSES (« la
 *   mairie me prête ce gymnase POUR cet ajustement », « je pose cette équipe DANS ce
 *   planning »), et le découpage hebdomadaire (E1) leur demandera de distinguer deux plans
 *   partageant un même déclencheur.
 * - `Constraint` → **`calendarEntryId`**. La contrainte DATÉE décrit le FAIT (« Barros
 *   fermé ») ; le radar de conflits la lit PAR L'ENTRÉE pour annoncer « cette fermeture
 *   gêne 3 séances » — c'est ce qui DÉCLENCHE le geste « ajuster ». L'ancrer au plan la
 *   rendrait illisible tant qu'aucun plan n'existe, or le plan naît de ce geste (décision
 *   fondateur 2026-07-17 ; l'invariant 5 de l'ADR a été corrigé, il se contredisait avec la
 *   section « Rôle de CalendarEntry »).
 *
 * La structure PARTAGÉE (inv. 6), celle qu'on photographie et que le socle génère : pour une
 * RÉPONSE, la ligne du plan de saison (lot 4 : le socle a son plan) ; pour un FAIT, la
 * contrainte sans entrée de calendrier (permanente). (Ce n'est PAS un critère de transition de
 * saison : `SeasonTransitionService` copie les créneaux de base mais pas les
 * réservations — un choix qui lui appartient, sans lien avec l'ancre.)
 *
 * Pourquoi une classe plutôt qu'un ternaire recopié : le mapping était dupliqué dans
 * StructureSnapshotter et StructureRestorer, et ce dernier le DEVINAIT — son `else`
 * retombait sur `calendarEntryId` pour toute entité hors liste. Une ancre devinée diverge
 * en silence, et sur une colonne nullable ça ne casse rien : ça fait passer une ligne de
 * période pour une ligne de base. Le planning reste plausible, et devient faux.
 */
final class StructureAnchor
{
    /**
     * Cette entité porte-t-elle une notion d'appartenance à une période ? Les appelants
     * doivent le demander ICI plutôt que de recopier la liste : deux copies divergent, et
     * une entité oubliée serait photographiée AVEC ses lignes de période.
     *
     * @param class-string $entityClass
     */
    public static function isPeriodScoped(string $entityClass): bool
    {
        return \in_array($entityClass, [Constraint::class, Reservation::class, VenueTrainingSlot::class], true);
    }

    /**
     * Le critère qui borne cette entité à la structure PARTAGÉE : `[champ => valeur]`, la valeur
     * nulle ne valant que pour l'entrée de calendrier d'une contrainte permanente.
     *
     * `match` EXHAUSTIF, sans branche par défaut : une entité inconnue lève, elle ne se voit
     * pas attribuer une ancre au hasard. C'est le point du refactor — un `else` qui retombe
     * sur `calendarEntryId` ne casse RIEN sur une colonne nullable : il fait passer les
     * lignes de période pour des lignes de base, le socle hérite de réglages qui ne lui
     * appartiennent pas, et le planning reste plausible. Mieux vaut exploser à l'ajout d'une
     * entité que diverger en silence.
     *
     * @param class-string $entityClass
     *
     * @return array<string, string|null>
     */
    public static function sharedStructureCriteria(string $entityClass, string $seasonPlanId): array
    {
        return match ($entityClass) {
            Reservation::class, VenueTrainingSlot::class => ['schedulePlanId' => $seasonPlanId],
            Constraint::class => ['calendarEntryId' => null],
            default => throw new LogicException(\sprintf('Aucune ancre de période connue pour %s — déclarez-la ici (schedulePlanId si la ligne est une RÉPONSE, calendarEntryId si elle décrit le FAIT).', $entityClass)),
        };
    }
}
