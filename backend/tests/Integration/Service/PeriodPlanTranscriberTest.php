<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\ScheduleSlotTemplate;
use App\Service\PeriodPlanTranscriber;
use App\Tests\PeriodSessionsDecorTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Promesse (lot 4, PR 3a, ligne 17 du plan) : la transcription d'un plan de vacances depuis le socle ne retire une
 * séance qu'à une équipe dont la ligne de période a moins de séances que la ligne du socle — les dernières de la
 * semaine, « à replacer ». Une équipe dont la ligne égale le socle ne perd rien, même quand la version du socle lui
 * donne plus de séances que son nombre de saison (une séance ajoutée à la main) : toute équipe a désormais une ligne
 * de période, ce n'est pas une raison de réduire.
 *
 * Jumeau, cité : le bilan de la même période, `SocleDeviationCalculatorTest` (`chosenSessions`).
 */
#[Group('integration')]
final class PeriodPlanTranscriberTest extends KernelTestCase
{
    use PeriodSessionsDecorTrait;

    public function testOnlyATeamWhosePeriodLineHasFewerSessionsThanTheSocleLosesItsLastSession(): void
    {
        $decor = $this->buildPeriodSessionsDecor();
        $this->teamLine($decor['holidayPlan'], $decor['sm1'])->setSessionsPerWeek(1);
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        $result = self::getContainer()->get(PeriodPlanTranscriber::class)->transcribe($decor['holidayPlan']);

        self::assertSame(
            [[$decor['sm1'], 3, PeriodPlanTranscriber::SKIP_TEAM_REDUCED]],
            array_map(static fn (array $r): array => [$r['teamId'], $r['dayOfWeek'], $r['reason']], $result->toReplace),
            'SM1 (1 séance pour les vacances, 2 au socle) perd sa dernière séance de la semaine ; rien d\'autre n\'est à replacer',
        );
        self::assertSame(['SM1' => 1, 'U15' => 3], $this->sessionsOf($result->scheduleId, $decor), 'U15, réglée comme le socle, garde ses trois séances');
    }

    public function testATeamWhosePeriodLineEqualsTheSocleLosesNothing(): void
    {
        $decor = $this->buildPeriodSessionsDecor();

        $result = self::getContainer()->get(PeriodPlanTranscriber::class)->transcribe($decor['holidayPlan']);

        self::assertSame([], $result->toReplace, 'aucune séance à replacer : chaque ligne de période égale le socle');
        self::assertSame(['SM1' => 2, 'U15' => 3], $this->sessionsOf($result->scheduleId, $decor), 'la version transcrite reprend toutes les séances du socle, la séance ajoutée à la main de U15 comprise');
    }

    protected function setUp(): void
    {
        self::bootKernel();
    }

    /**
     * @param array{sm1: string, u15: string} $decor
     *
     * @return array<string, int> séances de la version transcrite, par équipe
     */
    private function sessionsOf(string $scheduleId, array $decor): array
    {
        $repository = self::getContainer()->get(EntityManagerInterface::class)->getRepository(ScheduleSlotTemplate::class);

        return [
            'SM1' => \count($repository->findBy(['scheduleId' => $scheduleId, 'teamId' => $decor['sm1']])),
            'U15' => \count($repository->findBy(['scheduleId' => $scheduleId, 'teamId' => $decor['u15']])),
        ];
    }
}
