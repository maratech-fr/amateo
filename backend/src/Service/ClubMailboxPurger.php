<?php

declare(strict_types=1);

namespace App\Service;

use App\Command\ClubClockCommand;
use App\Controller\AdminDemoController;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Vide la boîte aux lettres d'un club — maison UNIQUE du geste, partagée par la console
 * ({@see AdminDemoController}) et la commande support
 * ({@see ClubClockCommand}) au relâchement de l'horloge.
 *
 * Sur la connexion ADMIN (amateo_owner, porte `admin_all`) : ces deux appelants agissent
 * HORS d'un contexte tenant (le firewall admin ne pose jamais de GUC `app.club_id`, et une
 * commande support n'en a pas), où un DELETE par la connexion runtime (amateo_app, policy
 * `tenant_isolation`) serait fail-closed — 0 ligne vue. La connexion admin traverse la RLS.
 */
final readonly class ClubMailboxPurger
{
    public function __construct(private ManagerRegistry $managerRegistry) {}

    /** Supprime toutes les lignes de boîte du club. Idempotent (0 ligne = rien à faire). */
    public function purge(string $clubId): void
    {
        $this->connection()->executeStatement(
            'DELETE FROM club_mailbox_message WHERE club_id = :id',
            ['id' => $clubId],
        );
    }

    private function connection(): Connection
    {
        $connection = $this->managerRegistry->getConnection('admin');
        \assert($connection instanceof Connection);

        return $connection;
    }
}
