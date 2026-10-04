<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * P4-301 — préavis « compte sans club » : colonne `app_user.orphan_notice_sent_at`.
 *
 * Non-null = date d'envoi RÉUSSI du mail prévenant le titulaire qu'il n'a plus
 * aucun accès (adhésion active, pending ou demande de création) et que son compte
 * sera supprimé 30 jours plus tard. Remise à null dès qu'il regagne une adhésion
 * active (OrphanAccountNotifier::cancelFor), pour qu'un cycle annulé ne supprime
 * jamais un compte sans nouveau mail.
 *
 * Écrite à la main (`make migration-diff` inopérant tant que doctrine/dbal < 4.5,
 * backend.md). RLS sans objet (migrations sous `amateo_owner`). Colonne nullable,
 * aucun backfill — le stock existant est pris en charge par l'étage 1 du cron
 * (mail puis 30 j), jamais supprimé sans mail.
 */
final class Version20261004130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute app_user.orphan_notice_sent_at (préavis de suppression d\'un compte sans club, P4-301).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user ADD orphan_notice_sent_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user DROP orphan_notice_sent_at');
    }
}
