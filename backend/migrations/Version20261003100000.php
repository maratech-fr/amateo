<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * BCK-33 — rend PARTIELLE l'unicité du code FFBB d'un club : `WHERE NOT is_demo`.
 *
 * Un club de DÉMONSTRATION peut désormais porter le même code FFBB qu'un vrai club
 * (l'animateur rejoue « son » club réel en démo) sans entrer en collision d'unicité,
 * tout en gardant l'unicité entre clubs RÉELS — c'est elle qui empêche deux espaces
 * concurrents sur un même ARA. Les chemins d'inscription/approbation résolvent le club
 * d'un code via `ClubRepository::findRealByFfbbCode` (is_demo = false), jamais un
 * `findOneBy` nu : une vraie inscription sur le code d'une démo n'entre donc pas dans la démo.
 *
 * Doctrine ne sait pas exprimer un index PARTIEL en attribut : l'attribut
 * `UniqueConstraint` (et le `unique: true` de colonne) ont été retirés de l'entité Club,
 * l'index vit ICI et seulement ici (même posture que les FK posées à la main).
 *
 * Écrite à la main (`make migration-diff` inopérant tant que doctrine/dbal < 4.5).
 * RLS sans objet (migrations sous `amateo_owner`, BYPASSRLS). Sûre : l'index PLEIN
 * garantit qu'aucun doublon de code n'existe aujourd'hui, le passage au partiel ne
 * fait que RELÂCHER la contrainte sur les lignes démo.
 */
final class Version20261003100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'BCK-33: l\'unicité du code FFBB d\'un club devient partielle (WHERE NOT is_demo) — une démo peut squatter le code d\'un vrai club.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_club_ffbb_club_code');
        $this->addSql('CREATE UNIQUE INDEX uniq_club_ffbb_club_code ON club (ffbb_club_code) WHERE NOT is_demo');
    }

    public function down(Schema $schema): void
    {
        // ⚠ Reverse SEULEMENT jouable si aucune démo ne partage le code d'un vrai club à
        // cet instant (sinon l'index PLEIN échouerait sur le doublon) — acceptable en
        // dev/test. En production, on restaure la sauvegarde antérieure, jamais ce down().
        $this->addSql('DROP INDEX uniq_club_ffbb_club_code');
        $this->addSql('CREATE UNIQUE INDEX uniq_club_ffbb_club_code ON club (ffbb_club_code)');
    }
}
