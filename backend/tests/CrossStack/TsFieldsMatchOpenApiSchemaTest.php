<?php

declare(strict_types=1);

namespace App\Tests\CrossStack;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * FRT-28 — le garde de dérive de CHAMPS entre une interface TypeScript et son schéma OpenAPI.
 *
 * Le front n'a **aucun codegen** : ~3 200 lignes de types API écrites à la main. Deux gardes
 * cross-stack existaient déjà, et tous deux ratent la dérive de CHAMP :
 *   - `OpenApiSnapshotMatchesTheLiveContractTest` compare le snapshot au contrat vivant, sans
 *     jamais regarder le TypeScript (« il dit que le contrat a bougé, pas que le front l'a raté ») ;
 *   - `TsUnionsMatchPhpEnumsTest` compare les UNIONS TS aux enums PHP, mais rien d'autre.
 * Entre les deux, un renommage de champ côté serveur, une suppression, ou un champ retypé en
 * `string` nu là où le schéma porte un enum, passent invisibles — jusqu'à l'écran.
 *
 * Preuve que ça mord déjà : `Team` est écrit trois fois côté front (wizard, matches, planning) et
 * la copie de `matches/api.ts` type `level`/`gender` en `string | null` là où le schéma porte un
 * enum — hors de portée du garde d'unions, qui ne regarde pas les interfaces.
 *
 * ── Le contrat de ce garde (SENS UNIQUE, comme sa cousine ne l'était pas) ──────────────────────
 *
 * Source de vérité : `specs/courantes/openapi-snapshot.json`, déjà un artefact gardé. Pour chaque
 * paire déclarée (interface TS ↔ schéma OpenAPI), on vérifie DEUX choses, et pas une troisième :
 *
 *  1. **Existence, dans un seul sens.** Tout champ que le TS déclare DOIT exister dans le schéma.
 *     C'est ce qui attrape un renommage ou une suppression côté serveur. L'inverse n'est PAS
 *     vérifié : un champ du schéma absent du TS est un choix du front (il ignore ce dont il n'a
 *     pas besoin — `planning/Team` ne lit que 6 des 17 propriétés du schéma `Team`), pas une
 *     dérive. Un garde qui exigerait la symétrie serait inutilisable et se ferait désarmer.
 *
 *  2. **L'enum perdu.** Si le champ porte un `enum` côté schéma, le champ TS ne doit pas être un
 *     `string` nu. C'est exactement la dérive démontrée sur `matches/api.ts`. Un renommage
 *     d'enum côté serveur, lui, est déjà gardé par `TsUnionsMatchPhpEnumsTest` — ici on ne
 *     regarde que « le front a-t-il abandonné le type nommé au profit d'un string ».
 *
 * Ce qu'on ne vérifie PAS en v1, sciemment : **l'optionalité**. Le snapshot PORTE bien des tableaux
 * `required` (34 schémas) — mais **33 d'entre eux sont les schémas `*Input`**, côté ÉCRITURE ; le
 * 34e est `HydraItemBaseSchema`. Les schémas de LECTURE que le front consomme (`Team`,
 * `VenueTravelTime`…) n'en portent aucun : API Platform leur donne un `default` par propriété, et
 * les réponses des routes custom (`CustomRoutesOpenApiFactory`) sont écrites à la main sans
 * `required`. Assertir « un champ non-optionnel côté TS doit être `required` côté schéma » sur ces
 * schémas-là ferait donc rougir CHAQUE champ : bruit total, garde désarmé.
 *
 * ⚑ La porte de sortie, si le besoin vient : les schémas `*Input` SONT exploitables. Un garde
 * d'optionalité a du sens sur les corps que le front POSTe/PUTe (paire TS ↔ `X.XInput`), pas sur
 * ce qu'il LIT. C'est une extension de ce fichier, pas un second garde.
 *
 * ⚠ **Les écarts se DÉCLARENT, ils ne se suppriment pas** (leçon de `TsUnionsMatchPhpEnumsTest`).
 * Un enum perdu qu'on ne peut pas corriger sans toucher à un écran consommateur se déclare dans
 * `DECLARED_ENUM_DRIFTS` avec sa raison — jamais en retirant la paire. Une exemption sans raison
 * ne peut pas exister (gardé par `testEveryDeclaredDriftCarriesAReason`).
 *
 * Amorçage volontairement étroit : les 4 APIs de FRT-28 + les 3 `Team` (la dérive démontrée). Le
 * garde vaut par son extensibilité paire par paire, pas par sa couverture initiale.
 */
#[Group('contract')]
final class TsFieldsMatchOpenApiSchemaTest extends TestCase
{
    /**
     * Les paires surveillées : interface TS ↔ schéma OpenAPI. Publique et extensible — ajouter une
     * paire, c'est ajouter une entrée ici, rien d'autre.
     *
     * Chaque entrée porte le fichier TS (`ts`, relatif à `frontend/src/features/`), le nom de
     * l'interface (`interface`), et LA source du schéma, l'une ou l'autre :
     *   - `schema` : un schéma nommé de `components.schemas` (les entités API Platform) ;
     *   - `response` : `[MÉTHODE, chemin]`, pour une route custom dont le corps 200 est écrit à la
     *     main inline dans `paths` (`CustomRoutesOpenApiFactory`).
     *
     * @var array<string, array<string, mixed>>
     */
    public const array PAIRS = [
        // Les trois copies de Team écrites à la main : c'est ici que vit la dérive démontrée.
        'wizard/Team' => ['ts' => 'wizard/api.ts', 'interface' => 'Team', 'schema' => 'Team'],
        'matches/Team' => ['ts' => 'matches/api.ts', 'interface' => 'Team', 'schema' => 'Team'],
        'planning/Team' => ['ts' => 'planning/api.ts', 'interface' => 'Team', 'schema' => 'Team'],
        // Les 4 APIs de FRT-28.
        'wizard/VenueTravelTime' => ['ts' => 'wizard/api.ts', 'interface' => 'VenueTravelTime', 'schema' => 'VenueTravelTime'],
        'matches/DeadlineOutlook' => ['ts' => 'matches/api.ts', 'interface' => 'DeadlineOutlook', 'response' => ['GET', '/api/matches/deadline-outlook']],
        'planning/ValidateImpact' => ['ts' => 'planning/api.ts', 'interface' => 'ValidateImpact', 'response' => ['GET', '/api/schedules/{id}/validate-impact']],
        'matches/FfbbRencontresResult' => ['ts' => 'matches/api.ts', 'interface' => 'FfbbRencontresResult', 'response' => ['GET', '/api/ffbb/rencontres']],
    ];
    private const string SNAPSHOT = __DIR__ . '/../../../specs/courantes/openapi-snapshot.json';
    private const string FRONT = __DIR__ . '/../../../frontend/src/features';

    /**
     * Enums perdus DÉCLARÉS : « paire.champ » => pourquoi le front reste sur un `string` nu.
     * La raison est OBLIGATOIRE (gardée). Ces entrées suppriment le SEUL finding d'enum perdu du
     * champ ; l'existence du champ, elle, reste vérifiée (jamais exemptable — c'est le cœur).
     *
     * @var array<string, string>
     */
    private const array DECLARED_ENUM_DRIFTS = [
        // P4-148 (2026-08-29) — les deux écarts FRT-22 (`matches/Team.level`/`.gender` en
        // `string | null` là où le schéma porte un enum) sont RÉSORBÉS : le front les retype sur
        // `TeamLevel | null`/`Gender | null` (descendus dans shared/lib/teamIdentity.ts). Ce garde
        // reste vert SANS exemption — il est devenu le test d'acceptation du correctif. La carte
        // reste ici, vide, pour la porte de sortie décrite dans le docblock (un écart futur se
        // déclare, il ne se supprime pas).
    ];

    public function testEveryDeclaredFieldExistsInItsSchema(): void
    {
        $missing = [];

        foreach (self::PAIRS as $pairKey => $pair) {
            $properties = $this->schemaProperties($pair);
            foreach ($this->tsFields($pair) as $field) {
                if (!\array_key_exists($field['name'], $properties)) {
                    $missing[] = \sprintf('%s : le champ TS « %s » n\'existe pas (ou plus) dans le schéma OpenAPI', $pairKey, $field['name']);
                }
            }
        }

        self::assertSame([], $missing, \sprintf(
            "Des champs déclarés côté TypeScript n'existent pas dans le schéma OpenAPI :\n  - %s\n\n"
            . "C'est un renommage ou une suppression côté serveur. TypeScript ne peut pas le voir :\n"
            . "il vérifie que le code respecte l'interface écrite à la main, jamais que cette\n"
            . "interface décrit l'API. Le champ devient `undefined` en silence, jusqu'à l'écran.\n"
            . 'Corriger : aligner le champ TS sur le schéma, OU (si le champ a bougé de nom) le renommer côté front.',
            implode("\n  - ", $missing),
        ));
    }

    public function testNoDeclaredFieldDroppedAnEnumForABareString(): void
    {
        $drifts = [];

        foreach (self::PAIRS as $pairKey => $pair) {
            $properties = $this->schemaProperties($pair);
            foreach ($this->tsFields($pair) as $field) {
                $property = $properties[$field['name']] ?? null;
                if (!\is_array($property) || !isset($property['enum'])) {
                    continue; // le schéma ne contraint pas ce champ à un enum : rien à garder ici
                }
                if (!$this->isBareStringType($field['type'])) {
                    continue; // le front porte un type nommé : c'est ce qu'on veut
                }
                if (isset(self::DECLARED_ENUM_DRIFTS[$pairKey . '.' . $field['name']])) {
                    continue; // écart déclaré avec sa raison
                }
                $drifts[] = \sprintf('%s : le champ « %s » est `string` nu côté TS alors que le schéma le contraint à un enum', $pairKey, $field['name']);
            }
        }

        self::assertSame([], $drifts, \sprintf(
            "Des champs contraints à un enum côté serveur sont typés `string` nu côté TypeScript :\n  - %s\n\n"
            . "Le front perd l'exhaustivité que le serveur garantit : une valeur retirée côté PHP\n"
            . "reste acceptée par le typecheck, et les `Record<Union, …>` de libellés rendent\n"
            . "`undefined` sans erreur.\n\n"
            . "Deux issues, jamais le silence : retyper le champ TS sur son union nommée, OU déclarer\n"
            . 'l\'écart dans self::DECLARED_ENUM_DRIFTS avec sa raison (cf. les deux champs FRT-22).',
            implode("\n  - ", $drifts),
        ));
    }

    public function testEveryDeclaredDriftCarriesAReason(): void
    {
        // La carte peut être vide (P4-148 a résorbé les deux écarts d'amorçage) : on garde une
        // assertion toujours exécutée pour que le test reste MEANINGFUL, et on vérifie chaque
        // raison quand il en existe.
        self::assertContainsOnly('string', self::DECLARED_ENUM_DRIFTS);

        foreach (self::DECLARED_ENUM_DRIFTS as $key => $reason) {
            self::assertNotSame('', trim($reason), \sprintf(
                'L\'écart déclaré « %s » n\'a pas de raison. Un écart se déclare AVEC son pourquoi, ou pas du tout.',
                $key,
            ));
        }
    }

    /**
     * Les propriétés du schéma OpenAPI de la paire : nom => définition. Résout soit un schéma nommé
     * de `components.schemas`, soit le corps 200 inline d'une route custom.
     *
     * @param array<string, mixed> $pair
     *
     * @return array<string, mixed>
     */
    private function schemaProperties(array $pair): array
    {
        $snapshot = $this->snapshot();

        if (isset($pair['schema'])) {
            $name = $pair['schema'];
            self::assertIsString($name);
            /** @var array<string, mixed> $schemas */
            $schemas = $snapshot['components']['schemas'] ?? [];
            self::assertArrayHasKey($name, $schemas, \sprintf('Schéma « %s » absent du snapshot — renommé ? Mettre à jour self::PAIRS.', $name));
            $schema = $schemas[$name];
        } else {
            self::assertArrayHasKey('response', $pair);
            $response = $pair['response'];
            self::assertIsArray($response);
            [$method, $path] = [$response[0], $response[1]];
            self::assertIsString($method);
            self::assertIsString($path);
            /** @var array<string, mixed> $paths */
            $paths = $snapshot['paths'] ?? [];
            self::assertArrayHasKey($path, $paths, \sprintf('Route « %s » absente du snapshot — Mettre à jour self::PAIRS.', $path));
            $operation = $paths[$path][strtolower($method)] ?? null;
            self::assertIsArray($operation, \sprintf('Opération %s %s absente du snapshot.', $method, $path));
            $schema = $operation['responses']['200']['content']['application/json']['schema'] ?? null;
        }

        self::assertIsArray($schema);
        $properties = $schema['properties'] ?? null;
        self::assertIsArray($properties, 'Le schéma résolu n\'a pas de bloc `properties`.');

        return $properties;
    }

    /**
     * Les champs de l'interface TS de la paire : nom, optionalité, type brut (tel qu'écrit).
     *
     * @param array<string, mixed> $pair
     *
     * @return list<array{name: string, optional: bool, type: string}>
     */
    private function tsFields(array $pair): array
    {
        $relativePath = $pair['ts'];
        $interface = $pair['interface'];
        self::assertIsString($relativePath);
        self::assertIsString($interface);

        $path = self::FRONT . '/' . $relativePath;
        $source = file_get_contents($path);
        self::assertIsString($source, \sprintf('Illisible : %s', $path));

        $body = $this->interfaceBody($source, $interface, $relativePath);

        $fields = [];
        foreach (explode("\n", $body) as $line) {
            $trimmed = trim($line);
            if ('' === $trimmed || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '/*')) {
                continue; // ligne vide ou commentaire (JSDoc compris)
            }
            if (1 !== preg_match('/^(\w+)(\??)\s*:\s*(.+?);\s*$/', $trimmed, $m)) {
                continue; // pas une déclaration de champ simple
            }
            $fields[] = ['name' => $m[1], 'optional' => '?' === $m[2], 'type' => trim($m[3])];
        }

        self::assertNotEmpty($fields, \sprintf('L\'interface `%s` de %s ne porte aucun champ lisible — renommée ?', $interface, $relativePath));

        return $fields;
    }

    /**
     * Le corps `{ … }` d'une interface TS, par comptage d'accolades depuis son ouverture.
     */
    private function interfaceBody(string $source, string $interface, string $relativePath): string
    {
        $found = preg_match(\sprintf('/export interface %s\b[^{]*\{/', preg_quote($interface, '/')), $source, $m, \PREG_OFFSET_CAPTURE);
        self::assertSame(1, $found, \sprintf(
            'L\'interface `%s` a disparu de %s — si elle a été renommée, mettre à jour self::PAIRS plutôt que de retirer la surveillance.',
            $interface,
            $relativePath,
        ));

        $open = (int) $m[0][1] + \strlen($m[0][0]) - 1; // position de l'accolade ouvrante
        $depth = 0;
        $length = \strlen($source);
        for ($i = $open; $i < $length; ++$i) {
            $char = $source[$i];
            if ('{' === $char) {
                ++$depth;
            } elseif ('}' === $char) {
                --$depth;
                if (0 === $depth) {
                    return substr($source, $open + 1, $i - $open - 1);
                }
            }
        }

        self::fail(\sprintf('Accolade fermante introuvable pour l\'interface `%s` dans %s.', $interface, $relativePath));
    }

    /**
     * Le type TS est-il un `string` nu ? On retire `| null` / `| undefined` et on cherche un membre
     * d'union exactement égal à `string` (`TeamLevel | null` ne l'est pas ; `string | null` l'est).
     */
    private function isBareStringType(string $type): bool
    {
        foreach (explode('|', $type) as $part) {
            $part = trim($part);
            if ('null' === $part || 'undefined' === $part) {
                continue;
            }
            if ('string' === $part) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    private function snapshot(): array
    {
        $raw = file_get_contents(self::SNAPSHOT);
        self::assertIsString($raw, \sprintf('Illisible : %s', self::SNAPSHOT));

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
