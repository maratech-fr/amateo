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
 * Listes FERMÉES à dessein (jobs, classes de runner, PR seulement / main seulement) : un job
 * ajouté demain rougit ici tant que son auteur ne l'a pas rangé — c'est le geste voulu.
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

    /** Sautés sur `push main` : la PR les a prouvés sur l'arbre mergé (`strict` + squash). */
    private const array PR_ONLY = [
        'phpstan', 'rector', 'blocking-tests', 'backend-tests', 'frontend', 'e2e', 'engine-semantics',
        'functional-tests', 'engine-perf-pr',
    ];

    private const array MAIN_ONLY = ['engine-perf'];

    /** Jouent sur la PR ET sur `main` (le run de `main` fait `isCiGreen`). */
    private const array PR_AND_MAIN = [
        'preflight', 'lint', 'secrets-scan', 'semgrep', 'dependency-audit', 'engine-tests', 'build-docker',
    ];

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
            . 'Un job ajouté ou retiré se range ici, dans sa classe de runner et sa liste PR/main.');

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
     * R3 — Promesse : chaque PR joue TOUT le gate (bloquants, suite complète, sémantique moteur,
     * Behat…) sans condition cachée, et le run de `main` garde ce qui fait `isCiGreen` (images
     * prod, perf, lint/secrets/semgrep/audit).
     */
    public function testEveryPullRequestRunsTheWholeGateAndMainKeepsWhatMakesItGreen(): void
    {
        $jobs = $this->jobs(self::CI);
        $classified = array_merge(self::PR_ONLY, self::MAIN_ONLY, self::PR_AND_MAIN);
        sort($classified);
        $all = self::JOBS;
        sort($all);
        self::assertSame($all, $classified, 'Chaque job est rangé dans UNE liste (PR seulement / main seulement / les deux).');

        foreach (self::PR_ONLY as $id) {
            self::assertSame('github.event_name==\'pull_request\'', $this->condition($jobs[$id]['if'] ?? null), \sprintf(
                "`%s` : `if:` doit être EXACTEMENT github.event_name == 'pull_request' — aucune autre condition (filtre de chemins,\n"
                . 'doc seule…) ne doit pouvoir le sauter sur une PR, sinon le gate se vide en silence.',
                $id,
            ));
        }

        self::assertStringContainsString('github.ref==\'refs/heads/main\'', $this->condition($jobs['engine-perf']['if'] ?? null), '`engine-perf` (budget BCCL) reste main seulement.');

        foreach (self::PR_AND_MAIN as $id) {
            if ('build-docker' === $id) {
                continue;
            }
            self::assertArrayNotHasKey('if', $jobs[$id], \sprintf('`%s` joue sur la PR ET sur `main` : aucune condition de job.', $id));
        }

        $build = $this->condition($jobs['build-docker']['if'] ?? null);
        foreach (['!cancelled()', 'needs.preflight.result==\'success\'', '!contains(needs.*.result,\'failure\')', '!contains(needs.*.result,\'cancelled\')'] as $term) {
            self::assertStringContainsString($term, $build, \sprintf(
                "`build-docker` doit tourner sur `main` alors que ses `needs` PR-only y sont sautés : son `if:` porte « %s ».\n"
                . 'Les 4 termes : !cancelled(), preflight success, aucun needs en failure, aucun en cancelled.',
                $term,
            ));
        }

        $raw = file_get_contents(self::ROOT . '/' . self::CI);
        self::assertIsString($raw);
        self::assertSame(0, preg_match('/docs[_-]only/i', $raw), 'Aucun filtre « doc seule » : le levier n\'est pas retenu, chaque PR joue tout.');
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
