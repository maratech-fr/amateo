<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\OpponentDirectoryEntry;
use App\Enum\OpponentLocationPrecision;
use App\Service\TenantConnectionContext;
use App\Storage\LogoStorage;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\Double\BanGeocodingHttpClientStub;
use App\Tests\Double\FfbbHttpClientStub;
use App\Tests\Double\IgnRoutingHttpClientStub;
use App\Tests\Double\RecordingFfbbLogoFetcher;
use App\Tests\MemberReadOnlyDecorTrait;
use App\Tests\StartsFreshBrowserSession;
use App\Tests\VerifiesRegistration;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Psr\Cache\CacheItemPoolInterface;
use ReflectionProperty;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * NR BLOQUANT — axe auth & memberships : « un Membre LIT tout, et lire ne change rien ».
 *
 * Les lectures ouvertes au Membre par le chantier (conflits de séances placées, contrôle des
 * contraintes avant génération, propositions de créneaux ligue, campagnes de doléances et leur
 * aperçu) et celles déjà ouvertes (conflits et corrections de matchs, trajets adverses, libellés de
 * salle) répondent 200 au Membre, et l'empreinte des tables du club (amical compris) ne bouge pas :
 * aucune écriture, aucun message, aucun appel sortant.
 *
 * Ce qui reste fermé, et pourquoi :
 *  - les SEPT lectures qui appellent l'extérieur (FFBB, géocodage, vérification géographique) →
 *    403, aucun appel parti — un Membre ne déclenche jamais d'appel sortant ;
 *  - l'aperçu du MAIL de doléances (`email-preview`) → 403 ;
 *  - le lien personnel d'un coach (`token`) n'est JAMAIS servi au Membre : ni dans la campagne,
 *    ni dans l'aperçu du formulaire — il reste servi au gestionnaire ;
 *  - un logo adverse absent du stockage n'est PAS téléchargé pour un Membre (404, aucun appel,
 *    aucun marqueur) ;
 *  - « Générer » refusé à un Membre ne consomme RIEN du cap de génération du club ;
 *  - l'export reste ouvert au Membre, et son PDF est compté sur le club.
 *
 * Seaux de débit : `reset()` ciblé des clés `api` du Membre/gestionnaire et des caps du club,
 * jamais FLUSHALL. Angle jumeau, cité : `MemberWriteSweepTest` (les écritures, balayées) ;
 * `ManagementRoleTest::testNonManagementMemberCanStillRead` (lectures CRUD de base).
 */
#[Group('phase1')]
#[Group('integration')]
final class MemberReadsAreSideEffectFreeTest extends WebTestCase
{
    use ChoosesPlanVersionTrait;
    use MemberReadOnlyDecorTrait;
    use StartsFreshBrowserSession;
    use VerifiesRegistration;

    /** Les lectures que le Membre doit obtenir : [méthode, chemin, corps]. */
    private const array OPEN_READS = [
        ['GET', '/api/training/placed-conflicts', null],
        ['POST', '/api/constraints/validate', '{}'],
        ['GET', '/api/league-window-suggestions', null],
        ['GET', '/api/coach_wish_campaigns', null],
        ['GET', '/api/coach_wish_campaigns/{coach_wish_campaign}', null],
        ['GET', '/api/coach_wish_campaigns/{coach_wish_campaign}/preview?coachId={coach}', null],
        ['GET', '/api/fixtures/conflicts', null],
        ['GET', '/api/fixtures/fbi-corrections', null],
        ['GET', '/api/opponents/travel', null],
        ['GET', '/api/venues/fbi-labels', null],
    ];

    /** Les lectures qui appellent l'extérieur : fermées au Membre, sans exception. */
    private const array OUTBOUND_READS = [
        '/api/ffbb/engagements',
        '/api/ffbb/rencontres',
        '/api/ffbb/salles?q=gymnase',
        '/api/ffbb/salles-proches?lat=45.75&lon=4.85',
        '/api/geocode?q=gymnase',
        '/api/geocode/reverse?lat=45.75&lon=4.85',
        '/api/venues/geo-check',
    ];

    private KernelBrowser $client;

    public function testMemberReadsEveryOpenedScreenWithoutMovingAnything(): void
    {
        $this->seed();
        $before = $this->fingerprint();

        $wrong = [];
        foreach (self::OPEN_READS as [$method, $path, $body]) {
            $this->asMember($method, $this->fill($path), $body);
            $status = $this->client->getResponse()->getStatusCode();
            if (200 !== $status) {
                $wrong[] = \sprintf('%s %s → %d %s', $method, $path, $status, mb_substr((string) $this->client->getResponse()->getContent(), 0, 200));
            }
            foreach ([...$this->messagesSentByLastRequest(), ...$this->outboundCallsByLastRequest()] as $effect) {
                $wrong[] = \sprintf('%s %s → effet de bord %s', $method, $path, $effect);
            }
        }

        self::assertSame([], $wrong, "Lectures refusées au Membre, ou qui ont agi :\n  - " . implode("\n  - ", $wrong));
        self::assertSame($before, $this->fingerprint(), 'une lecture du Membre a modifié les tables du club');
    }

    public function testMemberNeverTriggersAnOutboundCall(): void
    {
        $this->seed();

        $wrong = [];
        foreach (self::OUTBOUND_READS as $path) {
            $this->asMember('GET', $path);
            $status = $this->client->getResponse()->getStatusCode();
            if (403 !== $status) {
                $wrong[] = \sprintf('%s → %d', $path, $status);
            }
            foreach ($this->outboundCallsByLastRequest() as $call) {
                $wrong[] = \sprintf('%s → appel sortant %s', $path, $call);
            }
        }

        self::assertSame([], $wrong, "Lectures sortantes non fermées au Membre :\n  - " . implode("\n  - ", $wrong));
    }

    public function testCoachPersonalLinkIsServedToManagersOnly(): void
    {
        $this->seed();
        $campaign = '/api/coach_wish_campaigns/' . $this->ids['coach_wish_campaign'];

        $managerView = $this->api($this->managerToken, 'GET', $campaign);
        self::assertResponseIsSuccessful();
        self::assertNotEmpty($managerView['coaches'] ?? [], 'témoin : la campagne porte au moins un coach destinataire');
        self::assertArrayHasKey('token', $managerView['coaches'][0], 'le gestionnaire reçoit le lien personnel (copier, relancer)');

        foreach ([$campaign, '/api/coach_wish_campaigns'] as $url) {
            $this->asMember('GET', $url);
            self::assertResponseIsSuccessful(\sprintf('un Membre lit %s', $url));
            $content = (string) $this->client->getResponse()->getContent();
            self::assertStringNotContainsString('"token"', $content, \sprintf('%s sert le lien personnel d\'un coach à un Membre', $url));
            self::assertStringContainsString((string) $managerView['coaches'][0]['email'], $content, 'le Membre voit l\'adresse du coach (état de réponse visible)');
        }
    }

    public function testMemberPreviewContainsNoToken(): void
    {
        $this->seed();

        $this->asMember('GET', '/api/coach_wish_campaigns/' . $this->ids['coach_wish_campaign'] . '/preview?coachId=' . $this->ids['coach']);
        self::assertResponseIsSuccessful('un Membre ouvre l\'aperçu du formulaire coach');
        $content = (string) $this->client->getResponse()->getContent();
        self::assertDoesNotMatchRegularExpression('/[0-9a-f]{64}/i', $content, 'l\'aperçu sert une chaîne de 64 hexadécimaux (un jeton) à un Membre');
        self::assertStringNotContainsString('/doleances/', $content, 'l\'aperçu sert un lien personnel /doleances/ à un Membre');
    }

    public function testMailPreviewStaysManagerOnly(): void
    {
        $this->seed();

        $this->asMember('GET', '/api/coach_wish_campaigns/' . $this->ids['coach_wish_campaign'] . '/email-preview');
        self::assertResponseStatusCodeSame(403, 'l\'aperçu du mail aux coachs reste réservé au gestionnaire');
    }

    public function testOpponentLogoMissIsNotDownloadedForAMemberButAStoredLogoIsServed(): void
    {
        $this->seed();
        $code = 'ARA0069MBR';
        $em = self::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $cache = self::getContainer()->get('cache.app');
        \assert($cache instanceof CacheItemPoolInterface);
        $storage = self::getContainer()->get(LogoStorage::class);
        \assert($storage instanceof LogoStorage);
        // Redis et stockage hors transaction : résidus d'un run précédent effacés (DEL ciblé).
        $cache->deleteItem('ffbb_logo_miss_' . $code);
        $storage->delete('ffbb-opponent-' . $code);
        $entry = new OpponentDirectoryEntry($code, 'Adverse au logo connu', OpponentLocationPrecision::CITY);
        $entry->setCity('Lyon');
        $entry->setLogoId('cafecafe-dead-4bee-8bad-c0ffeec0ffee');
        $em->persist($entry);
        $em->flush();

        $this->asMember('GET', '/api/opponents/' . $code . '/logo');
        self::assertResponseStatusCodeSame(404, 'logo absent du stockage : rien à servir au Membre');
        $fetcher = self::getContainer()->get(RecordingFfbbLogoFetcher::class);
        \assert($fetcher instanceof RecordingFfbbLogoFetcher);
        self::assertSame(0, $fetcher->calls, 'un Membre a déclenché le téléchargement d\'un logo FFBB');
        $cache = self::getContainer()->get('cache.app');
        \assert($cache instanceof CacheItemPoolInterface);
        self::assertFalse($cache->getItem('ffbb_logo_miss_' . $code)->isHit(), 'un Membre a posé le marqueur d\'échec de téléchargement');

        // Témoin : un logo DÉJÀ stocké est servi à tous, Membre compris.
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', true);
        self::assertIsString($png);
        self::getContainer()->get(LogoStorage::class)->store('ffbb-opponent-' . $code, $png);
        $this->asMember('GET', '/api/opponents/' . $code . '/logo');
        self::assertResponseIsSuccessful('un logo stocké est servi au Membre');
        self::getContainer()->get(LogoStorage::class)->delete('ffbb-opponent-' . $code);
    }

    public function testMemberGenerateIsRefusedWithoutSpendingTheClubBudget(): void
    {
        $this->seed();
        $this->resetBucket('limiter.schedule_generation', 'club-' . $this->clubId);
        $budgetBefore = $this->remaining('limiter.schedule_generation', 'club-' . $this->clubId);

        for ($i = 1; $i <= 10; ++$i) {
            $this->asMember('POST', '/api/schedules/' . $this->ids['draft_schedule'] . '/generate');
            self::assertResponseStatusCodeSame(403, \sprintf('« Générer » n°%d d\'un Membre : 403, jamais le cap du club (429)', $i));
        }

        self::assertSame($budgetBefore, $this->remaining('limiter.schedule_generation', 'club-' . $this->clubId), 'les refus d\'un Membre ont consommé le cap de génération du club');
    }

    public function testMemberExportsAndThePdfIsChargedToTheClub(): void
    {
        $this->seed();

        $this->asMember('POST', '/api/schedules/' . $this->ids['schedule'] . '/export-xlsx');
        self::assertResponseIsSuccessful('un Membre exporte le planning en tableur');

        $this->resetBucket('limiter.schedule_pdf_export', 'club-' . $this->clubId);
        $before = $this->remaining('limiter.schedule_pdf_export', 'club-' . $this->clubId);
        $this->asMember('POST', '/api/schedules/' . $this->ids['schedule'] . '/export-pdf');
        self::assertContains($this->client->getResponse()->getStatusCode(), [200, 202], 'un Membre lance l\'export PDF');
        self::assertSame($before - 1, $this->remaining('limiter.schedule_pdf_export', 'club-' . $this->clubId), 'l\'export PDF d\'un Membre est compté sur le club');
        $this->resetBucket('limiter.schedule_pdf_export', 'club-' . $this->clubId);
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    private function seed(): void
    {
        $token = $this->registerManager('mr');
        $categoryId = $this->api($token, 'GET', '/api/sport_categories')['member'][0]['id'];
        $this->ids['team'] = $this->create($token, '/api/teams', ['name' => 'MR1', 'sportCategoryId' => $categoryId, 'priorityTierId' => 1]);
        $this->ids['venue'] = $this->create($token, '/api/venues', ['name' => 'Gym MR', 'source' => 'manual']);
        $this->create($token, '/api/venue_training_slots', ['venueId' => $this->ids['venue'], 'dayOfWeek' => 1, 'startTime' => '18:00', 'durationMinutes' => 90, 'capacity' => 1]);
        $this->ids['coach'] = $this->create($token, '/api/coaches', ['firstName' => 'Jean', 'lastName' => 'Coach', 'email' => 'coach-' . substr(md5(uniqid('', true)), 0, 8) . '@test.fr', 'isEmployee' => true]);
        $this->create($token, '/api/team_coaches', ['teamId' => $this->ids['team'], 'coachId' => $this->ids['coach'], 'role' => 'MAIN']);
        $this->create($token, '/api/constraints', ['name' => 'MR', 'scope' => 'CLUB', 'family' => 'DAY', 'ruleType' => 'HARD', 'config' => ['forbiddenDays' => [6]], 'isActive' => true, 'sortOrder' => 1]);
        $this->ids['draft_schedule'] = $this->create($token, '/api/schedules', ['name' => 'Brouillon MR', 'status' => 'DRAFT']);
        $this->pointValidatedSeasonPlan();
        $this->create($token, '/api/fixtures', [
            'teamId' => $this->ids['team'], 'matchDate' => new DateTimeImmutable('+10 days')->format('Y-m-d'),
            'homeAway' => 'HOME', 'opponentLabel' => 'Amical MR',
        ]);

        $monday = new DateTimeImmutable('monday next week +6 weeks');
        $holiday = $this->create($token, '/api/calendar_entries', ['kind' => 'period', 'periodType' => 'holiday', 'title' => 'Vacances MR', 'startDate' => $monday->format('Y-m-d'), 'endDate' => $monday->modify('+6 days')->format('Y-m-d')]);
        $this->create($token, '/api/schedule_plans', ['calendarEntryId' => $holiday]);
        $this->ids['coach_wish_campaign'] = $this->create($token, '/api/coach_wish_campaigns', ['calendarEntryId' => $holiday, 'deadline' => $monday->modify('-7 days')->format('Y-m-d'), 'weeks' => [$monday->format('Y-m-d')], 'teamIds' => [$this->ids['team']]]);

        $this->addActiveMember();
        self::getContainer()->get(TenantConnectionContext::class)->clear();
    }

    private function remaining(string $limiterService, string $key): int
    {
        $limiter = self::getContainer()->get($limiterService);
        \assert($limiter instanceof RateLimiterFactory);

        return $limiter->create($key)->consume(0)->getRemainingTokens();
    }

    /**
     * Les appels sortants partis PENDANT la dernière requête (le noyau redémarre avant chaque
     * requête : les doublures de ce conteneur ne voient qu'elle) — FFBB, géocodage, routage, logos.
     *
     * @return list<string>
     */
    private function outboundCallsByLastRequest(): array
    {
        $calls = [];
        foreach ([FfbbHttpClientStub::class, BanGeocodingHttpClientStub::class, IgnRoutingHttpClientStub::class] as $stubClass) {
            $stub = self::getContainer()->get($stubClass);
            $inner = new ReflectionProperty($stubClass, 'inner')->getValue($stub);
            \assert($inner instanceof MockHttpClient);
            if ($inner->getRequestsCount() > 0) {
                $calls[] = \sprintf('%s×%d', $stubClass, $inner->getRequestsCount());
            }
        }
        $logos = self::getContainer()->get(RecordingFfbbLogoFetcher::class);
        \assert($logos instanceof RecordingFfbbLogoFetcher);
        if ($logos->calls > 0) {
            $calls[] = 'logo FFBB×' . $logos->calls;
        }

        return $calls;
    }
}
