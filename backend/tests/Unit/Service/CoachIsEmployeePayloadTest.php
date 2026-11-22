<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Coach;
use App\Entity\Person;
use App\Service\ScheduleConstraintBuilder;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class CoachIsEmployeePayloadTest extends TestCase
{
    public function testBuildIncludesIsEmployeeForEachCoach(): void
    {
        $builder = new ScheduleConstraintBuilder($this->createMock(LoggerInterface::class));

        $employeeCoach = new Coach((new Person)->setClubId('club-1')->setFirstName('Eva')->setLastName('Employee'))
            ->setId('coach-employee')
            ->setClubId('club-1')
            ->setSeasonId('season-1')
            ->setIsActive(true)
            ->setIsEmployee(true);

        $volunteerCoach = new Coach((new Person)->setClubId('club-1')->setFirstName('Vera')->setLastName('Volunteer'))
            ->setId('coach-volunteer')
            ->setClubId('club-1')
            ->setSeasonId('season-1')
            ->setIsActive(true)
            ->setIsEmployee(false);

        $payload = $builder->build([], [], [$employeeCoach, $volunteerCoach]);

        self::assertCount(2, $payload['coaches']);

        $coachesById = [];
        foreach ($payload['coaches'] as $coachPayload) {
            self::assertArrayHasKey('isEmployee', $coachPayload);
            self::assertIsBool($coachPayload['isEmployee']);
            $coachesById[$coachPayload['id']] = $coachPayload;
        }

        self::assertTrue($coachesById['coach-employee']['isEmployee']);
        self::assertFalse($coachesById['coach-volunteer']['isEmployee']);
    }
}
