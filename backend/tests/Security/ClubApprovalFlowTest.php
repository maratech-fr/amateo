<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Club;
use App\Entity\ClubCreationRequest;
use App\Entity\ClubUser;
use App\Entity\EmailVerificationToken;
use App\Entity\User;
use App\Mail\EmailTemplateRenderer;
use App\Repository\ClubCreationRequestRepository;
use App\Service\ClubApprovalService;
use App\Service\EmailVerifier;
use App\Service\ProductIdentity;
use App\Tests\Double\FfbbHttpClientStub;
use App\Tests\ReadsJwtCookie;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Mime\Email;

/**
 * NR P3-4 (axe auth & memberships — décision fondateur 2026-08-05) : le premier
 * inscrivant d'un ARA inconnu ne matérialise PLUS le club à la vérification
 * d'email. Sa demande attend l'approbation du CLUB (lien au mail institutionnel
 * FFBB) ou du superadmin ; l'approbation provisionne (club + membership admin +
 * saison/plan/catégories/contraintes de base), le refus clôt. Anti-énumération :
 * 404 byte-identique pour token inconnu, malformé ou déjà consommé.
 */
#[Group('phase1')]
#[Group('integration')]
final class ClubApprovalFlowTest extends WebTestCase
{
    use ReadsJwtCookie;
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    public function testUnknownAraWaitsForApprovalWhichProvisionsTheClub(): void
    {
        // ARA connu du stub FFBB → le mail institutionnel est trouvé et stocké.
        $ara = FfbbHttpClientStub::CLUB_CODE;
        $this->deleteClubByAra($ara);
        [$jwt, $body] = $this->registerAndVerify('approve-' . uniqid() . '@t.fr', $ara, 'Club Approbation');

        self::assertSame('club_pending', $body['membershipStatus'], 'la vérification ne crée plus le club');
        self::assertNotSame('', $jwt, 'le demandeur garde son JWT (il verra l\'état de sa demande)');
        self::assertNull($this->em->getRepository(Club::class)->findOneBy(['ffbbClubCode' => $ara]), 'AUCUN club avant approbation');

        $request = $this->pendingRequestFor($body['user']['id']);
        self::assertSame(FfbbHttpClientStub::CLUB_EMAIL, $request->getClubEmail(), 'le mail institutionnel FFBB est résolu à l\'ouverture');

        // /api/me expose l'état de la demande (l'écran « demande transmise »).
        $this->client->request('GET', '/api/me', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $jwt]);
        $me = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('pending', $me['clubRequest']['status'] ?? null);

        // La page publique montre la demande au destinataire du mail du club.
        $this->client->request('GET', '/api/club-approvals/' . $request->getToken(), [], [], ['REMOTE_ADDR' => $this->ip()]);
        self::assertResponseIsSuccessful();

        // Approbation → le club EXISTE, provisionné comme un register d'antan.
        $this->client->request('POST', '/api/club-approvals/' . $request->getToken(), [], [], ['REMOTE_ADDR' => $this->ip(), 'CONTENT_TYPE' => 'application/json'], (string) json_encode(['decision' => 'approve']));
        self::assertResponseIsSuccessful();

        $club = $this->em->getRepository(Club::class)->findOneBy(['ffbbClubCode' => $ara]);
        self::assertInstanceOf(Club::class, $club);
        $membership = $this->em->getRepository(ClubUser::class)->findOneBy(['clubId' => $club->getId(), 'userId' => $body['user']['id']]);
        self::assertInstanceOf(ClubUser::class, $membership);
        self::assertTrue($membership->getIsActive(), 'le demandeur approuvé est gestionnaire actif');
        $this->scopeGucToClub($club->getId());
        $counts = $this->em->getConnection()->fetchAssociative(
            'SELECT (SELECT COUNT(*) FROM sport_category WHERE club_id = :id) AS categories, (SELECT COUNT(*) FROM "constraint" WHERE club_id = :id) AS constraints, (SELECT COUNT(*) FROM season WHERE club_id = :id) AS seasons',
            ['id' => $club->getId()],
        );
        self::assertIsArray($counts);
        self::assertGreaterThan(0, (int) $counts['categories'], 'workspace seedé : catégories');
        self::assertSame(5, (int) $counts['constraints'], 'workspace seedé : les 5 contraintes de base (P2-16)');
        self::assertSame(1, (int) $counts['seasons'], 'workspace seedé : la saison de naissance');

        // La décision est UNIQUE : le token consommé redevient un 404 anonyme.
        $this->client->request('POST', '/api/club-approvals/' . $request->getToken(), [], [], ['REMOTE_ADDR' => $this->ip(), 'CONTENT_TYPE' => 'application/json'], (string) json_encode(['decision' => 'approve']));
        self::assertResponseStatusCodeSame(404);

        $this->deleteClubByAra($ara);
    }

    public function testRefusalClosesWithoutCreatingAnything(): void
    {
        $ara = 'REF' . strtoupper(substr(md5(uniqid()), 0, 7));
        [, $body] = $this->registerAndVerify('refuse-' . uniqid() . '@t.fr', $ara, 'Club Refusé');
        $request = $this->pendingRequestFor($body['user']['id']);
        // ARA inconnu du stub FFBB → pas de mail institutionnel → file superadmin.
        self::assertNull($request->getClubEmail());

        $this->client->request('POST', '/api/club-approvals/' . $request->getToken(), [], [], ['REMOTE_ADDR' => $this->ip(), 'CONTENT_TYPE' => 'application/json'], (string) json_encode(['decision' => 'refuse']));
        self::assertResponseIsSuccessful();

        self::assertNull($this->em->getRepository(Club::class)->findOneBy(['ffbbClubCode' => $ara]), 'refus → aucun club');
        $this->em->clear();
        self::assertSame(ClubCreationRequest::STATUS_REFUSED, $this->em->getRepository(ClubCreationRequest::class)->find($request->getId())?->getStatus());
    }

    public function testUnknownAndMalformedTokensAreByteIdentical(): void
    {
        $this->client->request('GET', '/api/club-approvals/' . str_repeat('a', 64), [], [], ['REMOTE_ADDR' => $this->ip()]);
        $unknown = (string) $this->client->getResponse()->getContent();
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/api/club-approvals/not-a-token', [], [], ['REMOTE_ADDR' => $this->ip()]);
        self::assertResponseStatusCodeSame(404);
        self::assertSame($unknown, (string) $this->client->getResponse()->getContent(), 'inconnu et malformé : 404 BYTE-IDENTIQUE (anti-énumération)');
    }

    public function testConcurrentRequestOnTheSameAraBecomesAPendingMembership(): void
    {
        $ara = 'CCR' . strtoupper(substr(md5(uniqid()), 0, 7));
        [, $first] = $this->registerAndVerify('first-' . uniqid() . '@t.fr', $ara, 'Club Course');
        [, $second] = $this->registerAndVerify('second-' . uniqid() . '@t.fr', $ara, 'Club Course');

        $winner = $this->pendingRequestFor($first['user']['id']);
        $this->client->request('POST', '/api/club-approvals/' . $winner->getToken(), [], [], ['REMOTE_ADDR' => $this->ip(), 'CONTENT_TYPE' => 'application/json'], (string) json_encode(['decision' => 'approve']));
        self::assertResponseIsSuccessful();

        $club = $this->em->getRepository(Club::class)->findOneBy(['ffbbClubCode' => $ara]);
        self::assertInstanceOf(Club::class, $club);
        // Le second demandeur : jamais un 2e club — une adhésion pending, que le
        // premier gestionnaire approuvera (flux existant).
        $sibling = $this->em->getRepository(ClubUser::class)->findOneBy(['clubId' => $club->getId(), 'userId' => $second['user']['id']]);
        self::assertInstanceOf(ClubUser::class, $sibling);
        self::assertFalse($sibling->getIsActive());
        $this->em->clear();
        self::assertNotSame(ClubCreationRequest::STATUS_PENDING, $this->em->getRepository(ClubCreationRequest::class)->findOneBy(['userId' => $second['user']['id']])?->getStatus(), 'la demande concurrente est close');

        $this->deleteClubByAra($ara);
    }

    /**
     * Le mail au demandeur dit ce que l'approbation a VRAIMENT fait : club créé, club sans
     * gestionnaire repris, ou demande devenue une adhésion transmise aux gestionnaires en place.
     */
    public function testOutcomeMailsSayCreatedRepriseOrPending(): void
    {
        $product = $this->product();

        // Créé — ARA inconnu, le demandeur devient gestionnaire d'un club neuf.
        $ara = 'OUT' . strtoupper(substr(md5(uniqid()), 0, 7));
        [, $created] = $this->registerAndVerify('created-' . uniqid() . '@t.fr', $ara, 'Club Neuf');
        $this->decide($this->pendingRequestFor($created['user']['id'])->getToken(), 'approve');
        self::assertSame(['status' => 'approved'], $this->responseJson());
        $mails = $this->mailsTo($created['user']['email']);
        self::assertCount(1, $mails);
        self::assertStringContainsString(\sprintf('l\'espace %s de Club Neuf est créé et vous en êtes gestionnaire', $product), (string) $mails[0]->getTextBody());

        // Repris — club RÉEL (adresse officielle connue du stub FFBB) dont le dernier gestionnaire
        // est parti ; un Membre reste. La demande de Nadia part à l'adresse officielle en « reprendre ».
        $stubAra = FfbbHttpClientStub::CLUB_CODE;
        $this->deleteClubByAra($stubAra);
        [$marcJwt, $marc] = $this->registerAndVerify('marc-' . uniqid() . '@t.fr', $stubAra, 'Club Stub');
        $this->decide($this->pendingRequestFor($marc['user']['id'])->getToken(), 'approve');
        self::assertResponseIsSuccessful();
        $club = $this->em->getRepository(Club::class)->findOneBy(['ffbbClubCode' => $stubAra]);
        self::assertInstanceOf(Club::class, $club);
        $clubId = $club->getId();
        $clubName = $club->getName();
        $paulId = $this->insertActiveMember($clubId, 'member');
        $this->client->getCookieJar()->clear();
        $this->client->request('DELETE', '/api/me', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $marcJwt, 'REMOTE_ADDR' => $this->ip()], (string) json_encode(['password' => 'Password123!']));
        self::assertResponseIsSuccessful();

        $nadiaEmail = 'nadia-' . uniqid() . '@t.fr';
        [, $nadia] = $this->registerAndVerify($nadiaEmail, $stubAra, 'ignored', 'Nadia', 'Reprise');
        self::assertSame('club_pending', $nadia['membershipStatus'], 'club sans GESTIONNAIRE (un Membre reste) → demande à l\'adresse officielle');
        $request = $this->pendingRequestFor($nadia['user']['id']);
        $official = $this->mailsTo(FfbbHttpClientStub::CLUB_EMAIL);
        self::assertCount(1, $official);
        self::assertSame(\sprintf('Nadia Reprise demande à reprendre l\'espace %s de %s', $product, $clubName), $official[0]->getSubject());
        $link = 'http://localhost:5173/club-approval/' . $request->getToken();
        self::assertSame(
            \sprintf("Bonjour,\n\nNadia Reprise (%s) demande à reprendre la gestion de l'espace %s du club %s (code FFBB %s), qui n'a plus de gestionnaire.\n\nSi cette personne est bien un gestionnaire de votre club, approuvez sa demande :\n%s\n\nSinon, refusez-la. Ce lien expire le %s.\n\n%s", $nadiaEmail, $product, $clubName, $stubAra, $link, $request->getExpiresAt()->format('d/m/Y'), $product),
            (string) $official[0]->getTextBody(),
        );
        self::assertSame('Examiner la demande', $official[0]->getHeaders()->getHeaderBody(EmailTemplateRenderer::CTA_LABEL_HEADER));

        $this->decide($request->getToken(), 'approve');
        self::assertSame(['status' => 'approved'], $this->responseJson());
        $mails = $this->mailsTo($nadiaEmail);
        self::assertCount(1, $mails);
        self::assertSame(\sprintf("Bonjour,\n\nVotre demande a été approuvée : vous reprenez l'espace %s de %s et vous en êtes gestionnaire.\n\nConnectez-vous :\nhttp://localhost:5173/login\n\n%s", $product, $clubName, $product), (string) $mails[0]->getTextBody());
        self::assertSame('Me connecter', $mails[0]->getHeaders()->getHeaderBody(EmailTemplateRenderer::CTA_LABEL_HEADER));
        $paul = $this->em->getRepository(ClubUser::class)->findOneBy(['clubId' => $clubId, 'userId' => $paulId]);
        self::assertInstanceOf(ClubUser::class, $paul);
        self::assertSame('member', $paul->getRole(), 'le Membre restant reste Membre');
        $this->deleteClubByAra($stubAra);

        // Transmise — club peuplé, une demande extérieure devient une adhésion pending.
        [, $lea, $peopledName] = $this->populatedClubWithOutsiderRequest();
        $this->decide($lea['token'], 'approve');
        self::assertResponseIsSuccessful();
        $mails = $this->mailsTo($lea['email']);
        self::assertCount(1, $mails);
        self::assertSame(\sprintf("Bonjour,\n\nLe club %s existe déjà sur %s : votre demande d'adhésion est transmise à ses gestionnaires, qui l'accepteront ou la refuseront.\n\n%s", $peopledName, $product, $product), (string) $mails[0]->getTextBody());
        self::assertFalse($mails[0]->getHeaders()->has(EmailTemplateRenderer::CTA_URL_HEADER), 'rien à cliquer : EXEMPT');
    }

    /** Un refus par le club = UN seul mail au demandeur, qui porte l'échéance du compte quand il devient orphelin. */
    public function testRefusalSendsExactlyOneMail(): void
    {
        $ara = 'RFO' . strtoupper(substr(md5(uniqid()), 0, 7));
        $email = 'refused-' . uniqid() . '@t.fr';
        [, $body] = $this->registerAndVerify($email, $ara, 'Club Refus');
        $this->decide($this->pendingRequestFor($body['user']['id'])->getToken(), 'refuse');
        self::assertSame(['status' => 'refused'], $this->responseJson());

        $mails = $this->mailsTo($email);
        self::assertCount(1, $mails, 'UN seul mail : le refus porte le préavis orphelin, jamais deux mails');
        $text = (string) $mails[0]->getTextBody();
        self::assertStringContainsString('Votre demande de création de l\'espace Club Refus a été refusée par le club.', $text);
        self::assertMatchesRegularExpression('~supprimé le \d{2}/\d{2}/\d{4}~', $text, 'le demandeur orphelin lit l\'échéance de son compte');
    }

    /**
     * Deux clics sur le même lien d'approbation : le premier gagne ; le second — qui avait lu la
     * demande AVANT que le premier ne commite — relit sous verrou, ne touche à rien, et le lien
     * répond 404 byte-identique. Simulé : la demande en mémoire est « pending », la base dit « approved ».
     */
    public function testDoubleApproveSameLinkKeepsWinnerAndAnswers404(): void
    {
        $ara = 'DBA' . strtoupper(substr(md5(uniqid()), 0, 7));
        $email = 'double-' . uniqid() . '@t.fr';
        [, $body] = $this->registerAndVerify($email, $ara, 'Club Double');
        $request = $this->pendingRequestFor($body['user']['id']);
        $token = $request->getToken();
        $requestId = $request->getId();

        $this->decide($token, 'approve');
        self::assertSame(['status' => 'approved'], $this->responseJson(), 'le premier clic gagne');

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $stale = $em->find(ClubCreationRequest::class, $requestId);
        self::assertInstanceOf(ClubCreationRequest::class, $stale);
        $this->makeStale($em, $stale, ClubCreationRequest::STATUS_PENDING);

        // Le transport du container courant porte DÉJÀ le mail du gagnant (« … est prêt ») :
        // on mesure ce que le perdant AJOUTE.
        $mailsBeforeLoser = \count($this->mailsTo($email));
        $outcome = $this->approvalService()->approve($stale);
        self::assertSame('NoOp', $outcome->name, 'le perdant relit la demande sous verrou : rien à faire');
        self::assertSame(1, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM club WHERE ffbb_club_code = :a', ['a' => $ara]), 'un seul club');
        self::assertSame(1, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM club_user WHERE user_id = :u', ['u' => $body['user']['id']]), 'une seule adhésion pour le gagnant');
        self::assertSame(ClubCreationRequest::STATUS_APPROVED, $em->getConnection()->fetchOne('SELECT status FROM club_creation_request WHERE id = :id', ['id' => $requestId]));
        self::assertCount($mailsBeforeLoser, $this->mailsTo($email), 'le perdant n\'envoie aucun mail');

        $this->client->request('POST', '/api/club-approvals/' . str_repeat('b', 64), [], [], ['REMOTE_ADDR' => $this->ip(), 'CONTENT_TYPE' => 'application/json'], (string) json_encode(['decision' => 'approve']));
        $unknown = (string) $this->client->getResponse()->getContent();
        $this->decide($token, 'approve');
        self::assertResponseStatusCodeSame(404);
        self::assertSame($unknown, (string) $this->client->getResponse()->getContent(), '404 byte-identique à un lien inconnu');
        $this->deleteClubByAra($ara);
    }

    /** Une demande annulée (compte effacé, demande retirée) ne s'approuve ni ne se refuse plus : aucune écriture, aucun mail. */
    public function testApproveAfterCancelIsANoOp(): void
    {
        $ara = 'CAN' . strtoupper(substr(md5(uniqid()), 0, 7));
        $email = 'cancel-' . uniqid() . '@t.fr';
        [, $body] = $this->registerAndVerify($email, $ara, 'Club Annule');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $request = $em->getRepository(ClubCreationRequest::class)->findOneBy(['userId' => $body['user']['id']]);
        self::assertInstanceOf(ClubCreationRequest::class, $request);
        $em->getConnection()->executeStatement('UPDATE club_creation_request SET status = :s WHERE id = :id', ['s' => ClubCreationRequest::STATUS_CANCELLED, 'id' => $request->getId()]);

        self::assertSame('NoOp', $this->approvalService()->approve($request)->name);
        self::assertSame(0, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM club WHERE ffbb_club_code = :a', ['a' => $ara]), 'aucun club né d\'une demande annulée');

        $this->approvalService()->refuse($request);
        self::assertSame(ClubCreationRequest::STATUS_CANCELLED, $em->getConnection()->fetchOne('SELECT status FROM club_creation_request WHERE id = :id', ['id' => $request->getId()]), 'le refus ne réécrit pas une demande annulée');
        self::assertSame([], $this->mailsTo($email), 'aucun mail sur une demande annulée');
    }

    /** Un demandeur dont le compte a été anonymisé n'est jamais approuvé : sa demande est annulée, le lien répond 404. */
    public function testAnonymizedRequesterIsNeverApproved(): void
    {
        $ara = 'ANO' . strtoupper(substr(md5(uniqid()), 0, 7));
        [, $body] = $this->registerAndVerify('anon-' . uniqid() . '@t.fr', $ara, 'Club Anonyme');
        $request = $this->pendingRequestFor($body['user']['id']);
        $this->em->getConnection()->executeStatement('UPDATE app_user SET anonymized_at = NOW() WHERE id = :id', ['id' => $body['user']['id']]);

        $this->client->request('POST', '/api/club-approvals/' . str_repeat('c', 64), [], [], ['REMOTE_ADDR' => $this->ip(), 'CONTENT_TYPE' => 'application/json'], (string) json_encode(['decision' => 'approve']));
        $unknown = (string) $this->client->getResponse()->getContent();
        $this->decide($request->getToken(), 'approve');
        self::assertResponseStatusCodeSame(404);
        self::assertSame($unknown, (string) $this->client->getResponse()->getContent(), 'demandeur anonymisé → 404 byte-identique');
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM club WHERE ffbb_club_code = :a', ['a' => $ara]), 'aucun club pour un compte effacé');
        self::assertSame(ClubCreationRequest::STATUS_CANCELLED, $this->em->getConnection()->fetchOne('SELECT status FROM club_creation_request WHERE id = :id', ['id' => $request->getId()]));
    }

    /**
     * En PRODUCTION (`debug: false`) l'anti-énumération tient toujours : lien inconnu, malformé et
     * consommé (demandeur anonymisé) répondent le MÊME 404, octet pour octet — le relais des refus
     * 4xx en prod (`ErrorDetailSurvivesProdModeTest`) ne doit jamais créer d'oracle sur une page à
     * jeton. Variante prod de {@see testUnknownAndMalformedTokensAreByteIdentical} et
     * {@see testAnonymizedRequesterIsNeverApproved} (qui tournent en debug).
     */
    public function testUnknownAndConsumedTokensStayByteIdenticalWhenDebugIsOff(): void
    {
        self::ensureKernelShutdown();
        $this->client = self::createClient(['debug' => false]);
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $ara = 'PRD' . strtoupper(substr(md5(uniqid()), 0, 7));
        [, $body] = $this->registerAndVerify('prod-' . uniqid() . '@t.fr', $ara, 'Club Prod');
        $consumed = $this->pendingRequestFor($body['user']['id'])->getToken();
        self::getContainer()->get(EntityManagerInterface::class)->getConnection()->executeStatement('UPDATE app_user SET anonymized_at = NOW() WHERE id = :id', ['id' => $body['user']['id']]);

        $responses = [];
        foreach (['inconnu' => str_repeat('e', 64), 'malformé' => 'not-a-token', 'consommé' => $consumed] as $case => $token) {
            // POST d'abord : sur un demandeur anonymisé, c'est la décision qui clôt la demande.
            $this->decide($token, 'approve');
            self::assertResponseStatusCodeSame(404, $case);
            $responses['POST ' . $case] = (string) $this->client->getResponse()->getContent();

            $this->client->getCookieJar()->clear();
            $this->client->request('GET', '/api/club-approvals/' . $token, [], [], ['REMOTE_ADDR' => $this->ip(), 'HTTP_ACCEPT' => 'application/json']);
            self::assertResponseStatusCodeSame(404, $case);
            $responses['GET ' . $case] = (string) $this->client->getResponse()->getContent();
        }

        self::assertCount(1, array_unique($responses), 'inconnu, malformé et consommé : 404 BYTE-IDENTIQUE en prod (anti-énumération) — ' . json_encode($responses));
    }

    /** Une approbation qui n'a fait qu'ouvrir une adhésion (club déjà géré) ne répond pas « approved » mais « superseded », et prévient les gestionnaires. */
    public function testSupersededDecisionDoesNotAnswerApproved(): void
    {
        [$managerEmail, $lea, $clubName, $clubId] = $this->populatedClubWithOutsiderRequest();

        $this->decide($lea['token'], 'approve');
        self::assertResponseIsSuccessful();
        self::assertSame(['status' => 'superseded'], $this->responseJson(), 'la page publique ne dit pas « approuvée » quand le club existait déjà');
        $pending = $this->em->getRepository(ClubUser::class)->findOneBy(['clubId' => $clubId, 'userId' => $lea['userId']]);
        self::assertInstanceOf(ClubUser::class, $pending);
        self::assertFalse($pending->getIsActive(), 'une adhésion EN ATTENTE, au choix des gestionnaires');
        $notice = $this->mailsTo($managerEmail);
        self::assertCount(1, $notice, 'les gestionnaires en place sont prévenus');
        self::assertSame(\sprintf('Demande d\'adhésion — Léa Dehors souhaite rejoindre %s', $clubName), $notice[0]->getSubject());
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * Un club géré (Marc gestionnaire, né par le vrai rail) + une demande de club VIVANTE d'une
     * personne extérieure (Léa) sur le même code — le cas d'une demande ouverte avant que le club
     * ne retrouve un gestionnaire.
     *
     * @return array{0: string, 1: array{userId: string, email: string, token: string}, 2: string, 3: string} [email du gestionnaire, Léa, nom du club, id du club]
     */
    private function populatedClubWithOutsiderRequest(): array
    {
        $ara = 'POP' . strtoupper(substr(md5(uniqid()), 0, 7));
        $managerEmail = 'manager-' . uniqid() . '@t.fr';
        [, $manager] = $this->registerAndVerify($managerEmail, $ara, 'Club Peuple');
        $this->decide($this->pendingRequestFor($manager['user']['id'])->getToken(), 'approve');
        self::assertResponseIsSuccessful();
        $club = $this->em->getRepository(Club::class)->findOneBy(['ffbbClubCode' => $ara]);
        self::assertInstanceOf(Club::class, $club);

        $leaEmail = 'lea-' . uniqid() . '@t.fr';
        $lea = new User;
        $lea->setEmail($leaEmail);
        $lea->setFirstName('Léa');
        $lea->setLastName('Dehors');
        $lea->setPasswordHash('x');
        $lea->setEmailVerifiedAt(new DateTimeImmutable);
        $this->em->persist($lea);
        $request = new ClubCreationRequest;
        $request->setUserId($lea->getId());
        $request->setAra($ara);
        $request->setClubName('Club Peuple');
        $this->em->persist($request);
        $this->em->flush();

        return [$managerEmail, ['userId' => $lea->getId(), 'email' => $leaEmail, 'token' => $request->getToken()], $club->getName(), $club->getId()];
    }

    private function insertActiveMember(string $clubId, string $role): string
    {
        $user = new User;
        $user->setEmail('member-' . uniqid() . '@t.fr');
        $user->setFirstName('Paul');
        $user->setLastName('Membre');
        $user->setPasswordHash('x');
        $user->setEmailVerifiedAt(new DateTimeImmutable);
        $this->em->persist($user);
        $this->scopeGucToClub($clubId);
        $membership = new ClubUser;
        $membership->setClubId($clubId);
        $membership->setUserId($user->getId());
        $membership->setRole($role);
        $membership->setIsActive(true);
        $this->em->persist($membership);
        $this->em->flush();
        $this->clearGuc();

        return $user->getId();
    }

    /** La demande en mémoire porte `$status` alors que la base dit autre chose (lue avant le commit du concurrent). */
    private function makeStale(EntityManagerInterface $em, ClubCreationRequest $request, string $status): void
    {
        $request->setStatus($status);
        $em->getUnitOfWork()->setOriginalEntityProperty(spl_object_id($request), 'status', $status);
    }

    private function decide(string $token, string $decision): void
    {
        $this->client->getCookieJar()->clear();
        $this->client->request('POST', '/api/club-approvals/' . $token, [], [], ['REMOTE_ADDR' => $this->ip(), 'CONTENT_TYPE' => 'application/json'], (string) json_encode(['decision' => $decision]));
    }

    /** @return array<string, mixed> */
    private function responseJson(): array
    {
        return (array) json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    private function approvalService(): ClubApprovalService
    {
        $service = self::getContainer()->get(ClubApprovalService::class);
        \assert($service instanceof ClubApprovalService);

        return $service;
    }

    /** @return list<Email> les mails enfilés (container courant) adressés à `$address` */
    private function mailsTo(string $address): array
    {
        $transport = self::getContainer()->get('messenger.transport.mailer_in_memory');
        \assert($transport instanceof InMemoryTransport);
        $out = [];
        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if (!method_exists($message, 'getMessage') || !$message->getMessage() instanceof Email) {
                continue;
            }
            foreach ($message->getMessage()->getTo() as $to) {
                if (strtolower($to->getAddress()) === strtolower($address)) {
                    $out[] = $message->getMessage();
                }
            }
        }

        return $out;
    }

    private function product(): string
    {
        $product = self::getContainer()->get(ProductIdentity::class);
        \assert($product instanceof ProductIdentity);

        return $product->name();
    }

    /**
     * Register + verify SANS le relais dev — ces tests couvrent précisément
     * l'entre-deux que le trait VerifiesRegistration court-circuite.
     *
     * @return array{0: string, 1: array{membershipStatus: string, user: array{id: string}}}
     */
    private function registerAndVerify(string $email, string $ara, string $clubName, string $firstName = 'Ana', string $lastName = 'Approb'): array
    {
        $this->client->getCookieJar()->clear();
        $this->client->request('POST', '/api/register', [], [], ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $this->ip()], (string) json_encode([
            'email' => $email, 'password' => 'Password123!', 'firstName' => $firstName, 'lastName' => $lastName,
            'ara' => $ara, 'club_name' => $clubName, 'consent' => true,
        ]));
        self::assertResponseStatusCodeSame(202);

        // EM du CONTAINER COURANT (le kernel reboote entre deux requêtes — le EM de
        // setUp ne flushe pas ce que l'EmailVerifier du dernier kernel persiste).
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $user = $em->getRepository(User::class)->findOneBy(['email' => strtolower($email)]);
        \assert($user instanceof User);
        $pending = $em->getRepository(EmailVerificationToken::class)->findOneBy(['user' => $user]);
        \assert($pending instanceof EmailVerificationToken);
        $raw = $container->get(EmailVerifier::class)->generateToken($user, $pending->getAra(), $pending->getClubName());
        $em->flush();

        $this->client->request('POST', '/api/register/verify', [], [], ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $this->ip()], (string) json_encode(['token' => $raw]));
        self::assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        \assert(\is_array($body));

        return [$this->jwtFromCookie($this->client), $body];
    }

    private function pendingRequestFor(string $userId): ClubCreationRequest
    {
        $request = self::getContainer()->get(ClubCreationRequestRepository::class)->findPendingByUser($userId);
        \assert($request instanceof ClubCreationRequest);

        return $request;
    }

    /** Le stub FFBB partage son ARA entre suites : nettoyage avant/après. */
    private function deleteClubByAra(string $ara): void
    {
        $club = $this->em->getRepository(Club::class)->findOneBy(['ffbbClubCode' => $ara]);
        if ($club instanceof Club) {
            $this->em->getConnection()->executeStatement('DELETE FROM club WHERE id = :id', ['id' => $club->getId()]);
        }
    }

    private function ip(): string
    {
        return \sprintf('10.%d.%d.%d', random_int(1, 254), random_int(0, 254), random_int(1, 254));
    }
}
