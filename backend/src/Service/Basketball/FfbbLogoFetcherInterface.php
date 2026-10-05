<?php

declare(strict_types=1);

namespace App\Service\Basketball;

use App\Controller\Basketball\OpponentLogoController;
use App\Tests\Double\RecordingFfbbLogoFetcher;

/**
 * Télécharge (et valide) le logo fédéral d'un organisme depuis l'hôte d'assets FFBB.
 * Interface pour que {@see OpponentLogoController} soit
 * testable sans appel réseau réel (double {@see RecordingFfbbLogoFetcher}).
 */
interface FfbbLogoFetcherInterface
{
    /** Octets validés prêts à stocker, ou null si indisponible/rejeté (best-effort). */
    public function download(string $uuid): ?string;
}
