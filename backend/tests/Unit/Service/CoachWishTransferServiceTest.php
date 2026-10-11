<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\CoachWish;
use App\Service\CoachWishTransferService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * P2-63 PR 5 — garde applicative « autre club » du transfert, FALSIFIÉE directement.
 *
 * En intégration, RLS rend une doléance d'un autre club introuvable (`find` → null) : la garde
 * n'y est JAMAIS atteinte, donc inerte (M1 de la revue de sécurité). Elle est extraite en
 * prédicat PUR {@see CoachWishTransferService::isOwnedBy} pour être testée à part : si on relâche
 * la comparaison (la garde « retirée »), l'assertion `assertFalse` ci-dessous rougit — ce qu'un
 * dépôt simulé renvoyant une doléance d'un autre club prouverait côté service, mais que les
 * collaborateurs `final` (SchedulePlanProvisioner, PlanTeamSelectionSeeder) empêchent de monter
 * en mock ; le prédicat porte donc la règle, le wiring de la boucle est couvert par l'intégration.
 */
#[Group('phase1')]
final class CoachWishTransferServiceTest extends TestCase
{
    public function testIsOwnedByIsTrueOnlyForTheSameClub(): void
    {
        $mine = (new CoachWish)->setClubId('11111111-1111-4111-8111-111111111111');
        $foreign = (new CoachWish)->setClubId('22222222-2222-4222-8222-222222222222');

        self::assertTrue(CoachWishTransferService::isOwnedBy($mine, '11111111-1111-4111-8111-111111111111'));
        self::assertFalse(
            CoachWishTransferService::isOwnedBy($foreign, '11111111-1111-4111-8111-111111111111'),
            'une doléance d\'un AUTRE club n\'appartient jamais à ce club — jamais convertie',
        );
    }
}
