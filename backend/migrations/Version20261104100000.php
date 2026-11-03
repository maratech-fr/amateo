<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Chantier Membre (PR 3) — un Membre demande à devenir gestionnaire.
 *
 *  - `club_user.manager_requested_at` : la demande en cours auprès des gestionnaires du club
 *    (null = aucune) ;
 *  - `club_user.manager_request_escalated_at` : la demande, restée sans réponse 14 jours, a été
 *    transmise à l'adresse officielle du club (une seule transmission par demande) ;
 *  - `club.purge_hold_until` : une demande de reprise vivante retient la purge d'un club sans
 *    gestionnaire UNE fois, jusqu'à cette date (l'échéance de la demande).
 *
 * Colonnes nullables, aucune donnée à migrer ; les politiques RLS existantes de `club_user` et
 * `club` couvrent les nouvelles colonnes. Écrite à la main (`make migration-diff` inopérant tant
 * que doctrine/dbal < 4.5).
 */
final class Version20261104100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'club_user.manager_requested_at / manager_request_escalated_at, club.purge_hold_until (manager role request).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE club_user ADD manager_requested_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE club_user ADD manager_request_escalated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE club ADD purge_hold_until TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE club DROP purge_hold_until');
        $this->addSql('ALTER TABLE club_user DROP manager_request_escalated_at');
        $this->addSql('ALTER TABLE club_user DROP manager_requested_at');
    }
}
