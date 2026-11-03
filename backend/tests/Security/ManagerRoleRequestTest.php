<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Club;
use App\Entity\ClubCreationRequest;
use App\Entity\ClubUser;
use App\Entity\User;
use App\Enum\AuditAction;
use App\Mail\ClubBusinessMail;
use App\Service\ClubProvisioner;
use App\Service\ProductIdentity;
use App\Service\TenantConnectionContext;
use App\Tests\AssertsActionMailCta;
use App\Tests\Double\FfbbHttpClientStub;
use App\Tests\StartsFreshBrowserSession;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

/**
 * NR d'axe *auth & memberships* (chantier « Demandes d'adhésion et rôle Membre », PR 3) — la
 * promesse fermée par le fondateur : **un Membre peut demander à devenir gestionnaire, et seul
 * quelqu'un qui en a le droit le fait gestionnaire.**
 *
 *  - Club AVEC gestionnaire : la demande pose un stamp `manager_requested_at`, prévient les
 *    gestionnaires actifs (jamais un Membre) ; un gestionnaire accepte (promotion, mail, audit) ou
 *    refuse (mail, redemande possible le jour même) ; une demande à la fois, annulable ; bornée par
 *    un seau utilisateur, consommé APRÈS les refus 409.
 *  - Club SANS gestionnaire (fermeture en cours) : la demande part à l'ADRESSE OFFICIELLE du club
 *    (seul appel sortant d'un Membre, un seul, borné par un seau club) — un ancien stamp ne bloque
 *    jamais ; le premier approuvé gagne, la fermeture est annulée, les demandes sœurs sont closes
 *    (membre déjà là : « dépassée », lien mort ; extérieur : adhésion pending) ; un refus officiel
 *    bloque 30 jours ; l'expiration prévient le demandeur ; une adresse inconnue est relue chaque
 *    jour et le support est alerté ; une demande vivante retient la purge UNE fois, jusqu'à son
 *    échéance.
 *  - Le stamp meurt à la promotion, la reprise, la désactivation et l'effacement ; un compte
 *    effacé ne devient jamais gestionnaire ; la demande figure dans l'export art. 15.
 *
 * Décor : clubs et membres insérés directement (le rail d'inscription → approbation est prouvé par
 * `ClubApprovalFlowTest` et `MembershipRequestNotificationTest`) ; les GESTES prouvés passent tous
 * par l'API réelle. Club « fermant » = aucun gestionnaire actif + échéance d'effacement posée (l'état
 * exact que laisse l'effacement du dernier gestionnaire — `MembershipRequestNotificationTest`).
 *
 * Plafonds de test (`when@test`) : `manager_role_request` 2/jour/utilisateur ;
 * `manager_role_request_club` 5/jour/club (valeur de prod, aucune surcharge) ;
 * `membership_request_notify` 2/jour/club. Redis : JAMAIS FLUSHALL — chaque test a ses clubs et ses
 * utilisateurs neufs (clés neuves), aucun reset nécessaire.
 */
#[Group('phase1')]
#[Group('integration')]
final class ManagerRoleRequestTest extends WebTestCase
{
    use AssertsActionMailCta;
    use StartsFreshBrowserSession;
    use TenantGucTrait;

    private const string PASSWORD = 'Password123!';
    private const string ROUTE = '/api/memberships/me/manager-request';

    private KernelBrowser $client;

    // ---------------------------------------------------------------------------------------
    // Club AVEC gestionnaire — la demande va aux gestionnaires
    // ---------------------------------------------------------------------------------------

    /** Un Membre demande : chaque gestionnaire ACTIF reçoit UN mail (owner historique compris), personne d'autre ; la demande est tracée (ids seuls). */
    public function testMemberRequestsAndActiveManagersAreMailedOnce(): void
    {
        $club = $this->bareClub('RQML');
        $marc = $this->insertMember($club['clubId'], 'Marc', 'admin');
        $sophie = $this->insertMember($club['clubId'], 'Sophie', 'owner');
        $didier = $this->insertMember($club['clubId'], 'Didier', 'admin', active: false, deactivated: true);
        $kim = $this->insertMember($club['clubId'], 'Kim', 'member');
        $paul = $this->insertMember($club['clubId'], 'Paul', 'member');

        $body = $this->api('POST', self::ROUTE, $this->jwtFor($paul['userId']));
        self::assertResponseStatusCodeSame(201);
        self::assertSame('pending', $body['status'] ?? null);
        $this->assertIsoNow($body['since'] ?? null);
        self::assertNotNull($this->stamp($club['clubId'], $paul['userId']), 'la demande pose le stamp sur l\'adhésion');

        $sent = $this->sentEmails();
        $product = $this->product();
        $clubName = $this->clubName($club['clubId']);
        foreach ([$marc['email'], $sophie['email']] as $manager) {
            $mails = $this->mailsTo($sent, $manager);
            self::assertCount(1, $mails, \sprintf('le gestionnaire actif %s reçoit UN mail', $manager));
            self::assertSame(\sprintf('Paul Temoin demande à devenir gestionnaire de %s', $clubName), $mails[0]->getSubject());
            self::assertSame(
                "Bonjour,\n\nPaul Temoin ({$paul['email']}), membre de l'espace {$product} du club {$clubName}, demande à en devenir gestionnaire.\n\n"
                . "Connectez-vous pour accepter ou refuser dans l'écran Club, section « Membres » :\n{$this->base()}/club\n\n"
                . "Un gestionnaire peut tout modifier : n'acceptez que si vous connaissez cette personne.\n\n{$product}",
                (string) $mails[0]->getTextBody(),
            );
            $this->assertCtaHeadersPointToBodyLink($mails[0], $this->base() . '/club', 'Voir les membres');
            self::assertTrue(ClubBusinessMail::isClubBusiness($mails[0]), 'mail métier du club');
            self::assertCount(1, $mails[0]->getTo(), 'un mail par gestionnaire, jamais une liste d\'adresses');
        }
        foreach ([$kim['email'], $didier['email'], $paul['email']] as $other) {
            self::assertSame([], $this->mailsTo($sent, $other), 'ni un Membre, ni un désactivé, ni le demandeur');
        }

        self::assertSame(1, $this->auditCount($club['clubId'], AuditAction::MEMBERSHIP_ROLE_REQUESTED), 'la demande est auditée');
        $details = $this->auditDetails($club['clubId'], AuditAction::MEMBERSHIP_ROLE_REQUESTED);
        self::assertStringNotContainsString($paul['email'], $details, 'audit : ids seulement, jamais l\'adresse');
        self::assertStringNotContainsString('Paul', $details, 'audit : ids seulement, jamais le nom');
    }

    /**
     * Une demande à la fois : la seconde est refusée (409, aucun mail) ; l'annulation efface le
     * stamp, se trace, et rend la demande à nouveau possible ; rien à annuler → 404. Une demande de
     * club ouverte par le même compte sur un AUTRE code ne compte jamais (filtre par code).
     */
    public function testOneRequestAtATimePerCodeAndCancel(): void
    {
        $club = $this->bareClub('ONE');
        $marc = $this->insertMember($club['clubId'], 'Marc', 'admin');
        $paul = $this->insertMember($club['clubId'], 'Paul', 'member');
        $jwt = $this->jwtFor($paul['userId']);
        // Une demande VIVANTE de Paul sur un autre code (ancien club) : hors sujet ici.
        $this->insertClubRequest($paul['userId'], $this->uniq('AUTRE'), null);

        $me = $this->api('GET', '/api/me', $jwt);
        self::assertArrayHasKey('managerRequest', $me, 'le champ existe toujours');
        self::assertNull($me['managerRequest'], 'une demande sur un autre code n\'est jamais la demande de ce club');

        $this->api('POST', self::ROUTE, $jwt);
        self::assertResponseStatusCodeSame(201, 'une demande sur un autre code ne bloque pas celle-ci');
        self::assertCount(1, $this->mailsTo($this->sentEmails(), $marc['email']));

        $again = $this->api('POST', self::ROUTE, $jwt);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('Une demande est déjà en cours.', $again['error'] ?? null);
        self::assertSame([], $this->mailsTo($this->sentEmails(), $marc['email']), 'une seconde demande ne renvoie aucun mail');

        $this->api('DELETE', self::ROUTE, $jwt);
        self::assertResponseStatusCodeSame(204);
        self::assertNull($this->stamp($club['clubId'], $paul['userId']), 'annulée : stamp effacé');
        self::assertSame(1, $this->auditCount($club['clubId'], AuditAction::MEMBERSHIP_ROLE_REQUEST_CANCELLED), 'l\'annulation est auditée');
        self::assertSame([], $this->sentEmails(), 'l\'annulation n\'envoie aucun mail');

        $this->api('DELETE', self::ROUTE, $jwt);
        self::assertResponseStatusCodeSame(404, 'rien à annuler');

        $this->api('POST', self::ROUTE, $jwt);
        self::assertResponseStatusCodeSame(201, 'après annulation, la demande est de nouveau possible');
    }

    /**
     * Deux demandes concurrentes du même Membre se sérialisent sur le verrou
     * `manager-request:<club>:<user>` : tant qu'une autre session le tient, rien n'est écrit ni
     * envoyé. L'annulation clôt TOUTES les demandes vivantes de l'utilisateur sur le code : leurs
     * liens meurent (404 byte-identique), sans aucun mail.
     */
    public function testConcurrentRequestsSendOneMailAndCancelKillsEveryLink(): void
    {
        $club = $this->bareClub('CONC');
        $marc = $this->insertMember($club['clubId'], 'Marc', 'admin');
        $paul = $this->insertMember($club['clubId'], 'Paul', 'member');
        $jwt = $this->jwtFor($paul['userId']);

        $holder = $this->independentSession();
        $key = 'manager-request:' . $club['clubId'] . ':' . $paul['userId'];
        $taken = $holder->prepare('SELECT pg_try_advisory_lock(hashtext(?))');
        $taken->execute([$key]);
        self::assertTrue((bool) $taken->fetchColumn(), 'témoin : une autre session tient le verrou de la demande');
        $this->conn()->executeStatement('SET lock_timeout = \'1500ms\'');
        try {
            $this->api('POST', self::ROUTE, $jwt);
            self::assertGreaterThanOrEqual(500, $this->client->getResponse()->getStatusCode(), 'verrou tenu : la demande n\'aboutit pas');
        } finally {
            $holder->prepare('SELECT pg_advisory_unlock(hashtext(?))')->execute([$key]);
            $this->conn()->executeStatement('RESET lock_timeout');
        }
        self::assertNull($this->stamp($club['clubId'], $paul['userId']), 'verrou tenu : aucun stamp');
        self::assertSame([], $this->mailsTo($this->sentEmails(), $marc['email']), 'verrou tenu : aucun mail');

        $this->api('POST', self::ROUTE, $jwt);
        self::assertResponseStatusCodeSame(201, 'verrou libre : la demande passe');
        self::assertCount(1, $this->mailsTo($this->sentEmails(), $marc['email']), 'un seul mail');
        $this->api('DELETE', self::ROUTE, $jwt);
        self::assertResponseStatusCodeSame(204);

        // Club sans gestionnaire : deux demandes vivantes de Paul sur le code (une expirée, une en
        // cours) — l'annulation les clôt TOUTES, liens morts, aucun mail.
        $closing = $this->closingClub('CONK');
        $nina = $this->insertMember($closing['clubId'], 'Nina', 'member');
        $expired = $this->insertClubRequest($nina['userId'], $closing['ara'], null, ClubCreationRequest::STATUS_EXPIRED);
        $pending = $this->insertClubRequest($nina['userId'], $closing['ara'], null);
        $this->linkGet($pending['token']);
        self::assertResponseIsSuccessful('témoin : le lien vivant s\'ouvre');

        $this->api('DELETE', self::ROUTE, $this->jwtFor($nina['userId']));
        self::assertResponseStatusCodeSame(204);
        foreach ([$expired['id'], $pending['id']] as $id) {
            self::assertSame(ClubCreationRequest::STATUS_CANCELLED, $this->sql('SELECT status FROM club_creation_request WHERE id = :id', ['id' => $id], fetch: true), 'chaque demande vivante du code est close');
        }
        $unknown = $this->linkGet(str_repeat('c', 64));
        self::assertSame($unknown, $this->linkGet($pending['token']), 'lien mort : 404 byte-identique à un jeton inconnu');
        self::assertResponseStatusCodeSame(404);
        self::assertSame([], $this->sentEmails(), 'l\'annulation n\'envoie aucun mail');
    }

    /** « Accepter » fait du demandeur un gestionnaire, efface le stamp, le prévient et se trace ; un Membre ne peut pas accepter. */
    public function testManagerAcceptPromotesMailsAndAudits(): void
    {
        $club = $this->bareClub('ACPT');
        $marc = $this->insertMember($club['clubId'], 'Marc', 'admin');
        $kim = $this->insertMember($club['clubId'], 'Kim', 'member');
        $paul = $this->insertMember($club['clubId'], 'Paul', 'member');
        $this->api('POST', self::ROUTE, $this->jwtFor($paul['userId']));
        self::assertResponseStatusCodeSame(201);
        $membershipId = $this->membershipId($club['clubId'], $paul['userId']);
        $accept = '/api/memberships/' . $membershipId . '/manager-request/accept';

        $this->api('POST', $accept, $this->jwtFor($kim['userId']));
        self::assertResponseStatusCodeSame(403, 'un Membre n\'accepte jamais une demande');
        self::assertSame('member', $this->role($club['clubId'], $paul['userId']));

        $body = $this->api('POST', $accept, $this->jwtFor($marc['userId']));
        self::assertResponseIsSuccessful();
        self::assertSame(['id' => $membershipId, 'role' => 'admin', 'isActive' => true, 'isSelf' => false], $body);
        self::assertSame('admin', $this->role($club['clubId'], $paul['userId']), 'promu gestionnaire');
        self::assertNull($this->stamp($club['clubId'], $paul['userId']), 'stamp effacé');

        $mails = $this->mailsTo($this->sentEmails(), $paul['email']);
        self::assertCount(1, $mails, 'le demandeur est prévenu');
        self::assertSame(\sprintf('%s vous a nommé gestionnaire', $this->clubName($club['clubId'])), $mails[0]->getSubject());
        $this->assertCtaHeadersPointToBodyLink($mails[0], $this->base() . '/login', 'Me connecter');

        self::assertSame(1, $this->auditCount($club['clubId'], AuditAction::MEMBERSHIP_ROLE_GRANTED));
        $this->scopeGucToClub($club['clubId']);
        $row = $this->conn()->fetchAssociative('SELECT actor_user_id, details FROM audit_log WHERE club_id = :c AND action = :a', ['c' => $club['clubId'], 'a' => AuditAction::MEMBERSHIP_ROLE_GRANTED->value]);
        $this->clearGuc();
        self::assertIsArray($row);
        self::assertSame($marc['userId'], $row['actor_user_id'], 'l\'acteur est le gestionnaire qui accepte');
        self::assertStringContainsString('manager', (string) $row['details'], 'source : un gestionnaire');

        $again = $this->api('POST', $accept, $this->jwtFor($marc['userId']));
        self::assertResponseStatusCodeSame(409);
        self::assertSame('Aucune demande en cours.', $again['error'] ?? null);
    }

    /** « Refuser » efface le stamp, prévient et se trace ; le Membre garde sa consultation et peut redemander le jour même (aucun délai dans l'app). */
    public function testManagerRefuseClearsAndMailsThenRequestAgainIsAllowedTheSameDay(): void
    {
        $club = $this->bareClub('REFU');
        $marc = $this->insertMember($club['clubId'], 'Marc', 'admin');
        $paul = $this->insertMember($club['clubId'], 'Paul', 'member');
        $jwt = $this->jwtFor($paul['userId']);
        $this->api('POST', self::ROUTE, $jwt);
        self::assertResponseStatusCodeSame(201);

        $this->api('POST', '/api/memberships/' . $this->membershipId($club['clubId'], $paul['userId']) . '/manager-request/refuse', $this->jwtFor($marc['userId']));
        self::assertResponseIsSuccessful();
        self::assertNull($this->stamp($club['clubId'], $paul['userId']), 'refusée : stamp effacé');
        self::assertSame('member', $this->role($club['clubId'], $paul['userId']), 'le Membre reste Membre');
        self::assertTrue($this->isActive($club['clubId'], $paul['userId']), 'et garde son accès en consultation');
        self::assertSame(1, $this->auditCount($club['clubId'], AuditAction::MEMBERSHIP_ROLE_REFUSED));

        $mails = $this->mailsTo($this->sentEmails(), $paul['email']);
        self::assertCount(1, $mails, 'UN mail de refus');
        self::assertSame(\sprintf('%s n\'a pas donné suite à votre demande', $this->clubName($club['clubId'])), $mails[0]->getSubject());
        self::assertStringContainsString('demande de devenir gestionnaire. Vous gardez votre accès en consultation.', (string) $mails[0]->getTextBody());
        $this->assertCarriesNoCtaHeaders($mails[0]);

        $this->api('POST', self::ROUTE, $jwt);
        self::assertResponseStatusCodeSame(201, 'refus par un gestionnaire : redemande possible le jour même');
    }

    /**
     * Les seaux : 2/jour par utilisateur en test (3 en prod), 5/jour par club pour l'adresse
     * officielle — TOUS deux consommés APRÈS les refus 409 (une demande refusée d'office ne mange
     * aucun budget). Falsifié : consommer avant le 409 fait tomber la seconde demande légitime en 429.
     */
    public function testUserAndClubBucketsCapRequests(): void
    {
        // Seau utilisateur — club avec gestionnaire.
        $club = $this->bareClub('BKTU');
        $marc = $this->insertMember($club['clubId'], 'Marc', 'admin');
        $paul = $this->insertMember($club['clubId'], 'Paul', 'member');
        $jwt = $this->jwtFor($paul['userId']);
        $this->api('POST', self::ROUTE, $jwt);
        self::assertResponseStatusCodeSame(201, '1re demande');
        $this->api('POST', self::ROUTE, $jwt);
        self::assertResponseStatusCodeSame(409, 'doublon refusé d\'office');
        $this->api('POST', self::ROUTE, $this->jwtFor($marc['userId']));
        self::assertResponseStatusCodeSame(409, 'un gestionnaire ne demande rien');
        $this->api('DELETE', self::ROUTE, $jwt);
        $this->api('POST', self::ROUTE, $jwt);
        self::assertResponseStatusCodeSame(201, '2e demande : le 409 n\'a rien consommé');
        $this->api('DELETE', self::ROUTE, $jwt);
        $this->api('POST', self::ROUTE, $jwt);
        self::assertResponseStatusCodeSame(429, 'seau utilisateur épuisé');
        self::assertNull($this->stamp($club['clubId'], $paul['userId']), 'refusé par le seau : aucun stamp');

        // Seau club — club sans gestionnaire, 5 demandes/jour vers l'adresse officielle.
        $closing = $this->closingClub('BKTC');
        $first = $this->insertMember($closing['clubId'], 'Alpha', 'member');
        $this->api('POST', self::ROUTE, $this->jwtFor($first['userId']));
        self::assertResponseStatusCodeSame(201);
        $this->api('POST', self::ROUTE, $this->jwtFor($first['userId']));
        self::assertResponseStatusCodeSame(409, 'demande déjà vivante sur le code : refusée d\'office');
        foreach (['Bravo', 'Charlie', 'Delta', 'Echo'] as $name) {
            $member = $this->insertMember($closing['clubId'], $name, 'member');
            $this->api('POST', self::ROUTE, $this->jwtFor($member['userId']));
            self::assertResponseStatusCodeSame(201, \sprintf('%s : sous le plafond club (le 409 n\'a rien consommé)', $name));
        }
        $sixth = $this->insertMember($closing['clubId'], 'Foxtrot', 'member');
        $body = $this->api('POST', self::ROUTE, $this->jwtFor($sixth['userId']));
        self::assertResponseStatusCodeSame(429);
        self::assertSame('Le club a atteint sa limite de demandes pour aujourd\'hui.', $body['error'] ?? null);
        self::assertSame(0, (int) $this->sql('SELECT COUNT(*) FROM club_creation_request WHERE user_id = :u', ['u' => $sixth['userId']], fetch: true), 'au-delà du plafond : aucune demande ouverte');
    }

    /**
     * Ne demandent jamais : un gestionnaire (409), un désactivé ou un pending (aucun accès actif →
     * 403), le Membre d'un club DÉMO ou d'un club sans code FFBB (409, aucun mail, aucun appel
     * FFBB) ; un gestionnaire d'un AUTRE club n'accepte jamais (404, rien ne fuit).
     */
    public function testManagerOtherClubDeactivatedAndDemoCannotRequest(): void
    {
        $club = $this->bareClub('NOPE');
        $marc = $this->insertMember($club['clubId'], 'Marc', 'admin');
        $didier = $this->insertMember($club['clubId'], 'Didier', 'member', active: false, deactivated: true);
        $lea = $this->insertMember($club['clubId'], 'Léa', 'member', active: false);
        $paul = $this->insertMember($club['clubId'], 'Paul', 'member');

        $body = $this->api('POST', self::ROUTE, $this->jwtFor($marc['userId']));
        self::assertResponseStatusCodeSame(409);
        self::assertSame('Vous êtes déjà gestionnaire.', $body['error'] ?? null);
        foreach ([$didier, $lea] as $inactive) {
            $this->api('POST', self::ROUTE, $this->jwtFor($inactive['userId']));
            self::assertResponseStatusCodeSame(403, 'sans adhésion active, aucune demande');
            self::assertNull($this->stamp($club['clubId'], $inactive['userId']));
        }

        FfbbHttpClientStub::$organismeSearchCount = 0;
        $demo = $this->bareClub('DEMO', demo: true);
        $noCode = $this->bareClub('NOCD', withCode: false);
        foreach ([$demo, $noCode] as $target) {
            $this->insertMember($target['clubId'], 'Gerant', 'admin');
            $member = $this->insertMember($target['clubId'], 'Zoe', 'member');
            $refused = $this->api('POST', self::ROUTE, $this->jwtFor($member['userId']));
            self::assertResponseStatusCodeSame(409);
            self::assertSame('Cette demande n\'est pas possible pour ce club.', $refused['error'] ?? null);
            self::assertNull($this->stamp($target['clubId'], $member['userId']));
        }
        self::assertSame([], $this->sentEmails(), 'club démo / sans code : aucun mail');
        self::assertSame(0, FfbbHttpClientStub::$organismeSearchCount, 'aucun appel FFBB');

        // Un gestionnaire d'un AUTRE club ne touche jamais la demande de Paul.
        $this->api('POST', self::ROUTE, $this->jwtFor($paul['userId']));
        self::assertResponseStatusCodeSame(201);
        $other = $this->bareClub('AUTR');
        $zed = $this->insertMember($other['clubId'], 'Zed', 'admin');
        $this->api('POST', '/api/memberships/' . $this->membershipId($club['clubId'], $paul['userId']) . '/manager-request/accept', $this->jwtFor($zed['userId']));
        self::assertResponseStatusCodeSame(404, 'cible d\'un autre club : introuvable, rien ne fuit');
        self::assertSame('member', $this->role($club['clubId'], $paul['userId']));
        self::assertNotNull($this->stamp($club['clubId'], $paul['userId']), 'la demande est intacte');
    }

    // ---------------------------------------------------------------------------------------
    // Club SANS gestionnaire — la demande va à l'adresse officielle
    // ---------------------------------------------------------------------------------------

    /** Club sans gestionnaire : la demande part à l'adresse officielle — UN seul appel FFBB (la seule exception sortante d'un Membre), mail « reprendre » après commit. */
    public function testManagerlessClubRoutesTheRequestToTheOfficialMail(): void
    {
        $closing = $this->closingClub('', stubCode: true);
        $paul = $this->insertMember($closing['clubId'], 'Paul', 'member');

        FfbbHttpClientStub::$organismeSearchCount = 0;
        $body = $this->api('POST', self::ROUTE, $this->jwtFor($paul['userId']));
        self::assertResponseStatusCodeSame(201);
        self::assertSame('club_pending', $body['status'] ?? null);
        $this->assertIsoNow($body['since'] ?? null);
        self::assertTrue($body['clubEmailKnown'] ?? null);
        self::assertSame(1, FfbbHttpClientStub::$organismeSearchCount, 'un seul appel FFBB : la lecture de l\'adresse officielle');
        self::assertNull($this->stamp($closing['clubId'], $paul['userId']), 'club sans gestionnaire : pas de stamp, une demande au club');

        $request = $this->liveRequest($paul['userId'], $closing['ara']);
        self::assertSame(FfbbHttpClientStub::CLUB_EMAIL, $request['club_email']);
        $official = $this->mailsTo($this->sentEmails(), FfbbHttpClientStub::CLUB_EMAIL);
        self::assertCount(1, $official, 'UN mail à l\'adresse officielle');
        self::assertSame(\sprintf('Paul Temoin demande à reprendre l\'espace %s de %s', $this->product(), $this->clubName($closing['clubId'])), $official[0]->getSubject());
        self::assertStringContainsString($this->base() . '/club-approval/' . $request['token'], (string) $official[0]->getTextBody());

        $show = json_decode($this->linkGet($request['token']), true);
        self::assertResponseIsSuccessful('le lien du mail résout une demande COMMITÉE');
        self::assertIsArray($show);
        self::assertSame('reprise', $show['kind'] ?? null);
        self::assertSame($paul['email'], $show['requesterEmail'] ?? null, 'la page publique de reprise montre l\'adresse du demandeur');
    }

    /** Un Membre qui avait demandé AVANT le départ du dernier gestionnaire n'est pas bloqué par son vieux stamp : sa demande part à l'adresse officielle et le stamp meurt. */
    public function testStampedMemberCanStillTriggerOfficialMailWhenLastManagerLeaves(): void
    {
        $club = $this->bareClub('', stubCode: true);
        $marc = $this->insertMember($club['clubId'], 'Marc', 'admin');
        $lea = $this->insertMember($club['clubId'], 'Léa', 'member');
        $jwt = $this->jwtFor($lea['userId']);
        $this->api('POST', self::ROUTE, $jwt);
        self::assertResponseStatusCodeSame(201);
        self::assertNotNull($this->stamp($club['clubId'], $lea['userId']), 'témoin : stamp posé tant que Marc gérait');

        $this->eraseAccount($this->jwtFor($marc['userId']));
        self::assertNotNull($this->scheduledAt($club['clubId']), 'témoin : le club ferme');

        $body = $this->api('POST', self::ROUTE, $jwt);
        self::assertResponseStatusCodeSame(201, 'le vieux stamp ne bloque jamais la demande au club');
        self::assertSame('club_pending', $body['status'] ?? null);
        self::assertNull($this->stamp($club['clubId'], $lea['userId']), 'le vieux stamp est effacé');
        $official = array_values(array_filter(
            $this->mailsTo($this->sentEmails(), FfbbHttpClientStub::CLUB_EMAIL),
            static fn (Email $m): bool => str_contains((string) $m->getSubject(), 'demande à reprendre'),
        ));
        self::assertCount(1, $official, 'la demande part à l\'adresse officielle');
    }

    /** `/api/me` ne dit jamais « en attente des gestionnaires » sur un club qui n'en a plus : le vieux stamp ne se montre pas. */
    public function testMeNeverReportsPendingOnAManagerlessClub(): void
    {
        $club = $this->bareClub('MEPD');
        $marc = $this->insertMember($club['clubId'], 'Marc', 'admin');
        $lea = $this->insertMember($club['clubId'], 'Léa', 'member');
        $jwt = $this->jwtFor($lea['userId']);
        $this->api('POST', self::ROUTE, $jwt);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('pending', $this->api('GET', '/api/me', $jwt)['managerRequest']['status'] ?? null, 'témoin : en attente des gestionnaires');

        $this->eraseAccount($this->jwtFor($marc['userId']));
        self::assertNotNull($this->stamp($club['clubId'], $lea['userId']), 'témoin : le stamp est encore en base');
        self::assertNull($this->api('GET', '/api/me', $jwt)['managerRequest'], 'club sans gestionnaire : jamais « pending »');

        $this->api('POST', self::ROUTE, $jwt);
        self::assertResponseStatusCodeSame(201);
        $request = $this->api('GET', '/api/me', $jwt)['managerRequest'];
        self::assertIsArray($request);
        self::assertSame('club_pending', $request['status'] ?? null);
        self::assertFalse($request['clubEmailKnown'] ?? null, 'code inconnu de la FFBB : le support validera');
    }

    /**
     * Le premier approuvé gagne : la reprise clôt TOUTES les demandes sœurs du code sous le même
     * verrou. Un demandeur qui a DÉJÀ une ligne au club (Membre actif, ou ligne désactivée) →
     * `superseded`, mail « dépassée », lien mort ; un extérieur → adhésion pending et les
     * gestionnaires (le repreneur) sont prévenus.
     */
    public function testFirstApprovedWinsAndRepriseClosesSiblingsMailsMembersAndKillsTheirLinks(): void
    {
        $closing = $this->closingClub('SIBS');
        $paul = $this->insertMember($closing['clubId'], 'Paul', 'member');
        $lea = $this->insertMember($closing['clubId'], 'Léa', 'member');
        $didier = $this->insertMember($closing['clubId'], 'Didier', 'member', active: false, deactivated: true);
        $nadia = $this->insertUser('Nadia');
        $this->api('POST', self::ROUTE, $this->jwtFor($paul['userId']));
        self::assertResponseStatusCodeSame(201);
        $this->api('POST', self::ROUTE, $this->jwtFor($lea['userId']));
        self::assertResponseStatusCodeSame(201, 'une demande par MEMBRE, pas une par code');
        $didierRequest = $this->insertClubRequest($didier['userId'], $closing['ara'], null);
        $nadiaRequest = $this->insertClubRequest($nadia['userId'], $closing['ara'], null);
        $paulRequest = $this->liveRequest($paul['userId'], $closing['ara']);
        $leaRequest = $this->liveRequest($lea['userId'], $closing['ara']);
        $this->sentEmails();

        $this->publicDecision($paulRequest['token'], 'approve');
        self::assertSame(['status' => 'approved'], $this->responseJson());
        self::assertSame('admin', $this->role($closing['clubId'], $paul['userId']), 'le premier approuvé est gestionnaire');
        $sent = $this->sentEmails();
        $clubName = $this->clubName($closing['clubId']);

        foreach ([[$lea, $leaRequest], [$didier, $didierRequest]] as [$member, $request]) {
            self::assertSame(ClubCreationRequest::STATUS_SUPERSEDED, $this->requestStatus($request['id']), 'déjà une ligne au club → dépassée');
            $mails = $this->mailsTo($sent, $member['email']);
            self::assertCount(1, $mails, 'le demandeur dépassé est prévenu');
            self::assertSame(\sprintf('%s a déjà retrouvé un gestionnaire', $clubName), $mails[0]->getSubject());
            $this->assertCarriesNoCtaHeaders($mails[0]);
        }
        self::assertSame('member', $this->role($closing['clubId'], $lea['userId']), 'Léa reste Membre');
        self::assertSame(1, (int) $this->sql('SELECT COUNT(*) FROM club_user WHERE club_id = :c AND user_id = :u', ['c' => $closing['clubId'], 'u' => $didier['userId']], fetch: true), 'jamais une 2e ligne (unicité club+utilisateur)');
        self::assertFalse($this->isActive($closing['clubId'], $didier['userId']), 'la ligne désactivée n\'est pas réveillée');

        self::assertNotNull($this->sql('SELECT id FROM club_user WHERE club_id = :c AND user_id = :u AND is_active = false AND deactivated_at IS NULL', ['c' => $closing['clubId'], 'u' => $nadia['userId']], fetch: true), 'l\'extérieure devient une adhésion pending');
        self::assertNotSame(ClubCreationRequest::STATUS_PENDING, $this->requestStatus($nadiaRequest['id']), 'sa demande de club est close');
        $toPaul = array_values(array_filter($this->mailsTo($sent, $paul['email']), static fn (Email $m): bool => str_contains((string) $m->getSubject(), 'Nadia')));
        self::assertCount(1, $toPaul, 'le repreneur est prévenu de l\'adhésion de l\'extérieure');

        $unknown = $this->linkGet(str_repeat('d', 64));
        foreach ([$leaRequest, $didierRequest, $paulRequest] as $request) {
            self::assertSame($unknown, $this->linkGet($request['token']), 'lien mort : 404 byte-identique');
        }
        $this->publicDecision($leaRequest['token'], 'approve');
        self::assertResponseStatusCodeSame(404, 'le lien dépassé ne décide plus rien');
    }

    /** L'approbation officielle fait du demandeur un gestionnaire, annule la fermeture (retenue de purge comprise) et se trace. */
    public function testApprovalByTheClubPromotesCancelsClosureAndIsAudited(): void
    {
        $closing = $this->closingClub('APPR');
        $paul = $this->insertMember($closing['clubId'], 'Paul', 'member');
        $this->sql('UPDATE club SET erasure_reminder_sent_at = NOW(), purge_hold_until = NOW() + INTERVAL \'3 days\' WHERE id = :id', ['id' => $closing['clubId']]);
        $this->api('POST', self::ROUTE, $this->jwtFor($paul['userId']));
        self::assertResponseStatusCodeSame(201);

        $this->publicDecision($this->liveRequest($paul['userId'], $closing['ara'])['token'], 'approve');
        self::assertSame(['status' => 'approved'], $this->responseJson());

        self::assertSame('admin', $this->role($closing['clubId'], $paul['userId']));
        self::assertTrue($this->isActive($closing['clubId'], $paul['userId']));
        self::assertNull($this->scheduledAt($closing['clubId']), 'fermeture annulée');
        self::assertNull($this->sql('SELECT erasure_reminder_sent_at FROM club WHERE id = :id', ['id' => $closing['clubId']], fetch: true));
        self::assertNull($this->sql('SELECT purge_hold_until FROM club WHERE id = :id', ['id' => $closing['clubId']], fetch: true), 'la retenue de purge meurt avec la fermeture');
        self::assertNull($this->api('GET', '/api/me', $this->jwtFor($paul['userId']))['club']['closingOn'], 'bandeau éteint');

        self::assertSame(1, $this->auditCount($closing['clubId'], AuditAction::MEMBERSHIP_ROLE_GRANTED), 'la promotion est tracée');
        self::assertSame(1, $this->auditCount($closing['clubId'], AuditAction::CLUB_CLOSURE_CANCELLED), 'l\'annulation de fermeture est tracée');
        $this->scopeGucToClub($closing['clubId']);
        $row = $this->conn()->fetchAssociative('SELECT actor_user_id, details FROM audit_log WHERE club_id = :c AND action = :a', ['c' => $closing['clubId'], 'a' => AuditAction::MEMBERSHIP_ROLE_GRANTED->value]);
        $this->clearGuc();
        self::assertIsArray($row);
        self::assertNull($row['actor_user_id'], 'aucun acteur : l\'adresse officielle décide');
        self::assertStringContainsString('official_address', (string) $row['details']);
    }

    /** Un refus de l'adresse officielle se trace, laisse le Membre en consultation et bloque une nouvelle demande 30 jours (date dite). */
    public function testOfficialRefusalIsAuditedAndBlocksForThirtyDays(): void
    {
        $closing = $this->closingClub('OREF');
        $paul = $this->insertMember($closing['clubId'], 'Paul', 'member');
        $jwt = $this->jwtFor($paul['userId']);
        $this->api('POST', self::ROUTE, $jwt);
        self::assertResponseStatusCodeSame(201);
        $request = $this->liveRequest($paul['userId'], $closing['ara']);
        $this->sentEmails();

        $this->publicDecision($request['token'], 'refuse');
        self::assertSame(['status' => 'refused'], $this->responseJson());
        $decided = new DateTimeImmutable((string) $this->sql('SELECT decided_at FROM club_creation_request WHERE id = :id', ['id' => $request['id']], fetch: true));
        $renew = $decided->modify('+30 days');

        self::assertSame('member', $this->role($closing['clubId'], $paul['userId']));
        self::assertTrue($this->isActive($closing['clubId'], $paul['userId']), 'il garde la consultation');
        self::assertSame(1, $this->auditCount($closing['clubId'], AuditAction::MEMBERSHIP_ROLE_REFUSED), 'le refus officiel est tracé');
        $mails = $this->mailsTo($this->sentEmails(), $paul['email']);
        self::assertCount(1, $mails, 'UN mail de refus');
        self::assertSame(
            \sprintf("Bonjour,\n\nLe club %s n'a pas donné suite à votre demande de reprise. Vous gardez votre accès en consultation ; vous pourrez renouveler votre demande à partir du %s.\n\n%s", $this->clubName($closing['clubId']), $renew->format('d/m/Y'), $this->product()),
            (string) $mails[0]->getTextBody(),
        );
        $this->assertCarriesNoCtaHeaders($mails[0]);

        $me = $this->api('GET', '/api/me', $jwt)['managerRequest'];
        self::assertIsArray($me);
        self::assertSame('refused', $me['status'] ?? null);
        self::assertStringStartsWith($renew->format('Y-m-d'), (string) ($me['until'] ?? ''), '`until` = date de renouvellement');

        $body = $this->api('POST', self::ROUTE, $jwt);
        self::assertResponseStatusCodeSame(409);
        self::assertSame(\sprintf('Le club a refusé votre demande le %s : vous pourrez la renouveler à partir du %s.', $decided->format('d/m/Y'), $renew->format('d/m/Y')), $body['error'] ?? null);

        $this->sql('UPDATE club_creation_request SET decided_at = NOW() - INTERVAL \'31 days\' WHERE id = :id', ['id' => $request['id']]);
        $this->api('POST', self::ROUTE, $jwt);
        self::assertResponseStatusCodeSame(201, 'au-delà de 30 jours, la demande est de nouveau possible');
    }

    /**
     * Le lien d'approbation ouvert par un visiteur CONNECTÉ à un autre club (son JWT pose son propre
     * club) décide correctement : la ligne du demandeur est lue hors filtre tenant — promue en
     * reprise, jamais doublée ; déclarée « dépassée » quand le club a retrouvé un gestionnaire.
     */
    public function testApprovalByVisitorLoggedIntoAnotherClub(): void
    {
        $elsewhere = $this->bareClub('VIS');
        $visitor = $this->insertMember($elsewhere['clubId'], 'Visiteur', 'admin');
        $visitorJwt = $this->jwtFor($visitor['userId']);

        // (a) Reprise : le Membre est promu sur SA ligne, jamais une seconde ligne.
        $closing = $this->closingClub('VISA');
        $paul = $this->insertMember($closing['clubId'], 'Paul', 'member');
        $this->api('POST', self::ROUTE, $this->jwtFor($paul['userId']));
        self::assertResponseStatusCodeSame(201);
        $this->publicDecision($this->liveRequest($paul['userId'], $closing['ara'])['token'], 'approve', $visitorJwt);
        self::assertSame(['status' => 'approved'], $this->responseJson());
        self::assertSame('admin', $this->role($closing['clubId'], $paul['userId']));
        self::assertSame(1, (int) $this->sql('SELECT COUNT(*) FROM club_user WHERE club_id = :c AND user_id = :u', ['c' => $closing['clubId'], 'u' => $paul['userId']], fetch: true));

        // (b) Le club a retrouvé un gestionnaire entre-temps : la demande du Membre est « dépassée ».
        $managed = $this->bareClub('VISB');
        $this->insertMember($managed['clubId'], 'Marc', 'admin');
        $lea = $this->insertMember($managed['clubId'], 'Léa', 'member');
        $request = $this->insertClubRequest($lea['userId'], $managed['ara'], null);
        $this->publicDecision($request['token'], 'approve', $visitorJwt);
        self::assertSame(['status' => 'superseded'], $this->responseJson());
        self::assertSame(ClubCreationRequest::STATUS_SUPERSEDED, $this->requestStatus($request['id']));
        self::assertSame('member', $this->role($managed['clubId'], $lea['userId']));
        self::assertSame(1, (int) $this->sql('SELECT COUNT(*) FROM club_user WHERE club_id = :c AND user_id = :u', ['c' => $managed['clubId'], 'u' => $lea['userId']], fetch: true), 'jamais une 2e ligne');
        self::assertCount(1, array_filter($this->mailsTo($this->sentEmails(), $lea['email']), static fn (Email $m): bool => str_contains((string) $m->getSubject(), 'a déjà retrouvé un gestionnaire')), 'mail « dépassée »');
    }

    /**
     * Un lien échu répond 410 ; un lien décidé ne décide qu'une fois ; à l'échéance (7 j sans
     * réponse), le demandeur est prévenu, `/api/me` dit « expirée » et il peut renvoyer sa demande.
     */
    public function testExpiredLinkIs410TokenSingleUseAndExpiryTellsTheRequester(): void
    {
        $closing = $this->closingClub('EXPI');
        $paul = $this->insertMember($closing['clubId'], 'Paul', 'member');
        $jwt = $this->jwtFor($paul['userId']);
        $this->api('POST', self::ROUTE, $jwt);
        self::assertResponseStatusCodeSame(201);
        $request = $this->liveRequest($paul['userId'], $closing['ara']);
        $requestedAt = new DateTimeImmutable((string) $this->sql('SELECT created_at FROM club_creation_request WHERE id = :id', ['id' => $request['id']], fetch: true));
        $this->sql('UPDATE club_creation_request SET expires_at = NOW() - INTERVAL \'1 day\' WHERE id = :id', ['id' => $request['id']]);

        $this->linkGet($request['token']);
        self::assertResponseStatusCodeSame(410, 'lien échu');
        $this->sentEmails();

        $this->runCommand('app:club-approvals:digest');
        self::assertSame(ClubCreationRequest::STATUS_EXPIRED, $this->requestStatus($request['id']));
        $mails = $this->mailsTo($this->sentEmails(), $paul['email']);
        self::assertCount(1, $mails, 'le demandeur apprend l\'expiration');
        self::assertSame(\sprintf('Le club %s n\'a pas répondu à votre demande', $this->clubName($closing['clubId'])), $mails[0]->getSubject());
        self::assertStringContainsString(\sprintf('votre demande de reprise du %s dans les 7 jours', $requestedAt->format('d/m/Y')), (string) $mails[0]->getTextBody());
        $this->assertCarriesNoCtaHeaders($mails[0]);

        $me = $this->api('GET', '/api/me', $jwt)['managerRequest'];
        self::assertIsArray($me);
        self::assertSame('expired', $me['status'] ?? null);
        self::assertStringStartsWith($requestedAt->format('Y-m-d'), (string) ($me['since'] ?? ''), '`since` = date de la demande expirée');

        $this->api('POST', self::ROUTE, $jwt);
        self::assertResponseStatusCodeSame(201, '« Vous pouvez la renvoyer » : une demande expirée n\'interdit pas d\'en envoyer une nouvelle');
        self::assertSame(1, (int) $this->sql('SELECT COUNT(*) FROM club_creation_request WHERE user_id = :u AND ara = :a AND status IN (\'pending\', \'expired\')', ['u' => $paul['userId'], 'a' => $closing['ara']], fetch: true), 'une seule demande vivante à la fois sur le code');

        // Usage unique du lien.
        $fresh = $this->liveRequest($paul['userId'], $closing['ara']);
        $this->publicDecision($fresh['token'], 'refuse');
        self::assertSame(['status' => 'refused'], $this->responseJson());
        $this->publicDecision($fresh['token'], 'approve');
        self::assertResponseStatusCodeSame(404, 'une décision par lien');
        self::assertSame('member', $this->role($closing['clubId'], $paul['userId']));
    }

    /**
     * Adresse officielle inconnue au moment de la demande (FFBB muette) : la relève quotidienne la
     * relit et envoie le mail dès qu'elle revient ; les demandes encore sans adresse sur un club en
     * fermeture sont signalées au support (une liste, une fois par passage).
     */
    public function testUnknownAddressIsRetriedByDigestAndSupportIsAlerted(): void
    {
        $support = 'support-' . bin2hex(random_bytes(4)) . '@amateo.test';
        $this->insertSuperAdmin($support);

        // (a) La FFBB est muette au moment de la demande, puis revient.
        $stub = $this->closingClub('', stubCode: true);
        $paul = $this->insertMember($stub['clubId'], 'Paul', 'member');
        FfbbHttpClientStub::$failSearch = true;
        try {
            $body = $this->api('POST', self::ROUTE, $this->jwtFor($paul['userId']));
        } finally {
            FfbbHttpClientStub::$failSearch = false;
        }
        self::assertResponseStatusCodeSame(201);
        self::assertFalse($body['clubEmailKnown'] ?? null);
        self::assertSame([], $this->mailsTo($this->sentEmails(), FfbbHttpClientStub::CLUB_EMAIL), 'adresse inconnue : rien envoyé');

        // (b) Un club en fermeture dont le code ne donne jamais d'adresse.
        $lost = $this->closingClub('LOST');
        $lea = $this->insertMember($lost['clubId'], 'Léa', 'member');
        $this->api('POST', self::ROUTE, $this->jwtFor($lea['userId']));
        self::assertResponseStatusCodeSame(201);

        $this->runCommand('app:club-approvals:digest');
        $sent = $this->sentEmails();

        self::assertSame(FfbbHttpClientStub::CLUB_EMAIL, $this->liveRequest($paul['userId'], $stub['ara'])['club_email'], 'l\'adresse revenue est enregistrée');
        $official = $this->mailsTo($sent, FfbbHttpClientStub::CLUB_EMAIL);
        self::assertCount(1, $official, 'le mail « reprendre » part dès que l\'adresse revient');
        self::assertStringContainsString('demande à reprendre', (string) $official[0]->getSubject());

        $alerts = $this->mailsTo($sent, $support);
        self::assertCount(1, $alerts, 'le support reçoit UNE liste');
        self::assertSame('Demandes de reprise sans adresse officielle — 1 club(s)', $alerts[0]->getSubject());
        self::assertStringContainsString($this->clubName($lost['clubId']), (string) $alerts[0]->getTextBody());
        self::assertStringNotContainsString($this->clubName($stub['clubId']), (string) $alerts[0]->getTextBody(), 'un club dont l\'adresse est revenue n\'est plus signalé');
        $this->assertCtaHeadersPointToBodyLink($alerts[0], $this->base() . '/admin', 'Ouvrir la console');
    }

    /**
     * Une demande vivante retient la purge UNE fois, jusqu'à l'échéance de la demande (pas de
     * renouvellement) ; au-delà, la purge passe même si une demande vit encore.
     */
    public function testPendingRequestHoldsThePurgeOnceUntilItsExpiry(): void
    {
        $closing = $this->closingClub('HOLD', seeded: true);
        $paul = $this->insertMember($closing['clubId'], 'Paul', 'member');
        $this->api('POST', self::ROUTE, $this->jwtFor($paul['userId']));
        self::assertResponseStatusCodeSame(201);
        $request = $this->liveRequest($paul['userId'], $closing['ara']);
        $this->sql('UPDATE club SET erasure_scheduled_at = NOW() - INTERVAL \'1 hour\', erasure_reminder_sent_at = NOW() - INTERVAL \'8 days\' WHERE id = :id', ['id' => $closing['clubId']]);

        $tester = $this->runCommand('app:clubs:purge-erased');
        self::assertNull($this->sql('SELECT unsubscribed_at FROM club WHERE id = :id', ['id' => $closing['clubId']], fetch: true), 'demande vivante : purge retenue');
        self::assertTrue($this->clubHasSeason($closing['clubId']), 'workspace intact');
        $hold = $this->sql('SELECT purge_hold_until FROM club WHERE id = :id', ['id' => $closing['clubId']], fetch: true);
        self::assertIsString($hold);
        self::assertSame((new DateTimeImmutable($request['expires_at']))->format('Y-m-d'), (new DateTimeImmutable($hold))->format('Y-m-d'), 'retenue jusqu\'à l\'échéance de la demande');
        self::assertStringContainsString($closing['clubId'], $tester->getDisplay(), 'la retenue est tracée en sortie');

        $this->runCommand('app:clubs:purge-erased');
        self::assertNull($this->sql('SELECT unsubscribed_at FROM club WHERE id = :id', ['id' => $closing['clubId']], fetch: true), 'avant l\'échéance de la retenue : toujours sautée');
        self::assertSame($hold, $this->sql('SELECT purge_hold_until FROM club WHERE id = :id', ['id' => $closing['clubId']], fetch: true), 'la retenue n\'est pas renouvelée');

        // L'échéance de la retenue passe : la purge a lieu, même avec une demande encore vivante.
        $this->sql('UPDATE club SET purge_hold_until = NOW() - INTERVAL \'1 hour\' WHERE id = :id', ['id' => $closing['clubId']]);
        self::assertSame(ClubCreationRequest::STATUS_PENDING, $this->requestStatus($request['id']), 'témoin : la demande vit encore');
        $this->runCommand('app:clubs:purge-erased');
        self::assertNotNull($this->sql('SELECT unsubscribed_at FROM club WHERE id = :id', ['id' => $closing['clubId']], fetch: true), 'une seule retenue : la purge passe ensuite');
    }

    /** `/api/me.club.closingOn` = la plus tardive de l'échéance d'effacement et de la retenue de purge. */
    public function testMeClosingOnFollowsPurgeHold(): void
    {
        $closing = $this->closingClub('CLHO');
        $paul = $this->insertMember($closing['clubId'], 'Paul', 'member');
        $jwt = $this->jwtFor($paul['userId']);

        $this->sql('UPDATE club SET erasure_scheduled_at = NOW() + INTERVAL \'3 days\', purge_hold_until = NOW() + INTERVAL \'6 days\' WHERE id = :id', ['id' => $closing['clubId']]);
        $hold = new DateTimeImmutable((string) $this->sql('SELECT purge_hold_until FROM club WHERE id = :id', ['id' => $closing['clubId']], fetch: true));
        self::assertSame($hold->format('Y-m-d'), $this->api('GET', '/api/me', $jwt)['club']['closingOn'], 'retenue plus tardive : c\'est elle qui dit la date');

        $this->sql('UPDATE club SET purge_hold_until = NOW() + INTERVAL \'1 day\' WHERE id = :id', ['id' => $closing['clubId']]);
        $scheduled = $this->scheduledAt($closing['clubId']);
        self::assertInstanceOf(DateTimeImmutable::class, $scheduled);
        self::assertSame($scheduled->format('Y-m-d'), $this->api('GET', '/api/me', $jwt)['club']['closingOn'], 'échéance plus tardive : c\'est elle');
    }

    /** Le workspace a été purgé pendant la demande (retenue déjà consommée) : l'approbation reprend le club, recrée la ligne du demandeur et re-sème le workspace. */
    public function testPurgeDuringRequestThenApprovalReseeds(): void
    {
        $closing = $this->closingClub('RSED', seeded: true);
        $paul = $this->insertMember($closing['clubId'], 'Paul', 'member');
        $this->api('POST', self::ROUTE, $this->jwtFor($paul['userId']));
        self::assertResponseStatusCodeSame(201);
        $request = $this->liveRequest($paul['userId'], $closing['ara']);
        $this->sql('UPDATE club SET erasure_scheduled_at = NOW() - INTERVAL \'1 hour\', erasure_reminder_sent_at = NOW() - INTERVAL \'8 days\', purge_hold_until = NOW() - INTERVAL \'1 hour\' WHERE id = :id', ['id' => $closing['clubId']]);

        $this->runCommand('app:clubs:purge-erased');
        self::assertNotNull($this->sql('SELECT unsubscribed_at FROM club WHERE id = :id', ['id' => $closing['clubId']], fetch: true), 'témoin : purgé');
        self::assertFalse($this->clubHasSeason($closing['clubId']), 'témoin : workspace vidé');
        self::assertSame(0, (int) $this->sql('SELECT COUNT(*) FROM club_user WHERE club_id = :c', ['c' => $closing['clubId']], fetch: true), 'témoin : adhésions supprimées');

        $this->publicDecision($request['token'], 'approve');
        self::assertSame(['status' => 'approved'], $this->responseJson());
        self::assertSame('admin', $this->role($closing['clubId'], $paul['userId']), 'la ligne du demandeur est recréée, gestionnaire');
        self::assertTrue($this->isActive($closing['clubId'], $paul['userId']));
        self::assertTrue($this->clubHasSeason($closing['clubId']), 'workspace re-semé');
        self::assertNull($this->sql('SELECT unsubscribed_at FROM club WHERE id = :id', ['id' => $closing['clubId']], fetch: true), 'le club n\'est plus désinscrit');
    }

    /**
     * Le demandeur efface son compte : sa demande de reprise meurt (`cancelled`, lien 404
     * byte-identique, aucun mail). Angle Membre de la promesse prouvée pour une demande de
     * création par `MembershipRequestNotificationTest::testErasureClosesTheUsersPendingClubRequest`
     * (RGPD, point sensible : doublon voulu).
     */
    public function testErasedRequesterRequestIsCancelledAndLinkIs404(): void
    {
        $closing = $this->closingClub('ERAS');
        $paul = $this->insertMember($closing['clubId'], 'Paul', 'member');
        $jwt = $this->jwtFor($paul['userId']);
        $this->api('POST', self::ROUTE, $jwt);
        self::assertResponseStatusCodeSame(201);
        $request = $this->liveRequest($paul['userId'], $closing['ara']);
        $this->sentEmails();

        $this->eraseAccount($jwt);
        self::assertSame(ClubCreationRequest::STATUS_CANCELLED, $this->requestStatus($request['id']));
        foreach ($this->sentEmails() as $mail) {
            self::assertStringNotContainsString('reprendre', (string) $mail->getSubject(), 'aucun mail sur la demande close');
        }
        $unknown = $this->linkGet(str_repeat('e', 64));
        self::assertSame($unknown, $this->linkGet($request['token']), 'lien mort : 404 byte-identique');
        self::assertResponseStatusCodeSame(404);
    }

    /** Le stamp meurt à la promotion (changement de rôle), à la reprise, à la désactivation (et ne revient pas à la réactivation) et à l'effacement. */
    public function testRoleChangeRepriseDeactivationAndErasureClearStamp(): void
    {
        $club = $this->bareClub('STMP');
        $marc = $this->insertMember($club['clubId'], 'Marc', 'admin');
        $marcJwt = $this->jwtFor($marc['userId']);
        $paul = $this->insertMember($club['clubId'], 'Paul', 'member');
        $lea = $this->insertMember($club['clubId'], 'Léa', 'member');
        $kim = $this->insertMember($club['clubId'], 'Kim', 'member');
        foreach ([$paul, $lea, $kim] as $member) {
            $this->api('POST', self::ROUTE, $this->jwtFor($member['userId']));
            self::assertResponseStatusCodeSame(201);
            self::assertNotNull($this->stamp($club['clubId'], $member['userId']), 'témoin : stamp posé');
        }

        $this->api('POST', '/api/memberships/' . $this->membershipId($club['clubId'], $paul['userId']) . '/role', $marcJwt, ['role' => 'admin']);
        self::assertResponseIsSuccessful();
        self::assertNull($this->stamp($club['clubId'], $paul['userId']), 'promotion : stamp effacé');

        $leaId = $this->membershipId($club['clubId'], $lea['userId']);
        $this->api('POST', '/api/memberships/' . $leaId . '/deactivate', $marcJwt);
        self::assertResponseIsSuccessful();
        self::assertNull($this->stamp($club['clubId'], $lea['userId']), 'désactivation : stamp effacé');
        $this->api('POST', '/api/memberships/' . $leaId . '/reactivate', $marcJwt);
        self::assertResponseIsSuccessful();
        self::assertNull($this->stamp($club['clubId'], $lea['userId']), 'réactivée : la demande ne réapparaît pas');

        $this->eraseAccount($this->jwtFor($kim['userId']));
        self::assertNull($this->stamp($club['clubId'], $kim['userId']), 'effacement : stamp effacé');

        // Reprise : un Membre stampé du temps où le club avait un gestionnaire est repris via
        // l'adresse officielle — son stamp meurt avec la promotion.
        $closing = $this->bareClub('STRP');
        $gone = $this->insertMember($closing['clubId'], 'Gone', 'admin');
        $zed = $this->insertMember($closing['clubId'], 'Zed', 'member');
        $this->api('POST', self::ROUTE, $this->jwtFor($zed['userId']));
        self::assertResponseStatusCodeSame(201);
        $this->eraseAccount($this->jwtFor($gone['userId']));
        $request = $this->insertClubRequest($zed['userId'], $closing['ara'], null);
        self::assertNotNull($this->stamp($closing['clubId'], $zed['userId']), 'témoin : stamp encore posé');
        $this->publicDecision($request['token'], 'approve');
        self::assertSame(['status' => 'approved'], $this->responseJson());
        self::assertSame('admin', $this->role($closing['clubId'], $zed['userId']));
        self::assertNull($this->stamp($closing['clubId'], $zed['userId']), 'reprise : stamp effacé');
    }

    /** Un compte effacé (RGPD) ne devient jamais gestionnaire, même si sa demande traîne. */
    public function testAnonymizedTargetCannotBeAccepted(): void
    {
        $club = $this->bareClub('ANON');
        $marc = $this->insertMember($club['clubId'], 'Marc', 'admin');
        $paul = $this->insertMember($club['clubId'], 'Paul', 'member');
        $this->api('POST', self::ROUTE, $this->jwtFor($paul['userId']));
        self::assertResponseStatusCodeSame(201);
        $this->sql('UPDATE app_user SET anonymized_at = NOW() WHERE id = :id', ['id' => $paul['userId']]);

        $this->api('POST', '/api/memberships/' . $this->membershipId($club['clubId'], $paul['userId']) . '/manager-request/accept', $this->jwtFor($marc['userId']));
        self::assertResponseStatusCodeSame(409);
        self::assertSame('member', $this->role($club['clubId'], $paul['userId']), 'jamais promu');
        self::assertSame([], $this->mailsTo($this->sentEmails(), $paul['email']));
    }

    /** La demande se lit : `/api/me.managerRequest` pour le Membre, `managerRequestedAt` par ligne pour le gestionnaire. */
    public function testMeAndMembershipsExposeTheRequest(): void
    {
        $club = $this->bareClub('EXPO');
        $marc = $this->insertMember($club['clubId'], 'Marc', 'admin');
        $paul = $this->insertMember($club['clubId'], 'Paul', 'member');
        $kim = $this->insertMember($club['clubId'], 'Kim', 'member');
        $jwt = $this->jwtFor($paul['userId']);
        self::assertNull($this->api('GET', '/api/me', $jwt)['managerRequest'], 'aucune demande');

        $this->api('POST', self::ROUTE, $jwt);
        self::assertResponseStatusCodeSame(201);
        $request = $this->api('GET', '/api/me', $jwt)['managerRequest'];
        self::assertIsArray($request);
        self::assertSame('pending', $request['status'] ?? null);
        $this->assertIsoNow($request['since'] ?? null);

        $members = $this->api('GET', '/api/memberships', $this->jwtFor($marc['userId']))['members'] ?? [];
        self::assertIsArray($members);
        $byUser = array_column($members, null, 'userId');
        self::assertArrayHasKey('managerRequestedAt', $byUser[$kim['userId']], 'le champ existe sur chaque ligne');
        self::assertNull($byUser[$kim['userId']]['managerRequestedAt']);
        $this->assertIsoNow($byUser[$paul['userId']]['managerRequestedAt'] ?? null);
    }

    /** L'export art. 15 du Membre porte la date de sa demande (donnée personnelle). */
    public function testManagerRequestIsInTheArticle15Export(): void
    {
        $club = $this->bareClub('ART');
        $this->insertMember($club['clubId'], 'Marc', 'admin');
        $paul = $this->insertMember($club['clubId'], 'Paul', 'member');
        $jwt = $this->jwtFor($paul['userId']);
        $this->api('POST', self::ROUTE, $jwt);
        self::assertResponseStatusCodeSame(201);

        $export = $this->api('GET', '/api/me/export', $jwt);
        self::assertResponseIsSuccessful();
        $rows = array_values(array_filter((array) ($export['memberships'] ?? []), static fn (mixed $m): bool => \is_array($m) && ($m['club_id'] ?? null) === $club['clubId']));
        self::assertCount(1, $rows);
        self::assertArrayHasKey('manager_requested_at', $rows[0]);
        self::assertNotNull($rows[0]['manager_requested_at'], 'la date de la demande est exportée');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        FfbbHttpClientStub::$failSearch = false;
        FfbbHttpClientStub::$organismeSearchCount = 0;
    }

    protected function tearDown(): void
    {
        FfbbHttpClientStub::$failSearch = false;
        self::getContainer()->get(TenantConnectionContext::class)->clear();
        parent::tearDown();
    }

    // ---------------------------------------------------------------------------------------
    // Décor
    // ---------------------------------------------------------------------------------------

    /**
     * Un club réel (non démo) sans membre. `stubCode` : le code que le stub FFBB connaît (adresse
     * officielle {@see FfbbHttpClientStub::CLUB_EMAIL}) — le stub partage ce code entre suites, un
     * club qui le porterait est retiré d'abord.
     *
     * @return array{clubId: string, ara: string}
     */
    private function bareClub(string $tag, bool $stubCode = false, bool $demo = false, bool $withCode = true): array
    {
        $ara = $stubCode ? FfbbHttpClientStub::CLUB_CODE : $this->uniq($tag);
        if ($stubCode) {
            $this->conn()->executeStatement('DELETE FROM club WHERE ffbb_club_code = :a AND is_demo = false', ['a' => $ara]);
        }
        $suffix = strtolower($ara) . bin2hex(random_bytes(2));
        $club = (new Club)->setName('Club ' . $ara)->setSlug('club-' . $suffix)->setTimezone('Europe/Paris')->setLocale('fr');
        if ($withCode) {
            $club->setFfbbClubCode($ara);
        }
        if ($demo) {
            $club->setIsDemo(true);
        }
        $this->em()->persist($club);
        $this->em()->flush();

        return ['clubId' => $club->getId(), 'ara' => $ara];
    }

    /**
     * Un club EN FERMETURE : aucun gestionnaire actif, effacement programmé à +30 j (l'état exact
     * que laisse l'effacement du dernier gestionnaire). `seeded` : avec un workspace à purger.
     *
     * @return array{clubId: string, ara: string}
     */
    private function closingClub(string $tag, bool $stubCode = false, bool $seeded = false): array
    {
        $club = $this->bareClub($tag, $stubCode);
        if ($seeded) {
            $this->scopeGucToClub($club['clubId']);
            $entity = $this->em()->getRepository(Club::class)->find($club['clubId']);
            self::assertInstanceOf(Club::class, $entity);
            self::getContainer()->get(ClubProvisioner::class)->seedWorkspace($entity);
            $this->em()->flush();
            $this->clearGuc();
        }
        $this->sql('UPDATE club SET erasure_scheduled_at = NOW() + INTERVAL \'30 days\' WHERE id = :id', ['id' => $club['clubId']]);

        return $club;
    }

    /** @return array{userId: string, email: string} */
    private function insertUser(string $firstName): array
    {
        $email = strtolower($this->slug($firstName)) . '-' . bin2hex(random_bytes(4)) . '@test.fr';
        $user = new User;
        $user->setEmail($email);
        $user->setFirstName($firstName);
        $user->setLastName('Temoin');
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        \assert($hasher instanceof UserPasswordHasherInterface);
        $user->setPasswordHash($hasher->hashPassword($user, self::PASSWORD));
        $user->setEmailVerifiedAt(new DateTimeImmutable);
        $this->em()->persist($user);
        $this->em()->flush();

        return ['userId' => $user->getId(), 'email' => $email];
    }

    /** @return array{userId: string, email: string} */
    private function insertMember(string $clubId, string $firstName, string $role, bool $active = true, bool $deactivated = false): array
    {
        $user = $this->insertUser($firstName);
        $this->scopeGucToClub($clubId);
        $membership = new ClubUser;
        $membership->setClubId($clubId);
        $membership->setUserId($user['userId']);
        $membership->setRole($role);
        $membership->setIsActive($active);
        if ($deactivated) {
            $membership->setDeactivatedAt(new DateTimeImmutable);
        }
        $this->em()->persist($membership);
        $this->em()->flush();
        $this->clearGuc();

        return $user;
    }

    /**
     * Une demande de club insérée directement (décor d'un état que l'API produit ailleurs : demande
     * ouverte avant un changement de situation du club).
     *
     * @return array{id: string, token: string}
     */
    private function insertClubRequest(string $userId, string $ara, ?string $clubEmail, string $status = ClubCreationRequest::STATUS_PENDING): array
    {
        $request = new ClubCreationRequest;
        $request->setUserId($userId);
        $request->setAra($ara);
        $request->setClubName('Club ' . $ara);
        $request->setClubEmail($clubEmail);
        $request->setStatus($status);
        $this->em()->persist($request);
        $this->em()->flush();

        return ['id' => $request->getId(), 'token' => $request->getToken()];
    }

    private function insertSuperAdmin(string $email): void
    {
        $this->admin()->executeStatement(
            'INSERT INTO super_admin (id, email, password_hash, totp_secret, enabled, created_at) VALUES (:id, :email, :password, :secret, :enabled, NOW())',
            ['id' => Uuid::v4()->toRfc4122(), 'email' => $email, 'password' => 'x', 'secret' => 'x', 'enabled' => true],
            ['enabled' => ParameterType::BOOLEAN],
        );
    }

    private function eraseAccount(string $jwt): void
    {
        $this->api('DELETE', '/api/me', $jwt, ['password' => self::PASSWORD]);
        self::assertResponseIsSuccessful();
    }

    private function publicDecision(string $token, string $decision, ?string $visitorJwt = null): void
    {
        $this->startFreshBrowserSession($this->client);
        $server = ['REMOTE_ADDR' => $this->ip(), 'CONTENT_TYPE' => 'application/json'];
        if (null !== $visitorJwt) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $visitorJwt;
        }
        $this->client->request('POST', '/api/club-approvals/' . $token, [], [], $server, json_encode(['decision' => $decision], \JSON_THROW_ON_ERROR));
    }

    private function linkGet(string $token): string
    {
        $this->startFreshBrowserSession($this->client);
        $this->client->request('GET', '/api/club-approvals/' . $token, [], [], ['REMOTE_ADDR' => $this->ip()]);

        return (string) $this->client->getResponse()->getContent();
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed>
     */
    private function api(string $method, string $uri, string $jwt, ?array $body = null): array
    {
        $this->startFreshBrowserSession($this->client);
        $this->client->request($method, $uri, [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt, 'REMOTE_ADDR' => $this->ip(),
        ], null === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR));

        return (array) json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    /** @return array<string, mixed> */
    private function responseJson(): array
    {
        return (array) json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    /** @param array<string, mixed> $options */
    private function runCommand(string $name, array $options = []): CommandTester
    {
        $tester = new CommandTester(new Application(self::$kernel)->find($name));
        $tester->execute($options);

        return $tester;
    }

    // ---------------------------------------------------------------------------------------
    // Lecture
    // ---------------------------------------------------------------------------------------

    /** @return list<Email> les mails enfilés sur le transport mémoire du container courant (vidé à la lecture) */
    private function sentEmails(): array
    {
        $transport = self::getContainer()->get('messenger.transport.mailer_in_memory');
        \assert($transport instanceof InMemoryTransport);
        $emails = [];
        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if (method_exists($message, 'getMessage') && $message->getMessage() instanceof Email) {
                $emails[] = $message->getMessage();
            }
        }
        $transport->reset();

        return $emails;
    }

    /**
     * @param list<Email> $emails
     *
     * @return list<Email>
     */
    private function mailsTo(array $emails, string $address): array
    {
        return array_values(array_filter($emails, static function (Email $email) use ($address): bool {
            foreach ([...$email->getTo(), ...$email->getCc(), ...$email->getBcc()] as $to) {
                if (strtolower($to->getAddress()) === strtolower($address)) {
                    return true;
                }
            }

            return false;
        }));
    }

    private function stamp(string $clubId, string $userId): mixed
    {
        return $this->sql('SELECT manager_requested_at FROM club_user WHERE club_id = :c AND user_id = :u', ['c' => $clubId, 'u' => $userId], fetch: true);
    }

    private function role(string $clubId, string $userId): mixed
    {
        return $this->sql('SELECT role FROM club_user WHERE club_id = :c AND user_id = :u', ['c' => $clubId, 'u' => $userId], fetch: true);
    }

    private function isActive(string $clubId, string $userId): bool
    {
        return (bool) $this->sql('SELECT is_active FROM club_user WHERE club_id = :c AND user_id = :u', ['c' => $clubId, 'u' => $userId], fetch: true);
    }

    private function membershipId(string $clubId, string $userId): string
    {
        $id = $this->sql('SELECT id FROM club_user WHERE club_id = :c AND user_id = :u', ['c' => $clubId, 'u' => $userId], fetch: true);
        self::assertIsString($id);

        return $id;
    }

    /** @return array{id: string, token: string, club_email: string|null, expires_at: string} la demande VIVANTE (en cours) de l'utilisateur sur ce code */
    private function liveRequest(string $userId, string $ara): array
    {
        $row = $this->conn()->fetchAssociative('SELECT id, token, club_email, expires_at FROM club_creation_request WHERE user_id = :u AND ara = :a AND status = :s ORDER BY created_at DESC LIMIT 1', ['u' => $userId, 'a' => $ara, 's' => ClubCreationRequest::STATUS_PENDING]);
        self::assertIsArray($row, 'une demande en cours existe pour ce code');

        return ['id' => (string) $row['id'], 'token' => (string) $row['token'], 'club_email' => \is_string($row['club_email']) ? $row['club_email'] : null, 'expires_at' => (string) $row['expires_at']];
    }

    private function requestStatus(string $id): mixed
    {
        return $this->sql('SELECT status FROM club_creation_request WHERE id = :id', ['id' => $id], fetch: true);
    }

    private function auditCount(string $clubId, AuditAction $action): int
    {
        $this->scopeGucToClub($clubId);
        $count = (int) $this->conn()->fetchOne('SELECT COUNT(*) FROM audit_log WHERE club_id = :c AND action = :a', ['c' => $clubId, 'a' => $action->value]);
        $this->clearGuc();

        return $count;
    }

    private function auditDetails(string $clubId, AuditAction $action): string
    {
        $this->scopeGucToClub($clubId);
        $details = $this->conn()->fetchOne('SELECT details::text FROM audit_log WHERE club_id = :c AND action = :a', ['c' => $clubId, 'a' => $action->value]);
        $this->clearGuc();

        return \is_string($details) ? $details : '';
    }

    private function scheduledAt(string $clubId): ?DateTimeImmutable
    {
        $value = $this->conn()->fetchOne('SELECT erasure_scheduled_at FROM club WHERE id = :id', ['id' => $clubId]);

        return \is_string($value) ? new DateTimeImmutable($value) : null;
    }

    private function clubHasSeason(string $clubId): bool
    {
        $this->scopeGucToClub($clubId);
        $has = false !== $this->conn()->fetchOne('SELECT id FROM season WHERE club_id = :c LIMIT 1', ['c' => $clubId]);
        $this->clearGuc();

        return $has;
    }

    private function clubName(string $clubId): string
    {
        $name = $this->conn()->fetchOne('SELECT name FROM club WHERE id = :id', ['id' => $clubId]);
        self::assertIsString($name);

        return $name;
    }

    private function assertIsoNow(mixed $value): void
    {
        self::assertIsString($value, 'une date ISO');
        $date = new DateTimeImmutable($value);
        self::assertLessThan(120, abs(time() - $date->getTimestamp()), 'la date de la demande est maintenant');
    }

    private function jwtFor(string $userId): string
    {
        $user = $this->em()->getRepository(User::class)->find($userId);
        self::assertInstanceOf(User::class, $user);
        $jwt = self::getContainer()->get(JWTTokenManagerInterface::class);
        \assert($jwt instanceof JWTTokenManagerInterface);

        return $jwt->create($user);
    }

    /** @param array<string, mixed> $params */
    private function sql(string $statement, array $params, bool $fetch = false): mixed
    {
        if ($fetch) {
            $value = $this->conn()->fetchOne($statement, $params);

            return false === $value ? null : $value;
        }
        $this->conn()->executeStatement($statement, $params);

        return null;
    }

    private function product(): string
    {
        $product = self::getContainer()->get(ProductIdentity::class);
        \assert($product instanceof ProductIdentity);

        return $product->name();
    }

    private function base(): string
    {
        return rtrim((string) ($_SERVER['FRONTEND_BASE_URL'] ?? $_ENV['FRONTEND_BASE_URL'] ?? 'http://localhost:5173'), '/');
    }

    private function uniq(string $tag): string
    {
        return strtoupper($tag . substr(md5(uniqid('', true)), 0, 6));
    }

    private function slug(string $value): string
    {
        return (string) preg_replace('/[^a-z]/i', '', iconv('UTF-8', 'ASCII//TRANSLIT', $value) ?: 'x');
    }

    private function ip(): string
    {
        return \sprintf('10.%d.%d.%d', random_int(1, 254), random_int(0, 254), random_int(1, 254));
    }

    /** Une session Postgres indépendante de la transaction DAMA du test (mêmes paramètres). */
    private function independentSession(): PDO
    {
        $params = $this->conn()->getParams();
        $dsn = \sprintf('pgsql:host=%s;port=%s;dbname=%s', $params['host'] ?? 'postgres', $params['port'] ?? 5432, $params['dbname'] ?? 'amateo_test');

        return new PDO($dsn, (string) ($params['user'] ?? ''), (string) ($params['password'] ?? ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    private function admin(): Connection
    {
        $connection = self::getContainer()->get('doctrine')->getConnection('admin');
        \assert($connection instanceof Connection);

        return $connection;
    }

    private function conn(): Connection
    {
        return $this->em()->getConnection();
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }
}
