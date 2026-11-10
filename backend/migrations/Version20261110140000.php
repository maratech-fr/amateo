<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Journal de nouveautés — corps mis en forme d'une note (`release_note.body_rich`, JSONB).
 *
 * Facultatif (NULL = note texte, rendue comme avant) et rétro-compatible : aucune note existante
 * n'est réécrite, et `body` reste le texte brut — dérivé du corps riche par le serveur — qu'un
 * ancien bundle continue de lire. Le contenu est la liste blanche de `App\Service\ReleaseNoteRichDoc`.
 *
 * Aucun GRANT neuf : le GRANT de table couvre la colonne ajoutée. Écrite à la main :
 * `make migration-diff` est inopérant tant que `doctrine/dbal` reste < 4.5.
 */
final class Version20261110140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Release note: add body_rich (JSONB NULL) — whitelisted formatted body, body stays its plain-text mirror.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE release_note ADD body_rich JSONB DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE release_note DROP body_rich');
    }
}
