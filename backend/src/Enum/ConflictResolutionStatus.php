<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Le statut de TRAITEMENT qu'un gestionnaire pose sur un conflit du radar
 * (P4-207). Le conflit lui-même reste toujours rendu ; ce statut ne fait que
 * dire OÙ EN EST sa résolution.
 *
 * ⚠ « À traiter » n'a PAS de cas ici : c'est le défaut, et le défaut = AUCUNE
 * ligne en base (un conflit sans ligne `conflict_resolution` est « à traiter »).
 * Poser « À traiter » = SUPPRIMER la ligne (DELETE), jamais stocker une valeur.
 * Ces trois cas sont donc EXACTEMENT ceux qui se persistent.
 *
 * Patron {@see OpponentVenueLinkSource} (enum string court adossé à HasValues).
 */
enum ConflictResolutionStatus: string
{
    use HasValues;

    case DEROGATION_REQUESTED = 'DEROGATION_REQUESTED';

    case RESOLVED_INTERNALLY = 'RESOLVED_INTERNALLY';

    case NO_SOLUTION_YET = 'NO_SOLUTION_YET';

    // Deux statuts réservés aux conflits où la personne JOUE (un côté servi porte
    // le rôle PLAYER) : le gestionnaire tranche que la personne coache sans jouer,
    // ou joue sans coacher. Colonne `length: 30` — aucune migration.
    case COACHES_NOT_PLAYING = 'COACHES_NOT_PLAYING';

    case PLAYS_NOT_COACHING = 'PLAYS_NOT_COACHING';
}
