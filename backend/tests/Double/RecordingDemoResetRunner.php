<?php

declare(strict_types=1);

namespace App\Tests\Double;

use App\Service\DemoResetRunnerInterface;
use RuntimeException;

/**
 * Reset runner sans sous-processus, pour les tests HTTP de la console démo : le vrai
 * runner lance `bin/console app:demo:seed` (purge + re-seed du club BCCL), inobservable
 * et destructeur en test. Ce double enregistre l'appel et peut simuler l'échec.
 */
final class RecordingDemoResetRunner implements DemoResetRunnerInterface
{
    public int $calls = 0;

    public bool $shouldFail = false;

    public function run(): void
    {
        ++$this->calls;
        if ($this->shouldFail) {
            throw new RuntimeException('simulated reseed failure');
        }
    }

    public function reset(): void
    {
        $this->calls = 0;
        $this->shouldFail = false;
    }
}
