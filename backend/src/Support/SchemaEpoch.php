<?php

declare(strict_types=1);

namespace App\Support;

use LogicException;

/**
 * L'époque du schéma : l'empreinte de la liste des noms de fichiers de `backend/migrations/` livrés avec le code.
 *
 * Elle entre dans la clé du cache des demandes au moteur : un code qui n'a pas les mêmes migrations ne relit jamais
 * une demande mise en cache par un autre. Toute migration ajoutée ou retirée la change, quelle que soit sa date (une
 * branche longue en apporte une « du passé », qu'un « plus grand numéro » ne verrait pas) ; l'ordre de lecture du
 * dossier n'y entre pas. Suppose le dossier présent dans l'image : c'est elle qui migre.
 */
final class SchemaEpoch
{
    private const string MIGRATIONS_DIR = __DIR__ . '/../../migrations';

    private static ?string $current = null;

    /** @param list<string> $migrationFileNames */
    public static function of(array $migrationFileNames): string
    {
        sort($migrationFileNames, \SORT_STRING);

        return substr(hash('sha256', implode("\n", $migrationFileNames)), 0, 12);
    }

    /** Calculée une fois par processus. */
    public static function current(): string
    {
        if (null === self::$current) {
            $paths = glob(self::MIGRATIONS_DIR . '/*.php');
            if (false === $paths || [] === $paths) {
                throw new LogicException('Le dossier des migrations est introuvable : l\'époque du cache ne peut pas être calculée.');
            }
            self::$current = self::of(array_map(basename(...), $paths));
        }

        return self::$current;
    }
}
