<?php

declare(strict_types=1);

namespace App\Tests;

use GdImage;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Fabrique d'images de test pour le canal de signalement (Chantier F PR3, capture
 * d'écran). Des octets RÉELS (GD) pour les formats acceptés, et des leurres pour les
 * chemins d'abus : un SVG (XSS), une bombe de décompression (dimensions énormes
 * déclarées dans un en-tête minuscule), un corps non-image. Jamais de fichier
 * hard-codé fragile — tout est généré, donc valide par construction.
 */
trait BuildsTestImages
{
    /** @var list<string> fichiers temporaires à nettoyer */
    private array $tempImagePaths = [];

    private function validPngBytes(int $width = 4, int $height = 4): string
    {
        return $this->encode(imagecreatetruecolor($width, $height), 'png');
    }

    private function validWebpBytes(int $width = 4, int $height = 4): string
    {
        return $this->encode(imagecreatetruecolor($width, $height), 'webp');
    }

    private function validJpegBytes(int $width = 4, int $height = 4): string
    {
        return $this->encode(imagecreatetruecolor($width, $height), 'jpeg');
    }

    /**
     * Un en-tête PNG VALIDE (signature + IHDR) qui DÉCLARE des dimensions énormes alors
     * que le fichier reste minuscule — la forme d'une bombe de décompression.
     * `getimagesizefromstring` lit ces dimensions dans l'IHDR SANS décoder un seul pixel ;
     * un serveur qui déciderait d'allouer l'image avant de vérifier saturerait la mémoire.
     */
    private function pngDeclaringDimensions(int $width, int $height): string
    {
        $ihdr = pack('N', $width) . pack('N', $height) . "\x08\x06\x00\x00\x00";
        $chunk = 'IHDR' . $ihdr;

        return "\x89PNG\r\n\x1a\n" . pack('N', 13) . $chunk . pack('N', crc32($chunk));
    }

    private function svgBytes(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><script>alert(1)</script><rect width="10" height="10"/></svg>';
    }

    private function uploadedImage(string $bytes, string $filename, string $clientMimeType): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'fbshot');
        file_put_contents($path, $bytes);
        $this->tempImagePaths[] = $path;

        // Dernier argument TRUE = mode test : court-circuite is_uploaded_file() pour que le
        // fichier soit lisible par le contrôleur hors contexte d'upload HTTP réel.
        return new UploadedFile($path, $filename, $clientMimeType, null, true);
    }

    private function cleanupTempImages(): void
    {
        foreach ($this->tempImagePaths as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $this->tempImagePaths = [];
    }

    private function encode(GdImage|false $image, string $format): string
    {
        self::assertNotFalse($image, 'GD doit pouvoir créer l\'image de test');
        ob_start();
        match ($format) {
            'png' => imagepng($image),
            'webp' => imagewebp($image),
            'jpeg' => imagejpeg($image),
            default => null,
        };
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }
}
