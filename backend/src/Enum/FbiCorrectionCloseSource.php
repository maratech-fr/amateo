<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * COMMENT une entrée « à corriger dans FBI » s'est fermée (`FbiCorrection.closedBy`,
 * non nul dès que `closedAt` l'est) :
 *   - DEPOSIT : un dépôt (xlsx ou canal API) a constaté que FBI reflète désormais la
 *     valeur de l'appli (ou une TROISIÈME valeur — l'écart d'origine n'a plus d'objet) ;
 *     le gestionnaire n'a rien eu à faire, la source l'a fermée.
 *   - MANUAL : le gestionnaire a coché « Corrigé dans FBI » dans la liste (aucun dépôt
 *     ne l'a encore confirmé) — réouvrable dans les 24 h.
 */
enum FbiCorrectionCloseSource: string
{
    use HasValues;

    case DEPOSIT = 'deposit';

    case MANUAL = 'manual';
}
