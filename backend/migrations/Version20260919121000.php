<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `fixture.fbi_echo` — mémo `{field, value, at}` sur un domicile RÉTROGRADÉ de
 * SUBMITTED/VALIDATED à PLACED par un « prendre le fichier » sur l'heure : FBI
 * affiche encore une autre valeur, la rencontre est retombée « à saisir ». Sert la
 * mention « FBI affiche 15:30 » sur la ligne « à saisir » de la liste FBI. Effacé
 * dès que le statut repasse SUBMITTED/VALIDATED ({@see App\Entity\Fixture::setStatus}).
 * Nullable, défaut null.
 *
 * Écrit à la main : `make migration-diff` inopérant tant que doctrine/dbal < 4.5.
 */
final class Version20260919121000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'fixture.fbi_echo (mémo « FBI affiche … » sur un domicile rétrogradé).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE fixture ADD fbi_echo JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE fixture DROP fbi_echo');
    }
}
