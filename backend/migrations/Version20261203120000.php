<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Service\Basketball\VenueLabelNormalizer;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Compétition et engagement séparés (lot 7, PR 3b) : `engagement` porte l'équipe d'une compétition, son
 * libellé FBI, sa poule, ses adversaires et ses journées attendues ; ces six colonnes quittent `competition`,
 * avec l'unicité (club, saison, équipe, nom) : plus aucune unicité sur le nom d'une compétition.
 *
 * Conversion : UNE ligne d'engagement par ligne de compétition, rien de réuni (deux compétitions de même nom
 * restent deux compétitions), aucune compétition ne change d'identifiant ni de nom, aucun match n'est repointé.
 * Elle s'ARRÊTE (exception PostgreSQL, rien d'écrit) plutôt que de fabriquer un engagement : sur un match dont
 * l'équipe n'est pas celle de sa compétition, ou sur deux compétitions d'une même équipe dont les noms ne
 * diffèrent que par la casse, les accents ou la ponctuation (la porte, qui compare les noms normalisés, ne
 * saurait plus les départager).
 *
 * Clés COMPOSÉES avec le club, posées à la main (invisibles de Doctrine) : `engagement (competition_id,
 * club_id)` → `competition (id, club_id)` `ON DELETE CASCADE` ; `fixture (competition_id, team_id, club_id)`
 * → `engagement (competition_id, team_id, club_id)` `ON DELETE RESTRICT` (un match nul en compétition, un
 * amical, n'est pas contrôlé : MATCH SIMPLE). Aucune clé vers l'équipe. RLS : patron Version20261122140000.
 *
 * Part dans la mise en production unique du lot conception, site fermé : la release précédente lit les
 * colonnes supprimées. Irréversible.
 */
final class Version20261203120000 extends AbstractMigration
{
    private const string TENANT_PREDICATE = 'club_id = NULLIF(current_setting(\'app.club_id\', true), \'\')::uuid';

    /** La clé de nom de la porte ({@see VenueLabelNormalizer::normalize}), en SQL. */
    private const string NORMALIZED_NAME = 'trim(regexp_replace(lower(translate(%s, \'àáâãäåçèéêëìíîïñòóôõöùúûüýÿÀÁÂÃÄÅÇÈÉÊËÌÍÎÏÑÒÓÔÕÖÙÚÛÜÝ\', \'aaaaaaceeeeiiiinooooouuuuyyAAAAAACEEEEIIIINOOOOOUUUUY\')), \'[^a-z0-9]+\', \' \', \'g\'))';

    public function getDescription(): string
    {
        return 'engagement : une ligne par compétition (sans réunion), six colonnes et l\'unicité de nom retirées de competition, clés composées avec le club.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(\sprintf(
            <<<'SQL'
                DO $$
                BEGIN
                  IF EXISTS (SELECT 1 FROM fixture f JOIN competition c ON c.id = f.competition_id WHERE f.team_id <> c.team_id OR f.club_id <> c.club_id) THEN
                    RAISE EXCEPTION 'Conversion des compétitions en engagements : %% match(s) hors de l''équipe de leur compétition, la migration s''arrête.',
                      (SELECT COUNT(*) FROM fixture f JOIN competition c ON c.id = f.competition_id WHERE f.team_id <> c.team_id OR f.club_id <> c.club_id);
                  END IF;
                  IF EXISTS (
                    SELECT 1 FROM competition a JOIN competition b
                      ON a.club_id = b.club_id AND a.season_id = b.season_id AND a.team_id = b.team_id AND a.id < b.id
                     AND %1$s = %2$s
                  ) THEN
                    RAISE EXCEPTION 'Conversion des compétitions en engagements : deux compétitions d''une même équipe ne diffèrent que par la casse ou les accents, la migration s''arrête.';
                  END IF;
                END
                $$
                SQL,
            \sprintf(self::NORMALIZED_NAME, 'a.name'),
            \sprintf(self::NORMALIZED_NAME, 'b.name'),
        ));

        $this->addSql('CREATE TABLE engagement (id UUID NOT NULL, version INT DEFAULT 1 NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, club_id UUID NOT NULL, season_id UUID NOT NULL, competition_id UUID NOT NULL, team_id UUID NOT NULL, fbi_team_label VARCHAR(180) DEFAULT NULL, ffbb_poule_id VARCHAR(64) DEFAULT NULL, ffbb_poule_name VARCHAR(120) DEFAULT NULL, ffbb_poule_opponents JSON DEFAULT NULL, expected_matchdays INT DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_engagement_club_season ON engagement (club_id, season_id)');
        $this->addSql('CREATE INDEX idx_engagement_team ON engagement (team_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_engagement_competition_team ON engagement (competition_id, team_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_engagement_competition_team_club ON engagement (competition_id, team_id, club_id)');

        // Une ligne d'engagement par ligne de compétition, celle de son ancienne équipe.
        $this->addSql('INSERT INTO engagement (id, version, created_at, updated_at, club_id, season_id, competition_id, team_id, fbi_team_label, ffbb_poule_id, ffbb_poule_name, ffbb_poule_opponents, expected_matchdays) SELECT gen_random_uuid(), 1, created_at, updated_at, club_id, season_id, id, team_id, fbi_team_label, ffbb_poule_id, ffbb_poule_name, ffbb_poule_opponents, expected_matchdays FROM competition');
        $this->addSql(<<<'SQL'
            DO $$
            BEGIN
              IF (SELECT COUNT(*) FROM engagement) <> (SELECT COUNT(*) FROM competition) THEN
                RAISE EXCEPTION 'Conversion des compétitions en engagements : % engagements pour % compétitions, la migration s''arrête.',
                  (SELECT COUNT(*) FROM engagement), (SELECT COUNT(*) FROM competition);
              END IF;
            END
            $$
            SQL);

        $this->addSql('DROP INDEX uniq_competition_team_name');
        $this->addSql('DROP INDEX idx_competition_team');
        $this->addSql('ALTER TABLE competition DROP team_id, DROP fbi_team_label, DROP ffbb_poule_id, DROP ffbb_poule_name, DROP ffbb_poule_opponents, DROP expected_matchdays');

        $this->addSql('CREATE UNIQUE INDEX uniq_competition_id_club ON competition (id, club_id)');
        $this->addSql('ALTER TABLE engagement ADD CONSTRAINT fk_engagement_competition_club FOREIGN KEY (competition_id, club_id) REFERENCES competition (id, club_id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE fixture ADD CONSTRAINT fk_fixture_engagement_club FOREIGN KEY (competition_id, team_id, club_id) REFERENCES engagement (competition_id, team_id, club_id) ON DELETE RESTRICT');

        $appRole = $this->connection->fetchOne('SELECT rolname FROM pg_roles WHERE rolname IN (\'app_user\', \'amateo_app\') ORDER BY (rolname = \'amateo_app\') DESC LIMIT 1');
        $hasOwner = (bool) $this->connection->fetchOne('SELECT 1 FROM pg_roles WHERE rolname = \'amateo_owner\'');
        $hasRead = (bool) $this->connection->fetchOne('SELECT 1 FROM pg_roles WHERE rolname = \'amateo_read\'');

        if (\is_string($appRole)) {
            $this->addSql('GRANT SELECT, INSERT, UPDATE, DELETE ON engagement TO ' . $appRole);
            $this->addSql('ALTER TABLE public.engagement ENABLE ROW LEVEL SECURITY');
            $this->addSql('ALTER TABLE public.engagement FORCE ROW LEVEL SECURITY');
            $this->addSql(\sprintf(
                'CREATE POLICY tenant_isolation ON public.engagement FOR ALL TO ' . $appRole . ' USING (%s) WITH CHECK (%s)',
                self::TENANT_PREDICATE,
                self::TENANT_PREDICATE,
            ));
        }

        if ($hasOwner) {
            $this->addSql('CREATE POLICY admin_all ON public.engagement FOR ALL TO amateo_owner USING (true) WITH CHECK (true)');
        }

        // Rôle de lecture seule : comme `competition`, qui portait ces colonnes.
        if ($hasRead) {
            $this->addSql('GRANT SELECT ON public.engagement TO amateo_read');
            $this->addSql(\sprintf(
                'CREATE POLICY readonly_tenant ON public.engagement FOR SELECT TO amateo_read USING (%s)',
                self::TENANT_PREDICATE,
            ));
        }
    }

    public function postUp(Schema $schema): void
    {
        $this->write(\sprintf(
            'Engagements : %d compétitions, %d engagements, %d matchs.',
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM competition'),
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM engagement'),
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM fixture'),
        ));
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'Les colonnes d\'équipe et de poule supprimées de competition ne se recréent pas sans risque depuis les engagements. Retour arrière : restaurer la sauvegarde d\'avant migration.',
        );
    }
}
