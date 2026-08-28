<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * P4-143 — l'invariant « pas de DELETE pour le rôle applicatif » de la table
 * partagée `shared_competition_deadline` était FAUX en base.
 *
 * `shared_competition_deadline` (RMM-6, `Version20260825140000`) est une table de
 * RÉFÉRENCE GLOBALE hors-tenant : le GRANT explicite y est la SEULE couche DB (pas
 * de RLS). Ce GRANT ne conférait que SELECT+INSERT+UPDATE — l'intention documentée
 * étant « pas de DELETE » (revue sécurité 2026-08-25, F-1). Mais l'intention n'a
 * jamais atteint la base : l'`ALTER DEFAULT PRIVILEGES … GRANT … DELETE` de la
 * `Version20260703120000` confère DÉJÀ DELETE au rôle applicatif sur toute table
 * neuve, et un GRANT restreint ne RETIRE pas ce DELETE conféré par défaut — il faut
 * un REVOKE explicite. Le rôle applicatif POUVAIT donc supprimer une ligne partagée.
 *
 * Correctif : REVOKE DELETE explicite, exactement comme la `Version20260827150000`
 * l'a fait pour `opponent_directory` (même corollaire, même patron). Le rôle est
 * résolu dynamiquement (`app_user` OU `amateo_app`) pour rester rejouable sur tout
 * cluster, comme les migrations de GRANT le font désormais.
 *
 * Écrit à la main : `make migration-diff` est inopérant tant que doctrine/dbal
 * reste < 4.5 (backend.md).
 */
final class Version20260828170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'P4-143: REVOKE DELETE on shared_competition_deadline from the runtime application role (the restricted GRANT never removed the DELETE conferred by ALTER DEFAULT PRIVILEGES).';
    }

    public function up(Schema $schema): void
    {
        // ⚠ Le REVOKE est INDISPENSABLE : l'`ALTER DEFAULT PRIVILEGES` de la 20260703120000
        // confère DÉJÀ SELECT+INSERT+UPDATE+DELETE au rôle applicatif sur toute table neuve —
        // le GRANT restreint de la 20260825140000 n'a PAS retiré ce DELETE ; il faut le révoquer.
        $appRole = $this->connection->fetchOne('SELECT rolname FROM pg_roles WHERE rolname IN (\'app_user\', \'amateo_app\') ORDER BY (rolname = \'amateo_app\') DESC LIMIT 1');
        if (\is_string($appRole)) {
            $this->addSql('REVOKE DELETE ON shared_competition_deadline FROM ' . $appRole);
        }
    }

    public function down(Schema $schema): void
    {
        // Rétablit le DELETE conféré par défaut (état antérieur au correctif).
        $appRole = $this->connection->fetchOne('SELECT rolname FROM pg_roles WHERE rolname IN (\'app_user\', \'amateo_app\') ORDER BY (rolname = \'amateo_app\') DESC LIMIT 1');
        if (\is_string($appRole)) {
            $this->addSql('GRANT DELETE ON shared_competition_deadline TO ' . $appRole);
        }
    }
}
