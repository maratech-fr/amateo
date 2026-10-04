<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Le cycle de vie d'un RUN de placement automatique des matchs (rail asynchrone,
 * patron de la génération). PENDING à l'enfilage par le contrôleur, RUNNING quand
 * le worker prend la main, puis un état TERMINAL — COMPLETED (le solve a abouti,
 * son résultat est en base) ou FAILED (le moteur a échoué / timeout). Le worker
 * pose TOUJOURS un terminal (try/finally), jamais un run bloqué en RUNNING.
 */
enum MatchPlacementRunStatus: string
{
    case PENDING = 'PENDING';
    case RUNNING = 'RUNNING';
    case COMPLETED = 'COMPLETED';
    case FAILED = 'FAILED';
}
