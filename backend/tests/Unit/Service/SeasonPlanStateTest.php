<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Enum\SeasonPlanState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * PROMESSE (rappel vacances, D7) : l'état du planning de saison qui choisit le texte du rappel se
 * lit du payload de `SchedulePlanProvisioner::seasonPlanPayload` — un pointeur posé l'emporte sur
 * tout (CHOSEN), sinon une version terminée dit « à valider » (FINISHED_NOT_CHOSEN), sinon « à
 * générer puis valider » (NOT_GENERATED : aucun plan SEASON, ou générations toutes en échec).
 * Le texte que chaque état produit est gardé par `Unit/Command/PeriodReminderEmailTest`.
 */
#[Group('phase1')]
final class SeasonPlanStateTest extends TestCase
{
    /**
     * @return iterable<string, array{0: array{id: string, name: string, chosenScheduleId: string|null, hasFinishedVersion: bool}|null, 1: SeasonPlanState}>
     */
    public static function payloads(): iterable
    {
        yield 'aucun plan SEASON' => [null, SeasonPlanState::NOT_GENERATED];
        yield 'version choisie et terminée' => [self::payload('s-1', true), SeasonPlanState::CHOSEN];
        yield 'le pointeur prime même sans version terminée' => [self::payload('s-1', false), SeasonPlanState::CHOSEN];
        yield 'terminée mais non choisie' => [self::payload(null, true), SeasonPlanState::FINISHED_NOT_CHOSEN];
        yield 'générations toutes en échec (aucune terminée)' => [self::payload(null, false), SeasonPlanState::NOT_GENERATED];
    }

    /**
     * @return array{id: string, name: string, chosenScheduleId: string|null, hasFinishedVersion: bool}
     */
    private static function payload(?string $chosen, bool $finished): array
    {
        return ['id' => 'plan-1', 'name' => 'Planning', 'chosenScheduleId' => $chosen, 'hasFinishedVersion' => $finished];
    }

    /**
     * @param array{id: string, name: string, chosenScheduleId: string|null, hasFinishedVersion: bool}|null $payload
     */
    #[DataProvider('payloads')]
    public function testStateIsReadFromTheSeasonPlanPayload(?array $payload, SeasonPlanState $expected): void
    {
        self::assertSame($expected, SeasonPlanState::fromPayload($payload));
    }
}
