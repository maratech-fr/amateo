<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Validation automatique d'un planning de période 7 jours après le début de sa période :
 * trois colonnes nullables sur `schedule_plan`.
 *  - `auto_validated_date` : jour du club de la bascule automatique (mémoire « déjà auto-validé »,
 *    marque « Validé automatiquement ») ; jamais effacée, une réouverture ne la touche pas ;
 *  - `alert_seen_date` / `alert_seen_kind` : « J'ai vu » du club sur l'alerte du radar (type
 *    d'alerte vu et jour du club de la vue).
 *
 * Aucune donnée existante à reprendre : NULL = jamais auto-validé, jamais vu. RLS déjà posée sur la table.
 */
final class Version20261112090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'schedule_plan: auto_validated_date, alert_seen_date, alert_seen_kind (automatic validation of a started period plan).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE schedule_plan ADD auto_validated_date DATE DEFAULT NULL');
        $this->addSql('ALTER TABLE schedule_plan ADD alert_seen_date DATE DEFAULT NULL');
        $this->addSql('ALTER TABLE schedule_plan ADD alert_seen_kind VARCHAR(32) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE schedule_plan DROP auto_validated_date');
        $this->addSql('ALTER TABLE schedule_plan DROP alert_seen_date');
        $this->addSql('ALTER TABLE schedule_plan DROP alert_seen_kind');
    }
}
