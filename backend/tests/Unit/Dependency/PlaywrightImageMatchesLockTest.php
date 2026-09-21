<?php

declare(strict_types=1);

namespace App\Tests\Unit\Dependency;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * NR — l'image du conteneur e2e doit suivre le verrou de `@playwright/test`.
 *
 * L'incident : `make -C frontend e2e` a planté ses 46 specs sur
 * « browserType.launch: Executable doesn't exist » parce que le service `e2e`
 * de `docker-compose.yml` épinglait l'image officielle Playwright en
 * `v1.62.0-noble` pendant que `frontend/package-lock.json` verrouillait
 * `@playwright/test` en `1.63.0` — les navigateurs préinstallés dans l'image ne
 * correspondaient plus au client qui les lance.
 *
 * Le point vicieux, et la raison d'être de ce garde : la CI ne l'attrape
 * JAMAIS. Le job e2e n'utilise pas cette image — il fait `npx playwright
 * install chromium` avec un cache keyé sur le hash du lock (`ci.yml`). Une
 * montée de version reste donc VERTE en CI et ne casse que la suite LOCALE, en
 * silence, au pire moment : quand on essaie de jouer les tests chez soi. Rien
 * ne couplait les deux versions ; ce test est ce couplage.
 *
 * Source de vérité du verrou : la version RÉSOLUE réellement installée,
 * `packages['node_modules/@playwright/test'].version`, PAS la contrainte de
 * plage `^1.63.0` de `packages[''].devDependencies` — c'est le paquet installé
 * qui pose les navigateurs dans l'image, pas la plage acceptée.
 */
#[Group('phase1')]
final class PlaywrightImageMatchesLockTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../../..';

    private const string COMPOSE = self::ROOT . '/docker-compose.yml';

    private const string LOCK = self::ROOT . '/frontend/package-lock.json';

    /** Le service de compose qui joue les e2e sur l'image officielle Playwright. */
    private const string SERVICE = 'e2e';

    public function testTheE2eImageTagMatchesTheLockedPlaywrightVersion(): void
    {
        $image = $this->e2eImageTag();
        $inImage = $this->versionInImageTag($image);
        $locked = $this->lockedPlaywrightVersion();

        self::assertSame($locked, $inImage, \sprintf(
            'L\'image e2e de docker-compose (« %s », soit Playwright %s) ne suit plus le verrou '
            . "de @playwright/test (%s dans frontend/package-lock.json).\n"
            . 'Une dérive = « Executable doesn\'t exist » au premier `make -C frontend e2e`, et la CI '
            . "ne l'attrape pas (elle installe son propre navigateur, cache keyé sur le lock).\n"
            . 'Correctif : aligner le tag du service « %s » sur `mcr.microsoft.com/playwright:v%s-noble`.',
            $image,
            $inImage,
            $locked,
            self::SERVICE,
            $locked,
        ));
    }

    /**
     * Le tag d'image du service e2e, lu dans le YAML (pas au regex sur le brut :
     * plusieurs services portent une image, seul « e2e » fait foi ici).
     */
    private function e2eImageTag(): string
    {
        $compose = Yaml::parseFile(self::COMPOSE);
        self::assertIsArray($compose);
        self::assertIsArray($compose['services'] ?? null, 'docker-compose.yml n’a plus de bloc `services`.');
        self::assertIsArray(
            $compose['services'][self::SERVICE] ?? null,
            \sprintf('Le service « %s » a disparu de docker-compose.yml.', self::SERVICE),
        );

        $image = $compose['services'][self::SERVICE]['image'] ?? null;
        self::assertIsString($image, \sprintf('Le service « %s » n’a plus de clé `image`.', self::SERVICE));

        return $image;
    }

    /**
     * Extrait `X.Y.Z` d'un tag `…/playwright:vX.Y.Z-noble`.
     */
    private function versionInImageTag(string $image): string
    {
        self::assertSame(
            1,
            preg_match('#playwright:v(\d+\.\d+\.\d+)#', $image, $m),
            \sprintf('Le tag de l’image e2e (« %s ») n’a pas la forme `playwright:vX.Y.Z-…` attendue.', $image),
        );

        return $m[1];
    }

    /**
     * La version RÉSOLUE de @playwright/test dans le lock — l'installé, jamais la plage.
     */
    private function lockedPlaywrightVersion(): string
    {
        $raw = file_get_contents(self::LOCK);
        self::assertIsString($raw, 'frontend/package-lock.json introuvable.');

        /** @var array{packages?: array<string, array{version?: string}>} $lock */
        $lock = json_decode($raw, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($lock['packages'] ?? null, 'package-lock.json (v3) n’a plus de bloc `packages`.');

        $entry = $lock['packages']['node_modules/@playwright/test'] ?? null;
        self::assertIsArray(
            $entry,
            'node_modules/@playwright/test absent du lock — @playwright/test n’est plus une dépendance installée ?',
        );
        self::assertIsString($entry['version'] ?? null, 'La version résolue de @playwright/test manque au lock.');
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $entry['version'], 'Version @playwright/test non-semver dans le lock.');

        return $entry['version'];
    }
}
