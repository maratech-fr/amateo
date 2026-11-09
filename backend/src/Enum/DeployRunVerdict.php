<?php

declare(strict_types=1);

namespace App\Enum;

use App\Service\DeployRunOutcome;

/**
 * Ce qu'un run `deploy.yml` terminé a fait à la production, lu dans ses jobs et steps
 * ({@see DeployRunOutcome}) — c'est lui, pas la seule conclusion du run, qui choisit le statut
 * final du déploiement et le texte de l'e-mail.
 *
 *  - `PROD_UPDATED` : bascule faite, `release` recalée ;
 *  - `BUILD_ONLY` : images publiées, job de bascule sauté (déploiement automatique non armé) ;
 *  - `PROD_UPDATED_RELEASE_NOT_MOVED` : bascule faite, recalage de `release` en échec ;
 *  - `PROD_UPDATED_MAINTENANCE_LEFT` : bascule faite, seule la page de maintenance n'a pas été retirée ;
 *  - `PROD_UPDATED_UNVERIFIED` : run conclu `success`, jobs illisibles (verdict posé par le tick) ;
 *  - `FAILED_APP_IN_SERVICE` : échec AVANT la pose de la maintenance ;
 *  - `FAILED_UNDER_MAINTENANCE` : maintenance posée, bascule en échec ;
 *  - `UNDETERMINED` : l'état de la maintenance ne peut pas être déduit.
 */
enum DeployRunVerdict: string
{
    case PROD_UPDATED = 'prod_updated';
    case BUILD_ONLY = 'build_only';
    case PROD_UPDATED_RELEASE_NOT_MOVED = 'prod_updated_release_not_moved';
    case PROD_UPDATED_MAINTENANCE_LEFT = 'prod_updated_maintenance_left';
    case PROD_UPDATED_UNVERIFIED = 'prod_updated_unverified';
    case FAILED_APP_IN_SERVICE = 'failed_app_in_service';
    case FAILED_UNDER_MAINTENANCE = 'failed_under_maintenance';
    case UNDETERMINED = 'undetermined';
}
