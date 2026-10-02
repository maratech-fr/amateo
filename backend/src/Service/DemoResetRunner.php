<?php

declare(strict_types=1);

namespace App\Service;

use App\Command\DatabaseBackupCommand;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

/**
 * Réinitialise le club de DÉMONSTRATION permanent BCCL depuis la console superadmin.
 *
 * Le reset est un SOUS-PROCESSUS `bin/console app:demo:seed` avec la connexion ADMIN
 * (superuser, hors RLS) : la requête console tourne, elle, sur la connexion applicative
 * — incapable de purger le workspace à travers la RLS. Même patron
 * {@see Process} que {@see DatabaseBackupCommand} ;
 * la variable d'env DATABASE_URL passée au sous-processus BAT le dotenv (comme `make
 * seed-demo`). JAMAIS le ConsoleAdminJobExecutor (in-process, connexion app).
 *
 * AUCUN clubId ne vient du client : `app:demo:seed` résout ARA9999999 + `is_demo`
 * lui-même et REFUSE un club non-démo (fail-fast). La remise à zéro de la date simulée
 * (`club.simulated_today`) et l'audit sont portés par le contrôleur appelant.
 */
final readonly class DemoResetRunner implements DemoResetRunnerInterface
{
    public function __construct(
        #[Autowire(param: 'kernel.project_dir')]
        private string $projectDir,
        #[Autowire(env: 'DATABASE_ADMIN_URL')]
        private string $adminDatabaseUrl,
    ) {}

    public function run(): void
    {
        $process = new Process(
            ['php', 'bin/console', 'app:demo:seed', '--no-interaction'],
            $this->projectDir,
            ['DATABASE_URL' => $this->adminDatabaseUrl],
            null,
            600.0,
        );
        $process->run();

        if (!$process->isSuccessful()) {
            // stderr du seed (garde RLS refusée, ARA non-démo, base injoignable…) : diagnostic
            // sans secret (l'URL admin passe par l'env, jamais argv), remonté FRANC à l'appelant.
            throw new RuntimeException('Demo reseed failed: ' . trim($process->getErrorOutput() ?: $process->getOutput()));
        }
    }
}
