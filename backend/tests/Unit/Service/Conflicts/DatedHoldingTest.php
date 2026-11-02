<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Conflicts;

use App\Service\Conflicts\DatedAssignment;
use App\Service\Conflicts\DatedHolding;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Promesse : ce jour-là, chaque séance ou match a UN tenant parmi ses candidats, ou personne,
 * et ce choix est le même à chaque calcul. Les cas vivent dans `datedHolding.parity.json`,
 * que le miroir du moteur lira aussi.
 */
#[Group('unit')]
final class DatedHoldingTest extends TestCase
{
    private const string CASES = __DIR__ . '/../../../Fixtures/datedHolding.parity.json';

    /** @return iterable<string, array{0: list<array<string, mixed>>, 1: array<string, string>, 2: list<string>}> */
    public static function parityCases(): iterable
    {
        /** @var array{cases: list<array{name: string, needs: list<array<string, mixed>>, holders: array<string, string>, unheld: list<string>}>} $data */
        $data = json_decode((string) file_get_contents(self::CASES), true, 512, \JSON_THROW_ON_ERROR);
        foreach ($data['cases'] as $case) {
            yield $case['name'] => [$case['needs'], $case['holders'], $case['unheld']];
        }
    }

    /**
     * @param list<array<string, mixed>> $needs
     * @param array<string, string>      $holders
     * @param list<string>               $unheld
     */
    #[DataProvider('parityCases')]
    public function testEachNeedGetsTheExpectedHolderOrNobody(array $needs, array $holders, array $unheld): void
    {
        $assignment = $this->assign($needs);

        self::assertEquals($holders, $assignment->holders);
        self::assertEqualsCanonicalizing($unheld, array_keys($assignment->unheld));
        self::assertLessThan(10000, $assignment->steps, 'un petit cas reste sous le budget');
    }

    /**
     * Promesse : l'ordre de saisie ne change jamais qui tient (sinon une ligne rouge changerait
     * d'une visite à l'autre).
     *
     * @param list<array<string, mixed>> $needs
     * @param array<string, string>      $holders
     * @param list<string>               $unheld
     */
    #[DataProvider('parityCases')]
    public function testInputOrderNeverChangesTheResult(array $needs, array $holders, array $unheld): void
    {
        $assignment = $this->assign(array_reverse($needs));

        self::assertEquals($holders, $assignment->holders);
        self::assertEqualsCanonicalizing($unheld, array_keys($assignment->unheld));
    }

    /**
     * Promesse : au-delà de 10 000 pas, le calcul s'arrête sur un glouton dans le même ordre, donc
     * une journée très chargée ne bloque jamais l'écran et reste déterministe.
     */
    public function testBudgetExhaustionFallsBackToADeterministicGreedy(): void
    {
        $coaches = ['a', 'b', 'c', 'd', 'e', 'f'];
        $needs = [];
        for ($i = 10; $i < 40; ++$i) {
            $needs[] = [
                'id' => 'm-' . $i,
                'kind' => 'match',
                'teamId' => 't-' . $i,
                'startMinutes' => 900,
                'endMinutes' => 1005,
                'candidates' => $coaches,
                'players' => [],
            ];
        }

        $first = $this->assign($needs);
        $second = $this->assign(array_reverse($needs));

        self::assertLessThanOrEqual(11000, $first->steps, 'le compteur de pas s\'arrête au budget');
        self::assertEquals(['m-10' => 'a', 'm-11' => 'b', 'm-12' => 'c', 'm-13' => 'd', 'm-14' => 'e', 'm-15' => 'f'], $first->holders);
        self::assertCount(24, $first->unheld);
        self::assertEquals($first->holders, $second->holders);
    }

    /** @param list<array<string, mixed>> $needs */
    private function assign(array $needs): DatedAssignment
    {
        return new DatedHolding()->assign($needs);
    }
}
