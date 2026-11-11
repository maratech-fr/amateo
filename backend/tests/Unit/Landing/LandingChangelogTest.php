<?php

declare(strict_types=1);

namespace App\Tests\Unit\Landing;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * P4-522 (D8, D11) — promesse : le journal des évolutions de la vitrine dit, version par version, ce
 * qui a changé, et AUCUNE version ne se construit sans son entrée : le step « Check the changelog
 * names this version » de `deploy.yml` (job `build-push`) refuse un tag `v*` neuf absent de
 * `landing/evolutions.html`, et le dernier tag fusionné dans HEAD y a toujours son entrée.
 *
 * Contrat d'une entrée : `<article class="release" id="v1-3-5" data-version="1.3.5"><h2><time
 * datetime="2026-11-10">…</time> — v1.3.5 · …</h2><p>…</p></article>`, ordre décroissant,
 * `data-version` sans « v ». Limites dites : un tag futur n'est pas connu d'une PR (d'où la PR
 * `chore/release-vX.Y.Z` qui écrit l'entrée avant le tag, `docs/ops/deploy.md`) ; un hotfix
 * `sha-…` n'est pas gardé ; `grep -F` accepte une entrée laissée en commentaire.
 */
#[Group('phase1')]
final class LandingChangelogTest extends TestCase
{
    use LandingFiles;

    private const string WORKFLOW = '/.github/workflows/deploy.yml';

    private const string GUARD_STEP = 'Check the changelog names this version';

    /** Les entrées validées par le fondateur le 2026-11-11 (version → date de mise en ligne). */
    private const array VALIDATED_RELEASES = [
        '1.3.5' => '2026-11-10',
        '1.3.4' => '2026-11-04',
        '1.3.3' => '2026-11-02',
        '1.3.2' => '2026-11-01',
        '1.3.1' => '2026-10-31',
        '1.3.0' => '2026-10-28',
        '1.2.0' => '2026-10-16',
        '1.1.0' => '2026-10-11',
        '1.0.0' => '2026-10-02',
    ];

    /**
     * Promesse : chaque entrée du journal suit le contrat lu par la garde de déploiement (version
     * sans « v », `id` dérivé, date ISO, texte non vide), les versions vont de la plus récente à la
     * plus ancienne, et les neuf entrées validées y sont, à leur date.
     */
    public function testEveryReleaseEntryIsWellFormedAndDescending(): void
    {
        $document = $this->parse('evolutions.html');
        self::assertSame('Journal des évolutions', $this->text($document, 'h1'));

        $entries = [];
        $previous = null;
        foreach ($document->querySelectorAll('article.release') as $article) {
            $version = (string) $article->getAttribute('data-version');
            self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.]+)?$/', $version, 'data-version : X.Y.Z sans « v ».');
            self::assertArrayNotHasKey($version, $entries, "Version {$version} en double.");
            self::assertSame('v' . str_replace('.', '-', $version), $article->getAttribute('id'), "id de l'entrée {$version}");

            $date = $this->attribute($article, 'h2 time', 'datetime');
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            self::assertNotFalse($parsed, "{$version} : datetime « {$date} » n'est pas une date ISO.");
            self::assertSame($date, $parsed->format('Y-m-d'), "{$version} : datetime « {$date} » n'est pas une date réelle.");
            self::assertNotSame('', $this->text($article, 'h2 time'), "{$version} : date affichée vide.");
            self::assertStringContainsString('v' . $version, $this->text($article, 'h2'), "{$version} : le titre nomme la version.");
            self::assertNotSame('', $this->text($article, 'p'), "{$version} : entrée sans texte.");

            if (null !== $previous) {
                self::assertTrue(version_compare($previous['version'], $version, '>'), "Ordre décroissant rompu : {$previous['version']} puis {$version}.");
                self::assertGreaterThanOrEqual($date, $previous['date'], "Dates : {$previous['version']} ({$previous['date']}) avant {$version} ({$date}).");
            }
            $previous = ['version' => $version, 'date' => $date];
            $entries[$version] = $date;
        }

        foreach (self::VALIDATED_RELEASES as $version => $date) {
            self::assertSame($date, $entries[$version] ?? null, "Entrée validée v{$version} absente ou mal datée.");
        }
    }

    /** Promesse (F17) : le journal s'engage, sous son titre, à être tenu à chaque mise en ligne. */
    public function testJournalSubtitleIsTheCommitment(): void
    {
        self::assertStringContainsString('Ce journal est tenu à jour à chaque mise en ligne.', $this->visibleText('evolutions.html'));
    }

    /**
     * Promesse : la dernière version mise en ligne a son entrée — le tag `v*` le plus récent fusionné
     * dans HEAD figure dans le journal. Sauté (nommé) quand le dépôt git n'est pas lisible
     * (conteneur d'un worktree, clone superficiel) : la preuve est alors le job CI `backend-tests`
     * (`fetch-depth: 0`).
     */
    public function testTheNewestTagMergedInHeadHasAReleaseEntry(): void
    {
        [$exitCode, $output] = $this->runCommand(['git', '-C', self::repositoryRoot(), 'tag', '--merged', 'HEAD', '--list', 'v*', '--sort=-v:refname'], self::repositoryRoot(), []);
        if (0 !== $exitCode) {
            self::markTestSkipped('Dépôt git illisible ici (worktree en conteneur ?) : preuve = job CI backend-tests (fetch-depth: 0). ' . trim($output));
        }
        $tags = array_values(array_filter(array_map('trim', explode("\n", $output))));
        if ([] === $tags) {
            self::markTestSkipped('Aucun tag v* fusionné dans HEAD (clone superficiel ?) : preuve = job CI backend-tests (fetch-depth: 0).');
        }

        $version = substr($tags[0], 1);
        self::assertNotNull(
            $this->parse('evolutions.html')->querySelector('article.release[data-version="' . $version . '"]'),
            "Le dernier tag fusionné ({$tags[0]}) n'a pas d'entrée dans landing/evolutions.html.",
        );
    }

    /**
     * Promesse (D11) : un tag neuf absent du journal ne se construit pas. Le step « Check the changelog
     * names this version » vit dans `build-push`, après « Check registry for existing images » et avant
     * le premier « Build & push », sous `if` (images manquantes ET version `v*`), version passée par
     * `env` (jamais interpolée dans le shell) ; son `run`, JOUÉ ici dans un dossier jetable, saute
     * quand le journal n'existe pas au commit construit (rollback antérieur à la vitrine), passe quand
     * l'entrée existe, échoue quand elle manque.
     */
    public function testDeployWorkflowRefusesToBuildAVersionAbsentFromTheJournal(): void
    {
        $workflow = Yaml::parse($this->read(self::WORKFLOW));
        self::assertIsArray($workflow, 'deploy.yml ne parse pas en mapping YAML.');
        $steps = $workflow['jobs']['build-push']['steps'] ?? null;
        self::assertIsArray($steps, 'deploy.yml doit déclarer les `steps` du job `build-push`.');

        $names = [];
        foreach ($steps as $index => $step) {
            $names[$index] = \is_array($step) && \is_string($step['name'] ?? null) ? $step['name'] : '';
        }
        $guardAt = array_search(self::GUARD_STEP, $names, true);
        self::assertIsInt($guardAt, '`build-push` n\'a pas de step « ' . self::GUARD_STEP . ' ».');
        $registryAt = array_search('Check registry for existing images', $names, true);
        self::assertIsInt($registryAt, '`build-push` n\'a plus de step « Check registry for existing images ».');
        $firstBuildAt = null;
        foreach ($names as $index => $name) {
            if (str_starts_with($name, 'Build & push')) {
                $firstBuildAt = $index;
                break;
            }
        }
        self::assertIsInt($firstBuildAt, '`build-push` n\'a plus de step « Build & push ».');
        self::assertTrue($registryAt < $guardAt && $guardAt < $firstBuildAt, 'Ordre attendu : Check registry < ' . self::GUARD_STEP . ' < premier Build & push.');

        $guard = $steps[$guardAt];
        self::assertIsArray($guard);
        $if = (string) preg_replace('/\s+/', '', (string) ($guard['if'] ?? ''));
        self::assertStringContainsString('steps.existing.outputs.found!=\'true\'', $if, 'Le step ne joue que si une image manque (sinon rien ne se construit).');
        self::assertStringContainsString('startsWith(steps.meta.outputs.version,\'v\')', $if, 'Le step ne garde que les versions v* (un sha-… n\'est pas gardé).');
        self::assertSame('${{steps.meta.outputs.version}}', preg_replace('/\s+/', '', (string) ($guard['env']['VERSION'] ?? '')), 'La version arrive par env VERSION.');

        $run = (string) ($guard['run'] ?? '');
        foreach (['-f landing/evolutions.html', 'landing/evolutions.html', 'data-version', 'exit 1'] as $needle) {
            self::assertStringContainsString($needle, $run, "run du step : « {$needle} » absent.");
        }
        self::assertStringNotContainsString('${{', $run, 'Aucune expression ${{ }} interpolée dans le shell (injection) : passer par env.');

        $this->assertGuardRun($run, 'v1.0.0', null, 0, 'journal absent au commit construit → contrôle sauté');
        $this->assertGuardRun($run, 'v1.4.0', '<article class="release" id="v1-4-0" data-version="1.4.0"></article>', 0, 'entrée présente → construction autorisée');
        $this->assertGuardRun($run, 'v1.4.0', '<article class="release" id="v1-3-5" data-version="1.3.5"></article>', 1, 'entrée absente → construction refusée');
        $this->assertGuardRun($run, 'v1.4.0', '<article class="release" id="v1-4-01" data-version="1.4.01"></article>', 1, 'une version voisine ne vaut pas entrée');
    }

    /**
     * Joue le `run` du step comme GitHub (`bash -e -o pipefail`, `sh -e` à défaut) dans un dossier
     * jetable portant, ou non, `landing/evolutions.html`.
     *
     * @param 0|1 $expected 0 = succès attendu, 1 = échec attendu (code non nul)
     */
    private function assertGuardRun(string $run, string $version, ?string $journal, int $expected, string $case): void
    {
        $directory = sys_get_temp_dir() . '/landing-changelog-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory . '/landing', 0o777, true));
        try {
            if (null !== $journal) {
                file_put_contents($directory . '/landing/evolutions.html', $journal);
            }
            $shell = is_executable('/bin/bash') ? ['/bin/bash', '-e', '-o', 'pipefail', '-c', $run] : ['/bin/sh', '-e', '-c', $run];
            [$exitCode, $output] = $this->runCommand($shell, $directory, ['VERSION' => $version]);
            if (0 === $expected) {
                self::assertSame(0, $exitCode, "{$case} : le step a échoué.\n{$output}");
            } else {
                self::assertNotSame(0, $exitCode, "{$case} : le step a laissé passer {$version}.\n{$output}");
            }
        } finally {
            @unlink($directory . '/landing/evolutions.html');
            @rmdir($directory . '/landing');
            @rmdir($directory);
        }
    }

    /**
     * @param list<string>          $command
     * @param array<string, string> $environment
     *
     * @return array{int, string} code retour, sortie standard puis sortie d'erreur (sorties courtes)
     */
    private function runCommand(array $command, string $directory, array $environment): array
    {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $directory, ['PATH' => (string) getenv('PATH')] + $environment);
        if (!\is_resource($process)) {
            return [127, 'proc_open a échoué.'];
        }
        $output = (string) stream_get_contents($pipes[1]);
        $errors = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $output . $errors];
    }
}
