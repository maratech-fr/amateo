<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Démos (décision fondateur 2026-09-30) — la FENÊTRE D'ACTIVATION d'un compte démo.
 *
 * Une seule colonne, ADDITIVE et nullable, sur `app_user` : `demo_active_until`.
 * NULL = compte démo inactif (défaut) ; un instant FUTUR = fenêtre ouverte. Seuls les
 * deux comptes démo (animateur `demo@`, BCCL `demo-bccl@`) la lisent — `UserChecker`
 * refuse leur connexion hors fenêtre, du même refus indiscernable qu'un mauvais mot de
 * passe. Tout autre compte est insensible à cette colonne.
 *
 * `app_user` est une table GLOBALE (sans club_id, hors RLS). La colonne n'est PAS
 * ajoutée à la liste blanche du rôle `amateo_read` (Version20260930090000,
 * APP_USER_COLUMNS) : ce n'est pas un secret, mais l'opérateur de lecture seule n'en a
 * aucun besoin, et le défaut est fermé (une colonne non énumérée n'est pas lisible).
 * ReadOnlyRoleTest ne l'exige pas (son nom ne sent pas le secret, et il n'impose pas
 * une classification colonne par colonne d'app_user).
 *
 * Écrite à la main (`make migration-diff` inopérant tant que doctrine/dbal < 4.5).
 * Tourne sur la connexion admin (amateo_owner), comme toutes les migrations DDL.
 */
final class Version20260930120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Demo activation window: additive nullable app_user.demo_active_until (NULL = inactive). No amateo_read grant (default-closed).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user ADD COLUMN IF NOT EXISTS demo_active_until TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user DROP COLUMN IF EXISTS demo_active_until');
    }
}
