<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Fixture;
use App\Entity\Schedule;
use App\Entity\Season;
use App\Entity\Team;
use App\Entity\User;
use App\Enum\FixtureHomeAway;
use App\Enum\ScheduleStatus;
use App\Enum\SeasonStatus;
use App\Service\BrandAssets;
use App\Service\PdfGenerator;
use App\Service\ProductIdentity;
use App\Service\SchedulePlanProvisioner;
use App\Service\SeasonResolver;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\TenantGucTrait;
use ArrayObject;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use ReflectionClass;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * P4-357 — NR d'axe *tenant isolation* (§7.1). Le rendu PDF d'un planning ne se télécharge plus
 * par une URL statique publique (`/exports/…`) mais par une ROUTE AUTHENTIFIÉE confinée au club :
 * `GET /api/schedules/{id}/export-pdf/{filename}` (nouveau `ExportPdfDownloadController`, patron
 * `ExportXlsxController` — find sous RLS → 404 `{"error":"Planning introuvable."}`, puis garde de
 * club). Ce test prouve que :
 *
 *   1. le PROPRIÉTAIRE télécharge bien son rendu (200 `application/pdf`) — c'est aussi le TÉMOIN
 *      de falsification : tant que la route n'existe pas, cette assertion est ROUGE (404 route
 *      absente), elle vire au VERT quand `ExportPdfDownloadController` sert le fichier ;
 *   2. un club B, avec un JWT valide, visant l'URL EXACTE d'un rendu du club A obtient un 404
 *      BYTE-IDENTIQUE à celui d'un planning INCONNU (patron
 *      `Integration/Controller/ExportScheduleTest::testExportScopedToAnotherClubsVenueIs404` :
 *      aucune divulgation d'existence par différence de réponse) ;
 *   3. trois noms de fichier malveillants sont refusés 404 — une traversée (`..%2f…`), un nom
 *      HORS du motif strict (`PurgeExportsCommand::RENDER_PATTERN`), et le nom d'un AUTRE planning
 *      glissé sous le `{id}` du sien (le nom DOIT être préfixé `schedule-{id}-`) ;
 *   4. le disque reste INTACT après les refus (aucune suppression ni échappée hors répertoire).
 *
 * Angle VOULU en plus, cross-référencé (export = domaine sensible, charte §1) :
 * `Security/ExportAuthenticationTest::testTheRenderedPdfIsNotServedWithoutASession` couvre le refus
 * 401 de la MÊME route pour un anonyme ; ici on couvre le refus 404 CROSS-TENANT d'un club
 * authentifié et la robustesse du nom de fichier — deux abus distincts du même chemin.
 *
 * Export des matchs (`POST /api/fixtures/export-pdf`, troisième route d'export) : même promesse.
 * Une équipe d'un autre club se voit refuser par un 404 identique à celui d'une équipe inconnue, et
 * l'export « tout le club » ne contient jamais un match d'un autre club. Témoin : le propriétaire
 * obtient son PDF (200). Angle voisin, cité : `ExportMatchesTest::testAnUnknownTeamIs404`.
 *
 * ⚠ Ce test LIT et ÉCRIT dans le répertoire de rendus réel via l'accesseur
 * `PdfGenerator::outputDir()` (jamais une constante recopiée) : il suit le déplacement du
 * répertoire hors docroot (`public/exports` → `var/exports`) sans retouche.
 */
#[Group('phase1')]
#[Group('security')]
final class ExportPdfDownloadIsolationTest extends WebTestCase
{
    use ChoosesPlanVersionTrait;
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    /** @var list<string> fichiers écrits par le test, à retirer en fin */
    private array $writtenFiles = [];

    /**
     * Le propriétaire télécharge son rendu (200 PDF), un autre club est refusé 404 byte-identique
     * à un planning inconnu, trois noms malveillants sont refusés 404, le disque reste intact.
     */
    public function testOwnerDownloadsButAnotherClubAndMaliciousNamesGet404ByteIdentical(): void
    {
        [$clubA, $userA, $seasonA] = $this->seedClub('pdf-a');
        $scheduleA = $this->seedSchedule($clubA, $seasonA);
        $scheduleAId = $scheduleA->getId();

        // Un rendu réel sur disque, nommé comme le worker l'écrit (scope `all`).
        $filename = \sprintf('schedule-%s-all.pdf', $scheduleAId);
        $this->writeRender($filename);

        [$clubB, $userB] = $this->seedClub('pdf-b');
        $tokenA = self::getContainer()->get(JWTTokenManagerInterface::class)->create($userA);
        $tokenB = self::getContainer()->get(JWTTokenManagerInterface::class)->create($userB);
        // Vider la carte d'identité : le `find` du contrôleur doit toucher SQL (donc RLS).
        $this->em->clear();

        $ownUrl = \sprintf('/api/schedules/%s/export-pdf/%s', $scheduleAId, $filename);

        // 1. Propriétaire → 200 application/pdf (TÉMOIN de falsification : 404 tant que la route
        //    n'existe pas).
        $this->getAs($tokenA, $ownUrl);
        self::assertResponseStatusCodeSame(200, 'le gestionnaire du club télécharge le rendu de son propre planning');
        self::assertStringContainsString(
            'application/pdf',
            (string) $this->client->getResponse()->headers->get('Content-Type'),
            'le rendu est servi comme un PDF',
        );
        // Un rendu PDF est NOMINATIF (noms de coachs) : la réponse du propriétaire ne doit JAMAIS
        // être mise en cache par le navigateur — sinon elle survit sur un poste partagé du club.
        $cacheControl = (string) $this->client->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString(
            'no-store',
            $cacheControl,
            'le PDF nominatif n’est jamais stocké en cache navigateur (no-store)',
        );
        self::assertStringContainsString(
            'private',
            $cacheControl,
            'le PDF nominatif n’est jamais mis en cache partagé (private)',
        );

        // 2. Club B sur l'URL EXACTE de A → 404, byte-identique à un planning inconnu.
        $this->getAs($tokenB, $ownUrl);
        self::assertResponseStatusCodeSame(404, 'un club ne télécharge jamais le rendu d’un autre club');
        $crossTenantBody = (string) $this->client->getResponse()->getContent();

        $unknownId = $this->uuid();
        $this->getAs($tokenB, \sprintf('/api/schedules/%s/export-pdf/schedule-%s-all.pdf', $unknownId, $unknownId));
        self::assertResponseStatusCodeSame(404, 'un planning inconnu → 404');
        $unknownBody = (string) $this->client->getResponse()->getContent();

        self::assertSame(
            $this->withoutDebugTrace($crossTenantBody),
            $this->withoutDebugTrace($unknownBody),
            'le 404 cross-tenant doit être byte-identique au 404 d’un planning inconnu — aucune divulgation d’existence',
        );

        // 3. Trois noms malveillants, même sous le JWT du PROPRIÉTAIRE → 404.
        $foreignScheduleName = \sprintf('schedule-%s-all.pdf', $this->uuid());
        $malicious = [
            // Traversée : séparateurs encodés pour sortir du répertoire.
            '..%2f..%2f..%2fetc%2fpasswd',
            // Hors motif : ni le préfixe `schedule-`, ni l'extension attendue.
            'rapport-secret.pdf',
            // Le nom d'un AUTRE planning glissé sous le {id} de A : préfixe `schedule-{id}-` violé.
            $foreignScheduleName,
        ];
        foreach ($malicious as $name) {
            $this->getAs($tokenA, \sprintf('/api/schedules/%s/export-pdf/%s', $scheduleAId, $name));
            self::assertResponseStatusCodeSame(404, \sprintf('nom de fichier refusé : %s', $name));
        }

        // 4. Disque intact : le rendu de A est toujours là, rien n'a été supprimé ni échappé.
        self::assertFileExists(
            PdfGenerator::outputDir() . '/' . $filename,
            'aucun refus ne doit avoir supprimé ni déplacé le rendu légitime',
        );
    }

    /**
     * Une équipe d'un autre club : 404 identique à une équipe inconnue, aucune divulgation
     * d'existence. Son match n'apparaît pas non plus dans l'export « tout le club » du voisin.
     */
    public function testAnotherClubsTeamIsIndistinguishableFromAnUnknownOneInTheMatchesExport(): void
    {
        [$clubA, $userA, $seasonA] = $this->seedClub('mat-a');
        $teamAId = $this->seedTeamWithMatch($clubA, $seasonA, 'Adversaire du club A');
        [$clubB, $userB, $seasonB] = $this->seedClub('mat-b');
        $this->seedTeamWithMatch($clubB, $seasonB, 'Adversaire du club B');
        $tokenA = self::getContainer()->get(JWTTokenManagerInterface::class)->create($userA);
        $tokenB = self::getContainer()->get(JWTTokenManagerInterface::class)->create($userB);
        $this->client->disableReboot();
        $htmlSeen = $this->doubleTheWorker();
        $this->em->clear();

        $range = ['from' => $seasonA->getStartDate()->format('Y-m-d'), 'to' => $seasonA->getEndDate()->format('Y-m-d')];

        // Témoin : sur SA propre équipe, le propriétaire obtient son PDF.
        $this->postMatchesExport($tokenA, $range + ['teamId' => $teamAId]);
        self::assertResponseStatusCodeSame(200, 'le club exporte les matchs de sa propre équipe');

        // Club B, équipe du club A → 404, identique à une équipe inconnue.
        $this->postMatchesExport($tokenB, $range + ['teamId' => $teamAId]);
        self::assertResponseStatusCodeSame(404, 'une équipe d\'un autre club ne s\'exporte pas');
        $crossTenantBody = (string) $this->client->getResponse()->getContent();

        $this->postMatchesExport($tokenB, $range + ['teamId' => $this->uuid()]);
        self::assertResponseStatusCodeSame(404, 'une équipe inconnue → 404');

        self::assertSame(
            $this->withoutDebugTrace($crossTenantBody),
            $this->withoutDebugTrace((string) $this->client->getResponse()->getContent()),
            'le 404 cross-tenant doit être byte-identique à celui d\'une équipe inconnue',
        );

        // Export « tout le club » du club B : le match du club A n'y est pas.
        $htmlSeen->exchangeArray([]);
        $this->postMatchesExport($tokenB, $range + ['teamId' => null]);
        self::assertResponseStatusCodeSame(200);
        $html = (string) ($htmlSeen[0] ?? '');
        self::assertStringContainsString('Adversaire du club B', $html);
        self::assertStringNotContainsString('Adversaire du club A', $html, 'aucun match d\'un autre club dans le document');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->writtenFiles as $path) {
            @unlink($path);
        }
        $this->writtenFiles = [];
        parent::tearDown();
    }

    /**
     * Le worker est doublé : il écrit le fichier demandé. Rend le HTML reçu, requête après requête.
     *
     * @return ArrayObject<int, string>
     */
    private function doubleTheWorker(): ArrayObject
    {
        $htmlSeen = new ArrayObject;
        $reflection = new ReflectionClass(PdfGenerator::class);
        $pdf = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('httpClient')->setValue($pdf, new MockHttpClient(function (string $method, string $url, array $options) use ($htmlSeen): ResponseInterface {
            $payload = json_decode((string) $options['body'], true);
            \assert(\is_array($payload));
            $htmlSeen[] = (string) $payload['html'];
            $this->writeRender((string) $payload['filename']);

            return new MockResponse((string) json_encode(['success' => true]));
        }));
        $reflection->getProperty('brandAssets')->setValue($pdf, new BrandAssets);
        $reflection->getProperty('productIdentity')->setValue($pdf, new ProductIdentity(productName: 'Zephyr'));
        self::getContainer()->set(PdfGenerator::class, $pdf);

        return $htmlSeen;
    }

    /** @param array<string, string|null> $body */
    private function postMatchesExport(string $token, array $body): void
    {
        $this->client->request('POST', '/api/fixtures/export-pdf', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'CONTENT_TYPE' => 'application/json',
            'REMOTE_ADDR' => \sprintf('10.%d.%d.%d', random_int(1, 254), random_int(0, 254), random_int(1, 254)),
        ], json_encode($body, \JSON_THROW_ON_ERROR));
    }

    /**
     * Une équipe et son match, dans une saison recalée sur l'année courante : l'export lit la saison
     * courante du club. Rend l'id de l'équipe.
     */
    private function seedTeamWithMatch(Club $club, Season $season, string $opponent): string
    {
        $this->scopeGucToClub($club->getId());
        $year = SeasonResolver::seasonYear(new DateTimeImmutable('today'));
        $season->setName((string) $year);
        $season->setStartDate(new DateTimeImmutable($year . '-08-01'));
        $season->setEndDate(new DateTimeImmutable(($year + 1) . '-07-15'));

        $team = (new Team)
            ->setClubId($club->getId())
            ->setSeasonId($season->getId())
            ->setSportCategoryId('11111111-1111-4111-8111-111111111111')
            ->setPriorityTierId(1)
            ->setName('U13 M1')
            ->setSessionsPerWeek(2)
            ->setIsActive(true);
        $this->em->persist($team);

        $fixture = new Fixture;
        $fixture->setClubId($club->getId());
        $fixture->setSeasonId($season->getId());
        $fixture->setTeamId($team->getId());
        $fixture->setMatchDate(new DateTimeImmutable($year . '-11-14'));
        $fixture->setHomeAway(FixtureHomeAway::AWAY);
        $fixture->setOpponentLabel($opponent);
        $this->em->persist($fixture);
        $this->em->flush();

        return $team->getId();
    }

    /** Écrit un rendu minimal mais valide (entête %PDF) dans le répertoire réel d'exports. */
    private function writeRender(string $filename): void
    {
        $dir = PdfGenerator::outputDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0o775, true);
        }
        $path = $dir . '/' . $filename;
        file_put_contents($path, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
        $this->writtenFiles[] = $path;
    }

    /** GET authentifié (Bearer), IP neutre. */
    private function getAs(string $token, string $uri): void
    {
        $ip = \sprintf('10.%d.%d.%d', random_int(1, 254), random_int(0, 254), random_int(1, 254));
        $this->client->request('GET', $uri, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'REMOTE_ADDR' => $ip,
        ]);
    }

    /**
     * Retire la seule clé qui diffère légitimement entre deux 404 en APP_ENV=test (debug=true) :
     * la `trace` de débogage qui cite la ligne de l'appelant. Elle n'existe pas en prod.
     */
    private function withoutDebugTrace(string $body): string
    {
        $decoded = json_decode($body, true);
        if (!\is_array($decoded)) {
            return $body;
        }
        unset($decoded['trace']);

        return (string) json_encode($decoded);
    }

    private function uuid(): string
    {
        return \sprintf('%08x-0000-4000-8000-%012x', random_int(0, 0xFFFFFFFF), random_int(0, 0xFFFFFFFFFFFF));
    }

    /**
     * Un club + utilisateur admin actif + saison (plan SEASON), scopé au GUC du club (RLS WITH
     * CHECK), comme la prod.
     *
     * @return array{0: Club, 1: User, 2: Season}
     */
    private function seedClub(string $prefix): array
    {
        $uid = uniqid('', true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $club = new Club;
        $club->setName('EXP-ISO ' . $prefix);
        $club->setSlug('exp-iso-' . $prefix . '-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode(strtoupper(substr(md5($prefix . $uid), 0, 11)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail($prefix . '-' . $uid . '@test.com');
        $user->setFirstName('E');
        $user->setLastName('X');
        $user->setPasswordHash($hasher->hashPassword($user, 'pass'));
        $this->em->persist($user);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());

        $cu = new ClubUser;
        $cu->setClubId($club->getId());
        $cu->setUserId($user->getId());
        $cu->setRole('admin');
        $cu->setIsActive(true);
        $this->em->persist($cu);

        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName('2025-2026');
        $season->setStartDate(new DateTimeImmutable('2025-09-01'));
        $season->setEndDate(new DateTimeImmutable('2026-06-30'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($season);
        $this->em->flush();

        self::getContainer()->get(SchedulePlanProvisioner::class)->ensureSeasonPlan($season);
        $this->em->flush();

        return [$club, $user, $season];
    }

    /** Une version de saison COMPLETED liée à son plan (comme la prod au POST). */
    private function seedSchedule(Club $club, Season $season): Schedule
    {
        $this->scopeGucToClub($club->getId());
        $schedule = new Schedule;
        $schedule->setClubId($club->getId());
        $schedule->setSeasonId($season->getId());
        $schedule->setName('Export');
        $schedule->setStatus(ScheduleStatus::COMPLETED);
        $this->linkSeededSchedule($schedule);
        $this->em->flush();

        return $schedule;
    }
}
