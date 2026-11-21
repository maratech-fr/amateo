<?php

declare(strict_types=1);

namespace App\Tests;

use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;

/**
 * Les tables visées par les `DELETE` qu'un geste exécute réellement, dans l'ordre d'exécution
 * (journal de requêtes de la connexion `default`, actif en environnement de test). Un `DELETE` qui
 * n'efface aucune ligne compte quand même : c'est l'ordre des instructions qu'on lit, pas les données.
 */
trait CapturesDeletedTablesTrait
{
    /**
     * @param callable(): mixed $gesture
     *
     * @return list<string> tables, dans l'ordre des instructions `DELETE`
     */
    private function deletedTablesDuring(callable $gesture): array
    {
        $holder = static::getContainer()->get('doctrine.debug_data_holder');
        \assert($holder instanceof DebugDataHolder);
        $holder->reset();

        $gesture();

        $tables = [];
        foreach ($holder->getData()['default'] ?? [] as $query) {
            if (1 === preg_match('/^\s*DELETE\s+FROM\s+"?([a-z_]+)"?/i', (string) $query['sql'], $match)) {
                $tables[] = strtolower($match[1]);
            }
        }
        self::assertNotSame([], $tables, 'Aucun DELETE capté : le journal de requêtes est-il actif ?');

        return $tables;
    }
}
