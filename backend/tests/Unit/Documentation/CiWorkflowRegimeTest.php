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
 * Listes FERMÉES à dessein (jobs, classes de runner, 5 régimes ALWAYS / PR_ANY / PR_MAIN /
 * MAIN_TARGET / MAIN_PUSH) : un job ajouté demain rougit ici tant que son auteur ne l'a pas
 * rangé — c'est le geste voulu. Branche de lot (D1…D15) : une PR vers `lot/<sujet>` joue le
 * régime léger, une PR vers `main` (dont la PR finale du lot) le gate complet, le push `main`
 * ses 8 jobs (`isCiGreen`) ; les `if` réels sont ÉVALUÉS par événement (`jobsThatRun`).
 *
 * Ce que ce test NE prouve PAS (preuve ailleurs, ou aucune) :
 *  - la sémantique GitHub « un job sauté compte comme réussi pour un required check » — le test
 *    la suppose, il ne l'exécute pas ;
 *  - B1 (changement de base lot→main relance 16 jobs) et M1 (deux PR d'une même tête, rerun
 *    d'un run léger) : prouvés sur la branche d'essai `lot/essai` (gestes 4 et 5) ;
 *  - les rulesets `lot-gate` / `lot-no-delete` (hors dépôt), ni la concordance de
 *    LOT_REQUIRED_CHECKS avec le ruleset réellement posé ;
 *  - la concordance des 15 MAIN_REQUIRED_CHECKS avec la protection réelle de `main` : la
 *    constante est une COPIE, relue par `gh api …/branches/main/protection/required_status_checks` ;
 *  - qu'un check requis de `main` soit satisfait par un run LÉGER (même tête partagée, run
 *    annulé) : aucun test ne le voit — la garde est la lecture obligatoire de `gh pr checks <n>`
 *    (une seule ligne « skipping » admise, « Engine Perf Gate (main only) ») avant tout GO ou
 *    merge vers `main`.
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

    private const string PROJECT = 'ci-${{ github.run_id }}-${{ github.run_attempt }}-${{ github.job }}';

    private const string COMPOSE_FILE = '${{ github.workspace }}/docker-compose.yml:${{ github.workspace }}/docker-compose.test.yml';

    private const string ISOLATE_STEP = 'Isolate the Docker credentials of this job';

    private const string STACK_DOWN = './.github/actions/ci-stack-down';

    /** Les 16 jobs historiques + `preflight`. */
    private const array JOBS = [
        'preflight', 'lint', 'frontend', 'secrets-scan', 'semgrep', 'dependency-audit', 'phpstan', 'rector',
        'blocking-tests', 'backend-tests', 'engine-tests', 'engine-perf', 'engine-perf-pr', 'e2e',
        'engine-semantics', 'functional-tests', 'build-docker',
    ];

    /** Classe `amateo-heavy` (≈ 3-5 Go chacun) : au plus deux en même temps dans la VM. */
    private const array HEAVY_JOBS = ['e2e', 'functional-tests', 'backend-tests', 'build-docker', 'frontend'];

    /**
     * Jobs de ci.yml qui gardent un `runs-on` littéral GitHub-hosted. Vide : tout tourne sur le
     * runner. `engine-perf` n'y entre que si la mesure de marge (BCCL nominal > 10 s) l'impose,
     * sur décision du fondateur.
     */
    private const array LITERAL_JOBS = [];

    /** Workflows qui restent chez GitHub (déploiement, balayage hebdo, miroir manuel). */
    private const array HOSTED_WORKFLOWS = [
        '.github/workflows/deploy.yml',
        '.github/workflows/security-weekly.yml',
        '.github/workflows/mirror-images.yml',
    ];

    /**
     * Les 5 régimes : chaque job de ci.yml est dans UN seul (D2), et chaque régime a son `if`
     * EXACT (D3). Un job sauté compte comme RÉUSSI pour un required check : une coquille d'`if`
     * viderait le gate de `main` en silence — d'où listes fermées et conditions exactes.
     *
     * ALWAYS : aucun `if` — PR→main, PR→lot/**, push main.
     */
    private const array ALWAYS = ['preflight', 'lint', 'secrets-scan', 'semgrep', 'engine-tests'];

    /** PR_ANY : toute PR (vers `main` ou `lot/**`), jamais sur push main. */
    private const array PR_ANY = ['phpstan', 'rector', 'blocking-tests', 'frontend', 'engine-semantics', 'engine-perf-pr'];

    /** PR_MAIN : PR vers `main` seulement — les lourds du gate, rejoués à la PR finale d'un lot. */
    private const array PR_MAIN = ['backend-tests', 'e2e', 'functional-tests'];

    /** MAIN_TARGET : PR vers `main` ET push main (le run de `main` fait `isCiGreen`), jamais PR→lot. */
    private const array MAIN_TARGET = ['dependency-audit', 'build-docker'];

    /** MAIN_PUSH : push main seulement (budget de perf BCCL). */
    private const array MAIN_PUSH = ['engine-perf'];

    private const string IF_PR_ANY = 'github.event_name==\'pull_request\'';

    private const string IF_PR_MAIN = 'github.event_name==\'pull_request\'&&github.base_ref==\'main\'';

    private const string IF_MAIN_TARGET = 'github.event_name!=\'pull_request\'||github.base_ref==\'main\'';

    private const string IF_MAIN_PUSH = 'github.ref==\'refs/heads/main\'';

    /** `if` de build-docker, chaîne ENTIÈRE squashée (sans espaces ni `${{ }}`), parenthèses comprises. */
    private const string IF_BUILD_DOCKER = '!cancelled()&&needs.preflight.result==\'success\'&&!contains(needs.*.result,\'failure\')'
        . '&&!contains(needs.*.result,\'cancelled\')&&(github.event_name!=\'pull_request\'||github.base_ref==\'main\')';

    private const array BUILD_DOCKER_NEEDS = ['preflight', 'blocking-tests', 'engine-tests'];

    /**
     * COPIE des 15 contextes requis de `main` (tous `app_id 15368`), relue par
     * `gh api repos/{owner}/{repo}/branches/main/protection/required_status_checks` le 2026-11-10.
     * Aucun test ne relit la protection réelle : une divergence se voit à cette relecture-là.
     * Ni « Engine Perf Gate (main only) » ni « Engine Perf (PR, dense only) » n'y figurent.
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

    /**
     * Les 10 checks à poser dans le ruleset `lot-gate` (hors dépôt) : les 15 de `main` moins les 5
     * lourds. « Engine Perf (PR, dense only) » tourne sur PR→lot sans être requis (miroir de main).
     */
    private const array LOT_REQUIRED_CHECKS = [
        'Preflight', 'Lint', 'Secrets Scan (gitleaks)', 'Semgrep (security gate)', 'Engine Tests',
        'PHPStan & CS-Fixer', 'Rector (style gate)', 'Blocking Tests', 'Frontend (build + unit)', 'Engine semantics',
    ];

    /** Les 6 jobs SAUTÉS sur une PR→lot : les 5 lourds du gate de `main` + le budget de perf de main. */
    private const array SKIPPED_ON_LOT = ['backend-tests', 'e2e', 'functional-tests', 'dependency-audit', 'build-docker', 'engine-perf'];

    /** Run de push main (`isCiGreen` lit ce run) : inchangé par la branche de lot. */
    private const array MAIN_PUSH_RUN = [
        'preflight', 'lint', 'secrets-scan', 'semgrep', 'dependency-audit', 'engine-tests', 'engine-perf', 'build-docker',
    ];

    private const string CONCURRENCY_GROUP = 'ci-${{ github.ref }}';

    private const string CONCURRENCY_CANCEL = '${{ github.ref != \'refs/heads/main\' }}';

    private const string FILTERED_NR = 'testAMutualisationCorrectionWithATeamFromAnotherClubIsRefusedWritingNothing';

    /** Ids de steps lus par « Classify failure » du job e2e. */
    private const array E2E_STEP_IDS = ['prepull', 'buildengine', 'startservices', 'buildfrontend', 'playwright', 'a17'];

    /**
     * R1 — Promesse : chaque job de ci.yml part sur le runner par une VARIABLE de dépôt (retour
     * arrière = supprimer la variable, sans commit), dans sa classe de poids ; ce qui doit rester
     * chez GitHub garde le littéral.
     */
    public function testEveryCiJobIsRoutedByARepositoryVariableInItsWeightClass(): void
    {
        $jobs = $this->jobs(self::CI);
        $ids = array_keys($jobs);
        sort($ids);
        $expected = self::JOBS;
        sort($expected);
        self::assertSame($expected, $ids, "ci.yml doit porter EXACTEMENT ces jobs (16 historiques + `preflight`).\n"
            . 'Un job ajouté ou retiré se range ici, dans sa classe de runner et son régime (ALWAYS/PR_ANY/PR_MAIN/MAIN_TARGET/MAIN_PUSH).');

        $wrong = [];
        foreach ($jobs as $id => $job) {
            $want = match (true) {
                \in_array($id, self::LITERAL_JOBS, true) => 'ubuntu-24.04',
                \in_array($id, self::HEAVY_JOBS, true) => self::RUNS_ON_HEAVY,
                default => self::RUNS_ON,
            };
            $got = $this->squash($job['runs-on'] ?? null);
            if ($got !== $want) {
                $wrong[] = \sprintf('%s : runs-on « %s », attendu « %s »', $id, $got, $want);
            }
        }
        self::assertSame([], $wrong, "runs-on hors régime dans ci.yml :\n  - " . implode("\n  - ", $wrong)
            . "\nLourds (e2e, functional-tests, backend-tests, build-docker, frontend) → CI_RUNS_ON_HEAVY ; les autres → CI_RUNS_ON ;"
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
     * checkout) rougit sur un `pull_request` venu d'un autre dépôt, et tout autre job l'attend.
     */
    public function testAForkPullRequestIsRefusedBeforeAnyJobTouchesTheRunner(): void
    {
        $jobs = $this->jobs(self::CI);
        self::assertArrayHasKey('preflight', $jobs, 'ci.yml doit porter le job `preflight` (garde fork, required check « Preflight »).');
        $preflight = $jobs['preflight'];

        self::assertSame('Preflight', $preflight['name'] ?? null, 'Le job `preflight` s\'appelle « Preflight » : c\'est le nom du required check.');
        self::assertArrayNotHasKey('needs', $preflight, '`preflight` est la racine du graphe : il n\'attend rien.');
        self::assertArrayNotHasKey('if', $preflight, '`preflight` tourne toujours (sur `main`, sa garde se saute) : un job sauté vaudrait succès pour les jobs suivants.');

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
            if ('preflight' === $id) {
                continue;
            }
            if (!\in_array('preflight', $this->needs($job), true)) {
                $orphans[] = $id;
            }
        }
        self::assertSame([], $orphans, "Ces jobs n'attendent pas `preflight` (needs) — ils démarreraient sur le runner avant la garde fork :\n  - "
            . implode("\n  - ", $orphans));
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
     * D2 — Promesse : chaque job de ci.yml est rangé dans UN seul des 5 régimes (ALWAYS, PR_ANY,
     * PR_MAIN, MAIN_TARGET, MAIN_PUSH) — un job ajouté demain rougit ici tant qu'il n'est pas rangé.
     */
    public function testEveryJobBelongsToExactlyOneRegime(): void
    {
        $ids = array_keys($this->jobs(self::CI));
        sort($ids);

        $classified = array_merge(self::ALWAYS, self::PR_ANY, self::PR_MAIN, self::MAIN_TARGET, self::MAIN_PUSH);
        self::assertSame(array_values(array_unique($classified)), $classified, 'Un job figure dans DEUX régimes.');
        sort($classified);
        self::assertSame($ids, $classified, "Les 5 régimes doivent couvrir EXACTEMENT les jobs de ci.yml.\n"
            . 'Job manquant = non rangé (son `if` n\'est gardé par rien) ; job en trop = disparu de ci.yml.');
    }

    /**
     * D3 — Promesse : chaque job porte l'`if` EXACT de son régime, rien de plus — aucune condition
     * cachée (chemins, doc seule, action de PR…) ne peut sauter un job du gate de `main`, et
     * `build-docker` garde sa chaîne entière et ses `needs`.
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

        foreach (self::ALWAYS as $id) {
            $expect($id, null, $jobs[$id] ?? []);
        }
        foreach (self::PR_ANY as $id) {
            $expect($id, self::IF_PR_ANY, $jobs[$id] ?? []);
        }
        foreach (self::PR_MAIN as $id) {
            $expect($id, self::IF_PR_MAIN, $jobs[$id] ?? []);
        }
        $expect('dependency-audit', self::IF_MAIN_TARGET, $jobs['dependency-audit'] ?? []);
        $expect('build-docker', self::IF_BUILD_DOCKER, $jobs['build-docker'] ?? []);
        foreach (self::MAIN_PUSH as $id) {
            $expect($id, self::IF_MAIN_PUSH, $jobs[$id] ?? []);
        }

        self::assertSame([], $wrong, "`if` hors régime dans ci.yml (comparaison sans espaces ni `\${{ }}`) :\n  - " . implode("\n  - ", $wrong)
            . "\nUn job sauté vaut SUCCÈS pour un required check : l'`if` d'un régime est exact, jamais « contient ».");

        self::assertSame(self::BUILD_DOCKER_NEEDS, $this->needs($jobs['build-docker'] ?? []), '`build-docker` : needs EXACTS [preflight, blocking-tests, engine-tests].');
    }

    /**
     * D4 — Promesse : une PR vers `main` (dont la PR finale d'un lot) joue TOUT le gate — les 16
     * jobs, seul `engine-perf` (push main) est sauté. Évaluation des `if` réels de ci.yml.
     */
    public function testAPullRequestToMainRunsTheWholeGate(): void
    {
        $expected = array_values(array_diff(self::JOBS, self::MAIN_PUSH));
        sort($expected);
        self::assertCount(16, $expected);
        self::assertSame($expected, $this->jobsThatRun($this->pullRequestTo('main')), 'PR→main doit exécuter les 16 jobs (tout sauf engine-perf).');
    }

    /**
     * D5 — Promesse : chacun des 15 required checks de `main` est le `name:` d'un job qui TOURNE
     * sur une PR→main — aucun n'y est satisfait par un job sauté (sauté = succès pour GitHub).
     */
    public function testEveryRequiredCheckOfMainIsProducedOnAPullRequestToMain(): void
    {
        self::assertCount(15, self::MAIN_REQUIRED_CHECKS);
        self::assertSame(self::MAIN_REQUIRED_CHECKS, array_values(array_unique(self::MAIN_REQUIRED_CHECKS)));
        self::assertNotContains('Engine Perf Gate (main only)', self::MAIN_REQUIRED_CHECKS);
        self::assertNotContains('Engine Perf (PR, dense only)', self::MAIN_REQUIRED_CHECKS);
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
     * D6 — Promesse : une PR vers `lot/<sujet>` joue le régime LÉGER — ALWAYS ∪ PR_ANY (11 jobs),
     * dont les 10 checks du ruleset `lot-gate` ; les 6 autres (5 lourds + engine-perf) sont sautés.
     */
    public function testAPullRequestToALotBranchRunsOnlyTheLightRegime(): void
    {
        $light = array_merge(self::ALWAYS, self::PR_ANY);
        sort($light);
        self::assertCount(11, $light);
        $running = $this->jobsThatRun($this->pullRequestTo('lot/essai'));
        self::assertSame($light, $running, 'PR→lot/** doit exécuter EXACTEMENT ALWAYS ∪ PR_ANY (11 jobs).');

        $skipped = array_values(array_diff(self::JOBS, $running));
        sort($skipped);
        $expectedSkipped = self::SKIPPED_ON_LOT;
        sort($expectedSkipped);
        self::assertSame($expectedSkipped, $skipped, 'PR→lot/** saute EXACTEMENT les 6 : backend-tests, e2e, functional-tests, dependency-audit, build-docker, engine-perf.');

        self::assertCount(10, self::LOT_REQUIRED_CHECKS);
        foreach (self::LOT_REQUIRED_CHECKS as $check) {
            self::assertContains($check, self::MAIN_REQUIRED_CHECKS, \sprintf('« %s » (lot-gate) est un check de main.', $check));
            self::assertContains(self::CHECK_NAME_TO_JOB[$check], $running, \sprintf('« %s » (requis par lot-gate) doit TOURNER sur PR→lot.', $check));
        }
    }

    /**
     * D7 — Promesse : le run de push `main` (celui que lit `isCiGreen` avant un déploiement) garde
     * exactement ses 8 jobs : preflight, lint, secrets-scan, semgrep, dependency-audit,
     * engine-tests, engine-perf, build-docker.
     */
    public function testMainPushKeepsWhatMakesItGreen(): void
    {
        $expected = self::MAIN_PUSH_RUN;
        sort($expected);
        self::assertSame($expected, $this->jobsThatRun(['event_name' => 'push', 'base_ref' => '', 'ref' => 'refs/heads/main']), 'push main doit exécuter EXACTEMENT les 8 jobs de isCiGreen.');
    }

    /**
     * D8 — Promesse : aucun job n'est sauté selon le CONTENU d'une PR — ni `paths`/`paths-ignore`
     * sur les déclencheurs, ni filtre « doc seule » (décision 2026-11-06, maintenue).
     */
    public function testNoTriggerOrJobFiltersOnTheChangedPaths(): void
    {
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
     * au job, et la stack toujours démontée à la fin.
     */
    public function testEveryJobIsSealedOnTheSharedDockerDaemon(): void
    {
        $sealed = $this->jobs(self::CI);
        unset($sealed['preflight']);
        $sealed['visual-baselines.yml/visual-baselines'] = $this->job(self::VISUAL, 'visual-baselines');

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
            self::assertLessThanOrEqual(1, $isolate, \sprintf('`%s` : « %s » vient en premier ou juste après le checkout.', $id, self::ISOLATE_STEP));
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
