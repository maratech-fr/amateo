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
}
