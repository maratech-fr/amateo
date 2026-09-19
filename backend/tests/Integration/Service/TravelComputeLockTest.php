<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Service\TravelComputeLock;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * C5/C6 — le verrou par club du calcul de trajets (patron MatchPlacementLock). Sa clé
 * fait double emploi : verrou d'anti-double-calcul (C6) ET drapeau « calcul en cours »
 * que le contrôleur lit pour `travelStatus: pending` (C5). Ce test prouve le cycle
 * acquire → isHeld → release contre un vrai Redis.
 */
#[Group('integration')]
final class TravelComputeLockTest extends KernelTestCase
{
    public function testAcquireMarksItHeldAndReleaseClearsIt(): void
    {
        $lock = self::getContainer()->get(TravelComputeLock::class);
        $clubId = Uuid::v4()->toRfc4122();

        self::assertFalse($lock->isHeld($clubId), 'aucun calcul en cours au départ');

        $token = $lock->acquire($clubId, 30);
        self::assertIsString($token, 'le verrou libre s\'acquiert');
        self::assertTrue($lock->isHeld($clubId), 'un calcul est « en cours » tant que le verrou est tenu');

        // Un seul calcul par club : un second acquire échoue tant que le premier tient.
        self::assertNull($lock->acquire($clubId, 30), 'le verrou déjà tenu refuse un second acquéreur');

        $lock->release($clubId, $token);
        self::assertFalse($lock->isHeld($clubId), 'le relâchement efface la clé');
    }

    public function testReleaseWithAWrongTokenLeavesTheLockHeld(): void
    {
        $lock = self::getContainer()->get(TravelComputeLock::class);
        $clubId = Uuid::v4()->toRfc4122();

        $token = $lock->acquire($clubId, 30);
        self::assertIsString($token);
        // Compare-and-delete : seul le porteur du token peut relâcher.
        $lock->release($clubId, 'un-mauvais-token');
        self::assertTrue($lock->isHeld($clubId), 'un token étranger ne relâche pas le verrou');

        $lock->release($clubId, $token); // nettoyage
    }

    protected function setUp(): void
    {
        self::bootKernel();
    }
}
