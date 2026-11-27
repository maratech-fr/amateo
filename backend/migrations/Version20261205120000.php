<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Le suivi fédéral quitte le match (lot 7, PR 6b) :
 *  - chaque compétition reçoit sa source (`competition.source`, obligatoire) : `FBI` si elle porte un identifiant
 *    fédéral, un match venu de la fédération (numéro FBI ou rencontre du canal API) ou un match déjà saisi ou validé
 *    (trace fédérale) ; sinon `MANUAL`. « Amicaux », la compétition d'office, reste `MANUAL` ;
 *  - `fixture_federal_tracking` porte l'état fédéral (à saisir, saisi, validé), les écarts ouverts, le mémo
 *    « FBI affiche encore … » et le libellé de salle gardé : une ligne par match d'une compétition FBI, et une pour
 *    le match d'une compétition hors FBI qui portait encore un écart, un mémo ou un libellé gardé (rien ne se perd) ;
 *  - le statut stocké n'a plus que deux valeurs (CHECK) : « à placer » reste « à placer », « placé » reste
 *    « placé » ; « saisi » et « validé » deviennent « placé » si gymnase et heure, sinon « à placer », l'état fédéral
 *    gardant « saisi » ou « validé » ;
 *  - `fixture` perd `pending_deviations`, `fbi_echo`, `kept_venue_label`.
 *
 * Elle s'ARRÊTE (exception PostgreSQL, rien d'écrit) si un match d'une compétition FBI change de stade servi (validé
 * si son suivi est « validé », saisi s'il est « saisi », sinon son placement), ou si un match d'une compétition FBI
 * reste sans ligne de suivi.
 *
 * Clé COMPOSÉE avec le club, posée à la main (invisible de Doctrine) : `fixture_federal_tracking (fixture_id,
 * club_id)` → `fixture (id, club_id)` `ON DELETE CASCADE`. RLS : patron Version20261203120000.
 *
 * Part dans la mise en production unique du lot conception, site fermé : la release précédente lit les colonnes
 * supprimées. Irréversible.
 */
final class Version20261205120000 extends AbstractMigration
{
    private const string TENANT_PREDICATE = 'club_id = NULLIF(current_setting(\'app.club_id\', true), \'\')::uuid';

    public function getDescription(): string
    {
        return 'fixture_federal_tracking : le suivi fédéral quitte le match ; competition.source (FBI|MANUAL) ; statut stocké à deux valeurs.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE competition ADD source VARCHAR(10) DEFAULT NULL');
        $this->addSql(<<<'SQL'
            UPDATE competition c SET source = CASE
              WHEN c.default_friendly THEN 'MANUAL'
              WHEN c.ffbb_competition_id IS NOT NULL THEN 'FBI'
              WHEN EXISTS (
                SELECT 1 FROM fixture f WHERE f.competition_id = c.id
                  AND (f.external_ref IS NOT NULL OR f.ffbb_rencontre_id IS NOT NULL OR f.status IN ('SUBMITTED', 'VALIDATED'))
              ) THEN 'FBI'
              ELSE 'MANUAL'
            END
            SQL);
        $this->addSql('ALTER TABLE competition ALTER source SET NOT NULL');

        // Le stade servi de chaque match, avant la conversion : la preuve que rien ne bouge pour un match suivi.
        $this->addSql('CREATE TEMPORARY TABLE lot7_stage_before AS SELECT id, status FROM fixture');

        $this->addSql('CREATE TABLE fixture_federal_tracking (fixture_id UUID NOT NULL, version INT DEFAULT 1 NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, club_id UUID NOT NULL, season_id UUID NOT NULL, state VARCHAR(20) NOT NULL, pending_deviations JSON DEFAULT \'[]\' NOT NULL, fbi_echo JSON DEFAULT NULL, kept_venue_label VARCHAR(180) DEFAULT NULL, PRIMARY KEY (fixture_id))');
        $this->addSql('CREATE INDEX idx_fixture_federal_tracking_club_season ON fixture_federal_tracking (club_id, season_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_fixture_id_club ON fixture (id, club_id)');
        $this->addSql('ALTER TABLE fixture_federal_tracking ADD CONSTRAINT fk_fixture_federal_tracking_fixture_club FOREIGN KEY (fixture_id, club_id) REFERENCES fixture (id, club_id) ON DELETE CASCADE');

        $this->addSql(<<<'SQL'
            INSERT INTO fixture_federal_tracking (fixture_id, version, created_at, updated_at, club_id, season_id, state, pending_deviations, fbi_echo, kept_venue_label)
            SELECT f.id, 1, now(), now(), f.club_id, f.season_id,
                   CASE WHEN c.source = 'FBI' AND f.status IN ('SUBMITTED', 'VALIDATED') THEN f.status ELSE 'TO_ENTER' END,
                   f.pending_deviations, f.fbi_echo, f.kept_venue_label
              FROM fixture f JOIN competition c ON c.id = f.competition_id
             WHERE c.source = 'FBI'
                OR f.pending_deviations::jsonb <> '[]'::jsonb OR f.fbi_echo IS NOT NULL OR f.kept_venue_label IS NOT NULL
            SQL);
        $this->addSql(<<<'SQL'
            UPDATE fixture SET status = CASE WHEN venue_id IS NOT NULL AND kickoff_time IS NOT NULL THEN 'PLACED' ELSE 'UNPLACED' END
             WHERE status IN ('SUBMITTED', 'VALIDATED')
            SQL);
        $this->addSql('ALTER TABLE fixture ADD CONSTRAINT chk_fixture_status CHECK (status IN (\'UNPLACED\', \'PLACED\'))');

        $this->addSql(<<<'SQL'
            DO $$
            BEGIN
              IF EXISTS (
                SELECT 1 FROM fixture f JOIN competition c ON c.id = f.competition_id AND c.source = 'FBI'
                  LEFT JOIN fixture_federal_tracking t ON t.fixture_id = f.id
                 WHERE t.fixture_id IS NULL
              ) THEN
                RAISE EXCEPTION 'Conversion du suivi fédéral : un match d''une compétition FBI reste sans suivi, la migration s''arrête.';
              END IF;
              IF EXISTS (
                SELECT 1 FROM fixture f JOIN competition c ON c.id = f.competition_id AND c.source = 'FBI'
                  JOIN fixture_federal_tracking t ON t.fixture_id = f.id
                  JOIN lot7_stage_before b ON b.id = f.id
                 WHERE b.status <> CASE WHEN t.state IN ('SUBMITTED', 'VALIDATED') THEN t.state ELSE f.status END
              ) THEN
                RAISE EXCEPTION 'Conversion du suivi fédéral : % match(s) d''une compétition FBI changeraient de stade, la migration s''arrête.',
                  (SELECT COUNT(*) FROM fixture f JOIN competition c ON c.id = f.competition_id AND c.source = 'FBI'
                     JOIN fixture_federal_tracking t ON t.fixture_id = f.id
                     JOIN lot7_stage_before b ON b.id = f.id
                    WHERE b.status <> CASE WHEN t.state IN ('SUBMITTED', 'VALIDATED') THEN t.state ELSE f.status END);
              END IF;
            END
            $$
            SQL);

        $this->addSql('DROP TABLE lot7_stage_before');
        $this->addSql('ALTER TABLE fixture DROP pending_deviations, DROP fbi_echo, DROP kept_venue_label');

        $appRole = $this->connection->fetchOne('SELECT rolname FROM pg_roles WHERE rolname IN (\'app_user\', \'amateo_app\') ORDER BY (rolname = \'amateo_app\') DESC LIMIT 1');
        $hasOwner = (bool) $this->connection->fetchOne('SELECT 1 FROM pg_roles WHERE rolname = \'amateo_owner\'');
        $hasRead = (bool) $this->connection->fetchOne('SELECT 1 FROM pg_roles WHERE rolname = \'amateo_read\'');

        if (\is_string($appRole)) {
            $this->addSql('GRANT SELECT, INSERT, UPDATE, DELETE ON fixture_federal_tracking TO ' . $appRole);
            $this->addSql('ALTER TABLE public.fixture_federal_tracking ENABLE ROW LEVEL SECURITY');
            $this->addSql('ALTER TABLE public.fixture_federal_tracking FORCE ROW LEVEL SECURITY');
            $this->addSql(\sprintf(
                'CREATE POLICY tenant_isolation ON public.fixture_federal_tracking FOR ALL TO ' . $appRole . ' USING (%s) WITH CHECK (%s)',
                self::TENANT_PREDICATE,
                self::TENANT_PREDICATE,
            ));
        }

        if ($hasOwner) {
            $this->addSql('CREATE POLICY admin_all ON public.fixture_federal_tracking FOR ALL TO amateo_owner USING (true) WITH CHECK (true)');
        }

        // Rôle de lecture seule : comme `fixture`, qui portait ces colonnes.
        if ($hasRead) {
            $this->addSql('GRANT SELECT ON public.fixture_federal_tracking TO amateo_read');
            $this->addSql(\sprintf(
                'CREATE POLICY readonly_tenant ON public.fixture_federal_tracking FOR SELECT TO amateo_read USING (%s)',
                self::TENANT_PREDICATE,
            ));
        }
    }

    public function postUp(Schema $schema): void
    {
        $this->write(\sprintf(
            'Suivi fédéral : %d compétitions FBI, %d hors FBI ; %d lignes de suivi (%d à saisir, %d saisies, %d validées) ; %d matchs, %d placés, %d à placer ; %d matchs hors FBI.',
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM competition WHERE source = \'FBI\''),
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM competition WHERE source = \'MANUAL\''),
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM fixture_federal_tracking'),
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM fixture_federal_tracking WHERE state = \'TO_ENTER\''),
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM fixture_federal_tracking WHERE state = \'SUBMITTED\''),
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM fixture_federal_tracking WHERE state = \'VALIDATED\''),
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM fixture'),
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM fixture WHERE status = \'PLACED\''),
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM fixture WHERE status = \'UNPLACED\''),
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM fixture f JOIN competition c ON c.id = f.competition_id WHERE c.source = \'MANUAL\''),
        ));
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'Le statut saisi ou validé des matchs hors FBI et les colonnes de suivi retirées de fixture ne se retrouvent pas. Retour arrière : restaurer la sauvegarde d\'avant migration.',
        );
    }
}
