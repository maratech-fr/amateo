<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Coach;
use App\Entity\CoachWishCampaign;
use App\Entity\CoachWishToken;
use App\Entity\Season;
use App\Entity\Team;
use App\Entity\TeamCoach;
use App\Entity\User;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\SeasonStatus;
use App\Enum\TeamCoachRole;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * P4-316 — chemins d'ABUS de l'envoi des liens de collecte (anti-hameçonnage). Les actions
 * `send-links`/`remind` envoient de vrais e-mails à des adresses TIERCES (des coachs, pas des
 * membres) : c'est une surface d'hameçonnage. Trois garde-fous, chacun falsifiable ici :
 *
 *  1. un PLAFOND de volume par club et par 24 h (un club ne peut pas arroser des milliers
 *     d'adresses) — partagé entre send-links, relance, renvoi ciblé et campagnes du club ;
 *  2. l'envoi réel est INTERDIT en démonstration (un compte démo ne doit jamais e-mailer un
 *     tiers — aujourd'hui le trou existe, prouvé par le rouge d'assertion) ;
 *  3. un PIED anti-usurpation FIXE, hors contrôle du club (le pied lui-même est prouvé au plus
 *     bas sur le gabarit — {@see \App\Tests\Unit\Mail\EmailTemplateRendererTest::testAntiSpoofFooterIsFixedAndImmuneToClubData} ;
 *     ici on prouve qu'il ATTEINT l'e-mail réellement émis, à l'envoi comme à la relance).
 *
 * Les e-mails sont capturés par le collecteur de mail (clone signé par EmailSignatureListener à
 * l'enfilage — le HTML y est déjà rendu). Plafond en test : voir `when@test` de rate_limiter.yaml.
 */
#[Group('phase1')]
final class CoachWishSendAbuseTest extends WebTestCase
{
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    private Club $club;

    private Season $season;

    private User $manager;

    private string $jwt;

    private CalendarEntry $mother;

    private CoachWishCampaign $campaign;

    private Coach $coach;

    private CoachWishToken $token;

    public function testVolumeCapBlocksBeyondTwoHundredRecipientsPerClubPerDay(): void
    {
        // Le plafond compte les DESTINATAIRES par club sur 24 h : au-delà, le club est refusé et
        // aucun e-mail ne part. En test le plafond est petit (voir rate_limiter.yaml) — on le
        // sature par renvois ciblés successifs du même coach, puis on constate le refus 429.
        $limit = 2; // miroir du `when@test` coach_wish_send
        for ($i = 1; $i <= $limit; ++$i) {
            $this->client->request('POST', $this->sendLinksUrl(), [], [], $this->headers(), $this->coachIdsBody());
            self::assertResponseIsSuccessful();
            self::assertSame(1, $this->lastJson()['sent'], 'chaque renvoi ciblé consomme un destinataire du budget du club');
        }

        // Le renvoi qui dépasse le budget du jour est REFUSÉ, message figé, et rien n'est enfilé.
        $this->client->enableProfiler();
        $this->client->request('POST', $this->sendLinksUrl(), [], [], $this->headers(), $this->coachIdsBody());
        self::assertResponseStatusCodeSame(429);
        $error = (string) ($this->lastJson()['error'] ?? '');
        self::assertStringContainsString('Limite d\'envoi atteinte', $error);
        self::assertStringContainsString('réessayez demain', $error);
        self::assertQueuedEmailCount(0);
    }

    public function testDemoClubCannotSendRealCoachEmails(): void
    {
        // Falsification du TROU : aujourd'hui aucun garde démo ne protège l'envoi — un club de
        // démonstration e-maille réellement des tiers. On exige le refus (403) ET zéro e-mail
        // enfilé ; sur la prod actuelle l'e-mail part (200, 1 enfilé) → ces assertions ROUGISSENT.
        $this->markClubDemo();

        $this->client->enableProfiler();
        $this->client->request('POST', $this->sendLinksUrl(), [], [], $this->headers(), '{}');
        self::assertResponseStatusCodeSame(403);
        $error = (string) ($this->lastJson()['error'] ?? '');
        self::assertStringContainsString('indisponible en démonstration', $error);
        self::assertQueuedEmailCount(0);

        // La relance d'une démo est bloquée de la même manière (même garde, même message).
        $this->client->enableProfiler();
        $this->client->request('POST', $this->remindUrl(), [], [], $this->headers(), '{}');
        self::assertResponseStatusCodeSame(403);
        self::assertQueuedEmailCount(0);
    }

    public function testAntiSpoofFooterIsFixedAndOutOfClubControl(): void
    {
        // Le pied anti-usurpation FIXE doit atteindre l'e-mail réellement émis — à l'envoi…
        $this->client->enableProfiler();
        $this->client->request('POST', $this->sendLinksUrl(), [], [], $this->headers(), '{}');
        self::assertResponseIsSuccessful();
        $this->assertAntiSpoofFooterPresent();

        // …comme à la relance du silencieux (le coach n'a pas répondu dans ce décor).
        $this->client->enableProfiler();
        $this->client->request('POST', $this->remindUrl(), [], [], $this->headers(), '{}');
        self::assertResponseIsSuccessful();
        $this->assertAntiSpoofFooterPresent();
    }

    public function testReminderAndTargetedResendShareTheSameClubCap(): void
    {
        // Le plafond est PAR CLUB, pas par action ni par campagne : send-links, renvoi ciblé,
        // relance et une 2ᵉ campagne du même club puisent dans le MÊME budget de 24 h. Le décor
        // sature le plafond de test (2, miroir du `when@test` coach_wish_send) en deux envois.

        // 1) send-links global consomme 1 destinataire…
        $this->client->request('POST', $this->sendLinksUrl(), [], [], $this->headers(), '{}');
        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->lastJson()['sent']);

        // 2) …le renvoi ciblé du même coach consomme le second — budget du club saturé (2).
        $this->client->request('POST', $this->sendLinksUrl(), [], [], $this->headers(), $this->coachIdsBody());
        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->lastJson()['sent']);

        // 3) la RELANCE du silencieux est alors refusée — elle partage le budget de send-links.
        $this->client->request('POST', $this->remindUrl(), [], [], $this->headers(), '{}');
        self::assertResponseStatusCodeSame(429);
        self::assertStringContainsString('Limite d\'envoi atteinte', (string) ($this->lastJson()['error'] ?? ''));

        // 4) une 2ᵉ CAMPAGNE du même club est refusée elle aussi (plafond par club, pas par campagne).
        $secondCampaignId = $this->seedSecondCampaignWithCoach();
        $this->client->request('POST', '/api/coach_wish_campaigns/' . $secondCampaignId . '/send-links', [], [], $this->headers(), '{}');
        self::assertResponseStatusCodeSame(429);
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get('security.user_password_hasher');
        $uid = uniqid('', true);

        $this->club = (new Club)->setName('CWAbuse ' . $uid)->setSlug('cwabuse-' . $uid)->setTimezone('Europe/Paris')->setLocale('fr')->setOnboardingCompleted(true);
        $this->em->persist($this->club);
        $user = (new User)->setEmail('cwabuse' . $uid . '@test.com')->setFirstName('Gérald')->setLastName('A');
        $user->setPasswordHash($hasher->hashPassword($user, 'Password123!'));
        $this->em->persist($user);
        $this->em->flush();
        $this->manager = $user;

        $this->scopeGucToClub($this->club->getId());
        $this->em->persist((new ClubUser)->setClubId($this->club->getId())->setUserId($user->getId())->setRole('admin')->setIsActive(true));
        $this->season = (new Season)->setClubId($this->club->getId())->setName('2025-2026')
            ->setStartDate(new DateTimeImmutable('2025-09-01'))->setEndDate(new DateTimeImmutable('2026-06-30'))->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($this->season);

        $team = (new Team)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setSportCategoryId('11111111-1111-4111-8111-111111111111')->setPriorityTierId(1)->setName('SM1');
        $this->em->persist($team);
        $this->coach = (new Coach)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setFirstName('Maxime')->setLastName('Durand')->setEmail('maxime@test.com');
        $this->em->persist($this->coach);
        $this->em->flush();
        $this->em->persist((new TeamCoach)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setTeamId($team->getId())->setCoachId($this->coach->getId())->setRole(TeamCoachRole::MAIN));

        $this->mother = (new CalendarEntry)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setKind(CalendarEntryKind::PERIOD)->setPeriodType(CalendarEntryPeriodType::HOLIDAY)->setTitle('Toussaint')
            ->setStartDate(new DateTimeImmutable('2026-02-16'))->setEndDate(new DateTimeImmutable('2026-03-01'));
        $this->em->persist($this->mother);

        $this->campaign = (new CoachWishCampaign)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setCalendarEntryId($this->mother->getId())->setDeadline(new DateTimeImmutable('2027-06-30'))
            ->setWeeks(['2026-02-16'])->setTeamIds([$team->getId()]);
        $this->em->persist($this->campaign);

        $this->token = (new CoachWishToken)->setCampaignId($this->campaign->getId())->setCoachId($this->coach->getId())->setClubId($this->club->getId());
        $this->em->persist($this->token);
        $this->em->flush();

        $this->jwt = $container->get(JWTTokenManagerInterface::class)->create($user);
    }

    private function sendLinksUrl(): string
    {
        return '/api/coach_wish_campaigns/' . $this->campaign->getId() . '/send-links';
    }

    private function remindUrl(): string
    {
        return '/api/coach_wish_campaigns/' . $this->campaign->getId() . '/remind';
    }

    /** Corps JSON ciblant explicitement le coach (renvoi ciblé — re-sert même un coach déjà servi). */
    private function coachIdsBody(): string
    {
        return json_encode(['coachIds' => [$this->coach->getId()]], \JSON_THROW_ON_ERROR);
    }

    /** Marque le club de la campagne en démonstration (geste de compte interdit à une démo). */
    private function markClubDemo(): void
    {
        $this->scopeGucToClub($this->club->getId());
        $this->club->setIsDemo(true);
        $this->em->flush();
        $this->scopeGucToClub($this->club->getId());
    }

    /**
     * Le pied anti-usurpation FIXE dans l'e-mail réellement émis : l'avertissement mot pour mot
     * + l'attribution « via {produit} » (produit = « Amateo », résolu par ProductIdentity — pas
     * un littéral). Le club ne peut ni le retirer ni le réécrire (injection prouvée au plus bas).
     *
     * On vérifie sur le corps rendu COMPLET (HTML + texte) : le pied vit dans le gabarit HTML
     * ({@see \App\Mail\EmailTemplateRenderer}, foyer unique, cf. le jumeau unitaire) ; concaténer
     * le texte rend l'assertion robuste à la part retenue, sans rien affaiblir (le pied DOIT être
     * dans l'e-mail émis — si absent des deux, c'est rouge).
     */
    private function assertAntiSpoofFooterPresent(): void
    {
        $email = self::getMailerMessage();
        self::assertNotNull($email);
        $rendered = (string) $email->getHtmlBody() . "\n" . (string) $email->getTextBody();
        self::assertStringContainsString('Vous ne connaissez pas ce club ? Ignorez ce message.', $rendered);
        self::assertStringContainsString('Envoyé via Amateo à la demande du club', $rendered);
    }

    /** Une 2ᵉ campagne du MÊME club, avec son propre coach à email — pour éprouver le plafond par club. */
    private function seedSecondCampaignWithCoach(): string
    {
        $this->scopeGucToClub($this->club->getId());
        $team = (new Team)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setSportCategoryId('11111111-1111-4111-8111-111111111111')->setPriorityTierId(1)->setName('SF1');
        $this->em->persist($team);
        $coach = (new Coach)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setFirstName('Nadia')->setLastName('Bernard')->setEmail('nadia@test.com');
        $this->em->persist($coach);
        $this->em->flush();
        $this->em->persist((new TeamCoach)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setTeamId($team->getId())->setCoachId($coach->getId())->setRole(TeamCoachRole::MAIN));

        $entry = (new CalendarEntry)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setKind(CalendarEntryKind::PERIOD)->setPeriodType(CalendarEntryPeriodType::HOLIDAY)->setTitle('Noël')
            ->setStartDate(new DateTimeImmutable('2026-12-20'))->setEndDate(new DateTimeImmutable('2027-01-04'));
        $this->em->persist($entry);
        $campaign = (new CoachWishCampaign)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setCalendarEntryId($entry->getId())->setDeadline(new DateTimeImmutable('2027-06-30'))
            ->setWeeks(['2026-12-21'])->setTeamIds([$team->getId()]);
        $this->em->persist($campaign);
        $this->em->persist((new CoachWishToken)->setCampaignId($campaign->getId())->setCoachId($coach->getId())->setClubId($this->club->getId()));
        $this->em->flush();

        return $campaign->getId();
    }

    /** Le corps JSON (objet) de la dernière réponse. @return array<string, mixed> */
    private function lastJson(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return [
            'HTTP_X-Season-Id' => $this->season->getId(),
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->jwt,
            'CONTENT_TYPE' => 'application/json',
        ];
    }
}
