<?php

declare(strict_types=1);

namespace App\Tests;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Les clés étrangères réellement posées en base (`pg_constraint`), jamais une liste recopiée des
 * migrations : enfant, colonne, parent et règle de suppression (`c` CASCADE, `n` SET NULL,
 * `d` SET DEFAULT, `r` RESTRICT, `a` NO ACTION).
 */
trait RealForeignKeysTrait
{
    /** @return list<array{child: string, column: string, parent: string, onDelete: string, name: string}> */
    private function realForeignKeys(): array
    {
        $rows = static::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchAllAssociative(
            'SELECT con.conrelid::regclass::text AS child, att.attname AS col, con.confrelid::regclass::text AS parent,
                    con.confdeltype::text AS on_delete, con.conname AS name
               FROM pg_constraint con
               JOIN pg_attribute att ON att.attrelid = con.conrelid AND att.attnum = ANY (con.conkey)
              WHERE con.contype = :foreignKey
              ORDER BY 1, 2, 3',
            ['foreignKey' => 'f'],
        );

        return array_map(static fn (array $row): array => [
            'child' => trim((string) $row['child'], '"'),
            'column' => (string) $row['col'],
            'parent' => trim((string) $row['parent'], '"'),
            'onDelete' => (string) $row['on_delete'],
            'name' => (string) $row['name'],
        ], $rows);
    }
}
