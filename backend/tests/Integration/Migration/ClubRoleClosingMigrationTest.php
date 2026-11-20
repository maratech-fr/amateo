<?php

declare(strict_types=1);

namespace App\Tests\Integration\Migration;

use App\Tests\Security\MemberRoleTest;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\Exception\AbortMigration;
use Doctrine\Persistence\ManagerRegistry;
use DoctrineMigrations\Version20261120120000;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * NR de migration (charte docs/testing/tests-par-parcours.md §2, obligatoire jusqu'à l'application
 * en prod ; axe auth & memberships) : la fermeture de la colonne `role` convertit chaque ancienne
 * ligne vers l'un des deux rôles et n'en perd aucune. Un ancien `owner` devient Gestionnaire
 * (`admin`), un ancien `editor` ou `viewer` devient Membre (`member`), dans `club_user` comme dans
 * `club_invitation` (une invitation en attente garde sa chance d'être acceptée). Une valeur que la
 * migration ne connaît pas l'arrête AVANT toute conversion : personne ne perd ni ne gagne un droit
 * en silence.
 *
 * - `up()` : convertit, puis pose les deux contraintes CHECK (admin, member).
 * - `down()` : retire les contraintes et ne restaure pas les anciennes valeurs (sans retour).
 *
 * On joue les requêtes de `getSql()` sur la connexion `admin` (celle des migrations) dans une
 * transaction ANNULÉE en `finally` (patron {@see OfferTiersMigrationTest}). Chaque cas commence par
 * `down()` : il rend l'état d'avant la migration quel que soit l'état de la base de test.
 *
 * Jumeau vivant : {@see MemberRoleTest} — la base refuse tout autre rôle
 * (testTheDatabaseAcceptsOnlyTheTwoRoles), un `admin` gère, un `member` lit sans modifier. Ce test
 * ne garde que le run qui modifie les données, et que `up()` pose bien la contrainte.
 */
#[Group('integration')]
final class ClubRoleClosingMigrationTest extends KernelTestCase
{
    private Connection $admin;

    public function testUpConvertsEveryLegacyRoleIncludingPendingInvitations(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $this->play('down');
            [$clubId, $userIds] = $this->club(5);
            $owner = $this->membership($clubId, $userIds[0], 'owner');
            $editor = $this->membership($clubId, $userIds[1], 'editor');
            $viewer = $this->membership($clubId, $userIds[2], 'viewer');
            $admin = $this->membership($clubId, $userIds[3], 'admin');
            $member = $this->membership($clubId, $userIds[4], 'member');
            $ownerInvite = $this->invitation($clubId, $userIds[0], 'owner');
            $editorInvite = $this->invitation($clubId, $userIds[0], 'editor');
            $viewerInvite = $this->invitation($clubId, $userIds[0], 'viewer');

            $this->play('up');

            self::assertSame('admin', $this->roleOf('club_user', $owner), 'un ancien owner devient Gestionnaire');
            self::assertSame('member', $this->roleOf('club_user', $editor), 'un ancien editor devient Membre');
            self::assertSame('member', $this->roleOf('club_user', $viewer), 'un ancien viewer devient Membre');
            self::assertSame('admin', $this->roleOf('club_user', $admin), 'témoin : un Gestionnaire reste Gestionnaire');
            self::assertSame('member', $this->roleOf('club_user', $member), 'témoin : un Membre reste Membre');
            self::assertSame('admin', $this->roleOf('club_invitation', $ownerInvite), 'une invitation en attente « owner » devient Gestionnaire');
            self::assertSame('member', $this->roleOf('club_invitation', $editorInvite), 'une invitation en attente « editor » devient Membre');
            self::assertSame('member', $this->roleOf('club_invitation', $viewerInvite), 'une invitation en attente « viewer » devient Membre');
        });
    }

    public function testUpClosesBothColumns(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $this->play('down');
            [$clubId, $userIds] = $this->club(1);

            $this->play('up');

            self::assertSame('23514', $this->sqlStateOf(fn (): string => $this->membership($clubId, $userIds[0], 'owner')), 'après up(), club_user refuse « owner » (CHECK)');
            self::assertSame('23514', $this->sqlStateOf(fn (): string => $this->invitation($clubId, $userIds[0], 'editor')), 'après up(), club_invitation refuse « editor » (CHECK)');
            self::assertNull($this->sqlStateOf(fn (): string => $this->membership($clubId, $userIds[0], 'member')), 'témoin : « member » passe');
        });
    }

    public function testUpRefusesAnUnknownMembershipRoleBeforeConvertingAnything(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $this->play('down');
            [$clubId, $userIds] = $this->club(2);
            $owner = $this->membership($clubId, $userIds[0], 'owner');
            $this->membership($clubId, $userIds[1], 'coach');

            $this->assertUpAborts('coach');
            self::assertSame('owner', $this->roleOf('club_user', $owner), 'refus AVANT toute conversion : l\'ancien owner est intact');
        });
    }

    public function testUpRefusesAnUnknownInvitationRoleBeforeConvertingAnything(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $this->play('down');
            [$clubId, $userIds] = $this->club(1);
            $owner = $this->membership($clubId, $userIds[0], 'owner');
            $this->invitation($clubId, $userIds[0], 'superviseur');

            $this->assertUpAborts('superviseur');
            self::assertSame('owner', $this->roleOf('club_user', $owner), 'refus AVANT toute conversion : l\'ancien owner est intact');
        });
    }

    public function testDownDropsTheConstraintsWithoutRestoringTheOldValues(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $this->play('down');
            [$clubId, $userIds] = $this->club(2);
            $owner = $this->membership($clubId, $userIds[0], 'owner');
            $this->play('up');

            $this->play('down');

            self::assertSame('admin', $this->roleOf('club_user', $owner), 'down() ne restaure pas « owner » : la conversion est sans retour');
            self::assertNull($this->sqlStateOf(fn (): string => $this->membership($clubId, $userIds[1], 'viewer')), 'down() retire la contrainte de club_user');
            self::assertNull($this->sqlStateOf(fn (): string => $this->invitation($clubId, $userIds[1], 'viewer')), 'down() retire la contrainte de club_invitation');
        });
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(ManagerRegistry::class)->getConnection('admin');
        self::assertInstanceOf(Connection::class, $connection);
        $this->admin = $connection;
    }

    private function assertUpAborts(string $unknownRole): void
    {
        try {
            $this->play('up');
            self::fail(\sprintf('une valeur de rôle inconnue (« %s ») doit arrêter up() (AbortMigration)', $unknownRole));
        } catch (AbortMigration $abort) {
            self::assertStringContainsString($unknownRole, $abort->getMessage(), 'le refus nomme la valeur inconnue, pour que l\'exploitant sache quoi corriger');
        }
    }

    /** Tout le test dans une transaction de la connexion admin, toujours annulée. */
    private function inRolledBackTransaction(callable $scenario): void
    {
        $this->admin->beginTransaction();
        try {
            $scenario();
        } finally {
            $this->admin->rollBack();
        }
    }

    /** Une instance NEUVE par sens (getSql() cumule), TOUTES ses requêtes jouées sur la connexion admin. */
    private function play(string $direction): void
    {
        $migration = new Version20261120120000($this->admin, new NullLogger);
        'up' === $direction ? $migration->up(new Schema) : $migration->down(new Schema);
        $queries = $migration->getSql();
        self::assertNotEmpty($queries, \sprintf('%s() doit planifier ses requêtes par addSql', $direction));
        foreach ($queries as $query) {
            $this->admin->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }

    /**
     * Un club et `$users` comptes, en SQL brut sur la connexion admin (la migration n'y voit que
     * ce que sa propre transaction a écrit).
     *
     * @return array{0: string, 1: list<string>}
     */
    private function club(int $users): array
    {
        $clubId = $this->uuid();
        $this->admin->executeStatement(
            'INSERT INTO club (id, created_at, updated_at, name, slug, timezone, locale, onboarding_completed, generation_count_season) VALUES (?, now(), now(), ?, ?, \'Europe/Paris\', \'fr\', true, 0)',
            [$clubId, 'Rôles ' . $clubId, 'roles-' . $clubId],
        );
        $userIds = [];
        for ($i = 0; $i < $users; ++$i) {
            $userId = $this->uuid();
            $this->admin->executeStatement(
                'INSERT INTO app_user (id, created_at, updated_at, email, password_hash, first_name, last_name) VALUES (?, now(), now(), ?, \'x\', \'R\', \'Ole\')',
                [$userId, 'roles-' . $userId . '@test.fr'],
            );
            $userIds[] = $userId;
        }

        return [$clubId, $userIds];
    }

    private function membership(string $clubId, string $userId, string $role): string
    {
        $id = $this->uuid();
        $this->admin->executeStatement(
            'INSERT INTO club_user (id, created_at, updated_at, club_id, user_id, role, joined_at, is_active) VALUES (?, now(), now(), ?, ?, ?, now(), true)',
            [$id, $clubId, $userId, $role],
        );

        return $id;
    }

    /** Une invitation EN ATTENTE (non expirée, non consommée). */
    private function invitation(string $clubId, string $invitedBy, string $role): string
    {
        $id = $this->uuid();
        $this->admin->executeStatement(
            'INSERT INTO club_invitation (id, club_id, email, role, token_hash, expires_at, created_at, invited_by_user_id) VALUES (?, ?, ?, ?, ?, now() + interval \'7 days\', now(), ?)',
            [$id, $clubId, 'invite-' . $id . '@test.fr', $role, hash('sha256', $id), $invitedBy],
        );

        return $id;
    }

    private function roleOf(string $table, string $id): string
    {
        $role = $this->admin->fetchOne(\sprintf('SELECT role FROM %s WHERE id = ?', $table), [$id]);
        self::assertIsString($role, \sprintf('ligne %s de %s introuvable', $id, $table));

        return $role;
    }

    /** SQLSTATE du refus d'une écriture jouée dans un point de sauvegarde annulé, ou null si elle passe. */
    private function sqlStateOf(callable $write): ?string
    {
        $this->admin->beginTransaction();
        try {
            $write();

            return null;
        } catch (DriverException $refusal) {
            return $refusal->getSQLState();
        } finally {
            $this->admin->rollBack();
        }
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
