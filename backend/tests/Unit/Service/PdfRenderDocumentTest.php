<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\BrandAssets;
use App\Service\PdfGenerator;
use App\Service\ProductIdentity;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Promesse : un autre document que le planning peut passer par le worker PDF, en portrait, avec le
 * même pied de marque ; une panne du worker sort toujours en RuntimeException (le contrôleur en
 * fait un 502). Angle voisin : `PdfBrandFooterTest` garde, lui, le contenu du pied et l'appel à
 * trois arguments de `callWorker` — il reste vert sans modification.
 */
final class PdfRenderDocumentTest extends TestCase
{
    public function testAPortraitDocumentReachesTheWorkerWithTheBrandFooter(): void
    {
        $captured = [];
        $generator = $this->generatorOn(new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): ResponseInterface {
            $captured = json_decode((string) $options['body'], true);

            return new MockResponse((string) json_encode(['success' => true]));
        }));

        $generator->renderDocument('<html>matchs</html>', 'matchs-x.pdf', false, true);

        self::assertSame('<html>matchs</html>', $captured['html'] ?? null);
        self::assertSame('matchs-x.pdf', $captured['filename'] ?? null);
        self::assertFalse($captured['landscape'] ?? null, 'portrait : landscape part à false');
        self::assertTrue($captured['multiSection'] ?? null);
        self::assertStringContainsString('Généré avec', (string) ($captured['footerTemplate'] ?? ''), 'le pied de marque est composé en interne');
    }

    public function testAWorkerRefusalIsARuntimeException(): void
    {
        $generator = $this->generatorOn(new MockHttpClient(new MockResponse((string) json_encode(['success' => false, 'error' => 'Protocol error']))));

        $this->expectException(RuntimeException::class);
        $generator->renderDocument('<html></html>', 'matchs-x.pdf', false, true);
    }

    public function testAnUnreachableWorkerIsARuntimeException(): void
    {
        $generator = $this->generatorOn(new MockHttpClient(new MockResponse('', ['error' => 'connexion refusée'])));

        $this->expectException(RuntimeException::class);
        $generator->renderDocument('<html></html>', 'matchs-x.pdf', false, true);
    }

    private function generatorOn(MockHttpClient $client): PdfGenerator
    {
        $reflection = new ReflectionClass(PdfGenerator::class);
        $generator = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('httpClient')->setValue($generator, $client);
        $reflection->getProperty('brandAssets')->setValue($generator, new BrandAssets);
        $reflection->getProperty('productIdentity')->setValue($generator, new ProductIdentity(productName: 'Zephyr'));

        return $generator;
    }
}
