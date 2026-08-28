<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * SEC-12 (résiduel) — le SELECT des deux tables RLS hybrides devient « ouvert seulement
 * hors contexte tenant ».
 *
 * `club_user` et `coach_wish_token` doivent se lire AVANT que le GUC `app.club_id` soit
 * posé (résolution du club depuis le user au login, lookup du token public — c'est la
 * ligne lue qui PORTE le club). Le `USING (true)` historique payait ce besoin en ouvrant
 * le SELECT en permanence : dès qu'un contexte tenant existait, l'isolation de ces tables
 * ne reposait plus que sur le filtre Doctrine — une seule couche au lieu de deux.
 *
 * Nouveau prédicat : ouvert quand le GUC est ABSENT ou VIDE, étanche au tenant sinon.
 * ⚠ `TenantConnectionContext::clear()` pose la CHAÎNE VIDE, jamais NULL (asserté par
 * SuperAdminAccessTest) : le test du prédicat passe donc par NULLIF — un
 * `current_setting(...) IS NULL` nu serait faux après tout clear() et la branche droite
 * planterait en 22P02 sur `''::uuid`.
 *
 * Les lectures cross-tenant LÉGITIMES faites GUC posé (export RGPD multi-club,
 * effacement de compte) passent désormais par `TenantConnectionContext::runWithoutTenant()`
 * — l'ouverture redevient un geste explicite et localisé, plus un trou global.
 */
final class Version20260804120000 extends AbstractMigration
{
    private const HYBRID_PREDICATE = 'NULLIF(current_setting(\'app.club_id\', true), \'\') IS NULL OR club_id = NULLIF(current_setting(\'app.club_id\', true), \'\')::uuid';

    public function getDescription(): string
    {
        return 'SEC-12: scope the hybrid SELECT policies (club_user, coach_wish_token) to the tenant whenever the GUC is set.';
    }

    public function up(Schema $schema): void
    {
        $appRole = $this->connection->fetchOne('SELECT rolname FROM pg_roles WHERE rolname IN (\'app_user\', \'amateo_app\') ORDER BY (rolname = \'amateo_app\') DESC LIMIT 1');
        if (!\is_string($appRole)) {
            return;
        }

        $this->addSql('DROP POLICY IF EXISTS club_user_read ON public.club_user');
        $this->addSql(\sprintf('CREATE POLICY club_user_read ON public.club_user FOR SELECT TO ' . $appRole . ' USING (%s)', self::HYBRID_PREDICATE));

        $this->addSql('DROP POLICY IF EXISTS coach_wish_token_read ON public.coach_wish_token');
        $this->addSql(\sprintf('CREATE POLICY coach_wish_token_read ON public.coach_wish_token FOR SELECT TO ' . $appRole . ' USING (%s)', self::HYBRID_PREDICATE));
    }

    public function down(Schema $schema): void
    {
        $appRole = $this->connection->fetchOne('SELECT rolname FROM pg_roles WHERE rolname IN (\'app_user\', \'amateo_app\') ORDER BY (rolname = \'amateo_app\') DESC LIMIT 1');
        if (!\is_string($appRole)) {
            return;
        }

        $this->addSql('DROP POLICY IF EXISTS club_user_read ON public.club_user');
        $this->addSql('CREATE POLICY club_user_read ON public.club_user FOR SELECT TO ' . $appRole . ' USING (true)');

        $this->addSql('DROP POLICY IF EXISTS coach_wish_token_read ON public.coach_wish_token');
        $this->addSql('CREATE POLICY coach_wish_token_read ON public.coach_wish_token FOR SELECT TO ' . $appRole . ' USING (true)');
    }
}
