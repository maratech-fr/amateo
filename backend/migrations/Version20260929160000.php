<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * P4-271 — la semaine type A/B devient un TAG sur le créneau idéal, et les créneaux
 * de match partagés (rotations) disparaissent.
 *
 *  (a) `team_match_habit` gagne `week` (A|B|ALL, défaut 'ALL') ;
 *  (b) chaque membre de rotation devient une habitude taguée : position 0 → semaine A,
 *      1 → semaine B. Le tag est posé sur l'habitude MÊME-JOUR existante de l'équipe si
 *      elle existe, sinon une habitude est créée depuis le créneau de la rotation
 *      (jour/heure/gymnase) ;
 *  (c) déduplication pour la nouvelle unicité (club, saison, équipe) : on garde la ligne
 *      d'`updated_at` la plus récente (tiebreak id) ;
 *  (d) `DROP` de `match_slot_rotation_team` puis `match_slot_rotation`, et bascule de
 *      l'index unique de (club, saison, équipe, jour) vers (club, saison, équipe).
 *
 * ⚠ GARDE-FOU (condition de retour fondateur) : une rotation à 3 membres ou plus ne peut
 * pas être répartie en semaine A/B → ABORT avec un message clair, AVANT toute écriture.
 *
 * Écrite à la main (`make migration-diff` inopérant tant que doctrine/dbal < 4.5). Les
 * migrations tournent sous `amateo_owner` (BYPASSRLS) : la conversion cross-club n'a pas
 * besoin de GUC (patron Version20260928140000).
 */
final class Version20260929160000 extends AbstractMigration
{
    private const string TENANT_PREDICATE = 'club_id = NULLIF(current_setting(\'app.club_id\', true), \'\')::uuid';

    public function getDescription(): string
    {
        return 'P4-271: team_match_habit.week (A/B/ALL) + conversion des rotations en habitudes taguées + DROP des tables de rotation.';
    }

    public function up(Schema $schema): void
    {
        // GARDE-FOU : une rotation à ≥ 3 membres n'a pas de semaine A/B univoque.
        $tooMany = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM (SELECT rotation_id FROM match_slot_rotation_team GROUP BY rotation_id HAVING COUNT(*) >= 3) x',
        );
        $this->abortIf(
            $tooMany > 0,
            \sprintf(
                'P4-271 : %d créneau(x) de match partagé(s) compte(nt) 3 équipes ou plus — la semaine A/B ne peut '
                . 'pas leur être attribuée automatiquement. Ramenez ces créneaux à 2 équipes avant de migrer.',
                $tooMany,
            ),
        );

        // (a) La colonne, avec son défaut.
        $this->addSql('ALTER TABLE team_match_habit ADD week VARCHAR(8) DEFAULT \'ALL\' NOT NULL');

        // (b1) Tag posé sur l'habitude MÊME-JOUR existante (position 0 → A, sinon B).
        $this->addSql(
            'UPDATE team_match_habit h '
            . 'SET week = CASE WHEN t.position = 0 THEN \'A\' ELSE \'B\' END, updated_at = now() '
            . 'FROM match_slot_rotation_team t '
            . 'JOIN match_slot_rotation r ON r.id = t.rotation_id '
            . 'WHERE h.club_id = t.club_id AND h.season_id = t.season_id '
            . 'AND h.team_id = t.team_id AND h.day_of_week = r.day_of_week',
        );

        // (b2) Habitude CRÉÉE depuis le créneau de la rotation pour un membre sans habitude
        // ce jour-là (jour/heure/gymnase de la rotation, tag de semaine par la position).
        $this->addSql(
            'INSERT INTO team_match_habit '
            . '(id, version, created_at, updated_at, club_id, season_id, team_id, day_of_week, kickoff_time, venue_id, week) '
            . 'SELECT gen_random_uuid(), 1, now(), now(), t.club_id, t.season_id, t.team_id, r.day_of_week, '
            . 'r.kickoff_time, r.venue_id, CASE WHEN t.position = 0 THEN \'A\' ELSE \'B\' END '
            . 'FROM match_slot_rotation_team t '
            . 'JOIN match_slot_rotation r ON r.id = t.rotation_id '
            . 'WHERE NOT EXISTS ('
            . 'SELECT 1 FROM team_match_habit h '
            . 'WHERE h.club_id = t.club_id AND h.season_id = t.season_id '
            . 'AND h.team_id = t.team_id AND h.day_of_week = r.day_of_week)',
        );

        // (c) Dédup pour la nouvelle unicité (club, saison, équipe) : on garde la plus
        // récente (updated_at, id) par équipe, on supprime les autres.
        $this->addSql(
            'DELETE FROM team_match_habit h '
            . 'USING team_match_habit keep '
            . 'WHERE h.club_id = keep.club_id AND h.season_id = keep.season_id AND h.team_id = keep.team_id '
            . 'AND (keep.updated_at, keep.id) > (h.updated_at, h.id)',
        );

        // (d) Bascule de l'index unique et DROP des tables de rotation (leurs index,
        // policies et GRANT partent avec elles).
        $this->addSql('DROP INDEX uniq_team_match_habit_day');
        $this->addSql('CREATE UNIQUE INDEX uniq_team_match_habit_team ON team_match_habit (club_id, season_id, team_id)');
        $this->addSql('DROP TABLE match_slot_rotation_team');
        $this->addSql('DROP TABLE match_slot_rotation');
    }

    public function down(Schema $schema): void
    {
        // Reversal de SCHÉMA : les tables de rotation renaissent (VIDES — la conversion
        // (b) est one-way, la donnée d'origine est indiscernable des habitudes existantes),
        // l'index revient au grain jour, la colonne `week` part. Data loss assumée.
        $this->addSql('DROP INDEX uniq_team_match_habit_team');
        $this->addSql('CREATE UNIQUE INDEX uniq_team_match_habit_day ON team_match_habit (club_id, season_id, team_id, day_of_week)');
        $this->addSql('ALTER TABLE team_match_habit DROP week');

        $this->addSql('CREATE TABLE match_slot_rotation (id UUID NOT NULL, version INT DEFAULT 1 NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, club_id UUID NOT NULL, season_id UUID NOT NULL, venue_id UUID NOT NULL, day_of_week SMALLINT NOT NULL, kickoff_time TIME(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_match_slot_rotation_slot ON match_slot_rotation (club_id, season_id, venue_id, day_of_week, kickoff_time)');
        $this->addSql('CREATE INDEX idx_match_slot_rotation_club_season ON match_slot_rotation (club_id, season_id)');
        $this->addSql('CREATE INDEX idx_match_slot_rotation_venue ON match_slot_rotation (venue_id)');
        $this->addSql('CREATE TABLE match_slot_rotation_team (id UUID NOT NULL, club_id UUID NOT NULL, season_id UUID NOT NULL, rotation_id UUID NOT NULL, team_id UUID NOT NULL, position INT NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_match_slot_rotation_team ON match_slot_rotation_team (rotation_id, team_id)');
        $this->addSql('CREATE INDEX idx_match_slot_rotation_team_rotation ON match_slot_rotation_team (rotation_id)');
        $this->addSql('CREATE INDEX idx_match_slot_rotation_team_club_season ON match_slot_rotation_team (club_id, season_id)');

        $appRole = $this->connection->fetchOne('SELECT rolname FROM pg_roles WHERE rolname IN (\'app_user\', \'amateo_app\') ORDER BY (rolname = \'amateo_app\') DESC LIMIT 1');
        $hasOwner = (bool) $this->connection->fetchOne('SELECT 1 FROM pg_roles WHERE rolname = \'amateo_owner\'');
        foreach (['match_slot_rotation', 'match_slot_rotation_team'] as $table) {
            if (\is_string($appRole)) {
                $this->addSql(\sprintf('GRANT SELECT, INSERT, UPDATE, DELETE ON %s TO ' . $appRole, $table));
                $this->addSql(\sprintf('ALTER TABLE public.%s ENABLE ROW LEVEL SECURITY', $table));
                $this->addSql(\sprintf('ALTER TABLE public.%s FORCE ROW LEVEL SECURITY', $table));
                $this->addSql(\sprintf(
                    'CREATE POLICY tenant_isolation ON public.%s FOR ALL TO ' . $appRole . ' USING (%s) WITH CHECK (%s)',
                    $table,
                    self::TENANT_PREDICATE,
                    self::TENANT_PREDICATE,
                ));
            }
            if ($hasOwner) {
                $this->addSql(\sprintf('CREATE POLICY admin_all ON public.%s FOR ALL TO amateo_owner USING (true) WITH CHECK (true)', $table));
            }
        }
    }
}
