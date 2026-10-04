<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * SEC-30 — la date simulée (`club.simulated_today`) est réservée aux clubs de
 * DÉMONSTRATION (décision fondateur 2026-10-02).
 *
 * Décaler l'« aujourd'hui » d'un vrai club lui donnerait la main sur des mécanismes
 * datés qui ne le concernent pas (radar, bascule de saison, délais de sécurité,
 * e-mails). On NETTOIE d'abord toute date résiduelle posée sur un club non démo,
 * puis on pose une contrainte CHECK qui la rend impossible à l'avenir : la base
 * elle-même refuse l'écriture, en plus de la garde applicative (ClubClock,
 * ClubClockController, ClubClockCommand, AdminDemoController).
 *
 * Écrite à la main (`make migration-diff` inopérant tant que doctrine/dbal < 4.5).
 * RLS sans objet (migrations sous `amateo_owner`, BYPASSRLS).
 */
final class Version20261004140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'SEC-30: club.simulated_today demo-only — clear it on real clubs, then CHECK (simulated_today IS NULL OR is_demo).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('UPDATE club SET simulated_today = NULL WHERE NOT is_demo');
        $this->addSql('ALTER TABLE club ADD CONSTRAINT club_simulated_today_demo_only CHECK (simulated_today IS NULL OR is_demo)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE club DROP CONSTRAINT club_simulated_today_demo_only');
    }
}
