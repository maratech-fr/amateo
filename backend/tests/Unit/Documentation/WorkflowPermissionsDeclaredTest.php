<?php

declare(strict_types=1);

namespace App\Tests\Unit\Documentation;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * P4-92 — chaque workflow GitHub Actions déclare un bloc `permissions:` explicite à sa
 * racine, et aucune permission `write` n'est accordée hors d'une liste fermée documentée ici.
 *
 * ⚠ Ce que ce test N'EST PAS : il ne referme aucune faille ouverte. Le réglage de dépôt
 * `default_workflow_permissions` vaut aujourd'hui `read`, donc le `GITHUB_TOKEN` par défaut
 * est déjà en lecture seule. Ce test est un CLIQUET : si quelqu'un clique ce réglage
 * d'organisation vers `write`, un workflow SANS bloc `permissions:` hériterait soudain d'un
 * jeton en écriture sans qu'aucune revue ne le voie. Déclarer le régime dans le fichier retire
 * ce levier silencieux du tableau de bord ; l'assertion sur les scopes `write` force toute
 * escalade future à venir s'inscrire ICI, sous les yeux d'un relecteur.
 *
 * La liste est fermée à dessein : `deploy.yml` et `mirror-images.yml` poussent des images vers
 * ghcr.io et ont donc légitimement besoin de `packages: write`. Tout autre `write` — nouveau
 * fichier, nouveau scope, ou l'un de ces deux fichiers réclamant un scope supplémentaire — doit
 * rougir et forcer son auteur à motiver l'ajout dans self::ALLOWED_WRITE.
 *
 * Le glob couvre TOUT `.github/workflows/*.yml` : un workflow ajouté demain est gardé sans
 * qu'on ait à toucher ce test.
 */
#[Group('phase1')]
final class WorkflowPermissionsDeclaredTest extends TestCase
{
    private const string WORKFLOWS_DIR = __DIR__ . '/../../../../.github/workflows';

    /**
     * Liste fermée des permissions `write` autorisées, par fichier de workflow.
     * Clé = nom du fichier ; valeur = scopes `write` admis (push d'images vers ghcr.io).
     *
     * @var array<string, list<string>>
     */
    private const array ALLOWED_WRITE = [
        'deploy.yml' => ['packages'],
        'mirror-images.yml' => ['packages'],
    ];

    public function testEveryWorkflowDeclaresRootPermissions(): void
    {
        foreach ($this->workflowFiles() as $file => $path) {
            $workflow = Yaml::parseFile($path);
            self::assertIsArray($workflow, \sprintf('%s ne parse pas en mapping YAML.', $file));

            self::assertArrayHasKey('permissions', $workflow, \sprintf(
                "%s ne déclare pas de bloc `permissions:` à sa racine.\n"
                . "Convention maison (cliquet, PAS un correctif de faille) : chaque workflow fige son régime de\n"
                . "permissions dans le fichier, pour que le jeton par défaut ne dépende jamais d'un réglage\n"
                . 'd\'organisation cliquable. Ajoutez au minimum `permissions:` avec `contents: read`.',
                $file,
            ));
        }
    }

    public function testNoWriteScopeOutsideTheClosedList(): void
    {
        $violations = [];

        foreach ($this->workflowFiles() as $file => $path) {
            $workflow = Yaml::parseFile($path);
            self::assertIsArray($workflow, \sprintf('%s ne parse pas en mapping YAML.', $file));

            $allowed = self::ALLOWED_WRITE[$file] ?? [];

            foreach ($this->writeScopesGrantedBy($workflow) as $scope) {
                if (!\in_array($scope, $allowed, true)) {
                    $violations[] = \sprintf('%s → write sur « %s »', $file, $scope);
                }
            }
        }

        self::assertSame([], $violations, \sprintf(
            "Permission(s) `write` accordée(s) hors de la liste fermée de ce test :\n  - %s\n"
            . "Ce cliquet exige qu'une escalade soit inscrite EXPLICITEMENT, sous revue. Si ce `write` est\n"
            . "légitime, ajoutez le scope à self::ALLOWED_WRITE pour le fichier concerné, avec la raison en\n"
            . 'commentaire. Sinon, retirez-le du workflow.',
            implode("\n  - ", $violations),
        ));
    }

    /**
     * Tous les scopes accordés en `write` par un workflow, racine ET jobs confondus.
     * Un bloc `permissions:` peut être un mapping (scope => accès) ou la chaîne `write-all`.
     *
     * @param array<mixed> $workflow
     *
     * @return list<string>
     */
    private function writeScopesGrantedBy(array $workflow): array
    {
        $blocks = [];
        if (\array_key_exists('permissions', $workflow)) {
            $blocks[] = $workflow['permissions'];
        }

        if (\is_array($workflow['jobs'] ?? null)) {
            foreach ($workflow['jobs'] as $job) {
                if (\is_array($job) && \array_key_exists('permissions', $job)) {
                    $blocks[] = $job['permissions'];
                }
            }
        }

        $scopes = [];
        foreach ($blocks as $block) {
            if (\is_string($block)) {
                if (str_contains($block, 'write')) {
                    $scopes['*'] = true; // `write-all` : write sur tous les scopes
                }

                continue;
            }

            if (!\is_array($block)) {
                continue;
            }

            foreach ($block as $scope => $access) {
                if (\is_string($access) && str_contains($access, 'write')) {
                    $scopes[(string) $scope] = true;
                }
            }
        }

        return array_keys($scopes);
    }

    /**
     * Les workflows de `.github/workflows/`, indexés par nom de fichier.
     *
     * @return array<string, string>
     */
    private function workflowFiles(): array
    {
        $paths = glob(self::WORKFLOWS_DIR . '/*.yml');
        self::assertIsArray($paths);
        self::assertNotEmpty($paths, 'Aucun workflow trouvé dans .github/workflows/ — le glob a-t-il cassé ?');

        $files = [];
        foreach ($paths as $path) {
            $files[basename($path)] = $path;
        }

        return $files;
    }
}
