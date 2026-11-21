<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * PDF du planning : identifiant de la demande dont le fichier a été livré en dernier. Un message
 * relu par Messenger après livraison porte cet identifiant et n'est ni rendu ni décompté deux fois.
 * Nullable : les plannings existants n'ont rien de livré sous un identifiant.
 */
final class Version20261121120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'schedule.pdf_export_request_id : identifiant de la dernière demande PDF livrée (garde de redélivrance)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE schedule ADD pdf_export_request_id VARCHAR(32) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE schedule DROP pdf_export_request_id');
    }
}
