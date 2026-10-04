<?php

declare(strict_types=1);

namespace App\Tests\Security;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * SEC-23 — les secrets de prod ne vivent plus dans le dépôt. La source de vérité
 * est le secret GitHub `ENV_PROD` (contenu intégral du `.env.prod`), poussé sur la
 * VM par `deploy.yml`. Deux cliquets statiques, façon {@see EnvHygieneTest} :
 *   - l'ancien rail chiffré `.env.prod.gpg` n'est plus suivi par git et ne doit
 *     jamais y revenir (sinon des secrets symétriques repartent dans un dépôt
 *     public, attaquables hors ligne) ;
 *   - `deploy.yml` ne mentionne plus ni `gpg` ni `ENV_GPG_PASSPHRASE` — leur
 *     retour signalerait la résurrection du rail chiffré.
 *
 * On N'épingle PAS les anciennes valeurs de secrets : on ne les connaît pas, et
 * les écrire ici les publierait. La rotation est un geste fondateur, hors code.
 */
#[Group('phase1')]
final class SecretsRailTest extends TestCase
{
    /** Racine du dépôt : backend/tests/Security → backend/tests → backend → racine. */
    private const REPO_ROOT = __DIR__ . '/../../..';

    public function testTheEncryptedEnvRailIsNoLongerTracked(): void
    {
        $root = realpath(self::REPO_ROOT);
        self::assertNotFalse($root, 'racine du dépôt introuvable.');

        $out = [];
        exec('git -C ' . escapeshellarg($root) . ' ls-files -- ' . escapeshellarg('.env.prod.gpg') . ' 2>/dev/null', $out, $code);
        self::assertSame(0, $code, 'git ls-files a échoué — git indisponible ?');
        self::assertSame(
            [],
            $out,
            '.env.prod.gpg ne doit plus être suivi par git : la source de vérité des secrets de prod est le secret GitHub ENV_PROD (voir docs/ops/deploy.md).',
        );
    }

    public function testDeployWorkflowCarriesNoGpgRail(): void
    {
        $path = self::REPO_ROOT . '/.github/workflows/deploy.yml';
        $content = file_get_contents($path);
        self::assertIsString($content, 'deploy.yml illisible.');

        self::assertStringNotContainsString(
            'gpg',
            $content,
            'deploy.yml ne doit plus référencer gpg : le rail chiffré a été remplacé par le secret ENV_PROD.',
        );
        self::assertStringNotContainsString(
            'ENV_GPG_PASSPHRASE',
            $content,
            'deploy.yml ne doit plus référencer ENV_GPG_PASSPHRASE : le rail chiffré a été remplacé par le secret ENV_PROD.',
        );
    }
}
