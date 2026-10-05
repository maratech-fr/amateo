<?php

declare(strict_types=1);

namespace App\Message;

use App\Controller\AdminDemoController;
use App\MessageHandler\ResetDemoBcclHandler;
use App\Service\DemoResetTracker;

/**
 * Demande de RÉINITIALISATION ASYNCHRONE de la démo BCCL (BCK-35, patron du placement).
 *
 * Enfilée par {@see AdminDemoController::reset()} après ses gardes et l'ouverture du run
 * (verrou {@see DemoResetTracker}), jouée par {@see ResetDemoBcclHandler} dans le worker :
 * le re-seed (sous-processus `app:demo:seed`, jusqu'à 600 s) quitte le rail synchrone — où
 * nginx coupait à 120 s en laissant le seed tourner (504 trompeur) — pour le worker.
 *
 * Le verrou est pris PAR LE CONTRÔLEUR (son TTL couvre tout le run, anti-double-clic → 409)
 * et RELÂCHÉ par le worker : le `lockToken` voyage donc ici pour que le worker puisse rendre
 * le verrou (compare-and-delete par token) et poser l'issue terminale.
 *
 * `readonly` — porté par le transport `async` (PhpSerializer natif).
 */
final readonly class ResetDemoBcclMessage
{
    public function __construct(private string $lockToken) {}

    public function getLockToken(): string
    {
        return $this->lockToken;
    }
}
