<?php

declare(strict_types=1);

namespace App\Tests\Unit\Documentation;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Contrat du dispatch de déploiement — le backend (« mise en prod programmée », PR b) dispatche
 * `deploy.yml` via l'API GitHub en lui passant `version` ET `maintenance_until`. Ces deux inputs
 * DOIVENT exister côté workflow, sinon le dispatch partirait avec des paramètres que le workflow
 * ignore (pas de codegen, synchronisation manuelle — même esprit que les `*ContractSchemaTest`).
 *
 * Ce test est VERT dès à présent : `version` existe de longue date et `maintenance_until` a été
 * ajouté par la PR (a). Son rôle est un CLIQUET : retirer ou renommer l'un de ces inputs dans
 * `deploy.yml` le fait rougir, protégeant le contrat que le client GitHub du backend honore.
 *
 * (Symfony Yaml conserve la clé `on` telle quelle — vérifié : `$workflow['on']`.)
 */
#[Group('phase1')]
final class DeployWorkflowDispatchContractTest extends TestCase
{
    private const string WORKFLOW = __DIR__ . '/../../../../.github/workflows/deploy.yml';

    public function testDeployWorkflowDeclaresTheDispatchInputsTheBackendSends(): void
    {
        $workflow = Yaml::parseFile(self::WORKFLOW);
        self::assertIsArray($workflow, 'deploy.yml ne parse pas en mapping YAML.');

        $on = $workflow['on'] ?? null;
        self::assertIsArray($on, 'deploy.yml doit déclarer un bloc `on:`.');
        self::assertIsArray($on['workflow_dispatch'] ?? null, 'deploy.yml doit rester déclenchable par workflow_dispatch (le backend le dispatche).');

        $inputs = $on['workflow_dispatch']['inputs'] ?? null;
        self::assertIsArray($inputs, 'le workflow_dispatch de deploy.yml doit déclarer des `inputs:`.');

        foreach (['version', 'maintenance_until'] as $input) {
            self::assertArrayHasKey($input, $inputs, \sprintf(
                "deploy.yml ne déclare plus l'input workflow_dispatch « %s ».\n"
                . "Le backend le passe à chaque dispatch (App\\Service\\GitHubDeployClient) : le retirer ou\n"
                . 'le renommer casse silencieusement la mise en prod programmée.',
                $input,
            ));
        }
    }

    /**
     * Promesse : un push de tag ne DÉPLOIE jamais.
     *
     * Cadrage « tag ≠ déploiement » (`.next/cadrage-superadmin.md`) : pousser un tag `v*` ne fait
     * que CONSTRUIRE et publier les images ; le déploiement sur la VM se décide à la main (console
     * SuperAdmin « mise en prod programmée » ou `make deploy VERSION=…`). Le job `deploy` doit donc
     * exiger DEUX conditions : l'événement `workflow_dispatch` (jamais un push) ET la variable
     * d'armement `DEPLOY_ENABLED == 'true'`. La ceinture `workflow_dispatch` est la nouveauté qui
     * sépare le build du déploiement ; la variable d'armement préexiste (la VM n'existe pas encore).
     *
     * Pas de harnais d'exécution de workflow ici : ce test lit la STATIQUE du `if:`. La preuve
     * réelle (un tag qui construit sans déployer) sera le 1er tag poussé après le merge.
     */
    public function testATagPushNeverDeploysTheDeployJobDemandsWorkflowDispatchAndArming(): void
    {
        $workflow = Yaml::parseFile(self::WORKFLOW);
        self::assertIsArray($workflow, 'deploy.yml ne parse pas en mapping YAML.');

        $deploy = $workflow['jobs']['deploy'] ?? null;
        self::assertIsArray($deploy, 'deploy.yml doit déclarer le job `deploy`.');

        $if = $deploy['if'] ?? null;
        self::assertIsString($if, 'le job `deploy` doit porter une condition `if:`.');
        $normalized = $this->normalizeExpression($if);

        self::assertStringContainsString(
            'github.event_name==\'workflow_dispatch\'',
            $normalized,
            "le `if:` du job `deploy` doit exiger github.event_name == 'workflow_dispatch' : sans cette\n"
            . "ceinture, un push de tag v* déploierait sur la VM, alors qu'un tag ne doit que CONSTRUIRE\n"
            . '(cadrage « tag ≠ déploiement » ; déploiement = console SuperAdmin ou make deploy VERSION=).',
        );
        self::assertStringContainsString(
            'vars.DEPLOY_ENABLED==\'true\'',
            $normalized,
            'le `if:` du job `deploy` doit conserver la garde d\'armement vars.DEPLOY_ENABLED == \'true\'.',
        );
    }

    /**
     * Promesse : le job `update-release-branch` porte SA PROPRE ceinture `workflow_dispatch`.
     *
     * `needs: [build-push, deploy]` suffit en théorie (il ne tourne que si `deploy` a réussi), mais
     * le cadrage « tag ≠ déploiement » exige une ceinture EXPLICITE : recaler la branche `release`
     * est un geste de déploiement, jamais de simple build de tag. Le `if:` dédié rend l'intention
     * lisible et résiste à un futur découplage de `needs`.
     *
     * Lecture statique du `if:` ; preuve réelle = 1er tag après merge (ne recale pas `release`).
     */
    public function testUpdateReleaseBranchCarriesItsOwnWorkflowDispatchBelt(): void
    {
        $workflow = Yaml::parseFile(self::WORKFLOW);
        self::assertIsArray($workflow, 'deploy.yml ne parse pas en mapping YAML.');

        $job = $workflow['jobs']['update-release-branch'] ?? null;
        self::assertIsArray($job, 'deploy.yml doit déclarer le job `update-release-branch`.');

        $if = $job['if'] ?? null;
        self::assertIsString(
            $if,
            "le job `update-release-branch` doit porter sa PROPRE condition `if:` (ceinture explicite,\n"
            . 'en plus de `needs: deploy`) : recaler `release` est un geste de déploiement, pas de build de tag.',
        );
        self::assertStringContainsString(
            'github.event_name==\'workflow_dispatch\'',
            $this->normalizeExpression($if),
            'le `if:` du job `update-release-branch` doit exiger github.event_name == \'workflow_dispatch\'.',
        );
    }

    /**
     * Promesse (CLIQUET déjà VERT — Q1 fondateur) : un tag v* CONSTRUIT toujours.
     *
     * Ce test est vert aujourd'hui et le reste : il garde le fait que le push d'un tag `v*`
     * déclenche le workflow (on.push.tags) ET que le job `build-push` ne porte aucune condition
     * d'événement qui l'empêcherait de tourner sur un push. Il rougirait si quelqu'un retirait le
     * déclencheur de tag ou restreignait `build-push` au seul `workflow_dispatch` — ce qui casserait
     * le « tag = build seul » voulu.
     */
    public function testATagAlwaysBuildsImagesRatchet(): void
    {
        $workflow = Yaml::parseFile(self::WORKFLOW);
        self::assertIsArray($workflow, 'deploy.yml ne parse pas en mapping YAML.');

        $tags = $workflow['on']['push']['tags'] ?? null;
        self::assertIsArray($tags, 'deploy.yml doit rester déclenché par le push d\'un tag (on.push.tags).');
        self::assertContains('v*', $tags, 'deploy.yml doit construire sur tout tag v* (on.push.tags).');

        $buildPush = $workflow['jobs']['build-push'] ?? null;
        self::assertIsArray($buildPush, 'deploy.yml doit déclarer le job `build-push`.');

        $if = $buildPush['if'] ?? null;
        if (null !== $if) {
            self::assertIsString($if, 'une condition `if:` de job doit être une chaîne.');
            self::assertStringNotContainsString(
                'workflow_dispatch',
                $this->normalizeExpression($if),
                '`build-push` ne doit pas se restreindre à workflow_dispatch : un push de tag doit TOUJOURS construire.',
            );
        }
    }

    /**
     * Promesse : sur un push, `build-push` ANNONCE qu'aucun déploiement n'a lieu.
     *
     * Un step de `build-push`, conditionné à github.event_name == 'push', écrit dans
     * $GITHUB_STEP_SUMMARY un message « aucun déploiement » citant `make deploy VERSION=` — pour que
     * l'opérateur qui pousse un tag voie noir sur blanc que le tag n'a fait que construire, et
     * comment déployer ensuite (cadrage « tag ≠ déploiement »). Vérification statique : un step dont
     * le `if:` normalisé contient github.event_name == 'push' et dont le `run` cite à la fois
     * $GITHUB_STEP_SUMMARY et `make deploy VERSION=`.
     */
    public function testAPushBuildAnnouncesNoDeploymentInTheStepSummary(): void
    {
        $workflow = Yaml::parseFile(self::WORKFLOW);
        self::assertIsArray($workflow, 'deploy.yml ne parse pas en mapping YAML.');

        $steps = $workflow['jobs']['build-push']['steps'] ?? null;
        self::assertIsArray($steps, 'deploy.yml doit déclarer les `steps` du job `build-push`.');

        $found = false;
        foreach ($steps as $step) {
            if (!\is_array($step)) {
                continue;
            }
            $if = isset($step['if']) && \is_string($step['if']) ? $this->normalizeExpression($step['if']) : '';
            $run = isset($step['run']) && \is_string($step['run']) ? (string) preg_replace('/\s+/', ' ', $step['run']) : '';

            if (!str_contains($if, 'github.event_name==\'push\'')) {
                continue;
            }
            if (!str_contains($run, 'GITHUB_STEP_SUMMARY')) {
                continue;
            }
            if (!str_contains($run, 'make deploy VERSION=')) {
                continue;
            }
            $found = true;
            break;
        }

        self::assertTrue(
            $found,
            "`build-push` doit porter un step conditionné à github.event_name == 'push' qui écrit dans\n"
            . "\$GITHUB_STEP_SUMMARY un message « aucun déploiement » citant `make deploy VERSION=`.\n"
            . "Sans lui, l'opérateur qui pousse un tag ne voit nulle part que le tag n'a fait que CONSTRUIRE\n"
            . '(cadrage « tag ≠ déploiement » ; déploiement = console SuperAdmin ou make deploy VERSION=).',
        );
    }

    /**
     * Normalise une expression GitHub Actions `if:` en supprimant tout espace, pour comparer la
     * SUBSTANCE sans figer l'espacement (les expressions GHA n'autorisent que les guillemets
     * simples pour les littéraux, donc la casse des guillemets n'a pas à être normalisée).
     */
    private function normalizeExpression(string $expression): string
    {
        return (string) preg_replace('/\s+/', '', $expression);
    }
}
