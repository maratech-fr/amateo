<?php

declare(strict_types=1);

namespace App\Tests\Doctrine;

use App\Tests\Architecture\NoNullPlanBranchTest;
use App\Tests\Integration\Doctrine\NullPlanTripwireTest;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use LogicException;
use SensitiveParameter;

/**
 * Fil-piège du lot 4 (PR 2, « le socle a son plan »), en environnement de test seulement : toute requête SQL
 * qui cherche `schedule_plan_id IS NULL` ou `IS NOT NULL` lève. Depuis que chaque ligne du socle porte le plan
 * de saison, une telle recherche rend une liste vide sans erreur ; le fil-piège est la seule garde qui voit une
 * variable mise à nul puis passée à une recherche (le balayage {@see NoNullPlanBranchTest}
 * ne voit que les formes écrites). Il vaut ce que les suites exercent (limite 6 du plan).
 *
 * Seules les migrations y échappent : c'est là que la conversion trouve, une dernière fois, les lignes à plan nul.
 * Branché par `config/services_test.yaml` (`doctrine.middleware`) ; prouvé par
 * {@see NullPlanTripwireTest}.
 */
final class NullPlanTripwireMiddleware implements Middleware
{
    public const string PATTERN = '/\bschedule_plan_id\s+IS\s+(?:NOT\s+)?NULL\b/i';

    /** Lève sur une recherche de plan nul, hors migration. */
    public static function inspect(string $sql): void
    {
        if (1 !== preg_match(self::PATTERN, $sql) || self::runsInAMigration()) {
            return;
        }

        throw new LogicException(\sprintf("Recherche de « plan nul » : depuis le lot 4, chaque ligne du socle porte le plan de saison (SoclePlan::idOf).\nCette requête rendrait une liste vide sans erreur :\n%s", $sql));
    }

    private static function runsInAMigration(): bool
    {
        foreach (debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            $class = $frame['class'] ?? '';
            if (str_starts_with($class, 'DoctrineMigrations\\') || str_starts_with($class, 'Doctrine\Migrations\\')) {
                return true;
            }
        }

        return false;
    }

    public function wrap(Driver $driver): Driver
    {
        return new class($driver) extends AbstractDriverMiddleware {
            public function connect(#[SensitiveParameter] array $params): DriverConnection
            {
                return new class(parent::connect($params)) extends AbstractConnectionMiddleware {
                    public function prepare(string $sql): Statement
                    {
                        NullPlanTripwireMiddleware::inspect($sql);

                        return parent::prepare($sql);
                    }

                    public function query(string $sql): Result
                    {
                        NullPlanTripwireMiddleware::inspect($sql);

                        return parent::query($sql);
                    }

                    public function exec(string $sql): int|string
                    {
                        NullPlanTripwireMiddleware::inspect($sql);

                        return parent::exec($sql);
                    }
                };
            }
        };
    }
}
