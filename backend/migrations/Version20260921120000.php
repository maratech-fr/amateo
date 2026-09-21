<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lot N — « une seule déclaration d'erreur FBI vivante par conflit ». On rattache une
 * entrée `fbi_correction` née d'un conflit du radar à l'EMPREINTE de ce conflit
 * (`conflict_fingerprint`, nullable), pour pouvoir FERMER la déclaration précédente
 * quand le gestionnaire re-déclare sur une autre rencontre / un autre champ du MÊME
 * conflit.
 *
 * FORME retenue : une colonne scalaire NULLABLE sur `fbi_correction`, pas de table de
 * liaison ni de FK. C'est la forme la plus simple qui tienne : la relation « le conflit
 * qui a ouvert cette entrée » est un 0..1 au point d'écriture, et l'empreinte EST déjà
 * l'identité durable du litige côté résolution ({@see App\Entity\ConflictResolution}
 * — même longueur 255). Nullable parce que les entrées nées d'un arbitrage de dépôt
 * (import xlsx / canal API / revue d'écart) n'ont PAS de conflit derrière elles.
 *
 * La colonne ne participe à AUCUN index d'unicité (l'index partiel des entrées ouvertes
 * reste `(club, saison, rencontre, champ) WHERE closed_at IS NULL`) et vit sur la MÊME
 * ligne que le reste : les garanties de suppression existantes (purge de saison en DQL
 * DELETE de l'entité, retrait par rencontre) la balaient avec la ligne, sans échappatoire.
 *
 * Écrit à la main : `make migration-diff` est inopérant tant que doctrine/dbal reste
 * < 4.5 (backend.md).
 */
final class Version20260921120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'fbi_correction.conflict_fingerprint (lot N) : rattache une entrée « erreur FBI » au conflit du radar qui l\'a ouverte (une seule déclaration vivante par conflit).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE fbi_correction ADD conflict_fingerprint VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE fbi_correction DROP conflict_fingerprint');
    }
}
