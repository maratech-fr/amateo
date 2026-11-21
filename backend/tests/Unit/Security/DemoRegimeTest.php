<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Access\DemoRegime;
use App\Entity\Club;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

/**
 * Rien ne part vers un tiers en démonstration, et le refus est FERMÉ PAR DÉFAUT : quand le contrôle
 * central ne résout aucun club (`_club_id` absent), un compte de démonstration est refusé quand
 * même ; un compte ordinaire laisse décider le refus du rôle ou le contrôleur.
 */
final class DemoRegimeTest extends TestCase
{
    public function testAThirdPartySendIsForbiddenByADemoClubOrADemoAccount(): void
    {
        $regime = new DemoRegime;

        self::assertTrue($regime->forbidsThirdPartySend(new Club()->setIsDemo(true), new User), 'club démo, compte ordinaire');
        self::assertTrue($regime->forbidsThirdPartySend(new Club, new User()->setIsDemo(true)), 'club réel, compte démo');
        self::assertFalse($regime->forbidsThirdPartySend(new Club, new User), 'club et compte réels');
    }

    public function testWithoutAResolvedClubADemoAccountIsStillRefused(): void
    {
        $regime = new DemoRegime;

        self::assertTrue($regime->forbidsThirdPartySend(null, new User()->setIsDemo(true)), 'sans club, un compte démo est refusé (fermé par défaut)');
        self::assertFalse($regime->forbidsThirdPartySend(null, new User), 'sans club, un compte ordinaire n\'est pas refusé ici');
        self::assertFalse($regime->forbidsThirdPartySend(null, null), 'ni club ni compte : rien à décider ici');
    }
}
