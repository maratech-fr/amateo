<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration de DONNÉES — `match_placement_run.created_at` d'un club de démonstration a pu être
 * posé à la date SIMULÉE du club (horloge décorée), donc dans le futur : ce run restait « le
 * dernier » à vie pour l'écran (tri par `created_at`) et ne mûrissait jamais pour le rattrapage.
 * Le contrôleur date désormais le run à l'heure réelle ; ici, l'existant est ramené à son départ
 * réel (`started_at`, sinon `finished_at`, sinon maintenant).
 *
 * Connexion `admin` (policy `admin_all` sous FORCE RLS, Version20261004120000) : tous les clubs.
 * Idempotente (seules les lignes encore datées dans le futur bougent).
 */
final class Version20261111180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'match_placement_run: created_at set in the future by a demo clock is brought back to the real start (ordering of the latest run).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('UPDATE match_placement_run SET created_at = COALESCE(started_at, finished_at, now()) WHERE created_at > now()');
    }

    public function down(Schema $schema): void
    {
        // Sans effet : un created_at futur ramené au départ réel n'a rien à restaurer — la valeur
        // corrigée reste juste.
    }
}
