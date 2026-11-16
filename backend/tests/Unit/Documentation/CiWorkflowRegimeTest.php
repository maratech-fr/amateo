<?php

declare(strict_types=1);

namespace App\Tests\Unit\Documentation;

use JsonException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Le régime de la CI sur le runner auto-hébergé : la CI tourne dans une VM partagée par
 * plusieurs runners NON éphémères, et chaque job doit y rester ÉTANCHE (projet compose, ports,
 * identifiants Docker, fichiers, tags), routable (variable de dépôt, retour arrière sans
 * commit), et sa garde de fork comme le gate des PR ne doivent jamais s'éroder en silence.
 *
 * Lecture STATIQUE des fichiers de `.github/` (aucun harnais d'exécution de workflow) : la
 * preuve réelle de l'étanchéité est faite dans la VM (sondes, deux runs complets) — ce test
 * garde que le YAML ne s'en écarte pas après coup. Une méthode par décision du plan (R1…R9),
 * chacune nomme sa promesse.
 *
 * Listes FERMÉES à dessein (jobs, classes de runner, 3 régimes PR_MAIN / LOT / MAIN_PUSH, steps
 * du gate de lot) : un job ou un step ajouté demain rougit ici tant que son auteur ne l'a pas
 * rangé — c'est le geste voulu. Trois régimes (plan CI 2026-11-15, § 9) : une PR vers
 * `lot/<sujet>` joue le seul job `lot-gate` (« Lot Gate ») ; une PR vers `main` (dont la PR
 * finale du lot) joue le gate complet, 16 jobs ; le push `main` joue le seul `main-verdict`
 * (« Main Verdict »), qui prouve que la PR mergée a joué ce gate complet. Les `if` réels sont
 * ÉVALUÉS par événement (`jobsThatRun`). Mesure et frein (PR 2 du même plan, M1 à M4) : la
 * couverture backend et front sort du run de PR vers `quality-weekly.yml`, chaque suite se mesure
 * contre son plafond de temps, et ce plafond ne remonte pas sans décision du fondateur.
 *
 * Ce que ce test NE prouve PAS (preuve ailleurs, ou aucune) :
 *  - la sémantique GitHub « un job sauté compte comme réussi pour un required check » — le test
 *    la suppose, il ne l'exécute pas ; la logique du verdict : `scripts/ci-verdict-test.py` ;
 *  - que GitHub Actions accepte les ancres YAML (`&blocking_suite_run`) : premier run de la PR ;
 *  - le comportement réel de `vitest --changed` et du cache de l'image PHP : PR d'essai ;
 *  - les rulesets `lot-gate` / `lot-no-delete` (hors dépôt), ni la concordance de
 *    LOT_REQUIRED_CHECKS avec le ruleset réellement posé ;
 *  - la concordance des 15 MAIN_REQUIRED_CHECKS avec la protection réelle de `main` : la
 *    constante est une COPIE, relue par `gh api …/branches/main/protection/required_status_checks` ;
 *  - qu'un check requis de `main` soit satisfait par un run LÉGER (même tête partagée, run
 *    annulé) : aucun test ne le voit avant le merge — la garde est la lecture obligatoire de
 *    `gh pr checks <n>` (deux lignes « skipping » admises, « Lot Gate » et « Main Verdict »)
 *    avant tout GO ou merge vers `main`, puis le verdict après le merge.
 *
 * NR d'axe (tous les axes §7.1 : ce chantier décide QUAND tournent leurs NR) — il gate : step
 * `Blocking suite` du job `blocking-tests` + ligne de `docs/testing/blocking-tests.md`
 * (assertion de R5 ci-dessous, et `BlockingTestsListMatchesCiTest` pour la ligne).
 */
#[Group('phase1')]
final class CiWorkflowRegimeTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../../..';

    private const string CI = '.github/workflows/ci.yml';

    private const string VISUAL = '.github/workflows/visual-baselines.yml';

    private const string PROBE = '.github/workflows/ci-runner-isolation.yml';

    private const string RUNNER_DIR = '.github/runner';

    private const string RUNS_ON = '${{vars.CI_RUNS_ON||\'ubuntu-24.04\'}}';

    private const string RUNS_ON_HEAVY = '${{vars.CI_RUNS_ON_HEAVY||\'ubuntu-24.04\'}}';

    /** Gate de lot : hébergé par défaut, basculé sur la VM par la réserve de minutes (75 %). */
    private const string RUNS_ON_LOT = '${{vars.CI_RUNS_ON_LOT||\'ubuntu-24.04\'}}';

    /** Verdict de `main` : hébergé par défaut, basculé sur la VM à 90 % du quota. */
    private const string RUNS_ON_VERDICT = '${{vars.CI_RUNS_ON_VERDICT||\'ubuntu-24.04\'}}';

    private const string CACHE_WARM = '.github/workflows/cache-warm.yml';

    private const string BUILD_PHP_CACHED = './.github/actions/build-php-cached';

    private const string FORK_GUARD = 'Refuse pull requests from forks';

    private const string POOL_STEP = 'Say which pool plays the full gate';

    private const string POOL_WARNING = '::warning title=Gate complet sur un runner hébergé::Les variables CI_RUNS_ON et CI_RUNS_ON_HEAVY sont absentes : ce run consomme des minutes hébergées.';

    private const string VERDICT_STEP = 'Prove the merged pull request played the full gate';

    private const string GUARDS_STEP = 'Static guards (Vitest, always)';

    private const string RELATED_STEP = 'Unit tests related to the diff (Vitest --changed)';

    private const string GUARDS_COMMAND = 'npx vitest run src/test/ tooling/ .guard.test';

    private const string RELATED_COMMAND = 'npx vitest run --changed "origin/${BASE_REF}" --passWithNoTests';

    private const string BLOCKING_SUITE_STEP = 'Blocking suite (phase1, one PHPUnit process)';

    private const string FILTERED_NR_STEP = 'ParcImportCorrectionTenantNR';

    /** Relevé hebdomadaire de la couverture backend et front, hors du run de chaque PR (plan § 5.1, contrats de la PR 2). */
    private const string QUALITY = '.github/workflows/quality-weekly.yml';

    /** Garde « un plafond de temps ne remonte que sur décision du fondateur » (plan § 8), dans `lint` et `lot-gate`. */
    private const string TIME_CEILING_STEP = 'Test time ceiling never rises';

    private const string NEVER_RISES_COMMAND = 'python3 -I scripts/suite-time.py --never-rises "origin/${BASE_REF}"';

    /**
     * Les cinq suites sous plafond de temps (plan § 8) : job → [préfixe du nom du step de la
     * suite, nom de la suite dans `test-time-ceiling.json`]. Préfixes, comme la table du relevé.
     */
    private const array TIMED_SUITES = [
        'backend-tests' => ['Run full suite', 'backend'],
        'functional-tests' => ['Functional tests (Behat', 'behat'],
        'e2e' => ['Run Playwright suite', 'e2e'],
        'frontend' => ['Unit tests (Vitest', 'frontend'],
        'engine-tests' => ['Run pytest', 'engine'],
    ];

    /**
     * Steps du job `lot-gate`, dans l'ordre (contrat PR 1, état A). `(checkout)` et `(setup-node)`
     * désignent les actions par leur `uses`, quel que soit le nom donné au step. Le front passe
     * AVANT « Start services » : sur 2 vCPU, Vitest ne partage pas la machine avec la stack.
     */
    private const array LOT_GATE_STEPS = [
        self::FORK_GUARD,
        '(checkout)',
        self::ISOLATE_STEP,
        'Login to Docker Hub (anti-429)',
        'Login to ghcr.io (mirror pull)',
        'Run gitleaks (full history)',
        self::TIME_CEILING_STEP,
        'Prepare .env',
        'Build engine',
        'Run pytest + coverage + cliquet',
        'Run ruff',
        'Check ruff formatting',
        'Run mypy',
        'Run bandit',
        'Run deptry',
        '(setup-node)',
        'Install deps',
        'Build (tsc -b + vite)',
        self::GUARDS_STEP,
        self::RELATED_STEP,
        'Pre-build php image (GHA cache)',
        'Start services',
        'Install PHP deps (cached + retried)',
        'Run PHPStan',
        'Generate JWT keys',
        'Init test database',
        self::BLOCKING_SUITE_STEP,
        self::FILTERED_NR_STEP,
        'Tear down the compose stack (always)',
    ];

    /**
     * Déclencheurs d'un passage COMPLET de `vitest --changed`, en plus de ceux par défaut de
     * Vitest (plan § 9.2) : chacun est lu par la config ou les tests sans passer par le graphe
     * d'imports, donc un diff qui ne touche qu'eux ne rejouerait aucun test.
     */
    private const array FULL_RERUN_TRIGGERS = [
        'package-lock.json', 'tsconfig', 'tooling/', 'act-warnings-ceiling.json', 'coverage-floor.json', 'src/test/setup.ts',
    ];

    private const string PROJECT = 'ci-${{ github.run_id }}-${{ github.run_attempt }}-${{ github.job }}';

    private const string COMPOSE_FILE = '${{ github.workspace }}/docker-compose.yml:${{ github.workspace }}/docker-compose.test.yml';

    private const string ISOLATE_STEP = 'Isolate the Docker credentials of this job';

    private const string STACK_DOWN = './.github/actions/ci-stack-down';

    /** Les 16 jobs du gate complet (dont `preflight`), le gate de lot et le verdict de `main`. */
    private const array JOBS = [
        'preflight', 'lint', 'frontend', 'secrets-scan', 'semgrep', 'dependency-audit', 'phpstan', 'rector',
        'blocking-tests', 'backend-tests', 'engine-tests', 'engine-perf', 'e2e',
        'engine-semantics', 'functional-tests', 'build-docker', 'lot-gate', 'main-verdict',
    ];

    /** Classe `amateo-heavy` (≈ 3-5 Go chacun) : au plus deux en même temps dans la VM. */
    private const array HEAVY_JOBS = ['e2e', 'functional-tests', 'backend-tests', 'build-docker', 'frontend'];

    /**
     * Jobs de ci.yml qui gardent un `runs-on` littéral GitHub-hosted. Vide : tout tourne sur le
     * runner. `engine-perf` n'y entre que si la mesure de marge (BCCL nominal > 10 s) l'impose,
     * sur décision du fondateur.
     */
    private const array LITERAL_JOBS = [];

    /**
     * Workflows qui restent chez GitHub (déploiement, balayage hebdo, miroir manuel, réchauffage
     * quotidien du cache de l'image PHP — ce cache sert les runners hébergés, jamais la VM).
     */
    private const array HOSTED_WORKFLOWS = [
        '.github/workflows/deploy.yml',
        '.github/workflows/security-weekly.yml',
        '.github/workflows/mirror-images.yml',
        self::CACHE_WARM,
    ];

    /**
     * Les 3 régimes : chaque job de ci.yml est dans UN seul (D2), et chaque régime a son `if`
     * EXACT (D3). Un job sauté compte comme RÉUSSI pour un required check : une coquille d'`if`
     * viderait le gate de `main` en silence — d'où listes fermées et conditions exactes.
     *
     * PR_MAIN : PR vers `main` seulement — le gate complet, 15 checks requis plus `engine-perf`.
     */
    private const array PR_MAIN = [
        'preflight', 'lint', 'frontend', 'secrets-scan', 'semgrep', 'dependency-audit', 'phpstan', 'rector',
        'blocking-tests', 'backend-tests', 'engine-tests', 'engine-perf', 'e2e', 'engine-semantics',
        'functional-tests', 'build-docker',
    ];

    /** LOT : PR vers une autre base que `main` (les `lot/**`) — un seul job court. */
    private const array LOT = ['lot-gate'];

    /** MAIN_PUSH : push sur `main` seulement — le verdict que lit `isCiGreen`. */
    private const array MAIN_PUSH = ['main-verdict'];

    private const string IF_PR_MAIN = 'github.event_name==\'pull_request\'&&github.base_ref==\'main\'';

    private const string IF_LOT = 'github.event_name==\'pull_request\'&&github.base_ref!=\'main\'';

    private const string IF_MAIN_PUSH = 'github.event_name==\'push\'';

    /** `if` de build-docker, chaîne ENTIÈRE squashée (sans espaces ni `${{ }}`). */
    private const string IF_BUILD_DOCKER = '!cancelled()&&needs.preflight.result==\'success\'&&!contains(needs.*.result,\'failure\')'
        . '&&!contains(needs.*.result,\'cancelled\')&&github.event_name==\'pull_request\'&&github.base_ref==\'main\'';

    private const array BUILD_DOCKER_NEEDS = ['preflight', 'blocking-tests', 'engine-tests'];

    /**
     * COPIE des 15 contextes requis de `main` (tous `app_id 15368`), relue par
     * `gh api repos/{owner}/{repo}/branches/main/protection/required_status_checks` le 2026-11-15.
     * Aucun test ne relit la protection réelle : une divergence se voit à cette relecture-là.
     * Ni « Engine Perf Gate », ni « Lot Gate », ni « Main Verdict » n'y figurent.
     */
    private const array MAIN_REQUIRED_CHECKS = [
        'Preflight', 'Lint', 'Secrets Scan (gitleaks)', 'Semgrep (security gate)', 'Dependency Audit',
        'Engine Tests', 'PHPStan & CS-Fixer', 'Rector (style gate)', 'Blocking Tests', 'Frontend (build + unit)',
        'Engine semantics', 'Backend Tests (suite + couverture)', 'E2E (Playwright)', 'Functional Tests (Behat)',
        'Build Docker Images',
    ];

    /** Nom de check (`name:` du job) → id de job dans ci.yml. */
    private const array CHECK_NAME_TO_JOB = [
        'Preflight' => 'preflight',
        'Lint' => 'lint',
        'Secrets Scan (gitleaks)' => 'secrets-scan',
        'Semgrep (security gate)' => 'semgrep',
        'Dependency Audit' => 'dependency-audit',
        'Engine Tests' => 'engine-tests',
        'PHPStan & CS-Fixer' => 'phpstan',
        'Rector (style gate)' => 'rector',
        'Blocking Tests' => 'blocking-tests',
        'Frontend (build + unit)' => 'frontend',
        'Engine semantics' => 'engine-semantics',
        'Backend Tests (suite + couverture)' => 'backend-tests',
        'E2E (Playwright)' => 'e2e',
        'Functional Tests (Behat)' => 'functional-tests',
        'Build Docker Images' => 'build-docker',
    ];

    /** Le seul check du ruleset `lot-gate` (hors dépôt), réduit par le geste 3 de la PR 1. */
    private const array LOT_REQUIRED_CHECKS = ['Lot Gate'];

    /** Run de push main (`isCiGreen` lit ce run) : le verdict seul. */
    private const array MAIN_PUSH_RUN = ['main-verdict'];

    private const string CONCURRENCY_GROUP = 'ci-${{ github.ref }}';

    private const string CONCURRENCY_CANCEL = '${{ github.ref != \'refs/heads/main\' }}';

    private const string FILTERED_NR = 'testAMutualisationCorrectionWithATeamFromAnotherClubIsRefusedWritingNothing';

    /** Ids de steps lus par « Classify failure » du job e2e. */
    private const array E2E_STEP_IDS = ['prepull', 'buildengine', 'startservices', 'buildfrontend', 'playwright', 'a17'];

    /**
     * R1 — Promesse : chaque job de ci.yml part sur le runner par une VARIABLE de dépôt (retour
     * arrière = supprimer la variable, sans commit), dans sa classe de poids ; le gate de lot et
     * le verdict ont chacun la leur, hébergée par défaut, que la réserve de minutes bascule sur la
     * VM (plan § 9.1, § 9.6) ; ce qui doit rester chez GitHub, réchauffage du cache compris,
     * garde le littéral.
     */
    public function testEveryCiJobIsRoutedByARepositoryVariableInItsWeightClass(): void
    {
        $jobs = $this->jobs(self::CI);
        $ids = array_keys($jobs);
        sort($ids);
        $expected = self::JOBS;
        sort($expected);
        self::assertSame($expected, $ids, "ci.yml doit porter EXACTEMENT ces jobs (les 16 du gate complet, `lot-gate`, `main-verdict`).\n"
            . 'Un job ajouté ou retiré se range ici, dans sa classe de runner et son régime (PR_MAIN/LOT/MAIN_PUSH).');

        $wrong = [];
        foreach ($jobs as $id => $job) {
            $want = match (true) {
                \in_array($id, self::LITERAL_JOBS, true) => 'ubuntu-24.04',
                'lot-gate' === $id => self::RUNS_ON_LOT,
                'main-verdict' === $id => self::RUNS_ON_VERDICT,
                \in_array($id, self::HEAVY_JOBS, true) => self::RUNS_ON_HEAVY,
                default => self::RUNS_ON,
            };
            $got = $this->squash($job['runs-on'] ?? null);
            if ($got !== $want) {
                $wrong[] = \sprintf('%s : runs-on « %s », attendu « %s »', $id, $got, $want);
            }
        }
        self::assertSame([], $wrong, "runs-on hors régime dans ci.yml :\n  - " . implode("\n  - ", $wrong)
            . "\nLourds (e2e, functional-tests, backend-tests, build-docker, frontend) → CI_RUNS_ON_HEAVY ; lot-gate → CI_RUNS_ON_LOT ;"
            . ' main-verdict → CI_RUNS_ON_VERDICT ; les autres → CI_RUNS_ON ;'
            . ' toujours avec le repli `|| \'ubuntu-24.04\'` (variable absente = GitHub-hosted).');

        foreach (self::HOSTED_WORKFLOWS as $file) {
            foreach ($this->jobs($file) as $id => $job) {
                self::assertSame('ubuntu-24.04', $job['runs-on'] ?? null, \sprintf(
                    '%s / %s doit rester sur `ubuntu-24.04` littéral : il ne dépend jamais de la VM (déploiement, hebdo, miroir).',
                    $file,
                    $id,
                ));
            }
        }

        $visual = $this->job(self::VISUAL, 'visual-baselines');
        self::assertSame(self::RUNS_ON_HEAVY, $this->squash($visual['runs-on'] ?? null), 'visual-baselines doit tourner dans la classe heavy (CI_RUNS_ON_HEAVY) : même conteneur que le job e2e qui compare ses PNG.');

        foreach ($this->jobs(self::PROBE) as $id => $job) {
            self::assertSame('${{vars.CI_RUNS_ON}}', $this->squash($job['runs-on'] ?? null), \sprintf(
                '%s / %s : la sonde vise `vars.CI_RUNS_ON` NU — sans repli GitHub-hosted, sa preuve ne vaut que dans la VM.',
                self::PROBE,
                $id,
            ));
        }
    }

    /**
     * R2 — Promesse : une PR de fork n'exécute jamais rien sur le runner — `preflight` (sans
     * checkout) rougit sur un `pull_request` vers `main` venu d'un autre dépôt, et tout job du
     * gate complet l'attend. Seuls deux jobs ne l'attendent pas (plan § 9.2, § 9.4) : `lot-gate`,
     * qui porte sa propre garde fork en premier step, et `main-verdict`, qui ne tourne que sur un
     * push `main` (un fork ne pousse pas sur `main`) et n'attendrait sinon jamais une VM éteinte.
     */
    public function testAForkPullRequestIsRefusedBeforeAnyJobTouchesTheRunner(): void
    {
        $jobs = $this->jobs(self::CI);
        self::assertArrayHasKey('preflight', $jobs, 'ci.yml doit porter le job `preflight` (garde fork, required check « Preflight »).');
        $preflight = $jobs['preflight'];

        self::assertSame('Preflight', $preflight['name'] ?? null, 'Le job `preflight` s\'appelle « Preflight » : c\'est le nom du required check.');
        self::assertArrayNotHasKey('needs', $preflight, '`preflight` est la racine du graphe : il n\'attend rien.');
        self::assertSame(self::IF_PR_MAIN, $this->condition($preflight['if'] ?? null), '`preflight` ne tourne que sur une PR vers `main` : il ouvre le gate complet, et lui seul.');

        $guard = null;
        foreach ($this->steps($preflight, 'preflight') as $step) {
            self::assertStringNotContainsString('actions/checkout', $this->str($step['uses'] ?? null), '`preflight` ne checkoute JAMAIS : le code d\'un fork ne doit pas atterrir sur le runner.');
            if ('Refuse pull requests from forks' === ($step['name'] ?? null)) {
                $guard = $step;
            }
        }
        self::assertIsArray($guard, '`preflight` doit porter le step « Refuse pull requests from forks ».');

        $if = $this->condition($guard['if'] ?? null);
        self::assertStringContainsString('github.event_name==\'pull_request\'', $if, 'La garde fork ne joue que sur `pull_request` (le champ head.repo n\'existe que là ; sur `main` elle se saute).');
        $whole = $if . $this->squash($guard['run'] ?? null) . $this->squash(json_encode($guard['env'] ?? []));
        self::assertStringContainsString('github.event.pull_request.head.repo.full_name', $whole, 'La garde compare le dépôt de la tête de PR…');
        self::assertStringContainsString('github.repository', str_replace('github.event.pull_request.head.repo.full_name', '', $whole), '… au dépôt lui-même (github.repository).');
        self::assertStringContainsString('exit1', $this->squash($guard['run'] ?? null), 'La garde fork doit faire ÉCHOUER le step (`exit 1`), pas seulement écrire un message.');

        $orphans = [];
        foreach ($jobs as $id => $job) {
            if (\in_array($id, ['preflight', 'lot-gate', 'main-verdict'], true)) {
                continue;
            }
            if (!\in_array('preflight', $this->needs($job), true)) {
                $orphans[] = $id;
            }
        }
        self::assertSame([], $orphans, "Ces jobs n'attendent pas `preflight` (needs) — ils démarreraient sur le runner avant la garde fork :\n  - "
            . implode("\n  - ", $orphans));

        foreach (['lot-gate', 'main-verdict'] as $id) {
            self::assertArrayHasKey($id, $jobs, \sprintf('ci.yml doit porter le job `%s`.', $id));
            self::assertArrayNotHasKey('needs', $jobs[$id], \sprintf('`%s` n\'attend aucun job : `preflight` est sauté dans son régime, et un `needs` sauté le sauterait aussi (sauté = succès).', $id));
        }
    }

    /**
     * D1 — Promesse : ci.yml ne se déclenche QUE sur un push vers `main` et sur une PR vers
     * `main` ou `lot/**`, et un changement de base (type `edited`) relance le run — un squash
     * dans `lot/<sujet>` ne lance rien, aucun autre déclencheur ne contourne les régimes.
     */
    public function testTriggersAreExactlyPushMainAndPullRequestToMainOrLot(): void
    {
        $on = $this->workflow(self::CI)['on'] ?? null;
        self::assertIsArray($on, 'ci.yml déclare `on:` en mapping.');
        $events = array_keys($on);
        sort($events);
        self::assertSame(['pull_request', 'push'], $events, "ci.yml : `on` = EXACTEMENT {push, pull_request} — aucun pull_request_target,\n"
            . 'workflow_dispatch ni merge_group (un déclencheur hors régime jouerait un run sans gate prévu).');

        self::assertIsArray($on['push'] ?? null, '`on.push` est un mapping.');
        self::assertSame(['main'], $on['push']['branches'] ?? null, '`on.push.branches` = [main] seul : un squash dans lot/<sujet> ne déclenche aucun run.');

        self::assertIsArray($on['pull_request'] ?? null, '`on.pull_request` est un mapping.');
        self::assertSame(['main', 'lot/**'], $on['pull_request']['branches'] ?? null, '`on.pull_request.branches` = [main, \'lot/**\'] (ordre compris).');
        self::assertSame(['opened', 'synchronize', 'reopened', 'edited'], $on['pull_request']['types'] ?? null, "`on.pull_request.types` = [opened, synchronize, reopened, edited] (ordre compris) :\n"
            . '`edited` relance le gate COMPLET quand la base d\'une PR passe de lot/<sujet> à main.');
    }

    /**
     * D2 — Promesse : chaque job de ci.yml est rangé dans UN seul des 3 régimes (PR_MAIN 16 jobs,
     * LOT 1, MAIN_PUSH 1 ; plan § 9.1) — un job ajouté demain rougit ici tant qu'il n'est pas rangé.
     */
    public function testEveryJobBelongsToExactlyOneRegime(): void
    {
        $ids = array_keys($this->jobs(self::CI));
        sort($ids);

        self::assertCount(16, self::PR_MAIN);
        self::assertCount(1, self::LOT);
        self::assertCount(1, self::MAIN_PUSH);
        $classified = array_merge(self::PR_MAIN, self::LOT, self::MAIN_PUSH);
        self::assertSame(array_values(array_unique($classified)), $classified, 'Un job figure dans DEUX régimes.');
        sort($classified);
        self::assertSame($ids, $classified, "Les 3 régimes doivent couvrir EXACTEMENT les jobs de ci.yml.\n"
            . 'Job manquant = non rangé (son `if` n\'est gardé par rien) ; job en trop = disparu de ci.yml.');
    }

    /**
     * D3 — Promesse : chaque job porte l'`if` EXACT de son régime, rien de plus — aucune condition
     * cachée (chemins, doc seule, action de PR…) ne peut sauter un job du gate de `main`, et
     * `build-docker` garde sa chaîne entière et ses `needs` (plan § 12, contrats de la PR 1).
     */
    public function testEveryRegimeConditionIsExact(): void
    {
        $jobs = $this->jobs(self::CI);
        $wrong = [];
        $expect = function (string $id, ?string $want, array $job) use (&$wrong): void {
            $got = \array_key_exists('if', $job) ? $this->condition($job['if']) : null;
            if ($got !== $want) {
                $wrong[] = \sprintf('%s : if « %s », attendu « %s »', $id, $got ?? '(aucun)', $want ?? '(aucun)');
            }
        };

        foreach (self::PR_MAIN as $id) {
            $expect($id, 'build-docker' === $id ? self::IF_BUILD_DOCKER : self::IF_PR_MAIN, $jobs[$id] ?? []);
        }
        foreach (self::LOT as $id) {
            $expect($id, self::IF_LOT, $jobs[$id] ?? []);
        }
        foreach (self::MAIN_PUSH as $id) {
            $expect($id, self::IF_MAIN_PUSH, $jobs[$id] ?? []);
        }

        self::assertSame([], $wrong, "`if` hors régime dans ci.yml (comparaison sans espaces ni `\${{ }}`) :\n  - " . implode("\n  - ", $wrong)
            . "\nUn job sauté vaut SUCCÈS pour un required check : l'`if` d'un régime est exact, jamais « contient ».");

        self::assertSame(self::BUILD_DOCKER_NEEDS, $this->needs($jobs['build-docker'] ?? []), '`build-docker` : needs EXACTS [preflight, blocking-tests, engine-tests].');
    }

    /**
     * D4 — Promesse : une PR vers `main` (dont la PR finale d'un lot) joue TOUT le gate — les 16
     * jobs, perf du moteur comprise ; seuls `lot-gate` et `main-verdict` sont sautés (plan § 9.3).
     * Évaluation des `if` réels de ci.yml.
     */
    public function testAPullRequestToMainRunsTheWholeGate(): void
    {
        $expected = self::PR_MAIN;
        sort($expected);
        self::assertCount(16, $expected);
        self::assertSame($expected, $this->jobsThatRun($this->pullRequestTo('main')), 'PR→main doit exécuter les 16 jobs du gate complet (tout sauf lot-gate et main-verdict).');
    }

    /**
     * D5 — Promesse : chacun des 15 required checks de `main` est le `name:` d'un job qui TOURNE
     * sur une PR→main — aucun n'y est satisfait par un job sauté (sauté = succès pour GitHub).
     */
    public function testEveryRequiredCheckOfMainIsProducedOnAPullRequestToMain(): void
    {
        self::assertCount(15, self::MAIN_REQUIRED_CHECKS);
        self::assertSame(self::MAIN_REQUIRED_CHECKS, array_values(array_unique(self::MAIN_REQUIRED_CHECKS)));
        self::assertNotContains('Engine Perf Gate', self::MAIN_REQUIRED_CHECKS);
        self::assertNotContains('Lot Gate', self::MAIN_REQUIRED_CHECKS);
        self::assertNotContains('Main Verdict', self::MAIN_REQUIRED_CHECKS);
        $names = array_keys(self::CHECK_NAME_TO_JOB);
        sort($names);
        $required = self::MAIN_REQUIRED_CHECKS;
        sort($required);
        self::assertSame($required, $names, 'CHECK_NAME_TO_JOB nomme exactement les 15 checks requis de main.');

        $jobs = $this->jobs(self::CI);
        $running = $this->jobsThatRun($this->pullRequestTo('main'));
        $wrong = [];
        foreach (self::CHECK_NAME_TO_JOB as $check => $id) {
            if (($jobs[$id]['name'] ?? null) !== $check) {
                $wrong[] = \sprintf('« %s » : le job `%s` s\'appelle « %s »', $check, $id, $this->str($jobs[$id]['name'] ?? null));
            } elseif (!\in_array($id, $running, true)) {
                $wrong[] = \sprintf('« %s » : le job `%s` est SAUTÉ sur une PR→main', $check, $id);
            }
        }
        self::assertSame([], $wrong, "Required check de main non produit par un job exécuté sur PR→main :\n  - " . implode("\n  - ", $wrong));
    }

    /**
     * D6 — Promesse : une PR vers `lot/<sujet>` joue UN seul job, `lot-gate`, dont le nom
     * « Lot Gate » est le seul check du ruleset `lot-gate` ; les 17 autres sont sautés (plan § 9.2,
     * § 9.5).
     */
    public function testAPullRequestToALotBranchRunsOnlyTheLotGate(): void
    {
        $running = $this->jobsThatRun($this->pullRequestTo('lot/essai'));
        self::assertSame(self::LOT, $running, 'PR→lot/** doit exécuter EXACTEMENT `lot-gate`, et lui seul.');

        $jobs = $this->jobs(self::CI);
        self::assertSame(self::LOT_REQUIRED_CHECKS, [$this->str($jobs['lot-gate']['name'] ?? null)], 'Le job `lot-gate` s\'appelle « Lot Gate » : c\'est le seul check requis du ruleset `lot-gate`.');
        self::assertSame(25, $jobs['lot-gate']['timeout-minutes'] ?? null, '`lot-gate` : délai de 25 minutes (cible 9 minutes facturées).');
    }

    /**
     * D7 — Promesse : le push `main` ne joue que le verdict (plan § 9.4) : « Main Verdict », le
     * run que lit `isCiGreen` avant un déploiement.
     */
    public function testMainPushRunsOnlyTheVerdict(): void
    {
        $expected = self::MAIN_PUSH_RUN;
        sort($expected);
        self::assertSame($expected, $this->jobsThatRun(['event_name' => 'push', 'base_ref' => '', 'ref' => 'refs/heads/main']), 'push main doit exécuter EXACTEMENT `main-verdict`.');
        self::assertSame('Main Verdict', $this->job(self::CI, 'main-verdict')['name'] ?? null, 'Le job `main-verdict` s\'appelle « Main Verdict » (une des deux lignes « skipping » admises au GO).');
    }

    /**
     * D8 — Promesse : aucun job n'est sauté selon le CONTENU d'une PR — ni `paths`/`paths-ignore`
     * sur les déclencheurs, ni filtre « doc seule » (décision 2026-11-06, maintenue), ni `git diff`
     * qui saute un step ; la SEULE sélection par contenu est `vitest --changed` dans le gate de lot
     * (plan § 9.2 ; le filtre par chemin de la perf PR disparaît, § 9.3).
     */
    public function testNoTriggerOrJobFiltersOnTheChangedPaths(): void
    {
        $byContent = [];
        foreach ($this->jobs(self::CI) as $id => $job) {
            foreach (\is_array($job['steps'] ?? null) ? $job['steps'] : [] as $i => $step) {
                if (!\is_array($step)) {
                    continue;
                }
                $where = \sprintf('%s / %s', $id, $this->str($step['name'] ?? $i));
                $uses = $this->str($step['uses'] ?? null);
                if (str_contains($uses, 'paths-filter') || str_contains($uses, 'changed-files')) {
                    $byContent[] = $where . ' (uses ' . $uses . ')';
                }
                foreach ($this->logicalLines($this->str($step['run'] ?? null)) as $line) {
                    if (str_contains($line, 'git diff') || str_contains($line, '--changed')) {
                        $byContent[] = $where;
                    }
                }
            }
        }
        self::assertSame(['lot-gate / ' . self::RELATED_STEP], array_values(array_unique($byContent)), "La seule sélection par contenu de ci.yml est `vitest --changed` du gate de lot ; trouvé :\n  - "
            . implode("\n  - ", $byContent));

        $on = $this->workflow(self::CI)['on'] ?? [];
        self::assertIsArray($on);
        foreach ($on as $event => $config) {
            if (\is_array($config)) {
                self::assertArrayNotHasKey('paths', $config, \sprintf('`on.%s` : aucun filtre `paths`.', (string) $event));
                self::assertArrayNotHasKey('paths-ignore', $config, \sprintf('`on.%s` : aucun filtre `paths-ignore`.', (string) $event));
            }
        }
        $raw = file_get_contents(self::ROOT . '/' . self::CI);
        self::assertIsString($raw);
        self::assertSame(0, preg_match('/docs[_-]only/i', $raw), 'Aucun filtre « doc seule » : chaque PR joue tout son régime.');
    }

    /**
     * D9 — Promesse : la concurrence ne change pas — un groupe par ref, le run en cours annulé
     * partout sauf sur `main`.
     */
    public function testConcurrencyIsUnchanged(): void
    {
        $concurrency = $this->workflow(self::CI)['concurrency'] ?? null;
        self::assertIsArray($concurrency, 'ci.yml déclare `concurrency:` en mapping.');
        self::assertSame($this->squash(self::CONCURRENCY_GROUP), $this->squash($concurrency['group'] ?? null), 'concurrency.group = ci-${{ github.ref }}.');
        self::assertSame($this->squash(self::CONCURRENCY_CANCEL), $this->squash($concurrency['cancel-in-progress'] ?? null), 'concurrency.cancel-in-progress = ${{ github.ref != \'refs/heads/main\' }}.');
    }

    /**
     * D15 — Promesse : aucun `if` (job ou step) ne lit `github.event.action` — un `edited` (titre,
     * corps, base) rejoue le régime COMPLET de la base courante, jamais un run aux jobs sautés.
     */
    public function testNoJobFiltersOnThePullRequestAction(): void
    {
        $offenders = [];
        foreach ($this->jobs(self::CI) as $id => $job) {
            if (str_contains($this->squash($job['if'] ?? null), 'github.event.action')) {
                $offenders[] = $id;
            }
            foreach (\is_array($job['steps'] ?? null) ? $job['steps'] : [] as $i => $step) {
                if (\is_array($step) && str_contains($this->squash($step['if'] ?? null), 'github.event.action')) {
                    $offenders[] = \sprintf('%s / step %s', $id, (string) ($step['name'] ?? $i));
                }
            }
        }
        self::assertSame([], $offenders, "Ces `if` lisent github.event.action — un `edited` produirait un run aux jobs sautés (= succès) :\n  - " . implode("\n  - ", $offenders));
    }

    /**
     * R4 — Promesse : deux jobs qui tournent en même temps sur le même démon Docker ne se voient
     * jamais — projet compose propre au job, overlay sans port hôte, identifiants Docker propres
     * au job, et la stack toujours démontée à la fin. Vaut pour le gate de lot, qui tourne sur la
     * VM dès que la réserve de minutes l'y bascule (plan § 9.6), et pour le relevé hebdomadaire
     * de couverture, qui y tourne chaque lundi à côté des PR (plan § 5.1).
     */
    public function testEveryJobIsSealedOnTheSharedDockerDaemon(): void
    {
        $sealed = $this->jobs(self::CI);
        unset($sealed['preflight']);
        $sealed['visual-baselines.yml/visual-baselines'] = $this->job(self::VISUAL, 'visual-baselines');
        // Le relevé hebdomadaire de couverture tourne sur la VM, classe lourde, à côté des PR.
        foreach ($this->jobs(self::QUALITY) as $id => $job) {
            $sealed['quality-weekly.yml/' . $id] = $job;
        }

        $composeJobs = 0;
        foreach ($sealed as $id => $job) {
            $steps = $this->steps($job, $id);

            $isolate = null;
            foreach ($steps as $i => $step) {
                if (self::ISOLATE_STEP === ($step['name'] ?? null)) {
                    $isolate = $i;
                    break;
                }
            }
            self::assertIsInt($isolate, \sprintf('`%s` doit porter le step « %s » (DOCKER_CONFIG propre au job).', $id, self::ISOLATE_STEP));
            if ('lot-gate' === $id) {
                // Le gate de lot refuse un fork AVANT tout checkout : l'isolement vient juste après
                // la garde (step 0) et le checkout (step 1), et avant tout login.
                self::assertSame(2, $isolate, \sprintf('`lot-gate` : « %s » vient en troisième, après la garde fork et le checkout.', self::ISOLATE_STEP));
            } else {
                self::assertLessThanOrEqual(1, $isolate, \sprintf('`%s` : « %s » vient en premier ou juste après le checkout.', $id, self::ISOLATE_STEP));
            }
            for ($i = 0; $i < $isolate; ++$i) {
                self::assertFalse($this->isDockerLogin($steps[$i]), \sprintf('`%s` : un login Docker précède « %s » — ses identifiants tomberaient dans le DOCKER_CONFIG du runner.', $id, self::ISOLATE_STEP));
            }
            $run = $this->str($steps[$isolate]['run'] ?? null);
            self::assertStringContainsString('DOCKER_CONFIG=$RUNNER_TEMP/docker-config', $run, \sprintf('`%s` : le step d\'isolement pose DOCKER_CONFIG=$RUNNER_TEMP/docker-config.', $id));
            self::assertStringContainsString('>> "$GITHUB_ENV"', $run, \sprintf('`%s` : le step d\'isolement écrit dans "$GITHUB_ENV" (le contexte `runner` est interdit dans un `env` de job).', $id));

            $env = \is_array($job['env'] ?? null) ? $job['env'] : [];
            self::assertArrayNotHasKey('DOCKER_CONFIG', $env, \sprintf('`%s` : DOCKER_CONFIG se pose par step, jamais dans l\'`env` du job.', $id));
            foreach ($env as $key => $value) {
                self::assertStringNotContainsString('runner.', $this->str($value), \sprintf('`%s` : l\'`env` de job cite le contexte `runner` (%s) — GitHub le refuse à ce niveau.', $id, (string) $key));
            }

            if (!$this->isComposeJob($steps)) {
                continue;
            }
            ++$composeJobs;

            self::assertSame(self::PROJECT, $env['COMPOSE_PROJECT_NAME'] ?? null, \sprintf('`%s` monte une stack : COMPOSE_PROJECT_NAME = « %s » (un projet par job, par tentative).', $id, self::PROJECT));
            self::assertSame(self::PROJECT, $env['AMATEO_STACK'] ?? null, \sprintf('`%s` : AMATEO_STACK = COMPOSE_PROJECT_NAME (préfixe des noms de conteneurs).', $id));
            self::assertSame(self::COMPOSE_FILE, $env['COMPOSE_FILE'] ?? null, \sprintf(
                '`%s` : COMPOSE_FILE = overlay test (aucun port hôte) en chemins ABSOLUS — un step en `working-directory: frontend` ne résoudrait pas un chemin relatif.',
                $id,
            ));

            $last = $steps[array_key_last($steps)];
            self::assertSame(self::STACK_DOWN, $last['uses'] ?? null, \sprintf('`%s` : le DERNIER step démonte la stack (%s).', $id, self::STACK_DOWN));
            self::assertSame('always()', $this->condition($last['if'] ?? null), \sprintf('`%s` : le démontage tourne `if: always()`, même job rouge.', $id));
        }

        self::assertGreaterThan(5, $composeJobs, 'Trop peu de jobs compose détectés — la détection a-t-elle cassé ?');

        $action = self::ROOT . '/.github/actions/ci-stack-down/action.yml';
        self::assertFileExists($action, 'Le composite ci-stack-down doit exister.');
        $content = file_get_contents($action);
        self::assertIsString($content);
        self::assertStringContainsString('down -v --rmi local --remove-orphans', $content, 'ci-stack-down : conteneurs, volumes, images locales et orphelins du projet du job.');
    }

    /**
     * R5 — Promesse : le gate bloquant lance TOUS ses tests en deux processus PHPUnit (suite
     * phase1 + le NR tenant filtré, qui n'est pas phase1), il s'inclut lui-même, et la suite
     * complète de `backend-tests` reste entière.
     */
    public function testTheBlockingGateRunsInTwoPhpunitProcessesAndKeepsTheFilteredTenantNr(): void
    {
        $jobs = $this->jobs(self::CI);
        $phpunit = [];
        foreach ($this->steps($jobs['blocking-tests'], 'blocking-tests') as $step) {
            if (str_contains($this->str($step['run'] ?? null), 'phpunit')) {
                $phpunit[] = $step;
            }
        }
        self::assertCount(2, $phpunit, \sprintf('Le job `blocking-tests` lance PHPUnit en EXACTEMENT 2 steps (aujourd\'hui %d) : la suite phase1 en un processus + le step filtré.', \count($phpunit)));

        $suite = null;
        $filtered = null;
        foreach ($phpunit as $step) {
            $run = $this->str($step['run'] ?? null);
            if (str_contains($run, '--filter')) {
                $filtered = $run;
            } else {
                $suite = $step;
            }
        }
        self::assertIsArray($suite, 'Il manque le step de la suite phase1.');
        self::assertSame('Blocking suite (phase1, one PHPUnit process)', $suite['name'] ?? null);
        $run = $this->str($suite['run'] ?? null);
        self::assertStringContainsString('--group phase1', $run);
        self::assertStringContainsString('-d memory_limit=1G', $run, 'Une seule invocation pour ~133 fichiers : memory_limit=1G, comme `backend-tests`.');

        $paths = [];
        foreach ($this->logicalLines($run) as $line) {
            preg_match_all('#tests/\S*?Test\.php#', $line, $found);
            foreach ($found[0] as $path) {
                $paths[$path] = true;
            }
        }
        self::assertGreaterThanOrEqual(100, \count($paths), \sprintf('La suite bloquante doit nommer ≥ 100 fichiers de test (hors commentaires) ; %d trouvés.', \count($paths)));
        self::assertArrayHasKey('tests/Unit/Documentation/CiWorkflowRegimeTest.php', $paths, 'Ce NR doit GATER : ajoutez son chemin au step « Blocking suite » (et sa ligne dans docs/testing/blocking-tests.md).');

        self::assertIsString($filtered, 'Le NR tenant filtré doit rester un step à part.');
        self::assertStringContainsString('tests/Integration/Api/ParcImportExecuteTest.php', $filtered);
        self::assertStringContainsString('--filter ' . self::FILTERED_NR, $filtered, 'Le NR tenant `ParcImportExecuteTest` n\'est pas phase1 : une invocation `--group phase1` le perdrait en silence.');

        $full = '';
        foreach ($this->steps($jobs['backend-tests'], 'backend-tests') as $step) {
            $full .= $this->str($step['run'] ?? null) . "\n";
        }
        self::assertStringContainsString('phpunit tests/ --exclude-group contract', $full, '`backend-tests` rejoue toujours la suite COMPLÈTE.');
    }

    /**
     * R6 — Promesse : sur un runner persistant, l'audit et le scan d'images ne laissent rien
     * derrière eux ni ne se marchent dessus — pas de PHP installé sur l'hôte, cache Trivy sous le
     * dossier temporaire du job, tags de scan propres au run, images prod tirées fraîches.
     */
    public function testAuditAndImageScanLeaveNothingSharedOnThePersistentRunner(): void
    {
        $raw = file_get_contents(self::ROOT . '/' . self::CI);
        self::assertIsString($raw);
        self::assertFalse(str_contains($raw, 'shivammathur/setup-php'), 'Plus de setup-php : PHP n\'est jamais installé sur l\'hôte du runner (composer audit passe par le conteneur).');

        $jobs = $this->jobs(self::CI);
        $audit = $this->steps($jobs['dependency-audit'], 'dependency-audit');
        self::assertContains('./.github/actions/build-php-cached', array_map(fn (array $s): string => $this->str($s['uses'] ?? null), $audit), '`dependency-audit` construit l\'image php par build-php-cached.');
        $auditRuns = implode("\n", array_map(fn (array $s): string => $this->str($s['run'] ?? null), $audit));
        self::assertMatchesRegularExpression('/docker compose run --rm --no-deps php-fpm[^\n]*composer audit/', $auditRuns, '`composer audit` tourne dans le conteneur php-fpm.');

        $build = $this->steps($jobs['build-docker'], 'build-docker');
        $runs = implode("\n", array_map(fn (array $s): string => $this->str($s['run'] ?? null), $build));
        self::assertStringNotContainsString('$PWD/.trivy-cache', $runs, 'Le cache Trivy (écrit en root) ne vit plus dans le workspace : le `git clean` du checkout suivant casserait.');
        self::assertStringContainsString('"$RUNNER_TEMP/trivy-cache:/root/.cache/trivy"', $runs, 'Trivy monte son cache depuis $RUNNER_TEMP/trivy-cache.');
        self::assertStringNotContainsString('scan/', $runs, 'Plus de tag `scan/…` partagé entre jobs : `scan-<run_id>-<attempt>/…`.');

        $trivyCache = null;
        foreach ($build as $step) {
            if (str_starts_with($this->str($step['uses'] ?? null), 'actions/cache@') && str_contains($this->str($step['with']['key'] ?? null), 'trivy')) {
                $trivyCache = $step;
            }
        }
        self::assertIsArray($trivyCache, 'Le cache Actions de la base Trivy reste (pas de re-téléchargement à chaque run).');
        self::assertStringStartsWith('${{ runner.temp }}', $this->str($trivyCache['with']['path'] ?? null), 'Le `path:` du cache Trivy commence par ${{ runner.temp }}.');

        $prodBuilds = [];
        foreach ($this->logicalLines($runs) as $line) {
            if (preg_match('/\bdocker build\b/', $line) && preg_match('/--target (prod|nginx-prod)|Dockerfile\.prod/', $line)) {
                $prodBuilds[] = $line;
            }
        }
        self::assertCount(5, $prodBuilds, 'Les 5 builds d\'images prod.');
        foreach ($prodBuilds as $line) {
            self::assertStringContainsString('--pull', $line, 'Build prod sans --pull : sur un démon persistant, la base serait celle d\'un run ancien. Ligne : ' . $line);
            self::assertStringContainsString('-t scan-${{ github.run_id }}-${{ github.run_attempt }}/', $line, 'Tag de scan propre au run. Ligne : ' . $line);
        }
    }

    /**
     * D5 (lot « déploiement fiable ») — Promesse : aucune image que la release POUSSE n'échappe au
     * gate Trivy CRITICAL du build. Pour chaque image de la boucle de `deploy.yml` (step « Check
     * registry for existing images », `for IMG in …`), le step Trivy de `build-docker` scanne
     * `"$SCAN/<IMG>-prod"` ou `"$SCAN/<IMG>"`, ET « Build prod images » construit ce même tag
     * (`docker build --pull … -t scan-<run>-<tentative>/<même suffixe>`). Une image ajoutée à la
     * release sans scan rougit ici — c'est le trou qui a laissé passer 16 CRITICAL dans pdf-worker.
     */
    public function testTheBuildTrivyGateScansEveryImageDeployPushes(): void
    {
        $deploy = $this->job('.github/workflows/deploy.yml', 'build-push');
        $registryCheck = '';
        foreach ($this->steps($deploy, 'build-push') as $step) {
            if ('Check registry for existing images' === $this->str($step['name'] ?? null)) {
                $registryCheck = $this->str($step['run'] ?? null);
            }
        }
        self::assertSame(1, preg_match('/\bfor IMG in ([^;\n]+);/', $registryCheck, $loop), 'deploy.yml : la boucle `for IMG in …;` du step « Check registry for existing images » est la liste des images poussées.');
        $images = preg_split('/\s+/', trim($loop[1])) ?: [];
        self::assertNotEmpty($images);

        $build = $this->steps($this->job(self::CI, 'build-docker'), 'build-docker');
        $trivy = '';
        $prodBuild = '';
        foreach ($build as $step) {
            $name = $this->str($step['name'] ?? null);
            if (str_starts_with($name, 'Trivy scan')) {
                $trivy = $this->str($step['run'] ?? null);
            }
            if ('Build prod images' === $name) {
                $prodBuild = $this->str($step['run'] ?? null);
            }
        }
        self::assertNotSame('', $trivy, '`build-docker` doit garder son step « Trivy scan … ».');
        self::assertNotSame('', $prodBuild, '`build-docker` doit garder son step « Build prod images ».');
        $buildLines = $this->logicalLines($prodBuild);

        foreach ($images as $image) {
            $suffix = null;
            foreach ([$image . '-prod', $image] as $candidate) {
                if (str_contains($trivy, '"$SCAN/' . $candidate . '"')) {
                    $suffix = $candidate;
                    break;
                }
            }
            self::assertNotNull($suffix, \sprintf('Le gate Trivy du build ne scanne pas l\'image « %s » que deploy.yml pousse (attendu "$SCAN/%s-prod" ou "$SCAN/%s").', $image, $image, $image));

            $built = array_filter($buildLines, static fn (string $line): bool => 1 === preg_match('/\bdocker build\b/', $line)
                && str_contains($line, '--pull')
                && 1 === preg_match('~-t scan-\$\{\{ github\.run_id \}\}-\$\{\{ github\.run_attempt \}\}/' . preg_quote($suffix, '~') . '(\s|$)~', $line));
            self::assertNotEmpty($built, \sprintf('« Build prod images » ne construit pas `scan-…/%s` (avec --pull) : le scan de « %s » viserait une image absente.', $suffix, $image));
        }
    }

    /**
     * R10 — Promesse : le scan juge des images construites de zéro, comme celles que livre le
     * déploiement ; le cache persistant du démon de la VM ne doit pas décider d'un verdict de
     * sécurité. Chaque `docker build` de « Build prod images » porte `--no-cache` et `--pull`
     * (`--pull` tire la base mais sert encore une couche `RUN apt-get …` périmée) ; « Build all
     * images » (cibles de dev) garde son cache.
     */
    public function testTheImagesTheScanJudgesAreBuiltWithoutCache(): void
    {
        $build = $this->job(self::CI, 'build-docker');
        $prodLines = array_filter(
            $this->logicalLines($this->str($this->namedStep($build, 'build-docker', 'Build prod images')['run'] ?? null)),
            static fn (string $line): bool => 1 === preg_match('/\bdocker build\b/', $line),
        );
        self::assertNotEmpty($prodLines, '« Build prod images » construit les images que le scan juge.');
        foreach ($prodLines as $line) {
            self::assertMatchesRegularExpression('/(^|\s)--no-cache(\s|$)/', $line, 'Build prod sans --no-cache : une couche en cache sur le démon de la VM déciderait du verdict Trivy. Ligne : ' . $line);
            self::assertMatchesRegularExpression('/(^|\s)--pull(\s|$)/', $line, 'Build prod sans --pull. Ligne : ' . $line);
        }

        $devRun = $this->str($this->namedStep($build, 'build-docker', 'Build all images')['run'] ?? null);
        self::assertStringNotContainsString('--no-cache', $devRun, '« Build all images » (cibles de dev) garde son cache : seul le scan exige des images construites de zéro.');
    }

    /**
     * R7 — Promesse : l'e2e (et la régénération des captures) tourne dans le conteneur
     * Playwright — mêmes PNG chez GitHub et dans la VM — avec mailpit, un volume node_modules
     * rendu à l'uid du runner par l'HÔTE, et la clé TOTP du superadmin jamais exportée au job.
     *
     * Faux positif possible, assumé : la regex « chown … $(id -u) entre apostrophes » rougit si
     * deux chaînes à apostrophes distinctes d'une même ligne encadrent `chown … $(id -u)`. Le
     * correctif est alors de réécrire la ligne, jamais d'affaiblir la garde : sous apostrophes,
     * `$(id -u)` s'évalue DANS le conteneur root et vaut 0.
     */
    public function testE2eRunsInThePlaywrightContainerWithTheVolumeHandedBackByTheHost(): void
    {
        $targets = [
            'ci.yml/e2e' => $this->job(self::CI, 'e2e'),
            'visual-baselines.yml/visual-baselines' => $this->job(self::VISUAL, 'visual-baselines'),
        ];

        foreach ($targets as $id => $job) {
            $steps = $this->steps($job, $id);
            $lines = [];
            $runs = [];
            foreach ($steps as $step) {
                $uses = $this->str($step['uses'] ?? null);
                self::assertStringNotContainsString('actions/setup-node', $uses, \sprintf('`%s` : plus de setup-node — Node vit dans le conteneur Playwright.', $id));
                self::assertStringNotContainsString('ms-playwright', $this->str($step['with']['path'] ?? null), \sprintf('`%s` : plus de cache de navigateur sur l\'hôte.', $id));
                $run = $this->str($step['run'] ?? null);
                $runs[] = $run;
                foreach ($this->logicalLines($run) as $line) {
                    $lines[] = $line;
                }
            }

            foreach ($runs as $run) {
                self::assertStringNotContainsString('/tmp/', $run, \sprintf('`%s` : aucun /tmp/ (partagé entre runners) — $RUNNER_TEMP.', $id));
                if (str_contains($run, 'GITHUB_ENV')) {
                    self::assertStringNotContainsString('E2E_SUPERADMIN', $run, \sprintf('`%s` : la clé TOTP du superadmin ne part jamais dans GITHUB_ENV — lue de $RUNNER_TEMP au seul step utile.', $id));
                }
            }

            $playwright = 0;
            $mailpit = false;
            $wget = false;
            $chown = false;
            foreach ($lines as $line) {
                self::assertStringNotContainsString('playwright install', $line, \sprintf('`%s` : plus de `playwright install` sur l\'hôte.', $id));
                if (str_contains($line, 'npx playwright')) {
                    ++$playwright;
                    self::assertMatchesRegularExpression('/\bdocker compose\b.*\brun\b.*\be2e\b/', $line, \sprintf('`%s` : tout `npx playwright` passe par `docker compose … run … e2e`. Ligne : %s', $id, $line));
                }
                if (preg_match('/\bdocker compose\b.*\bup\b/', $line) && str_contains($line, 'mailpit')) {
                    $mailpit = true;
                }
                if (str_contains($line, 'wget -qO- http://127.0.0.1:5173')) {
                    $wget = true;
                }
                // Sous Alpine (frontend-dev), `localhost` se résout en `::1` (IPv6) alors que Vite
                // écoute `--host 0.0.0.0` (IPv4 seul) : une sonde sur localhost échoue toujours.
                self::assertFalse(str_contains($line, 'localhost:5173'), \sprintf('`%s` : attente Vite sur `127.0.0.1`, jamais `localhost` (→ ::1 sous Alpine, Vite en IPv4 seul). Ligne : %s', $id, $line));
                self::assertFalse(str_contains($line, 'curl') && str_contains($line, '5173'), \sprintf('`%s` : frontend-dev est busybox — attente Vite par wget, jamais curl. Ligne : %s', $id, $line));
                if (str_contains($line, '--user root') && str_contains($line, 'sh -lc "chown -R $(id -u):$(id -g) /app/frontend/node_modules"')) {
                    $chown = true;
                }
                self::assertDoesNotMatchRegularExpression('/\'[^\']*chown[^\']*\$\(id -u\)[^\']*\'/', $line, \sprintf(
                    '`%s` : `chown … $(id -u)` entre apostrophes s\'évalue dans le conteneur root (uid 0) — guillemets doubles, l\'HÔTE expanse. Ligne : %s',
                    $id,
                    $line,
                ));
            }
            self::assertGreaterThan(0, $playwright, \sprintf('`%s` : aucune invocation Playwright trouvée.', $id));
            self::assertTrue($mailpit, \sprintf('`%s` : le `up` nomme mailpit (vérification d\'e-mail à l\'inscription).', $id));
            self::assertTrue($wget, \sprintf('`%s` : attente Vite par `wget -qO- http://127.0.0.1:5173` dans frontend-dev.', $id));
            self::assertTrue($chown, \sprintf('`%s` : chown du volume e2e_node_modules en `--user root` avec `sh -lc "chown -R $(id -u):$(id -g) /app/frontend/node_modules"`.', $id));
        }

        $e2eSteps = $this->steps($targets['ci.yml/e2e'], 'e2e');
        $ids = array_map(fn (array $s): string => $this->str($s['id'] ?? null), $e2eSteps);
        foreach (self::E2E_STEP_IDS as $stepId) {
            self::assertContains($stepId, $ids, \sprintf('e2e : l\'id de step « %s » est lu par « Classify failure » — il reste.', $stepId));
        }
        $a17 = $e2eSteps[(int) array_search('a17', $ids, true)];
        $a17Run = $this->str($a17['run'] ?? null);
        self::assertMatchesRegularExpression('#E2E_BASE_URL=http://frontend(?![\w:-])#', $a17Run, 'A17 vise le service nginx `http://frontend` (pas le dev server, pas un port hôte).');
        self::assertStringContainsString('E2E_A17_REQUIRED=1', $a17Run, 'A17 : le skip ne revient pas en silence.');
    }

    /**
     * R8 — Promesse : les hooks du runner nettoient ce qu'un job a laissé (stack, volumes,
     * réseaux, tags, fichiers root) sans JAMAIS faire échouer un job, et la configuration copiée
     * dans la VM est complète et lisible.
     */
    public function testRunnerHooksCleanUpWithoutEverFailingAJob(): void
    {
        $dir = self::ROOT . '/' . self::RUNNER_DIR;
        foreach (['job-started.sh', 'job-completed.sh'] as $hook) {
            $path = $dir . '/' . $hook;
            self::assertFileExists($path, \sprintf('%s/%s doit exister.', self::RUNNER_DIR, $hook));
            self::assertNotSame(0, fileperms($path) & 0o111, \sprintf('%s doit être exécutable (git update-index --chmod=+x).', $hook));
            $content = file_get_contents($path);
            self::assertIsString($content);

            self::assertMatchesRegularExpression('/^\s*set -u\s*$/m', $content, \sprintf('%s : `set -u`.', $hook));
            self::assertStringContainsString('trap \'exit 0\' EXIT', $content, \sprintf('%s : `trap \'exit 0\' EXIT` — un hook non nul fait échouer le JOB.', $hook));
            self::assertDoesNotMatchRegularExpression('/^\s*set\s+-[a-z]*e/m', $content, \sprintf('%s : jamais `set -e` (ni -eu) — un `docker run` en 429 sortirait le hook non nul.', $hook));
            self::assertStringNotContainsString('errexit', $content, \sprintf('%s : jamais `set -o errexit`.', $hook));

            $chownLines = array_values(array_filter(
                explode("\n", $content),
                static fn (string $l): bool => !preg_match('/^\s*#/', $l) && str_contains($l, 'alpine') && str_contains($l, 'chown -R'),
            ));
            self::assertGreaterThanOrEqual(2, \count($chownLines), \sprintf('%s : deux chown via un conteneur alpine (le hook tourne sous un uid non root ; un chown direct échouerait en silence).', $hook));
            $joined = implode("\n", $chownLines);
            self::assertStringContainsString('GITHUB_WORKSPACE', $joined, \sprintf('%s : chown du workspace.', $hook));
            self::assertStringContainsString('RUNNER_TEMP', $joined, \sprintf('%s : chown du dossier temporaire du job.', $hook));
        }

        $completed = file_get_contents($dir . '/job-completed.sh');
        self::assertIsString($completed);
        self::assertStringContainsString('down -v --rmi local --remove-orphans', $completed, 'job-completed.sh rejoue le démontage du projet du job.');

        $started = file_get_contents($dir . '/job-started.sh');
        self::assertIsString($started);
        foreach (['volume', 'network', 'scan-'] as $needle) {
            self::assertStringContainsString($needle, $started, \sprintf('job-started.sh purge aussi les restes « %s » d\'un job tué.', $needle));
        }

        $envFile = $dir . '/runner.env.example';
        self::assertFileExists($envFile);
        $envContent = file_get_contents($envFile);
        self::assertIsString($envContent);
        preg_match_all('/^\s*([A-Z_]+)=/m', $envContent, $keys);
        $found = $keys[1];
        sort($found);
        self::assertSame(['ACTIONS_RUNNER_HOOK_JOB_COMPLETED', 'ACTIONS_RUNNER_HOOK_JOB_STARTED', 'DOCKER_CONFIG', 'HOME'], $found, 'runner.env.example porte exactement les 4 clés : 2 hooks, DOCKER_CONFIG, HOME.');

        $daemon = $dir . '/docker-daemon.json';
        self::assertFileExists($daemon);
        $json = file_get_contents($daemon);
        self::assertIsString($json);
        try {
            $decoded = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            self::fail('docker-daemon.json n\'est pas du JSON valide (dockerd refuserait de démarrer) : ' . $e->getMessage());
        }
        self::assertIsArray($decoded, 'docker-daemon.json est un objet JSON.');
    }

    /**
     * R9 — Promesse : la sonde d'étanchéité réseau ne se joue qu'à la main, dans la VM, avec les
     * IP en entrée (jamais dans le dépôt), et en lecture seule.
     */
    public function testTheIsolationProbeIsManualSelfHostedAndReadOnly(): void
    {
        $workflow = $this->workflow(self::PROBE);

        $on = $workflow['on'] ?? null;
        self::assertIsArray($on, 'La sonde déclare `on:`.');
        self::assertSame(['workflow_dispatch'], array_keys($on), 'La sonde se déclenche par workflow_dispatch SEUL (un pull_request n\'accepte pas d\'inputs).');
        $inputs = $on['workflow_dispatch']['inputs'] ?? null;
        self::assertIsArray($inputs);
        foreach (['host_ip', 'lan_ip', 'wsl_ip'] as $input) {
            self::assertArrayHasKey($input, $inputs, \sprintf('La sonde prend l\'input « %s ».', $input));
        }
        foreach (['lan_ip', 'wsl_ip'] as $input) {
            self::assertIsArray($inputs[$input]);
            self::assertArrayNotHasKey('default', $inputs[$input], \sprintf('« %s » sans défaut : les IP du poste ne vont pas dans le dépôt.', $input));
        }

        self::assertSame(['contents' => 'read'], $workflow['permissions'] ?? null, 'La sonde : permissions contents: read, rien d\'autre.');

        foreach ($this->jobs(self::PROBE) as $id => $job) {
            $steps = $this->steps($job, $id);
            $first = $steps[0];
            self::assertSame('Runner must be self-hosted', $first['name'] ?? null, 'Le PREMIER step de la sonde vérifie qu\'elle tourne dans la VM.');
            $whole = $this->squash($first['if'] ?? null) . $this->squash($first['run'] ?? null);
            self::assertStringContainsString('runner.environment', $whole);
            self::assertStringContainsString('self-hosted', $whole);
            self::assertStringContainsString('exit1', $this->squash($first['run'] ?? null), 'Hors self-hosted, la sonde ROUGIT (une sonde verte chez GitHub ne prouverait rien).');
        }
    }

    /**
     * L1 — Promesse : quand le gate complet part sur un runner hébergé (variables de la VM
     * absentes), `preflight` le dit en avertissement — sans checkout, sans jamais rougir (plan
     * § 9.1, contrats de la PR 1).
     */
    public function testPreflightWarnsWhenTheFullGatePlaysOnAHostedRunner(): void
    {
        $pool = $this->namedStep($this->job(self::CI, 'preflight'), 'preflight', self::POOL_STEP);
        $run = $this->str($pool['run'] ?? null);
        self::assertStringContainsString(self::POOL_WARNING, $run, '`preflight` : avertissement mot pour mot, niveau `::warning` (jamais `::error`).');
        self::assertStringNotContainsString('exit 1', $run, 'L\'avertissement de pool ne fait jamais rougir `preflight`.');
        $if = $this->condition($pool['if'] ?? null);
        self::assertMatchesRegularExpression('/runner\.environment|vars\.CI_RUNS_ON/', $if, \sprintf('« %s » ne parle que sur un runner hébergé : son `if` lit `runner.environment` ou les variables de routage.', self::POOL_STEP));
    }

    /**
     * L2 — Promesse : une PR de fork vers un lot n'exécute rien — le PREMIER step de `lot-gate` est
     * la garde fork, identique à celle de `preflight`, avant tout checkout ; le checkout qui suit
     * prend tout l'historique (gitleaks sur l'historique, `--changed` contre la base) (plan § 9.2).
     */
    public function testTheLotGateRefusesAForkBeforeAnyCheckoutAndFetchesTheWholeHistory(): void
    {
        $steps = $this->steps($this->job(self::CI, 'lot-gate'), 'lot-gate');
        $preflightGuard = $this->namedStep($this->job(self::CI, 'preflight'), 'preflight', self::FORK_GUARD);

        self::assertSame(self::FORK_GUARD, $steps[0]['name'] ?? null, '`lot-gate` : le PREMIER step est la garde fork.');
        self::assertSame($this->condition($preflightGuard['if'] ?? null), $this->condition($steps[0]['if'] ?? null), 'La garde fork de `lot-gate` porte la même condition que celle de `preflight`.');
        self::assertStringContainsString('exit1', $this->squash($steps[0]['run'] ?? null), 'La garde fork de `lot-gate` fait ÉCHOUER le job (`exit 1`).');

        self::assertStringStartsWith('actions/checkout@', $this->str($steps[1]['uses'] ?? null), '`lot-gate` : le checkout vient juste après la garde fork, jamais avant.');
        self::assertSame(0, $steps[1]['with']['fetch-depth'] ?? null, '`lot-gate` : checkout complet (`fetch-depth: 0`) — gitleaks lit tout l\'historique, `--changed` compare à origin/<base>.');
    }

    /**
     * L3 — Promesse : le gate de lot joue exactement son contenu, dans l'ordre du contrat (état A :
     * gitleaks, moteur, front, PHPStan, suite bloquante), et rien d'autre (plan § 9.2).
     */
    public function testTheLotGatePlaysItsStepsInTheContractOrder(): void
    {
        $job = $this->job(self::CI, 'lot-gate');
        $labels = array_map(fn (array $step): string => $this->stepLabel($step), $this->steps($job, 'lot-gate'));
        self::assertSame(self::LOT_GATE_STEPS, $labels, '`lot-gate` : steps exacts, dans l\'ordre du contrat de la PR 1.');

        $uses = [];
        foreach ($this->steps($job, 'lot-gate') as $step) {
            $uses[$this->stepLabel($step)] = $this->str($step['uses'] ?? null);
        }
        self::assertSame(self::BUILD_PHP_CACHED, $uses['Pre-build php image (GHA cache)'] ?? null, '`lot-gate` construit l\'image PHP par build-php-cached : le cache que réchauffe `cache-warm.yml`.');
        self::assertSame(self::STACK_DOWN, $uses['Tear down the compose stack (always)'] ?? null);
        self::assertStringContainsString('composer phpstan', $this->str($this->namedStep($job, 'lot-gate', 'Run PHPStan')['run'] ?? null), 'PHPStan reste dans le gate de lot.');
    }

    /**
     * L4 — Promesse : trois steps du gate de lot portent une condition, et pas un de plus — la garde
     * fork, le login Docker Hub, le démontage en `always()` ; tout autre step tourne toujours, le
     * premier rouge arrête le job (plan § 9.2, « Trois conditions de step admises »).
     */
    public function testTheLotGateAdmitsOnlyThreeStepConditions(): void
    {
        $preflightGuard = $this->namedStep($this->job(self::CI, 'preflight'), 'preflight', self::FORK_GUARD);
        $admitted = [
            self::FORK_GUARD => $this->condition($preflightGuard['if'] ?? null),
            'Login to Docker Hub (anti-429)' => 'vars.DOCKERHUB_USERNAME!=\'\'',
            'Tear down the compose stack (always)' => 'always()',
        ];

        $wrong = [];
        foreach ($this->steps($this->job(self::CI, 'lot-gate'), 'lot-gate') as $step) {
            $label = $this->stepLabel($step);
            $got = \array_key_exists('if', $step) ? $this->condition($step['if']) : null;
            $want = $admitted[$label] ?? null;
            if ($got !== $want) {
                $wrong[] = \sprintf('%s : if « %s », attendu « %s »', $label, $got ?? '(aucun)', $want ?? '(aucun)');
            }
        }
        self::assertSame([], $wrong, "`lot-gate` : conditions de step hors des trois admises :\n  - " . implode("\n  - ", $wrong));
    }

    /**
     * L5 — Promesse : le gate de lot laisse à la PR finale ce qu'il ne joue pas — Rector,
     * CS-Fixer, Semgrep, le groupe `contract` (sémantique moteur), la perf, la couverture Vitest,
     * ESLint (plan § 9.2, « Ce que le lot laisse passer »). Lu sur les commandes, commentaires
     * shell retirés.
     */
    public function testTheLotGateLeavesOutWhatOnlyTheFullGatePlays(): void
    {
        $lines = [];
        foreach ($this->steps($this->job(self::CI, 'lot-gate'), 'lot-gate') as $step) {
            foreach ($this->logicalLines($this->str($step['run'] ?? null)) as $line) {
                $lines[] = $line;
            }
            $lines[] = $this->str($step['uses'] ?? null);
        }
        $commands = strtolower(implode("\n", $lines));

        // Mots entiers : un chemin de la liste bloquante (`OpponentDirectoryShareTest`) contient « rector ».
        foreach ([
            '/\brector\b/' => 'Rector',
            '/\bphp-cs-fixer\b/' => 'CS-Fixer',
            '/\bsemgrep\b/' => 'Semgrep',
            '/--group[\s=]+contract\b/' => 'le groupe `contract`',
            '/--coverage\b/' => 'la couverture Vitest',
            '/\btest:coverage\b/' => 'la couverture Vitest',
            '/\bnpm run lint\b/' => 'ESLint',
            '/\beslint\b/' => 'ESLint',
        ] as $pattern => $what) {
            self::assertDoesNotMatchRegularExpression($pattern, $commands, \sprintf('`lot-gate` ne joue pas %s (%s) : la PR finale vers `main` le joue.', $what, $pattern));
        }
        self::assertDoesNotMatchRegularExpression('/-m\s*["\']?perf\b/', $commands, '`lot-gate` ne joue pas la perf du moteur : elle vit dans `engine-perf`, sur PR vers `main`.');
    }

    /**
     * L6 — Promesse : le gate de lot joue toujours les gardes statiques Vitest, puis seulement les
     * tests liés au diff (`--changed` contre la base), sans couverture ; la base passe par
     * l'environnement, jamais interpolée dans le shell (plan § 9.2, contrats de la PR 1).
     */
    public function testTheLotGateRunsTheVitestGuardsThenOnlyTheTestsRelatedToTheDiff(): void
    {
        $job = $this->job(self::CI, 'lot-gate');

        $guards = $this->namedStep($job, 'lot-gate', self::GUARDS_STEP);
        self::assertSame('frontend', $guards['working-directory'] ?? null, 'Les gardes Vitest tournent dans `frontend`.');
        self::assertStringContainsString(self::GUARDS_COMMAND, $this->str($guards['run'] ?? null), 'Gardes statiques : `src/test/`, `tooling/` et les `.guard.test`, à chaque run.');

        $related = $this->namedStep($job, 'lot-gate', self::RELATED_STEP);
        self::assertSame('frontend', $related['working-directory'] ?? null, 'Le Vitest lié tourne dans `frontend`.');
        $run = $this->str($related['run'] ?? null);
        self::assertStringContainsString(self::RELATED_COMMAND, $run, 'Vitest lié : `--changed` contre origin/<base>, vert si aucun test n\'est lié.');
        self::assertStringNotContainsString('${{', $run, 'La base n\'est jamais interpolée dans le shell (injection par nom de branche).');
        $env = \is_array($related['env'] ?? null) ? $related['env'] : [];
        self::assertSame('${{github.base_ref}}', $this->squash($env['BASE_REF'] ?? null), 'La base passe par `env: BASE_REF: ${{ github.base_ref }}`.');
    }

    /**
     * L7 — Promesse : le gate de lot joue EXACTEMENT la liste bloquante, sans la recopier — les deux
     * `run` PHPUnit de `blocking-tests` portent les ancres `&blocking_suite_run` et
     * `&filtered_tenant_nr_run`, que `lot-gate` reprend par alias (plan § 9.2, contrats de la PR 1 ;
     * la liste garde une seule maison, gardée par `BlockingTestsListMatchesCiTest`).
     */
    public function testTheLotGatePlaysExactlyTheBlockingListThroughTwoYamlAnchors(): void
    {
        $raw = file_get_contents(self::ROOT . '/' . self::CI);
        self::assertIsString($raw);
        foreach (['blocking_suite_run', 'filtered_tenant_nr_run'] as $anchor) {
            self::assertSame(1, preg_match_all('/^\s*run:\s*&' . $anchor . '\b/m', $raw), \sprintf('ci.yml : une seule ancre `run: &%s`, posée dans `blocking-tests`.', $anchor));
            self::assertSame(1, preg_match_all('/^\s*run:\s*\*' . $anchor . '\s*$/m', $raw), \sprintf('ci.yml : un seul alias `run: *%s`, dans `lot-gate`.', $anchor));
        }

        $blocking = $this->job(self::CI, 'blocking-tests');
        $lot = $this->job(self::CI, 'lot-gate');
        foreach ([self::BLOCKING_SUITE_STEP, self::FILTERED_NR_STEP] as $name) {
            $source = $this->str($this->namedStep($blocking, 'blocking-tests', $name)['run'] ?? null);
            self::assertNotSame('', $source);
            self::assertSame($source, $this->str($this->namedStep($lot, 'lot-gate', $name)['run'] ?? null), \sprintf('« %s » : `lot-gate` joue le même `run` que `blocking-tests`.', $name));
        }
    }

    /**
     * L8 — Promesse : `vitest --changed` rejoue TOUT quand change un fichier que la config ou les
     * tests lisent hors du graphe d'imports — les déclencheurs par défaut de Vitest, plus
     * `package-lock.json`, `tsconfig*.json`, `tooling/**`, `act-warnings-ceiling.json`,
     * `coverage-floor.json`, `src/test/setup.ts` (plan § 9.2). Lecture statique de la config,
     * commentaires retirés.
     */
    public function testVitestRerunsEverythingWhenAFileOutsideTheImportGraphChanges(): void
    {
        $config = file_get_contents(self::ROOT . '/frontend/vitest.config.ts');
        self::assertIsString($config);
        $code = (string) preg_replace('#//[^\n]*#', '', $config);
        self::assertSame(1, preg_match('/forceRerunTriggers\s*:\s*\[([^\]]*)\]/', $code, $m), '`frontend/vitest.config.ts` déclare `test.forceRerunTriggers: [ … ]`.');
        $triggers = $m[1];

        $defaults = str_contains($triggers, 'configDefaults.forceRerunTriggers')
            || (str_contains($triggers, 'package.json') && str_contains($triggers, 'vitest.config') && str_contains($triggers, 'vite.config'));
        self::assertTrue($defaults, '`forceRerunTriggers` garde les déclencheurs par défaut de Vitest (`...configDefaults.forceRerunTriggers`) : la clé les remplace sinon.');
        foreach (self::FULL_RERUN_TRIGGERS as $trigger) {
            self::assertStringContainsString($trigger, $triggers, \sprintf('`forceRerunTriggers` : « %s » force un passage complet.', $trigger));
        }
    }

    /**
     * L9 — Promesse : la perf du moteur est UN job de la PR vers `main`, « Engine Perf Gate », qui
     * joue ses trois paliers sans filtre par chemin, après les tests moteur, sans être un check
     * requis — le verdict de `main` la compte (plan § 9.3).
     */
    public function testTheEnginePerfIsOneJobOfThePullRequestToMainWithItsThreeTiersAndNotRequired(): void
    {
        $job = $this->job(self::CI, 'engine-perf');
        self::assertSame('Engine Perf Gate', $job['name'] ?? null, 'Le job de perf s\'appelle « Engine Perf Gate ».');
        self::assertSame(['preflight', 'engine-tests'], $this->needs($job), '`engine-perf` : needs EXACTS [preflight, engine-tests].');
        self::assertNotContains('Engine Perf Gate', self::MAIN_REQUIRED_CHECKS, 'La perf n\'est pas un check requis de `main`.');

        $perfRuns = [];
        foreach ($this->jobs(self::CI) as $id => $any) {
            foreach (\is_array($any['steps'] ?? null) ? $any['steps'] : [] as $step) {
                foreach ($this->logicalLines($this->str(\is_array($step) ? ($step['run'] ?? null) : null)) as $line) {
                    if (1 === preg_match('/-m\s*["\']?perf\b/', $line)) {
                        $perfRuns[] = $id . ' : ' . trim($line);
                    }
                }
            }
        }
        self::assertCount(1, $perfRuns, "Un seul step de ci.yml joue la perf du moteur :\n  - " . implode("\n  - ", $perfRuns));
        self::assertStringStartsWith('engine-perf : ', $perfRuns[0]);
        self::assertStringContainsString('pytest -m perf --durations=0', $perfRuns[0], '`engine-perf` : `pytest -m perf --durations=0`.');
        self::assertStringNotContainsString(' -k ', $perfRuns[0], '`engine-perf` joue les trois paliers : aucun `-k` ne les filtre.');
    }

    /**
     * L10 — Promesse : le verdict de `main` ne fait que LIRE — il ne tourne que sur un push `main`,
     * checkoute la réf par défaut (jamais une réf de PR), ne touche pas à Docker, n'a que des
     * permissions de lecture et le jeton du job, et lance `scripts/ci-verdict.py` (plan § 9.4).
     */
    public function testTheMainVerdictOnlyReadsAndProvesTheMergedPullRequest(): void
    {
        $job = $this->job(self::CI, 'main-verdict');
        self::assertSame(['actions' => 'read', 'contents' => 'read', 'pull-requests' => 'read'], $this->sorted($job['permissions'] ?? null), '`main-verdict` : permissions `contents`, `actions`, `pull-requests` en lecture, rien d\'autre.');

        $steps = $this->steps($job, 'main-verdict');
        self::assertSame(['(checkout)', self::ISOLATE_STEP, self::VERDICT_STEP], array_map(fn (array $s): string => $this->stepLabel($s), $steps), '`main-verdict` : checkout, isolement, verdict — trois steps.');

        $with = \is_array($steps[0]['with'] ?? null) ? $steps[0]['with'] : [];
        self::assertArrayNotHasKey('ref', $with, 'Le checkout du verdict est celui par défaut (le commit poussé), jamais une réf de PR.');
        self::assertArrayNotHasKey('repository', $with, 'Le checkout du verdict vise ce dépôt.');

        foreach ($steps as $step) {
            self::assertFalse($this->isDockerLogin($step), '`main-verdict` : aucun login Docker.');
        }
        self::assertFalse($this->isComposeJob($steps), '`main-verdict` : aucune stack compose.');
        $verdictRun = $this->str($steps[2]['run'] ?? null);
        self::assertStringNotContainsString('docker', $verdictRun, '`main-verdict` : aucun step Docker.');
        self::assertStringContainsString('python3 -I scripts/ci-verdict.py', $verdictRun, 'Le verdict lance `python3 -I scripts/ci-verdict.py`.');

        preg_match_all('/secrets\.(\w+)/', (string) json_encode($job), $secrets);
        self::assertSame([], array_values(array_diff(array_unique($secrets[1]), ['GITHUB_TOKEN'])), '`main-verdict` : aucun secret — seul le jeton du job.');
    }

    /**
     * L11 — Promesse : la logique du verdict est éprouvée à chaque PR — `scripts/ci-verdict-test.py`
     * tourne dans `lint`, juste avant le self-test du relevé, qui reste le dernier step (contrats
     * de la PR 1 ; `CiReleveWiringTest::testSelfTestIsTheLastStepOfLint`).
     */
    public function testTheVerdictSelfTestRunsInLintBeforeTheReleveSelfTest(): void
    {
        $steps = $this->steps($this->job(self::CI, 'lint'), 'lint');
        $names = array_map(fn (array $s): string => $this->str($s['name'] ?? null), $steps);
        $verdict = array_search('ci-verdict self-test', $names, true);
        $releve = array_search('ci-releve self-test', $names, true);
        self::assertIsInt($verdict, '`lint` porte le step « ci-verdict self-test ».');
        self::assertIsInt($releve);
        self::assertSame($releve - 1, $verdict, '« ci-verdict self-test » vient juste avant « ci-releve self-test ».');
        self::assertStringContainsString('python3 -I scripts/ci-verdict-test.py', $this->str($steps[$verdict]['run'] ?? null));
    }

    /**
     * L12 — Promesse : le cache GHA de l'image PHP reste chaud pour les PR de lot — `cache-warm.yml`
     * (« Cache Warm ») le réécrit chaque jour depuis `main` (planifié, ou à la main), sur un runner
     * hébergé, par la même action que le gate de lot (plan § 9.2 « Cache de l'image PHP »).
     */
    public function testTheCacheWarmRewritesThePhpImageCacheDailyFromMain(): void
    {
        $workflow = $this->workflow(self::CACHE_WARM);
        self::assertSame('Cache Warm', $workflow['name'] ?? null);

        $on = $workflow['on'] ?? null;
        self::assertIsArray($on);
        $events = array_keys($on);
        sort($events);
        self::assertSame(['schedule', 'workflow_dispatch'], $events, '`cache-warm.yml` : planifié et manuel, rien d\'autre (le planifié tourne sur `main`).');
        self::assertIsArray($on['schedule'], '`on.schedule` porte sa ligne cron.');
        self::assertNotEmpty($on['schedule']);

        $jobs = $this->jobs(self::CACHE_WARM);
        self::assertCount(1, $jobs, '`cache-warm.yml` : un seul job.');
        foreach ($jobs as $id => $job) {
            self::assertSame('ubuntu-24.04', $job['runs-on'] ?? null, 'Le réchauffage tourne sur `ubuntu-24.04` littéral : ce cache sert les runners hébergés.');
            $steps = $this->steps($job, $id);
            $uses = array_map(fn (array $s): string => $this->str($s['uses'] ?? null), $steps);
            self::assertContains(self::BUILD_PHP_CACHED, $uses, '`cache-warm.yml` appelle build-php-cached : même scope de cache que le gate de lot.');
            foreach ($steps as $step) {
                if (str_starts_with($this->str($step['uses'] ?? null), 'actions/checkout@')) {
                    self::assertArrayNotHasKey('ref', \is_array($step['with'] ?? null) ? $step['with'] : [], 'Le réchauffage construit le Dockerfile de `main` : checkout par défaut.');
                }
            }
        }
    }

    /**
     * M1 — Promesse : la couverture backend et front sort du run de chaque PR — aucun job de
     * ci.yml ne collecte la couverture PHPUnit ou Vitest, ni n'en publie le rapport ; seule la
     * couverture du moteur reste dans le run (28 s) ; les suites jouent sans elle (plan § 5.1,
     * contrats de la PR 2). Le nom du check « Backend Tests (suite + couverture) » ne change pas :
     * D5 (`testEveryRequiredCheckOfMainIsProducedOnAPullRequestToMain`) le garde déjà.
     */
    public function testCoverageLeavesEveryPullRequestJobButTheEngine(): void
    {
        $found = [];
        foreach ($this->jobs(self::CI) as $id => $job) {
            foreach ($this->steps($job, $id) as $step) {
                $where = \sprintf('%s / %s', $id, $this->stepLabel($step));
                foreach ($this->logicalLines($this->str($step['run'] ?? null)) as $line) {
                    foreach ([
                        '/--coverage\b/' => 'couverture PHPUnit ou Vitest',
                        '/\btest:coverage\b/' => 'couverture Vitest',
                        '/pcov\.enabled\s*=\s*1/' => 'pcov',
                        '/coverage-gate\.php/' => 'cliquet de couverture backend',
                    ] as $pattern => $what) {
                        if (1 === preg_match($pattern, $line)) {
                            $found[] = $where . ' : ' . $what;
                        }
                    }
                }
                $with = \is_array($step['with'] ?? null) ? $step['with'] : [];
                $artifact = $this->str($with['name'] ?? null);
                if (str_starts_with($this->str($step['uses'] ?? null), 'actions/upload-artifact@')
                    && \in_array($artifact, ['coverage-frontend', 'coverage-backend'], true)) {
                    $found[] = $where . ' : publie ' . $artifact;
                }
            }
        }
        self::assertSame([], $found, "La couverture backend et front vit dans `quality-weekly.yml`, plus dans un job de PR :\n  - " . implode("\n  - ", $found));

        $frontend = $this->stepStartingWith($this->job(self::CI, 'frontend'), 'frontend', 'Unit tests (Vitest');
        self::assertStringContainsString('npm run test', $this->str($frontend['run'] ?? null), '`frontend` : Vitest joue toute la suite, sans couverture.');
        $backend = $this->stepStartingWith($this->job(self::CI, 'backend-tests'), 'backend-tests', 'Run full suite');
        self::assertStringContainsString('vendor/bin/phpunit tests/ --exclude-group contract', $this->str($backend['run'] ?? null), '`backend-tests` : PHPUnit joue toute la suite, sans couverture.');

        $engine = $this->stepStartingWith($this->job(self::CI, 'engine-tests'), 'engine-tests', 'Run pytest');
        self::assertStringContainsString('--cov-fail-under', $this->squash($engine['run'] ?? null), '`engine-tests` garde sa couverture et son cliquet à chaque run.');
    }

    /**
     * M2 — Promesse : la couverture backend et front se mesure chaque lundi (ou à la main), sur la
     * VM en classe lourde, contre les planchers de `coverage-floor.json` ; un rouge ne bloque
     * aucun merge, car ce relevé ne tourne sur aucune PR ni aucun push (plan § 5.1, contrats de
     * la PR 2 ; parcours « Lundi, relevé hebdomadaire »).
     */
    public function testTheWeeklyQualityRunMeasuresBackendAndFrontendCoverageAgainstTheFloors(): void
    {
        $workflow = $this->workflow(self::QUALITY);
        self::assertSame('Quality Weekly', $workflow['name'] ?? null);

        $on = $workflow['on'] ?? null;
        self::assertIsArray($on);
        $events = array_keys($on);
        sort($events);
        self::assertSame(['schedule', 'workflow_dispatch'], $events, '`quality-weekly.yml` : planifié et manuel, jamais sur une PR ni un push — il ne bloque aucun merge.');
        self::assertIsArray($on['schedule']);
        $crons = array_map(fn (mixed $entry): string => \is_array($entry) ? $this->str($entry['cron'] ?? null) : '', $on['schedule']);
        self::assertSame(['0 5 * * 1'], array_values($crons), 'Le relevé tourne le lundi à 05:00 UTC.');

        $jobs = $this->jobs(self::QUALITY);
        $ids = array_keys($jobs);
        sort($ids);
        self::assertSame(['coverage-backend', 'coverage-frontend'], $ids, '`quality-weekly.yml` : deux jobs, `coverage-backend` et `coverage-frontend`.');

        $commands = [];
        foreach ($jobs as $id => $job) {
            self::assertSame(self::RUNS_ON_HEAVY, $this->squash($job['runs-on'] ?? null), \sprintf('`%s` tourne sur la VM, classe lourde (CI_RUNS_ON_HEAVY, repli hébergé).', $id));
            self::assertSame([], $this->needs($job), \sprintf('`%s` : aucun `needs`.', $id));
            $lines = [];
            foreach ($this->steps($job, $id) as $step) {
                foreach ($this->logicalLines($this->str($step['run'] ?? null)) as $line) {
                    $lines[] = $line;
                }
            }
            $commands[$id] = implode("\n", $lines);
        }

        self::assertMatchesRegularExpression('/vendor\/bin\/phpunit\b.*--coverage-clover\b/', $commands['coverage-backend'], '`coverage-backend` : la suite PHPUnit avec la couverture clover.');
        self::assertStringContainsString('php scripts/coverage-gate.php', $commands['coverage-backend'], '`coverage-backend` : le plancher `backend` de `coverage-floor.json`, par `coverage-gate.php`.');
        self::assertMatchesRegularExpression('/\bnpm run test:coverage\b/', $commands['coverage-frontend'], '`coverage-frontend` : Vitest avec la couverture, dont le plancher `frontend` est lu par `vitest.config.ts`.');
    }

    /**
     * M3 — Promesse : chaque suite se mesure contre son plafond de temps — `scripts/suite-time.py`
     * en dernière commande du `run:` de la suite, dans `backend-tests`, `functional-tests`,
     * `e2e`, `frontend`, `engine-tests`, une fois chacune et nulle part ailleurs ; le step ne
     * peut pas échouer en silence (plan § 8, contrats de la PR 2).
     */
    public function testEverySuiteChecksItsTimeCeilingAtTheEndOfItsRun(): void
    {
        foreach (self::TIMED_SUITES as $id => [$prefix, $suite]) {
            $step = $this->stepStartingWith($this->job(self::CI, $id), $id, $prefix);
            $lines = $this->logicalLines($this->str($step['run'] ?? null));
            self::assertNotSame([], $lines, \sprintf('`%s` / « %s » : `run` vide.', $id, $prefix));
            $last = trim((string) end($lines));
            self::assertMatchesRegularExpression(
                '/^python3 -I (\.\.\/)?scripts\/suite-time\.py --suite ' . preg_quote($suite, '/') . ' --seconds \S+$/',
                $last,
                \sprintf('`%s` / « %s » : la DERNIÈRE commande mesure la suite `%s` contre son plafond.', $id, $prefix, $suite),
            );
            self::assertArrayNotHasKey('continue-on-error', $step, \sprintf('`%s` / « %s » : un plafond dépassé fait échouer le step.', $id, $prefix));
        }

        $calls = [];
        foreach ($this->jobs(self::CI) as $id => $job) {
            foreach ($this->steps($job, $id) as $step) {
                foreach ($this->logicalLines($this->str($step['run'] ?? null)) as $line) {
                    if (str_contains($line, 'suite-time.py --suite')) {
                        $calls[] = $id;
                    }
                }
            }
        }
        sort($calls);
        $expected = array_keys(self::TIMED_SUITES);
        sort($expected);
        self::assertSame($expected, $calls, 'Une mesure par suite, dans son job seulement (le gate de lot ne mesure aucun plafond).');
    }

    /**
     * M4 — Promesse : un plafond de temps ne remonte jamais sans la ligne datée du fondateur — la
     * garde `--never-rises` compare `test-time-ceiling.json` à la base de la PR, dans `lint` (PR
     * vers `main`, dont la PR finale d'un lot, qui redemande la ligne) et dans `lot-gate` ; le
     * corps de la PR et la base passent par l'environnement, jamais interpolés dans le shell
     * (plan § 8 « Comment il remonte », « Dans un lot » ; contrats de la PR 2).
     */
    public function testTheTimeCeilingNeverRisesWithoutTheFoundersDatedLine(): void
    {
        foreach (['lint', 'lot-gate'] as $id) {
            $step = $this->namedStep($this->job(self::CI, $id), $id, self::TIME_CEILING_STEP);
            $run = $this->str($step['run'] ?? null);
            self::assertStringContainsString(self::NEVER_RISES_COMMAND, $run, \sprintf('`%s` : `suite-time.py --never-rises` contre origin/<base>.', $id));
            self::assertStringNotContainsString('${{', $run, \sprintf('`%s` : ni le corps de la PR ni la base ne sont interpolés dans le shell (injection).', $id));
            $env = \is_array($step['env'] ?? null) ? $step['env'] : [];
            self::assertSame('${{github.base_ref}}', $this->squash($env['BASE_REF'] ?? null), \sprintf('`%s` : la base passe par `env: BASE_REF`.', $id));
            self::assertSame('${{github.event.pull_request.body}}', $this->squash($env['PR_BODY'] ?? null), \sprintf('`%s` : le corps de la PR passe par `env: PR_BODY`.', $id));
            self::assertArrayNotHasKey('if', $step, \sprintf('`%s` : la garde tourne à chaque run.', $id));
            self::assertArrayNotHasKey('continue-on-error', $step, \sprintf('`%s` : un refus fait échouer le job.', $id));
        }

        $names = array_map(fn (array $s): string => $this->str($s['name'] ?? null), $this->steps($this->job(self::CI, 'lint'), 'lint'));
        $guard = array_search(self::TIME_CEILING_STEP, $names, true);
        $verdict = array_search('ci-verdict self-test', $names, true);
        self::assertIsInt($guard);
        self::assertIsInt($verdict);
        self::assertLessThan($verdict, $guard, '`lint` : la garde du plafond vient avant les self-tests, dont celui du relevé reste le dernier.');
    }

    /**
     * M5 — Promesse : les gardes Python de la mesure et du frein sont éprouvées à chaque PR — les
     * self-tests de `suite-time`, `ci-mesure` et `tests-retires` tournent dans `lint`, dans cet
     * ordre, avant celui du verdict ; celui du relevé reste le dernier step (décision de
     * l'orchestrateur sur la PR 2, `CiReleveWiringTest::testSelfTestIsTheLastStepOfLint`).
     */
    public function testTheMeasureSelfTestsRunInLintBeforeTheVerdictSelfTest(): void
    {
        $steps = $this->steps($this->job(self::CI, 'lint'), 'lint');
        $names = array_map(fn (array $s): string => $this->str($s['name'] ?? null), $steps);

        $positions = [];
        foreach (['suite-time', 'ci-mesure', 'tests-retires'] as $script) {
            $position = array_search($script . ' self-test', $names, true);
            self::assertIsInt($position, \sprintf('`lint` porte le step « %s self-test ».', $script));
            self::assertSame(\sprintf('python3 -I scripts/%s-test.py', $script), trim($this->str($steps[$position]['run'] ?? null)), \sprintf('« %s self-test » lance `python3 -I scripts/%s-test.py`.', $script, $script));
            $positions[] = $position;
        }
        $sorted = $positions;
        sort($sorted);
        self::assertSame($sorted, $positions, '`lint` : suite-time, puis ci-mesure, puis tests-retires.');

        $verdict = array_search('ci-verdict self-test', $names, true);
        self::assertIsInt($verdict);
        self::assertLessThan($verdict, max($positions), 'Les trois self-tests viennent avant « ci-verdict self-test ».');
        self::assertSame('ci-releve self-test', end($names), '« ci-releve self-test » reste le dernier step de `lint`.');
    }

    // --- évaluation des `if` de ci.yml --------------------------------------------------------

    /** @return array{event_name: string, base_ref: string, ref: string} */
    private function pullRequestTo(string $base): array
    {
        return ['event_name' => 'pull_request', 'base_ref' => $base, 'ref' => 'refs/pull/1/merge'];
    }

    /**
     * Les jobs de ci.yml qui S'EXÉCUTENT pour cet événement, triés — tous les jobs exécutés
     * supposés verts. Règle GitHub reproduite : un `if` sans fonction de statut porte un
     * `success()` implicite (un `needs` sauté saute le job) ; avec `!cancelled()`/`always()`, il
     * tourne malgré un `needs` sauté. Un terme que l'évaluateur ne connaît pas fait ÉCHOUER le
     * test (une condition nouvelle se range ici, jamais en silence).
     *
     * @param array{event_name: string, base_ref: string, ref: string} $event
     *
     * @return list<string>
     */
    private function jobsThatRun(array $event): array
    {
        $jobs = $this->jobs(self::CI);
        $memo = [];
        $runs = function (string $id, array $trail = []) use (&$runs, &$memo, $jobs, $event): bool {
            if (isset($memo[$id])) {
                return $memo[$id];
            }
            self::assertArrayHasKey($id, $jobs, \sprintf('needs vers un job inconnu : « %s ».', $id));
            self::assertNotContains($id, $trail, 'Cycle de needs : ' . implode(' → ', [...$trail, $id]));
            $job = $jobs[$id];
            $if = \array_key_exists('if', $job) ? $this->condition($job['if']) : '';
            $needsRan = true;
            foreach ($this->needs($job) as $need) {
                $needsRan = $runs($need, [...$trail, $id]) && $needsRan;
            }
            $statusAware = 1 === preg_match('/\b(cancelled|always|success|failure)\(\)/', $if);
            $result = ('' === $if || $this->evaluate($if, $event, $id)) && ($statusAware || $needsRan);

            return $memo[$id] = $result;
        };

        $running = [];
        foreach (array_keys($jobs) as $id) {
            if ($runs($id)) {
                $running[] = $id;
            }
        }
        sort($running);

        return $running;
    }

    /**
     * Évalue une condition squashée : `||`, `&&`, parenthèses, comparaisons `==`/`!=` sur
     * github.event_name / base_ref / ref, et les termes de statut de `build-docker` (vrais : le
     * run n'est pas annulé, aucun needs en échec).
     *
     * @param array{event_name: string, base_ref: string, ref: string} $event
     */
    private function evaluate(string $condition, array $event, string $id): bool
    {
        $pos = 0;
        $value = $this->parseOr($condition, $pos, $event, $id);
        self::assertSame(\strlen($condition), $pos, \sprintf('`%s` : condition non évaluable par le test à partir de « %s ».', $id, substr($condition, $pos)));

        return $value;
    }

    /** @param array{event_name: string, base_ref: string, ref: string} $event */
    private function parseOr(string $c, int &$pos, array $event, string $id): bool
    {
        $value = $this->parseAnd($c, $pos, $event, $id);
        while (str_starts_with(substr($c, $pos), '||')) {
            $pos += 2;
            $right = $this->parseAnd($c, $pos, $event, $id);
            $value = $value || $right;
        }

        return $value;
    }

    /** @param array{event_name: string, base_ref: string, ref: string} $event */
    private function parseAnd(string $c, int &$pos, array $event, string $id): bool
    {
        $value = $this->parseTerm($c, $pos, $event, $id);
        while (str_starts_with(substr($c, $pos), '&&')) {
            $pos += 2;
            $right = $this->parseTerm($c, $pos, $event, $id);
            $value = $value && $right;
        }

        return $value;
    }

    /** @param array{event_name: string, base_ref: string, ref: string} $event */
    private function parseTerm(string $c, int &$pos, array $event, string $id): bool
    {
        $rest = substr($c, $pos);
        if (str_starts_with($rest, '(')) {
            ++$pos;
            $value = $this->parseOr($c, $pos, $event, $id);
            self::assertSame(')', substr($c, $pos, 1), \sprintf('`%s` : parenthèse non fermée dans « %s ».', $id, $c));
            ++$pos;

            return $value;
        }
        foreach (['!cancelled()', 'always()', 'needs.preflight.result==\'success\'', '!contains(needs.*.result,\'failure\')', '!contains(needs.*.result,\'cancelled\')'] as $status) {
            if (str_starts_with($rest, $status)) {
                $pos += \strlen($status);

                return true;
            }
        }
        if (1 === preg_match('/^github\.(event_name|base_ref|ref)(==|!=)\'([^\']*)\'/', $rest, $m)) {
            $pos += \strlen($m[0]);
            $equal = $event[$m[1]] === $m[3];

            return '==' === $m[2] ? $equal : !$equal;
        }
        self::fail(\sprintf('`%s` : terme non évaluable par le test : « %s ».', $id, $rest));
    }

    // --- lecture des workflows ---------------------------------------------------------------

    /** @return array<mixed> */
    private function workflow(string $relative): array
    {
        $path = self::ROOT . '/' . $relative;
        self::assertFileExists($path, \sprintf('%s doit exister.', $relative));
        $parsed = Yaml::parseFile($path, Yaml::PARSE_CUSTOM_TAGS);
        self::assertIsArray($parsed, \sprintf('%s ne parse pas en mapping YAML.', $relative));

        return $parsed;
    }

    /** @return array<string, array<mixed>> */
    private function jobs(string $relative): array
    {
        $workflow = $this->workflow($relative);
        self::assertIsArray($workflow['jobs'] ?? null, \sprintf('%s : pas de `jobs:`.', $relative));

        $jobs = [];
        foreach ($workflow['jobs'] as $id => $job) {
            self::assertIsArray($job, \sprintf('%s / %s n\'est pas un mapping.', $relative, (string) $id));
            $jobs[(string) $id] = $job;
        }

        return $jobs;
    }

    /** @return array<mixed> */
    private function job(string $relative, string $id): array
    {
        $jobs = $this->jobs($relative);
        self::assertArrayHasKey($id, $jobs, \sprintf('%s : le job « %s » a disparu.', $relative, $id));

        return $jobs[$id];
    }

    /**
     * @param array<mixed> $job
     *
     * @return list<array<mixed>>
     */
    private function steps(array $job, string $id): array
    {
        self::assertIsArray($job['steps'] ?? null, \sprintf('`%s` : pas de `steps:`.', $id));
        $steps = [];
        foreach ($job['steps'] as $step) {
            self::assertIsArray($step, \sprintf('`%s` : un step n\'est pas un mapping.', $id));
            $steps[] = $step;
        }
        self::assertNotEmpty($steps, \sprintf('`%s` : aucun step.', $id));

        return $steps;
    }

    /**
     * Le step de ce nom, dans ce job — absent, le test rougit en le nommant.
     *
     * @param array<mixed> $job
     *
     * @return array<mixed>
     */
    private function namedStep(array $job, string $id, string $name): array
    {
        foreach ($this->steps($job, $id) as $step) {
            if ($name === ($step['name'] ?? null)) {
                return $step;
            }
        }
        self::fail(\sprintf('`%s` doit porter le step « %s ».', $id, $name));
    }

    /**
     * Le seul step de ce job dont le nom commence par ce préfixe — absent ou en double, le test
     * rougit en le nommant (le nom complet d'un step de suite reste libre, comme dans la table du
     * relevé).
     *
     * @param array<mixed> $job
     *
     * @return array<mixed>
     */
    private function stepStartingWith(array $job, string $id, string $prefix): array
    {
        $matching = array_values(array_filter($this->steps($job, $id), fn (array $step): bool => str_starts_with($this->str($step['name'] ?? null), $prefix)));
        self::assertCount(1, $matching, \sprintf('`%s` doit porter UN step dont le nom commence par « %s ».', $id, $prefix));

        return $matching[0];
    }

    /**
     * Le nom d'un step ; `(checkout)` et `(setup-node)` pour ces deux actions, quel que soit leur nom.
     *
     * @param array<mixed> $step
     */
    private function stepLabel(array $step): string
    {
        $uses = $this->str($step['uses'] ?? null);

        return match (true) {
            str_starts_with($uses, 'actions/checkout@') => '(checkout)',
            str_starts_with($uses, 'actions/setup-node@') => '(setup-node)',
            default => $this->str($step['name'] ?? $uses),
        };
    }

    /** @return array<mixed> */
    private function sorted(mixed $map): array
    {
        if (!\is_array($map)) {
            return [];
        }
        ksort($map);

        return $map;
    }

    /**
     * @param array<mixed> $job
     *
     * @return list<string>
     */
    private function needs(array $job): array
    {
        $needs = $job['needs'] ?? [];
        if (\is_string($needs)) {
            return [$needs];
        }

        return \is_array($needs) ? array_values(array_map(fn (mixed $n): string => $this->str($n), $needs)) : [];
    }

    /**
     * Un job « compose » crée des ressources sur le démon (build, up, run, exec, pull) — un
     * simple `docker compose config` n'en crée aucune.
     *
     * @param list<array<mixed>> $steps
     */
    private function isComposeJob(array $steps): bool
    {
        foreach ($steps as $step) {
            $uses = $this->str($step['uses'] ?? null);
            if (str_contains($uses, '/build-php-cached') || str_contains($uses, '/composer-install-cached')) {
                return true;
            }
            foreach ($this->logicalLines($this->str($step['run'] ?? null)) as $line) {
                if (preg_match('/\bdocker compose\b.*\b(up|run|build|exec|pull)\b/', $line)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param array<mixed> $step */
    private function isDockerLogin(array $step): bool
    {
        return str_contains($this->str($step['uses'] ?? null), 'docker/login-action')
            || str_contains($this->str($step['run'] ?? null), 'docker login');
    }

    /**
     * Les lignes de commande d'un `run`, continuations `\` jointes, commentaires shell retirés.
     *
     * @return list<string>
     */
    private function logicalLines(string $run): array
    {
        $joined = (string) preg_replace('/\\\\\r?\n\s*/', ' ', $run);
        $lines = [];
        foreach (explode("\n", $joined) as $line) {
            if ('' === trim($line) || preg_match('/^\s*#/', $line)) {
                continue;
            }
            $lines[] = $line;
        }

        return $lines;
    }

    /** Une expression `if:` sans espaces ni enveloppe `${{ }}`. */
    private function condition(mixed $value): string
    {
        $squashed = $this->squash($value);
        if (str_starts_with($squashed, '${{') && str_ends_with($squashed, '}}')) {
            return substr($squashed, 3, -2);
        }

        return $squashed;
    }

    private function squash(mixed $value): string
    {
        return (string) preg_replace('/\s+/', '', $this->str($value));
    }

    private function str(mixed $value): string
    {
        return \is_string($value) ? $value : (\is_scalar($value) ? (string) $value : '');
    }
}
