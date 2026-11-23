<?php

declare(strict_types=1);

namespace App\Tests;

use InvalidArgumentException;

/**
 * Le balayage de l'effacement d'une personne (lot conception 3, PR 3) : UNE requête qui lit toutes
 * les colonnes texte, JSON et tableau de toutes les tables du schéma `public`, et rend la liste
 * `table.colonne` de celles qui contiennent encore l'un des textes cherchés (casse ignorée), ou une
 * chaîne vide. Une seule requête, pour qu'un context Behat la joue en un appel de `dbal:run-sql`.
 *
 * Ce qu'elle voit dépend de la connexion qui la joue : par la connexion d'administration (Behat,
 * `BaseContext::dbalScalar(…, admin: true)`) la base entière ; par la connexion de l'application
 * (tests d'intégration), les lignes du club posé en GUC et les tables sans RLS. Une table que le
 * rôle ne peut pas lire est sautée (`has_table_privilege`), sans faire échouer la requête. Un appelant
 * prouve donc d'abord que le balayage TROUVE la trace avant l'effacement : un zéro sans ce témoin ne
 * prouve rien.
 *
 * Une colonne `json` se lit par `::jsonb::text` : Doctrine l'écrit sans `JSON_UNESCAPED_UNICODE`, son
 * texte brut porte donc « Hélène » et un nom accentué n'y serait jamais trouvé ; `jsonb`
 * rend les caractères décodés.
 */
final class PersonTraceSweep
{
    /** @param list<string> $needles textes cherchés, sans guillemet, accolade, virgule ni barre oblique inverse */
    public static function sql(array $needles): string
    {
        if ([] === $needles) {
            throw new InvalidArgumentException('le balayage a besoin d\'au moins un texte à chercher');
        }
        $patterns = [];
        foreach ($needles as $needle) {
            if ('' === trim($needle) || 1 === preg_match('/["\'{},\\\\]/', $needle)) {
                throw new InvalidArgumentException(\sprintf('texte à chercher refusé par le balayage : « %s »', $needle));
            }
            $patterns[] = '"%' . $needle . '%"';
        }
        $array = '{' . implode(',', $patterns) . '}';

        return <<<SQL
            SELECT COALESCE(string_agg(c.table_name || '.' || c.column_name, ', ' ORDER BY c.table_name, c.column_name), '') AS behatval
            FROM information_schema.columns c
            JOIN information_schema.tables t ON t.table_schema = c.table_schema AND t.table_name = c.table_name AND t.table_type = 'BASE TABLE'
            WHERE c.table_schema = 'public'
              AND c.data_type IN ('text', 'character varying', 'character', 'json', 'jsonb', 'ARRAY')
              AND has_table_privilege(quote_ident(c.table_schema) || '.' || quote_ident(c.table_name), 'SELECT')
              AND (xpath('/row/n/text()', query_to_xml(format('SELECT count(*) AS n FROM %I.%I WHERE %I::%s ILIKE ANY (%L::text[])', c.table_schema, c.table_name, c.column_name, CASE WHEN c.data_type = 'json' THEN 'jsonb::text' ELSE 'text' END, '{$array}'), false, true, '')))[1]::text::int > 0
            SQL;
    }
}
