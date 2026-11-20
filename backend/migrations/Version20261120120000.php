<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Rôles d'adhésion : deux valeurs seulement, colonne fermée.
 *
 * Conversion sans retour des anciennes valeurs, dans `club_user` ET `club_invitation`
 * (invitations en attente comprises) : `owner` → `admin` (Gestionnaire), `editor` et
 * `viewer` → `member` (Membre). Puis `CHECK (role IN ('admin', 'member'))` sur les deux tables.
 *
 * Toute autre valeur arrête la migration AVANT toute écriture, en la nommant : personne ne
 * perd ni ne gagne un droit en silence ; l'exploitant corrige la ligne et relance.
 *
 * down() retire les deux contraintes et ne restaure PAS les anciennes valeurs : l'ancien code
 * redéployé n'écrit que `admin` et `member`, et lit déjà `admin` comme gestion.
 */
final class Version20261120120000 extends AbstractMigration
{
    private const array TABLES = ['club_user', 'club_invitation'];
    private const array KNOWN_ROLES = ['owner', 'editor', 'viewer', 'admin', 'member'];
    private const array CONVERSIONS = ['owner' => 'admin', 'editor' => 'member', 'viewer' => 'member'];

    public function getDescription(): string
    {
        return 'Rôles d\'adhésion : owner → admin, editor/viewer → member, colonne role fermée à (admin, member) sur club_user et club_invitation';
    }

    public function up(Schema $schema): void
    {
        foreach (self::TABLES as $table) {
            /** @var list<string> $unknown */
            $unknown = $this->connection->fetchFirstColumn(
                \sprintf('SELECT DISTINCT role FROM %s WHERE role NOT IN (?, ?, ?, ?, ?) ORDER BY role', $table),
                self::KNOWN_ROLES,
            );
            $this->abortIf([] !== $unknown, \sprintf(
                'Valeur de rôle inconnue dans %s : %s. Aucune ligne convertie ; corriger ces lignes puis relancer.',
                $table,
                implode(', ', array_map(static fn (string $role): string => '« ' . $role . ' »', $unknown)),
            ));
        }

        foreach (self::TABLES as $table) {
            foreach (self::CONVERSIONS as $from => $to) {
                $count = (int) $this->connection->fetchOne(\sprintf('SELECT COUNT(*) FROM %s WHERE role = ?', $table), [$from]);
                $this->write(\sprintf('%s : %d ligne(s) « %s » → « %s »', $table, $count, $from, $to));
                $this->addSql(\sprintf('UPDATE %s SET role = :to WHERE role = :from', $table), ['to' => $to, 'from' => $from]);
            }
            $this->addSql(\sprintf('ALTER TABLE %s ADD CONSTRAINT chk_%s_role CHECK (role IN (\'admin\', \'member\'))', $table, $table));
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::TABLES as $table) {
            $this->addSql(\sprintf('ALTER TABLE %s DROP CONSTRAINT IF EXISTS chk_%s_role', $table, $table));
        }
    }
}
