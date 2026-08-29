<?php

declare(strict_types=1);

namespace App\Tests\CrossStack;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionEnum;
use ReflectionEnumBackedCase;
use UnitEnum;

/**
 * D-34/D-38 — le PREMIER test qui traverse la frontière backend ↔ frontend.
 *
 * Constat de l'inventaire (2026-08-08) : **aucun** test ne la traversait, dans aucun sens —
 * vérifié. La frontière backend↔engine a trois gardes sémantiques ; celle-ci n'en avait
 * aucun, alors que **26 unions TypeScript** doublent un enum PHP. Un cas ajouté côté serveur
 * arrive dans le navigateur, TypeScript croit son union exhaustive, et les `Record<Union,…>`
 * de libellés rendent `undefined` — sans une erreur, sans un test rouge.
 *
 * ⚠ **Ce test n'exige PAS l'égalité partout, et c'est le cœur de sa conception.**
 * `LockLevel` le prouve : PHP porte `SOFT` pour pouvoir le **REFUSER explicitement**
 * (`ManualEditController` : « use NONE or HARD » plutôt qu'un « Invalid lockLevel » générique,
 * ENG-21), et l'UI ne doit surtout pas l'offrir. Les deux côtés ont raison. Un garde qui
 * aurait exigé l'égalité aurait poussé à « corriger » l'un des deux — donc à casser soit le
 * message d'erreur, soit l'interface. **Les écarts se déclarent avec leur raison ; ils ne se
 * suppriment pas.**
 */
#[Group('contract')]
final class TsUnionsMatchPhpEnumsTest extends TestCase
{
    /**
     * enum PHP => fichier TS qui porte l'union jumelle (nom d'union = nom de l'enum).
     *
     * Publique : `FrontRederivationRegistryTest` réutilise cette liste (P4-88, la maison
     * unique des enums métier franchissant la frontière) plutôt que d'en tenir une seconde.
     *
     * @var array<string, string>
     */
    public const array MIRRORED = [
        // P4-123 — l'union descend dans shared/lib/ (résorption AUD-FRT-21) ; le chemin remonte de src/features via `../`.
        'ScheduleStatus' => '../shared/lib/scheduleStatus.ts',
        'ScheduleDiagnosticSeverity' => 'planning/api.ts',
        'SchedulePlanType' => 'planning/api.ts',
        'LockLevel' => 'planning/api.ts',
        'LockOrigin' => 'planning/api.ts',
        'CalendarEntryKind' => 'cockpit/api.ts',
        'CalendarEntryPeriodType' => 'cockpit/api.ts',
        'CalendarEntryStatus' => 'cockpit/api.ts',
        // P4-148 — l'union descend dans shared/lib/teamIdentity.ts (avec TeamLevel : les deux axes
        // d'identité FFBB d'une équipe) ; le chemin remonte de src/features via `../`.
        'Gender' => '../shared/lib/teamIdentity.ts',
        'VenuePeriodMode' => 'wizard/api.ts',
        'TeamCoachRole' => 'wizard/api.ts',
        'ConstraintFamily' => 'wizard/api.ts',
        'ConstraintScope' => 'wizard/api.ts',
        'ConstraintRuleType' => 'wizard/api.ts',
        'TeamTagAxis' => 'wizard/api.ts',
        // AUD-FRT-22 (2026-08-19) — TeamLevel manquait à cette liste alors que l'union TS
        // existait depuis longtemps dans wizard/api.ts : le niveau d'une équipe traversait
        // donc la frontière sans qu'aucun côté ne garde l'autre. C'est un enum de PÉRIMÈTRE
        // ENGAGÉ (§7.1) — une valeur ajoutée côté PHP et oubliée côté TS rendrait un niveau
        // affiché comme inconnu sur l'écran des équipes.
        // P4-148 — descendu dans shared/lib/teamIdentity.ts avec Gender (identité FFBB d'une équipe).
        'TeamLevel' => '../shared/lib/teamIdentity.ts',
        'FixtureStatus' => 'matches/api.ts',
        'TeamLinkType' => 'matches/api.ts',
        // Lot PASSERELLES PR-3 (2026-08-22) — l'intensité d'entraînement d'une passerelle
        // (PREFERRED/MANDATORY) traverse la frontière : PR-1 l'avait laissée sans union TS
        // (« aucune union requise en PR-1 ») car c'est l'écran de saisie (PR-3) qui la crée.
        'TeamLinkIntensity' => 'matches/api.ts',
    ];
    private const string FRONT = __DIR__ . '/../../../frontend/src/features';

    /**
     * Le nom de l'union TS quand il diffère de celui de l'enum PHP.
     *
     * @var array<string, string>
     */
    private const array TS_ALIASES = [
        'ScheduleDiagnosticSeverity' => 'DiagnosticSeverity',
    ];

    /**
     * Écarts VOULUS : enum PHP => [valeurs absentes côté TS => pourquoi].
     *
     * @var array<string, array<string, string>>
     */
    private const array DELIBERATE_GAPS = [
        // ENG-21 : le SOFT lock est un placebo (le solveur ne lit jamais la pénalité). PHP
        // garde le cas pour le REFUSER en le nommant — sans lui, `ManualEditController` ne
        // pourrait rendre qu'un « Invalid lockLevel » générique. L'UI, elle, ne doit pas
        // offrir un verrou sans effet : l'absence côté TS est le comportement voulu.
        'LockLevel' => ['SOFT' => 'refusé explicitement côté API (ENG-21) — l\'UI ne doit pas l\'offrir'],
    ];

    public function testEveryMirroredUnionMatchesItsPhpEnum(): void
    {
        $mismatches = [];

        foreach (self::MIRRORED as $enumName => $relativePath) {
            $php = $this->phpValues($enumName);
            $ts = $this->tsValues($relativePath, self::TS_ALIASES[$enumName] ?? $enumName);
            $allowed = self::DELIBERATE_GAPS[$enumName] ?? [];

            $missingInTs = array_values(array_diff($php, $ts, array_keys($allowed)));
            $unknownInPhp = array_values(array_diff($ts, $php));

            foreach ($missingInTs as $value) {
                $mismatches[] = \sprintf('%s : « %s » existe côté PHP, absent de l\'union TS', $enumName, $value);
            }
            foreach ($unknownInPhp as $value) {
                $mismatches[] = \sprintf('%s : « %s » existe côté TS, inconnu de l\'enum PHP', $enumName, $value);
            }
        }

        self::assertSame([], $mismatches, \sprintf(
            "Ces unions TypeScript ne reflètent plus leur enum PHP :\n  - %s\n\n"
            . "Un cas ajouté côté serveur arrive dans le navigateur sans que TypeScript le sache :\n"
            . "les Record<Union, …> de libellés rendent `undefined`, sans erreur ni test rouge.\n\n"
            . "Deux issues, jamais le silence : aligner l'union, OU déclarer l'écart dans\n"
            . 'self::DELIBERATE_GAPS avec sa raison (cf. LockLevel::SOFT, voulu des deux côtés).',
            implode("\n  - ", $mismatches),
        ));
    }

    /** @return list<string> */
    private function phpValues(string $enumName): array
    {
        /** @var class-string<UnitEnum> $class */
        $class = 'App\\Enum\\' . $enumName;
        self::assertTrue(enum_exists($class), \sprintf('L\'enum %s a disparu — ce test le référence.', $class));

        $values = array_map(
            static fn (ReflectionEnumBackedCase $case): string => (string) $case->getBackingValue(),
            new ReflectionEnum($class)->getCases(),
        );
        sort($values);

        return $values;
    }

    /** @return list<string> */
    private function tsValues(string $relativePath, string $unionName): array
    {
        $path = self::FRONT . '/' . $relativePath;
        $source = file_get_contents($path);
        self::assertIsString($source, \sprintf('Illisible : %s', $path));

        // AUD-FRT-22 — `=\s*` et non `= ` : une union assez longue est écrite sur PLUSIEURS lignes
        // (`export type TeamLevel =\n  | "ELITE"…`), et l'ancienne regex, qui exigeait une espace
        // juste après le `=`, ne la voyait pas. Le garde lit le code tel qu'il est écrit ; il
        // n'impose pas un formatage au fichier qu'il surveille.
        $found = preg_match(\sprintf('/export type %s =\s*([^;]+);/', preg_quote($unionName, '/')), $source, $matches);
        self::assertSame(1, $found, \sprintf(
            'L\'union `%s` a disparu de %s — si elle a été renommée, mettre à jour self::MIRRORED plutôt que de retirer la surveillance.',
            $unionName,
            $relativePath,
        ));

        preg_match_all('/"([^"]+)"/', $matches[1], $values);
        $union = $values[1];
        sort($union);

        self::assertNotEmpty($union, \sprintf('L\'union `%s` ne porte plus aucune valeur littérale.', $unionName));

        return $union;
    }
}
