<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Rappel J-7 avant suppression définitive d'un club orphelin : colonne
 * `club.erasure_reminder_sent_at`.
 *
 * Non-null = le rappel « suppression définitive imminente » a déjà été envoyé au
 * contact officiel du club pour l'échéance en cours — garde-fou d'idempotence de la
 * commande `app:clubs:erasure-remind` (un seul rappel par échéance). Remise à null
 * quand l'effacement est annulé (reprise du club / retour d'un membre actif).
 *
 * Écrite à la main (`make migration-diff` inopérant tant que doctrine/dbal < 4.5).
 * RLS sans objet (migrations sous `amateo_owner`). Colonne nullable, aucun backfill.
 */
final class Version20261003110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute club.erasure_reminder_sent_at (idempotence du rappel J-7 avant suppression d\'un club orphelin).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE club ADD erasure_reminder_sent_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE club DROP erasure_reminder_sent_at');
    }
}
