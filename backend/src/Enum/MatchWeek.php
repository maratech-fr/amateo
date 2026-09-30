<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * P4-271 — la semaine d'alternance d'un créneau idéal de match (« semaine type »).
 *
 * AIDE VISUELLE, jamais une contrainte : le modèle IDÉAL du gestionnaire (« si j'avais
 * le choix »). La FFBB impose qui reçoit chaque week-end ; côté moteur, l'idéal reste un
 * BONUS d'attraction. Deux semaines seulement : `A` (le défaut, un club sans alternance
 * range tout en A) et `B`. Ce tag NE VOYAGE PAS au moteur : il ne sert qu'à l'aide
 * visuelle A/B côté frontend — le solveur voit une habitude, sans étiquette de semaine.
 */
enum MatchWeek: string
{
    use HasValues;

    case A = 'A';
    case B = 'B';
}
