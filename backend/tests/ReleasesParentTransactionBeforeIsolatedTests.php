<?php

declare(strict_types=1);

namespace App\Tests;

use DAMA\DoctrineTestBundle\PHPUnit\PHPUnitExtension as DamaExtension;
use PHPUnit\Event\TestSuite\Started;
use PHPUnit\Event\TestSuite\StartedSubscriber;
use PHPUnit\Event\TestSuite\TestSuiteForTestClass;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;
use ReflectionClass;

/**
 * Relâche la transaction DAMA du processus PARENT avant qu'une classe de tests
 * isolés (`#[RunInSeparateProcess]`) ne démarre.
 *
 * Pourquoi : DAMA n'annule la transaction du test précédent qu'au
 * `PreparationStarted` du test SUIVANT. Pour un test isolé, cet événement est
 * émis dans le processus ENFANT et n'atteint le parent qu'une fois l'enfant
 * terminé. Pendant toute la vie de l'enfant, le parent garde donc les écritures
 * NON COMMITÉES du test d'avant — et leurs verrous. Si l'enfant touche une clé
 * que le parent détient (ex. `priority_tier` id 1, que dix-neuf tests API créent
 * dans leur transaction et que `BcclSeeder` insère en find-or-create), l'enfant
 * attend le parent, qui attend l'enfant : interblocage muet, tranché seulement par
 * `idle_in_transaction_session_timeout` (60 s sur amateo_test, PR #281) qui tue
 * la connexion du parent — et tout le reste de la suite meurt en cascade
 * (« no connection to the server », 772 erreurs constatées le 2026-09-03 quand
 * `BcclSeedCommandTest` s'est retrouvé juste après `VenueUsageStatsApiTest`).
 * Sans timeout, la suite pend pour toujours.
 *
 * Reproduction (avant ce correctif) :
 *   vendor/bin/phpunit tests/Integration/Api/VenueUsageStatsApiTest.php \
 *                      tests/Integration/Command/BcclSeedCommandTest.php
 *   → 1 min 04, « terminating connection due to idle-in-transaction timeout ».
 *
 * Le correctif : au démarrage de la suite d'une classe portant des tests isolés,
 * on annule la transaction du parent (rien à préserver : le prochain
 * `PreparationStarted` l'aurait annulée de toute façon). Les autres classes ne
 * sont pas touchées. Déclaré dans `phpunit.xml.dist` APRÈS l'extension DAMA.
 */
final class ReleasesParentTransactionBeforeIsolatedTests implements Extension
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $facade->registerSubscriber(new class implements StartedSubscriber {
            public function notify(Started $event): void
            {
                $suite = $event->testSuite();
                if (!$suite instanceof TestSuiteForTestClass || !$this->runsTestsInSeparateProcesses($suite->className())) {
                    return;
                }

                DamaExtension::rollBack();
            }

            /** @param class-string $className */
            private function runsTestsInSeparateProcesses(string $className): bool
            {
                $class = new ReflectionClass($className);
                if ([] !== $class->getAttributes(RunTestsInSeparateProcesses::class)) {
                    return true;
                }

                foreach ($class->getMethods() as $method) {
                    if ([] !== $method->getAttributes(RunInSeparateProcess::class)) {
                        return true;
                    }
                }

                return false;
            }
        });
    }
}
