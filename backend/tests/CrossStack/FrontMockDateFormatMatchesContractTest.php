<?php

declare(strict_types=1);

namespace App\Tests\CrossStack;

use App\Tests\OpenApi\DateOnlyFieldsServeDateOnlyStringsTest;
use FilesystemIterator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use SplFileInfo;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Lot bugs Rillieux #16 (garde anti-récidive, exigence fondateur 2026-10-22) — un MOCK de test
 * front sert aux champs de date LE FORMAT QUE L'API SERT VRAIMENT, jamais l'inverse.
 *
 * Le bug « NaN/NaN/NaN » venait d'un écart de format jamais attrapé : le back servait un
 * `startDate` en RFC 3339, le mock front le servait en `Y-m-d` (ou l'inverse), si bien que le test
 * front restait VERT sur une forme que l'API n'envoie jamais — le vrai format cassait en prod. Ce
 * garde rend l'écart opposable, DANS LES DEUX SENS.
 *
 * Il NE peut PAS s'appuyer sur le snapshot OpenAPI : les champs date-seule servis en `string`
 * (`CalendarEntry`, `SchedulePlan`…) y apparaissent `{type: string}` SANS `format: date`
 * (API Platform ne l'infère pas d'une propriété PHP `string`). La source de vérité est donc le
 * TYPE PHP des DTO `src/ApiResource/*.php`, dérivé par réflexion (même régime de frontière statique
 * que {@see FrontRederivationRegistryTest} / {@see TsUnionsMatchPhpEnumsTest}) :
 *
 *   - DATE-SEULE : propriété sérialisée en lecture dont le NOM finit par `Date`/`Deadline`
 *     (`startDate`, `endDate`, `deadline`, `matchDate`, `entryDeadline`…) — servie `Y-m-d`
 *     (cf. {@see DateOnlyFieldsServeDateOnlyStringsTest}). Un mock qui lui donne
 *     un datetime (« 2026-01-01T… ») MENT.
 *   - HORODATAGE : propriété sérialisée typée `DateTimeImmutable` (`createdAt`, `updatedAt`,
 *     `reviewedAt`…) — servie RFC 3339. Un mock qui lui donne une date nue (« 2026-01-01 ») MENT.
 *
 * On ne scanne que des VALEURS LITTÉRALES de date dans les objets de test (`clé: "AAAA-MM-JJ…"`) —
 * une variable, un `expect(...)`, une chaîne non-date sont ignorés. Falsifications (prouvées au
 * brief) : `PlanningToolbar.test.tsx` servait `createdAt: "2026-01-01"` → sens HORODATAGE rougit ;
 * `campaignWeeks.test.ts` sert `startDate: "…T00:00:00+00:00"` → sens DATE-SEULE rougit (exempté
 * ci-dessous : input de ROBUSTESSE délibéré, pas un mock de forme d'API).
 *
 * Régime : groupe `contract` / job CI `engine-semantics` (required check), PAS un step
 * `blocking-tests` (aucun invariant runtime multi-tenant).
 */
#[Group('contract')]
final class FrontMockDateFormatMatchesContractTest extends TestCase
{
    private const string REPO = __DIR__ . '/../../..';

    private const string FRONT = self::REPO . '/frontend/src';

    private const string API_RESOURCE_DIR = self::REPO . '/backend/src/ApiResource';

    private const string NAMESPACE = 'App\\ApiResource\\';

    /**
     * Fichiers de test front où une valeur de date « au mauvais format » est VOULUE (chemin relatif
     * à frontend/src => raison structurelle). Nominatif, jamais en masse.
     *
     * @var array<string, string>
     */
    private const array EXEMPTIONS = [
        'features/coach-wishes/campaignWeeks.test.ts' => 'input de ROBUSTESSE : sert À DESSEIN un `startDate` RFC 3339 hérité pour prouver que `planDerivedWeeks` le normalise au lundi au lieu de dériver « NaN » (germe du bug Rillieux). Ce n\'est pas un mock de la forme d\'API servie — l\'API sert bien Y-m-d (cf. ses autres cas, lignes 18-46).',
    ];

    /**
     * Exemptions CIBLÉES (fichier → champ → raison) : un champ servi, pour UN endpoint précis, dans
     * un format que la classification par NOM ne peut pas voir — parce qu'un contrôleur ÉCRIT À LA
     * MAIN sérialise ce nom autrement que les DTO ApiResource homonymes. Le scan des mocks étant par
     * nom (sans connaître le endpoint), ces cas ne se distinguent que nommément : on exempte le
     * couple (fichier, champ), jamais tout le fichier (ses AUTRES champs restent gardés). Nominatif,
     * jamais en masse ; chaque entrée est validée par {@see testExemptionsStillExistAndAreMotivated}
     * (fichier présent, champ encore classé, raison non vide).
     *
     * @var array<string, array<string, string>>
     */
    private const array FIELD_EXEMPTIONS = [
        'features/admin/AdminDashboardPage.test.tsx' => [
            // `GET /api/admin/club-requests` n'est PAS une ApiResource : AdminClubRequestController.php:68
            // sérialise `createdAt` en Y-m-d (snapshot `format: date`), pas en RFC 3339 comme les ~10
            // DTO ApiResource homonymes (`CoachResource`, `UserResource`…) d'où le garde tire sa
            // classification HORODATAGE. Le mock `newClubRequest` sert donc la vraie forme de CE
            // endpoint — une date nue. Les AUTRES `createdAt` du fichier (clubs, RFC 3339) restent gardés.
            'createdAt' => 'GET /api/admin/club-requests servi par un contrôleur écrit à la main '
                . '(AdminClubRequestController.php:68) qui sérialise `createdAt` en Y-m-d (snapshot '
                . '`format: date`), pas en RFC 3339 comme les DTO ApiResource homonymes.',
        ],
    ];

    public function testFrontDateMocksMatchTheServedFormat(): void
    {
        $dateOnly = $this->dateOnlyFieldNames();
        $timestamp = array_values(array_diff($this->timestampFieldNames(), $dateOnly));

        self::assertNotEmpty($dateOnly, 'Aucun champ date-seule dérivé des ApiResource — la source de vérité a bougé, le garde ne garde plus rien.');
        self::assertNotEmpty($timestamp, 'Aucun champ horodatage dérivé des ApiResource — la source de vérité a bougé, le garde ne garde plus rien.');

        $dateOnlySet = array_fill_keys($dateOnly, true);
        $timestampSet = array_fill_keys($timestamp, true);
        $violations = [];

        foreach ($this->frontTestFiles() as $file) {
            $relative = $this->relative($file);
            if (isset(self::EXEMPTIONS[$relative])) {
                continue;
            }
            $lines = explode("\n", (string) file_get_contents($file->getPathname()));
            foreach ($lines as $i => $line) {
                if (0 === preg_match_all('/\b([A-Za-z]\w*)\s*:\s*["\']([^"\']*)["\']/', $line, $matches, \PREG_SET_ORDER)) {
                    continue;
                }
                foreach ($matches as $match) {
                    [$key, $value] = [$match[1], $match[2]];
                    if (isset(self::FIELD_EXEMPTIONS[$relative][$key])) {
                        continue;
                    }
                    if (isset($dateOnlySet[$key]) && $this->looksLikeDateTime($value)) {
                        $violations[] = \sprintf('%s:%d — `%s` servi `%s` (DATE-SEULE : l\'API sert Y-m-d)', $relative, $i + 1, $key, $value);
                    } elseif (isset($timestampSet[$key]) && $this->looksLikeBareDate($value)) {
                        $violations[] = \sprintf('%s:%d — `%s` servi `%s` (HORODATAGE : l\'API sert RFC 3339)', $relative, $i + 1, $key, $value);
                    }
                }
            }
        }

        self::assertSame([], $violations, \sprintf(
            "Des mocks front servent un format de date que l'API n'envoie jamais :\n  - %s\n\n"
            . "« Un mock doit envoyer ce que l'API peut envoyer » (décision fondateur). Un champ dont le\n"
            . "nom finit par Date/Deadline est servi Y-m-d ; un champ *At (DateTimeImmutable) est servi\n"
            . "RFC 3339. Corriger la VALEUR du mock (c'est du test). Si le test ROUGIT une fois corrigé,\n"
            . "c'est un vrai bug produit (le front ne sait pas lire la vraie forme) — corriger le PRODUIT.\n"
            . 'Format « faux » VOULU (input de robustesse) → self::EXEMPTIONS avec sa raison.',
            implode("\n  - ", $violations),
        ));
    }

    public function testExemptionsStillExistAndAreMotivated(): void
    {
        $stale = [];
        foreach (self::EXEMPTIONS as $relative => $reason) {
            if ('' === trim($reason)) {
                $stale[] = \sprintf('%s : exemption SANS raison', $relative);
            }
            if (!is_file(self::FRONT . '/' . $relative)) {
                $stale[] = \sprintf('%s : exempté mais le fichier n\'existe plus — retirer l\'exemption', $relative);
            }
        }

        // Exemptions par champ : fichier présent, raison non vide, ET le champ est TOUJOURS classé
        // (dans le jeu date-seule ∪ horodatage). Un champ qui n'est plus classé ne serait jamais
        // flaggé — son exemption ne garderait plus rien : la retirer (anti-rot).
        $classified = array_fill_keys(array_merge($this->dateOnlyFieldNames(), $this->timestampFieldNames()), true);
        foreach (self::FIELD_EXEMPTIONS as $relative => $fields) {
            if (!is_file(self::FRONT . '/' . $relative)) {
                $stale[] = \sprintf('%s : exempté (par champ) mais le fichier n\'existe plus — retirer l\'exemption', $relative);
            }
            foreach ($fields as $field => $reason) {
                if ('' === trim($reason)) {
                    $stale[] = \sprintf('%s/%s : exemption de champ SANS raison', $relative, $field);
                }
                if (!isset($classified[$field])) {
                    $stale[] = \sprintf('%s/%s : champ exempté mais plus classé (ni date-seule ni horodatage) — l\'exemption ne garde plus rien, la retirer', $relative, $field);
                }
            }
        }

        self::assertSame([], $stale, implode("\n  - ", $stale));
    }

    private function looksLikeDateTime(string $value): bool
    {
        return 1 === preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d/', $value);
    }

    private function looksLikeBareDate(string $value): bool
    {
        return 1 === preg_match('/^\d{4}-\d{2}-\d{2}$/', $value);
    }

    /**
     * Noms de propriétés date-seule (servies Y-m-d) dérivés des ApiResource — un nom en `Date`/
     * `Deadline`, sérialisé en lecture. Maison unique de la règle avec
     * {@see DateOnlyFieldsServeDateOnlyStringsTest}.
     *
     * @return list<string>
     */
    private function dateOnlyFieldNames(): array
    {
        $names = [];
        foreach ($this->readSerializedProperties() as $property) {
            $name = $property->getName();
            if (1 === preg_match('/at$/i', $name)) {
                continue;
            }
            if (1 === preg_match('/(?:date|deadline)$/i', $name)) {
                $names[$name] = true;
            }
        }

        return array_keys($names);
    }

    /**
     * Noms de propriétés horodatage (servies RFC 3339) : type `DateTimeImmutable`, sérialisées en
     * lecture.
     *
     * @return list<string>
     */
    private function timestampFieldNames(): array
    {
        $names = [];
        foreach ($this->readSerializedProperties() as $property) {
            $type = $property->getType();
            $typeName = $type instanceof ReflectionNamedType ? $type->getName() : '';
            if ('DateTimeImmutable' === $typeName) {
                $names[$property->getName()] = true;
            }
        }

        return array_keys($names);
    }

    /**
     * @return list<ReflectionProperty> toutes les propriétés publiques sérialisées `read` des DTO API
     */
    private function readSerializedProperties(): array
    {
        $properties = [];
        /** @var SplFileInfo $file */
        foreach (new FilesystemIterator(self::API_RESOURCE_DIR, FilesystemIterator::SKIP_DOTS) as $file) {
            if (!$file->isFile() || 'php' !== $file->getExtension()) {
                continue;
            }
            /** @var class-string $class */
            $class = self::NAMESPACE . $file->getBasename('.php');
            if (!class_exists($class)) {
                continue;
            }
            foreach (new ReflectionClass($class)->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
                foreach ($property->getAttributes(Groups::class) as $attribute) {
                    /** @var array{0?: list<string>|string} $args */
                    $args = $attribute->getArguments();
                    $groups = $args[0] ?? [];
                    if (\is_array($groups) && \in_array('read', $groups, true)) {
                        $properties[] = $property;
                        break;
                    }
                }
            }
        }

        return $properties;
    }

    /** @return list<SplFileInfo> les fichiers *.test.ts / *.test.tsx de frontend/src */
    private function frontTestFiles(): array
    {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::FRONT, FilesystemIterator::SKIP_DOTS));
        $files = [];
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $name = $file->getFilename();
            if (str_ends_with($name, '.test.ts') || str_ends_with($name, '.test.tsx')) {
                $files[] = $file;
            }
        }

        return $files;
    }

    private function relative(SplFileInfo $file): string
    {
        return str_replace(str_replace('\\', '/', self::FRONT) . '/', '', str_replace('\\', '/', $file->getPathname()));
    }
}
