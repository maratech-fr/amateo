<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Service\XlsxUploadGuard;
use App\Tests\VerifiesRegistration;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * SEC-22 — la surface d'upload xlsx des ÉQUIPES (`POST /api/clubs/{id}/import-teams`,
 * ImportController) partage le même garde ({@see XlsxUploadGuard}) que
 * l'import de rencontres : la borne octets (2 Mo) tombe AVANT tout parsing, avec un
 * refus 413 lisible.
 */
#[Group('integration')]
final class ImportTeamsUploadGuardTest extends WebTestCase
{
    use VerifiesRegistration;

    private KernelBrowser $client;

    /** @var list<string> */
    private array $tempFiles = [];

    public function testUploadOverTwoMegabytesIsRefusedOnTeamsImport(): void
    {
        [$token, $clubId] = $this->register('TUP');

        $path = tempnam(sys_get_temp_dir(), 'big') . '.xlsx';
        file_put_contents($path, str_repeat('x', (int) (2.1 * 1024 * 1024)));
        $this->tempFiles[] = $path;

        $this->client->request('POST', '/api/clubs/' . $clubId . '/import-teams', [], [
            'file' => new UploadedFile($path, 'ffbb.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
        ], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);

        self::assertResponseStatusCodeSame(413);
        $error = (string) (json_decode((string) $this->client->getResponse()->getContent(), true)['error'] ?? '');
        self::assertStringContainsString('2 Mo', $error);
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    /**
     * @return array{0: string, 1: string} [token, clubId]
     */
    private function register(string $ara): array
    {
        $ip = \sprintf('10.%d.%d.%d', random_int(1, 254), random_int(0, 254), random_int(1, 254));
        $suffix = strtolower($ara) . substr(md5(uniqid('', true)), 0, 6);
        $this->client->request('POST', '/api/register', [], [], [
            'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $ip,
        ], json_encode([
            'email' => $suffix . '@test.fr', 'password' => 'Password123!',
            'firstName' => 'F', 'lastName' => 'Teams', 'ara' => strtoupper($suffix), 'club_name' => 'Club ' . $ara, 'consent' => true,
        ], \JSON_THROW_ON_ERROR));

        $token = $this->verifyRegistration($this->client, $suffix . '@test.fr');
        self::assertNotSame('', $token);

        $this->client->request('GET', '/api/me', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        $clubId = json_decode((string) $this->client->getResponse()->getContent(), true)['club']['id'];

        return [$token, $clubId];
    }
}
