<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * SEC-22 — l'échelle des bornes d'upload est épinglée dans l'image PHP
 * (`docker/php/Dockerfile`, étage `base` hérité par `prod`), contre une dérive
 * des valeurs par défaut upstream : appli 2 Mo (XlsxUploadGuard) < PHP upload 4M
 * < POST 8M < nginx 20m. Ce test garde le PALIER PHP ; le garde applicatif, lui,
 * refuse toujours EN PREMIER (voir XlsxUploadGuardTest).
 *
 * ⚠ Les `.ini` sont CUITS dans l'image : après une édition du Dockerfile, il faut
 * REBUILD l'image php-fpm (et recréer le conteneur) pour que ce test passe.
 */
#[Group('unit')]
final class PhpUploadIniTest extends TestCase
{
    public function testUploadMaxFilesizeIsPinnedToFourMegabytes(): void
    {
        self::assertSame('4M', \ini_get('upload_max_filesize'));
    }

    public function testPostMaxSizeIsPinnedToEightMegabytes(): void
    {
        self::assertSame('8M', \ini_get('post_max_size'));
    }
}
