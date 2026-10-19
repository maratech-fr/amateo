<?php

declare(strict_types=1);

namespace App\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tout SOUS-RÉPERTOIRE de `config/packages/` est le nom d'un ENVIRONNEMENT chargé par le kernel —
 * sinon sa config est morte EN SILENCE.
 *
 * `MicroKernelTrait::configureContainer` (vendor/symfony/framework-bundle/Kernel/MicroKernelTrait.php:54-55)
 * n'importe que deux choses sous `config/packages/` :
 *   - `config/packages/*.{php,yaml}`             → tous environnements ;
 *   - `config/packages/{$this->environment}/*`   → l'environnement COURANT uniquement.
 * `$this->environment` (APP_ENV) ne vaut jamais que `dev`, `test` ou `prod`. Un dossier nommé
 * autrement n'est l'environnement d'aucun run : ses yaml ne sont JAMAIS importés, le conteneur se
 * compile sans eux, et tout reste vert — le réglage ne sert à rien et personne ne le voit.
 *
 * ⚑ Le piège exact : la conditionnelle d'environnement DANS un yaml déjà chargé s'écrit `when@test:`
 * (une CLÉ, gardée par Symfony — voir doctrine.yaml, monolog.yaml…), et quelqu'un recopie cette
 * syntaxe en NOM DE DOSSIER (`config/packages/when@test/`). Le dossier mime la clé mais n'est pas
 * un environnement : son contenu est du réglage mort. Ce garde tient la frontière par le NOM de
 * dossier, le signal le plus honnête.
 */
#[Group('phase1')]
final class ConfigPackagesSubdirectoriesAreEnvironmentsTest extends TestCase
{
    /**
     * Les seules valeurs que `Kernel::$environment` (APP_ENV) prend dans ce dépôt — donc les seuls
     * noms de sous-dossier que `configureContainer` importe. `dev`/`test` sont committés ; `prod`
     * est posé au déploiement et jamais committé (cf. `.env.prod`), mais `config/packages/prod/`
     * serait légitimement chargé sous cet environnement.
     *
     * @var list<string>
     */
    private const array KERNEL_ENVIRONMENTS = ['dev', 'test', 'prod'];

    private const string PACKAGES_DIR = __DIR__ . '/../../../config/packages';

    /**
     * La promesse : aucun sous-dossier de `config/packages/` autre qu'un environnement du kernel.
     */
    public function testEverySubdirectoryOfConfigPackagesIsAKernelEnvironment(): void
    {
        self::assertDirectoryExists(self::PACKAGES_DIR);

        $dead = [];
        foreach (scandir(self::PACKAGES_DIR) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }

            $path = self::PACKAGES_DIR . '/' . $entry;
            if (!is_dir($path)) {
                continue; // les FICHIERS yaml (env-agnostiques) ne sont pas concernés
            }

            if (!\in_array($entry, self::KERNEL_ENVIRONMENTS, true)) {
                $dead[] = $entry;
            }
        }

        self::assertSame([], $dead, \sprintf(
            <<<'TXT'
                Ces sous-dossiers de config/packages/ ne sont le nom d'AUCUN environnement du kernel
                (dev/test/prod) : le MicroKernelTrait n'importe que `config/packages/{env}/*`, donc
                leurs yaml ne sont chargés NULLE PART — réglage mort, en silence.

                Dossier(s) fautif(s) : %s

                La conditionnelle d'environnement n'est pas un DOSSIER : à l'intérieur d'un yaml déjà
                chargé, on écrit la clé `when@<env>:` (p. ex. `when@test:` dans doctrine.yaml).
                Déplacez le contenu sous une clé `when@<env>:` d'un `config/packages/<paquet>.yaml`,
                puis SUPPRIMEZ le dossier mort.
                TXT,
            implode(', ', $dead),
        ));
    }

    /**
     * Contrôle positif : le garde ACCEPTE un vrai environnement (`config/packages/test/`), il ne
     * rejette pas tout sous-dossier. Sans ce témoin, la règle pourrait « tenir » en bannissant
     * jusqu'au dossier d'environnement légitime.
     */
    public function testAGenuineEnvironmentSubdirectoryIsAccepted(): void
    {
        self::assertDirectoryExists(
            self::PACKAGES_DIR . '/test',
            'le décor suppose un config/packages/test/ — un environnement réellement chargé par le kernel',
        );
        self::assertContains(
            'test',
            self::KERNEL_ENVIRONMENTS,
            '`test` DOIT figurer parmi les environnements acceptés, sinon le garde bannirait un dossier légitime',
        );
    }
}
