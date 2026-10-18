<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Le cycle de vie d'une « mise en prod programmée » (console superadmin).
 *
 * `SCHEDULED` : programmée, en attente de l'heure H ; `DISPATCHED` : le workflow
 * de déploiement a été déclenché (le train est parti, plus annulable) ; `SUCCEEDED`
 * / `FAILED` : run GitHub terminé ; `CANCELLED` : annulée avant dispatch.
 *
 * Les deux premiers statuts sont ACTIFS : un seul déploiement actif à la fois
 * (index unique partiel sur `scheduled_deployment`).
 */
enum DeploymentStatus: string
{
    use HasValues;

    /**
     * Les statuts ACTIFS — ceux qu'un nouveau déploiement ne peut pas doubler
     * (gardés par l'index unique partiel).
     *
     * @return list<string>
     */
    public static function activeValues(): array
    {
        return [self::SCHEDULED->value, self::DISPATCHED->value];
    }

    case SCHEDULED = 'scheduled';
    case DISPATCHED = 'dispatched';
    case SUCCEEDED = 'succeeded';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';
}
