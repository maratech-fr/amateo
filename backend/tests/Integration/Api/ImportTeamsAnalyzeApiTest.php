<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\ClubUser;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\SeasonStatus;
use App\Service\TeamImportGate;
use App\Tests\TenantGucTrait;
use App\Tests\VerifiesRegistration;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * P3-7 — l'analyse (`POST /api/clubs/{id}/import-teams/analyze`) et l'import
 * (`POST /api/clubs/{id}/import-teams`) partagent la MÊME gate ({@see TeamImportGate}).
 * Sur chaque refus (404/403/409/413/429 + seasonId), les deux endpoints doivent
 * répondre BYTE-IDENTIQUEMENT : c'est l'invariant que garde cette suite. Une
 * divergence (un contrôleur qui reformule un refus, ou une gate qui se dédouble)
 * la casse.
 *
 * ⚠ Limiteur Redis keyé PAR UTILISATEUR : chaque test crée sa propre identité —
 * aucun FLUSHALL, aucune fuite entre suites.
 */
#[Group('integration')]
final class ImportTeamsAnalyzeApiTest extends WebTestCase
{
    use TenantGucTrait;
    use VerifiesRegistration;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    /** @var list<string> */
    private array $tempFiles = [];

    public function testUnknownClubRefusalIsIdentical(): void
    {
        [$token] = $this->registerManager('IAA');
        // Un uuid dont l'appelant n'est pas membre : 404 (jamais 403, qui fuiterait l'existence).
        $randomClub = \sprintf('%s-%s-4%s-8%s-%s', substr(md5('a' . uniqid()), 0, 8), substr(md5('b' . uniqid()), 0, 4), substr(md5('c' . uniqid()), 0, 3), substr(md5('d' . uniqid()), 0, 3), substr(md5('e' . uniqid()), 0, 12));

        $this->assertIdenticalRefusal($randomClub, $token, 404);
    }

    public function testNonManagerRefusalIsIdentical(): void
    {
        [, $clubId] = $this->registerManager('IAB');
        $editorToken = $this->addActiveMember($clubId, 'editor');

        $this->assertIdenticalRefusal($clubId, $editorToken, 403);
    }

    public function testOversizedUploadRefusalIsIdentical(): void
    {
        [$token, $clubId] = $this->registerManager('IAC');

        $this->assertIdenticalRefusal($clubId, $token, 413, filesFactory: fn (): array => [
            'file' => new UploadedFile($this->bigFile(), 'ffbb.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
        ]);
    }

    public function testArchivedSeasonRefusalIsIdentical(): void
    {
        [$token, $clubId] = $this->registerManager('IAD');

        // Une saison PASSÉE (année-saison antérieure à la courante) est en lecture
        // seule : l'écrire est refusée en 409, avant même le garde d'upload.
        $this->scopeGucToClub($clubId);
        $old = (new Season)->setClubId($clubId)->setName('2000-2001')
            ->setStartDate(new DateTimeImmutable('2000-07-15'))->setEndDate(new DateTimeImmutable('2001-07-14'))
            ->setStatus(SeasonStatus::ACTIVE)->setTransitionData([]);
        $this->em->persist($old);
        $this->em->flush();

        $this->assertIdenticalRefusal($clubId, $token, 409, server: ['HTTP_X_SEASON_ID' => $old->getId()]);
    }

    public function testMissingSeasonIdRefusalIsIdentical(): void
    {
        [$token, $clubId] = $this->registerManager('IAE');

        // Un vrai .xlsx (le garde d'upload passe), mais aucun seasonId dans le corps.
        $this->assertIdenticalRefusal($clubId, $token, 400, filesFactory: fn (): array => [
            'file' => new UploadedFile($this->smallXlsx(), 'ffbb.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
        ]);
    }

    public function testRateLimitRefusalIsIdentical(): void
    {
        [$token, $clubId] = $this->registerManager('IAF');

        // La borne when@test est 5/15min. Les 5 premières passent la gate puis butent
        // sur « aucun fichier » (400) : le budget est consommé à chaque fois.
        for ($i = 1; $i <= 5; ++$i) {
            $this->post('/api/clubs/' . $clubId . '/import-teams', [], [], $token, []);
            self::assertResponseStatusCodeSame(400, \sprintf('la requête %d doit passer le limiteur', $i));
        }

        // 6e (analyse) et 7e (import) : toutes deux au-delà de la borne → 429 identique.
        $this->assertIdenticalRefusal($clubId, $token, 429);
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    /**
     * Tire la MÊME requête sur les deux endpoints et exige un statut ET un corps
     * identiques. `$filesFactory` fabrique un jeu de fichiers FRAIS par appel (un
     * UploadedFile ne se rejoue pas entre deux requêtes).
     *
     * @param array<string, string>                          $params
     * @param array<string, string>                          $server
     * @param (callable(): array<string, UploadedFile>)|null $filesFactory
     */
    private function assertIdenticalRefusal(string $clubId, string $token, int $expectedStatus, array $params = [], array $server = [], ?callable $filesFactory = null): void
    {
        $filesFactory ??= static fn (): array => [];

        $import = $this->post('/api/clubs/' . $clubId . '/import-teams', $params, $filesFactory(), $token, $server);
        $analyze = $this->post('/api/clubs/' . $clubId . '/import-teams/analyze', $params, $filesFactory(), $token, $server);

        self::assertSame($expectedStatus, $import['status'], 'import : statut attendu');
        self::assertSame($import['status'], $analyze['status'], 'analyse et import : même statut de refus');
        self::assertSame(
            $this->clientVisibleBody($import['body']),
            $this->clientVisibleBody($analyze['body']),
            'analyse et import : corps de refus identique (hors trace de debug dev, absente en prod)',
        );
    }

    /**
     * Le corps tel que le CLIENT le voit en prod : on retire la clé `trace` que
     * l'error-handler API Platform n'ajoute qu'en dev/test et qui, par nature,
     * nomme le contrôleur traversé (le seul point où les deux endpoints diffèrent
     * sur le chemin d'exception non capturée du 409).
     */
    private function clientVisibleBody(string $body): string
    {
        $decoded = json_decode($body, true);
        if (!\is_array($decoded)) {
            return $body;
        }
        unset($decoded['trace']);

        return json_encode($decoded, \JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, string>       $params
     * @param array<string, UploadedFile> $files
     * @param array<string, string>       $server
     *
     * @return array{status: int, body: string}
     */
    private function post(string $path, array $params, array $files, string $token, array $server): array
    {
        $this->client->request('POST', $path, $params, $files, ['HTTP_AUTHORIZATION' => 'Bearer ' . $token] + $server);
        $response = $this->client->getResponse();

        return ['status' => $response->getStatusCode(), 'body' => (string) $response->getContent()];
    }

    private function bigFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'big') . '.xlsx';
        file_put_contents($path, str_repeat('x', (int) (2.1 * 1024 * 1024)));
        $this->tempFiles[] = $path;

        return $path;
    }

    private function smallXlsx(): string
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray(['Nom', 'Catégorie', 'Numéro', 'Organisme'], null, 'A1');
        $path = tempnam(sys_get_temp_dir(), 'small') . '.xlsx';
        new Xlsx($spreadsheet)->save($path);
        $this->tempFiles[] = $path;

        return $path;
    }

    /**
     * @return array{0: string, 1: string} [token, clubId]
     */
    private function registerManager(string $ara): array
    {
        $ip = \sprintf('10.%d.%d.%d', random_int(1, 254), random_int(0, 254), random_int(1, 254));
        $suffix = strtolower($ara) . substr(md5(uniqid('', true)), 0, 6);
        $this->client->request('POST', '/api/register', [], [], [
            'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $ip,
        ], json_encode([
            'email' => $suffix . '@test.fr', 'password' => 'Password123!',
            'firstName' => 'F', 'lastName' => 'Ia', 'ara' => strtoupper($suffix), 'club_name' => 'Club ' . $ara, 'consent' => true,
        ], \JSON_THROW_ON_ERROR));
        $token = $this->verifyRegistration($this->client, $suffix . '@test.fr');
        self::assertNotSame('', $token);

        $this->client->request('GET', '/api/me', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        $clubId = json_decode((string) $this->client->getResponse()->getContent(), true)['club']['id'];
        self::assertIsString($clubId);

        return [$token, $clubId];
    }

    private function addActiveMember(string $clubId, string $role): string
    {
        $container = self::getContainer();
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $uid = substr(md5(uniqid('', true)), 0, 8);
        $user = new User;
        $user->setEmail($role . $uid . '@test.fr');
        $user->setFirstName('N');
        $user->setLastName('Member');
        $user->setPasswordHash($hasher->hashPassword($user, 'Password123!'));
        $this->em->persist($user);

        $this->scopeGucToClub($clubId);
        $membership = new ClubUser;
        $membership->setClubId($clubId);
        $membership->setUserId($user->getId());
        $membership->setRole($role);
        $membership->setIsActive(true);
        $this->em->persist($membership);
        $this->em->flush();

        return $container->get(JWTTokenManagerInterface::class)->create($user);
    }
}
