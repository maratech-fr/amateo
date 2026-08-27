<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * P2-54 RMM-9 PR-1 — la durée de match devient un réglage par catégorie.
 *
 * `sport_category.match_minutes` / `warmup_minutes` : deux colonnes NULLABLES.
 * NULL = « suit le défaut de sa famille » (MatchDurationResolver) — donc AUCUN
 * backfill, aucun seed : toutes les catégories existantes restent sur leur
 * défaut de famille tant que le gestionnaire ne saisit rien.
 *
 * Écrite à la main : `doctrine/dbal` 4.4.4 rend `make migration-diff` inopérant
 * (.claude/rules/backend.md). Pas de RLS/grant : la table existe déjà et sa
 * politique tenant couvre ces colonnes.
 */
final class Version20260827120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'P2-54 RMM-9 PR-1: sport_category.match_minutes + warmup_minutes (nullable, défaut de famille).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sport_category ADD match_minutes INT DEFAULT NULL, ADD warmup_minutes INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sport_category DROP match_minutes, DROP warmup_minutes');
    }
}
