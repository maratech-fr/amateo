<?php

declare(strict_types=1);

namespace App\Tests\Unit\Documentation;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * AUD-DOC-26 — la liste canonique des blocking-tests (`docs/testing/blocking-tests.md`, sortie
 * de `CLAUDE.md` §4 le 2026-08-27 pour alléger l'index) doit être EXACTEMENT celle des
 * steps du job `blocking-tests`. Dans les deux sens.
 *
 * Au 2026-08-08 elle mentait des deux côtés à la fois : `TeamTagScopeTest` y était annoncé
 * bloquant sans qu'aucun step ne le lance, tandis que `CoachDoubleBookingTest` et
 * `ScheduleConstraintBuilderOverlayTest` bloquaient sans y figurer. Cette liste est ce que lit
 * un agent : quand elle ment sur ce qui garde le dépôt, tout ce qui en découle est faux.
 *
 * ⚠ Ce que ce test défend, au-delà de la liste : **`#[Group('phase1')]` ne fait pas un gate.**
 * 143 fichiers portent l'annotation, ~24 sont des steps. Un NR d'axe structurant (§7.1) annoté
 * mais non ajouté au job tourne dans `unit-tests` — après le gate, sans bloquer `build-docker`.
 * Le piège a mordu deux fois (P2-9ter, puis DOC-26) ; ce garde est ce qui l'empêche de mordre
 * une troisième.
 *
 * Faux positif possible, et c'est assumé : si une NOTE de la liste venait à citer une classe
 * en `…Test` qui n'est pas une entrée, ce test rougirait. Le correctif est alors d'écrire la
 * note sans le nom de classe — pas d'affaiblir le garde.
 */
#[Group('phase1')]
final class BlockingTestsListMatchesCiTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../../..';

    private const string WORKFLOW = self::ROOT . '/.github/workflows/ci.yml';

    private const string INDEX = self::ROOT . '/docs/testing/blocking-tests.md';

    /** Le job dont les steps FONT le gate. */
    private const string JOB = 'blocking-tests';

    /** Bornes du paragraphe qui porte la liste (hors en-tête du fichier). */
    private const string LIST_OPENS = '**blocking-tests** (must pass first';

    private const string LIST_CLOSES = 'Detail:';

    public function testEveryGatingStepIsAnnouncedInTheIndex(): void
    {
        $missing = array_diff($this->testsRunByTheGate(), $this->testsListedInTheIndex());

        self::assertSame([], array_values($missing), \sprintf(
            "Ces tests BLOQUENT (steps du job « %s ») mais ne figurent pas dans la liste de docs/testing/blocking-tests.md :\n  - %s\n"
            . "Un gate non annoncé est un gate que personne ne sait maintenir : ajoutez-les à la liste,\n"
            . 'en disant CE QU\'ILS GARDENT (pas seulement leur nom).',
            self::JOB,
            implode("\n  - ", $missing),
        ));
    }

    public function testEveryTestAnnouncedInTheIndexActuallyGates(): void
    {
        $phantom = array_diff($this->testsListedInTheIndex(), $this->testsRunByTheGate());

        self::assertSame([], array_values($phantom), \sprintf(
            "docs/testing/blocking-tests.md annonce ces tests comme bloquants, mais AUCUN step du job « %s » ne les lance :\n  - %s\n"
            . "Ils tournent probablement dans `unit-tests` — donc après le gate et sans bloquer `build-docker`.\n"
            . "Deux issues, jamais le silence : (a) ajouter le step à ci.yml si le test doit gater,\n"
            . '(b) le retirer de la liste s\'il ne doit pas — et l\'écrire là où on le présentait comme bloquant.',
            self::JOB,
            implode("\n  - ", $phantom),
        ));
    }

    /**
     * Les classes réellement lancées par les steps du job, lues dans le YAML.
     *
     * @return list<string>
     */
    private function testsRunByTheGate(): array
    {
        $workflow = Yaml::parseFile(self::WORKFLOW);
        self::assertIsArray($workflow);
        self::assertArrayHasKey('jobs', $workflow);
        self::assertIsArray($workflow['jobs']);
        self::assertArrayHasKey(self::JOB, $workflow['jobs'], \sprintf('Le job « %s » a disparu de ci.yml.', self::JOB));

        $job = $workflow['jobs'][self::JOB];
        self::assertIsArray($job);
        self::assertIsArray($job['steps'] ?? null);

        $classes = [];
        foreach ($job['steps'] as $step) {
            if (!\is_array($step) || !\is_string($step['run'] ?? null)) {
                continue;
            }
            preg_match_all('#tests/\S*?/?(\w+Test)\.php#', $step['run'], $found);
            foreach ($found[1] as $class) {
                $classes[$class] = true;
            }
        }

        self::assertNotEmpty($classes, \sprintf('Aucun test lancé par « %s » — le parsing du workflow a-t-il cassé ?', self::JOB));

        return $this->sorted($classes);
    }

    /**
     * Les classes citées par la liste de docs/testing/blocking-tests.md.
     *
     * @return list<string>
     */
    private function testsListedInTheIndex(): array
    {
        $index = file_get_contents(self::INDEX);
        self::assertIsString($index);

        $start = strpos($index, self::LIST_OPENS);
        self::assertIsInt($start, \sprintf('Le paragraphe « %s » a disparu de docs/testing/blocking-tests.md.', self::LIST_OPENS));

        $end = strpos($index, self::LIST_CLOSES, $start);
        self::assertIsInt($end, 'La fin de la liste (« Detail: ») a disparu de docs/testing/blocking-tests.md.');

        preg_match_all('/\b(\w+Test)\b/', substr($index, $start, $end - $start), $found);

        $classes = [];
        foreach ($found[1] as $class) {
            $classes[$class] = true;
        }

        self::assertNotEmpty($classes, 'La liste de docs/testing/blocking-tests.md ne cite plus aucun test.');

        return $this->sorted($classes);
    }

    /**
     * @param array<string, true> $set
     *
     * @return list<string>
     */
    private function sorted(array $set): array
    {
        $classes = array_keys($set);
        sort($classes);

        return $classes;
    }
}
