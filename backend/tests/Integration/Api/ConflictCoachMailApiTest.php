<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Clock\DevClockStore;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Coach;
use App\Entity\CoachPlayerMembership;
use App\Entity\Fixture;
use App\Entity\Season;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\TeamCoach;
use App\Entity\User;
use App\Enum\FixtureHomeAway;
use App\Enum\SeasonStatus;
use App\Enum\TeamCoachRole;
use App\Repository\ConflictMailReceiptRepository;
use App\Service\ConflictCoachMailing;
use App\Service\ConflictMailingLock;
use App\Service\SeasonResolver;
use App\Service\SocleGuard;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\TenantGucTrait;
use App\Tests\Unit\Service\ConflictCoachMailBuilderTest;
use App\Tests\Unit\Service\ConflictCoachMailingTest;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use Symfony\Component\Uid\Uuid;

/**
 * « Envoyer aux coachs » de bout en bout côté serveur : `POST /api/fixtures/conflicts/mail-coaches`
 * e-maille à chaque coach concerné SES conflits de personne sur un match, prévient d'un « plus aucun
 * conflit » qui avait reçu une liste, et tient la mémoire du dernier envoi (table `conflict_mailing`).
 * `GET /api/fixtures/conflicts` en sert les compteurs (`coachMailing`), calculés par le même service.
 * Mémoire PAR CONFLIT (`conflict_mail_receipt`) : par défaut, seuls partent les coachs qui ont un
 * conflit nouveau ou modifié depuis le dernier envoi (`resendAll` renvoie tout) ; `GET
 * …/mail-coaches/preview` dit avant l'envoi, coach par coach, ce que le POST fera.
 *
 * Décor « Les Aiglons » (un club NEUF par test — le plafond de test `conflict_mail_send` est de 2
 * e-mails par club et par jour, chaque scénario en enfile au plus 2) : Nadia gestionnaire ; Karim
 * principal U15 et joueur en SM2 ; Emma principale SM2 ; Sam adjoint U15. Samedi 04/10, U15 reçoit
 * à 15:30 et SM2 à 16:00 : Karim est attendu aux deux.
 *
 * Les règles pures (qui reçoit quoi, mémoire) sont prouvées au plus bas par
 * {@see ConflictCoachMailingTest} ; ici on prouve qu'elles atteignent le
 * vrai envoi, la vraie base et les vraies gardes.
 */
#[Group('phase1')]
#[Group('integration')]
final class ConflictCoachMailApiTest extends WebTestCase
{
    use ChoosesPlanVersionTrait;
    use TenantGucTrait;

    private const string URL = '/api/fixtures/conflicts/mail-coaches';

    private const string PREVIEW_URL = '/api/fixtures/conflicts/mail-coaches/preview';

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    private Club $club;

    private Season $season;

    private User $nadia;

    private Coach $karim;

    private ?Coach $emma = null;

    private Coach $sam;

    private string $sm2FixtureId;

    // ── Envoi nominal ──

    /** Chaque coach concerné (la personne + les principaux des deux équipes) reçoit UN mail ; l'adjoint, non. */
    public function testEachConcernedCoachReceivesHisConflictsOnce(): void
    {
        $this->aiglons();

        $this->client->enableProfiler();
        $this->post();
        self::assertResponseStatusCodeSame(200);

        self::assertQueuedEmailCount(2);
        $to = array_map(static fn (Email $m): string => $m->getTo()[0]->getAddress(), $this->queuedEmails());
        self::assertEqualsCanonicalizing(['karim@aiglons.test', 'emma@aiglons.test'], $to);
        self::assertNotContains('sam@aiglons.test', $to, 'un adjoint ne reçoit pas');
        foreach ($this->queuedEmails() as $email) {
            self::assertSame('Un conflit vous concerne — ' . $this->club->emailLabel(), $email->getSubject());
        }

        $body = $this->json();
        self::assertSame(1, $body['conflictCount']);
        self::assertIsString($body['sentAt']);
        self::assertNotFalse(DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $body['sentAt']));
        self::assertEqualsCanonicalizing([$this->karim->getId(), $this->emma?->getId()], array_column($body['recipients'], 'coachId'));
        self::assertSame([], $body['cleared']);
        self::assertSame([], $body['skipped']);
        self::assertSame([], $body['failed']);
    }

    /**
     * Le conflit réel du radar (bornes à l'heure murale servie) arrive dans le mail AVEC sa ligne de
     * chevauchement : Karim est attendu à 15:30 avec U15 et à 16:00 avec SM2. Jumeau unitaire :
     * {@see ConflictCoachMailBuilderTest::testAConflictAsTheRadarServesItShowsItsOverlap}.
     */
    public function testTheMailOfARealRadarConflictShowsItsOverlap(): void
    {
        $this->aiglons();

        $this->client->enableProfiler();
        $this->post();
        self::assertResponseStatusCodeSame(200);

        $toKarim = array_values(array_filter($this->queuedEmails(), static fn (Email $m): bool => 'karim@aiglons.test' === $m->getTo()[0]->getAddress()));
        self::assertCount(1, $toKarim);
        self::assertMatchesRegularExpression('/Chevauchement 16:00 → \d{2}:\d{2} \(\d/u', (string) $toKarim[0]->getTextBody());
    }

    /** Le GET compte ce que le POST envoie (même service), puis sert la ligne « Envoyé le … par Nadia — 2 e-mails ». */
    public function testTheGetCountsWhatThePostSendsThenShowsTheStamp(): void
    {
        $this->aiglons();

        $before = $this->coachMailing();
        self::assertSame(1, $before['personConflictCount'], 'un seul conflit de personne : les niveaux manquants ne comptent pas');
        self::assertSame(0, $before['pendingRecipientCount']);
        self::assertSame(2, $before['recipientCount'] ?? null, 'la confirmation annonce 2 coachs');
        self::assertNull($before['last'], 'aucun envoi : pas de ligne « Envoyé le … »');

        $this->post();
        self::assertResponseStatusCodeSame(200);
        $sent = $this->json();
        self::assertSame($before['personConflictCount'], $sent['conflictCount'], 'parité GET/POST');
        self::assertSame($before['recipientCount'], \count($sent['recipients']) + \count($sent['cleared']), 'parité GET/POST : les coachs annoncés sont ceux qui reçoivent');

        $after = $this->coachMailing();
        self::assertSame($sent['sentAt'], $after['last']['sentAt'] ?? null);
        self::assertSame('Nadia', $after['last']['sentByFirstName'] ?? null);
        self::assertSame(2, $after['last']['mailedCount'] ?? null);
        self::assertSame([], $after['last']['skippedCoachIds'] ?? null);
        self::assertEqualsCanonicalizing([$this->karim->getId(), $this->emma?->getId()], $this->memory());
    }

    /** Un coach sans adresse est sauté, nommé (kind « conflicts »), compté dans la ligne et n'entre pas en mémoire. */
    public function testACoachWithoutAddressIsSkippedNamedAndNotRemembered(): void
    {
        $this->aiglons(karimEmail: null);

        $this->client->enableProfiler();
        $this->post();
        self::assertResponseStatusCodeSame(200);
        self::assertQueuedEmailCount(1);

        $body = $this->json();
        self::assertSame([['coachId' => $this->karim->getId(), 'firstName' => 'Karim', 'lastName' => 'Benali', 'kind' => 'conflicts']], $body['skipped']);
        self::assertSame([$this->karim->getId()], $this->coachMailing()['last']['skippedCoachIds'] ?? null);
        self::assertSame([$this->emma?->getId()], $this->memory());
    }

    /** Tous sautés : rien n'est enfilé, `sentAt` est null, aucune ligne n'est écrite (ni stamp, ni mémoire). */
    public function testWhenEveryoneIsSkippedNothingIsStamped(): void
    {
        $this->aiglons(karimEmail: null, emmaEmail: null);

        $this->client->enableProfiler();
        $this->post();
        self::assertResponseStatusCodeSame(200);
        self::assertQueuedEmailCount(0);

        $body = $this->json();
        self::assertNull($body['sentAt']);
        self::assertCount(2, $body['skipped']);
        self::assertNull($this->coachMailing()['last']);
        self::assertSame(0, $this->mailingRowCount());
    }

    /** Un envoi qui échoue est rendu dans `failed` avec son kind ; seul ce qui est parti entre en mémoire. */
    public function testAFailedSendIsReportedAndKeptOutOfMemory(): void
    {
        $this->aiglons();

        $mailer = $this->postWithMailerFailingFor(['emma@aiglons.test']);
        self::assertResponseStatusCodeSame(200);

        $body = $this->json();
        self::assertSame([['coachId' => $this->emma?->getId(), 'firstName' => 'Emma', 'lastName' => 'Durand', 'kind' => 'conflicts']], $body['failed']);
        self::assertSame(['karim@aiglons.test'], $mailer->sentTo);
        self::assertSame([$this->karim->getId()], $this->memory());
        self::assertSame(1, $this->coachMailing()['last']['mailedCount'] ?? null);
    }

    // ── Mémoire : « plus aucun conflit » ──

    /**
     * Karim avait reçu une liste ; son conflit a disparu. Le mail « plus aucun » échoue : il reste en
     * mémoire (kind « cleared »). Au reclic il part, Karim sort, et un 3ᵉ clic est un 422.
     * Deux jetons consommés (échec + reclic), pas deux mails partis.
     */
    public function testAFailedClearedMailKeepsTheCoachThenTheReclicTellsHim(): void
    {
        $this->aiglons(withConflict: false);
        $this->rememberedFromAnEarlierSend([$this->karim->getId()]);
        self::assertSame(1, $this->coachMailing()['pendingRecipientCount']);

        $this->postWithMailerFailingFor(['karim@aiglons.test']);
        self::assertResponseStatusCodeSame(200);
        $failed = $this->json();
        self::assertSame('cleared', $failed['failed'][0]['kind'] ?? null);
        self::assertNull($failed['sentAt'], 'rien n\'est parti');
        self::assertSame([$this->karim->getId()], $this->memory(), 'il n\'a pas été informé : il reste');

        $this->client->enableProfiler();
        $this->post();
        self::assertResponseStatusCodeSame(200);
        self::assertQueuedEmailCount(1);
        self::assertSame('Plus aucun conflit sur un match ne vous concerne — ' . $this->club->emailLabel(), $this->queuedEmails()[0]->getSubject());
        self::assertSame([$this->karim->getId()], array_column($this->json()['cleared'], 'coachId'));
        self::assertSame([], $this->memory());
        self::assertSame(1, $this->coachMailing()['last']['mailedCount'] ?? null, '« plus aucun » compte dans les e-mails');

        $this->post();
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Aucun conflit à envoyer et aucun coach à prévenir.', $this->json()['error'] ?? null);
    }

    /** Karim a retiré son adresse et n'a plus de conflit : il reste en mémoire, ne compte pas (422) ; adresse remise, il est prévenu. */
    public function testAClearedCoachWithoutAddressWaitsForHisAddress(): void
    {
        $this->aiglons(withConflict: false, karimEmail: null);
        $this->rememberedFromAnEarlierSend([$this->karim->getId()]);

        self::assertSame(0, $this->coachMailing()['pendingRecipientCount']);
        $this->post();
        self::assertResponseStatusCodeSame(422);
        self::assertSame([$this->karim->getId()], $this->memory());

        $this->setEmail($this->karim, 'karim@aiglons.test');
        self::assertSame(1, $this->coachMailing()['pendingRecipientCount']);
        $this->client->enableProfiler();
        $this->post();
        self::assertResponseStatusCodeSame(200);
        self::assertQueuedEmailCount(1);
        self::assertSame([], $this->memory());
    }

    /**
     * Léa (ici Karim, seul concerné) avait reçu une liste lundi ; mardi, sans adresse, il est sauté
     * alors qu'il a encore un conflit : il reste en mémoire. Vendredi, conflit disparu et adresse
     * remise : il reçoit « plus aucun conflit ».
     */
    public function testACoachSkippedWhileInConflictIsToldOnceItIsOver(): void
    {
        $this->aiglons(karimEmail: null, soloKarim: true);
        $this->rememberedFromAnEarlierSend([$this->karim->getId()]);

        $this->post();
        self::assertResponseStatusCodeSame(200);
        self::assertSame('conflicts', $this->json()['skipped'][0]['kind'] ?? null);
        self::assertSame([$this->karim->getId()], $this->memory());

        $this->removeTheConflict();
        $this->setEmail($this->karim, 'karim@aiglons.test');
        $this->client->enableProfiler();
        $this->post();
        self::assertResponseStatusCodeSame(200);
        self::assertQueuedEmailCount(1);
        self::assertStringStartsWith('Plus aucun conflit', (string) $this->queuedEmails()[0]->getSubject());
    }

    /** Cas inverse : jamais informé, sauté pendant le conflit, puis conflit disparu — aucun mail, le bouton est éteint. */
    public function testACoachNeverToldIsNotToldItIsOver(): void
    {
        $this->aiglons(karimEmail: null, soloKarim: true);

        $this->post();
        self::assertResponseStatusCodeSame(200);
        self::assertSame([], $this->memory());

        $this->removeTheConflict();
        $this->setEmail($this->karim, 'karim@aiglons.test');
        self::assertSame(0, $this->coachMailing()['pendingRecipientCount']);
        $this->client->enableProfiler();
        $this->post();
        self::assertResponseStatusCodeSame(422);
        self::assertQueuedEmailCount(0);
    }

    // ── Ce qui part : « à venir » = le radar, une mémoire par saison ──

    /** Un match d'aujourd'hui (date civile du club) part encore le soir même. */
    public function testAMatchOfTodayIsStillMailedTonight(): void
    {
        self::getContainer()->get(DevClockStore::class)->set(new DateTimeImmutable('2026-10-04 20:00:00'));
        $this->aiglons();
        $this->post();
        self::assertResponseStatusCodeSame(200);
        self::assertSame(1, $this->json()['conflictCount'], 'le match joué cet après-midi est encore daté d\'aujourd\'hui');
    }

    /** Le lendemain, le match d'hier ne part plus : rien à envoyer. */
    public function testAPastMatchIsNoLongerMailed(): void
    {
        self::getContainer()->get(DevClockStore::class)->set(new DateTimeImmutable('2026-10-05 08:00:00'));
        $this->aiglons();
        $this->post();
        self::assertResponseStatusCodeSame(422);
    }

    /** Deux saisons = deux mémoires : l'envoi de l'une ne touche pas la mémoire de l'autre, et chaque mail nomme sa saison. */
    public function testTwoSeasonsKeepTwoMemories(): void
    {
        $this->aiglons(soloKarim: true);
        $current = $this->season;
        $currentKarim = $this->karim;
        $next = $this->nextSeasonWithItsOwnConflict();

        $this->client->enableProfiler();
        $this->post($current);
        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString('saison ' . $current->getName(), (string) $this->queuedEmails()[0]->getTextBody());

        $this->client->enableProfiler();
        $this->post($next);
        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString('saison ' . $next->getName(), (string) $this->queuedEmails()[0]->getTextBody());

        self::assertSame([$currentKarim->getId()], $this->memory($current));
        self::assertSame([$this->karim->getId()], $this->memory($next));
        self::assertNotSame($currentKarim->getId(), $this->karim->getId());
    }

    // ── Mémoire par conflit : reçus (conflict_mail_receipt) ──

    /**
     * Après un envoi, chaque coach servi détient UN reçu par conflit envoyé (empreinte + digest du
     * contenu), et tout coach ayant un reçu est dans la mémoire « qui détient une liste ».
     */
    public function testAfterASendEveryCoachWithAReceiptIsRemembered(): void
    {
        $this->aiglons();
        $fingerprints = $this->personConflictFingerprints();
        self::assertCount(1, $fingerprints, 'témoin : un seul conflit de personne dans le décor');

        $this->post();
        self::assertResponseStatusCodeSame(200);

        foreach ([$this->karim, $this->emma] as $coach) {
            \assert($coach instanceof Coach);
            $rows = $this->receipts($coach);
            self::assertSame($fingerprints, array_keys($rows), \sprintf('%s détient un reçu par conflit envoyé', $coach->getFirstName()));
            self::assertMatchesRegularExpression('/^[0-9a-f]{40}$/', (string) array_values($rows)[0], 'le reçu porte le digest du contenu affiché');
        }
        self::assertSame([], array_values(array_diff($this->receiptCoachIds(), $this->memory())), 'tout coach ayant un reçu est en mémoire');
    }

    /** Un coach prévenu « plus aucun conflit » perd ses reçus : il ne détient plus aucune liste. */
    public function testAClearedCoachLosesHisReceipts(): void
    {
        $this->aiglons(soloKarim: true);
        $this->post();
        self::assertResponseStatusCodeSame(200);
        self::assertNotSame([], $this->receipts($this->karim), 'témoin : le premier envoi a écrit le reçu de Karim');

        $this->removeTheConflict();
        $this->post();
        self::assertResponseStatusCodeSame(200);
        self::assertSame([$this->karim->getId()], array_column($this->json()['cleared'], 'coachId'));
        self::assertSame([], $this->receipts($this->karim), 'prévenu « plus aucun », Karim n\'a plus de reçu');
    }

    /** Un envoi qui échoue n'écrit aucun reçu pour ce coach : rien ne prétend qu'il a reçu sa liste. */
    public function testAFailedSendWritesNoReceipt(): void
    {
        $this->aiglons();

        $this->postWithMailerFailingFor(['emma@aiglons.test']);
        self::assertResponseStatusCodeSame(200);

        \assert($this->emma instanceof Coach);
        self::assertSame([], $this->receipts($this->emma), 'l\'envoi à Emma a échoué : aucun reçu');
        self::assertNotSame([], $this->receipts($this->karim), 'témoin : Karim, servi, a son reçu');
    }

    /**
     * La réciproque de l'invariant est FAUSSE, et c'est voulu : Karim, en mémoire d'avant cette
     * fonction (aucun reçu), sauté faute d'adresse, reste en mémoire sans reçu.
     */
    public function testARememberedCoachSkippedWithoutAddressStaysWithoutReceipt(): void
    {
        $this->aiglons(karimEmail: null, soloKarim: true);
        $this->rememberedFromAnEarlierSend([$this->karim->getId()]);

        $this->post();
        self::assertResponseStatusCodeSame(200);
        self::assertSame('conflicts', $this->json()['skipped'][0]['kind'] ?? null);
        self::assertSame([$this->karim->getId()], $this->memory());
        self::assertSame([], $this->receipts($this->karim));
    }

    // ── Aperçu (GET preview) = ce que l'envoi fera ──

    /**
     * L'aperçu dit, coach par coach, ce que le POST enverra : mêmes destinataires, mêmes compteurs,
     * même nombre de mails — et le lire n'écrit rien (ni ligne, ni reçu, ni mail).
     */
    public function testThePreviewSaysExactlyWhatThePostThenSends(): void
    {
        $this->aiglons();

        $this->client->enableProfiler();
        $preview = $this->preview();
        self::assertResponseStatusCodeSame(200);
        self::assertQueuedEmailCount(0);
        self::assertSame(0, $this->mailingRowCount(), 'lire l\'aperçu n\'écrit rien');
        self::assertSame([], $this->receiptCoachIds());

        self::assertSame($this->season->getId(), $preview['seasonId'] ?? null);
        self::assertSame(1, $preview['personConflictCount'] ?? null);
        self::assertSame(2, $preview['defaultMailCount'] ?? null);
        self::assertSame(2, $preview['resendAllMailCount'] ?? null);
        self::assertNull(\array_key_exists('blockedByDefault', $preview) ? $preview['blockedByDefault'] : 'absent');
        $karim = $this->recipient($preview, $this->karim);
        self::assertSame(['Karim', 'Benali', 'karim@aiglons.test', true, 'conflicts', false, 1, 0, 0, 0, null, true, true], [
            $karim['firstName'], $karim['lastName'], $karim['email'], $karim['hasValidAddress'], $karim['kind'], $karim['receiptKnown'],
            $karim['conflictCount'], $karim['newCount'], $karim['changedCount'], $karim['alreadySentCount'], $karim['lastSentAt'],
            $karim['sendsByDefault'], $karim['sendsOnResendAll'],
        ]);

        $this->client->enableProfiler();
        $this->post();
        self::assertResponseStatusCodeSame(200);
        self::assertQueuedEmailCount($preview['defaultMailCount']);
        $project = static fn (array $r): array => [$r['coachId'], $r['conflictCount'], $r['newCount'] ?? 'absent', $r['changedCount'] ?? 'absent', $r['receiptKnown'] ?? 'absent'];
        $announced = array_map($project, array_values(array_filter($preview['recipients'], static fn (array $r): bool => true === $r['sendsByDefault'] && 'conflicts' === $r['kind'])));
        $sent = array_map($project, $this->json()['recipients']);
        sort($announced);
        sort($sent);
        self::assertSame($announced, $sent, 'parité aperçu/envoi : mêmes coachs, mêmes compteurs');
    }

    /** Rien en conflit, personne en mémoire : l'aperçu est vide et dit pourquoi rien ne partira. */
    public function testThePreviewOfNothingSaysNothing(): void
    {
        $this->aiglons(withConflict: false);

        $preview = $this->preview();
        self::assertResponseStatusCodeSame(200, 'aucun 422 « rien » sur l\'aperçu : une liste vide');
        self::assertSame([], $preview['recipients'] ?? null);
        self::assertSame(0, $preview['defaultMailCount'] ?? null);
        self::assertSame('nothing', $preview['blockedByDefault'] ?? null);
        self::assertSame('nothing', $preview['blockedOnResendAll'] ?? null);
    }

    /** Un coach informé dont le conflit a disparu figure dans l'aperçu (« plus aucun ») et part par défaut. */
    public function testThePreviewListsAClearedCoach(): void
    {
        $this->aiglons(withConflict: false);
        $this->rememberedFromAnEarlierSend([$this->karim->getId()]);

        $preview = $this->preview();
        self::assertResponseStatusCodeSame(200);
        $karim = $this->recipient($preview, $this->karim);
        self::assertSame('cleared', $karim['kind']);
        self::assertTrue($karim['sendsByDefault']);
        self::assertSame(1, $preview['defaultMailCount'] ?? null);
        self::assertNull(\array_key_exists('blockedByDefault', $preview) ? $preview['blockedByDefault'] : 'absent');
    }

    /** Personne n'a d'adresse : rien ne partira, ni par défaut ni en renvoyant tout — motif « no_address ». */
    public function testThePreviewSaysWhenNoCoachHasAnAddress(): void
    {
        $this->aiglons(karimEmail: null, emmaEmail: null);

        $preview = $this->preview();
        self::assertResponseStatusCodeSame(200);
        self::assertSame(0, $preview['defaultMailCount'] ?? null);
        self::assertSame(0, $preview['resendAllMailCount'] ?? null);
        self::assertSame('no_address', $preview['blockedByDefault'] ?? null);
        self::assertSame('no_address', $preview['blockedOnResendAll'] ?? null);
        $karim = $this->recipient($preview, $this->karim);
        self::assertNull($karim['email']);
        self::assertFalse($karim['hasValidAddress']);
        self::assertFalse($karim['sendsOnResendAll'], 'sans adresse valide, même « tout renvoyer » ne lui envoie rien');
    }

    /**
     * Après un envoi, sans changement : tout est « déjà envoyé », rien ne part par défaut (motif
     * « nothing_new ») mais « Tout renvoyer » renverrait les deux listes.
     */
    public function testAfterASendNothingIsNewButEverythingCanBeResent(): void
    {
        $this->aiglons();
        $this->post();
        self::assertResponseStatusCodeSame(200);

        $preview = $this->preview();
        self::assertResponseStatusCodeSame(200);
        self::assertSame(0, $preview['defaultMailCount'] ?? null);
        self::assertSame('nothing_new', $preview['blockedByDefault'] ?? null);
        self::assertSame(2, $preview['resendAllMailCount'] ?? null);
        self::assertNull(\array_key_exists('blockedOnResendAll', $preview) ? $preview['blockedOnResendAll'] : 'absent');
        $karim = $this->recipient($preview, $this->karim);
        self::assertTrue($karim['receiptKnown']);
        self::assertSame([0, 0, 1], [$karim['newCount'], $karim['changedCount'], $karim['alreadySentCount']]);
        self::assertFalse($karim['sendsByDefault']);
        self::assertTrue($karim['sendsOnResendAll'], '« Tout renvoyer » = toute adresse valide');
        self::assertNotFalse(DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, (string) $karim['lastSentAt']), 'lastSentAt en ATOM');
    }

    /**
     * Cas mixte : seul Karim a du nouveau, mais son adresse est invalide (« karim@ ») ; Emma n'a rien
     * de nouveau. L'aperçu dit « mixed » et montre l'adresse invalide telle quelle (à corriger sur place).
     */
    public function testThePreviewSaysMixedWhenOnlyAnUnreachableCoachHasSomethingNew(): void
    {
        $this->aiglons();
        $this->post();
        self::assertResponseStatusCodeSame(200);
        $this->replaceReceiptsWithADisappearedOne($this->karim);
        $this->setEmail($this->karim, 'karim@');

        $preview = $this->preview();
        self::assertResponseStatusCodeSame(200);
        self::assertSame(0, $preview['defaultMailCount'] ?? null);
        self::assertSame('mixed', $preview['blockedByDefault'] ?? null);
        self::assertSame(1, $preview['resendAllMailCount'] ?? null, 'en renvoyant tout, Emma recevrait');
        $karim = $this->recipient($preview, $this->karim);
        self::assertSame('karim@', $karim['email']);
        self::assertFalse($karim['hasValidAddress']);
        self::assertSame(1, $karim['newCount']);
    }

    // ── Envoi : « nouveau ou modifié » par défaut, « tout renvoyer » sur demande ──

    /** L'option « tout renvoyer » est un booléen : toute autre valeur est refusée, rien ne part. */
    public function testResendAllMustBeABoolean(): void
    {
        $this->aiglons();

        $this->client->enableProfiler();
        $this->postBody(['resendAll' => '1']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Option « tout renvoyer » invalide.', $this->json()['error'] ?? null);
        self::assertQueuedEmailCount(0);
        self::assertSame(0, $this->mailingRowCount());
    }

    /** Un second envoi sans aucun changement est refusé : rien de nouveau, aucun mail, la ligne « Envoyé le … » ne bouge pas. */
    public function testASecondSendWithoutChangeIsRefused(): void
    {
        $this->aiglons(soloKarim: true);
        $this->post();
        self::assertResponseStatusCodeSame(200);
        $stamp = $this->coachMailing()['last'];

        $this->client->enableProfiler();
        $this->post();
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Rien de nouveau depuis le dernier envoi : cochez « Tout renvoyer » pour renvoyer les listes.', $this->json()['error'] ?? null);
        self::assertQueuedEmailCount(0);
        self::assertSame($stamp, $this->coachMailing()['last'], 'la ligne « Envoyé le … » reste celle du premier envoi');
    }

    /**
     * Karim n'a rien de nouveau ET n'a plus d'adresse : il est écarté AVANT le tri des adresses — ni
     * servi, ni « sauté », ni nommé dans la ligne « Envoyé le … ». Emma non plus : refus « rien de nouveau ».
     */
    public function testACoachWithNothingNewIsNeitherSentNorSkipped(): void
    {
        $this->aiglons();
        $this->post();
        self::assertResponseStatusCodeSame(200);
        $this->setEmail($this->karim, null);

        $this->client->enableProfiler();
        $this->post();
        self::assertResponseStatusCodeSame(422, 'Karim et Emma n\'ont rien de nouveau : rien à envoyer, personne à sauter');
        self::assertSame('Rien de nouveau depuis le dernier envoi : cochez « Tout renvoyer » pour renvoyer les listes.', $this->json()['error'] ?? null);
        self::assertQueuedEmailCount(0);
        self::assertSame([], $this->coachMailing()['last']['skippedCoachIds'] ?? null, 'Karim n\'est jamais nommé « sans adresse »');
    }

    /**
     * Cas mixte : seul Karim a du nouveau et n'a pas d'adresse, Emma n'a rien de nouveau. Ce n'est pas
     * le refus « rien de nouveau » : 200, Karim nommé sauté, aucun mail, aucun reçu, ligne « Envoyé le … » intacte.
     */
    public function testWhenOnlyACoachWithoutAddressHasSomethingNewHeIsNamedAndNothingLeaves(): void
    {
        $this->aiglons();
        $this->post();
        self::assertResponseStatusCodeSame(200);
        $stamp = $this->coachMailing()['last'];
        $this->replaceReceiptsWithADisappearedOne($this->karim);
        $karimReceipts = $this->receipts($this->karim);
        $this->setEmail($this->karim, null);

        $this->client->enableProfiler();
        $this->post();
        self::assertResponseStatusCodeSame(200);
        self::assertQueuedEmailCount(0);
        $body = $this->json();
        self::assertNull($body['sentAt']);
        self::assertSame([], $body['recipients']);
        self::assertSame([], $body['cleared']);
        self::assertSame([$this->karim->getId()], array_column($body['skipped'], 'coachId'));
        self::assertSame($stamp, $this->coachMailing()['last'], 'aucun mail parti : la ligne « Envoyé le … » ne bouge pas');
        self::assertSame($karimReceipts, $this->receipts($this->karim), 'aucun reçu écrit');
    }

    /** « Tout renvoyer » : sans rien de nouveau, chaque liste repart et dit qu'aucun de ses conflits n'est nouveau ou modifié. */
    public function testResendAllSendsEveryListAgain(): void
    {
        $this->aiglons(soloKarim: true);
        $this->post();
        self::assertResponseStatusCodeSame(200);

        $this->client->enableProfiler();
        $this->postBody(['resendAll' => true]);
        self::assertResponseStatusCodeSame(200);
        self::assertQueuedEmailCount(1);
        self::assertTrue($this->json()['resendAll'] ?? null);
        self::assertMatchesRegularExpression(
            '/Cette liste vous est renvoyée : aucun conflit de cette liste n\'est nouveau ou modifié depuis l\'envoi du \d{2}\/\d{2}\/\d{4}\./u',
            (string) $this->queuedEmails()[0]->getTextBody(),
        );
    }

    /** Le match de SM2 est décalé de 16:00 à 16:30 : même conflit, contenu différent — Karim reçoit sa liste, le conflit marqué « Modifié ». */
    public function testAMovedMatchIsSentAgainMarkedChanged(): void
    {
        $this->aiglons(soloKarim: true);
        $this->post();
        self::assertResponseStatusCodeSame(200);
        $before = $this->personConflictFingerprints();

        $this->moveSm2Kickoff('16:30');
        self::assertSame($before, $this->personConflictFingerprints(), 'témoin : même conflit (même empreinte)');

        $this->client->enableProfiler();
        $this->post();
        self::assertResponseStatusCodeSame(200);
        self::assertQueuedEmailCount(1);
        $text = (string) $this->queuedEmails()[0]->getTextBody();
        self::assertStringContainsString(' · Modifié', $text);
        self::assertMatchesRegularExpression('/Depuis le dernier envoi du \d{2}\/\d{2}\/\d{4}, 1 conflit est nouveau ou modifié : il est marqué\./u', $text);
        $body = $this->json();
        self::assertFalse($body['resendAll'] ?? null);
        self::assertSame([1, 0, true], [$body['recipients'][0]['changedCount'] ?? null, $body['recipients'][0]['newCount'] ?? null, $body['recipients'][0]['receiptKnown'] ?? null]);
    }

    /**
     * Karim avait reçu plusieurs conflits ; celui de SM2 a disparu (match avancé au dimanche), celui
     * des deux matchs de 15:30 (U15, U11) est inchangé. Un conflit disparu n'est pas une nouveauté :
     * rien ne part (refus « rien de nouveau »), et Karim, toujours en conflit, n'est pas « plus aucun ».
     */
    public function testADisappearedConflictAloneSendsNothing(): void
    {
        $this->aiglons(soloKarim: true);
        $this->u11CoachedByKarimAtTheSameTime();
        $sent = $this->personConflictFingerprints();
        self::assertGreaterThanOrEqual(2, \count($sent), 'témoin : Karim a plusieurs conflits — servis : ' . implode(', ', $sent));
        $this->post();
        self::assertResponseStatusCodeSame(200);

        $this->removeTheConflict();
        $left = $this->personConflictFingerprints();
        self::assertCount(1, $left, 'témoin : un seul conflit reste — servis : ' . implode(', ', $left));
        self::assertContains($left[0], $sent, 'témoin : le conflit restant est l\'un de ceux déjà envoyés');
        $this->client->enableProfiler();
        $this->post();
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Rien de nouveau depuis le dernier envoi : cochez « Tout renvoyer » pour renvoyer les listes.', $this->json()['error'] ?? null);
        self::assertQueuedEmailCount(0);
    }

    /** Un coach en mémoire d'avant cette fonction (aucun reçu) reçoit sa liste une fois, sans aucune marque. */
    public function testARememberedCoachWithoutReceiptIsSentHisListUnmarked(): void
    {
        $this->aiglons(soloKarim: true);
        $this->rememberedFromAnEarlierSend([$this->karim->getId()]);

        $this->client->enableProfiler();
        $this->post();
        self::assertResponseStatusCodeSame(200);
        self::assertQueuedEmailCount(1);
        $text = (string) $this->queuedEmails()[0]->getTextBody();
        foreach (['Nouveau', 'Modifié', 'Depuis le dernier envoi', 'Cette liste vous est renvoyée'] as $absent) {
            self::assertStringNotContainsString($absent, $text);
        }
        self::assertSame([false, 0, 0], [$this->json()['recipients'][0]['receiptKnown'] ?? null, $this->json()['recipients'][0]['newCount'] ?? null, $this->json()['recipients'][0]['changedCount'] ?? null]);
    }

    /**
     * Le conflit est déjà parti à Emma ; Karim, sans adresse à ce moment, ne l'a jamais reçu. Adresse
     * remise : seul Karim reçoit — Emma n'a rien de nouveau.
     */
    public function testAConflictAlreadySentToEmmaGoesToKarimAlone(): void
    {
        $this->aiglons(karimEmail: null);
        $this->post();
        self::assertResponseStatusCodeSame(200);
        self::assertSame([$this->emma?->getId()], array_column($this->json()['recipients'], 'coachId'), 'témoin : Emma servie au premier envoi');

        $this->setEmail($this->karim, 'karim@aiglons.test');
        $this->client->enableProfiler();
        $this->post();
        self::assertResponseStatusCodeSame(200);
        self::assertQueuedEmailCount(1);
        self::assertSame('karim@aiglons.test', $this->queuedEmails()[0]->getTo()[0]->getAddress());
        self::assertSame([$this->karim->getId()], array_column($this->json()['recipients'], 'coachId'));
        self::assertSame([], $this->json()['skipped']);
    }

    // ── Dépôt des reçus : une connexion, un club ──

    /** Remplacer les reçus d'un coach garde UNE ligne par empreinte (la dernière) et ne touche jamais un autre coach. */
    public function testReplacingACoachsReceiptsKeepsOneRowPerFingerprint(): void
    {
        $this->aiglons();
        \assert($this->emma instanceof Coach);
        $repository = self::getContainer()->get(ConflictMailReceiptRepository::class);
        $this->scopeGucToClub($this->club->getId());
        $repository->replaceForCoach($this->club->getId(), $this->season->getId(), $this->emma->getId(), ['MATCH_MATCH:e' => sha1('e')], new DateTimeImmutable('2026-11-03 14:32:00'));
        $repository->replaceForCoach($this->club->getId(), $this->season->getId(), $this->karim->getId(), ['MATCH_MATCH:a' => sha1('a1'), 'MATCH_MATCH:b' => sha1('b')], new DateTimeImmutable('2026-11-03 14:32:00'));
        $repository->replaceForCoach($this->club->getId(), $this->season->getId(), $this->karim->getId(), ['MATCH_MATCH:a' => sha1('a2')], new DateTimeImmutable('2026-11-10 09:00:00'));

        self::assertSame(['MATCH_MATCH:a' => sha1('a2')], $this->receipts($this->karim), 'remplacement : la liste COURANTE seule, digest à jour');
        self::assertSame(['MATCH_MATCH:e' => sha1('e')], $this->receipts($this->emma), 'Emma n\'est pas touchée');

        $map = $repository->mapBySeason($this->season->getId());
        self::assertSame(sha1('a2'), $map[$this->karim->getId()]['MATCH_MATCH:a']['digest'] ?? null);
        self::assertEquals(new DateTimeImmutable('2026-11-10 09:00:00'), $map[$this->karim->getId()]['MATCH_MATCH:a']['sentAt'] ?? null);
    }

    /** Supprimer les reçus d'une liste VIDE de coachs ne fait rien (jamais un `NOT IN ()` qui viderait tout) ; d'un coach, seulement les siens. */
    public function testDeletingReceiptsForCoachesTouchesOnlyThem(): void
    {
        $this->aiglons();
        \assert($this->emma instanceof Coach);
        $repository = self::getContainer()->get(ConflictMailReceiptRepository::class);
        $this->scopeGucToClub($this->club->getId());
        foreach ([$this->karim, $this->emma] as $coach) {
            $repository->replaceForCoach($this->club->getId(), $this->season->getId(), $coach->getId(), ['MATCH_MATCH:x' => sha1('x')], new DateTimeImmutable('2026-11-03 14:32:00'));
        }

        $repository->deleteForCoaches($this->season->getId(), []);
        self::assertEqualsCanonicalizing([$this->karim->getId(), $this->emma->getId()], $this->receiptCoachIds(), 'liste vide : rien n\'est supprimé');

        $repository->deleteForCoaches($this->season->getId(), [$this->karim->getId()]);
        self::assertSame([$this->emma->getId()], $this->receiptCoachIds());
    }

    /**
     * Le dépôt lit et écrit sur LA connexion de l'EntityManager, celle où le club est posé : sous le
     * club A, la lecture d'une saison du club B ne rend jamais un reçu de B (RLS).
     */
    public function testTheReceiptStoreNeverReadsAnotherClub(): void
    {
        $this->aiglons();
        $clubA = $this->club;
        $repository = self::getContainer()->get(ConflictMailReceiptRepository::class);

        $this->aiglons();
        $seasonB = $this->season;
        $this->scopeGucToClub($this->club->getId());
        $repository->replaceForCoach($this->club->getId(), $seasonB->getId(), $this->karim->getId(), ['MATCH_MATCH:b' => sha1('b')], new DateTimeImmutable('2026-11-03 14:32:00'));
        self::assertArrayHasKey($this->karim->getId(), $repository->mapBySeason($seasonB->getId()), 'témoin : sous B, le reçu de B est lu');

        $this->scopeGucToClub($clubA->getId());
        self::assertSame([], $repository->mapBySeason($seasonB->getId()), 'sous A, aucun reçu de B');
    }

    // ── Abus et ordre des refus ──

    /** Un Membre ne peut pas envoyer : 403, aucun mail, aucune ligne. */
    public function testMemberIsRefused(): void
    {
        $this->aiglons();
        $member = $this->member('member');

        $this->client->enableProfiler();
        $this->post(as: $member);
        self::assertResponseStatusCodeSame(403);
        self::assertQueuedEmailCount(0);
        self::assertSame(0, $this->mailingRowCount());

        // L'aperçu sert noms et adresses des coachs : fermé au Membre comme l'envoi.
        $this->preview(as: $member);
        self::assertResponseStatusCodeSame(403, 'l\'aperçu de l\'envoi reste réservé au gestionnaire');
    }

    /** Un club de démonstration n'e-maille jamais un tiers : 403, message nommé, aucun mail. */
    public function testDemoClubNeverMails(): void
    {
        $this->aiglons();
        $this->scopeGucToClub($this->club->getId());
        $this->club->setIsDemo(true);
        $this->em->flush();

        $this->client->enableProfiler();
        $this->post();
        self::assertResponseStatusCodeSame(403);
        self::assertSame('Envoi d\'e-mails indisponible en démonstration.', $this->json()['error'] ?? null);
        self::assertQueuedEmailCount(0);

        $this->preview();
        self::assertResponseStatusCodeSame(403, 'l\'aperçu est refusé à la démo, comme l\'envoi');
        self::assertSame('Envoi d\'e-mails indisponible en démonstration.', $this->json()['error'] ?? null);
    }

    /** Sans planning de saison choisi, rien ne part : 409 du garde du socle, lu en `detail`. */
    public function testNoSocleIsRefused(): void
    {
        $this->aiglons(withSocle: false);

        $this->client->enableProfiler();
        $this->post();
        self::assertResponseStatusCodeSame(409);
        self::assertSame(SocleGuard::SEASON_PLAN_REQUIRED_MESSAGE, $this->json()['detail'] ?? null);
        self::assertQueuedEmailCount(0);

        $this->preview();
        self::assertResponseStatusCodeSame(409, 'l\'aperçu refuse comme l\'envoi : pas de socle, pas d\'aperçu');
        self::assertSame(SocleGuard::SEASON_PLAN_REQUIRED_MESSAGE, $this->json()['detail'] ?? null);
    }

    /** Saison archivée : 409 en lecture seule, AVANT le contrôle de rôle (même pour un Membre). */
    public function testArchivedSeasonIsReadonly(): void
    {
        $this->aiglons();
        $member = $this->member('member');
        $this->scopeGucToClub($this->club->getId());
        $this->season->setStatus(SeasonStatus::ARCHIVED);
        $this->em->flush();

        $this->client->enableProfiler();
        $this->post();
        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('lecture seule', (string) ($this->json()['detail'] ?? ''));
        self::assertQueuedEmailCount(0);

        $this->post(as: $member);
        self::assertResponseStatusCodeSame(409, 'le 409 du noyau passe avant le 403 du contrôleur');

        // Saison gelée À LA MAIN : l'aperçu refuse au même rang que l'envoi (409 avant le 403) —
        // sinon il répondrait 200 sur une saison en lecture seule.
        $this->preview();
        self::assertResponseStatusCodeSame(409);
        self::assertSame('Cette saison est archivée — elle est en lecture seule.', $this->json()['detail'] ?? null);
        $this->preview(as: $member);
        self::assertResponseStatusCodeSame(409, 'saison gelée : le 409 de l\'aperçu passe avant le 403 du Membre');
    }

    /**
     * Saison archivée PAR LE CALENDRIER (une saison plus récente est en cours) : le garde du noyau
     * refuse l'aperçu (GET compris) comme l'envoi, 409 avant le 403, même pour un Membre.
     */
    public function testACalendarArchivedSeasonRefusesThePreviewBeforeTheRole(): void
    {
        $this->aiglons();
        $member = $this->member('member');
        $this->scopeGucToClub($this->club->getId());
        $past = $this->newSeason(SeasonResolver::seasonYear(new DateTimeImmutable('today')) - 1, false);

        $this->preview(season: $past);
        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('lecture seule', (string) ($this->json()['detail'] ?? ''));
        $this->preview(season: $past, as: $member);
        self::assertResponseStatusCodeSame(409, 'le 409 du noyau passe avant le 403 du contrôleur, aperçu compris');
    }

    /** Aucune saison résolue : 422 « Aucune saison sélectionnée. ». */
    public function testNoSeasonIs422(): void
    {
        $this->aiglons(withSeason: false);

        $this->post();
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Aucune saison sélectionnée.', $this->json()['error'] ?? null);

        $this->preview();
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Aucune saison sélectionnée.', $this->json()['error'] ?? null);
    }

    /** Rien à envoyer : 422, et le verrou d'envoi est relâché (un envoi suivant le prend). */
    public function testNothingToSendIs422AndReleasesTheLock(): void
    {
        $this->aiglons(withConflict: false);

        $this->post();
        self::assertResponseStatusCodeSame(422);
        $this->assertTheLockIsFree();
    }

    /**
     * Plafond : 2 listes + 1 « plus aucun » = 3 mails sur un plafond de test de 2 → 429, AUCUN mail,
     * mémoire intacte, verrou relâché. Le lot est refusé en bloc, jamais tronqué.
     */
    public function testQuotaRefusesWholeBatch(): void
    {
        $this->aiglons();
        $lea = $this->coach('Léa', 'Roux', 'lea@aiglons.test');
        $this->rememberedFromAnEarlierSend([$lea->getId()]);

        $this->client->enableProfiler();
        $this->post();
        self::assertResponseStatusCodeSame(429);
        self::assertSame('Limite d\'envoi atteinte (200 e-mails / 24 h), réessayez demain.', $this->json()['error'] ?? null);
        self::assertQueuedEmailCount(0);
        self::assertSame([$lea->getId()], $this->memory());
        $this->assertTheLockIsFree();
    }

    /** Un envoi déjà en cours pour le club : le second clic reçoit 409 (jamais un 500), aucun mail ; libéré, l'envoi passe. */
    public function testConcurrentSendIsRefusedOnce(): void
    {
        $this->aiglons();
        $lock = self::getContainer()->get(ConflictMailingLock::class);
        $token = $lock->acquire($this->club->getId(), 60);
        self::assertNotNull($token);

        try {
            $this->client->enableProfiler();
            $this->post();
            self::assertResponseStatusCodeSame(409);
            self::assertSame('Un envoi est déjà en cours, réessayez dans un instant.', $this->json()['error'] ?? null);
            self::assertQueuedEmailCount(0);

            // L'aperçu ne prend pas le verrou d'envoi : il reste lisible pendant un envoi.
            $this->preview();
            self::assertResponseStatusCodeSame(200, 'l\'aperçu ne dépend pas du verrou d\'envoi');
        } finally {
            self::getContainer()->get(ConflictMailingLock::class)->release($this->club->getId(), $token);
        }

        $this->post();
        self::assertResponseStatusCodeSame(200);
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        // Le radar tait les matchs passés (date civile du club) : la rencontre du décor (04/10)
        // reste à venir quoi que dise l'horloge murale.
        self::getContainer()->get(DevClockStore::class)->set(new DateTimeImmutable('2026-09-01 10:00:00'));
    }

    protected function tearDown(): void
    {
        // Redis n'est pas rendu par dama : on relâche l'épingle quoi qu'il arrive.
        self::getContainer()->get(DevClockStore::class)->set(null);
        parent::tearDown();
    }

    // ── Décor ──

    /**
     * Les Aiglons, saison courante, socle choisi. Avec conflit : U15 (Karim principal, Sam adjoint)
     * reçoit samedi 04/10 à 15:30, SM2 (Emma principale, Karim joueur) à 16:00. `soloKarim` : Karim
     * est principal des deux équipes et seul concerné (pas d'Emma).
     */
    private function aiglons(
        bool $withConflict = true,
        ?string $karimEmail = 'karim@aiglons.test',
        ?string $emmaEmail = 'emma@aiglons.test',
        bool $soloKarim = false,
        bool $withSocle = true,
        bool $withSeason = true,
    ): void {
        $uid = uniqid('', true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $this->club = new Club()->setName('Les Aiglons ' . $uid)->setSlug('aiglons-' . $uid)->setTimezone('Europe/Paris')->setLocale('fr')->setOnboardingCompleted(true);
        $this->club->setFfbbClubCode(strtoupper(substr(md5($uid), 0, 3)) . strtoupper(substr(md5($uid), 3, 10)));
        $this->em->persist($this->club);
        $this->nadia = new User()->setEmail('nadia' . $uid . '@test.com')->setFirstName('Nadia')->setLastName('Gestion');
        $this->nadia->setPasswordHash($hasher->hashPassword($this->nadia, 'pass'));
        $this->em->persist($this->nadia);
        $this->em->flush();

        $this->scopeGucToClub($this->club->getId());
        $this->em->persist(new ClubUser()->setClubId($this->club->getId())->setUserId($this->nadia->getId())->setRole('admin')->setIsActive(true));
        if (!$withSeason) {
            $this->em->flush();

            return;
        }
        $this->season = $this->newSeason(SeasonResolver::seasonYear(new DateTimeImmutable('today')), $withSocle);

        $this->karim = $this->coach('Karim', 'Benali', $karimEmail);
        $this->emma = $soloKarim ? null : $this->coach('Emma', 'Durand', $emmaEmail);
        $this->sam = $this->coach('Sam', 'Adjoint', 'sam@aiglons.test');
        $u15 = $this->team('U15');
        $sm2 = $this->team('SM2');
        $this->staff($u15, $this->karim, TeamCoachRole::MAIN);
        $this->staff($u15, $this->sam, TeamCoachRole::ASSISTANT);
        if ($soloKarim) {
            $this->staff($sm2, $this->karim, TeamCoachRole::MAIN);
        } else {
            \assert($this->emma instanceof Coach);
            $this->staff($sm2, $this->emma, TeamCoachRole::MAIN);
            $this->em->persist(new CoachPlayerMembership()->setClubId($this->club->getId())->setSeasonId($this->season->getId())->setCoachId($this->karim->getId())->setTeamId($sm2->getId())->setIsActive(true));
        }
        $this->em->flush();

        if ($withConflict) {
            $this->homeMatch($u15, '2026-10-04', '15:30', 'Les Loups');
            $this->sm2FixtureId = $this->homeMatch($sm2, '2026-10-04', '16:00', 'Les Ours');
        }
    }

    private function newSeason(int $year, bool $withSocle): Season
    {
        $season = new Season()->setClubId($this->club->getId())->setName($year . '-' . ($year + 1))
            ->setStartDate(new DateTimeImmutable($year . '-08-01'))->setEndDate(new DateTimeImmutable(($year + 1) . '-07-15'))
            ->setStatus(SeasonStatus::ACTIVE)->setTransitionData([]);
        $this->em->persist($season);
        $this->em->flush();
        if ($withSocle) {
            $this->settleSeasonPlan($season);
        }

        return $season;
    }

    /** La saison suivante du même club, socle choisi, où un AUTRE Karim (coachs par saison) est seul concerné. */
    private function nextSeasonWithItsOwnConflict(): Season
    {
        $this->scopeGucToClub($this->club->getId());
        $this->season = $this->newSeason(SeasonResolver::seasonYear(new DateTimeImmutable('today')) + 1, true);
        $this->karim = $this->coach('Karim', 'Benali', 'karim.n1@aiglons.test');
        $u15 = $this->team('U15');
        $sm2 = $this->team('SM2');
        $this->staff($u15, $this->karim, TeamCoachRole::MAIN);
        $this->staff($sm2, $this->karim, TeamCoachRole::MAIN);
        $this->em->flush();
        $year = SeasonResolver::seasonYear(new DateTimeImmutable('today')) + 1;
        $this->homeMatch($u15, $year . '-10-03', '15:30', 'Les Loups');
        $this->homeMatch($sm2, $year . '-10-03', '16:00', 'Les Ours');

        return $this->season;
    }

    private function coach(string $first, string $last, ?string $email): Coach
    {
        $this->scopeGucToClub($this->club->getId());
        $coach = new Coach()->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setFirstName($first)->setLastName($last)->setEmail($email)->setIsActive(true);
        $this->em->persist($coach);
        $this->em->flush();

        return $coach;
    }

    private function team(string $name): Team
    {
        $this->scopeGucToClub($this->club->getId());
        $sport = $this->em->getRepository(Sport::class)->findOneBy(['isActive' => true]);
        if (!$sport instanceof Sport) {
            $uid = uniqid('', true);
            $sport = new Sport;
            $sport->setName('Basket ' . $uid);
            $sport->setSlug('basket-' . $uid);
            $sport->setIsActive(true);
            $this->em->persist($sport);
        }
        $category = new SportCategory;
        $category->setClubId($this->club->getId());
        $category->setSportId($sport->getId());
        $category->setName($name . '-' . uniqid('', true));
        $this->em->persist($category);

        $team = new Team()->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setSportCategoryId($category->getId())->setPriorityTierId(3)->setName($name)->setSessionsPerWeek(2)->setIsActive(true);
        $this->em->persist($team);
        $this->em->flush();

        return $team;
    }

    private function staff(Team $team, Coach $coach, TeamCoachRole $role): void
    {
        $this->em->persist(new TeamCoach()->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setTeamId($team->getId())->setCoachId($coach->getId())->setRole($role));
    }

    private function homeMatch(Team $team, string $date, string $kickoff, string $opponent): string
    {
        $this->scopeGucToClub($this->club->getId());
        $fixture = new Fixture;
        $fixture->setClubId($this->club->getId());
        $fixture->setSeasonId($this->season->getId());
        $fixture->setTeamId($team->getId());
        $fixture->setMatchDate(new DateTimeImmutable($date));
        $fixture->setHomeAway(FixtureHomeAway::HOME);
        $fixture->setOpponentLabel($opponent);
        $fixture->setKickoffTime(DateTimeImmutable::createFromFormat('!H:i', $kickoff) ?: null);
        $this->em->persist($fixture);
        $this->em->flush();

        return $fixture->getId();
    }

    /** Le match de SM2 est avancé au dimanche matin : plus aucun chevauchement (geste réel : la rencontre bouge). */
    private function removeTheConflict(): void
    {
        $this->scopeGucToClub($this->club->getId());
        $this->em->getConnection()->executeStatement(
            'UPDATE fixture SET match_date = :d, kickoff_time = :t WHERE id = :id',
            ['d' => '2026-10-05', 't' => '09:00:00', 'id' => $this->sm2FixtureId],
        );
    }

    /** Le match de SM2 change d'heure le même jour (geste réel : la rencontre est décalée) — même conflit, contenu différent. */
    private function moveSm2Kickoff(string $kickoff): void
    {
        $this->scopeGucToClub($this->club->getId());
        $this->em->getConnection()->executeStatement('UPDATE fixture SET kickoff_time = :t WHERE id = :id', ['t' => $kickoff . ':00', 'id' => $this->sm2FixtureId]);
    }

    /** U11, que Karim coache aussi, reçoit samedi 04/10 à 15:30 : deux conflits de plus pour Karim (avec U15 et SM2). */
    private function u11CoachedByKarimAtTheSameTime(): void
    {
        $u11 = $this->team('U11');
        $this->staff($u11, $this->karim, TeamCoachRole::MAIN);
        $this->em->flush();
        $this->homeMatch($u11, '2026-10-04', '15:30', 'Les Lynx');
    }

    /**
     * Pointage : les reçus de ce coach sont remplacés par UN reçu d'un conflit qui n'existe plus —
     * il garde un envoi connu, et ses conflits courants deviennent « nouveaux » pour lui.
     */
    private function replaceReceiptsWithADisappearedOne(Coach $coach): void
    {
        $this->scopeGucToClub($this->club->getId());
        $connection = $this->em->getConnection();
        $connection->executeStatement('DELETE FROM conflict_mail_receipt WHERE coach_id = :c', ['c' => $coach->getId()]);
        $connection->executeStatement(
            'INSERT INTO conflict_mail_receipt (id, club_id, season_id, fingerprint, coach_id, content_digest, sent_at)
             VALUES (:id, :club, :season, :fingerprint, :coach, :digest, :sentAt)',
            [
                'id' => Uuid::v4()->toRfc4122(),
                'club' => $this->club->getId(),
                'season' => $this->season->getId(),
                'fingerprint' => 'MATCH_MATCH:' . $coach->getId() . ':disparu',
                'coach' => $coach->getId(),
                'digest' => sha1('disparu'),
                'sentAt' => '2026-08-25 18:00:00+00',
            ],
        );
    }

    /** @return array<string, string> les reçus du coach en base : empreinte → digest, triés par empreinte */
    private function receipts(Coach $coach): array
    {
        $this->scopeGucToClub($this->club->getId());
        $rows = $this->em->getConnection()->fetchAllKeyValue(
            'SELECT fingerprint, content_digest FROM conflict_mail_receipt WHERE coach_id = :c AND season_id = :s ORDER BY fingerprint',
            ['c' => $coach->getId(), 's' => $this->season->getId()],
        );

        /* @var array<string, string> $rows */
        return $rows;
    }

    /** @return list<string> les coachs qui détiennent au moins un reçu pour la saison, triés */
    private function receiptCoachIds(): array
    {
        $this->scopeGucToClub($this->club->getId());
        /** @var list<string> $ids */
        $ids = $this->em->getConnection()->fetchFirstColumn(
            'SELECT DISTINCT coach_id FROM conflict_mail_receipt WHERE season_id = :s ORDER BY coach_id',
            ['s' => $this->season->getId()],
        );
        sort($ids);

        return $ids;
    }

    /** @return list<string> les empreintes des conflits de personne servis par le GET conflits (ceux que l'envoi considère), triées */
    private function personConflictFingerprints(): array
    {
        $this->client->request('GET', '/api/fixtures/conflicts', [], [], $this->headers($this->nadia, null));
        self::assertResponseStatusCodeSame(200);
        $fingerprints = [];
        foreach ($this->json()['conflicts'] ?? [] as $conflict) {
            if (\in_array($conflict['type'] ?? null, ConflictCoachMailing::MAILED_FAMILIES, true) && !isset($conflict['heldBy'])) {
                $fingerprints[] = (string) $conflict['fingerprint'];
            }
        }
        sort($fingerprints);

        return array_values(array_unique($fingerprints));
    }

    private function setEmail(Coach $coach, ?string $email): void
    {
        $this->scopeGucToClub($this->club->getId());
        $this->em->getConnection()->executeStatement('UPDATE coach SET email = :e WHERE id = :id', ['e' => $email, 'id' => $coach->getId()]);
    }

    private function member(string $role): User
    {
        $hasher = self::getContainer()->get('security.user_password_hasher');
        $uid = uniqid($role, true);
        $user = new User()->setEmail($role . $uid . '@test.com')->setFirstName('Paul')->setLastName('Membre');
        $user->setPasswordHash($hasher->hashPassword($user, 'pass'));
        $this->em->persist($user);
        $this->scopeGucToClub($this->club->getId());
        $this->em->persist(new ClubUser()->setClubId($this->club->getId())->setUserId($user->getId())->setRole($role)->setIsActive(true));
        $this->em->flush();

        return $user;
    }

    /**
     * Pointage : la mémoire d'un envoi précédent (mardi), posée directement — le plafond de test (2
     * e-mails par club et par jour) ne laisse pas rejouer l'envoi d'origine dans le même scénario.
     * Colonnes du contrat de la table `conflict_mailing` (plan D9).
     *
     * @param list<string> $coachIds
     */
    private function rememberedFromAnEarlierSend(array $coachIds): void
    {
        $this->scopeGucToClub($this->club->getId());
        $this->em->getConnection()->executeStatement(
            'INSERT INTO conflict_mailing (id, club_id, season_id, sent_at, sent_by_user_id, recipient_coach_ids, skipped_coach_ids, mailed_count)
             VALUES (:id, :club, :season, :sentAt, :by, :recipients, :skipped, :mailed)',
            [
                'id' => Uuid::v4()->toRfc4122(),
                'club' => $this->club->getId(),
                'season' => $this->season->getId(),
                'sentAt' => '2026-08-25 18:00:00+00',
                'by' => $this->nadia->getId(),
                'recipients' => json_encode($coachIds, \JSON_THROW_ON_ERROR),
                'skipped' => '[]',
                'mailed' => \count($coachIds),
            ],
        );
    }

    /** @return list<string> la mémoire D3 lue en base pour la saison */
    private function memory(?Season $season = null): array
    {
        $this->scopeGucToClub($this->club->getId());
        $raw = $this->em->getConnection()->fetchOne(
            'SELECT recipient_coach_ids FROM conflict_mailing WHERE season_id = :s',
            ['s' => ($season ?? $this->season)->getId()],
        );
        if (false === $raw || null === $raw) {
            return [];
        }
        $ids = json_decode((string) $raw, true, 512, \JSON_THROW_ON_ERROR);
        \assert(\is_array($ids));
        sort($ids);

        /* @var list<string> $ids */
        return $ids;
    }

    private function mailingRowCount(): int
    {
        $this->scopeGucToClub($this->club->getId());

        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM conflict_mailing WHERE club_id = :c', ['c' => $this->club->getId()]);
    }

    private function assertTheLockIsFree(): void
    {
        $lock = self::getContainer()->get(ConflictMailingLock::class);
        $token = $lock->acquire($this->club->getId(), 60);
        self::assertNotNull($token, 'le verrou d\'envoi doit être relâché sur tout chemin');
        $lock->release($this->club->getId(), $token);
    }

    // ── HTTP ──

    private function post(?Season $season = null, ?User $as = null): void
    {
        $this->client->request('POST', self::URL, [], [], $this->headers($as ?? $this->nadia, $season), '');
    }

    /** @param array<string, mixed> $body */
    private function postBody(array $body, ?User $as = null): void
    {
        $this->client->request('POST', self::URL, [], [], $this->headers($as ?? $this->nadia, null), json_encode($body, \JSON_THROW_ON_ERROR));
    }

    /**
     * `GET …/mail-coaches/preview` — le statut se lit après (`assertResponseStatusCodeSame`).
     *
     * @return array<string, mixed> le corps décodé (vide si ce n'est pas un objet JSON)
     */
    private function preview(?Season $season = null, ?User $as = null): array
    {
        $this->client->request('GET', self::PREVIEW_URL, [], [], $this->headers($as ?? $this->nadia, $season));
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true);

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $preview
     *
     * @return array<string, mixed> la ligne de l'aperçu pour ce coach
     */
    private function recipient(array $preview, Coach $coach): array
    {
        foreach (\is_array($preview['recipients'] ?? null) ? $preview['recipients'] : [] as $row) {
            if (\is_array($row) && $coach->getId() === ($row['coachId'] ?? null)) {
                return $row;
            }
        }
        self::fail(\sprintf('%s absent de l\'aperçu (%s)', $coach->getFirstName(), mb_substr((string) json_encode($preview), 0, 300)));
    }

    /**
     * Le même POST avec un mailer doublé qui LÈVE pour les adresses données (l'échec d'enfilage
     * d'un mail) et note les autres. Noyau neuf : le double doit être posé avant la requête.
     *
     * @param list<string> $failFor
     *
     * @return MailerInterface&object{sentTo: list<string>}
     */
    private function postWithMailerFailingFor(array $failFor): MailerInterface
    {
        $headers = $this->headers($this->nadia, null);
        self::ensureKernelShutdown();
        $this->client = self::createClient();
        self::getContainer()->get(DevClockStore::class)->set(new DateTimeImmutable('2026-09-01 10:00:00'));
        $mailer = new class($failFor) implements MailerInterface {
            /** @var list<string> */
            public array $sentTo = [];

            /** @param list<string> $failFor */
            public function __construct(private readonly array $failFor) {}

            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                \assert($message instanceof Email);
                $to = $message->getTo()[0]->getAddress();
                if (\in_array($to, $this->failFor, true)) {
                    throw new RuntimeException('enfilage impossible');
                }
                $this->sentTo[] = $to;
            }
        };
        self::getContainer()->set('mailer.mailer', $mailer);
        $this->client->request('POST', self::URL, [], [], $headers, '');
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        return $mailer;
    }

    /** @return array<string, mixed> le champ `coachMailing` du GET conflits */
    private function coachMailing(): array
    {
        $this->client->request('GET', '/api/fixtures/conflicts', [], [], $this->headers($this->nadia, null));
        self::assertResponseStatusCodeSame(200);
        $mailing = $this->json()['coachMailing'] ?? null;
        self::assertIsArray($mailing, 'le GET sert toujours coachMailing');

        return $mailing;
    }

    /** @return list<Email> */
    private function queuedEmails(): array
    {
        return array_values(array_filter(self::getMailerMessages(), static fn (RawMessage $m): bool => $m instanceof Email));
    }

    /** @return array<string, string> */
    private function headers(User $user, ?Season $season): array
    {
        $headers = ['HTTP_AUTHORIZATION' => 'Bearer ' . self::getContainer()->get(JWTTokenManagerInterface::class)->create($user), 'CONTENT_TYPE' => 'application/json'];
        $season ??= $this->season ?? null;
        if ($season instanceof Season) {
            $headers['HTTP_X-Season-Id'] = $season->getId();
        }

        return $headers;
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
