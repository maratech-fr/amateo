<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Le moment où joue le rattrapage des runs de placement figés ({@see StuckPlacementRunReconciler}).
 *
 *  - Startup : au démarrage du worker, AVANT sa première lecture de file — aucun autre consommateur
 *    n'existe (un seul consommateur du transport `async` à la fois) : tout run RUNNING est orphelin.
 *  - Cli : commande d'exploitation, worker VIVANT — un run RUNNING n'est déclaré mort que si son
 *    verrou a disparu (budget écoulé) et qu'il a démarré il y a plus de 5 minutes.
 */
enum ReconcileMode: string
{
    case Startup = 'startup';
    case Cli = 'cli';
}
