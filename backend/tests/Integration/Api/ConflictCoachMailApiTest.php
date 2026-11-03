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
use App\Service\ConflictMailingLock;
use App\Service\SeasonResolver;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\TenantGucTrait;
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

    /** Le GET compte ce que le POST envoie (même service), puis sert la ligne « Envoyé le … par Nadia — 2 e-mails ». */
    public function testTheGetCountsWhatThePostSendsThenShowsTheStamp(): void
    {
        $this->aiglons();

        $before = $this->coachMailing();
        self::assertSame(1, $before['personConflictCount'], 'un seul conflit de personne : les niveaux manquants ne comptent pas');
        self::assertSame(0, $before['pendingRecipientCount']);
        self::assertNull($before['last'], 'aucun envoi : pas de ligne « Envoyé le … »');

        $this->post();
        self::assertResponseStatusCodeSame(200);
        $sent = $this->json();
        self::assertSame($before['personConflictCount'], $sent['conflictCount'], 'parité GET/POST');

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
    }

    /** Sans planning principal choisi, rien ne part : 409 du garde du socle, lu en `detail`. */
    public function testNoSocleIsRefused(): void
    {
        $this->aiglons(withSocle: false);

        $this->client->enableProfiler();
        $this->post();
        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('Validez le planning principal', (string) ($this->json()['detail'] ?? ''));
        self::assertQueuedEmailCount(0);
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
    }

    /** Aucune saison résolue : 422 « Aucune saison sélectionnée. ». */
    public function testNoSeasonIs422(): void
    {
        $this->aiglons(withSeason: false);

        $this->post();
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
