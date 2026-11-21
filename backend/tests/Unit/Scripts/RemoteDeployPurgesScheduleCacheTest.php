<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scripts;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Promesse : une mise en prod réussie purge seule le cache des demandes au moteur
 * (`cache:pool:clear cache.schedule`), première installation comprise, et un échec de cette purge
 * le DIT au journal sans jamais faire échouer le déploiement (la levée de la maintenance et le
 * recalage de `release` lisent son code de sortie, `.github/workflows/deploy.yml`).
 *
 * Le script réel tourne, `docker` et `curl` remplacés par des doublures qui journalisent leurs
 * appels : les deux branches (installation existante, première installation) sont jouées pour de bon,
 * et le code de sortie lu est celui du script. Jumeau statique du câblage voisin :
 * `RemoteDeployRealignsSnapshotsTest`. Recours manuel sur alerte : `docs/ops/deploy.md`.
 * Annoté `phase1` comme son jumeau, NON listé dans « Blocking suite » : il tourne dans `backend-tests`.
 */
#[Group('phase1')]
final class RemoteDeployPurgesScheduleCacheTest extends TestCase
{
    private const string SCRIPT = __DIR__ . '/../../../../scripts/remote-deploy.sh';
    private const string PURGE = 'exec -T php-fpm php bin/console cache:pool:clear cache.schedule';
    private const string MANUAL_RELAUNCH = 'docker exec amateo-php-fpm php bin/console cache:pool:clear cache.schedule';

    private string $dir = '';

    /** @return iterable<string, array{bool}> */
    public static function branches(): iterable
    {
        yield 'installation existante' => [true];
        yield 'première installation' => [false];
    }

    /** Le cache est purgé après « Deploy … OK », dans les deux branches du script. */
    #[DataProvider('branches')]
    public function testASuccessfulDeployPurgesTheScheduleCache(bool $existingInstall): void
    {
        $run = $this->deploy($existingInstall, purgeFails: false);

        self::assertSame(0, $run['exit'], 'le déploiement réussit : ' . $run['stderr']);
        $stdout = $run['stdout'];
        // Témoin de branche : sans lui, les deux cas pourraient jouer le même chemin.
        self::assertSame($existingInstall, str_contains($stdout, 'app:db:backup'), 'seule une installation existante fait sa sauvegarde');
        self::assertSame(!$existingInstall, str_contains($stdout, '==> First install'), 'seule une première installation suit l\'ordre d\'amorçage');
        $deployOk = strpos($stdout, '==> Deploy v9.9.9 OK');
        $purge = $this->positionOfPurge($stdout);
        self::assertNotFalse($deployOk, 'le script annonce le déploiement réussi');
        self::assertNotFalse($purge, \sprintf('le script lance « %s »', self::PURGE));
        self::assertGreaterThan($deployOk, $purge, 'la purge tourne après « Deploy … OK » (sondes de santé passées, version écrite)');
    }

    /** Une purge en échec laisse le déploiement réussi, dit l'alerte avec la commande à relancer, et le script va au bout. */
    #[DataProvider('branches')]
    public function testAFailedPurgeWarnsWithoutFailingTheDeploy(bool $existingInstall): void
    {
        $run = $this->deploy($existingInstall, purgeFails: true);

        self::assertNotFalse($this->positionOfPurge($run['stdout']), 'la purge a été tentée');
        self::assertSame(0, $run['exit'], 'un échec de la purge ne fait jamais échouer un déploiement réussi');
        self::assertStringNotContainsString('FATAL', $run['stderr'], 'le piège d\'erreur du script ne se déclenche pas');
        self::assertStringContainsString(self::MANUAL_RELAUNCH, $run['stderr'], 'l\'alerte donne la commande de purge à relancer à la main');
        self::assertStringContainsString('STUB docker image prune', $run['stdout'], 'le script continue après l\'échec de la purge');
    }

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/remote-deploy-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/bin', 0o775, true);
        mkdir($this->dir . '/deploy', 0o775, true);
        file_put_contents($this->dir . '/deploy/.env.prod', "FRONTEND_PORT=8081\nVERSION=v9.9.8\n");

        // Doublure de docker : journalise chaque appel sur la sortie standard, dans l'ordre du script.
        $this->stub('docker', <<<'SH'
            #!/usr/bin/env bash
            echo "STUB docker $*"
            case "$*" in
              *" config"*) echo "name: amateo" ;;
              "volume inspect"*) [ "$STUB_EXISTING_INSTALL" = "1" ] || exit 1 ;;
              *"cache:pool:clear cache.schedule"*) [ "$STUB_PURGE_FAILS" = "1" ] && exit 1 ;;
            esac
            exit 0
            SH);
        $this->stub('curl', "#!/usr/bin/env bash\nexit 0\n");
    }

    protected function tearDown(): void
    {
        new Process(['rm', '-rf', $this->dir])->run();
    }

    /** @return array{exit: int|null, stdout: string, stderr: string} */
    private function deploy(bool $existingInstall, bool $purgeFails): array
    {
        self::assertFileExists(self::SCRIPT);
        $process = new Process(['bash', self::SCRIPT], null, [
            'PATH' => $this->dir . '/bin:' . (getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin'),
            'DEPLOY_PATH' => $this->dir . '/deploy',
            'VERSION' => 'v9.9.9',
            'STUB_EXISTING_INSTALL' => $existingInstall ? '1' : '0',
            'STUB_PURGE_FAILS' => $purgeFails ? '1' : '0',
        ], null, 60);
        $process->run();

        return ['exit' => $process->getExitCode(), 'stdout' => $process->getOutput(), 'stderr' => $process->getErrorOutput()];
    }

    private function positionOfPurge(string $stdout): int|false
    {
        foreach (explode("\n", $stdout) as $line) {
            if (str_starts_with($line, 'STUB docker ') && str_contains($line, self::PURGE)) {
                return strpos($stdout, $line);
            }
        }

        return false;
    }

    private function stub(string $name, string $body): void
    {
        $path = $this->dir . '/bin/' . $name;
        file_put_contents($path, $body . "\n");
        chmod($path, 0o755);
    }
}
