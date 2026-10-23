<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Basketball;

use App\Service\Basketball\LogoPaletteExtractor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * P2-21 / retour Rillieux #14 — l'extracteur GD lit les 2 couleurs dominantes des
 * octets d'un logo DÉJÀ téléchargé. Pure, sans réseau, sans dépendance : on lui
 * passe des octets, il rend `[dominante, ?seconde]` en `#rrggbb` minuscule, ou
 * `null` quand rien n'est extractible (pas une image, hors bornes). Les images de
 * test sont fabriquées à la volée avec GD — aucun binaire hostile en dépôt.
 */
#[Group('phase1')]
final class LogoPaletteExtractorTest extends TestCase
{
    /**
     * Promesse 1 — la couleur dominante du logo est rendue EN PREMIER, la seconde
     * ensuite. Cas réel : le logo noir/blanc de Rillieux a donné #1d1d1d / #eeeeee
     * (ce que le fondateur avait posé à la main). Ici un logo majoritairement
     * sombre avec un coin clair : la dominante est sombre, la seconde claire.
     */
    public function testDominantLogoColourIsReturnedFirstAndTheSecondColourSecond(): void
    {
        $bytes = self::png(40, 40, static function ($img, int $w, int $h): void {
            $dark = (int) imagecolorallocate($img, 16, 16, 16);
            $light = (int) imagecolorallocate($img, 240, 240, 240);
            imagefilledrectangle($img, 0, 0, $w - 1, $h - 1, $dark);
            // 10 % de la surface en clair (coin haut).
            imagefilledrectangle($img, 0, 0, $w - 1, (int) ($h / 10) - 1, $light);
        });

        $pair = (new LogoPaletteExtractor)->extract($bytes);

        self::assertNotNull($pair, 'un logo bicolore est extractible');
        self::assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $pair[0], 'dominante en #rrggbb minuscule');
        self::assertNotNull($pair[1], 'une seconde couleur existe');
        self::assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $pair[1]);
        self::assertHexNear('#101010', $pair[0], 'la dominante est la couleur majoritaire (sombre)');
        self::assertHexNear('#f0f0f0', $pair[1], 'la seconde est la couleur minoritaire (claire)');
    }

    /**
     * Promesse — un logo d'UNE seule couleur opaque a une dominante mais PAS de
     * seconde couleur (l'accent sombre restera donc à la dérivation du front).
     */
    public function testAFlatSingleColourLogoHasADominantButNoSecondColour(): void
    {
        $bytes = self::png(24, 24, static function ($img, int $w, int $h): void {
            imagefilledrectangle($img, 0, 0, $w - 1, $h - 1, (int) imagecolorallocate($img, 201, 16, 46));
        });

        $pair = (new LogoPaletteExtractor)->extract($bytes);

        self::assertNotNull($pair);
        self::assertHexNear('#c9102e', $pair[0]);
        self::assertNull($pair[1], 'une seule couleur opaque → aucune seconde');
    }

    /**
     * Promesse 4 (socle) — un logo sans pixel opaque (1×1 transparent) n'est pas
     * extractible → `null`. C'est ce `null` qui, côté populate, fait retomber sur
     * le repli `gradient_color` FFBB (cf. FfbbClubPopulatorTest).
     */
    public function testAFullyTransparentLogoIsNotExtractable(): void
    {
        // 1×1 transparent PNG — valide pour finfo, mais aucun pixel opaque à lire.
        $bytes = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=', true);

        self::assertNull((new LogoPaletteExtractor)->extract($bytes));
    }

    /**
     * Abus — une image VALIDE dont une dimension dépasse le plafond est refusée
     * AVANT tout décodage (anti-bombe). Témoin falsifiable : un ruban d'un pixel
     * de haut mais trop large se décoderait sans peine si la borne était retirée ;
     * la borne, elle, le refuse → `null`.
     */
    public function testAnImageOverTheDimensionCapIsRejectedBeforeDecoding(): void
    {
        $bytes = self::png(LogoPaletteExtractor::MAX_DIMENSION + 1, 1, static function ($img, int $w): void {
            imagefilledrectangle($img, 0, 0, $w - 1, 0, (int) imagecolorallocate($img, 10, 20, 30));
        });

        self::assertNull((new LogoPaletteExtractor)->extract($bytes));
    }

    /**
     * Abus — des octets plus gros que le plafond sont refusés AVANT tout décodage.
     * Témoin falsifiable : un PNG valide suivi d'un bourrage ; sans la borne de
     * taille, GD décoderait le PNG (il ignore la traîne) et rendrait sa couleur.
     */
    public function testBytesOverTheSizeCapAreRejectedBeforeDecoding(): void
    {
        $valid = self::png(2, 2, static function ($img): void {
            imagefilledrectangle($img, 0, 0, 1, 1, (int) imagecolorallocate($img, 1, 2, 3));
        });
        $padded = $valid . str_repeat("\x00", LogoPaletteExtractor::MAX_BYTES + 1 - \strlen($valid));
        self::assertGreaterThan(LogoPaletteExtractor::MAX_BYTES, \strlen($padded));

        self::assertNull((new LogoPaletteExtractor)->extract($padded));
    }

    /**
     * Abus « logo hostile » — octets corrompus, format inconnu, bombe de dimensions
     * DÉCLARÉES (en-tête PNG forgé annonçant 100000×100000, fichier minuscule). Dans
     * tous les cas : best-effort SILENCIEUX, `null`, aucune exception remontée, et
     * `getimagesizefromstring` consulté AVANT tout `imagecreatefromstring` (la bombe
     * déclarée n'alloue jamais son canevas).
     *
     * @param string $bytes
     */
    #[DataProvider('hostileBytes')]
    public function testHostileBytesAreBestEffortAndSilent(string $bytes): void
    {
        self::assertNull((new LogoPaletteExtractor)->extract($bytes));
    }

    /** @return iterable<string, array{string}> */
    public static function hostileBytes(): iterable
    {
        yield 'octets corrompus' => ["\x89PNG\r\n\x1a\n corrompu et tronqué \x00\xff\xfe"];
        yield 'texte, pas une image' => [str_repeat('pas une image du tout ', 64)];
        yield 'octets aléatoires non reconnus' => [random_bytes(512)];
        yield 'bombe de dimensions déclarées' => [self::forgedHugePng(100000, 100000)];
    }

    private static function assertHexNear(string $expected, string $actual, string $message = ''): void
    {
        $e = self::rgb($expected);
        $a = self::rgb($actual);
        $delta = max(abs($e[0] - $a[0]), abs($e[1] - $a[1]), abs($e[2] - $a[2]));
        self::assertLessThanOrEqual(48, $delta, \sprintf('%s (%s attendu, %s obtenu)', $message, $expected, $actual));
    }

    /** @return array{int, int, int} */
    private static function rgb(string $hex): array
    {
        $n = (int) hexdec(ltrim($hex, '#'));

        return [($n >> 16) & 0xFF, ($n >> 8) & 0xFF, $n & 0xFF];
    }

    /**
     * Construit un PNG en mémoire avec GD (aucun binaire en dépôt).
     *
     * @param callable(\GdImage, int, int): void $painter
     */
    private static function png(int $w, int $h, callable $painter): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        $painter($img, $w, $h);
        ob_start();
        imagepng($img);
        $bytes = (string) ob_get_clean();
        imagedestroy($img);

        return $bytes;
    }

    /** PNG minuscule dont l'IHDR DÉCLARE des dimensions énormes (fichier de quelques octets). */
    private static function forgedHugePng(int $w, int $h): string
    {
        $sig = "\x89PNG\r\n\x1a\n";
        $ihdrData = pack('N2', $w, $h) . pack('C5', 8, 2, 0, 0, 0); // 8-bit, truecolor
        $ihdr = pack('N', \strlen($ihdrData)) . 'IHDR' . $ihdrData . pack('N', crc32('IHDR' . $ihdrData));
        $iend = pack('N', 0) . 'IEND' . pack('N', crc32('IEND'));

        return $sig . $ihdr . $iend;
    }
}
