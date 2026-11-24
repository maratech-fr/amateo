<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Les gymnases d'une période (lot 4 conception, PR 3b) : une ligne `plan_venue` par (plan de période, gymnase) —
 * participe, effet de fermeture levé, masque de jours. Aucune ligne pour le plan de saison (aucun lecteur).
 *
 * Dans l'ordre, en une transaction :
 *  1. La table et sa clé composée (plan, club) vers le plan en cascade : une ligne ne désigne jamais le plan d'un
 *     autre club (une vérification de clé étrangère ignore la RLS). Pas de clé vers le gymnase : « Charger » efface
 *     puis réinsère les gymnases.
 *  2. Chaque couple (plan de période, gymnase de sa saison) : son réglage s'il existe, sinon le défaut. « Hors
 *     service » (DISABLED) devient « ne participe pas », le masque restant tel quel (dormant) ; « grille ressaisie »
 *     (BLANK) devient « effet de fermeture levé » ; le masque passe tel quel. Sans réglage : participe, fermeture non
 *     levée, sans masque.
 *  3. Arrêt si le nombre de lignes n'est pas « gymnases × plans de période » de chaque saison (deux réglages pour un
 *     même couple, par exemple). L'unicité (plan, gymnase) n'est posée qu'ensuite, pour que ce compte parle le premier.
 *  4. Suppression de `venue_period_override`.
 *  5. RLS active et forcée (patron Version20261128120000).
 *
 * Part dans la mise en production unique du lot conception, site fermé : la release précédente lit la table
 * supprimée (dérogation écrite à la règle « compatible une release en arrière »). Irréversible.
 */
final class Version20261129120000 extends AbstractMigration
{
    private const string TENANT_PREDICATE = 'club_id = NULLIF(current_setting(\'app.club_id\', true), \'\')::uuid';

    public function getDescription(): string
    {
        return 'Les gymnases d\'une période : plan_venue (une ligne par plan de période et gymnase, réglages de période convertis) ; venue_period_override supprimée.';
    }

    public function isTransactional(): bool
    {
        return true;
    }

    public function up(Schema $schema): void
    {
        // 1. La table.
        $this->addSql('CREATE TABLE plan_venue (id UUID NOT NULL, version INT NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, club_id UUID NOT NULL, season_id UUID NOT NULL, schedule_plan_id UUID NOT NULL, venue_id UUID NOT NULL, participates BOOLEAN NOT NULL, closure_lifted BOOLEAN DEFAULT false NOT NULL, day_overrides JSON DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_plan_venue_season ON plan_venue (season_id)');
        $this->addSql('CREATE INDEX idx_plan_venue_venue ON plan_venue (venue_id)');
        $this->addSql('ALTER TABLE plan_venue ADD CONSTRAINT fk_plan_venue_schedule_plan FOREIGN KEY (schedule_plan_id, club_id) REFERENCES schedule_plan (id, club_id) ON DELETE CASCADE');

        // 2. Chaque couple (plan de période, gymnase) : le réglage converti, sinon le défaut.
        $this->addSql(<<<'SQL'
            INSERT INTO plan_venue (id, version, created_at, updated_at, club_id, season_id, schedule_plan_id, venue_id, participates, closure_lifted, day_overrides)
            SELECT gen_random_uuid(), 1, now(), now(), p.club_id, p.season_id, p.id, v.id,
              o.mode IS DISTINCT FROM 'DISABLED',
              o.mode IS NOT DISTINCT FROM 'BLANK',
              o.day_overrides
            FROM schedule_plan p
            JOIN venue v ON v.season_id = p.season_id
            LEFT JOIN venue_period_override o ON o.schedule_plan_id = p.id AND o.venue_id = v.id
            WHERE p.type <> 'SEASON'
            SQL);

        // 3. Une ligne par (plan de période, gymnase), ni plus ni moins ; puis l'unicité.
        $this->addSql(<<<'SQL'
            DO $$
            DECLARE
              written INT;
              expected INT;
            BEGIN
              SELECT COUNT(*) INTO written FROM plan_venue;
              SELECT COUNT(*) INTO expected FROM schedule_plan p JOIN venue v ON v.season_id = p.season_id WHERE p.type <> 'SEASON';
              IF written <> expected THEN
                RAISE EXCEPTION 'Les gymnases d''une période : % lignes pour % couples (plan de période, gymnase), la conversion s''annule.', written, expected;
              END IF;
            END
            $$
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_plan_venue ON plan_venue (schedule_plan_id, venue_id)');

        // 4. Ce que les lignes remplacent.
        $this->addSql('DROP TABLE venue_period_override');

        // 5. RLS.
        $this->addSql('ALTER TABLE public.plan_venue ENABLE ROW LEVEL SECURITY');
        $this->addSql('ALTER TABLE public.plan_venue FORCE ROW LEVEL SECURITY');
        $appRole = $this->connection->fetchOne('SELECT rolname FROM pg_roles WHERE rolname IN (\'app_user\', \'amateo_app\') ORDER BY (rolname = \'amateo_app\') DESC LIMIT 1');
        if (\is_string($appRole)) {
            $this->addSql('GRANT SELECT, INSERT, UPDATE, DELETE ON plan_venue TO ' . $appRole);
            $this->addSql(\sprintf(
                'CREATE POLICY tenant_isolation ON public.plan_venue FOR ALL TO ' . $appRole . ' USING (%s) WITH CHECK (%s)',
                self::TENANT_PREDICATE,
                self::TENANT_PREDICATE,
            ));
        }
        if ((bool) $this->connection->fetchOne('SELECT 1 FROM pg_roles WHERE rolname = \'amateo_owner\'')) {
            $this->addSql('CREATE POLICY admin_all ON public.plan_venue FOR ALL TO amateo_owner USING (true) WITH CHECK (true)');
        }
        if ((bool) $this->connection->fetchOne('SELECT 1 FROM pg_roles WHERE rolname = \'amateo_read\'')) {
            $this->addSql('GRANT SELECT ON public.plan_venue TO amateo_read');
            $this->addSql(\sprintf('CREATE POLICY readonly_tenant ON public.plan_venue FOR SELECT TO amateo_read USING (%s)', self::TENANT_PREDICATE));
        }
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'Les réglages de gymnase d\'une période ne se recréent pas sans risque depuis les lignes des plans. Retour arrière : restaurer la sauvegarde d\'avant migration.',
        );
    }
}
