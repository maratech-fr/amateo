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

    public function testSheetRelocatedOutsideWorksheetsIsStillCounted(): void
    {
        // L'emplacement d'une feuille dans un .xlsx n'est PAS imposé (OPC) : la
        // cible est déclarée par xl/_rels/workbook.xml.rels, le format autorise
        // n'importe quel chemin de partie. PhpSpreadsheet suit les rels et lit une
        // feuille rangée en xl/data/s1.xml ; la garde, qui ne parse pas les rels,
        // doit donc compter les `<row` sur TOUTE partie XML — sinon 5 001 lignes
        // hors xl/worksheets/ passeraient inaperçues (le trou que SEC-22 comble).
        // Falsification : restaurer `str_starts_with($name, 'xl/worksheets/')`
        // dans inspectArchive → ce test rougit (aucune ligne comptée).
        $path = $this->xlsxWithExtraXmlPart(
            'xl/data/s1.xml',
            '<?xml version="1.0" encoding="UTF-8"?><worksheet><sheetData>'
            . str_repeat('<row/>', 5001)
            . '</sheetData></worksheet>',
        );

        $result = $this->guard()->requireXlsxFile($this->requestWithFile($path, 'relocated.xlsx'));
        $this->assertRefused($result, 413, '5 000');
    }

    public function testDuplicateEntryNamesAreEachMeasuredByIndex(): void
    {
        // Zip à deux entrées HOMONYMES : la première minuscule, la seconde >20 Mo
        // décompressés. `getStream($name)` rendrait deux fois la PREMIÈRE et la
        // seconde échapperait au comptage ; `getStreamIndex($i)` inflate l'entrée
        // que la boucle visite → les octets de la seconde comptent → refus « 20 Mo ».
        // Falsification : revenir à `getStream($name)` → ce test rougit (les octets
        // volumineux ne sont jamais lus). ZipArchive dédoublonne à l'écriture, d'où
        // le zip fabriqué à la main (en-têtes locaux + annuaire central).
        $path = $this->zipWithDuplicateEntries(
            'xl/worksheets/sheet1.xml',
            '<x/>',
            str_repeat('x', 25 * 1024 * 1024),
        );
        self::assertLessThan(
            2 * 1024 * 1024,
            (int) filesize($path),
            'le zip compressé reste sous la borne octets, pour que ce soit bien la borne décompressé qui tranche',
        );

        $result = $this->guard()->requireXlsxFile($this->requestWithFile($path, 'dup.xlsx'));
        $this->assertRefused($result, 413, '20 Mo');
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

    /**
     * Un classeur PhpSpreadsheet réel auquel on AJOUTE une partie XML à un chemin
     * arbitraire (hors xl/worksheets/) — pour prouver que la garde compte les
     * lignes où qu'elles vivent, comme le fait PhpSpreadsheet via les rels.
     */
    private function xlsxWithExtraXmlPart(string $entryName, string $xml): string
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray([['A']], null, 'A1');
        $path = $this->tempFile('part');
        new Xlsx($spreadsheet)->save($path);

        $zip = new ZipArchive;
        $zip->open($path);
        $zip->addFromString($entryName, $xml);
        $zip->close();

        return $path;
    }

    /**
     * Un zip fabriqué OCTET PAR OCTET portant DEUX entrées de même nom (ZipArchive
     * dédoublonne à l'écriture, impossible autrement). Chaque entrée est deflatée
     * (méthode 8) pour que 25 Mo de remplissage tiennent sous la borne octets ;
     * l'annuaire central pointe deux en-têtes locaux distincts, si bien que
     * numFiles vaut 2 et getStreamIndex($i) rend chacune telle qu'elle est.
     */
    private function zipWithDuplicateEntries(string $name, string $firstData, string $secondData): string
    {
        $entries = [$firstData, $secondData];
        $local = '';
        $central = '';
        $offsets = [];
        foreach ($entries as $data) {
            $offsets[] = \strlen($local);
            $compressed = (string) gzdeflate($data);
            $local .= pack('V', 0x04034B50)
                . pack('vvvvv', 20, 0, 8, 0, 0)
                . pack('VVV', crc32($data), \strlen($compressed), \strlen($data))
                . pack('vv', \strlen($name), 0)
                . $name
                . $compressed;
        }
        foreach ($entries as $idx => $data) {
            $compressed = (string) gzdeflate($data);
            $central .= pack('V', 0x02014B50)
                . pack('vvvvvv', 20, 20, 0, 8, 0, 0)
                . pack('VVV', crc32($data), \strlen($compressed), \strlen($data))
                . pack('vvvvv', \strlen($name), 0, 0, 0, 0)
                . pack('VV', 0, $offsets[$idx])
                . $name;
        }
        $eocd = pack('V', 0x06054B50)
            . pack('vvvv', 0, 0, \count($entries), \count($entries))
            . pack('VV', \strlen($central), \strlen($local))
            . pack('v', 0);

        $path = $this->tempFile('dup');
        file_put_contents($path, $local . $central . $eocd);

        return $path;
    }

    private function tempFile(string $prefix): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix) . '.xlsx';
        $this->tempFiles[] = $path;

        return $path;
    }
}
