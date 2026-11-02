<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\CoachWish;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Promesse : une personne partie de l'équipe disparaît de la doléance partout où on la relit ; le
 * filtre vit en une seule maison, sans jamais effacer la donnée brute.
 */
#[Group('unit')]
final class CoachWishStaffUnavailabilityTest extends TestCase
{
    public function testAmongKeepsOnlyTheCurrentStaffAndLeavesTheRawListIntact(): void
    {
        $lines = [['coachId' => 'karim', 'days' => [4]], ['coachId' => 'emma', 'days' => [2]]];
        $wish = (new CoachWish)->setStaffUnavailability($lines);

        self::assertSame([['coachId' => 'karim', 'days' => [4]]], $wish->staffUnavailabilityAmong(['karim' => true, 'benoit' => true]));
        self::assertSame($lines, $wish->getStaffUnavailability());
    }
}
