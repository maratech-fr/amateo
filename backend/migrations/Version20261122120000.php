<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Supprime les deux tables d'étiquettes d'équipe, `team_tag_assignment` puis `team_tag` : plus rien ne les écrit
 * ni ne les lit, les étiquettes se calculent à la lecture depuis la catégorie, le sexe et le niveau de l'équipe
 * (`TeamTagService`). Leurs policies, index et droits partent avec elles.
 *
 * Part dans la même mise en production que les migrations qui ont cessé de les écrire, site fermé : la release
 * précédente les lit encore (dérogation écrite à la règle « compatible une release en arrière »).
 *
 * Les photos de structure qui portent encore la clé `TeamTagAssignment` ne sont pas réécrites : « Charger » ignore
 * une famille qu'il ne connaît pas.
 *
 * Irréversible : les lignes supprimées ne se recalculent pas en base.
 */
final class Version20261122120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Supprime les tables team_tag_assignment et team_tag : les étiquettes d\'équipe se calculent à la lecture.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP TABLE team_tag_assignment');
        $this->addSql('DROP TABLE team_tag');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'Les tables d\'étiquettes supprimées ne se recréent pas : les étiquettes se calculent à la lecture. Retour arrière : restaurer la sauvegarde d\'avant migration.',
        );
    }
}
