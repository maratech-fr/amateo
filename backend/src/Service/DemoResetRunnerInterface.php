<?php

declare(strict_types=1);

namespace App\Service;

use RuntimeException;

/**
 * Réinitialise le club de DÉMONSTRATION permanent BCCL (purge + re-seed à l'identique).
 * Interface pour que la console puisse être testée sans lancer le seed réel.
 */
interface DemoResetRunnerInterface
{
    /**
     * Purge puis re-seed le club démo BCCL (ARA9999999).
     *
     * @throws RuntimeException si le re-seed échoue (échec FRANC, jamais silencieux)
     */
    public function run(): void;
}
