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
use App\Entity\User;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\SeasonStatus;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Aperçu de l'e-mail du lien coach (D1) — GET /api/coach_wish_campaigns/{id}/email-preview.
 *
 * Axes d'abus : réservé au gestionnaire (SEC-07, 403 sinon) ; borné au club de l'appelant (404
 * byte-identique pour une campagne d'un autre club) ; et SURTOUT il ne doit JAMAIS exposer un
 * vrai jeton personnel (le builder est appelé avec un jeton FACTICE constant).
 */
#[Group('phase1')]
final class CoachWishEmailPreviewTest extends WebTestCase
{
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    private Club $club;

    private Season $season;

    private CalendarEntry $mother;

    private CoachWishCampaign $campaign;

    private CoachWishToken $realToken;

    private string $managerJwt;

    private string $viewerJwt;

    public function testPreviewReturnsTheInitialSendEmailWithFormulaAAndCta(): void
    {
        $this->client->request('GET', '/api/coach_wish_campaigns/' . $this->campaign->getId() . '/email-preview', [], [], $this->headers($this->managerJwt));
        self::assertResponseIsSuccessful();

        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('Vos disponibilités pour Toussaint', $body['subject']);
        self::assertStringContainsString('via Amateo', $body['from']);
        // Le gabarit HTML (bouton + corps) est rendu, avec le prénom du gestionnaire (Formule A).
        self::assertStringContainsString('Donner mes disponibilités', $body['html']);
        self::assertStringContainsString('Gérald (', $body['html']);
    }

    public function testPreviewRequiresManager(): void
    {
        $this->client->request('GET', '/api/coach_wish_campaigns/' . $this->campaign->getId() . '/email-preview', [], [], $this->headers($this->viewerJwt));
        self::assertResponseStatusCodeSame(403);
    }

    public function testPreviewIsScopedToCallersClub(): void
    {
        // Une campagne d'un AUTRE club : la RLS la masque → 404, BYTE-IDENTIQUE à une campagne
        // inconnue (on ne révèle jamais l'existence d'une campagne d'autrui).
        [$otherCampaignId] = $this->seedOtherClubCampaign();

        $this->client->request('GET', '/api/coach_wish_campaigns/' . $otherCampaignId . '/email-preview', [], [], $this->headers($this->managerJwt));
        self::assertResponseStatusCodeSame(404);
        $foreign = $this->problemShape(json_decode((string) $this->client->getResponse()->getContent(), true));

        $this->client->request('GET', '/api/coach_wish_campaigns/00000000-0000-4000-8000-000000000000/email-preview', [], [], $this->headers($this->managerJwt));
        self::assertResponseStatusCodeSame(404);
        $unknown = $this->problemShape(json_decode((string) $this->client->getResponse()->getContent(), true));

        // Les champs STABLES du problème sont identiques : un 404 cross-club ne se distingue pas
        // d'un 404 inconnu (en prod, le corps est byte-identique ; en test, seule la `trace` de
        // debug rejoue l'id envoyé par le client — pas une fuite, il l'a lui-même fourni).
        self::assertSame($unknown, $foreign, 'un 404 cross-club est indistinguable d\'un 404 inconnu');
        self::assertSame(['detail' => 'Not Found', 'status' => 404, 'title' => 'An error occurred'], $foreign);
    }

    public function testPreviewNeverExposesARealToken(): void
    {
        $realToken = $this->realToken->getToken();
        self::assertNotSame('', $realToken);

        $this->client->request('GET', '/api/coach_wish_campaigns/' . $this->campaign->getId() . '/email-preview', [], [], $this->headers($this->managerJwt));
        self::assertResponseIsSuccessful();

        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertStringNotContainsString($realToken, (string) $body['html'], 'aucun vrai jeton dans le HTML de l\'aperçu');
        self::assertStringNotContainsString($realToken, (string) $body['subject']);
        self::assertStringNotContainsString($realToken, (string) $body['from']);
        // Le lien de l'aperçu porte un jeton FACTICE, jamais lu en base.
        self::assertStringContainsString('/doleances/apercu-', (string) $body['html']);
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get('security.user_password_hasher');
        $uid = uniqid('', true);

        $this->club = (new Club)->setName('CWP ' . $uid)->setSlug('cwp-' . $uid)->setTimezone('Europe/Paris')->setLocale('fr')->setOnboardingCompleted(true);
        $this->em->persist($this->club);

        $manager = (new User)->setEmail('mgr' . $uid . '@test.com')->setFirstName('Gérald')->setLastName('M');
        $manager->setPasswordHash($hasher->hashPassword($manager, 'Password123!'));
        $viewer = (new User)->setEmail('view' . $uid . '@test.com')->setFirstName('Vicky')->setLastName('V');
        $viewer->setPasswordHash($hasher->hashPassword($viewer, 'Password123!'));
        $this->em->persist($manager);
        $this->em->persist($viewer);
        $this->em->flush();

        $this->scopeGucToClub($this->club->getId());
        $this->em->persist((new ClubUser)->setClubId($this->club->getId())->setUserId($manager->getId())->setRole('admin')->setIsActive(true));
        $this->em->persist((new ClubUser)->setClubId($this->club->getId())->setUserId($viewer->getId())->setRole('viewer')->setIsActive(true));

        $this->season = (new Season)->setClubId($this->club->getId())->setName('2025-2026')
            ->setStartDate(new DateTimeImmutable('2025-09-01'))->setEndDate(new DateTimeImmutable('2026-06-30'))->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($this->season);

        $coach = (new Coach)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setFirstName('Maxime')->setLastName('Durand')->setEmail('maxime@test.com');
        $this->em->persist($coach);

        $this->mother = (new CalendarEntry)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setKind(CalendarEntryKind::PERIOD)->setPeriodType(CalendarEntryPeriodType::HOLIDAY)->setTitle('Toussaint')
            ->setStartDate(new DateTimeImmutable('2026-02-16'))->setEndDate(new DateTimeImmutable('2026-03-01'));
        $this->em->persist($this->mother);

        $this->campaign = (new CoachWishCampaign)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setCalendarEntryId($this->mother->getId())->setDeadline(new DateTimeImmutable('2027-06-30'))
            ->setWeeks(['2026-02-16'])->setTeamIds([]);
        $this->em->persist($this->campaign);

        $this->realToken = (new CoachWishToken)->setCampaignId($this->campaign->getId())->setCoachId($coach->getId())->setClubId($this->club->getId());
        $this->em->persist($this->realToken);
        $this->em->flush();
        $this->clearGuc();

        $jwtManager = $container->get(JWTTokenManagerInterface::class);
        $this->managerJwt = $jwtManager->create($manager);
        $this->viewerJwt = $jwtManager->create($viewer);
    }

    protected function tearDown(): void
    {
        $this->clearGuc();
        parent::tearDown();
    }

    /**
     * Les champs STABLES d'un problème HTTP (hors `trace`/`class` de debug) — triés pour une
     * comparaison déterministe.
     *
     * @return array<string, mixed>
     */
    private function problemShape(mixed $problem): array
    {
        self::assertIsArray($problem);
        $shape = ['detail' => $problem['detail'] ?? null, 'status' => $problem['status'] ?? null, 'title' => $problem['title'] ?? null];
        ksort($shape);

        return $shape;
    }

    /** @return array{0: string} [campaignId] d'un AUTRE club, invisible au club courant. */
    private function seedOtherClubCampaign(): array
    {
        $uid = uniqid('', true);
        $club = (new Club)->setName('Autre ' . $uid)->setSlug('autre-' . $uid)->setTimezone('Europe/Paris')->setLocale('fr');
        $this->em->persist($club);
        $season = (new Season)->setClubId($club->getId())->setName('2025-2026')
            ->setStartDate(new DateTimeImmutable('2025-09-01'))->setEndDate(new DateTimeImmutable('2026-06-30'))->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($season);

        $this->scopeGucToClub($club->getId());
        $entry = (new CalendarEntry)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setKind(CalendarEntryKind::PERIOD)->setPeriodType(CalendarEntryPeriodType::HOLIDAY)->setTitle('Noël')
            ->setStartDate(new DateTimeImmutable('2026-02-16'))->setEndDate(new DateTimeImmutable('2026-03-01'));
        $this->em->persist($entry);
        $campaign = (new CoachWishCampaign)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setCalendarEntryId($entry->getId())->setDeadline(new DateTimeImmutable('2027-06-30'))
            ->setWeeks(['2026-02-16'])->setTeamIds([]);
        $this->em->persist($campaign);
        $this->em->flush();
        $this->clearGuc();

        return [$campaign->getId()];
    }

    /** @return array<string, string> */
    private function headers(string $jwt): array
    {
        return [
            'HTTP_X-Season-Id' => $this->season->getId(),
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt,
            'CONTENT_TYPE' => 'application/json',
            // JSON négocié : un 404 d'API est alors un problème générique (« Not Found »),
            // sans écho du chemin — ce qui rend le 404 cross-club byte-identique au 404 inconnu.
            // La page d'erreur HTML du dev, elle, rejoue l'URL demandée (donc non identique).
            'HTTP_ACCEPT' => 'application/ld+json',
        ];
    }
}
