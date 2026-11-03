<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Club;
use App\Entity\ClubCreationRequest;
use App\Entity\ClubInvitation;
use App\Entity\ClubUser;
use App\Entity\EmailVerificationToken;
use App\Entity\User;
use App\Enum\AuditAction;
use App\Enum\ClubRole;
use App\Mail\ClubBusinessMail;
use App\Mail\ClubMailMetadata;
use App\Mail\EmailTemplateRenderer;
use App\Repository\ClubMailboxMessageRepository;
use App\Service\ClubProvisioner;
use App\Service\EmailVerifier;
use App\Service\MembershipRequestNotifier;
use App\Service\ProductIdentity;
use App\Service\TenantConnectionContext;
use App\Tests\AssertsActionMailCta;
use App\Tests\StartsFreshBrowserSession;
use App\Tests\TenantGucTrait;
use App\Tests\VerifiesRegistration;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
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

/**
 * NR d'axe *auth & memberships* (chantier « Demandes d'adhésion et rôle Membre », PR 1) —
 * DEUX promesses que le fondateur a fermées :
 *
 *  1. **Les gestionnaires sont prévenus d'une demande d'adhésion** : un mail par demande aux
 *     gestionnaires ACTIFS du club (jamais à un Membre, jamais à un désactivé), un mail GROUPÉ
 *     quand plusieurs demandes naissent dans la même transaction, un plafond par club (au-delà :
 *     UNE synthèse, puis silence), un double clic = un mail, aucun destinataire = aucun budget
 *     consommé, le nom du demandeur n'injecte jamais de lien. Le demandeur apprend l'issue
 *     (accepté avec son rôle / refusé en UN seul mail).
 *  2. **Un club sans gestionnaire ferme** : le dernier GESTIONNAIRE actif effacé (geste ou
 *     inactivité) programme la fermeture même si des Membres restent ; ces Membres sont prévenus
 *     à J0 et J-7 AVANT l'adresse officielle (et même sans elle) ; la purge est RETENUE tant que
 *     le rappel J-7 n'est pas réellement parti (reprogrammée à +7 j), et revalide sous le verrou
 *     d'approbation ; une invitation gestionnaire acceptée annule la fermeture ; `/api/me` expose
 *     `club.closingOn` tant qu'aucun gestionnaire n'existe.
 *
 * Décor par l'API (inscription → vérification → approbation) ; insertions directes seulement pour
 * les membres témoins (le plafond de test est 2/jour/club : passer par la vérification
 * consommerait le budget que le test mesure). Mails lus sur le transport mémoire du container
 * COURANT (le kernel reboote à chaque requête : on lit juste après le geste).
 *
 * Plafonds de test (`when@test`) : `membership_request_notify` 2/jour/club,
 * `membership_request_digest` 1/jour/club. Redis : jamais FLUSHALL — chaque test a son club
 * (clé neuve), aucun reset nécessaire.
 */
#[Group('phase1')]
#[Group('integration')]
final class MembershipRequestNotificationTest extends WebTestCase
{
    use AssertsActionMailCta;
    use StartsFreshBrowserSession;
    use TenantGucTrait;
    use VerifiesRegistration;

    private const string PASSWORD = 'Password123!';

    private KernelBrowser $client;

    // ---------------------------------------------------------------------------------------
    // Promesse 1 — les gestionnaires sont prévenus
    // ---------------------------------------------------------------------------------------

    /** Une demande d'adhésion = un mail à CHAQUE gestionnaire actif (owner historique compris), personne d'autre. */
    public function testVerifyMailsEachActiveManagerAndNobodyElse(): void
    {
        $club = $this->managedClub('NTFA');
        $sophie = $this->insertMember($club['clubId'], 'Sophie', 'admin');
        $olivier = $this->insertMember($club['clubId'], 'Olivier', 'owner');
        $paul = $this->insertMember($club['clubId'], 'Paul', 'member');
        $didier = $this->insertMember($club['clubId'], 'Didier', 'admin', active: false, deactivated: true);

        $lea = $this->joinerVerifies($club['ara'], 'Léa', 'Martin');
        self::assertSame('pending', $lea['body']['membershipStatus'] ?? null);

        $product = $this->product();
        $clubName = $this->clubName($club['clubId']);
        foreach ([$club['email'], $sophie['email'], $olivier['email']] as $manager) {
            $mails = $this->mailsTo($lea['mails'], $manager);
            self::assertCount(1, $mails, \sprintf('le gestionnaire actif %s reçoit UN mail', $manager));
            $mail = $mails[0];
            self::assertSame(\sprintf('Demande d\'adhésion — Léa Martin souhaite rejoindre %s', $clubName), $mail->getSubject());
            self::assertSame(
                "Bonjour,\n\nLéa Martin ({$lea['email']}) demande à rejoindre l'espace {$product} du club {$clubName}.\n\n"
                . "Connectez-vous pour accepter ou refuser cette demande dans l'écran Club, section « Demandes d'adhésion » :\n"
                . "{$this->base()}/club\n\nSi vous ne connaissez pas cette personne, refusez la demande. Ce message ne vous demande rien d'autre que d'ouvrir l'application.\n\n{$product}",
                (string) $mail->getTextBody(),
            );
            $this->assertCtaHeadersPointToBodyLink($mail, $this->base() . '/club', 'Voir les demandes');
            self::assertTrue(ClubBusinessMail::isClubBusiness($mail), 'mail métier du club (boîte aux lettres sous horloge simulée)');
            self::assertSame($club['clubId'], $mail->getHeaders()->getHeaderBody(ClubMailMetadata::CLUB_ID_HEADER));
            self::assertCount(1, $mail->getTo(), 'un mail par gestionnaire — jamais une liste d\'adresses exposée');
            self::assertSame([], $mail->getCc());
            self::assertSame([], $mail->getBcc());
        }
        self::assertSame([], $this->mailsTo($lea['mails'], $paul['email']), 'un Membre n\'est jamais prévenu');
        self::assertSame([], $this->mailsTo($lea['mails'], $didier['email']), 'un gestionnaire désactivé n\'est jamais prévenu');
        self::assertSame([], $this->mailsTo($lea['mails'], $lea['email']), 'le demandeur ne reçoit rien à la vérification');
    }

    /** Plusieurs adhésions nées dans la MÊME transaction (sœurs à l'approbation) → UN mail groupé qui les liste. */
    public function testThreeSiblingApprovalsSendOneGroupedMail(): void
    {
        $ara = $this->uniq('SIB');
        $winner = $this->registerAndVerifyPending($ara, 'Wina', 'Premier', 'Club Soeurs ' . $ara);
        $siblings = [];
        foreach ([['Anna', 'Un'], ['Bruno', 'Deux'], ['Chloé', 'Trois']] as [$first, $last]) {
            $siblings[] = $this->registerAndVerifyPending($ara, $first, $last, 'Club Soeurs ' . $ara);
        }

        $request = $this->em()->getRepository(ClubCreationRequest::class)->findOneBy(['userId' => $winner['userId'], 'status' => ClubCreationRequest::STATUS_PENDING]);
        self::assertInstanceOf(ClubCreationRequest::class, $request);
        $this->publicDecision($request->getToken(), 'approve');
        self::assertResponseIsSuccessful();
        $sent = $this->sentEmails();

        $clubId = $this->clubIdByAra($ara);
        $clubName = $this->clubName($clubId);
        $grouped = array_values(array_filter(
            $this->mailsTo($sent, $winner['email']),
            static fn (Email $m): bool => str_contains((string) $m->getSubject(), 'demandes d\'adhésion'),
        ));
        self::assertCount(1, $grouped, 'UN mail groupé pour les trois sœurs, pas trois mails');
        self::assertSame(\sprintf('3 demandes d\'adhésion — %s', $clubName), $grouped[0]->getSubject());
        $body = (string) $grouped[0]->getTextBody();
        self::assertStringContainsString(\sprintf('3 personnes demandent à rejoindre l\'espace %s du club %s :', $this->product(), $clubName), $body);
        foreach ([['Anna Un', 0], ['Bruno Deux', 1], ['Chloé Trois', 2]] as [$name, $i]) {
            self::assertStringContainsString(\sprintf('- %s (%s)', $name, $siblings[$i]['email']), $body);
        }
        $this->assertCtaHeadersPointToBodyLink($grouped[0], $this->base() . '/club', 'Voir les demandes');
    }

    /** Aucun gestionnaire actif à prévenir → aucun mail ET aucun budget consommé (le budget reste entier pour la suite). */
    public function testNoManagerMeansNoMailAndNoBudgetSpent(): void
    {
        $club = $this->bareClub('NOMGR');
        $paul = $this->insertMember($club['clubId'], 'Paul', 'member');
        $lea = $this->insertMember($club['clubId'], 'Léa', 'member', active: false);

        $notifier = self::getContainer()->get(MembershipRequestNotifier::class);
        \assert($notifier instanceof MembershipRequestNotifier);
        // Plafond de test = 2 : deux appels sans destinataire épuiseraient le budget s'ils le consommaient.
        $notifier->notifyManagers($club['clubId'], [$lea['userId']]);
        $notifier->notifyManagers($club['clubId'], [$lea['userId']]);
        self::assertSame([], $this->sentEmails(), 'aucun gestionnaire → aucun mail (le Membre n\'est pas un repli)');

        $marc = $this->insertMember($club['clubId'], 'Marc', 'admin');
        $notifier->notifyManagers($club['clubId'], [$lea['userId']]);
        $mails = $this->mailsTo($this->sentEmails(), $marc['email']);
        self::assertCount(1, $mails);
        self::assertStringStartsWith('Demande d\'adhésion — ', (string) $mails[0]->getSubject(), 'mail INDIVIDUEL : les appels sans destinataire n\'ont rien consommé (sinon synthèse ou silence)');
        self::assertSame([], $this->mailsTo($this->sentEmails(), $paul['email']));
    }

    /** Le double clic sur le lien de vérification ne produit qu'UN mail aux gestionnaires. */
    public function testDoubleVerifySendsASingleMail(): void
    {
        $club = $this->managedClub('DBLV');
        $email = $this->registerOnly($club['ara'], 'Léa', 'Double');
        $raw = $this->mintVerificationToken($email);

        $this->postVerify($raw);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->mailsTo($this->sentEmails(), $club['email']), 'premier clic : un mail');

        $this->postVerify($raw);
        self::assertSame([], $this->mailsTo($this->sentEmails(), $club['email']), 'second clic : aucun mail de plus');
        self::assertSame(1, $this->pendingCount($club['clubId'], $this->userIdOf($email)), 'une seule adhésion pending');
    }

    /** La réponse de la vérification ne trahit pas si un mail est parti (mail individuel vs plafond atteint). */
    public function testResponsesDoNotRevealWhetherAMailWasSent(): void
    {
        $club = $this->managedClub('ORAC');
        $first = $this->joinerVerifies($club['ara'], 'Un', 'Premier');
        $firstStatus = $this->client->getResponse()->getStatusCode();
        self::assertCount(1, $this->mailsTo($first['mails'], $club['email']), 'témoin : le premier a déclenché un mail');

        $this->joinerVerifies($club['ara'], 'Deux', 'Second');
        $this->joinerVerifies($club['ara'], 'Trois', 'Synthese');
        $silent = $this->joinerVerifies($club['ara'], 'Quatre', 'Silence');
        self::assertSame([], $this->mailsTo($silent['mails'], $club['email']), 'témoin : le quatrième n\'a déclenché aucun mail');

        self::assertSame($firstStatus, $this->client->getResponse()->getStatusCode(), 'même code HTTP');
        self::assertSame(array_keys($first['body']), array_keys($silent['body']), 'mêmes clés de réponse');
        self::assertSame(array_keys($first['body']['user']), array_keys($silent['body']['user']));
        self::assertSame($first['body']['membershipStatus'], $silent['body']['membershipStatus']);
    }

    /** Au-delà du plafond quotidien du club : UNE synthèse, puis silence (falsifié : sans le plafond, mails individuels sans fin). */
    public function testBeyondTheDailyCapOneDigestThenSilence(): void
    {
        $club = $this->managedClub('CAPD');
        $clubName = $this->clubName($club['clubId']);
        $product = $this->product();

        foreach (['Un', 'Deux'] as $first) {
            $mails = $this->mailsTo($this->joinerVerifies($club['ara'], $first, 'Cap')['mails'], $club['email']);
            self::assertCount(1, $mails);
            self::assertSame(\sprintf('Demande d\'adhésion — %s Cap souhaite rejoindre %s', $first, $clubName), $mails[0]->getSubject(), 'sous le plafond : mail individuel');
        }

        $digest = $this->mailsTo($this->joinerVerifies($club['ara'], 'Trois', 'Cap')['mails'], $club['email']);
        self::assertCount(1, $digest, 'plafond atteint : UNE synthèse');
        self::assertSame(\sprintf('Plusieurs demandes d\'adhésion aujourd\'hui — %s', $clubName), $digest[0]->getSubject());
        self::assertSame(
            "Bonjour,\n\nPlusieurs personnes ont demandé à rejoindre l'espace {$product} du club {$clubName} aujourd'hui. Vous ne recevrez plus de message individuel avant demain.\n\n"
            . "Retrouvez toutes les demandes dans l'écran Club, section « Demandes d'adhésion » :\n{$this->base()}/club\n\n{$product}",
            (string) $digest[0]->getTextBody(),
        );
        $this->assertCtaHeadersPointToBodyLink($digest[0], $this->base() . '/club', 'Voir les demandes');

        self::assertSame([], $this->mailsTo($this->joinerVerifies($club['ara'], 'Quatre', 'Cap')['mails'], $club['email']), 'après la synthèse : silence');
    }

    /** Le nom saisi par le demandeur ne fait jamais entrer un lien ou un domaine dans le sujet ni le corps. */
    public function testRequesterNameNeverReachesSubjectOrBody(): void
    {
        $club = $this->managedClub('SAFE');
        $clubName = $this->clubName($club['clubId']);

        // Revue de sécurité : un domaine suivi d'une ponctuation, que Gmail/Outlook rendent en lien.
        // Plafond de test = 2 mails individuels par club : deux demandeurs hostiles, deux mails.
        foreach ([['Gagnez', 'amateo-verif.com.'], ['x.com,', 'EVIL.COM!']] as [$first, $last]) {
            $spam = $this->joinerVerifies($club['ara'], $first, $last);
            $mails = $this->mailsTo($spam['mails'], $club['email']);
            self::assertCount(1, $mails);
            self::assertSame(\sprintf('Demande d\'adhésion — Un demandeur souhaite rejoindre %s', $clubName), $mails[0]->getSubject(), 'nom hors liste blanche → « Un demandeur », jamais nettoyé mot à mot');

            // Hors l'adresse du demandeur (légitime, entre parenthèses) et le lien de l'application,
            // ni le sujet ni le corps ne portent de domaine.
            $body = str_replace([$spam['email'], $this->base()], '', (string) $mails[0]->getTextBody());
            foreach ([(string) $mails[0]->getSubject(), $body] as $text) {
                self::assertDoesNotMatchRegularExpression('~[\p{L}\d-]+\.[a-z]{2,}~iu', $text, 'aucun domaine (« x.com », « EVIL.COM »…) dans le mail aux gestionnaires');
            }
        }
    }

    /**
     * Club démo à horloge simulée : un mail dont tous les destinataires sont du club part dans la
     * boîte aux lettres ; un destinataire EXTÉRIEUR (demandeur refusé, sorti du club) le fait partir
     * réellement — l'intercepteur est en liste blanche (`ClockedClubMailInterceptor`).
     */
    public function testClockedClubMailsToMembersLandInTheMailboxButAnOutsiderIsReallySent(): void
    {
        $clubId = $this->clockedDemoClub();
        $marc = $this->insertMember($clubId, 'Marc', 'admin');
        $lea = $this->insertMember($clubId, 'Léa', 'member', active: false);
        $nina = $this->insertMember($clubId, 'Nina', 'member', active: false);
        $mailbox = self::getContainer()->get(ClubMailboxMessageRepository::class);
        \assert($mailbox instanceof ClubMailboxMessageRepository);
        $notifier = self::getContainer()->get(MembershipRequestNotifier::class);
        \assert($notifier instanceof MembershipRequestNotifier);

        $this->scopeGucToClub($clubId);
        $notifier->notifyManagers($clubId, [$lea['userId']]);
        self::assertSame([], $this->mailsTo($this->sentEmails(), $marc['email']), 'gestionnaire du club → jamais un vrai envoi');
        self::assertSame(1, $mailbox->countForClub($clubId), 'la demande d\'adhésion est rangée dans la boîte');

        // Léa acceptée : elle est membre active → son mail « accepté » va dans la boîte.
        $this->em()->getConnection()->executeStatement('UPDATE club_user SET is_active = true WHERE club_id = :c AND user_id = :u', ['c' => $clubId, 'u' => $lea['userId']]);
        $membership = $this->em()->getRepository(ClubUser::class)->findOneBy(['clubId' => $clubId, 'userId' => $lea['userId']]);
        self::assertInstanceOf(ClubUser::class, $membership);
        $this->em()->refresh($membership);
        $notifier->notifyAccepted($membership);
        self::assertSame([], $this->mailsTo($this->sentEmails(), $lea['email']));
        self::assertSame(2, $mailbox->countForClub($clubId), 'le mail « accepté » d\'un membre du club est rangé');

        // Nina refusée : sa ligne est supprimée, elle est EXTÉRIEURE → le mail part réellement.
        $this->em()->getConnection()->executeStatement('DELETE FROM club_user WHERE club_id = :c AND user_id = :u', ['c' => $clubId, 'u' => $nina['userId']]);
        $notifier->notifyRefused($clubId, $nina['userId']);
        self::assertCount(1, $this->mailsTo($this->sentEmails(), $nina['email']), 'un destinataire hors club → envoi réel');
        self::assertSame(2, $mailbox->countForClub($clubId), 'rien de plus dans la boîte');
        $this->clearGuc();
    }

    /** « Approuver » prévient le demandeur, et lui dit avec quel rôle il entre. */
    public function testApproveMailsTheRequesterWithItsRole(): void
    {
        $club = $this->managedClub('APRL');
        $clubName = $this->clubName($club['clubId']);
        $product = $this->product();

        foreach ([['Léa', 'member', 'Vous y entrez comme membre : vous pouvez tout consulter.'], ['Sam', 'admin', 'Vous y entrez comme gestionnaire.']] as [$first, $role, $sentence]) {
            $joiner = $this->joinerVerifies($club['ara'], $first, 'Accepte');
            $this->api('POST', '/api/memberships/' . $this->pendingId($club['clubId'], $joiner['userId']) . '/approve', $club['token'], ['role' => $role]);
            self::assertResponseIsSuccessful();

            $mails = $this->mailsTo($this->sentEmails(), $joiner['email']);
            self::assertCount(1, $mails, 'UN mail au demandeur accepté');
            self::assertSame(\sprintf('%s a accepté votre demande', $clubName), $mails[0]->getSubject());
            self::assertSame(
                "Bonjour {$first},\n\nLe club {$clubName} a accepté votre demande : vous avez maintenant accès à son espace {$product}.\n{$sentence}\n\nConnectez-vous :\n{$this->base()}/login\n\n{$product}",
                (string) $mails[0]->getTextBody(),
            );
            $this->assertCtaHeadersPointToBodyLink($mails[0], $this->base() . '/login', 'Me connecter');
        }
    }

    /** « Refuser » envoie UN seul mail : le refus, qui porte l'échéance du compte quand il devient orphelin. */
    public function testRejectSendsExactlyOneMail(): void
    {
        $club = $this->managedClub('REJ1');
        $clubName = $this->clubName($club['clubId']);
        $lea = $this->joinerVerifies($club['ara'], 'Léa', 'Refus');

        $this->api('POST', '/api/memberships/' . $this->pendingId($club['clubId'], $lea['userId']) . '/reject', $club['token']);
        self::assertResponseStatusCodeSame(204);

        $mails = $this->mailsTo($this->sentEmails(), $lea['email']);
        self::assertCount(1, $mails, 'UN seul mail (le refus porte aussi le préavis orphelin) — jamais deux');
        self::assertSame(\sprintf('%s n\'a pas donné suite à votre demande', $clubName), $mails[0]->getSubject());
        $body = (string) $mails[0]->getTextBody();
        self::assertStringStartsWith(\sprintf("Bonjour Léa,\n\nLe club %s n'a pas donné suite à votre demande d'accès à son espace %s.", $clubName, $this->product()), $body);
        self::assertMatchesRegularExpression(
            \sprintf('~Vous n\'avez plus accès à aucun club sur %s\. Sans nouvel accès, votre compte sera supprimé le \d{2}/\d{2}/\d{4}\.~', preg_quote($this->product(), '~')),
            $body,
        );
        $this->assertCarriesNoCtaHeaders($mails[0]);

        $this->em()->clear();
        $user = $this->em()->getRepository(User::class)->find($lea['userId']);
        self::assertInstanceOf(User::class, $user);
        self::assertNotNull($user->getOrphanNoticeSentAt(), 'le stamp orphelin est posé comme avant (le mail unique le porte)');
    }

    // ---------------------------------------------------------------------------------------
    // Promesse 2 — un club sans gestionnaire ferme
    // ---------------------------------------------------------------------------------------

    /** Dernier gestionnaire effacé, un Membre reste, aucune adresse officielle : le Membre est prévenu à J0 puis à J-7. */
    public function testMembersMailedAtJ0AndJ7WithoutOfficialAddress(): void
    {
        $club = $this->managedClub('J0J7');
        $paul = $this->insertMember($club['clubId'], 'Paul', 'member');
        $clubName = $this->clubName($club['clubId']);
        $product = $this->product();

        $this->eraseAccount($club['token']);
        $deadline = $this->scheduledAt($club['clubId']);
        self::assertNotNull($deadline, 'dernier gestionnaire effacé → fermeture programmée, même avec un Membre restant');

        $j0 = $this->mailsTo($this->sentEmails(), $paul['email']);
        self::assertCount(1, $j0, 'J0 : le Membre est prévenu même sans adresse officielle');
        self::assertSame(\sprintf('L\'espace %s du club %s n\'a plus de gestionnaire', $product, $clubName), $j0[0]->getSubject());
        $body = (string) $j0[0]->getTextBody();
        self::assertStringContainsString(\sprintf('Le dernier gestionnaire de l\'espace %s du club %s a quitté le service. Sans reprise par un gestionnaire, cet espace sera supprimé définitivement le %s.', $product, $clubName, $deadline->format('d/m/Y')), $body);
        // PR 3 : le Membre demande lui-même depuis le bandeau (plus de renvoi à l'inscription).
        self::assertStringContainsString('Vous gardez un accès en consultation jusque-là. Si vous êtes gestionnaire du club, cliquez « Demander à devenir gestionnaire » dans le bandeau de l\'application : l\'adresse officielle du club validera.', $body);
        self::assertSame($this->base() . '/club', $j0[0]->getHeaders()->getHeaderBody(EmailTemplateRenderer::CTA_URL_HEADER));
        self::assertSame('Ouvrir l\'application', $j0[0]->getHeaders()->getHeaderBody(EmailTemplateRenderer::CTA_LABEL_HEADER));

        // J-7 : l'échéance entre dans la fenêtre du rappel.
        $this->sql('UPDATE club SET erasure_scheduled_at = NOW() + INTERVAL \'5 days\', erasure_reminder_sent_at = NULL WHERE id = :id', ['id' => $club['clubId']]);
        $deadline = $this->scheduledAt($club['clubId']);
        self::assertInstanceOf(DateTimeImmutable::class, $deadline);
        $this->runCommand('app:clubs:erasure-remind');

        $j7 = $this->mailsTo($this->sentEmails(), $paul['email']);
        self::assertCount(1, $j7, 'J-7 : le Membre est rappelé même sans adresse officielle');
        self::assertStringStartsWith('Rappel — ', (string) $j7[0]->getSubject());
        self::assertStringContainsString(\sprintf('supprimé définitivement le %s, dans 7 jours.', $deadline->format('d/m/Y')), (string) $j7[0]->getTextBody());
        self::assertNotNull($this->sql('SELECT erasure_reminder_sent_at FROM club WHERE id = :id', ['id' => $club['clubId']], fetch: true), 'un envoi réussi pose le stamp J-7');
    }

    /** Échéance passée sans rappel J-7 réellement parti : la purge est RETENUE (rien n'est détruit) et tracée. */
    public function testPurgeHeldWhenReminderNeverActuallySent(): void
    {
        $club = $this->managedClub('HOLD');
        $this->eraseAccount($club['token']);
        $this->sql('UPDATE club SET erasure_scheduled_at = NOW() - INTERVAL \'1 hour\', erasure_reminder_sent_at = NULL WHERE id = :id', ['id' => $club['clubId']]);

        $tester = $this->runCommand('app:clubs:purge-erased');

        self::assertNull($this->sql('SELECT unsubscribed_at FROM club WHERE id = :id', ['id' => $club['clubId']], fetch: true), 'purge retenue : le club n\'est pas désinscrit');
        self::assertTrue($this->clubHasSeason($club['clubId']), 'purge retenue : le workspace est intact');
        self::assertNotNull($this->scheduledAt($club['clubId']), 'retenue, pas annulée : la fermeture reste programmée');
        self::assertStringContainsString($club['clubId'], $tester->getDisplay(), 'la retenue est tracée en sortie (club nommé)');
    }

    /** La purge retenue repousse l'échéance à +7 j et remet le rappel à zéro : le rappel repart, puis la purge passe — jamais retenue pour toujours. */
    public function testPastDueUnremindedClubIsRescheduledAndRemindedNotHeldForever(): void
    {
        $club = $this->managedClub('RESC');
        $paul = $this->insertMember($club['clubId'], 'Paul', 'member');
        $this->eraseAccount($club['token']);
        $this->sql('UPDATE club SET erasure_scheduled_at = NOW() - INTERVAL \'1 hour\', erasure_reminder_sent_at = NULL WHERE id = :id', ['id' => $club['clubId']]);

        $this->runCommand('app:clubs:purge-erased');

        $rescheduled = $this->scheduledAt($club['clubId']);
        self::assertInstanceOf(DateTimeImmutable::class, $rescheduled);
        self::assertGreaterThan(new DateTimeImmutable('+6 days'), $rescheduled, 'échéance repoussée à aujourd\'hui + 7 jours');
        self::assertLessThan(new DateTimeImmutable('+8 days'), $rescheduled);
        self::assertNull($this->sql('SELECT erasure_reminder_sent_at FROM club WHERE id = :id', ['id' => $club['clubId']], fetch: true), 'rappel remis à zéro');

        $this->runCommand('app:clubs:erasure-remind');
        $reminder = $this->mailsTo($this->sentEmails(), $paul['email']);
        self::assertCount(1, $reminder, 'le rappel repart sur la nouvelle échéance');
        self::assertStringContainsString($rescheduled->format('d/m/Y'), (string) $reminder[0]->getTextBody(), 'le mail annonce la NOUVELLE date');

        $this->sql('UPDATE club SET erasure_scheduled_at = NOW() - INTERVAL \'1 hour\' WHERE id = :id', ['id' => $club['clubId']]);
        $this->runCommand('app:clubs:purge-erased');
        self::assertNotNull($this->sql('SELECT unsubscribed_at FROM club WHERE id = :id', ['id' => $club['clubId']], fetch: true), 'rappel parti → la purge passe à l\'échéance');
    }

    /**
     * La purge prend le verrou d'approbation du club (`club-approval:<code>`) avant de revalider :
     * tant qu'une approbation le tient, elle n'efface rien. Falsifié : sans le verrou, la purge
     * détruit le workspace pendant qu'une reprise est en cours.
     */
    public function testPurgeRevalidatesUnderTheApprovalLock(): void
    {
        // Club semé SANS passer par l'approbation : `approve` prend ce même verrou en transaction
        // (savepoint DAMA) et la session de test le garderait jusqu'à la fin du test.
        $club = $this->bareClub('LOCK');
        $marc = $this->insertMember($club['clubId'], 'Marc', 'admin');
        $this->scopeGucToClub($club['clubId']);
        self::getContainer()->get(ClubProvisioner::class)->seedWorkspace($this->em()->getRepository(Club::class)->find($club['clubId']));
        $this->em()->flush();
        $this->clearGuc();
        $this->eraseAccount($this->jwtFor($marc['userId']));
        $this->sql('UPDATE club SET erasure_scheduled_at = NOW() - INTERVAL \'1 hour\', erasure_reminder_sent_at = NOW() - INTERVAL \'8 days\' WHERE id = :id', ['id' => $club['clubId']]);
        self::assertTrue($this->clubHasSeason($club['clubId']), 'témoin : un workspace à purger');

        // Une AUTRE session Postgres (PDO nu, hors transaction de test) tient le verrou, comme le
        // ferait une approbation en cours : il survit à l'appel, jusqu'au unlock explicite.
        $holder = $this->independentSession();
        $conn = $this->conn();
        $key = 'club-approval:' . $club['ara'];
        $taken = $holder->prepare('SELECT pg_try_advisory_lock(hashtext(?))');
        $taken->execute([$key]);
        self::assertTrue((bool) $taken->fetchColumn(), 'témoin : la session « approbation » tient bien le verrou');
        $conn->executeStatement('SET lock_timeout = \'1500ms\'');
        try {
            $this->runCommand('app:clubs:purge-erased');
        } finally {
            $holder->prepare('SELECT pg_advisory_unlock(hashtext(?))')->execute([$key]);
            $conn->executeStatement('RESET lock_timeout');
        }
        self::assertNull($conn->fetchOne('SELECT unsubscribed_at FROM club WHERE id = :id', ['id' => $club['clubId']]), 'verrou d\'approbation tenu → aucune purge');
        self::assertTrue($this->clubHasSeason($club['clubId']), 'workspace intact tant que l\'approbation tient le verrou');

        // Témoin : verrou libéré → la purge passe.
        $this->runCommand('app:clubs:purge-erased');
        self::assertNotNull($conn->fetchOne('SELECT unsubscribed_at FROM club WHERE id = :id', ['id' => $club['clubId']]), 'verrou libre → purge');
    }

    /** `/api/me.club.closingOn` vaut l'échéance tant qu'aucun gestionnaire actif n'existe, null sinon. */
    public function testMeExposesClosingOnOnlyWhileNoManager(): void
    {
        $club = $this->managedClub('CLON');
        $paul = $this->insertMember($club['clubId'], 'Paul', 'member');

        $me = $this->api('GET', '/api/me', $this->jwtFor($paul['userId']));
        self::assertArrayHasKey('closingOn', $me['club'], 'le champ existe toujours');
        self::assertNull($me['club']['closingOn'], 'un gestionnaire actif → pas de fermeture');

        $this->eraseAccount($club['token']);
        $deadline = $this->scheduledAt($club['clubId']);
        self::assertInstanceOf(DateTimeImmutable::class, $deadline);
        $me = $this->api('GET', '/api/me', $this->jwtFor($paul['userId']));
        self::assertIsString($me['club']['closingOn'] ?? null);
        self::assertStringStartsWith($deadline->format('Y-m-d'), $me['club']['closingOn'], 'closingOn = l\'échéance de suppression');

        $this->insertMember($club['clubId'], 'Sophie', 'admin');
        self::assertNotNull($this->scheduledAt($club['clubId']), 'témoin : l\'échéance est encore posée en base');
        $me = $this->api('GET', '/api/me', $this->jwtFor($paul['userId']));
        self::assertNull($me['club']['closingOn'], 'un gestionnaire actif existe → closingOn null, même échéance posée');
    }

    /**
     * Une invitation GESTIONNAIRE émise avant le départ du dernier gestionnaire, acceptée ensuite
     * (lien public ou compte connecté), annule la fermeture ; une invitation Membre non.
     */
    public function testAcceptedManagerInvitationClearsClosure(): void
    {
        // (a) Lien public : Julie, invitée gestionnaire par Marc avant son départ, crée son compte.
        $a = $this->managedClub('INVA');
        $rawA = $this->seedInvitation($a['clubId'], 'julie-' . strtolower($a['ara']) . '@test.fr', ClubRole::MANAGER, $a['userId']);
        $this->eraseAccount($a['token']);
        self::assertNotNull($this->scheduledAt($a['clubId']));
        $this->sql('UPDATE club SET erasure_reminder_sent_at = NOW() WHERE id = :id', ['id' => $a['clubId']]);

        $this->startFreshBrowserSession($this->client);
        $this->client->request('POST', '/api/invitations/public/' . $rawA . '/accept', [], [], ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $this->ip()], json_encode(['firstName' => 'Julie', 'lastName' => 'Relais', 'password' => self::PASSWORD, 'consent' => true], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
        self::assertNull($this->scheduledAt($a['clubId']), 'invitation gestionnaire acceptée → fermeture annulée');
        self::assertNull($this->sql('SELECT erasure_reminder_sent_at FROM club WHERE id = :id', ['id' => $a['clubId']], fetch: true), 'le rappel J-7 est remis à zéro');
        $this->scopeGucToClub($a['clubId']);
        self::assertSame(1, (int) $this->conn()->fetchOne('SELECT COUNT(*) FROM audit_log WHERE club_id = :c AND action = :a', ['c' => $a['clubId'], 'a' => AuditAction::CLUB_CLOSURE_CANCELLED->value]), 'l\'annulation est auditée');
        $this->clearGuc();

        // (b) Compte connecté : Zoé, sans club, accepte une invitation gestionnaire.
        $b = $this->managedClub('INVB');
        $zoe = $this->insertUser('Zoé');
        $rawB = $this->seedInvitation($b['clubId'], $zoe['email'], ClubRole::MANAGER, $b['userId']);
        $this->eraseAccount($b['token']);
        self::assertNotNull($this->scheduledAt($b['clubId']));
        $this->api('POST', '/api/invitations/' . $rawB . '/accept', $this->jwtFor($zoe['userId']));
        self::assertResponseIsSuccessful();
        self::assertNull($this->scheduledAt($b['clubId']), 'acceptation connectée d\'une invitation gestionnaire → fermeture annulée');

        // (c) Une invitation MEMBRE acceptée ne sauve pas le club.
        $c = $this->managedClub('INVC');
        $rawC = $this->seedInvitation($c['clubId'], 'kim-' . strtolower($c['ara']) . '@test.fr', ClubRole::MEMBER, $c['userId']);
        $this->eraseAccount($c['token']);
        $this->startFreshBrowserSession($this->client);
        $this->client->request('POST', '/api/invitations/public/' . $rawC . '/accept', [], [], ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $this->ip()], json_encode(['firstName' => 'Kim', 'lastName' => 'Membre', 'password' => self::PASSWORD, 'consent' => true], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
        self::assertNotNull($this->scheduledAt($c['clubId']), 'un Membre de plus ne sauve pas un club sans gestionnaire');
    }

    /** L'effacement pour inactivité du SEUL gestionnaire ferme le club et prévient les Membres restants. */
    public function testInactivityErasureOfTheSoleManagerClosesTheClubAndMailsMembers(): void
    {
        $club = $this->managedClub('INAC');
        $paul = $this->insertMember($club['clubId'], 'Paul', 'member');
        $this->makeInactiveAndWarned($club['userId']);

        $this->runCommand('app:users:purge-inactive');

        self::assertNotNull($this->sql('SELECT anonymized_at FROM app_user WHERE id = :id', ['id' => $club['userId']], fetch: true), 'témoin : le gestionnaire inactif est anonymisé');
        self::assertNotNull($this->scheduledAt($club['clubId']), 'dernier gestionnaire effacé par inactivité → fermeture programmée');
        $mails = $this->mailsTo($this->sentEmails(), $paul['email']);
        self::assertCount(1, $mails, 'le Membre restant est prévenu');
        self::assertSame(\sprintf('L\'espace %s du club %s n\'a plus de gestionnaire', $this->product(), $this->clubName($club['clubId'])), $mails[0]->getSubject());
    }

    /** Le mail à l'adresse officielle dit « a quitté le service » (vrai aussi pour l'inactivité), et part APRÈS celui des Membres. */
    public function testInactivityClosureMailDoesNotClaimDeletion(): void
    {
        $club = $this->managedClub('INOF');
        $paul = $this->insertMember($club['clubId'], 'Paul', 'member');
        $official = 'officiel-' . strtolower($club['ara']) . '@club.fr';
        $this->sql('UPDATE club SET contact_email = :e WHERE id = :id', ['e' => $official, 'id' => $club['clubId']]);
        $this->makeInactiveAndWarned($club['userId']);

        $this->runCommand('app:users:purge-inactive');

        $sent = $this->sentEmails();
        $mails = $this->mailsTo($sent, $official);
        self::assertCount(1, $mails);
        $body = (string) $mails[0]->getTextBody();
        self::assertStringContainsString(\sprintf('Le dernier gestionnaire de l\'espace %s du club %s a quitté le service : cet espace n\'a plus de gestionnaire.', $this->product(), $this->clubName($club['clubId'])), $body);
        self::assertStringNotContainsString('vient de supprimer son compte', $body, 'un compte effacé pour inactivité n\'a rien « supprimé »');

        $order = array_map(static fn (Email $m): string => $m->getTo()[0]->getAddress(), $sent);
        $paulAt = array_search($paul['email'], $order, true);
        $officialAt = array_search($official, $order, true);
        self::assertIsInt($paulAt);
        self::assertIsInt($officialAt);
        self::assertLessThan($officialAt, $paulAt, 'les Membres d\'abord, PUIS l\'adresse officielle');
    }

    /** Le préavis d'inactivité du dernier gestionnaire nomme le club qui sera supprimé ; celui d'un co-gestionnaire ne dit rien. */
    public function testLastManagerInactivityWarningNamesTheClub(): void
    {
        $sole = $this->managedClub('WSOL');
        $this->insertMember($sole['clubId'], 'Paul', 'member');
        $co = $this->managedClub('WCOG');
        $this->insertMember($co['clubId'], 'Sophie', 'admin');
        foreach ([$sole['userId'], $co['userId']] as $userId) {
            $this->sql('UPDATE app_user SET created_at = NOW() - INTERVAL \'23 months 15 days\', last_login_at = NOW() - INTERVAL \'23 months 15 days\', inactivity_warned_at = NULL WHERE id = :id', ['id' => $userId]);
        }

        $this->runCommand('app:users:purge-inactive');
        $sent = $this->sentEmails();

        $soleWarning = $this->mailsTo($sent, $sole['email']);
        self::assertCount(1, $soleWarning);
        self::assertStringContainsString(\sprintf('Vous êtes le dernier gestionnaire de %s : sans reconnexion, cet espace sera ensuite supprimé.', $this->clubName($sole['clubId'])), (string) $soleWarning[0]->getTextBody());

        $coWarning = $this->mailsTo($sent, $co['email']);
        self::assertCount(1, $coWarning, 'témoin : le co-gestionnaire est prévenu de son inactivité');
        self::assertStringNotContainsString('Vous êtes le dernier gestionnaire', (string) $coWarning[0]->getTextBody(), 'un autre gestionnaire reste → aucune annonce de suppression du club');
    }

    /** L'effacement d'un compte clôt sa demande de club vivante : statut `cancelled`, lien mort (404 identique), aucun mail. */
    public function testErasureClosesTheUsersPendingClubRequest(): void
    {
        $ara = $this->uniq('CNCL');
        $nadia = $this->registerAndVerifyPending($ara, 'Nadia', 'Annule', 'Club Annule ' . $ara);
        $request = $this->em()->getRepository(ClubCreationRequest::class)->findOneBy(['userId' => $nadia['userId']]);
        self::assertInstanceOf(ClubCreationRequest::class, $request);
        self::assertSame(ClubCreationRequest::STATUS_PENDING, $request->getStatus(), 'témoin : demande vivante');
        $token = $request->getToken();
        $requestId = $request->getId();

        $this->eraseAccount($nadia['token']);
        foreach ($this->sentEmails() as $mail) {
            self::assertStringNotContainsString('Club Annule', (string) $mail->getSubject() . (string) $mail->getTextBody(), 'aucun mail sur la demande close');
        }

        self::assertSame(ClubCreationRequest::STATUS_CANCELLED, $this->sql('SELECT status FROM club_creation_request WHERE id = :id', ['id' => $requestId], fetch: true));
        $this->startFreshBrowserSession($this->client);
        $this->client->request('GET', '/api/club-approvals/' . str_repeat('a', 64), [], [], ['REMOTE_ADDR' => $this->ip()]);
        $unknown = (string) $this->client->getResponse()->getContent();
        $this->client->request('GET', '/api/club-approvals/' . $token, [], [], ['REMOTE_ADDR' => $this->ip()]);
        self::assertResponseStatusCodeSame(404);
        self::assertSame($unknown, (string) $this->client->getResponse()->getContent(), 'lien mort : 404 byte-identique à un jeton inconnu');
    }

    /** Une personne déjà inscrite qui se réinscrit sur un club SANS gestionnaire est orientée vers le support (réponse inchangée). */
    public function testAccountExistsMailPointsToSupportOnAManagerlessClub(): void
    {
        $sentence = 'Ce club n\'a plus de gestionnaire. Si vous en êtes un, contactez le support depuis la page Confidentialité : il peut traiter votre demande.';

        $closing = $this->managedClub('SUPA');
        $paul = $this->insertMember($closing['clubId'], 'Paul', 'member');
        $this->eraseAccount($closing['token']);
        $this->registerAttempt($paul['email'], $closing['ara']);
        self::assertResponseStatusCodeSame(202);
        $closingBody = (string) $this->client->getResponse()->getContent();
        $mails = $this->mailsTo($this->sentEmails(), $paul['email']);
        self::assertCount(1, $mails);
        self::assertStringContainsString($sentence, (string) $mails[0]->getTextBody());

        $managed = $this->managedClub('SUPB');
        $sophie = $this->insertMember($managed['clubId'], 'Sophie', 'member');
        $this->registerAttempt($sophie['email'], $managed['ara']);
        self::assertResponseStatusCodeSame(202);
        self::assertSame($closingBody, (string) $this->client->getResponse()->getContent(), 'réponse byte-identique : rien ne fuit sur l\'état du club');
        $mails = $this->mailsTo($this->sentEmails(), $sophie['email']);
        self::assertCount(1, $mails);
        self::assertStringNotContainsString($sentence, (string) $mails[0]->getTextBody(), 'club géré → aucune indication support');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    protected function tearDown(): void
    {
        self::getContainer()->get(TenantConnectionContext::class)->clear();
        parent::tearDown();
    }

    // ---------------------------------------------------------------------------------------
    // Décor
    // ---------------------------------------------------------------------------------------

    /**
     * Un club créé par le vrai rail (inscription → vérification → approbation par le relais dev),
     * son gestionnaire Marc actif.
     *
     * @return array{clubId: string, ara: string, token: string, userId: string, email: string}
     */
    private function managedClub(string $tag): array
    {
        $ara = $this->uniq($tag);
        $email = 'marc-' . strtolower($ara) . '@test.fr';
        $this->registerAttempt($email, $ara, 'Marc', 'Gestion', 'Club ' . $ara);
        self::assertResponseStatusCodeSame(202);
        $token = $this->verifyRegistration($this->client, $email);
        self::assertNotSame('', $token);
        $userId = $this->userIdOf($email);
        $clubId = $this->clubIdByAra($ara);

        return ['clubId' => $clubId, 'ara' => $ara, 'token' => $token, 'userId' => $userId, 'email' => $email];
    }

    /** @return array{clubId: string, ara: string} un club réel sans aucun membre */
    private function bareClub(string $tag): array
    {
        $ara = $this->uniq($tag);
        $club = (new Club)->setName('Club ' . $ara)->setSlug('club-' . strtolower($ara))->setTimezone('Europe/Paris')->setLocale('fr');
        $club->setFfbbClubCode($ara);
        $this->em()->persist($club);
        $this->em()->flush();

        return ['clubId' => $club->getId(), 'ara' => $ara];
    }

    private function clockedDemoClub(): string
    {
        $suffix = bin2hex(random_bytes(4));
        $club = (new Club)->setName('Démo ' . $suffix)->setSlug('demo-' . $suffix)->setTimezone('Europe/Paris')->setLocale('fr');
        $club->setIsDemo(true);
        $club->setSimulatedToday(new DateTimeImmutable('2026-12-24'));
        $this->em()->persist($club);
        $this->em()->flush();

        return $club->getId();
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

    /**
     * Un membre témoin inséré directement (le rail d'approbation est prouvé ailleurs dans ce fichier).
     *
     * @return array{userId: string, email: string}
     */
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

    private function seedInvitation(string $clubId, string $email, ClubRole $role, string $invitedBy): string
    {
        $raw = bin2hex(random_bytes(32));
        $this->scopeGucToClub($clubId);
        $invitation = new ClubInvitation;
        $invitation->setClubId($clubId);
        $invitation->setEmail($email);
        $invitation->setRole($role->value);
        $invitation->setTokenHash(hash('sha256', $raw));
        $invitation->setExpiresAt(new DateTimeImmutable('+7 days'));
        $invitation->setInvitedByUserId($invitedBy);
        $this->em()->persist($invitation);
        $this->em()->flush();
        $this->clearGuc();
        $this->em()->clear();

        return $raw;
    }

    private function registerAttempt(string $email, string $ara, string $first = 'Re', string $last = 'Inscrit', string $clubName = 'x'): void
    {
        $this->startFreshBrowserSession($this->client);
        $this->client->request('POST', '/api/register', [], [], ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $this->ip()], json_encode([
            'email' => $email, 'password' => self::PASSWORD, 'firstName' => $first, 'lastName' => $last,
            'ara' => $ara, 'club_name' => $clubName, 'consent' => true,
        ], \JSON_THROW_ON_ERROR));
    }

    private function registerOnly(string $ara, string $first, string $last, string $clubName = 'x'): string
    {
        // Adresse neutre : le nom saisi peut être hostile, l'adresse ne doit pas en porter la trace.
        $email = 'joiner-' . bin2hex(random_bytes(5)) . '@test.fr';
        $this->registerAttempt($email, $ara, $first, $last, $clubName);
        self::assertResponseStatusCodeSame(202);

        return $email;
    }

    private function mintVerificationToken(string $email): string
    {
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $user = $em->getRepository(User::class)->findOneBy(['email' => strtolower($email)]);
        self::assertInstanceOf(User::class, $user);
        $pending = $em->getRepository(EmailVerificationToken::class)->findOneBy(['user' => $user]);
        self::assertInstanceOf(EmailVerificationToken::class, $pending);
        $raw = $container->get(EmailVerifier::class)->generateToken($user, $pending->getAra(), $pending->getClubName());
        $em->flush();

        return $raw;
    }

    /** @return array<string, mixed> */
    private function postVerify(string $raw): array
    {
        $this->startFreshBrowserSession($this->client);
        $this->client->request('POST', '/api/register/verify', [], [], ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $this->ip()], json_encode(['token' => $raw], \JSON_THROW_ON_ERROR));

        return (array) json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    /**
     * Inscription + vérification SANS relais d'approbation, mails de la vérification capturés.
     *
     * @return array{userId: string, email: string, body: array<string, mixed>, mails: list<Email>}
     */
    private function joinerVerifies(string $ara, string $first, string $last): array
    {
        $email = $this->registerOnly($ara, $first, $last);
        $body = $this->postVerify($this->mintVerificationToken($email));
        self::assertResponseIsSuccessful();

        return ['userId' => $this->userIdOf($email), 'email' => $email, 'body' => $body, 'mails' => $this->sentEmails()];
    }

    /** @return array{userId: string, email: string, token: string} une demande de club en attente (club_pending) */
    private function registerAndVerifyPending(string $ara, string $first, string $last, string $clubName): array
    {
        $email = $this->registerOnly($ara, $first, $last, $clubName);
        $body = $this->postVerify($this->mintVerificationToken($email));
        self::assertSame('club_pending', $body['membershipStatus'] ?? null);

        return ['userId' => $this->userIdOf($email), 'email' => $email, 'token' => $this->jwtFromCookie($this->client)];
    }

    private function publicDecision(string $token, string $decision): void
    {
        $this->startFreshBrowserSession($this->client);
        $this->client->request('POST', '/api/club-approvals/' . $token, [], [], ['REMOTE_ADDR' => $this->ip(), 'CONTENT_TYPE' => 'application/json'], json_encode(['decision' => $decision], \JSON_THROW_ON_ERROR));
    }

    private function eraseAccount(string $token): void
    {
        $this->api('DELETE', '/api/me', $token, ['password' => self::PASSWORD]);
        self::assertResponseIsSuccessful();
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed>
     */
    private function api(string $method, string $uri, string $token, ?array $body = null): array
    {
        $this->startFreshBrowserSession($this->client);
        $this->client->request($method, $uri, [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'REMOTE_ADDR' => $this->ip(),
        ], null === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR));

        return (array) json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    private function makeInactiveAndWarned(string $userId): void
    {
        $this->sql('UPDATE app_user SET created_at = NOW() - INTERVAL \'25 months\', last_login_at = NOW() - INTERVAL \'25 months\', inactivity_warned_at = NOW() - INTERVAL \'40 days\' WHERE id = :id', ['id' => $userId]);
    }

    private function runCommand(string $name): CommandTester
    {
        $tester = new CommandTester(new Application(self::$kernel)->find($name));
        $tester->execute([]);

        return $tester;
    }

    // ---------------------------------------------------------------------------------------
    // Lecture
    // ---------------------------------------------------------------------------------------

    /** @return list<Email> les mails enfilés sur le transport mémoire du container courant */
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

    private function pendingId(string $clubId, string $userId): string
    {
        $id = $this->conn()->fetchOne('SELECT id FROM club_user WHERE club_id = :c AND user_id = :u AND is_active = false AND deactivated_at IS NULL', ['c' => $clubId, 'u' => $userId]);
        self::assertIsString($id, 'une adhésion pending existe');

        return $id;
    }

    private function pendingCount(string $clubId, string $userId): int
    {
        return (int) $this->conn()->fetchOne('SELECT COUNT(*) FROM club_user WHERE club_id = :c AND user_id = :u', ['c' => $clubId, 'u' => $userId]);
    }

    private function userIdOf(string $email): string
    {
        $id = $this->conn()->fetchOne('SELECT id FROM app_user WHERE email = :e', ['e' => strtolower($email)]);
        self::assertIsString($id);

        return $id;
    }

    private function clubIdByAra(string $ara): string
    {
        $id = $this->conn()->fetchOne('SELECT id FROM club WHERE ffbb_club_code = :a AND is_demo = false', ['a' => $ara]);
        self::assertIsString($id, 'le club existe');

        return $id;
    }

    private function clubName(string $clubId): string
    {
        $name = $this->conn()->fetchOne('SELECT name FROM club WHERE id = :id', ['id' => $clubId]);
        self::assertIsString($name);

        return $name;
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
