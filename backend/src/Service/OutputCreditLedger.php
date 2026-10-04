<?php

declare(strict_types=1);

namespace App\Service;

use App\EventListener\CreditBudgetSubscriber;
use App\MessageHandler\PlaceMatchesHandler;
use Doctrine\DBAL\Connection;

/**
 * Le COMPTEUR du pool de crédits de sortie d'un club (offre Découverte, spec
 * bridage-freemium §2) — maison unique de l'incrément, partagée par
 * {@see CreditBudgetSubscriber} (sorties synchrones : export)
 * et par {@see PlaceMatchesHandler} (placement, décompté au
 * SUCCÈS dans le worker, plus au 202).
 *
 * Incrément SQL ATOMIQUE (pas de read-modify-write en PHP) : le pool est PAR CLUB,
 * partagé entre gestionnaires par construction. `club` n'a pas de colonne club_id —
 * pas de policy RLS —, l'UPDATE ciblé par id passe sur la connexion par défaut.
 */
final readonly class OutputCreditLedger
{
    public function __construct(private Connection $connection) {}

    public function consume(string $clubId): void
    {
        if ('' === $clubId) {
            return;
        }

        $this->connection->executeStatement(
            'UPDATE club SET output_credits_used = output_credits_used + 1 WHERE id = :id',
            ['id' => $clubId],
        );
    }
}
