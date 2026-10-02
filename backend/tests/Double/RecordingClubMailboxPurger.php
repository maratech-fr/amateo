<?php

declare(strict_types=1);

namespace App\Tests\Double;

use App\Service\ClubMailboxPurgerInterface;

/**
 * Double du vidage de boîte pour les tests HTTP tenant : le vrai purger supprime via la
 * connexion ADMIN, invisible à la transaction DAMA d'une requête tenant (l'effet DB réel est
 * couvert par ClubClockCommandTest). Ce double enregistre les clubId purgés — on vérifie que
 * le contrôleur invoque bien la maison unique au `clear`, et jamais en posant une date.
 */
final class RecordingClubMailboxPurger implements ClubMailboxPurgerInterface
{
    /** @var list<string> */
    public array $purged = [];

    public function purge(string $clubId): void
    {
        $this->purged[] = $clubId;
    }
}
