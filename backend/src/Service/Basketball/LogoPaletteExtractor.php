<?php

declare(strict_types=1);

namespace App\Service\Basketball;

use GdImage;
use Throwable;

/**
 * Lit les deux couleurs dominantes des octets d'un logo DÉJÀ téléchargé, via GD
 * (P2-21 / retour Rillieux #14). Service PUR : aucune dépendance, aucun réseau,
 * aucune I/O — on lui passe des octets, il rend `[dominante, ?seconde]` en
 * `#rrggbb` minuscule, ou `null` quand rien n'est extractible.
 *
 * Best-effort SILENCIEUX : aucune exception n'en sort (octets corrompus, format
 * inconnu, bombe de dimensions). Anti-bombe à deux étages : une taille d'octets
 * ou une dimension (DÉCLARÉE par l'en-tête, lue sans décoder) hors bornes est
 * refusée AVANT tout `imagecreatefromstring`, donc avant toute allocation de
 * canevas. Les pixels entièrement transparents sont ignorés ; sans pixel opaque,
 * le résultat est `null` (côté populate, c'est ce `null` qui garde le repli
 * `gradient_color` FFBB).
 */
final class LogoPaletteExtractor
{
    /** Plafond d'octets accepté — au-delà, refus AVANT tout décodage (anti-bombe). */
    public const int MAX_BYTES = 2 * 1024 * 1024;

    /** Plafond de dimension par axe — au-delà (déclaré OU réel), refus AVANT décodage. */
    public const int MAX_DIMENSION = 4096;

    /** Grain de quantification par canal : fond les quasi-identiques dans un même seau. */
    private const int QUANT = 32;

    /**
     * @return array{0: string, 1: ?string}|null [dominante #rrggbb, ?seconde] ou null
     */
    public function extract(string $bytes): ?array
    {
        if ('' === $bytes || \strlen($bytes) > self::MAX_BYTES) {
            return null;
        }

        try {
            // 1) dimensions LUES sans décoder : une bombe déclarée n'alloue jamais son canevas.
            $size = @getimagesizefromstring($bytes);
            if (false === $size) {
                return null;
            }
            [$width, $height] = $size;
            if ($width < 1 || $height < 1 || $width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION) {
                return null;
            }

            // 2) seulement ensuite, le décodage réel.
            $image = @imagecreatefromstring($bytes);
            if (!$image instanceof GdImage) {
                return null;
            }

            // Sur une image À PALETTE, `imagecolorat` rend l'INDICE de palette, pas le
            // RGBA : on convertit en truecolor pour lire les vraies couleurs (un PNG
            // palettisé sinon ras des #000000).
            if (!imageistruecolor($image)) {
                imagepalettetotruecolor($image);
            }

            try {
                return $this->palette($image, $width, $height);
            } finally {
                imagedestroy($image);
            }
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Compte les pixels opaques par seau de couleur quantifié, puis rend la
     * moyenne du seau le plus fréquent (dominante) et celle du suivant (seconde).
     *
     * @return array{0: string, 1: ?string}|null
     */
    private function palette(GdImage $image, int $width, int $height): ?array
    {
        /** @var array<int, array{count: int, r: int, g: int, b: int}> $buckets */
        $buckets = [];
        for ($y = 0; $y < $height; ++$y) {
            for ($x = 0; $x < $width; ++$x) {
                $rgba = imagecolorat($image, $x, $y);
                if (127 === (($rgba >> 24) & 0x7F)) {
                    continue; // pixel entièrement transparent → ignoré
                }
                $r = ($rgba >> 16) & 0xFF;
                $g = ($rgba >> 8) & 0xFF;
                $b = $rgba & 0xFF;
                $key = (intdiv($r, self::QUANT) << 16) | (intdiv($g, self::QUANT) << 8) | intdiv($b, self::QUANT);
                if (!isset($buckets[$key])) {
                    $buckets[$key] = ['count' => 0, 'r' => 0, 'g' => 0, 'b' => 0];
                }
                ++$buckets[$key]['count'];
                $buckets[$key]['r'] += $r;
                $buckets[$key]['g'] += $g;
                $buckets[$key]['b'] += $b;
            }
        }

        if ([] === $buckets) {
            return null; // aucun pixel opaque → inextractible
        }

        uasort($buckets, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);
        $ordered = array_values($buckets);

        return [
            $this->average($ordered[0]),
            isset($ordered[1]) ? $this->average($ordered[1]) : null,
        ];
    }

    /** @param array{count: int, r: int, g: int, b: int} $bucket */
    private function average(array $bucket): string
    {
        return \sprintf(
            '#%02x%02x%02x',
            intdiv($bucket['r'], $bucket['count']),
            intdiv($bucket['g'], $bucket['count']),
            intdiv($bucket['b'], $bucket['count']),
        );
    }
}
