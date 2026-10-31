<?php

declare(strict_types=1);

namespace App\Tests\Security;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * La version affichée par l'API est celle réellement déployée : compose interpole `VERSION` au
 * déploiement, mais le conteneur ne la reçoit que si le bloc `environment:` de php-fpm la lui passe
 * (sinon il lit un `.env.prod` réécrit après le démarrage, donc périmé d'une version).
 * Le `${VERSION:-latest}` de la ligne `image:` ne compte pas : seul le bloc `environment:` est lu.
 */
#[Group('phase1')]
final class ProdComposeVersionEnvTest extends TestCase
{
    private const COMPOSE = __DIR__ . '/../../../docker-compose.prod.yml';

    public function testPhpFpmReceivesTheDeployedVersionInItsEnvironment(): void
    {
        self::assertStringContainsString(
            'VERSION: "${VERSION:-latest}"',
            $this->phpFpmEnvironmentBlock(),
            'php-fpm doit recevoir VERSION dans son bloc `environment:`, sinon /api sert la version précédente.',
        );
    }

    private function phpFpmEnvironmentBlock(): string
    {
        $compose = file_get_contents(self::COMPOSE);
        self::assertIsString($compose, 'docker-compose.prod.yml introuvable.');

        $matched = preg_match('/\n {2}php-fpm:\n.*?\n {4}environment:\n((?: {6}[^\n]*\n)+)/s', $compose, $matches);
        self::assertSame(1, $matched, 'php-fpm doit déclarer un bloc `environment:`.');

        return $matches[1];
    }
}
