<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * P4-128 n°1 — une CASE (gymnase, jour, heure) ne peut porter la MÊME équipe deux fois dans la
 * MÊME portée : index UNIQUE sur `reservation`.
 *
 * La garde applicative amont (`ReservationGroupOccupancy`) refuse déjà le doublon à l'écriture
 * séquentielle, mais une course (deux requêtes concurrentes) la franchit : la base est le dernier
 * filet. L'index couvre (club, saison, PLAN, gymnase, jour, heure, équipe) — la portée du plan
 * compte (socle `schedule_plan_id` NULL vs copie de période), deux mondes distincts (ADR-0002).
 *
 * NULLS NOT DISTINCT (PG 16) : sans lui, deux réservations de SOCLE (plan NULL) de la même équipe
 * sur la même case seraient vues comme distinctes et l'unicité ne les rejetterait jamais.
 * Précédent : `uniq_implicit_rule_club_season_plan_key` (Version20260817130000). Écrite à la main :
 * `make migration-diff` est inopérant tant que doctrine/dbal reste < 4.5. Aucun dédoublonnage en
 * `up` : zéro doublon constaté sur les bases réelles.
 */
final class Version20261008100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'P4-128 n°1: index UNIQUE reservation (club, season, plan, venue, day, start_time, team) NULLS NOT DISTINCT.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE UNIQUE INDEX uniq_reservation_case_team ON reservation (club_id, season_id, schedule_plan_id, venue_id, day_of_week, start_time, team_id) NULLS NOT DISTINCT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_reservation_case_team');
    }
}
