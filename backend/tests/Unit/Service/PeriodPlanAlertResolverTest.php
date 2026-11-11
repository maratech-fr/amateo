<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Enum\SchedulePlanType;
use App\Service\PeriodPlanAlertResolver;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * D8 — l'alerte d'un planning de période et sa mise en sourdine se DÉRIVENT des faits du plan,
 * sans requête (le provider les calcule en lot) :
 *  - « validée automatiquement » : marque posée (auto ∧ jamais validé à la main), au moins une
 *    autre version gardée, période pas finie, pas encore vue ;
 *  - « commencée sans planning » : plan éligible (entrée active, sans enfant, période — pas le
 *    socle), jamais validé, aucune version terminée ni en vol, le jour dans la fenêtre, pas vue ;
 *  - `radarDismissed` : « J'ai vu » sur « commencée sans planning », plan toujours ouvert, le
 *    jour dans la fenêtre — la période sort du radar jusqu'à sa fin.
 *
 * Jumeau intégré : `Security/PeriodPlanAutoValidationTest` (faits lus en base sous plusieurs
 * fuseaux) et `Integration/Api/SchedulePlanAlertSeenTest` (champs servis par l'API).
 */
final class PeriodPlanAlertResolverTest extends TestCase
{
    private const string TODAY = '2026-12-28';

    /** Validé automatiquement avec une version gardée : la carte dit la version pointée et le nombre de gardées. */
    public function testAutoValidatedWithKeptVersionsAlerts(): void
    {
        $out = $this->resolve($this->autoValidated(keptVersionCount: 2, chosenVersionNumber: 3));

        self::assertTrue($out['autoValidated']);
        self::assertSame(['kind' => PeriodPlanAlertResolver::KIND_AUTO_VALIDATED, 'chosenVersionNumber' => 3, 'keptVersionCount' => 2], $out['alert']);
        self::assertFalse($out['radarDismissed']);
    }

    /** Validé automatiquement sans autre version : marque, mais aucune carte (rien à choisir). */
    public function testAutoValidatedWithASingleVersionIsSilent(): void
    {
        $out = $this->resolve($this->autoValidated(keptVersionCount: 0));

        self::assertTrue($out['autoValidated']);
        self::assertNull($out['alert']);
    }

    /** Une période finie n'alerte plus, la marque reste. */
    public function testAutoValidatedAfterTheEndIsSilent(): void
    {
        $out = $this->resolve($this->autoValidated(keptVersionCount: 2, endDate: '2026-12-27'));

        self::assertTrue($out['autoValidated']);
        self::assertNull($out['alert']);
    }

    /** « J'ai vu » sur la carte « validée automatiquement » l'éteint pour le club. */
    public function testAutoValidatedSeenIsSilent(): void
    {
        $out = $this->resolve($this->autoValidated(keptVersionCount: 2, alertSeenKind: PeriodPlanAlertResolver::KIND_AUTO_VALIDATED, alertSeenDate: self::TODAY));

        self::assertNull($out['alert']);
        self::assertFalse($out['radarDismissed'], 'un plan validé ne se « met pas en sourdine » au radar');
    }

    /** Vu sur l'AUTRE carte (« commencée sans planning ») puis auto-validé avec plusieurs versions : la nouvelle carte s'affiche. */
    public function testSeenOnTheOtherKindStillAlerts(): void
    {
        $out = $this->resolve($this->autoValidated(keptVersionCount: 1, alertSeenKind: PeriodPlanAlertResolver::KIND_STARTED_WITHOUT_PLAN, alertSeenDate: '2026-12-21'));

        self::assertSame(PeriodPlanAlertResolver::KIND_AUTO_VALIDATED, $out['alert']['kind'] ?? null);
    }

    /** Validé à la main après l'auto (première validation humaine posée) : ni marque ni carte. */
    public function testAManualValidationDropsTheMark(): void
    {
        $out = $this->resolve($this->autoValidated(keptVersionCount: 2, firstChosenAt: '2026-12-29 10:00:00+00'));

        self::assertFalse($out['autoValidated']);
        self::assertNull($out['alert']);
    }

    /** Rouvert après l'auto : ni marque ni carte (le plan a des versions, il n'est pas « sans planning »). */
    public function testAReopenedPlanHasNoMarkNorAlert(): void
    {
        $out = $this->resolve($this->autoValidated(keptVersionCount: 2, chosenVersionNumber: null, chosenScheduleId: null));

        self::assertFalse($out['autoValidated']);
        self::assertNull($out['alert']);
    }

    /** Commencée, aucune version terminée ni en vol : la carte « commencée sans planning ». */
    public function testStartedWithoutPlanAlerts(): void
    {
        $out = $this->resolve($this->startedWithoutPlan());

        self::assertFalse($out['autoValidated']);
        self::assertSame(['kind' => PeriodPlanAlertResolver::KIND_STARTED_WITHOUT_PLAN], $out['alert']);
        self::assertFalse($out['radarDismissed']);
    }

    /** Dès le jour du début (pas d'attente du seuil J+7), et jusqu'au dernier jour compris. */
    public function testStartedWithoutPlanCoversTheWholeWindowAndOnlyIt(): void
    {
        self::assertNotNull($this->resolve($this->startedWithoutPlan(), '2026-12-21')['alert'], 'jour J');
        self::assertNotNull($this->resolve($this->startedWithoutPlan(), '2027-01-03')['alert'], 'dernier jour');
        self::assertNull($this->resolve($this->startedWithoutPlan(), '2026-12-20')['alert'], 'veille du début');
        self::assertNull($this->resolve($this->startedWithoutPlan(), '2027-01-04')['alert'], 'lendemain de la fin');
    }

    /** Une mère découpée, une entrée ignorée, un plan de saison : jamais « commencée sans planning ». */
    public function testIneligiblePlansNeverAlertStartedWithoutPlan(): void
    {
        self::assertNull($this->resolve($this->startedWithoutPlan(hasChildren: true))['alert'], 'mère découpée');
        self::assertNull($this->resolve($this->startedWithoutPlan(entryActive: false))['alert'], 'entrée ignorée');
        self::assertNull($this->resolve($this->startedWithoutPlan(type: SchedulePlanType::SEASON))['alert'], 'plan de saison');
    }

    /** Une version terminée ou une génération en vol : pas « sans planning ». */
    public function testAFinishedOrInFlightVersionIsNotWithoutPlan(): void
    {
        self::assertNull($this->resolve($this->startedWithoutPlan(completedCount: 1))['alert'], 'version terminée');
        self::assertNull($this->resolve($this->startedWithoutPlan(inFlightCount: 1))['alert'], 'génération en vol');
    }

    /** Un plan déjà validé une fois puis rouvert, sans version terminée : pas éligible, pas de carte. */
    public function testAPlanValidatedOnceNeverAlertsStartedWithoutPlan(): void
    {
        self::assertNull($this->resolve($this->startedWithoutPlan(firstChosenAt: '2026-09-01 10:00:00+00'))['alert']);
    }

    /** « J'ai vu » sur « commencée sans planning » : plus de carte, la période sort du radar jusqu'à sa fin. */
    public function testStartedWithoutPlanSeenIsDismissedUntilTheEnd(): void
    {
        $seen = $this->startedWithoutPlan(alertSeenKind: PeriodPlanAlertResolver::KIND_STARTED_WITHOUT_PLAN, alertSeenDate: '2026-12-22');

        $during = $this->resolve($seen);
        self::assertNull($during['alert']);
        self::assertTrue($during['radarDismissed'], 'vue : la période sort du radar');

        self::assertFalse($this->resolve($seen, '2027-01-04')['radarDismissed'], 'fin passée : plus rien à mettre en sourdine');
    }

    /** Une vue antérieure au début (période re-datée depuis) ne compte pas : la carte revient. */
    public function testASeenBeforeTheStartDoesNotCount(): void
    {
        $out = $this->resolve($this->startedWithoutPlan(alertSeenKind: PeriodPlanAlertResolver::KIND_STARTED_WITHOUT_PLAN, alertSeenDate: '2026-12-01'));

        self::assertSame(PeriodPlanAlertResolver::KIND_STARTED_WITHOUT_PLAN, $out['alert']['kind'] ?? null);
        self::assertFalse($out['radarDismissed']);
    }

    /** Le résolveur rend une entrée par plan, sans en perdre ni en inventer. */
    public function testOneVerdictPerPlan(): void
    {
        $out = new PeriodPlanAlertResolver()->forPlans([
            'p1' => $this->startedWithoutPlan(),
            'p2' => $this->autoValidated(keptVersionCount: 1),
        ], new DateTimeImmutable(self::TODAY));

        self::assertSame(['p1', 'p2'], array_keys($out));
    }

    /**
     * @param array<string, mixed> $facts
     *
     * @return array{autoValidated: bool, alert: array<string, mixed>|null, radarDismissed: bool}
     */
    private function resolve(array $facts, string $today = self::TODAY): array
    {
        $out = new PeriodPlanAlertResolver()->forPlans(['plan' => $facts], new DateTimeImmutable($today));
        self::assertArrayHasKey('plan', $out);

        return $out['plan'];
    }

    /** @return array<string, mixed> Vacances du 21/12 au 03/01, V{n} pointée par l'auto le 28/12. */
    private function autoValidated(
        int $keptVersionCount,
        ?int $chosenVersionNumber = 2,
        ?string $chosenScheduleId = 'version-pointee',
        ?string $firstChosenAt = null,
        string $endDate = '2027-01-03',
        ?string $alertSeenKind = null,
        ?string $alertSeenDate = null,
    ): array {
        return [
            'type' => SchedulePlanType::HOLIDAY,
            'startDate' => '2026-12-21',
            'endDate' => $endDate,
            'chosenScheduleId' => $chosenScheduleId,
            'firstChosenAt' => $firstChosenAt,
            'autoValidatedDate' => '2026-12-28',
            'alertSeenDate' => $alertSeenDate,
            'alertSeenKind' => $alertSeenKind,
            'entryActive' => true,
            'hasChildren' => false,
            'completedCount' => $keptVersionCount + 1,
            'inFlightCount' => 0,
            'chosenVersionNumber' => $chosenVersionNumber,
            'keptVersionCount' => $keptVersionCount,
        ];
    }

    /** @return array<string, mixed> Vacances du 21/12 au 03/01, jamais validées, aucune version terminée. */
    private function startedWithoutPlan(
        SchedulePlanType $type = SchedulePlanType::HOLIDAY,
        bool $entryActive = true,
        bool $hasChildren = false,
        int $completedCount = 0,
        int $inFlightCount = 0,
        ?string $firstChosenAt = null,
        ?string $alertSeenKind = null,
        ?string $alertSeenDate = null,
    ): array {
        return [
            'type' => $type,
            'startDate' => '2026-12-21',
            'endDate' => '2027-01-03',
            'chosenScheduleId' => null,
            'firstChosenAt' => $firstChosenAt,
            'autoValidatedDate' => null,
            'alertSeenDate' => $alertSeenDate,
            'alertSeenKind' => $alertSeenKind,
            'entryActive' => $entryActive,
            'hasChildren' => $hasChildren,
            'completedCount' => $completedCount,
            'inFlightCount' => $inFlightCount,
            'chosenVersionNumber' => null,
            'keptVersionCount' => 0,
        ];
    }
}
