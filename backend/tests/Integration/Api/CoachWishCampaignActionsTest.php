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
 * Actions d'envoi d'une campagne (feature #10, lot C3) : « send-links » n'envoie qu'aux
 * coachs à email PAS ENCORE servis (D2 : pas un renvoi général) et stampe sentAt ;
 * « remind » ne vise que les silencieux et se bloque le reste de la journée (D3).
 * Les emails sont capturés par le profiler (transport null en test).
 */
#[Group('phase1')]
final class CoachWishCampaignActionsTest extends WebTestCase
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

    private Coach $withEmail;

    private Coach $noEmail;

    private CoachWishToken $tokenWithEmail;

    private CoachWishToken $tokenNoEmail;

    public function testSendLinksMailsOnlyUnsentCoachesWithEmailAndStampsSentAt(): void
    {
        $this->client->enableProfiler();
        $this->client->request('POST', '/api/coach_wish_campaigns/' . $this->campaign->getId() . '/send-links', [], [], $this->headers(), '{}');
        self::assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame(1, $body['sent'], 'seul le coach À EMAIL est servi (l\'autre = badge « pas d\'email »)');

        // P5-3a : les e-mails partent désormais par le bus (SendEmailMessage routé en
        // mémoire en test) — ils sont ENFILÉS, pas envoyés dans la requête.
        self::assertQueuedEmailCount(1);
        $email = self::getMailerMessage();
        self::assertNotNull($email);
        self::assertEmailHeaderSame($email, 'To', 'maxime@test.com');
        self::assertEmailTextBodyContains($email, '/doleances/' . $this->tokenWithEmail->getToken());

        // sentAt stampé — visible aussi dans la ressource retournée.
        $coaches = array_column($body['campaign']['coaches'], 'sentAt', 'coachId');
        self::assertNotNull($coaches[$this->withEmail->getId()]);
        self::assertNull($coaches[$this->noEmail->getId()]);

        // Second clic global : personne de neuf → 0 envoi (D2, pas un renvoi général).
        $this->client->request('POST', '/api/coach_wish_campaigns/' . $this->campaign->getId() . '/send-links', [], [], $this->headers(), '{}');
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame(0, $body['sent'], 'un coach déjà servi n\'est pas re-servi par le bouton global');
    }

    public function testSendLinksBodyAndSenderCarryTheTriggeringManagerIdentity(): void
    {
        // Formule A (D1) : le corps ET l'expéditeur portent le prénom du gestionnaire qui
        // déclenche l'envoi + le libellé du club (ici le nom long, pas de nom court).
        $this->client->enableProfiler();
        $this->client->request('POST', '/api/coach_wish_campaigns/' . $this->campaign->getId() . '/send-links', [], [], $this->headers(), '{}');
        self::assertResponseIsSuccessful();

        $email = self::getMailerMessage();
        self::assertNotNull($email);
        self::assertEmailTextBodyContains($email, 'Gérald (' . $this->club->emailLabel() . ') prépare le planning de « Toussaint »');

        $from = $email->getFrom();
        self::assertCount(1, $from);
        self::assertSame('Gérald (' . $this->club->emailLabel() . ') via Amateo', $from[0]->getName(), 'expéditeur « Prénom (libellé) via produit »');
    }

    public function testSendLinksTargetedResendsToTheListedCoach(): void
    {
        // Ajout tardif d'un email (D1) : l'envoi CIBLÉ re-sert même un coach déjà servi.
        $this->scopeGucToClub($this->club->getId());
        $this->tokenWithEmail->markSent(new DateTimeImmutable('-1 day'));
        $this->em->flush();

        $this->client->request('POST', '/api/coach_wish_campaigns/' . $this->campaign->getId() . '/send-links', [], [], $this->headers(), json_encode([
            'coachIds' => [$this->withEmail->getId()],
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame(1, $body['sent'], 'le ciblage explicite re-sert le coach listé');
    }

    public function testRemindTargetsSilentCoachesAndBlocksASecondCallTheSameDay(): void
    {
        // Le coach à email a RÉPONDU → plus personne à relancer parmi les emails valides ?
        // Non : on le laisse silencieux ici pour vérifier le ciblage, puis le verrou du jour.
        $this->client->request('POST', '/api/coach_wish_campaigns/' . $this->campaign->getId() . '/remind', [], [], $this->headers(), '{}');
        self::assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame(1, $body['sent'], 'le silencieux à email est relancé');
        self::assertNotNull($body['campaign']['lastReminderAt']);

        // Deuxième relance le MÊME jour → 422 (« c'est du harcèlement », D3).
        $this->client->request('POST', '/api/coach_wish_campaigns/' . $this->campaign->getId() . '/remind', [], [], $this->headers(), '{}');
        self::assertResponseStatusCodeSame(422);
    }

    public function testRemindSkipsCoachesWhoAlreadyResponded(): void
    {
        $this->scopeGucToClub($this->club->getId());
        $this->tokenWithEmail->markResponded(new DateTimeImmutable);
        $this->em->flush();

        $this->client->request('POST', '/api/coach_wish_campaigns/' . $this->campaign->getId() . '/remind', [], [], $this->headers(), '{}');
        self::assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame(0, $body['sent'], 'un répondant n\'est jamais relancé');
    }

    public function testActionsAreRefusedOnAnArchivedSeason(): void
    {
        // Revue C3 #3 : une saison gelée est en lecture seule — send-links/remind écrivent
        // (sentAt/lastReminderAt), elles doivent 409 comme le chemin processor.
        $this->scopeGucToClub($this->club->getId());
        $this->season->setStatus(SeasonStatus::ARCHIVED);
        $this->em->flush();

        $this->client->request('POST', '/api/coach_wish_campaigns/' . $this->campaign->getId() . '/send-links', [], [], $this->headers(), '{}');
        self::assertResponseStatusCodeSame(409);

        $this->client->request('POST', '/api/coach_wish_campaigns/' . $this->campaign->getId() . '/remind', [], [], $this->headers(), '{}');
        self::assertResponseStatusCodeSame(409);
    }

    public function testPreviewRendersTheCoachFacingFormInReadOnlyMode(): void
    {
        // D2 — aperçu gestionnaire : la VRAIE page du coach, mais vidée de ses données.
        $this->client->request('GET', '/api/coach_wish_campaigns/' . $this->campaign->getId() . '/preview?coachId=' . $this->withEmail->getId(), [], [], $this->headers());
        self::assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('Maxime', $body['coachFirstName']);
        // Lecture seule : aucune donnée du coach, aucune réponse.
        self::assertSame([], $body['wishes']);
        self::assertSame([], $body['mutualizations']);
        self::assertNull($body['respondedAt']);
        // Même forme que le GET public.
        self::assertSame(
            ['coachFirstName', 'periodTitle', 'periodStart', 'periodEnd', 'deadline', 'weeks', 'teams', 'partnerTeams', 'teamLinks', 'wishes', 'mutualizations', 'respondedAt'],
            array_keys($body),
        );
    }

    public function testPreviewFollowsCampaignAccessAndCrossClub404IsByteIdentical(): void
    {
        // Un coach HORS du périmètre de la campagne → 404.
        $this->scopeGucToClub($this->club->getId());
        $stranger = (new Coach)->setClubId($this->club->getId())->setSeasonId($this->season->getId())->setFirstName('Zoé')->setLastName('Hors');
        $this->em->persist($stranger);
        $this->em->flush();

        $this->client->request('GET', '/api/coach_wish_campaigns/' . $this->campaign->getId() . '/preview?coachId=' . $stranger->getId(), [], [], $this->headers());
        self::assertResponseStatusCodeSame(404);
        $coachOutside404 = (string) $this->client->getResponse()->getContent();

        // Une campagne d'un AUTRE club → 404 (RLS la rend invisible), BYTE-IDENTIQUE.
        $otherCampaignId = $this->seedForeignCampaignId();
        $this->client->request('GET', '/api/coach_wish_campaigns/' . $otherCampaignId . '/preview?coachId=' . $this->withEmail->getId(), [], [], $this->headers());
        self::assertResponseStatusCodeSame(404);
        self::assertSame($coachOutside404, (string) $this->client->getResponse()->getContent(), 'cross-club et coach hors campagne : 404 identique');
    }

    public function testPreviewRefusesANonManagerWith403(): void
    {
        // Parité SEC-07 : un membre NON gestionnaire (rôle non management) est refusé en 403.
        $this->scopeGucToClub($this->club->getId());
        $hasher = self::getContainer()->get('security.user_password_hasher');
        $uid = uniqid('', true);
        $user = (new User)->setEmail('viewer' . $uid . '@test.com')->setFirstName('V')->setLastName('V');
        $user->setPasswordHash($hasher->hashPassword($user, 'Password123!'));
        $this->em->persist($user);
        $this->em->flush();
        $this->em->persist((new ClubUser)->setClubId($this->club->getId())->setUserId($user->getId())->setRole('editor')->setIsActive(true));
        $this->em->flush();
        $jwt = self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);

        $this->client->request('GET', '/api/coach_wish_campaigns/' . $this->campaign->getId() . '/preview?coachId=' . $this->withEmail->getId(), [], [], [
            'HTTP_X-Season-Id' => $this->season->getId(),
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt,
            'CONTENT_TYPE' => 'application/json',
        ]);
        self::assertResponseStatusCodeSame(403);
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get('security.user_password_hasher');
        $uid = uniqid('', true);

        $this->club = (new Club)->setName('CWA ' . $uid)->setSlug('cwa-' . $uid)->setTimezone('Europe/Paris')->setLocale('fr')->setOnboardingCompleted(true);
        $this->em->persist($this->club);
        $user = (new User)->setEmail('cwa' . $uid . '@test.com')->setFirstName('Gérald')->setLastName('A');
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
        $this->withEmail = (new Coach)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setFirstName('Maxime')->setLastName('Durand')->setEmail('maxime@test.com');
        $this->noEmail = (new Coach)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setFirstName('Mara')->setLastName('Petit');
        $this->em->persist($this->withEmail);
        $this->em->persist($this->noEmail);
        $this->em->flush();
        foreach ([$this->withEmail, $this->noEmail] as $coach) {
            $this->em->persist((new TeamCoach)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
                ->setTeamId($team->getId())->setCoachId($coach->getId())->setRole(TeamCoachRole::MAIN));
        }

        $this->mother = (new CalendarEntry)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setKind(CalendarEntryKind::PERIOD)->setPeriodType(CalendarEntryPeriodType::HOLIDAY)->setTitle('Toussaint')
            ->setStartDate(new DateTimeImmutable('2026-02-16'))->setEndDate(new DateTimeImmutable('2026-03-01'));
        $this->em->persist($this->mother);

        $this->campaign = (new CoachWishCampaign)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setCalendarEntryId($this->mother->getId())->setDeadline(new DateTimeImmutable('2027-06-30'))
            ->setWeeks(['2026-02-16'])->setTeamIds([$team->getId()]);
        $this->em->persist($this->campaign);

        $this->tokenWithEmail = (new CoachWishToken)->setCampaignId($this->campaign->getId())->setCoachId($this->withEmail->getId())->setClubId($this->club->getId());
        $this->tokenNoEmail = (new CoachWishToken)->setCampaignId($this->campaign->getId())->setCoachId($this->noEmail->getId())->setClubId($this->club->getId());
        $this->em->persist($this->tokenWithEmail);
        $this->em->persist($this->tokenNoEmail);
        $this->em->flush();

        $this->jwt = $container->get(JWTTokenManagerInterface::class)->create($user);
    }

    /** Crée un club étranger avec sa propre campagne et retourne son id (invisible au club courant). */
    private function seedForeignCampaignId(): string
    {
        $uid = uniqid('', true);
        $club = (new Club)->setName('Etr ' . $uid)->setSlug('etr-' . $uid)->setTimezone('Europe/Paris')->setLocale('fr')->setOnboardingCompleted(true);
        $this->em->persist($club);
        $this->em->flush();
        $this->scopeGucToClub($club->getId());
        $season = (new Season)->setClubId($club->getId())->setName('2025-2026')
            ->setStartDate(new DateTimeImmutable('2025-09-01'))->setEndDate(new DateTimeImmutable('2026-06-30'))->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($season);
        $entry = (new CalendarEntry)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setKind(CalendarEntryKind::PERIOD)->setPeriodType(CalendarEntryPeriodType::HOLIDAY)->setTitle('Toussaint')
            ->setStartDate(new DateTimeImmutable('2026-02-16'))->setEndDate(new DateTimeImmutable('2026-03-01'));
        $this->em->persist($entry);
        $campaign = (new CoachWishCampaign)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setCalendarEntryId($entry->getId())->setDeadline(new DateTimeImmutable('2027-06-30'))->setWeeks(['2026-02-16'])->setTeamIds([]);
        $this->em->persist($campaign);
        $this->em->flush();
        $this->scopeGucToClub($this->club->getId());

        return $campaign->getId();
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
