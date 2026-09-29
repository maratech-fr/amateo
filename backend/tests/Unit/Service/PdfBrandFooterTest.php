<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\BrandAssets;
use App\Service\PdfGenerator;
use App\Service\ProductIdentity;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * P5-24 — l'export PDF porte un pied de marque « Généré avec [icône] amateo », posé par
 * Puppeteer sur CHAQUE page. Ce test épingle le CONTENU du pied (construit côté backend) et
 * le fait qu'il voyage bien dans le payload du worker :
 *   - le texte « Généré avec » annonce la provenance ;
 *   - l'icône est INLINÉE en data URI (le worker ne joint aucune URL applicative) ;
 *   - le nom vient d'un `ProductIdentity` injecté — variable, JAMAIS un littéral de marque.
 *
 * Les builders purs sont exercés par réflexion, comme la matrice (`PdfTeamDayMatrixTest`) :
 * `generate()` charge des données et n'est pas invocable ici, mais `buildFooterTemplate()` et
 * `callWorker()` le sont.
 */
#[Group('phase1')]
final class PdfBrandFooterTest extends TestCase
{
    public function testFooterCarriesTheGeneratedWithLabelTheInlinedLogoAndTheProductName(): void
    {
        // Un nom de TEST, pas « Amateo » : la preuve que le mot vient du service, pas d'un littéral.
        $footer = $this->buildFooter(new ProductIdentity(productName: 'Zephyr'));

        self::assertStringContainsString('Généré avec', $footer, 'le pied annonce la provenance');
        // Icône INLINÉE en data URI — aucun fetch réseau depuis le worker Puppeteer.
        self::assertStringContainsString('src="data:image/svg+xml;base64,', $footer, 'le logo est inliné en data URI');
        // Le mot du logo est DÉRIVÉ du nom produit, en minuscules comme le logotype à l'écran.
        self::assertStringContainsString('>zephyr<', $footer, 'le mot du logo est dérivé du nom produit, en minuscules');
        self::assertStringContainsString('alt="Zephyr"', $footer);
        self::assertStringNotContainsString('Amateo', $footer, 'aucun littéral de marque codé en dur dans le pied');
    }

    public function testTheFooterTravelsInTheWorkerPayload(): void
    {
        $captured = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): ResponseInterface {
            $captured[] = json_decode((string) $options['body'], true);

            return new MockResponse(json_encode(['success' => true]));
        });

        $generator = new ReflectionClass(PdfGenerator::class)->newInstanceWithoutConstructor();
        new ReflectionClass(PdfGenerator::class)->getProperty('httpClient')->setValue($generator, $client);

        new ReflectionMethod(PdfGenerator::class, 'callWorker')
            ->invoke($generator, '<html></html>', 'schedule-x-all.pdf', '<div id="brand-footer">Généré avec</div>');

        self::assertSame('<div id="brand-footer">Généré avec</div>', $captured[0]['footerTemplate'] ?? null, 'le pied reçu part tel quel dans le payload du worker');
    }

    private function buildFooter(ProductIdentity $identity): string
    {
        $generator = new ReflectionClass(PdfGenerator::class)->newInstanceWithoutConstructor();
        $reflection = new ReflectionClass(PdfGenerator::class);
        // BrandAssets sans argument résout l'asset RÉEL depuis l'emplacement de la classe
        // (backend/assets/brand/icon.svg) — le data URI produit est donc le vrai mark.
        $reflection->getProperty('brandAssets')->setValue($generator, new BrandAssets);
        $reflection->getProperty('productIdentity')->setValue($generator, $identity);

        return (string) new ReflectionMethod(PdfGenerator::class, 'buildFooterTemplate')->invoke($generator);
    }
}
