<?php

declare(strict_types=1);

namespace App\Tests\Integration\Doctrine;

use App\Entity\Reservation;
use App\Tests\Architecture\NoNullPlanBranchTest;
use App\Tests\Doctrine\NullPlanTripwireMiddleware;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Promesse : en test, une requête qui cherche « plan nul » échoue haut et fort au lieu de rendre une liste vide
 * (lot 4, PR 2 ; {@see NullPlanTripwireMiddleware}). Les requêtes fautives sont écrites exprès, sous les trois
 * formes qu'un appelant produit : SQL brut, critère `findBy` à nul (une variable mise à nul puis passée), DQL.
 * Témoin : la même recherche sur un vrai plan passe.
 *
 * Angle jumeau, cité : {@see NoNullPlanBranchTest} (les formes écrites dans le code) ;
 * l'exemption des migrations est prouvée par `Lot4MigrationChainTest`, dont la conversion cherche les lignes à
 * plan nul sous ce même fil-piège.
 */
#[Group('phase1')]
#[Group('integration')]
final class NullPlanTripwireTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    #[TestWith(['SELECT id FROM venue_training_slot WHERE schedule_plan_id IS NULL'])]
    #[TestWith(['SELECT id FROM reservation r WHERE r.club_id = r.club_id AND r.schedule_plan_id is not null'])]
    #[TestWith(['DELETE FROM shared_training_block WHERE schedule_plan_id IS NULL AND club_id = \'00000000-0000-4000-8000-000000000000\''])]
    public function testARawQueryLookingForANullPlanFails(string $sql): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('plan nul');

        $this->em->getConnection()->executeQuery($sql);
    }

    public function testACriterionSetToNullFails(): void
    {
        $planId = null;

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('plan nul');

        $this->em->getRepository(Reservation::class)->findBy(['schedulePlanId' => $planId]);
    }

    public function testADqlQueryLookingForANullPlanFails(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('plan nul');

        $this->em->createQuery('SELECT r.id FROM App\Entity\Reservation r WHERE r.schedulePlanId IS NULL')->getResult();
    }

    public function testTheSameLookupOnARealPlanPasses(): void
    {
        $planId = '00000000-0000-4000-8000-000000000000';

        self::assertSame([], $this->em->getRepository(Reservation::class)->findBy(['schedulePlanId' => $planId]));
        self::assertSame([], $this->em->getConnection()->fetchFirstColumn('SELECT id FROM venue_training_slot WHERE schedule_plan_id = ?', [$planId]));
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }
}
