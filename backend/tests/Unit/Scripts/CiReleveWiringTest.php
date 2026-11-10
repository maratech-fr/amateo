<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scripts;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Promesse : le relevé des jobs CI rouges (`make ci-releve`) se lance depuis toute copie du
 * dépôt sans Docker, son self-test rougit `lint` à chaque PR, et le script ne peut ni écrire dans
 * le dépôt, ni agir sur GitHub, ni recopier un secret.
 *
 * Grep statique du câblage (Makefile, ci.yml, texte du script). Le comportement (classement,
 * assainissement, aucune valeur CLI dans un appel `gh`) : `scripts/ci-releve-test.py` (T12, T14).
 * Annoté `phase1` mais NON listé dans « Blocking suite » : il tourne dans `backend-tests`.
 */
#[Group('phase1')]
final class CiReleveWiringTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../../..';
    private const string SCRIPT = 'scripts/ci-releve.py';
    private const string TARGET = 'ci-releve';

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function forbiddenSnippets(): iterable
    {
        yield 'GITHUB_ENV' => ['open(os.environ["GITHUB_ENV"], "a").write(line)'];
        yield 'GITHUB_OUTPUT' => ['print(f"x={x}", file=open(os.environ["GITHUB_OUTPUT"], "a"))'];
        yield 'shell=True' => ['subprocess.run(command, shell = True)'];
        yield 'gh api -X' => ['subprocess.run(["gh", "api", "-X", "POST", path])'];
        yield 'gh api --method' => ['subprocess.run(["gh", "api", "--method", "PATCH", path])'];
        yield 'git' => ['subprocess.run(["git", "push"])'];
        yield 'gh pr' => ['subprocess.run(["gh", "pr", "comment", "1"])'];
        yield 'gh issue' => ['os.system("gh issue create")'];
        yield 'argv in a gh list' => ['subprocess.run(["gh", "api", sys.argv[1]])'];
        yield 'args. in a gh list' => ['subprocess.run(["gh", "api", f"repos/x/runs?branch={args.branch}"])'];
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function skipKeywords(): iterable
    {
        foreach (['totp', 'password', 'passwd', 'mot de passe', 'secret', 'token', 'authorization', 'bearer', 'credential', 'setup key', 'otpauth', '"body"', 'Fingerprint'] as $keyword) {
            yield $keyword => [$keyword];
        }
    }

    public function testMakeTargetRunsTheScriptOnTheHostWithFourteenDaysByDefault(): void
    {
        $makefile = $this->read('Makefile');
        $recipe = $this->recipe($makefile, self::TARGET);

        self::assertMatchesRegularExpression('/^ci-releve:.*##\s*\S/m', $makefile, 'La cible a sa ligne d\'aide.');
        self::assertStringContainsString('python3 -I scripts/ci-releve.py', $recipe);
        self::assertStringContainsString('--days $(or $(DAYS),14)', $recipe);
        self::assertStringContainsString('$(ARGS)', $recipe);
        self::assertStringNotContainsString('docker', $recipe, 'Le relevé tourne sur l\'hôte (gh du poste), jamais dans un conteneur.');
    }

    /** Sinon un worktree nu (ou une copie hors dépôt) refuse `make ci-releve` avant toute recette. */
    public function testTargetIsAdmittedByEveryStackGuardList(): void
    {
        self::assertSame([], $this->listsMissing($this->read('Makefile'), self::TARGET));
    }

    /** Falsification permanente du garde ci-dessus : il voit une liste qui oublie la cible. */
    public function testTheListGuardSeesAListThatForgetsTheTarget(): void
    {
        $makefile = $this->read('Makefile');

        self::assertSame([], $this->listsMissing($makefile, 'deploy'), 'Témoin : `deploy` est admis partout.');
        self::assertCount(3, $this->listsMissing($makefile, 'no-such-target'));

        $inOneList = preg_replace('/filter-out /', 'filter-out only-in-first ', $makefile, 1);
        self::assertIsString($inOneList);
        self::assertCount(2, $this->listsMissing($inOneList, 'only-in-first'));
    }

    public function testSelfTestIsTheLastStepOfLint(): void
    {
        $steps = $this->steps($this->jobs()['lint'] ?? []);
        $last = end($steps);

        self::assertIsArray($last);
        self::assertSame('ci-releve self-test', $last['name'] ?? null, 'Dernier step de `lint` (R4 ne contraint que le step d\'isolement).');
        $run = (string) ($last['run'] ?? '');
        self::assertStringContainsString('python3 --version', $run);
        self::assertStringContainsString('python3 -I scripts/ci-releve-test.py', $run);
    }

    /** Aucun job ni workflow nouveau : le relevé ne vit que dans `lint` (aucun required check ajouté). */
    public function testTheReleveLivesOnlyInLint(): void
    {
        foreach ($this->jobs() as $id => $job) {
            self::assertStringNotContainsString('releve', $id);
            if ('lint' !== $id) {
                self::assertStringNotContainsString(self::TARGET, (string) json_encode($job), \sprintf('Le job `%s` ne porte pas le relevé.', $id));
            }
        }

        $workflows = glob(self::ROOT . '/.github/workflows/*.y*ml') ?: [];
        self::assertNotEmpty($workflows);
        foreach ($workflows as $path) {
            if ('ci.yml' !== basename($path)) {
                self::assertStringNotContainsString(self::TARGET, (string) file_get_contents($path), basename($path));
            }
        }
    }

    public function testScriptNeverWritesToTheRepositoryNorActsOnGitHub(): void
    {
        self::assertSame([], $this->forbiddenIn($this->read(self::SCRIPT)));
    }

    /** Falsification permanente du garde ci-dessus : chaque appel interdit est vu. */
    #[DataProvider('forbiddenSnippets')]
    public function testTheScriptGuardSeesEachForbiddenCall(string $snippet): void
    {
        self::assertNotSame([], $this->forbiddenIn("import subprocess\n" . $snippet . "\n"));
    }

    /**
     * Doublon assumé, angle nommé : T12 de `scripts/ci-releve-test.py` prouve le comportement
     * (chaque ligne porteuse tombe) ; ici, le texte du motif ne peut pas perdre un mot-clé sans
     * que ce garde le voie, même si le test Python est désactivé.
     */
    #[DataProvider('skipKeywords')]
    public function testSkipPatternCarriesEverySensitiveKeyword(string $keyword): void
    {
        self::assertMatchesRegularExpression('/' . preg_quote($keyword, '/') . '/i', $this->read(self::SCRIPT));
    }

    /**
     * @return list<string>
     */
    private function forbiddenIn(string $script): array
    {
        $found = [];
        foreach ([
            'GITHUB_ENV' => '/GITHUB_ENV/',
            'GITHUB_OUTPUT' => '/GITHUB_OUTPUT/',
            'shell=True' => '/shell\s*=\s*True/',
            'gh -X/--method' => '/["\'\s]-X["\'\s]|--method\b/',
            'git' => '/\bgit\b/',
            'gh pr/issue' => '/\bgh["\',\s]+(pr|issue)\b/',
        ] as $label => $pattern) {
            if (1 === preg_match($pattern, $script)) {
                $found[] = $label;
            }
        }

        preg_match_all('/\[\s*["\']gh["\'][^\]]*\]/s', $script, $lists);
        foreach ($lists[0] as $list) {
            if (1 === preg_match('/argv|\bargs\./', $list)) {
                $found[] = 'CLI value in a gh list: ' . $list;
            }
        }

        return $found;
    }

    /**
     * @return list<int> index (0-based) des listes `$(filter-out …,$(STACK_GOALS))` qui n'admettent pas la cible
     */
    private function listsMissing(string $makefile, string $target): array
    {
        preg_match_all('/\$\(filter-out ([^,]+),\$\(STACK_GOALS\)\)/', $makefile, $lists);
        self::assertGreaterThanOrEqual(3, \count($lists[1]), 'Témoin : les trois listes du garde de stack (Makefile:14, :19, :24).');

        $missing = [];
        foreach ($lists[1] as $index => $list) {
            if (!\in_array($target, preg_split('/\s+/', trim($list)) ?: [], true)) {
                $missing[] = $index;
            }
        }

        return $missing;
    }

    /**
     * @return array<string, array<mixed>>
     */
    private function jobs(): array
    {
        $workflow = Yaml::parseFile(self::ROOT . '/.github/workflows/ci.yml', Yaml::PARSE_CUSTOM_TAGS);
        self::assertIsArray($workflow);
        self::assertIsArray($workflow['jobs'] ?? null);

        $jobs = [];
        foreach ($workflow['jobs'] as $id => $job) {
            self::assertIsArray($job);
            $jobs[(string) $id] = $job;
        }
        self::assertArrayHasKey('lint', $jobs);

        return $jobs;
    }

    /**
     * @param array<mixed> $job
     *
     * @return list<array<mixed>>
     */
    private function steps(array $job): array
    {
        self::assertIsArray($job['steps'] ?? null);
        $steps = [];
        foreach ($job['steps'] as $step) {
            self::assertIsArray($step);
            $steps[] = $step;
        }

        return $steps;
    }

    private function read(string $file): string
    {
        self::assertFileExists(self::ROOT . '/' . $file);
        $contents = file_get_contents(self::ROOT . '/' . $file);
        self::assertIsString($contents);

        return $contents;
    }

    /** Patron WorktreeTestRoutingTest::extractRecipe (lignes indentées du target). */
    private function recipe(string $makefile, string $target): string
    {
        $recipe = [];
        $inTarget = false;
        foreach (explode("\n", $makefile) as $line) {
            if (1 === preg_match('/^' . preg_quote($target, '/') . ':/', $line)) {
                $inTarget = true;

                continue;
            }
            if ($inTarget) {
                if (str_starts_with($line, "\t")) {
                    $recipe[] = $line;

                    continue;
                }
                if ('' === trim($line)) {
                    continue;
                }
                $inTarget = false;
            }
        }
        self::assertNotSame([], $recipe, \sprintf('La cible `%s` doit exister dans le Makefile racine.', $target));

        return implode("\n", $recipe);
    }
}
