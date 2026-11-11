<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Enum\MatchPlacementRunStatus;
use App\Service\PlacementConflictText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Promesse : le 409 d'une seconde demande de placement dit au gestionnaire ce qui se passe
 * VRAIMENT, selon le dernier run du club — jamais un délai inventé.
 *
 *  - dernier run en file (PENDING) : il n'a pas démarré, le TTL posé à l'enfilage n'est pas une
 *    fin (le worker le réarme au départ) → aucun délai promis, aucun « réessayez » (l'écran passe
 *    en « en cours » juste après, un reclic coûterait une unité de quota pour rien) ;
 *  - sinon (en cours, ou terminal avec un calcul encore tenu au moteur) : le délai restant du
 *    verrou, arrondi à la minute SUPÉRIEURE (« au plus »), ou le repli historique sans délai.
 *
 * Jumeau de bout en bout (HTTP, verrou Redis réel) :
 * `PlaceMatchesControllerTest::testASecondRequestBehindAQueuedRunDoesNotPromiseADelay` et
 * `::testSecondRequestSaysHowLongTheRunningPlacementMayStillTake`.
 */
final class PlacementConflictTextTest extends TestCase
{
    private const string QUEUED = 'Un placement est déjà demandé pour ce club — il démarrera dès que possible.';

    private const string FALLBACK = 'Un placement est déjà en cours — réessayez dans un instant.';

    private const string ONE_MINUTE = 'Un placement est déjà en cours — il se terminera dans 1 minute au plus.';

    /** @return iterable<string, array{0: MatchPlacementRunStatus|null, 1: int|null, 2: string}> */
    public static function cases(): iterable
    {
        yield 'en file, verrou posé à l\'enfilage : aucun délai promis' => [MatchPlacementRunStatus::PENDING, 150, self::QUEUED];
        yield 'en file, verrou expiré : même texte' => [MatchPlacementRunStatus::PENDING, null, self::QUEUED];
        yield 'en cours, verrou illisible : repli historique' => [MatchPlacementRunStatus::RUNNING, null, self::FALLBACK];
        yield 'en cours, 1 s restante' => [MatchPlacementRunStatus::RUNNING, 1, self::ONE_MINUTE];
        yield 'en cours, 45 s restantes' => [MatchPlacementRunStatus::RUNNING, 45, self::ONE_MINUTE];
        yield 'en cours, 60 s restantes : encore une minute' => [MatchPlacementRunStatus::RUNNING, 60, self::ONE_MINUTE];
        yield 'en cours, 61 s : arrondi au supérieur, 2 minutes' => [MatchPlacementRunStatus::RUNNING, 61, 'Un placement est déjà en cours — il se terminera dans 2 minutes au plus.'];
        yield 'échoué mais calcul orphelin encore tenu (150 s)' => [MatchPlacementRunStatus::FAILED, 150, 'Un placement est déjà en cours — il se terminera dans 3 minutes au plus.'];
        yield 'terminé mais clé encore tenue' => [MatchPlacementRunStatus::COMPLETED, 150, 'Un placement est déjà en cours — il se terminera dans 3 minutes au plus.'];
        yield 'aucun run de cette saison (autre saison tient la clé)' => [null, 2710, 'Un placement est déjà en cours — il se terminera dans 46 minutes au plus.'];
    }

    #[DataProvider('cases')]
    public function testTheConflictTextFollowsTheLatestRunAndTheLockDelay(?MatchPlacementRunStatus $latestStatus, ?int $remainingSeconds, string $expected): void
    {
        self::assertSame($expected, PlacementConflictText::for($latestStatus, $remainingSeconds));
    }
}
