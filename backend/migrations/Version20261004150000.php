<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * SEC-28 — `app_user.is_demo` : le drapeau d'IDENTITÉ d'un compte de démonstration.
 *
 * Un compte démo (animateur `demo@`, gestionnaire BCCL `demo-bccl@`) ne peut ni
 * changer d'e-mail/mot de passe/prénom-nom ni se supprimer, et il est hors de la
 * règle des comptes orphelins. Jusqu'ici ces chemins reconnaissaient un compte démo
 * par son ADRESSE (params `app.demo_animator_email` / `app.demo_bccl_email`) ; on
 * pose maintenant un drapeau porté par l'entité, posé à la création (commande support
 * `app:demo:create`, raccourci `DevDemoRegisterController`, seed `app:demo:seed`).
 *
 * Backfill des comptes EXISTANTS par leurs adresses littérales — celles des deux
 * params au moment de cette migration (`demo@amateo.fr`, `demo-bccl@amateo.fr`,
 * cf. App\Command\DemoCreateCommand::DEFAULT_ANIMATOR_EMAIL et
 * `app.demo_bccl_email` dans services.yaml). Les migrations embarquant des littéraux
 * figés dans le temps, aucune commande de déploiement n'est requise : le up() suffit.
 *
 * Écrite à la main (`make migration-diff` inopérant tant que doctrine/dbal < 4.5).
 * RLS sans objet (`app_user` n'est pas une table tenant ; migrations sous
 * `amateo_owner`, BYPASSRLS).
 */
final class Version20261004150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'SEC-28: app_user.is_demo flag (default false) + backfill the two demo accounts by address.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user ADD is_demo BOOLEAN NOT NULL DEFAULT FALSE');
        $this->addSql('UPDATE app_user SET is_demo = TRUE WHERE LOWER(email) IN (\'demo@amateo.fr\', \'demo-bccl@amateo.fr\')');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user DROP is_demo');
    }
}
