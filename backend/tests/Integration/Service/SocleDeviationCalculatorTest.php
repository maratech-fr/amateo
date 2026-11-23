<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Service\PeriodPlanTranscriber;
use App\Service\SocleDeviationCalculator;
use App\Tests\PeriodSessionsDecorTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Promesse (lot 4, PR 3a, ligne 17 du plan) : le bilan d'un plan de vacances par rapport au socle ne nomme un nombre
 * de séances choisi (`chosenSessions`) que pour une équipe dont la ligne de période diffère du socle ; il est nul quand
 * la ligne égale le socle — toute équipe a désormais une ligne, ce n'est pas un choix.
 *
 * Jumeau, cité : la transcription de la même période, `PeriodPlanTranscriberTest` (quelles séances sont retirées).
 */
#[Group('integration')]
final class SocleDeviationCalculatorTest extends KernelTestCase
{
    use PeriodSessionsDecorTrait;

    public function testChosenSessionsIsNamedOnlyForALineThatDiffersFromTheSocle(): void
    {
        $decor = $this->buildPeriodSessionsDecor();
        $this->teamLine($decor['holidayPlan'], $decor['sm1'])->setSessionsPerWeek(1);
        self::getContainer()->get(EntityManagerInterface::class)->flush();
        $period = self::getContainer()->get(PeriodPlanTranscriber::class)->transcribe($decor['holidayPlan']);

        $result = self::getContainer()->get(SocleDeviationCalculator::class)->calculate(
            $decor['club']->getId(),
            $decor['season']->getId(),
            $decor['holidayPlan'],
            $decor['holiday'],
            $decor['socle']->getId(),
            $period->scheduleId,
        );

        $chosen = [];
        foreach ($result->teams as $team) {
            $chosen[$team['teamId']] = $team['chosenSessions'];
        }
        self::assertArrayHasKey($decor['u15'], $chosen, 'témoin : le bilan nomme U15');
        self::assertSame(['SM1' => 1, 'U15' => null], ['SM1' => $chosen[$decor['sm1']] ?? 'absente', 'U15' => $chosen[$decor['u15']]], 'SM1 a choisi 1 séance ; U15, réglée comme le socle, n\'a rien choisi');
    }

    protected function setUp(): void
    {
        self::bootKernel();
    }
}
