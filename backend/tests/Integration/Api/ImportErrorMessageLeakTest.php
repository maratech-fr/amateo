<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\Club;
use App\Entity\Season;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\TenantGucTrait;
use App\Tests\VerifiesRegistration;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use ZipArchive;

/**
 * NR — un import en échec ne renvoie JAMAIS la chaîne d'une dépendance (P4-5).
 *
 * Les contrôleurs d'import relayaient `$e->getMessage()` en se fiant à la CLASSE
 * de l'exception. Or `PhpOffice\PhpSpreadsheet\Reader\Exception` **étend
 * `RuntimeException`** : une exception de librairie tombait dans le même `catch`
 * et sa chaîne partait en 422. Reproduit avant correctif :
 *
 *     File "/var/www/html/var/tmp/php7Zx9Qa" does not exist or is not readable.
 *
 * Ce test tient les DEUX bords, et c'est le second qui compte autant que le
 * premier : masquer tout échec derrière un message générique fermerait bien la
 * fuite, mais rendrait l'import inutilisable (le gestionnaire ne saurait plus
 * qu'il lui manque une colonne). Un message MÉTIER doit continuer de passer.
 */
#[Group('integration')]
final class ImportErrorMessageLeakTest extends WebTestCase
{
    use ChoosesPlanVersionTrait;
    use TenantGucTrait;
    use VerifiesRegistration;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    /** @var list<string> */
    private array $tempFiles = [];

    /**
     * Un .xlsx illisible : PhpSpreadsheet jette, et rien de sa chaîne ne sort.
     *
     * L'extension et le type MIME sont ceux d'un vrai classeur — sans quoi le
     * garde de format du contrôleur répondrait 400 AVANT d'atteindre le parseur,
     * et le test ne prouverait rien du chemin qu'il prétend garder. Même raison pour
     * la garde de fraîcheur des exports FBI : le zip porte un `docProps/core.xml`
     * daté de l'instant (il la franchit), mais son classeur est illisible.
     */
    public function testALibraryFailureNeverLeaksItsMessage(): void
    {
        [$token] = $this->registerWithTeam();

        $path = tempnam(sys_get_temp_dir(), 'leak') . '.xlsx';
        $this->tempFiles[] = $path;
        $zip = new ZipArchive;
        self::assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip->addFromString('docProps/core.xml', '<?xml version="1.0" encoding="UTF-8"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dcterms="http://purl.org/dc/terms/"><dcterms:created>' . gmdate('Y-m-d\TH:i:s\Z') . '</dcterms:created></cp:coreProperties>');
        $zip->addFromString('xl/workbook.xml', 'ceci n\'est pas un classeur');
        $zip->close();

        $this->client->request('POST', '/api/fixtures/import', [], [
            'file' => new UploadedFile($path, 'fbi.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
        ], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);

        self::assertResponseStatusCodeSame(422);
        $error = (string) (json_decode((string) $this->client->getResponse()->getContent(), true)['error'] ?? '');

        // Le message générique, et RIEN de la librairie ni du système de fichiers.
        self::assertStringContainsString('Le fichier n’a pas pu être lu', str_replace('\'', '’', $error));
        foreach (['/var/www', '/tmp', sys_get_temp_dir(), 'PhpOffice', 'Spreadsheet', 'reader', 'Reader'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $error, \sprintf('La réponse d’erreur ne doit rien révéler du serveur ni de la librairie — « %s » trouvé.', $forbidden));
        }
    }

    /**
     * L'autre bord : un refus MÉTIER garde son message, sinon le correctif
     * rendrait l'import muet sur ce que le gestionnaire doit corriger.
     */
    public function testABusinessRejectionStillReachesTheUser(): void
    {
        [$token] = $this->registerWithTeam();

        // Classeur valide, mais sans les colonnes attendues par l'import FBI.
        $file = $this->xlsxWithHeader(['Colonne A', 'Colonne B']);

        $this->client->request('POST', '/api/fixtures/import', [], [
            'file' => new UploadedFile($file, 'fbi.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
        ], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);

        self::assertResponseStatusCodeSame(400);
        $error = (string) (json_decode((string) $this->client->getResponse()->getContent(), true)['error'] ?? '');
        self::assertStringContainsString('Colonnes requises manquantes', $error, 'Le message métier doit continuer d’atteindre le gestionnaire.');
    }

    /**
     * Le MÊME contrat sur l'import d'ÉQUIPES FFBB (`ImportController`).
     *
     * C'est cet endpoint que le docblock accuse : `FfbbExcelImporter` appelle
     * `IOFactory::load()` SANS reader épinglé — contrairement à l'import de
     * rencontres qui force `READER_XLSX` — donc c'est le chemin le plus exposé à
     * l'exception fuyante. Ne tester que l'autre contrôleur laisserait une
     * réécriture de ce `catch` réintroduire la divulgation, CI verte.
     */
    public function testTheTeamsImportAlsoHidesLibraryMessages(): void
    {
        [$token, , , $clubId, $seasonId] = $this->registerWithTeam();

        $path = tempnam(sys_get_temp_dir(), 'leak') . '.xlsx';
        file_put_contents($path, "PK\x03\x04 ceci n'est pas un classeur");
        $this->tempFiles[] = $path;

        $this->client->request('POST', '/api/clubs/' . $clubId . '/import-teams', ['seasonId' => $seasonId], [
            'file' => new UploadedFile($path, 'ffbb.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
        ], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);

        // Le test doit atteindre le PARSEUR, pas s'arrêter à un garde amont : sinon il
        // vérifierait l'absence de fuite sur une réponse qui n'a jamais lu le fichier.
        self::assertResponseStatusCodeSame(422);
        $error = (string) (json_decode((string) $this->client->getResponse()->getContent(), true)['error'] ?? '');
        self::assertStringContainsString('Le fichier n’a pas pu être lu', str_replace('\'', '’', $error));
        foreach (['/var/www', '/tmp', sys_get_temp_dir(), 'PhpOffice', 'Spreadsheet'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $error, \sprintf('L’import d’équipes ne doit rien révéler du serveur — « %s » trouvé.', $forbidden));
        }
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

    /** @param list<string> $header */
    private function xlsxWithHeader(array $header): string
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray($header, null, 'A1');
        $path = tempnam(sys_get_temp_dir(), 'hdr') . '.xlsx';
        new Xlsx($spreadsheet)->save($path);
        $this->tempFiles[] = $path;

        return $path;
    }

    /**
     * Même montage que `ImportFixturesApiTest` : l'import de rencontres est une
     * écriture du module matchs, refusée en 409 tant que le plan de la saison ne
     * pointe pas une version — d'où `settleSeasonPlan`.
     *
     * @return array{0: string, 1: string, 2: string, 3: string, 4: string} [token, clubName, teamId, clubId, seasonId]
     */
    private function registerWithTeam(): array
    {
        $ip = \sprintf('10.%d.%d.%d', random_int(1, 254), random_int(0, 254), random_int(1, 254));
        $suffix = 'leak' . substr(md5(uniqid('', true)), 0, 6);
        $clubName = 'BC ' . ucfirst($suffix);
        $this->client->request('POST', '/api/register', [], [], [
            'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $ip,
        ], json_encode([
            'email' => $suffix . '@test.fr', 'password' => 'Password123!',
            'firstName' => 'F', 'lastName' => 'Bi', 'ara' => strtoupper($suffix), 'club_name' => $clubName, 'consent' => true,
        ], \JSON_THROW_ON_ERROR));
        $token = $this->verifyRegistration($this->client, $suffix . '@test.fr');
        self::assertNotSame('', $token);

        $this->client->request('GET', '/api/me', [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
        $clubId = json_decode((string) $this->client->getResponse()->getContent(), true)['club']['id'];

        $this->scopeGucToClub($clubId);
        $season = $this->em->getRepository(Season::class)->findOneBy(['clubId' => $clubId]);
        self::assertNotNull($season);
        $this->settleSeasonPlan($season);

        $sport = $this->em->getRepository(Sport::class)->findOneBy(['isActive' => true]);
        self::assertNotNull($sport, 'register seeds the basketball sport');
        $category = new SportCategory;
        $category->setClubId($clubId);
        $category->setSportId($sport->getId());
        $category->setName('U13-' . uniqid('', true));
        $this->em->persist($category);

        $team = new Team;
        $team->setClubId($clubId);
        $team->setSeasonId($season->getId());
        $team->setSportCategoryId($category->getId());
        $team->setPriorityTierId(3);
        $team->setName('U13-1');
        $team->setSessionsPerWeek(2);
        $team->setIsActive(true);
        $this->em->persist($team);

        // Sans code FFBB, l'import s'arrête sur un refus MÉTIER en amont du parseur :
        // le test vérifierait alors l'absence de fuite sur une réponse qui n'a jamais
        // ouvert le fichier.
        $club = $this->em->getRepository(Club::class)->find($clubId);
        self::assertNotNull($club);
        $club->setFfbbClubCode('BC0001');

        $this->em->flush();

        return [$token, $clubName, $team->getId(), $clubId, $season->getId()];
    }
}
