<?php

declare(strict_types=1);

namespace App\Tests\CrossStack;

use App\Message\GenerateScheduleMessage;
use App\MessageHandler\GenerateScheduleHandler;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * D-05 — le verrou de génération doit TOUJOURS survivre au solve.
 *
 * S'il expire pendant que le worker attend `EngineClient::solve()`, un second message
 * acquiert le verrou et **deux solves tournent sur le même club**. Deux `ScheduleResultImporter`
 * écrivent, le dernier gagne. Et comme `ClubGenerationLock::release()` compare son token avant
 * de supprimer, le premier worker fait un no-op **correct** en revenant : **aucune exception,
 * aucun log, aucun test rouge**. Le gestionnaire découvre un planning qui n'est pas celui
 * qu'il a demandé, sans qu'aucune trace n'existe.
 *
 * Le TTL vivait sur un littéral `+ 60` que rien ne reliait au budget réel du moteur, lequel
 * est décidé... côté Python. `ConcurrentGenerationTest` couvre la CONTENTION (deux messages
 * qui se disputent le verrou), pas l'EXPIRATION — le scénario où le verrou disparaît tout seul.
 *
 * Ce test lit le budget dans `engine/app/main.py` : la marge n'est plus une intention, c'est
 * un invariant vérifié contre la zone qui le consomme.
 */
#[Group('contract')]
final class GenerationLockOutlivesTheSolveTest extends TestCase
{
    private const string ENGINE_MAIN = __DIR__ . '/../../../engine/app/main.py';

    public function testTheLockTtlExceedsTheWorstCaseEngineBudget(): void
    {
        $requested = new GenerateScheduleMessage('schedule-1', 'club-1')->getTimeoutSeconds();

        /** @var int $margin */
        $margin = new ReflectionClass(GenerateScheduleHandler::class)->getConstant('LOCK_TTL_MARGIN_SECONDS');
        $ttl = $requested + $margin;

        // Le moteur borne sa phase 1 au plus petit du palier et du plafond de payload, puis
        // ajoute la phase de chaînage et le choix du coach stocké. Le pire cas est donc atteint
        // au palier le plus haut.
        $worstCase = min($this->highestEngineTier(), $requested) + $this->chainingCap() + $this->engineBudget('HOLD_SETTLE_MAX_SECONDS');

        self::assertGreaterThan($worstCase, $ttl, \sprintf(
            "Le verrou (TTL %d s) ne survit plus au pire solve (%d s).\n"
            . "Il expirerait PENDANT l'attente du moteur : un second message prendrait le verrou,\n"
            . "deux solves tourneraient sur le même club et le dernier import écraserait l'autre —\n"
            . 'sans exception, sans log, sans test rouge (le release par token no-op correctement).',
            $ttl,
            $worstCase,
        ));
    }

    /**
     * Le chemin le plus long d'une génération : solve nominal INFEASIBLE au palier le plus haut (sans
     * chaînage, faute de solution), solve diagnostique, sonde de causalité, best-effort, chaînage de ce
     * dernier. L'attente HTTP du worker et le verrou doivent survivre à toutes ces phases. Sinon le worker
     * abandonne un planning que le moteur est en train de produire, et le second message prend le verrou.
     *
     * `_settle_holds` termine chaque `_solve` qui trouve une solution : la sonde (faisable, sinon pas de
     * best-effort) et le best-effort le paient. Pas le nominal ni le diagnostic : ils sont INFEASIBLE sur
     * ce chemin. Un diagnostic faisable arrête là, sans best-effort, donc bien plus court.
     *
     * La somme est un majorant : `_best_effort_solve` compte déjà la sonde dans ses 60 s.
     */
    public function testHttpTimeoutAndLockOutliveTheWholeBestEffortPath(): void
    {
        $requested = new GenerateScheduleMessage('schedule-1', 'club-1')->getTimeoutSeconds();
        /** @var int $margin */
        $margin = new ReflectionClass(GenerateScheduleHandler::class)->getConstant('LOCK_TTL_MARGIN_SECONDS');

        $phases = [
            'nominal' => min($this->highestEngineTier(), $requested),
            'diagnostic' => $this->engineBudget('DIAGNOSTIC_SOLVE_MAX_SECONDS'),
            'sonde' => $this->engineBudget('BEST_EFFORT_PROBE_MAX_SECONDS'),
            'coach stocké de la sonde' => $this->engineBudget('HOLD_SETTLE_MAX_SECONDS'),
            'best-effort' => $this->engineBudget('BEST_EFFORT_SOLVE_MAX_SECONDS'),
            'chaînage du best-effort' => $this->chainingCap(),
            'coach stocké du best-effort' => $this->engineBudget('HOLD_SETTLE_MAX_SECONDS'),
        ];
        $worstCase = array_sum($phases);

        self::assertLessThan($requested, $worstCase, \sprintf(
            'Le worker cesse d\'attendre le moteur à %d s, le pire chemin en demande %d (%s).',
            $requested,
            $worstCase,
            json_encode($phases, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE),
        ));
        self::assertGreaterThan($worstCase, $requested + $margin, 'Le verrou expirerait avant la fin du pire chemin.');
    }

    /** Le palier de budget le plus élevé de `_adaptive_timeout`. */
    private function highestEngineTier(): int
    {
        preg_match_all('/^\s+adaptive = (\d+)$/m', $this->engineSource(), $tiers);
        self::assertNotEmpty($tiers[1], 'Les paliers de budget ont disparu de `_adaptive_timeout` — ce test doit suivre, pas se taire.');

        return max(array_map(intval(...), $tiers[1]));
    }

    private function chainingCap(): int
    {
        $found = preg_match('/^CHAINING_PHASE_MAX_SECONDS = (\d+)$/m', $this->engineSource(), $matches);
        self::assertSame(1, $found, 'CHAINING_PHASE_MAX_SECONDS a disparu du moteur — la phase 2 n\'est plus bornée par cette constante.');

        return (int) $matches[1];
    }

    /** Un budget du moteur en secondes entières, arrondi au-dessus (`HOLD_SETTLE_MAX_SECONDS` est un float). */
    private function engineBudget(string $constant): int
    {
        $found = preg_match(\sprintf('/^%s = (\d+(?:\.\d+)?)$/m', $constant), $this->engineSource(), $matches);
        self::assertSame(1, $found, $constant . ' a disparu du moteur : cette phase n\'est plus bornée par cette constante.');

        return (int) ceil((float) $matches[1]);
    }

    private function engineSource(): string
    {
        $source = file_get_contents(self::ENGINE_MAIN);
        self::assertIsString($source, 'engine/app/main.py est illisible.');

        return $source;
    }
}
