<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Où en est le TRAITEMENT d'une rencontre importée par le gestionnaire (espace
 * « Importer », PR-3a). Distinct du {@see FixtureStatus} (cycle de placement) :
 * une rencontre peut être placée sans être « à jour », et à jour sans être placée.
 *
 * - NEW : jamais examinée (fraîchement importée / créée par un canal).
 * - OUT_OF_SYNC : la source diverge de ce que le gestionnaire a traité — au moins
 *   un écart pendant subsiste (date/heure/salle), à trancher plus tard.
 * - REVIEWED : le gestionnaire a tranché (ou a placé le match, ou l'a créé à la
 *   main) — aucun écart pendant.
 *
 * Le libellé humain est « traité » — jamais « Validé ligue » (qui, lui, est le
 * statut {@see FixtureStatus::VALIDATED}).
 */
enum FixtureReviewState: string
{
    use HasValues;

    case NEW = 'NEW';
    case OUT_OF_SYNC = 'OUT_OF_SYNC';
    case REVIEWED = 'REVIEWED';
}
