<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Le cran de la règle implicite « Trajet entre gymnases » (levier `VenueTravelRuleSetting`).
 *
 * Distinct de {@see TeamLinkIntensity} (passerelles, PREFERRED/MANDATORY seulement) : la règle de
 * trajet gagne un troisième cran, `OFF` « Inactive » (décision fondateur 2026-09-30), qui n'a aucun
 * sens pour une passerelle — d'où un enum DÉDIÉ plutôt qu'un `OFF` forcé dans le vocabulaire partagé.
 *
 * `OFF` : la règle N'EST PAS émise au solveur (payload comme un club sans matrice ; la matrice reste
 * STOCKÉE, elle n'est simplement pas envoyée). `PREFERRED` (défaut) : battement trop court = violation
 * SOFT nommée. `MANDATORY` : battement trop court = interdit dur (peut rendre le planning infaisable).
 */
enum VenueTravelRuleIntensity: string
{
    use HasValues;

    case OFF = 'OFF';

    case PREFERRED = 'PREFERRED';

    case MANDATORY = 'MANDATORY';
}
