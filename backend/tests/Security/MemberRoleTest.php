<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\ClubUser;
use App\Entity\User;
use App\Enum\ClubRole;
use App\Tests\StartsFreshBrowserSession;
use App\Tests\VerifiesRegistration;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * P1-1 (PR B) non-régression — axe auth & memberships (§7.1).
 *
 * Le modèle de rôles se resserre à DEUX rôles assignables (ClubRole :
 * Gestionnaire `admin` / Membre `member`), et l'adhésion gagne un cycle de vie
 * réversible. Ce test épingle :
 *
 *  - un `member` actif LIT tout (teams/schedules/`/api/me`) mais ne peut PILOTER
 *    aucune adhésion (l'enforcement PR A ferme déjà ses écritures métier) ;
 *  - l'approbation POSE un rôle : défaut Gestionnaire (statu quo), `member` sur
 *    demande, tout autre libellé (« coach ») → 422 ;
 *  - désactiver/réactiver DISTINGUE « désactivé » de « en attente » (`deactivatedAt`) :
 *    un désactivé quitte la file d'approbation, son `/api/me` dit `deactivated`,
 *    une pending ne se réactive pas (approve est son seul chemin) ;
 *  - l'invariant « au moins un gestionnaire actif » : rétrograder OU désactiver le
 *    DERNIER gestionnaire → 409, y compris sur soi-même ;
 *  - P4-77 — un compte EFFACÉ (RGPD, `User::anonymizedAt`) ne se réactive ni ne s'approuve :
 *    ressusciter une identité anonymisée est refusé (409), la réactivation normale intacte.
 *  - Lot 2 PR 1 — la colonne `role` est FERMÉE à deux valeurs : la base refuse toute autre
 *    valeur (anciennes `owner`/`editor`/`viewer` comprises) dans `club_user` ET
 *    `club_invitation`, l'API refuse en 422, et l'adhésion se lit en `ClubRole`.
 *
 * La conversion des anciennes lignes (owner → admin, editor/viewer → member, invitations en
 * attente comprises, refus sur valeur inconnue) est le jumeau de migration
 * {@see \App\Tests\Integration\Migration\ClubRoleClosingMigrationTest} : il prouve ce que
 * DEVIENT une ancienne ligne ; ce test prouve ce que fait chacune des deux valeurs (un `admin`
 * gère : testChangeRoleValidatesAndTakesEffect ; un `member` lit sans modifier :
 * testMemberReadsEverythingButCannotPilotMemberships ; une invitation `member` s'accepte :
 * ClubInvitationTest::testAcceptCancelsAnOrphanNotice). La chaîne entière ne tient pas dans
 * un seul test : la migration joue sur la connexion `admin`, l'API sur `default`, deux
 * transactions DAMA qui ne se voient pas.
 */
#[Group('phase1')]
#[Group('integration')]
final class MemberRoleTest extends WebTestCase
{
    use StartsFreshBrowserSession;
    use VerifiesRegistration;

    private const string PASSWORD = 'Password123!';

    private KernelBrowser $client;

    public function testMemberReadsEverythingButCannotPilotMemberships(): void
    {
        [, , $clubA] = $this->register('MRA');
        [$memberToken] = $this->makeMembership($clubA, 'member', isActive: true);

        // Lecture ouverte : l'atelier reste consultable pour un membre.
        foreach (['/api/teams', '/api/schedules'] as $url) {
            $this->get($url, $memberToken);
            self::assertResponseIsSuccessful(\sprintf('un membre doit lire %s', $url));
        }
        $this->get('/api/me', $memberToken);
        self::assertResponseIsSuccessful();
        self::assertSame('active', $this->body()['membershipStatus'] ?? null);

        // Pilotage des adhésions : fermé au non-management (403).
        $this->get('/api/memberships', $memberToken);
        self::assertResponseStatusCodeSame(403, 'lister les membres est management-only');
        $this->post('/api/memberships/' . $this->nilUuid() . '/role', $memberToken, '{"role":"member"}');
        self::assertResponseStatusCodeSame(403, 'changer un rôle est management-only');
        $this->post('/api/memberships/' . $this->nilUuid() . '/deactivate', $memberToken);
        self::assertResponseStatusCodeSame(403, 'désactiver est management-only');
        $this->post('/api/memberships/' . $this->nilUuid() . '/reactivate', $memberToken);
        self::assertResponseStatusCodeSame(403, 'réactiver est management-only');
    }

    public function testApproveRequiresAnExplicitAssignableRole(): void
    {
        [$adminToken, , $clubA] = $this->register('MRB');

        // PR C — le rôle est désormais REQUIS : sans corps → 422, rien n'est activé
        // (fini le défaut Gestionnaire silencieux ; le front envoie toujours le rôle).
        [, , $pendingA] = $this->makeMembership($clubA, 'member', isActive: false);
        $this->post('/api/memberships/' . $pendingA . '/approve', $adminToken);
        self::assertResponseStatusCodeSame(422, 'approve sans corps → 422 (rôle requis)');
        $this->get('/api/memberships/pending', $adminToken);
        self::assertContains($pendingA, $this->memberIds(), 'un approve refusé laisse la cible en attente');

        // Corps {"role":"admin"} : Gestionnaire explicite.
        [, , $pendingMgr] = $this->makeMembership($clubA, 'member', isActive: false);
        $this->post('/api/memberships/' . $pendingMgr . '/approve', $adminToken, '{"role":"admin"}');
        self::assertResponseIsSuccessful();
        self::assertSame('admin', $this->body()['role'] ?? null, 'approve {role:admin} → Gestionnaire');

        // Corps {"role":"member"} : Membre explicite.
        [, , $pendingB] = $this->makeMembership($clubA, 'member', isActive: false);
        $this->post('/api/memberships/' . $pendingB . '/approve', $adminToken, '{"role":"member"}');
        self::assertResponseIsSuccessful();
        self::assertSame('member', $this->body()['role'] ?? null, 'approve {role:member} → Membre');

        // Corps {"role":"coach"} : hors enum assignable → 422, jamais persisté.
        [, , $pendingC] = $this->makeMembership($clubA, 'member', isActive: false);
        $this->post('/api/memberships/' . $pendingC . '/approve', $adminToken, '{"role":"coach"}');
        self::assertResponseStatusCodeSame(422, 'approve {role:coach} → 422');
    }

    public function testChangeRoleValidatesAndTakesEffect(): void
    {
        [$adminToken, , $clubA] = $this->register('MRC');
        [$victimToken, , $victimId] = $this->makeMembership($clubA, 'admin', isActive: true);

        // Rôle requis / hors enum → 422 (jamais persisté). Les trois anciennes valeurs
        // (owner, editor, viewer) sont hors liste comme n'importe quel libellé inventé.
        $this->post('/api/memberships/' . $victimId . '/role', $adminToken, '');
        self::assertResponseStatusCodeSame(422, 'changer un rôle sans corps → 422 (rôle requis)');
        foreach (['coach', 'owner', 'editor', 'viewer'] as $outOfList) {
            $this->post('/api/memberships/' . $victimId . '/role', $adminToken, json_encode(['role' => $outOfList], \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(422, \sprintf('rôle « %s » hors liste → 422', $outOfList));
        }

        // Rétrogradation effective : la victime (2e gestionnaire) devient membre et
        // perd l'accès au pilotage — on vérifie l'EFFET, pas seulement le 200.
        $this->post('/api/memberships/' . $victimId . '/role', $adminToken, '{"role":"member"}');
        self::assertResponseIsSuccessful();
        self::assertSame('member', $this->body()['role'] ?? null);
        $this->get('/api/memberships', $victimToken);
        self::assertResponseStatusCodeSame(403, 'un rétrogradé ne pilote plus rien');
    }

    public function testLastActiveManagerCannotBeDemotedOrDeactivatedEvenOnSelf(): void
    {
        [$adminToken, , , $selfMembership] = $this->register('MRD');

        // Seul gestionnaire : ni se rétrograder…
        $this->post('/api/memberships/' . $selfMembership . '/role', $adminToken, '{"role":"member"}');
        self::assertResponseStatusCodeSame(409, 'le dernier gestionnaire ne peut pas se rétrograder');
        // …ni se désactiver.
        $this->post('/api/memberships/' . $selfMembership . '/deactivate', $adminToken);
        self::assertResponseStatusCodeSame(409, 'le dernier gestionnaire ne peut pas se désactiver');

        // Un SECOND gestionnaire lève le verrou pour l'un, puis se referme sur l'autre.
        [, , $secondManager] = $this->makeMembership($this->clubOf($adminToken), 'admin', isActive: true);
        $this->post('/api/memberships/' . $secondManager . '/role', $adminToken, '{"role":"member"}');
        self::assertResponseIsSuccessful('avec deux gestionnaires, en rétrograder un passe');
        // De nouveau seul → le verrou se referme.
        $this->post('/api/memberships/' . $selfMembership . '/deactivate', $adminToken);
        self::assertResponseStatusCodeSame(409, 'redevenu seul gestionnaire, la désactivation est refusée');
    }

    public function testDeactivateReactivateRoundTripAndPendingBoundaries(): void
    {
        [$adminToken, , $clubA] = $this->register('MRE');
        [$memberToken, , $memberId] = $this->makeMembership($clubA, 'member', isActive: true);
        [, , $genuinePending] = $this->makeMembership($clubA, 'member', isActive: false);

        // Désactivation : sort des membres actifs, ne rentre pas dans la file.
        $this->post('/api/memberships/' . $memberId . '/deactivate', $adminToken);
        self::assertResponseIsSuccessful();

        $this->get('/api/memberships', $adminToken);
        self::assertNotContains($memberId, $this->memberIds(), 'un désactivé ne figure plus dans les membres actifs');
        self::assertArrayNotHasKey('deactivated', $this->body(), 'la réponse par défaut reste actifs seuls');

        // PR C — l'écran de gestion demande les désactivés via `?includeDeactivated=1` :
        // ils arrivent dans une clé `deactivated` dédiée, jamais mêlés aux actifs.
        $this->get('/api/memberships?includeDeactivated=1', $adminToken);
        $withDeactivated = $this->body();
        self::assertArrayHasKey('deactivated', $withDeactivated);
        self::assertNotContains($memberId, array_column($withDeactivated['members'], 'id'), 'un désactivé n\'est pas dans les actifs');
        self::assertContains($memberId, array_column($withDeactivated['deactivated'], 'id'), 'un désactivé figure dans la liste dédiée');

        $this->get('/api/memberships/pending', $adminToken);
        $pendingIds = $this->memberIds();
        self::assertContains($genuinePending, $pendingIds, 'une pending reste dans la file');
        self::assertNotContains($memberId, $pendingIds, 'un désactivé ne re-rentre pas dans la file d\'approbation');

        // Son /api/me le dit « deactivated », pas « pending ».
        $this->get('/api/me', $memberToken);
        self::assertSame('deactivated', $this->body()['membershipStatus'] ?? null);

        // Réactivation : restaure avec son rôle, ré-apparaît en actif.
        $this->post('/api/memberships/' . $memberId . '/reactivate', $adminToken);
        self::assertResponseIsSuccessful();
        self::assertSame('member', $this->body()['role'] ?? null, 'la réactivation restaure le rôle');
        $this->get('/api/me', $memberToken);
        self::assertSame('active', $this->body()['membershipStatus'] ?? null);

        // Une pending ne se « réactive » pas — l'approbation est son seul chemin.
        $this->post('/api/memberships/' . $genuinePending . '/reactivate', $adminToken);
        self::assertResponseStatusCodeSame(409, 'une pending ne se réactive pas');

        // Revue sécu PR B — approve/reject n'opèrent QUE sur le vrai pending :
        // (a) approve sur un ACTIF ne le promeut pas silencieusement en admin ;
        $this->post('/api/memberships/' . $memberId . '/approve', $adminToken);
        self::assertResponseStatusCodeSame(409, 'approve sur un actif est refusé');
        $this->get('/api/memberships', $adminToken);
        self::assertSame('member', $this->roleOf($memberId), 'le rôle du membre actif n\'a pas bougé');

        // (b) approve sur un DÉSACTIVÉ ne fabrique pas l'état hybride actif+deactivatedAt ;
        $this->post('/api/memberships/' . $memberId . '/deactivate', $adminToken);
        self::assertResponseIsSuccessful();
        $this->post('/api/memberships/' . $memberId . '/approve', $adminToken);
        self::assertResponseStatusCodeSame(409, 'approve sur un désactivé est refusé — reactivate est le geste');

        // (c) reject sur un désactivé ne supprime pas la ligne (l'archive reste réversible).
        $this->post('/api/memberships/' . $memberId . '/reject', $adminToken);
        self::assertResponseStatusCodeSame(409, 'reject sur un désactivé est refusé');
        $this->post('/api/memberships/' . $memberId . '/reactivate', $adminToken);
        self::assertResponseIsSuccessful('la ligne a survécu et se réactive');
    }

    /**
     * Revue sécu PR B (finding F1) : le DERNIER gestionnaire ne peut pas s'auto-
     * SUPPRIMER via reject — l'invariant « ≥ 1 gestionnaire actif » tenait pour
     * la rétrogradation et la désactivation, reject le contournait en une requête.
     */
    public function testLastManagerCannotSelfDeleteViaReject(): void
    {
        [$adminToken, , $clubA] = $this->register('MRJ');

        $this->get('/api/memberships', $adminToken);
        $selfId = $this->body()['members'][0]['id'] ?? null;
        self::assertIsString($selfId);

        $this->post('/api/memberships/' . $selfId . '/reject', $adminToken);
        self::assertResponseStatusCodeSame(409, 'un actif ne se rejette pas — a fortiori le dernier gestionnaire');

        $this->get('/api/memberships', $adminToken);
        self::assertContains($selfId, $this->memberIds(), 'la ligne du gestionnaire a survécu');
    }

    public function testMemberKeepsSelfServiceProfileAndErasure(): void
    {
        [, , $clubA] = $this->register('MRF');
        [$memberToken] = $this->makeMembership($clubA, 'member', isActive: true);

        // Éditer SON profil n'est pas un geste de management (opt-out self-only).
        $this->client->request('PATCH', '/api/me', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $memberToken,
            'CONTENT_TYPE' => 'application/json',
        ], '{"firstName":"Renommé"}');
        self::assertResponseIsSuccessful('un membre édite son propre profil (jamais 403)');

        // Effacer SON compte (RGPD) reste ouvert : le gate management ne s'arme pas —
        // avec le bon mot de passe, l'anonymisation aboutit (200), jamais 403.
        $this->client->request('DELETE', '/api/me', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $memberToken,
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['password' => self::PASSWORD], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful('un membre efface son propre compte (jamais 403)');
    }

    /**
     * P4-75 (axe auth & memberships) : un membre ACTIF qui efface son compte
     * (RGPD) voit son adhésion désactivée. Sans `deactivated_at`, cette ligne
     * (is_active=false, deactivated_at=null) se relit comme une demande jamais
     * approuvée et réapparaît dans la file d'approbation du club — une adhésion
     * fantôme. `AccountErasureService` doit poser `deactivated_at`.
     */
    public function testErasedMemberDoesNotReappearAsPhantomPending(): void
    {
        [$adminToken, , $clubA] = $this->register('MRK');
        [$memberToken, , $memberId] = $this->makeMembership($clubA, 'member', isActive: true);

        // Baseline : un membre actif n'est pas dans la file d'attente.
        $this->get('/api/memberships/pending', $adminToken);
        self::assertNotContains($memberId, $this->memberIds());

        // Le membre efface son compte ; le gestionnaire reste actif → pas de purge.
        $this->client->request('DELETE', '/api/me', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $memberToken,
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['password' => self::PASSWORD], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();

        // L'adhésion effacée ne revient pas comme demande fantôme.
        $this->get('/api/memberships/pending', $adminToken);
        self::assertNotContains($memberId, $this->memberIds(), 'une adhésion effacée ne revient pas dans la file d\'approbation');
    }

    /**
     * P4-77 (axe auth & memberships) : un compte EFFACÉ (RGPD, `User::anonymizedAt`) ne peut
     * être ni réactivé ni approuvé — le geste ressusciterait une identité anonymisée. Le gestionnaire
     * qui veut ré-inclure la personne doit la faire ré-inscrire, pas ranimer le compte effacé.
     *
     * Falsification : chaque garde désactivée fait retomber son cas en 200 (le test rougit).
     */
    public function testAnonymizedAccountCannotBeReactivatedOrApproved(): void
    {
        [$adminToken, , $clubA] = $this->register('MRL');

        // Adhésion DÉSACTIVÉE dont le compte est anonymisé : réactiver → 409 + message dédié.
        [, , $erasedDeactivated] = $this->makeMembership($clubA, 'member', isActive: false, deactivated: true, anonymized: true);
        $this->post('/api/memberships/' . $erasedDeactivated . '/reactivate', $adminToken);
        self::assertResponseStatusCodeSame(409, 'un compte anonymisé ne se réactive pas');
        self::assertStringContainsString('supprimé', (string) ($this->body()['error'] ?? ''), 'le message dit que le compte a été supprimé (RGPD)');

        // Ceinture : une adhésion EN ATTENTE dont le compte est anonymisé ne s'approuve pas.
        [, , $erasedPending] = $this->makeMembership($clubA, 'member', isActive: false, anonymized: true);
        $this->post('/api/memberships/' . $erasedPending . '/approve', $adminToken, '{"role":"member"}');
        self::assertResponseStatusCodeSame(409, 'une adhésion en attente dont le compte est anonymisé ne s\'approuve pas');

        // Non-régression : un compte NON anonymisé se réactive normalement (la garde ne mord
        // que sur l'anonymisation).
        [, , $liveDeactivated] = $this->makeMembership($clubA, 'member', isActive: false, deactivated: true);
        $this->post('/api/memberships/' . $liveDeactivated . '/reactivate', $adminToken);
        self::assertResponseIsSuccessful('un compte vivant se réactive comme avant');
    }

    /** Le créateur d'un club naît Gestionnaire, et l'adhésion se lit en `ClubRole`, jamais en chaîne libre. */
    public function testTheClubCreatorIsBornManagerReadAsAClubRole(): void
    {
        [, , , $selfMembership] = $this->register('MRG');

        $em = self::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $membership = $em->find(ClubUser::class, $selfMembership);
        self::assertInstanceOf(ClubUser::class, $membership);
        self::assertSame(ClubRole::MANAGER, $membership->getRole(), 'le créateur du club naît Gestionnaire, lu en ClubRole');
        self::assertTrue($membership->getRole()->isManager(), 'ClubRole::isManager() : le Gestionnaire gère');
        self::assertFalse(ClubRole::MEMBER->isManager(), 'ClubRole::isManager() : le Membre ne gère pas');
    }

    /**
     * La base n'accepte que les deux rôles : une ancienne valeur (owner, editor, viewer) ou un
     * libellé inventé ne peut plus entrer dans `club_user` ni dans `club_invitation`, quel que
     * soit l'écrivain (SQL brut compris), donc aucun code ne relira jamais un troisième rôle.
     *
     * Témoin : la même ligne avec `admin` puis `member` passe — le refus vient bien de la
     * contrainte CHECK (SQLSTATE 23514), pas d'une clé étrangère ni d'un NOT NULL.
     * Falsification : contraintes retirées (`down()` de la migration) → les inserts passent, rouge.
     */
    public function testTheDatabaseAcceptsOnlyTheTwoRoles(): void
    {
        $admin = self::getContainer()->get(ManagerRegistry::class)->getConnection('admin');
        self::assertInstanceOf(Connection::class, $admin);

        $clubId = $this->uuid();
        $admin->executeStatement(
            'INSERT INTO club (id, created_at, updated_at, name, slug, timezone, locale, onboarding_completed, generation_count_season) VALUES (?, now(), now(), ?, ?, \'Europe/Paris\', \'fr\', true, 0)',
            [$clubId, 'Rôles ' . $clubId, 'roles-' . $clubId],
        );
        $userId = $this->uuid();
        $admin->executeStatement(
            'INSERT INTO app_user (id, created_at, updated_at, email, password_hash, first_name, last_name) VALUES (?, now(), now(), ?, \'x\', \'R\', \'Ole\')',
            [$userId, 'roles-' . $userId . '@test.fr'],
        );

        $insertMembership = static fn (string $role): int => $admin->executeStatement(
            'INSERT INTO club_user (id, created_at, updated_at, club_id, user_id, role, joined_at, is_active) VALUES (gen_random_uuid(), now(), now(), ?, ?, ?, now(), true)',
            [$clubId, $userId, $role],
        );
        $insertInvitation = static fn (string $role): int => $admin->executeStatement(
            'INSERT INTO club_invitation (id, club_id, email, role, token_hash, expires_at, created_at, invited_by_user_id) VALUES (gen_random_uuid(), ?, ?, ?, md5(random()::text), now() + interval \'7 days\', now(), ?)',
            [$clubId, 'invite-' . bin2hex(random_bytes(4)) . '@test.fr', $role, $userId],
        );

        foreach (['club_user' => $insertMembership, 'club_invitation' => $insertInvitation] as $table => $insert) {
            foreach (['owner', 'editor', 'viewer', 'coach'] as $refused) {
                self::assertSame('23514', $this->sqlStateOf($admin, static fn () => $insert($refused)), \sprintf('%s.role = « %s » doit être refusé par la contrainte CHECK', $table, $refused));
            }
            foreach (ClubRole::cases() as $accepted) {
                self::assertNull($this->sqlStateOf($admin, static fn () => $insert($accepted->value)), \sprintf('témoin : %s.role = « %s » passe', $table, $accepted->value));
            }
        }
    }

    public function testPublicTokenRoutesStayReachable(): void
    {
        // Les pages publiques à token (le token EST l'identité) répondent 404
        // byte-identique pour un token inconnu — la PR ne les a pas cassées.
        $this->client->request('GET', '/api/coach-wishes/public/unknown-token');
        self::assertResponseStatusCodeSame(404, 'la page publique doléances reste joignable');
        $this->client->request('GET', '/api/club-approvals/unknown-token');
        self::assertResponseStatusCodeSame(404, 'la page publique d\'approbation reste joignable');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    /**
     * Inscription réelle → Gestionnaire ACTIF d'un club neuf.
     *
     * @return array{0: string, 1: string, 2: string, 3: string} [token, userId, clubId, selfMembershipId]
     */
    private function register(string $ara): array
    {
        $ip = \sprintf('10.%d.%d.%d', random_int(1, 254), random_int(0, 254), random_int(1, 254));
        $suffix = strtolower($ara) . substr(md5(uniqid('', true)), 0, 6);
        $this->client->request('POST', '/api/register', [], [], [
            'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $ip,
        ], json_encode([
            'email' => $suffix . '@test.fr', 'password' => self::PASSWORD,
            'firstName' => 'M', 'lastName' => 'Role', 'ara' => strtoupper($suffix),
            'club_name' => 'Club ' . $ara, 'consent' => true,
        ], \JSON_THROW_ON_ERROR));

        $token = $this->verifyRegistration($this->client, $suffix . '@test.fr');
        self::assertNotSame('', $token, 'verification must return a token');

        $this->get('/api/me', $token);
        $me = $this->body();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $membership = $em->getRepository(ClubUser::class)->findOneBy(['userId' => $me['id'], 'clubId' => $me['club']['id']]);
        \assert($membership instanceof ClubUser);

        // SEC-16 : le cookie JWT posé par la vérification serait rejoué sur les
        // requêtes suivantes et pourrait l'emporter sur un Bearer d'AUTRE identité —
        // on repart d'une session neuve, toutes les requêtes portent leur Bearer.
        $this->startFreshBrowserSession($this->client);

        return [$token, $me['id'], $me['club']['id'], $membership->getId()];
    }

    /**
     * @return array{0: string, 1: string, 2: string} [token, userId, membershipId]
     */
    private function makeMembership(string $clubId, string $role, bool $isActive, bool $deactivated = false, bool $anonymized = false): array
    {
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $hasher = $container->get(UserPasswordHasherInterface::class);
        \assert($hasher instanceof UserPasswordHasherInterface);

        $uid = substr(md5(uniqid('', true)), 0, 8);
        $user = new User;
        $user->setEmail($role . $uid . '@test.fr');
        $user->setFirstName('N');
        $user->setLastName('Member');
        $user->setPasswordHash($hasher->hashPassword($user, self::PASSWORD));
        if ($anonymized) {
            $user->setAnonymizedAt(new DateTimeImmutable);
        }
        $em->persist($user);

        $membership = new ClubUser;
        $membership->setClubId($clubId);
        $membership->setUserId($user->getId());
        $membership->setRole($role);
        $membership->setIsActive($isActive);
        if ($deactivated) {
            $membership->setDeactivatedAt(new DateTimeImmutable);
        }
        $em->persist($membership);
        $em->flush();

        $token = $container->get(JWTTokenManagerInterface::class)->create($user);

        return [$token, $user->getId(), $membership->getId()];
    }

    private function clubOf(string $token): string
    {
        $this->get('/api/me', $token);

        return $this->body()['club']['id'];
    }

    private function get(string $url, string $token): void
    {
        $this->client->request('GET', $url, [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
    }

    private function post(string $url, string $token, string $body = ''): void
    {
        $this->client->request('POST', $url, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'CONTENT_TYPE' => 'application/json',
        ], $body);
    }

    /** @return array<string, mixed> */
    private function body(): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * Les ids des membres de la dernière réponse `{members: [...]}`.
     *
     * @return list<string>
     */
    private function memberIds(): array
    {
        $members = $this->body()['members'] ?? [];
        \assert(\is_array($members));

        return array_map(static fn (array $m): string => (string) $m['id'], $members);
    }

    /** Le rôle d'un membre dans la dernière réponse `{members: [...]}`, ou null. */
    private function roleOf(string $membershipId): ?string
    {
        $members = $this->body()['members'] ?? [];
        \assert(\is_array($members));
        foreach ($members as $m) {
            \assert(\is_array($m));
            if ((string) $m['id'] === $membershipId) {
                return isset($m['role']) ? (string) $m['role'] : null;
            }
        }

        return null;
    }

    private function nilUuid(): string
    {
        return '11111111-1111-4111-8111-111111111111';
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /**
     * Joue une écriture dans un point de sauvegarde annulé et rend le SQLSTATE de son refus, ou
     * null si elle passe. Le point de sauvegarde garde la transaction utilisable après un refus.
     */
    private function sqlStateOf(Connection $connection, callable $write): ?string
    {
        $connection->beginTransaction();
        try {
            $write();

            return null;
        } catch (DriverException $refusal) {
            return $refusal->getSQLState();
        } finally {
            $connection->rollBack();
        }
    }
}
