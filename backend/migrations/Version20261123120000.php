<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * L'abonnement devient une ligne par club et par saison : `club_subscription` (offre, réglée ou non,
 * date du règlement, origine), clé unique (club, année de saison — 2026 = saison 2026-2027). Les
 * deux champs du club (`plan_id`, `paid_season_year`) disparaissent.
 *
 * Conversion, sans changer l'offre effective d'aucun club : pour chaque club dont l'offre stockée
 * n'est pas Découverte et qui a une année réglée, une ligne réglée (origine « conversion ») par
 * saison EXISTANTE couverte par cette année, plus une ligne pour l'année « suivante » si elle est
 * réglée — « suivante » = `max(année en cours + 1, année d'horloge + 1)`, l'année que la bascule
 * créera (l'année en cours ne dépasse l'année d'horloge que pour un club dont aucune saison n'a
 * commencé : sa première saison). Un club en Découverte, ou dont l'offre n'a pas d'année réglée,
 * n'a pas de ligne. Seul écart : un club en Découverte marqué « saison suivante payée » pouvait
 * basculer, il ne le peut plus. La date de règlement des lignes converties est celle de la
 * conversion. La migration ne s'arrête sur aucune donnée : elle écrit son décompte.
 *
 * Accès : RLS du patron Version20261122140000 (ENABLE/FORCE, `tenant_isolation`, `admin_all`,
 * `readonly_tenant` + GRANT SELECT au rôle de lecture). Un seul écrivain au sens de la base : le
 * rôle applicatif LIT et SUPPRIME (effacement du club), `INSERT` et `UPDATE` lui sont RETIRÉS —
 * l'`ALTER DEFAULT PRIVILEGES` de Version20260703120000 les lui confère sur toute table neuve, un
 * GRANT restreint ne les retire pas (patron du REVOKE de Version20260828170000). Seule la
 * connexion propriétaire écrit une ligne (`ClubSubscriptionWriter`).
 *
 * Part dans la mise en production unique du lot conception, site fermé : la release précédente lit
 * les colonnes supprimées. Irréversible.
 */
final class Version20261123120000 extends AbstractMigration
{
    private const string TENANT_PREDICATE = 'club_id = NULLIF(current_setting(\'app.club_id\', true), \'\')::uuid';

    /** L'année de saison d'une date (pivot du 15 juillet), miroir SQL de `SeasonResolver::seasonYear`. */
    private const string SEASON_YEAR_OF_START = '(EXTRACT(YEAR FROM s.start_date)::int - CASE WHEN to_char(s.start_date, \'MM-DD\') < \'07-15\' THEN 1 ELSE 0 END)';

    public function getDescription(): string
    {
        return 'club_subscription: one subscription line per club and season (converted from club.plan_id / club.paid_season_year, both dropped); RLS, read-only role, INSERT/UPDATE revoked from the application role.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE club_subscription (id UUID NOT NULL, club_id UUID NOT NULL, season_year INT NOT NULL, plan_id UUID NOT NULL, paid_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, origin VARCHAR(20) NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_club_subscription_season ON club_subscription (club_id, season_year)');
        $this->addSql('CREATE INDEX idx_club_subscription_plan ON club_subscription (plan_id)');
        $this->addSql('ALTER TABLE club_subscription ADD CONSTRAINT fk_club_subscription_club FOREIGN KEY (club_id) REFERENCES club (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE club_subscription ADD CONSTRAINT fk_club_subscription_plan FOREIGN KEY (plan_id) REFERENCES subscription_plan (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE club_subscription ADD CONSTRAINT chk_club_subscription_origin CHECK (origin IN (\'support\', \'conversion\', \'seed\'))');

        $this->writeTally();

        $this->addSql(\sprintf(<<<'SQL'
            INSERT INTO club_subscription (id, club_id, season_year, plan_id, paid_at, origin, created_at, updated_at)
            SELECT gen_random_uuid(), years.club_id, years.season_year, years.plan_id, now(), 'conversion', now(), now()
            FROM (%s) years
            SQL, $this->convertedYears()));

        $this->addSql('ALTER TABLE club DROP plan_id, DROP paid_season_year');

        $appRole = $this->connection->fetchOne('SELECT rolname FROM pg_roles WHERE rolname IN (\'app_user\', \'amateo_app\') ORDER BY (rolname = \'amateo_app\') DESC LIMIT 1');
        $hasOwner = (bool) $this->connection->fetchOne('SELECT 1 FROM pg_roles WHERE rolname = \'amateo_owner\'');
        $hasRead = (bool) $this->connection->fetchOne('SELECT 1 FROM pg_roles WHERE rolname = \'amateo_read\'');

        if (\is_string($appRole)) {
            $this->addSql('REVOKE INSERT, UPDATE ON club_subscription FROM ' . $appRole);
            $this->addSql('GRANT SELECT, DELETE ON club_subscription TO ' . $appRole);
            $this->addSql('ALTER TABLE public.club_subscription ENABLE ROW LEVEL SECURITY');
            $this->addSql('ALTER TABLE public.club_subscription FORCE ROW LEVEL SECURITY');
            $this->addSql(\sprintf(
                'CREATE POLICY tenant_isolation ON public.club_subscription FOR ALL TO ' . $appRole . ' USING (%s) WITH CHECK (%s)',
                self::TENANT_PREDICATE,
                self::TENANT_PREDICATE,
            ));
        }

        if ($hasOwner) {
            $this->addSql('CREATE POLICY admin_all ON public.club_subscription FOR ALL TO amateo_owner USING (true) WITH CHECK (true)');
        }

        // Rôle de lecture seule : aucun secret (offre, saison, date de règlement, origine).
        if ($hasRead) {
            $this->addSql('GRANT SELECT ON public.club_subscription TO amateo_read');
            $this->addSql(\sprintf(
                'CREATE POLICY readonly_tenant ON public.club_subscription FOR SELECT TO amateo_read USING (%s)',
                self::TENANT_PREDICATE,
            ));
        }
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigration(
            'Les deux champs du club ne se recréent pas depuis les lignes par saison sans perte (une saison réglée n\'en couvre plus d\'autre). Retour arrière : restaurer la sauvegarde d\'avant migration.',
        );
    }

    /**
     * Les (club, année, offre) à convertir : les saisons existantes couvertes par l'année réglée,
     * plus l'année « suivante » si elle est réglée. Lu sur l'état d'avant la migration.
     */
    private function convertedYears(): string
    {
        $seasonYear = self::SEASON_YEAR_OF_START;
        $clockYear = '(EXTRACT(YEAR FROM CURRENT_DATE)::int - CASE WHEN to_char(CURRENT_DATE, \'MM-DD\') < \'07-15\' THEN 1 ELSE 0 END)';

        return <<<SQL
            WITH paying AS (
                SELECT c.id AS club_id, c.plan_id, c.paid_season_year
                FROM club c
                JOIN subscription_plan p ON p.id = c.plan_id
                WHERE p.code <> 'decouverte' AND c.paid_season_year IS NOT NULL
            )
            SELECT paying.club_id, $seasonYear AS season_year, paying.plan_id
            FROM paying
            JOIN season s ON s.club_id = paying.club_id
            WHERE $seasonYear <= paying.paid_season_year
            UNION
            SELECT paying.club_id, next_year.season_year, paying.plan_id
            FROM paying
            CROSS JOIN LATERAL (
                SELECT GREATEST(
                    $clockYear,
                    COALESCE((
                        SELECT MIN($seasonYear) FROM season s
                        WHERE s.club_id = paying.club_id
                        HAVING bool_and(s.start_date > CURRENT_DATE)
                    ), $clockYear)
                ) + 1 AS season_year
            ) next_year
            WHERE next_year.season_year <= paying.paid_season_year
            SQL;
    }

    /** Le décompte, écrit avant la conversion : clubs convertis, lignes, clubs en Découverte marqués réglés pour une saison à venir. */
    private function writeTally(): void
    {
        $clubs = (int) $this->connection->fetchOne(\sprintf('SELECT COUNT(DISTINCT club_id) FROM (%s) years', $this->convertedYears()));
        $lines = (int) $this->connection->fetchOne(\sprintf('SELECT COUNT(*) FROM (%s) years', $this->convertedYears()));
        $seasonYear = self::SEASON_YEAR_OF_START;
        $decouverteAhead = (int) $this->connection->fetchOne(<<<SQL
            SELECT COUNT(*)
            FROM club c
            LEFT JOIN subscription_plan p ON p.id = c.plan_id
            WHERE (c.plan_id IS NULL OR p.code = 'decouverte')
              AND c.paid_season_year IS NOT NULL
              AND c.paid_season_year > COALESCE((SELECT MAX($seasonYear) FROM season s WHERE s.club_id = c.id AND s.start_date <= CURRENT_DATE), (SELECT MIN($seasonYear) FROM season s WHERE s.club_id = c.id), 0)
            SQL);

        $this->write(\sprintf('Abonnement par saison : %d club(s) converti(s), %d ligne(s) ; %d club(s) en Découverte marqué(s) réglé(s) pour une saison à venir (sans ligne).', $clubs, $lines, $decouverteAhead));
    }
}
