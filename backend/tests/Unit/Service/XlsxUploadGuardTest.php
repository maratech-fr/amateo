<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\XlsxUploadGuard;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use ZipArchive;

/**
 * SEC-22 — les trois bornes applicatives de l'upload xlsx, mesurées SANS faire
 * confiance à un en-tête : octets uploadés (2 Mo), décompressé cumulé (20 Mo),
 * lignes (5 000). La borne ouvre le zip et INFLATE ; elle ne lit jamais les
 * tailles déclarées ni `<dimension ref>`.
 *
 * Le cas VERT est le fichier réel du fondateur (22 Ko / 291 lignes / ×7,3 à la
 * décompression), utilisé EN LECTURE SEULE.
 */
#[Group('unit')]
final class XlsxUploadGuardTest extends TestCase
{
    private const string REAL_FILE = __DIR__ . '/../../../../specs/initiales/rechercherRencontre.xlsx';

    private const string XLSX_MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    /** @var list<string> */
    private array $tempFiles = [];

    public function testTheRealFounderExportPasses(): void
    {
        self::assertFileExists(self::REAL_FILE, 'le fichier réel versionné doit exister');
        $result = $this->guard()->requireXlsxFile($this->requestWithFile(self::REAL_FILE, 'rechercherRencontre.xlsx'));
        self::assertInstanceOf(UploadedFile::class, $result, 'le fichier réel du fondateur doit franchir les bornes');
    }

    public function testOverTwoMegabytesIsRefusedBeforeParsing(): void
    {
        // 2,1 Mo d'octets quelconques nommés .xlsx : la borne octets tombe AVANT
        // toute ouverture du zip (le contenu n'est même pas un classeur).
        $path = $this->tempFile('big');
        file_put_contents($path, str_repeat('x', (int) (2.1 * 1024 * 1024)));

        $result = $this->guard()->requireXlsxFile($this->requestWithFile($path, 'big.xlsx'));
        $this->assertRefused($result, 413, '2 Mo');
    }

    public function testInflatedOverTwentyMegabytesIsRefused(): void
    {
        // ~25 Mo de remplissage SANS balise `<row` → c'est la borne décompressé
        // (20 Mo) qui tombe, pas celle des lignes.
        $path = $this->xlsxWithSheetXml(
            '<?xml version="1.0" encoding="UTF-8"?><worksheet><sheetData></sheetData><pad>'
            . str_repeat('x', 25 * 1024 * 1024)
            . '</pad></worksheet>',
        );

        $result = $this->guard()->requireXlsxFile($this->requestWithFile($path, 'bomb.xlsx'));
        $this->assertRefused($result, 413, '20 Mo');
    }

    public function testOverFiveThousandRowsIsRefused(): void
    {
        $path = $this->xlsxWithSheetXml(
            '<?xml version="1.0" encoding="UTF-8"?><worksheet><sheetData>'
            . str_repeat('<row/>', 5001)
            . '</sheetData></worksheet>',
        );

        $result = $this->guard()->requireXlsxFile($this->requestWithFile($path, 'rows.xlsx'));
        $this->assertRefused($result, 413, '5 000');
    }

    public function testRowCountSurvivesTinyChunksAtTagBoundaries(): void
    {
        // Le piège nommé par le plan : un chunk peut couper `<row/>` en deux. Avec
        // une taille de chunk de 4 octets, chaque balise chevauche une frontière —
        // le tampon de recouvrement doit quand même compter 5 001.
        $path = $this->xlsxWithSheetXml(
            '<?xml version="1.0" encoding="UTF-8"?><worksheet><sheetData>'
            . str_repeat('<row/>', 5001)
            . '</sheetData></worksheet>',
        );

        $result = $this->guard(chunkSize: 4)->requireXlsxFile($this->requestWithFile($path, 'rows.xlsx'));
        $this->assertRefused($result, 413, '5 000');
    }

    public function testLyingDimensionCannotHideTheRowCount(): void
    {
        // `<dimension>` ment (A1:L2) mais la feuille porte 6 000 `<row>` : on mesure
        // en inflatant, jamais la dimension déclarée → refus « 5 000 ».
        $path = $this->xlsxWithSheetXml(
            '<?xml version="1.0" encoding="UTF-8"?><worksheet><dimension ref="A1:L2"/><sheetData>'
            . str_repeat('<row r="1"><c/></row>', 6000)
            . '</sheetData></worksheet>',
        );

        $result = $this->guard()->requireXlsxFile($this->requestWithFile($path, 'liar.xlsx'));
        $this->assertRefused($result, 413, '5 000');
    }

    public function testUploadIniSizeErrorIsRefusedNamingTheByteBound(): void
    {
        $path = $this->tempFile('ini');
        file_put_contents($path, '');
        $file = new UploadedFile($path, 'fbi.xlsx', self::XLSX_MIME, \UPLOAD_ERR_INI_SIZE, true);

        $result = $this->guard()->requireXlsxFile($this->requestWithUploadedFile($file));
        $this->assertRefused($result, 413, '2 Mo');
    }

    public function testOtherUploadErrorIsAnHonestFourHundred(): void
    {
        $path = $this->tempFile('partial');
        file_put_contents($path, '');
        $file = new UploadedFile($path, 'fbi.xlsx', self::XLSX_MIME, \UPLOAD_ERR_PARTIAL, true);

        $result = $this->guard()->requireXlsxFile($this->requestWithUploadedFile($file));
        self::assertInstanceOf(JsonResponse::class, $result);
        self::assertSame(400, $result->getStatusCode(), 'un upload tronqué n\'est pas un « trop gros » mais un 400 honnête');
    }

    public function testMissingFileWithOversizedContentLengthTellsTheTruth(): void
    {
        // Cas > post_max_size : $_FILES est vide. Content-Length dépasse la borne
        // applicative → 413 nommant 2 Mo, pas le 400 mensonger « aucun fichier ».
        $request = Request::create('/api/fixtures/import', 'POST');
        $request->headers->set('Content-Length', (string) (3 * 1024 * 1024));

        $result = $this->guard()->requireXlsxFile($request);
        $this->assertRefused($result, 413, '2 Mo');
    }

    public function testMissingFileWithoutContentLengthFallsBackToFourHundred(): void
    {
        $result = $this->guard()->requireXlsxFile(Request::create('/api/fixtures/import', 'POST'));
        self::assertInstanceOf(JsonResponse::class, $result);
        self::assertSame(400, $result->getStatusCode());
    }

    public function testNonXlsxExtensionIsRefusedAsBadFormat(): void
    {
        $path = $this->tempFile('csv');
        file_put_contents($path, 'a;b;c');

        $result = $this->guard()->requireXlsxFile($this->requestWithFile($path, 'teams.csv', 'text/csv'));
        self::assertInstanceOf(JsonResponse::class, $result);
        self::assertSame(400, $result->getStatusCode(), 'un .csv est refusé en 400 format, jamais lu comme un zip');
    }

    public function testNonZipNamedXlsxIsLeftToTheDownstreamParser(): void
    {
        // Un fichier illisible comme zip n'a aucun inflate à borner : le garde ne le
        // refuse PAS (il le laisse au parseur en aval, dont le filet générique P4-5
        // ne fuit rien — c'est ce que garde ImportErrorMessageLeakTest). Refuser ici
        // rendrait ce NR intestable (400 au lieu du 422 attendu).
        $path = $this->tempFile('fake');
        file_put_contents($path, "PK\x03\x04 ceci n'est pas un classeur");

        $result = $this->guard()->requireXlsxFile($this->requestWithFile($path, 'fake.xlsx'));
        self::assertInstanceOf(UploadedFile::class, $result, 'un zip illisible passe le garde, le parseur tranchera');
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    private function guard(int $chunkSize = 65536): XlsxUploadGuard
    {
        return new XlsxUploadGuard($chunkSize);
    }

    private function assertRefused(JsonResponse|UploadedFile $result, int $status, string $needle): void
    {
        self::assertInstanceOf(JsonResponse::class, $result, 'le fichier devait être refusé');
        self::assertSame($status, $result->getStatusCode());
        $error = (string) (json_decode((string) $result->getContent(), true)['error'] ?? '');
        self::assertStringContainsString($needle, $error, \sprintf('le message doit nommer « %s » ; obtenu : %s', $needle, $error));
        self::assertStringContainsString('découper', $error, 'le message mentionne le découpage par équipe/date comme issue possible');
    }

    private function requestWithFile(string $path, string $clientName, string $mime = self::XLSX_MIME): Request
    {
        return $this->requestWithUploadedFile(new UploadedFile($path, $clientName, $mime, null, true));
    }

    private function requestWithUploadedFile(UploadedFile $file): Request
    {
        return Request::create('/api/fixtures/import', 'POST', [], [], ['file' => $file]);
    }

    /**
     * Un petit classeur PhpSpreadsheet dont on RÉÉCRIT la feuille via ZipArchive —
     * la technique qui amplifie ou multiplie les lignes sans binaire lourd commité.
     */
    private function xlsxWithSheetXml(string $sheetXml): string
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray([['A']], null, 'A1');
        $path = $this->tempFile('sheet');
        new Xlsx($spreadsheet)->save($path);

        $zip = new ZipArchive;
        $zip->open($path);
        $zip->deleteName('xl/worksheets/sheet1.xml');
        $zip->close();
        $zip->open($path);
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
        $zip->close();

        return $path;
    }

    private function tempFile(string $prefix): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix) . '.xlsx';
        $this->tempFiles[] = $path;

        return $path;
    }
}
