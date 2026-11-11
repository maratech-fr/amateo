<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Service\MatchPlacementLock;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Promesse : le verrou de placement d'un club se LIT sans se prendre — présent ou non, et pour
 * combien de temps encore (le 409 en tire son « au plus N minutes », la commande de rattrapage
 * son « budget dépassé ») — et seul son DÉTENTEUR peut le réarmer au budget du run qui démarre.
 *
 * Sur le Redis réel ; clés uniques par test, rendues par compare-and-delete (jamais FLUSHALL).
 * Les consommateurs : `PlacementConflictTextTest` (texte), `RedeliveredPlacementTest` (réarmement),
 * `StuckPlacementRunReconcilerTest` (lecture en mode commande).
 */
#[Group('integration')]
final class MatchPlacementLockTest extends KernelTestCase
{
    public function testRemainingSecondsAndIsHeldReadRedis(): void
    {
        $lock = $this->lock();
        $clubId = 'lock-read-' . bin2hex(random_bytes(6));

        self::assertFalse($lock->isHeld($clubId), 'aucune clé : verrou libre');
        self::assertNull($lock->remainingSeconds($clubId), 'aucune clé : aucun délai à annoncer');

        $token = $lock->acquire($clubId, 150);
        self::assertNotNull($token);

        try {
            self::assertTrue($lock->isHeld($clubId));
            self::assertSame(150, $lock->remainingSeconds($clubId), 'le délai restant arrondit à la seconde SUPÉRIEURE (le « au plus »)');
        } finally {
            $lock->release($clubId, $token);
        }

        self::assertFalse($lock->isHeld($clubId), 'rendu : verrou libre');
        self::assertNull($lock->remainingSeconds($clubId));
    }

    public function testOnlyTheHolderCanRearmTheLock(): void
    {
        $lock = $this->lock();
        $clubId = 'lock-extend-' . bin2hex(random_bytes(6));

        self::assertFalse($lock->extend($clubId, 'aucun-jeton', 600), 'une clé absente ne se réarme pas : elle se reprend');

        $token = $lock->acquire($clubId, 5);
        self::assertNotNull($token);

        try {
            self::assertFalse($lock->extend($clubId, 'jeton-d-un-autre', 600), 'un autre jeton ne réarme jamais le verrou');
            self::assertLessThanOrEqual(5, $lock->remainingSeconds($clubId), 'le refus laisse le délai intact');

            self::assertTrue($lock->extend($clubId, $token, 600), 'le détenteur réarme son verrou');
            self::assertGreaterThanOrEqual(598, $lock->remainingSeconds($clubId), 'réarmé au budget complet du run');
        } finally {
            $lock->release($clubId, $token);
        }
    }

    private function lock(): MatchPlacementLock
    {
        self::bootKernel();
        $lock = self::getContainer()->get(MatchPlacementLock::class);
        \assert($lock instanceof MatchPlacementLock);

        return $lock;
    }
}
