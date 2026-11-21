<?php

declare(strict_types=1);

namespace App\Service;

use App\Access\ClubAccessListener;
use App\Entity\Club;
use App\Entity\Season;
use App\MessageHandler\ExportPdfHandler;
use App\MessageHandler\GenerateScheduleHandler;
use App\MessageHandler\PlaceMatchesHandler;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Le COMPTEUR du pool de crédits de sortie d'un club (offre Découverte, spec bridage-freemium §2) —
 * maison unique de l'incrément. Un crédit se prend quand la sortie est OBTENUE : à la réponse pour
 * une sortie rendue dans la requête ({@see ClubAccessListener}), dans la transaction qui livre le
 * résultat pour les autres ({@see GenerateScheduleHandler}, {@see PlaceMatchesHandler},
 * {@see ExportPdfHandler}).
 *
 * Incrément SQL ATOMIQUE (pas de read-modify-write en PHP) : le pool est PAR CLUB, partagé entre
 * gestionnaires par construction. `club` n'a pas de colonne club_id — pas de policy RLS —,
 * l'UPDATE ciblé par id passe sur la connexion reçue.
 */
final readonly class OutputCreditLedger
{
    public function __construct(
        private Connection $connection,
        private PlanEntitlements $planEntitlements,
        private EntityManagerInterface $entityManager,
    ) {}

    /**
     * Prend un crédit au club pour une sortie obtenue. À appeler dans une transaction ouverte : le
     * crédit fait partie du résultat, l'un ne s'écrit jamais sans l'autre.
     *
     * Club non bridé (offre payante, bêta, démonstration, pool illimité) ou introuvable : `true`
     * sans rien écrire. Sinon +1 au compteur ; en mode strict, seulement tant que le pool n'est pas
     * plein. Aucune ligne touchée : `false` (le pool est plein en mode strict).
     */
    public function settle(string $clubId, ?string $seasonId, bool $strict = false): bool
    {
        if ('' === $clubId || null === $seasonId || '' === $seasonId) {
            return true;
        }

        $club = $this->entityManager->getRepository(Club::class)->find($clubId);
        $season = $this->entityManager->getRepository(Season::class)->find($seasonId);
        if (!$club instanceof Club || !$season instanceof Season) {
            return true;
        }

        $budget = $this->planEntitlements->outputBudget($club, $season);
        if (!$budget['restricted']) {
            return true;
        }

        $touched = $strict
            ? $this->connection->executeStatement(
                'UPDATE club SET output_credits_used = output_credits_used + 1 WHERE id = :id AND output_credits_used < :max',
                ['id' => $clubId, 'max' => $budget['max']],
            )
            : $this->connection->executeStatement(
                'UPDATE club SET output_credits_used = output_credits_used + 1 WHERE id = :id',
                ['id' => $clubId],
            );

        return $touched > 0;
    }
}
