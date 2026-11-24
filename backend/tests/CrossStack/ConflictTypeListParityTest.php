<?php

declare(strict_types=1);

namespace App\Tests\CrossStack;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use App\Matches\ConflictType;
use FilesystemIterator;
use PHPUnit\Framework\Attributes\Group;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Promesse (lot 7, PR 2) : la liste des types de conflit du radar existe UNE fois, côté
 * serveur — l'énumération `App\Matches\ConflictType` — et tout ce qui la recopie dit la même
 * liste : l'union TypeScript `ConflictType` (`frontend/src/features/matches/api/conflicts.ts`),
 * les clés de `CONFLICT_FAMILY_LABEL` (`features/matches/lib/conflictLabels.ts`, une chip par
 * type), la documentation de `GET /api/fixtures/conflicts` (contrat vivant ET
 * `specs/courantes/openapi-snapshot.json`, dont le front tire ses types).
 *
 * Avant la PR 2, quatorze types vivaient en chaînes recopiées et la documentation de l'API
 * n'en listait que douze (`MATCH_COACH_UNAVAILABLE` et `TEAM_LEVEL_MISSING` manquaient) : un
 * type ajouté côté serveur arrivait dans le navigateur sans libellé, sans qu'un test rougisse.
 *
 * Pourquoi pas une ligne de `TsUnionsMatchPhpEnumsTest::MIRRORED` : ce garde-là ne lit que
 * `App\Enum\*` et ne regarde ni les libellés ni l'OpenAPI. Le libellé lui-même est gardé par
 * `frontend/src/features/matches/lib/conflictLabels.test.ts` (Vitest) ; ici seule la LISTE
 * est comparée.
 *
 * Le second test garde l'autre moitié de la promesse : aucun fichier de `backend/src` ne
 * réécrit un type de conflit en chaîne littérale ; émetteurs, empreinte, table des statuts
 * et documentation lisent l'énumération. Trois lecteurs, laissés hors de la PR 2 par le plan,
 * sont nommés un par un dans {@see self::READERS_LEFT_OUT} ; une entrée qui ne recopie plus
 * rien rougit, pour que la liste ne garde pas une porte ouverte.
 *
 * Régime : garde de frontière statique, groupe `contract` (job « Engine semantics »), comme
 * `TsUnionsMatchPhpEnumsTest` — pas un step `blocking-tests` (aucun axe §7.1).
 */
#[Group('contract')]
final class ConflictTypeListParityTest extends KernelTestCase
{
    private const string FRONT = __DIR__ . '/../../../frontend/src/features/matches';

    private const string SNAPSHOT = __DIR__ . '/../../../specs/courantes/openapi-snapshot.json';

    private const string SRC = __DIR__ . '/../../src';

    private const string ENUM_FILE = 'Matches/ConflictType.php';

    private const string CONFLICTS_PATH = '/api/fixtures/conflicts';

    /**
     * Lecteurs qui comparent encore un type à une chaîne, hors du périmètre de la PR 2
     * (plan `specs/evolution/lot-conception-7-les-matchs.md` § PR 2 : « émetteurs, empreinte,
     * table des statuts et documentation de l'API »). Chemin relatif à `backend/src` => raison.
     *
     * @var array<string, string>
     */
    private const array READERS_LEFT_OUT = [
        'Service/ConflictCoachMailBuilder.php' => 'lecteur : titre du courriel par famille de personne et branche « coach indisponible » — hors PR 2',
        'Service/ConflictCoachMailing.php' => 'lecteur : `MAILED_FAMILIES` et branches par famille du courriel aux coachs — hors PR 2',
        'Service/ConflictFeedAssembler.php' => 'lecteur : côtés à éclater par famille de personne — hors PR 2',
    ];

    public function testEveryCopyOfTheConflictTypeListMatchesTheEnum(): void
    {
        $server = array_map(static fn (ConflictType $type): string => $type->value, ConflictType::cases());
        sort($server);

        $copies = [
            'union TypeScript `ConflictType` (features/matches/api/conflicts.ts)' => $this->tsUnion(),
            'clés de `CONFLICT_FAMILY_LABEL` (features/matches/lib/conflictLabels.ts)' => $this->labelKeys(),
            'OpenAPI vivant de GET /api/fixtures/conflicts' => $this->liveOpenApiEnum(),
            'specs/courantes/openapi-snapshot.json, GET /api/fixtures/conflicts' => $this->snapshotEnum(),
        ];

        $mismatches = [];
        foreach ($copies as $where => $values) {
            foreach (array_diff($server, $values) as $missing) {
                $mismatches[] = \sprintf('%s : « %s » manque', $where, $missing);
            }
            foreach (array_diff($values, $server) as $unknown) {
                $mismatches[] = \sprintf('%s : « %s » est inconnu de App\Matches\ConflictType', $where, $unknown);
            }
        }

        self::assertSame([], $mismatches, \sprintf(
            "La liste des types de conflit n'est plus la même partout :\n  - %s\n\n"
            . "L'énumération App\\Matches\\ConflictType fait foi. Aligner la copie (l'OpenAPI lit l'énumération ;\n"
            . 'le snapshot se régénère par `php bin/console api:openapi:export --output=/app/specs/courantes/openapi-snapshot.json`).',
            implode("\n  - ", $mismatches),
        ));
    }

    /**
     * Les lignes `held` (tenues par un autre coach) ne portent que des familles de personne :
     * un sous-ensemble de l'énumération, jamais un type qu'elle ignore.
     */
    public function testHeldLinesOnlyCarryKnownConflictTypes(): void
    {
        $server = array_map(static fn (ConflictType $type): string => $type->value, ConflictType::cases());

        foreach (['vivant' => $this->liveSchema(), 'snapshot' => $this->snapshotSchema()] as $where => $schema) {
            $held = $schema['properties']['held']['items']['properties']['type']['enum'] ?? null;
            self::assertIsArray($held, \sprintf('OpenAPI %s : `held[].type` ne documente plus son enum.', $where));
            self::assertNotSame([], $held);
            self::assertSame([], array_values(array_diff($held, $server)), \sprintf(
                'OpenAPI %s : `held[].type` annonce un type que App\Matches\ConflictType ignore.',
                $where,
            ));
        }
    }

    public function testNoServerFileSpellsAConflictTypeAsAString(): void
    {
        $values = array_map(static fn (ConflictType $type): string => preg_quote($type->value, '/'), ConflictType::cases());
        $pattern = \sprintf('/([\'"])(%s)\1/', implode('|', $values));

        $copies = [];
        $readersStillSpelling = [];
        foreach ($this->serverSourceFiles() as $relative => $source) {
            if (self::ENUM_FILE === $relative) {
                continue;
            }
            $count = preg_match_all($pattern, $source, $hits);
            if (0 === $count) {
                continue;
            }
            if (isset(self::READERS_LEFT_OUT[$relative])) {
                $readersStillSpelling[] = $relative;

                continue;
            }
            $copies[] = \sprintf('%s (%s)', $relative, implode(', ', array_values(array_unique($hits[2]))));
        }

        self::assertSame([], $copies, \sprintf(
            "Ces fichiers du serveur recopient un type de conflit en chaîne :\n  - %s\n\n"
            . 'Écrire `ConflictType::X` (ou `->value` dans un tableau sérialisé) : la liste existe une fois.',
            implode("\n  - ", $copies),
        ));

        $stale = array_values(array_diff(array_keys(self::READERS_LEFT_OUT), $readersStillSpelling));
        self::assertSame([], $stale, \sprintf(
            "Ces lecteurs ne recopient plus aucun type — retirer leur entrée de self::READERS_LEFT_OUT :\n  - %s",
            implode("\n  - ", $stale),
        ));
    }

    /** @return list<string> */
    private function tsUnion(): array
    {
        $source = $this->read(self::FRONT . '/api/conflicts.ts');
        $withoutComments = (string) preg_replace('#//[^\n]*#', '', $source);
        self::assertSame(1, preg_match('/export type ConflictType =\s*([^;]+);/', $withoutComments, $match), 'L\'union `ConflictType` a disparu de features/matches/api/conflicts.ts.');
        preg_match_all('/"([^"]+)"/', $match[1], $values);

        return $this->sorted($values[1]);
    }

    /** @return list<string> */
    private function labelKeys(): array
    {
        $source = $this->read(self::FRONT . '/lib/conflictLabels.ts');
        self::assertSame(1, preg_match('/CONFLICT_FAMILY_LABEL\s*:\s*Record<ConflictType,\s*string>\s*=\s*\{([^}]*)\}/', $source, $match), 'La table `CONFLICT_FAMILY_LABEL` a disparu de features/matches/lib/conflictLabels.ts.');
        preg_match_all('/^\s*([A-Z_]+)\s*:/m', $match[1], $keys);

        return $this->sorted($keys[1]);
    }

    /** @return list<string> */
    private function liveOpenApiEnum(): array
    {
        $enum = $this->liveSchema()['properties']['conflicts']['items']['properties']['type']['enum'] ?? null;
        self::assertIsArray($enum, 'OpenAPI vivant : `conflicts[].type` ne documente plus son enum.');

        return $this->sorted($enum);
    }

    /** @return list<string> */
    private function snapshotEnum(): array
    {
        $enum = $this->snapshotSchema()['properties']['conflicts']['items']['properties']['type']['enum'] ?? null;
        self::assertIsArray($enum, 'Snapshot OpenAPI : `conflicts[].type` ne documente plus son enum.');

        return $this->sorted($enum);
    }

    /** @return array<string, mixed> */
    private function liveSchema(): array
    {
        self::bootKernel();
        $factory = self::getContainer()->get(OpenApiFactoryInterface::class);
        \assert($factory instanceof OpenApiFactoryInterface);

        $operation = $factory()->getPaths()->getPath(self::CONFLICTS_PATH)?->getGet();
        self::assertNotNull($operation, 'GET /api/fixtures/conflicts n\'est plus documenté.');
        $ok = ($operation->getResponses() ?? [])['200'] ?? null;
        self::assertNotNull($ok);

        /** @var array<string, mixed> $schema */
        $schema = json_decode(json_encode($ok->getContent()['application/json']['schema'] ?? null, \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR);

        return $schema;
    }

    /** @return array<string, mixed> */
    private function snapshotSchema(): array
    {
        /** @var array<string, mixed> $snapshot */
        $snapshot = json_decode($this->read(self::SNAPSHOT), true, 512, \JSON_THROW_ON_ERROR);
        /** @var array<string, mixed>|null $schema */
        $schema = $snapshot['paths'][self::CONFLICTS_PATH]['get']['responses']['200']['content']['application/json']['schema'] ?? null;
        self::assertIsArray($schema, 'Le snapshot ne documente plus GET /api/fixtures/conflicts.');

        return $schema;
    }

    /** @return array<string, string> chemin relatif à backend/src => source */
    private function serverSourceFiles(): array
    {
        $root = (string) realpath(self::SRC);
        $files = [];
        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
            if (!$file->isFile() || 'php' !== $file->getExtension()) {
                continue;
            }
            $files[str_replace($root . '/', '', $file->getPathname())] = $this->read($file->getPathname());
        }
        self::assertNotSame([], $files, 'Aucun fichier PHP lu sous backend/src : le chemin du garde est faux.');

        return $files;
    }

    private function read(string $path): string
    {
        $source = file_get_contents($path);
        self::assertIsString($source, \sprintf('Illisible : %s', $path));

        return $source;
    }

    /**
     * @param array<mixed> $values
     *
     * @return list<string>
     */
    private function sorted(array $values): array
    {
        $strings = array_values(array_map(strval(...), $values));
        sort($strings);

        return $strings;
    }
}
