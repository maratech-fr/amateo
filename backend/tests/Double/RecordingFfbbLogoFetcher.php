<?php

declare(strict_types=1);

namespace App\Tests\Double;

use App\Service\Basketball\FfbbLogoFetcherInterface;

/**
 * Fetcher de logo sans réseau, pour les tests de la route de logo adverse : le vrai fetcher
 * télécharge depuis l'hôte d'assets FFBB (timeout 8 s), inobservable en test. Ce double
 * COMPTE les tentatives et rend ce qu'on lui a dit (octets fixés, ou null = échec), ce qui
 * permet de prouver le cache négatif de BCK-37 (pas de 2ᵉ téléchargement).
 */
final class RecordingFfbbLogoFetcher implements FfbbLogoFetcherInterface
{
    public int $calls = 0;

    public ?string $returns = null;

    public function download(string $uuid): ?string
    {
        ++$this->calls;

        return $this->returns;
    }

    public function reset(): void
    {
        $this->calls = 0;
        $this->returns = null;
    }
}
