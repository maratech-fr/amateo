<?php

declare(strict_types=1);

namespace App\Service;

use App\Command\ClubClockCommand;
use App\Controller\AdminDemoController;
use App\Controller\ClubClockController;

/**
 * Vide la boîte aux lettres d'un club — maison UNIQUE du geste, partagée par la console
 * ({@see AdminDemoController}), la commande support
 * ({@see ClubClockCommand}) et le widget d'horloge du compte démo
 * ({@see ClubClockController}) au relâchement de l'horloge.
 *
 * Interface pour que les tests HTTP tenant puissent la doubler (le vidage réel passe par la
 * connexion ADMIN, invisible à la transaction DAMA d'une requête tenant) — l'effet DB réel est
 * couvert par le test de la commande support (Integration/Command/ClubClockCommandTest).
 */
interface ClubMailboxPurgerInterface
{
    /** Supprime toutes les lignes de boîte du club. Idempotent (0 ligne = rien à faire). */
    public function purge(string $clubId): void;
}
