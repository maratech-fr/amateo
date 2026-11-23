<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\PlanTeam;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\TeamMatchHabit;
use App\Service\ScheduleConstraintBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class ScheduleConstraintBuilderAgeFieldsTest extends TestCase
{
    private ScheduleConstraintBuilder $builder;

    private EntityManagerInterface&MockObject $entityManager;

    /** @var EntityRepository<SportCategory>&MockObject */
    private EntityRepository&MockObject $sportCategoryRepository;

    private LoggerInterface&MockObject $logger;

    /** L'âge minimal de la catégorie part au moteur pour chaque équipe ; l'âge maximal, jamais lu, ne part plus. */
    public function testBuildAddsAgeFieldsToEveryTeam(): void
    {
        $u13Category = (new SportCategory)
            ->setId('sport-category-u13m')
            ->setAgeMin(12)
            ->setAgeMax(13);

        $loisirCategory = (new SportCategory)
            ->setId('sport-category-loisir')
            ->setAgeMin(null)
            ->setAgeMax(null);

        $this->sportCategoryRepository->method('find')->willReturnCallback(
            static fn (string $id): ?SportCategory => match ($id) {
                'sport-category-u13m' => $u13Category,
                'sport-category-loisir' => $loisirCategory,
                default => null,
            },
        );

        $teams = [
            (new Team)
                ->setId('team-u13')
                ->setClubId('club-1')
                ->setSeasonId('season-1')
                ->setSportCategoryId('sport-category-u13m')
                ->setPriorityTierId(1)
                ->setName('U13M 1'),
            (new Team)
                ->setId('team-loisir')
                ->setClubId('club-1')
                ->setSeasonId('season-1')
                ->setSportCategoryId('sport-category-loisir')
                ->setPriorityTierId(1)
                ->setName('Loisir 1'),
        ];

        // Les séances de chaque équipe : sa ligne dans le plan construit (lot 4, PR 3a).
        $payload = $this->builder->build([], $teams, [], teamLines: ['team-u13' => (new PlanTeam)->setTeamId('team-u13')->setSessionsPerWeek(2), 'team-loisir' => (new PlanTeam)->setTeamId('team-loisir')->setSessionsPerWeek(2)]);

        self::assertCount(2, $payload['teams']);

        $teamsById = [];
        foreach ($payload['teams'] as $teamPayload) {
            self::assertArrayHasKey('ageMin', $teamPayload);
            self::assertArrayNotHasKey('ageMax', $teamPayload);
            $teamsById[$teamPayload['id']] = $teamPayload;
        }

        self::assertSame(12, $teamsById['team-u13']['ageMin']);
        self::assertNull($teamsById['team-loisir']['ageMin']);
    }

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->sportCategoryRepository = $this->createMock(EntityRepository::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        // P4-271 — `serializeTeam` dérive le `matchDay` des seules habitudes (deriveMatchDay).
        // Le mock doit servir ce repo (findBy vide) sinon `getRepository` rend null et le build
        // lève un TypeError. findBy vide → repli sur le champ déclaré (ici null).
        $emptyRepository = $this->createMock(EntityRepository::class);
        $emptyRepository->method('findBy')->willReturn([]);

        $this->entityManager->method('getRepository')->willReturnMap([
            [SportCategory::class, $this->sportCategoryRepository],
            [TeamMatchHabit::class, $emptyRepository],
        ]);

        $this->builder = new ScheduleConstraintBuilder($this->logger, $this->entityManager);
    }
}
