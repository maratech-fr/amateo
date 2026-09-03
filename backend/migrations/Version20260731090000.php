<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * SEC — supprime le rôle `migration_user`, compte de service DORMANT à droits larges.
 *
 * Il était créé par `docker/postgres/init/02-users.sh` avec `GRANT ALL PRIVILEGES` sur le
 * schéma `public`, toutes ses tables et toutes ses séquences, plus des `ALTER DEFAULT
 * PRIVILEGES` qui étendaient ces droits à toute table FUTURE. Et il n'était **utilisé par
 * aucune connexion** : les migrations passent par `clubscheduler` (`DATABASE_ADMIN_URL`),
 * ce que `backend/docs/RLS.md` documentait déjà comme un artefact hérité à ne pas employer.
 *
 * Pourquoi le supprimer plutôt que le documenter une fois de plus : un compte de service
 * inutilisé à droits larges est une surface d'attaque sans contrepartie — il ne sert rien,
 * donc il ne coûte rien à retirer, et tout audit de sécurité le relève.
 *
 * Pourquoi il ne pouvait pas être « câblé pour de vrai » : `NOSUPERUSER` sans `BYPASSRLS`,
 * donc sous `FORCE ROW LEVEL SECURITY` il est default-deny sur chaque table tenant — les
 * migrations et les fixtures casseraient. C'est précisément pourquoi il avait été abandonné.
 *
 * `DROP OWNED BY` retire d'un coup TOUS les privilèges accordés au rôle (y compris les
 * `ALTER DEFAULT PRIVILEGES`), ce qu'une liste de `REVOKE` écrite à la main raterait dès
 * qu'une table est ajoutée. Sans lui, `DROP ROLE` échoue avec « role has privileges ».
 *
 * Idempotente : sans le rôle (base neuve créée après le retrait dans `02-users.sh`), tout
 * est sauté. La migration tourne sur la connexion `admin` (`clubscheduler`, superuser).
 */
final class Version20260731090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'SEC: drop the dormant migration_user role (unused service account with schema-wide GRANT ALL).';
    }

    public function up(Schema $schema): void
    {
        if (!$this->roleExists()) {
            return;
        }

        // DROP OWNED BY : révoque tous les privilèges du rôle dans CETTE base, y compris
        // les default privileges. Le rôle ne possède aucun objet (il n'a jamais servi),
        // donc rien n'est supprimé — seuls les droits tombent.
        $this->addSql('DROP OWNED BY migration_user');
        $this->addSql('DROP ROLE migration_user');
    }

    public function down(Schema $schema): void
    {
        // Volontairement NON réversible. Recréer le rôle demanderait de réinventer un mot de
        // passe (celui d'origine venait de MIGRATION_USER_PASSWORD, retiré des compose par le
        // même lot) et rétablirait la surface qu'on vient de supprimer. Le rollback attendu
        // d'une base de dev est `make db-empty` ; en prod, la restauration d'un dump.
        $this->throwIrreversibleMigrationException(
            'Le rôle migration_user est supprimé définitivement (compte dormant à droits larges). '
            . 'Restaurer un dump si un retour arrière est réellement nécessaire.',
        );
    }

    public function isTransactional(): bool
    {
        // DROP ROLE ne peut pas s'exécuter dans un bloc transactionnel partagé avec d'autres
        // bases ; on le sort de la transaction Doctrine par prudence, comme les autres
        // opérations de rôle du projet.
        return false;
    }

    private function roleExists(): bool
    {
        return (bool) $this->connection->fetchOne('SELECT 1 FROM pg_roles WHERE rolname = \'migration_user\'');
    }
}
