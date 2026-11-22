<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Enum\SubscriptionOrigin;
use App\Service\ClubSubscriptionWriter;
use App\Tests\Double\SameTransactionClubSubscriptionWriter;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Décor de test : règle l'offre d'un club pour UNE saison, par le seul écrivain de l'abonnement
 * ({@see ClubSubscriptionWriter}) — jamais par un accesseur du club ni par un `INSERT` à la main.
 * Une ligne Découverte s'écrit non réglée, toute autre réglée à l'instant (règle de l'écrivain).
 *
 * ⚠ Sous DAMA, chaque connexion a sa propre transaction de test : l'écrivain réel écrit par la
 * connexion propriétaire, qui ne voit pas un club semé par l'ORM (clé étrangère vers `club` en
 * échec, 23503), et une ligne qu'il écrirait resterait invisible des lecteurs de l'application.
 * Choix retenu : en environnement de test, le service est le double
 * {@see SameTransactionClubSubscriptionWriter} (`config/services_test.yaml`) — même règle, mais
 * quand le club est visible de la connexion applicative, la ligne s'écrit DANS sa transaction, par
 * une fonction `SECURITY DEFINER` propre à la base de test (détenue par le propriétaire, EXECUTE au
 * seul rôle applicatif, créée par le double, jamais par une migration). Un club semé par la
 * connexion propriétaire passe par l'écrivain réel. Le rôle applicatif ne reçoit jamais `INSERT`
 * ni `UPDATE` sur la table, en production comme en test (refus prouvé par `RlsIsolationTest`).
 * Écartés : partager la connexion DAMA (les deux connexions n'ont pas le même rôle de connexion),
 * un décor commité (il ne pourrait pas désigner un club semé non commité).
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
