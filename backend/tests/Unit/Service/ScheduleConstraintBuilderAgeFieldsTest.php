<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\MatchSlotRotation;
use App\Entity\MatchSlotRotationTeam;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\TeamMatchHabit;
use App\Entity\TeamTag;
use App\Entity\TeamTagAssignment;
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

        $payload = $this->builder->build([], $teams, []);

        self::assertCount(2, $payload['teams']);

        $teamsById = [];
        foreach ($payload['teams'] as $teamPayload) {
            self::assertArrayHasKey('ageMin', $teamPayload);
            self::assertArrayHasKey('ageMax', $teamPayload);
            $teamsById[$teamPayload['id']] = $teamPayload;
        }

        self::assertSame(12, $teamsById['team-u13']['ageMin']);
        self::assertSame(13, $teamsById['team-u13']['ageMax']);
        self::assertNull($teamsById['team-loisir']['ageMin']);
        self::assertNull($teamsById['team-loisir']['ageMax']);
    }

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->sportCategoryRepository = $this->createMock(EntityRepository::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        // P2-9ter — la lecture des tags est désormais gardée par la seule présence de
        // l'EntityManager (avant, elle exigeait aussi un TeamTagService, que ce test ne
        // passait pas : la branche était donc sautée). Le mock doit servir les deux repos
        // de tags, sinon `getRepository` rend null et le build lève un TypeError.
        $tagRepository = $this->createMock(EntityRepository::class);
        $tagRepository->method('findBy')->willReturn([]);

        // RMM-5 PR-3 — `serializeTeam` dérive désormais le `matchDay` des habitudes ∪ rotations
        // (deriveMatchDay). Le mock doit servir ces repos (findBy vide) sinon `getRepository` rend
        // null et le build lève un TypeError. findBy vide → repli sur le champ déclaré (ici null).
        $emptyRepository = $this->createMock(EntityRepository::class);
        $emptyRepository->method('findBy')->willReturn([]);

        $this->entityManager->method('getRepository')->willReturnMap([
            [SportCategory::class, $this->sportCategoryRepository],
            [TeamTagAssignment::class, $tagRepository],
            [TeamTag::class, $tagRepository],
            [TeamMatchHabit::class, $emptyRepository],
            [MatchSlotRotationTeam::class, $emptyRepository],
            [MatchSlotRotation::class, $emptyRepository],
        ]);

        $this->builder = new ScheduleConstraintBuilder($this->logger, $this->entityManager);
    }
}
