<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Export\MatchesExportData;
use App\Service\BrandAssets;
use App\Service\MatchesPdfGenerator;
use App\Service\PdfGenerator;
use App\Service\ProductIdentity;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Promesse : la liste des matchs ne survit pas sur le disque et n'a aucune adresse à deviner —
 * le worker écrit un fichier au nom aléatoire, le backend en rend les octets et le supprime. Écrit
 * dans le vrai répertoire de rendus (`PdfGenerator::outputDir()`, jamais une constante recopiée).
 */
#[Group('integration')]
final class MatchesPdfGeneratorTest extends TestCase
{
    /** @var list<string> */
    private array $filenames = [];

    /** @var list<array<string, mixed>> */
    private array $payloads = [];

    public function testItReturnsTheBytesAndLeavesNoFile(): void
    {
        $bytes = $this->generatorWritingPdf()->generate($this->data());

        self::assertSame('%PDF-1.4 test', $bytes);
        self::assertCount(1, $this->filenames);
        self::assertFileDoesNotExist(PdfGenerator::outputDir() . '/' . $this->filenames[0], 'aucun fichier ne reste après la réponse');
    }

    public function testTheDocumentIsPortrait(): void
    {
        $this->generatorWritingPdf()->generate($this->data());

        self::assertFalse($this->payloads[0]['landscape'] ?? null);
    }

    /** Aucun nom réutilisable : deux exports identiques ne partagent jamais un fichier. */
    public function testEachRenderGetsItsOwnUnguessableName(): void
    {
        $generator = $this->generatorWritingPdf();
        $generator->generate($this->data());
        $generator->generate($this->data());

        self::assertMatchesRegularExpression('/^matchs-[0-9a-f-]{36,}\.pdf$/', $this->filenames[0]);
        self::assertNotSame($this->filenames[0], $this->filenames[1]);
    }

    public function testAWorkerFailureIsARuntimeException(): void
    {
        $generator = $this->generatorOn(new MockHttpClient(new MockResponse((string) json_encode(['success' => false, 'error' => 'Protocol error']))));

        $this->expectException(RuntimeException::class);
        $generator->generate($this->data());
    }

    protected function setUp(): void
    {
        if (!is_dir(PdfGenerator::outputDir())) {
            @mkdir(PdfGenerator::outputDir(), 0o775, true);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->filenames as $name) {
            @unlink(PdfGenerator::outputDir() . '/' . $name);
        }
    }

    private function generatorWritingPdf(): MatchesPdfGenerator
    {
        return $this->generatorOn(new MockHttpClient(function (string $method, string $url, array $options): ResponseInterface {
            $payload = json_decode((string) $options['body'], true);
            \assert(\is_array($payload));
            $this->payloads[] = $payload;
            $this->filenames[] = (string) $payload['filename'];
            file_put_contents(PdfGenerator::outputDir() . '/' . $payload['filename'], '%PDF-1.4 test');

            return new MockResponse((string) json_encode(['success' => true]));
        }));
    }

    private function generatorOn(MockHttpClient $client): MatchesPdfGenerator
    {
        $reflection = new ReflectionClass(PdfGenerator::class);
        $pdf = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('httpClient')->setValue($pdf, $client);
        $reflection->getProperty('brandAssets')->setValue($pdf, new BrandAssets);
        $reflection->getProperty('productIdentity')->setValue($pdf, new ProductIdentity(productName: 'Zephyr'));

        return new MatchesPdfGenerator($pdf);
    }

    private function data(): MatchesExportData
    {
        return new MatchesExportData(
            clubName: 'Les Lions',
            logoDataUri: null,
            from: new DateTimeImmutable('2026-11-09'),
            to: new DateTimeImmutable('2026-11-15'),
            editedOn: new DateTimeImmutable('2026-11-09'),
            teamScopeName: null,
            hasTravel: false,
            sections: [],
        );
    }
}
