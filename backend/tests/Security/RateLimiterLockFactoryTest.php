<?php

declare(strict_types=1);

namespace App\Tests\Security;

use PHPUnit\Framework\Attributes\Group;
use ReflectionProperty;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * P4-316 anti-hameçonnage — un plafond d'envoi n'est une borne qu'autant que sa lecture-puis-écriture
 * est ATOMIQUE. `SlidingWindowLimiter::reserve()` lit l'état, décide, puis écrit : sans verrou, N
 * requêtes parallèles lisant le même état passent TOUTES (10 clics simultanés = 10×200 au lieu de
 * 200), ce qui vide le garde-fou `coach_wish_send` par concurrence.
 *
 * Le verrou n'existe que si la `RateLimiterFactory` reçoit une `LockFactory`. Avec
 * `framework.rate_limiter.*.lock_factory: auto` (le défaut), Symfony câble `null` TANT QUE le
 * composant Lock n'est pas configuré (`symfony/lock` absent → `framework.lock` vide →
 * `FrameworkExtension` résout `auto` à null). On épingle donc, au plus bas, que le limiteur réel
 * est construit AVEC une lock factory — témoin de l'ensemble, car `auto` résout la MÊME fabrique
 * pour tous les limiteurs du dépôt dès que Lock est configuré.
 *
 * Falsification : sans `symfony/lock` + `framework.lock`, la propriété est null → ROUGE.
 */
#[Group('phase1')]
final class RateLimiterLockFactoryTest extends KernelTestCase
{
    public function testCoachWishSendLimiterIsBuiltWithALockFactorySoItsCapIsAtomic(): void
    {
        self::bootKernel();
        $factory = self::getContainer()->get('limiter.coach_wish_send');
        self::assertInstanceOf(RateLimiterFactory::class, $factory);

        $lockFactory = new ReflectionProperty(RateLimiterFactory::class, 'lockFactory')->getValue($factory);

        self::assertNotNull(
            $lockFactory,
            'Le limiteur coach_wish_send est construit SANS lock factory : son plafond est contournable '
            . 'par concurrence (lecture-puis-écriture non atomique). Installer symfony/lock et configurer '
            . 'framework.lock pour que lock_factory: auto câble une fabrique de verrous.',
        );
    }
}
