<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Enum\SubscriptionOrigin;
use App\Service\ClubSubscriptionWriter;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Décor de test : règle l'offre d'un club pour UNE saison, par le seul écrivain de l'abonnement
 * ({@see ClubSubscriptionWriter}, connexion propriétaire) — jamais par un accesseur du club ni par
 * un `INSERT` à la main. Une ligne Découverte s'écrit non réglée, toute autre réglée à l'instant
 * (règle de l'écrivain).
 *
 * ⚠ L'écrivain passe par la connexion `admin` : sous DAMA, chaque connexion a sa propre transaction
 * de test, une ligne écrite ici n'est visible que des lectures faites sur cette même connexion, et
 * le club visé doit lui-même y être visible (clé étrangère vers `club`).
 *
 * @phpstan-require-extends KernelTestCase
 */
trait SubscribesClub
{
    private function subscribeClub(string $clubId, int $seasonYear, string $planCode): void
    {
        $writer = static::getContainer()->get(ClubSubscriptionWriter::class);
        \assert($writer instanceof ClubSubscriptionWriter);
        $writer->record($clubId, $seasonYear, $planCode, SubscriptionOrigin::SEED);
    }
}
