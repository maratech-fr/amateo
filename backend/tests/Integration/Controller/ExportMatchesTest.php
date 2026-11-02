<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Fixture;
use App\Entity\Season;
use App\Entity\SubscriptionPlan;
use App\Entity\Team;
use App\Entity\User;
use App\Enum\ClubRole;
use App\Enum\FixtureHomeAway;
use App\Enum\SeasonStatus;
use App\Service\BrandAssets;
use App\Service\PdfGenerator;
use App\Service\ProductIdentity;
use App\Service\SeasonResolver;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use ReflectionClass;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Promesse : tout membre du club repart avec le PDF de ses matchs, jamais mis en cache, et un échec
 * ne lui coûte aucun crédit. Le worker est doublé (client HTTP de `PdfGenerator`) : il écrit le fichier demandé, ou
 * tombe en panne. Refus anonyme et cross-tenant : `Security/ExportAuthenticationTest` et
 * `Security/ExportPdfDownloadIsolationTest` (bloquants). Comptabilité fine du pool :
 * `Security/PlanEntitlementsTest`.
 */
#[Group('integration')]
final class ExportMatchesTest extends WebTestCase
{
    use TenantGucTrait;

    private const string URI = '/api/fixtures/export-pdf';

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    private int $year;

    /** @var list<string> */
    private array $writtenFiles = [];

    /** @var list<array<string, mixed>> */
    private array $workerPayloads = [];

    /** @return array<string, array{array<string, mixed>}> */
    public static function invalidRanges(): array
    {
        return [
            'début après fin' => [['from' => '2026-11-15', 'to' => '2026-11-09', 'teamId' => null]],
            'format français' => [['from' => '09/11/2026', 'to' => '2026-11-15', 'teamId' => null]],
            'date impossible' => [['from' => '2026-02-31', 'to' => '2026-03-01', 'teamId' => null]],
            'borne absente' => [['from' => '2026-11-09', 'teamId' => null]],
        ];
    }

    /** @return array<string, array{mixed}> */
    public static function malformedTeamIds(): array
    {
        return [
            'texte' => ['pas-un-uuid'],
            'injection' => ['\' OR 1=1 --'],
            'vide' => [''],
            'nombre' => [42],
            'tableau' => [['a']],
        ];
    }

    public function testAManagerGetsAPrivatePdf(): void
    {
        $ctx = $this->seedClub();
        $this->matchIn($ctx, 'Les Lions');
        $this->workerWrites();

        $this->export($ctx['user'], $this->november());

        self::assertResponseStatusCodeSame(200);
        $response = $this->client->getResponse();
        self::assertStringContainsString('application/pdf', (string) $response->headers->get('Content-Type'));
        self::assertSame('attachment; filename="matchs.pdf"', $response->headers->get('Content-Disposition'));
        $cacheControl = (string) $response->headers->get('Cache-Control');
        self::assertStringContainsString('no-store', $cacheControl);
        self::assertStringContainsString('private', $cacheControl);
        self::assertStringStartsWith('%PDF', (string) $this->client->getInternalResponse()->getContent());
    }

    public function testNoFileRemainsAfterTheResponse(): void
    {
        $ctx = $this->seedClub();
        $this->workerWrites();

        $this->export($ctx['user'], $this->november());

        self::assertResponseStatusCodeSame(200);
        self::assertCount(1, $this->writtenFiles);
        self::assertFileDoesNotExist($this->writtenFiles[0]);
    }

    /** Décision fondateur : parité planning, un membre exporte aussi, et son export compte. */
    public function testAMemberExportsAndItCountsInTheCredits(): void
    {
        $ctx = $this->seedClub(role: ClubRole::MEMBER);
        $this->workerWrites();

        $this->export($ctx['user'], $this->november());

        self::assertResponseStatusCodeSame(200);
        self::assertSame(1, $this->creditsOf($ctx['club']));
    }

    public function testAMemberOfAPaidOfferExportsWithoutCounting(): void
    {
        $ctx = $this->seedClub(role: ClubRole::MEMBER, planCode: 'essentiel');
        $this->workerWrites();

        $this->export($ctx['user'], $this->november());

        self::assertResponseStatusCodeSame(200);
        self::assertSame(0, $this->creditsOf($ctx['club']));
    }

    public function testAMemberHitsTheCreditWallLikeAnyone(): void
    {
        $ctx = $this->seedClub(role: ClubRole::MEMBER, credits: 10);
        $this->workerWrites();

        $this->export($ctx['user'], $this->november());

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->workerPayloads, 'le mur refuse avant le worker');
    }

    public function testTheDocumentCarriesTheMatchAndIsPortrait(): void
    {
        $ctx = $this->seedClub();
        $this->matchIn($ctx, 'Les Lions');
        $this->workerWrites();

        $this->export($ctx['user'], $this->november());

        self::assertStringContainsString('Les Lions', (string) ($this->workerPayloads[0]['html'] ?? ''));
        self::assertFalse($this->workerPayloads[0]['landscape'] ?? null);
    }

    public function testATeamScopeLeavesOtherTeamsOut(): void
    {
        $ctx = $this->seedClub();
        $this->matchIn($ctx, 'Adversaire gardé');
        $other = $this->team($ctx, 'Autre équipe');
        $this->matchIn($ctx, 'Adversaire écarté', $other);
        $this->workerWrites();

        $this->export($ctx['user'], ['teamId' => $ctx['team']->getId()] + $this->november());

        self::assertResponseStatusCodeSame(200);
        $html = (string) ($this->workerPayloads[0]['html'] ?? '');
        self::assertStringContainsString('Adversaire gardé', $html);
        self::assertStringNotContainsString('Adversaire écarté', $html);
    }

    public function testAnUnknownTeamIs404(): void
    {
        $ctx = $this->seedClub();
        $this->workerWrites();

        $this->export($ctx['user'], ['teamId' => '00000000-0000-4000-8000-0000000000ee'] + $this->november());

        self::assertResponseStatusCodeSame(404);
        self::assertSame('Équipe introuvable.', $this->errorBody());
        self::assertSame(0, $this->creditsOf($ctx['club']));
    }

    /** Un identifiant mal formé ne touche pas la base (un `guid` invalide y ferait un 500) : même 404. */
    #[DataProvider('malformedTeamIds')]
    public function testAMalformedTeamIdIsTheSame404(mixed $teamId): void
    {
        $ctx = $this->seedClub();
        $this->workerWrites();

        $this->export($ctx['user'], ['teamId' => '00000000-0000-4000-8000-0000000000ee'] + $this->november());
        $unknown = json_decode((string) $this->client->getResponse()->getContent(), true);

        $this->export($ctx['user'], ['teamId' => $teamId] + $this->november());

        self::assertResponseStatusCodeSame(404);
        self::assertSame($unknown, json_decode((string) $this->client->getResponse()->getContent(), true));
        self::assertSame(0, $this->creditsOf($ctx['club']));
    }

    public function testAnUnreachableWorkerIsA502AndCostsNothing(): void
    {
        $ctx = $this->seedClub();
        $this->worker(static function (): ResponseInterface {
            throw new TransportException('connexion refusée');
        });

        $this->export($ctx['user'], $this->november());

        self::assertResponseStatusCodeSame(502);
        self::assertSame('Le document n\'a pas pu être généré.', $this->errorBody());
        self::assertSame(0, $this->creditsOf($ctx['club']));
    }

    public function testAWorkerRefusalIsA502AndCostsNothing(): void
    {
        $ctx = $this->seedClub();
        $this->worker(static fn (): ResponseInterface => new MockResponse((string) json_encode(['success' => false, 'error' => 'Protocol error'])));

        $this->export($ctx['user'], $this->november());

        self::assertResponseStatusCodeSame(502);
        self::assertSame('Le document n\'a pas pu être généré.', $this->errorBody());
        self::assertSame(0, $this->creditsOf($ctx['club']));
    }

    /** @param array<string, mixed> $body */
    #[DataProvider('invalidRanges')]
    public function testAnInvalidRangeIs422AndCostsNothing(array $body): void
    {
        $ctx = $this->seedClub();
        $this->workerWrites();

        $this->export($ctx['user'], $body);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Plage de dates invalide.', $this->errorBody());
        self::assertSame(0, $this->creditsOf($ctx['club']));
        self::assertSame([], $this->workerPayloads);
    }

    /** Une plage hors saison n'est pas une erreur : le document dit qu'il n'y a rien. */
    public function testARangeOutsideTheSeasonStillGivesADocument(): void
    {
        $ctx = $this->seedClub();
        $this->matchIn($ctx, 'Les Lions');
        $this->workerWrites();

        $this->export($ctx['user'], ['from' => ($this->year + 5) . '-01-01', 'to' => ($this->year + 5) . '-01-31', 'teamId' => null]);

        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString('Aucun match sur cette période.', (string) ($this->workerPayloads[0]['html'] ?? ''));
    }

    /** Abus « plage énorme » : pas de plafond, la lecture reste bornée par la saison. */
    public function testATenYearRangeStaysFast(): void
    {
        $ctx = $this->seedClub();
        $this->matchIn($ctx, 'Les Lions');
        $this->workerWrites();

        $start = microtime(true);
        $this->export($ctx['user'], ['from' => $this->year . '-01-01', 'to' => ($this->year + 10) . '-01-01', 'teamId' => null]);

        self::assertResponseStatusCodeSame(200);
        self::assertLessThan(2.0, microtime(true) - $start);
    }

    public function testAClubWithoutSeasonIs400(): void
    {
        $ctx = $this->seedClub(withSeason: false);
        $this->workerWrites();

        $this->export($ctx['user'], $this->november());

        self::assertResponseStatusCodeSame(400);
        self::assertSame('Club ou saison introuvable dans le contexte.', $this->errorBody());
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        // Le double du worker survit d'une requête à l'autre : pas de reboot du kernel.
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->year = SeasonResolver::seasonYear(new DateTimeImmutable('today'));
    }

    protected function tearDown(): void
    {
        foreach ($this->writtenFiles as $path) {
            @unlink($path);
        }
        parent::tearDown();
    }

    /** @return array{from: string, to: string, teamId: null} une plage de la saison courante */
    private function november(): array
    {
        return ['from' => $this->year . '-11-01', 'to' => $this->year . '-11-30', 'teamId' => null];
    }

    private function workerWrites(): void
    {
        $this->worker(function (string $method, string $url, array $options): ResponseInterface {
            $payload = json_decode((string) $options['body'], true);
            \assert(\is_array($payload));
            $this->workerPayloads[] = $payload;
            $path = PdfGenerator::outputDir() . '/' . $payload['filename'];
            if (!is_dir(PdfGenerator::outputDir())) {
                @mkdir(PdfGenerator::outputDir(), 0o775, true);
            }
            file_put_contents($path, '%PDF-1.4 test');
            $this->writtenFiles[] = $path;

            return new MockResponse((string) json_encode(['success' => true]));
        });
    }

    private function worker(callable $responseFactory): void
    {
        $reflection = new ReflectionClass(PdfGenerator::class);
        $pdf = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('httpClient')->setValue($pdf, new MockHttpClient($responseFactory));
        $reflection->getProperty('brandAssets')->setValue($pdf, new BrandAssets);
        $reflection->getProperty('productIdentity')->setValue($pdf, new ProductIdentity(productName: 'Zephyr'));
        self::getContainer()->set(PdfGenerator::class, $pdf);
    }

    /** @param array<string, mixed> $body */
    private function export(User $user, array $body): void
    {
        $token = self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
        $this->em->clear();
        $this->client->request('POST', self::URI, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'CONTENT_TYPE' => 'application/json',
            'REMOTE_ADDR' => \sprintf('10.%d.%d.%d', random_int(1, 254), random_int(0, 254), random_int(1, 254)),
        ], json_encode($body, \JSON_THROW_ON_ERROR));
    }

    private function errorBody(): ?string
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true);

        return \is_array($decoded) && \is_string($decoded['error'] ?? null) ? $decoded['error'] : null;
    }

    private function creditsOf(Club $club): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT output_credits_used FROM club WHERE id = :id', ['id' => $club->getId()]);
    }

    /** @return array{user: User, club: Club, season: Season, team: Team} */
    private function seedClub(ClubRole $role = ClubRole::MANAGER, int $credits = 0, ?string $planCode = null, bool $withSeason = true): array
    {
        $uid = uniqid('', true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $club = new Club;
        $club->setName('Les Lions ' . $uid);
        $club->setSlug('lions-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setOutputCreditsUsed($credits);
        if (null !== $planCode) {
            $plan = $this->em->getRepository(SubscriptionPlan::class)->findOneBy(['code' => $planCode]);
            \assert($plan instanceof SubscriptionPlan);
            $club->setPlanId($plan->getId());
            $club->setPaidSeasonYear($this->year);
        }
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('lions-' . $uid . '@test.com');
        $user->setFirstName('Lea');
        $user->setLastName('Martin');
        $user->setPasswordHash($hasher->hashPassword($user, 'pass'));
        $this->em->persist($user);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());

        $membership = new ClubUser;
        $membership->setClubId($club->getId());
        $membership->setUserId($user->getId());
        $membership->setRole($role->value);
        $membership->setIsActive(true);
        $this->em->persist($membership);

        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName((string) $this->year);
        $season->setStartDate(new DateTimeImmutable($this->year . '-08-01'));
        $season->setEndDate(new DateTimeImmutable(($this->year + 1) . '-07-15'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $season->setTransitionData([]);
        if ($withSeason) {
            $this->em->persist($season);
        }
        $this->em->flush();

        $ctx = ['user' => $user, 'club' => $club, 'season' => $season];

        return $ctx + ['team' => $withSeason ? $this->team($ctx, 'U13 M1') : new Team];
    }

    /** @param array{club: Club, season: Season} $ctx */
    private function team(array $ctx, string $name): Team
    {
        $this->scopeGucToClub($ctx['club']->getId());
        $team = (new Team)
            ->setClubId($ctx['club']->getId())
            ->setSeasonId($ctx['season']->getId())
            ->setSportCategoryId('11111111-1111-4111-8111-111111111111')
            ->setPriorityTierId(1)
            ->setName($name)
            ->setSessionsPerWeek(2)
            ->setIsActive(true);
        $this->em->persist($team);
        $this->em->flush();

        return $team;
    }

    /** @param array{club: Club, season: Season, team: Team} $ctx */
    private function matchIn(array $ctx, string $opponent, ?Team $team = null): void
    {
        $this->scopeGucToClub($ctx['club']->getId());
        $fixture = new Fixture;
        $fixture->setClubId($ctx['club']->getId());
        $fixture->setSeasonId($ctx['season']->getId());
        $fixture->setTeamId(($team ?? $ctx['team'])->getId());
        $fixture->setMatchDate(new DateTimeImmutable($this->year . '-11-14'));
        $fixture->setHomeAway(FixtureHomeAway::AWAY);
        $fixture->setOpponentLabel($opponent);
        $this->em->persist($fixture);
        $this->em->flush();
    }
}
