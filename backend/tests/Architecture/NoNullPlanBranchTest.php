<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\Tests\Integration\Doctrine\NullPlanTripwireTest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Promesse : depuis que le socle a son plan (lot 4, PR 2), aucune donnée de planning ne se cherche ni ne
 * s'écrit sur « plan nul » dans `backend/src` — une colonne obligatoire cherchée à nul rend une liste vide,
 * sans erreur, et le planning reste plausible en devenant faux.
 *
 * Le balayage lit le code (commentaires retirés) et refuse quatre formes, vérifiées dans le code d'avant :
 *  - `valeur nulle` : une valeur qui contient `null`, même conditionnelle (`$period ? … : null`), affectée à
 *    une clé `schedulePlanId` ou `schedule_plan_id` (`=>` ou `[…] =`) ;
 *  - `IS NULL` : un `IS NULL` ou `IS NOT NULL` sur `schedulePlanId` ou `schedule_plan_id` dans une chaîne ;
 *  - `ancre nulle` : une instruction qui pose ou compare à nul l'ancre rendue par `StructureAnchor::of(…)`
 *    (`sprintf('e.%s IS NULL', StructureAnchor::of(…))`, `$criteria[StructureAnchor::of(…)] = null`) ;
 *  - `déclaration nullable` : un paramètre ou une propriété `?string $schedulePlanId` / `?string $planId`
 *    (ou `string|null`), un accesseur `getSchedulePlanId(): ?string`.
 *
 * Exceptions, chacune pour une raison de structure :
 *  - `Plans/SoclePlan.php`, la frontière : c'est elle qui traduit « null » de l'API en plan de saison ;
 *  - `ApiResource/` et `Dto/`, pour la seule déclaration nullable : l'API garde `schedulePlanId: null` pour
 *    le socle jusqu'au lot 6 (écart E2 du plan du lot 4), et une cible de désérialisation reste nullable
 *    pour que le validateur réponde 422 ;
 *  - `Entity/Schedule.php`, pour le seul `IS NOT NULL` : la clause d'un index partiel de la table des
 *    versions (colonne déjà obligatoire), du schéma, jamais une recherche.
 *
 * Limite écrite (plan, limite 6) : le balayage ne voit que les formes qu'il nomme ; une variable mise à nul
 * puis passée à une recherche est vue par le fil-piège ({@see NullPlanTripwireTest}),
 * et les comparaisons à nul restantes par PHPStan (accesseurs typés `string`).
 * Les deux derniers cas prouvent que chaque forme est vue (un motif à zéro ne prouve rien tant qu'il n'a pas
 * matché un cas positif) et qu'une forme propre passe.
 */
#[Group('phase1')]
final class NoNullPlanBranchTest extends TestCase
{
    private const string SRC = __DIR__ . '/../../src';

    private const string FRONTIER = 'Plans/SoclePlan.php';

    /** @var list<string> */
    private const array API_FRONTIER_DIRECTORIES = ['ApiResource/', 'Dto/'];

    private const string PARTIAL_INDEX_OWNER = 'Entity/Schedule.php';

    private const string NULL_VALUE = 'valeur nulle';

    private const string IS_NULL = 'IS NULL';

    private const string NULL_ANCHOR = 'ancre nulle';

    private const string NULLABLE_DECLARATION = 'déclaration nullable';

    private const array PLAN_KEYS = ['schedulePlanId', 'schedule_plan_id'];

    private const string IS_NULL_PATTERN = '/\b(?:schedulePlanId|schedule_plan_id)\s+IS\s+(?:NOT\s+)?NULL\b/i';

    private const string ANY_IS_NULL_PATTERN = '/\bIS\s+(?:NOT\s+)?NULL\b/i';

    private const string NULLABLE_PARAMETER_PATTERN = '/(?:\?\s*string|\bstring\s*\|\s*null|\bnull\s*\|\s*string)\s+&?\s*(?:\.\.\.)?\$(?:schedulePlanId|planId)\b/i';

    private const string NULLABLE_ACCESSOR_PATTERN = '/\bfunction\s+getSchedulePlanId\s*\(\s*\)\s*:\s*(?:\?\s*string|string\s*\|\s*null|null\s*\|\s*string)/i';

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function forbiddenForms(): iterable
    {
        yield 'clé à nul dans un critère' => ['$repo->findBy([\'clubId\' => $c, \'schedulePlanId\' => null]);', self::NULL_VALUE];
        yield 'clé à nul conditionnelle' => ['$criteria = [\'schedulePlanId\' => $period ? $schedule->getSchedulePlanId() : null, \'x\' => 1];', self::NULL_VALUE];
        yield 'clé à nul par repli' => ['$criteria = [\'schedulePlanId\' => $planId ?? null];', self::NULL_VALUE];
        yield 'clé SQL à nul' => ['$connection->insert(\'reservation\', [\'id\' => $id, \'schedule_plan_id\' => null]);', self::NULL_VALUE];
        yield 'clé affectée à nul' => ['$criteria[\'schedulePlanId\'] = null;', self::NULL_VALUE];
        yield 'DQL IS NULL' => ['$qb->andWhere(\'e.schedulePlanId IS NULL\');', self::IS_NULL];
        yield 'SQL IS NOT NULL' => ['$sql = \'SELECT id FROM venue_training_slot WHERE schedule_plan_id IS NOT NULL\';', self::IS_NULL];
        yield 'IS NULL conditionnel' => ['$qb->andWhere(null === $planId ? \'e.schedulePlanId IS NULL\' : \'e.schedulePlanId = :planId\');', self::IS_NULL];
        yield 'IS NULL dans un heredoc' => ["\$sql = <<<SQL\n  SELECT 1 FROM reservation r WHERE r.schedule_plan_id IS NULL AND r.club_id = {\$club}\n  SQL;", self::IS_NULL];
        yield 'ancre fabriquée' => ['$qb->andWhere(\\sprintf(\'e.%s IS NULL\', StructureAnchor::of($entityClass)));', self::NULL_ANCHOR];
        yield 'ancre posée à nul' => ["if (\$scoped) {\n    \$criteria[StructureAnchor::of(\$entityClass)] = null;\n}", self::NULL_ANCHOR];
        yield 'paramètre nullable' => ['function scope(string $clubId, ?string $schedulePlanId): void {}', self::NULLABLE_DECLARATION];
        yield 'paramètre nullable par défaut' => ['function scope(?string $planId = null): void {}', self::NULLABLE_DECLARATION];
        yield 'paramètre en union' => ['function scope(string|null $planId): void {}', self::NULLABLE_DECLARATION];
        yield 'propriété promue nullable' => ['final class X { public function __construct(private readonly ?string $schedulePlanId) {} }', self::NULLABLE_DECLARATION];
        yield 'propriété nullable' => ['final class X { private ?string $schedulePlanId = null; }', self::NULLABLE_DECLARATION];
        yield 'accesseur nullable' => ['final class X { public function getSchedulePlanId(): ?string { return null; } }', self::NULLABLE_DECLARATION];
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function allowedForms(): iterable
    {
        yield 'critère sur le plan' => ['$repo->findBy([\'clubId\' => $c, \'schedulePlanId\' => SoclePlan::idOf($seasonId)]);', 'Service/Exemple.php'];
        yield 'nul sur une autre clé' => ['$repo->findBy([\'schedulePlanId\' => $planId, \'groupLabel\' => null]);', 'Service/Exemple.php'];
        yield 'nul après la clé' => ['$a = [\'schedulePlanId\' => $planId, \'calendarEntryId\' => null];', 'Service/Exemple.php'];
        yield 'IS NULL sur une autre colonne' => ['$qb->andWhere(\'c.calendarEntryId IS NULL\');', 'Service/Exemple.php'];
        yield 'plan non nul' => ['function scope(string $planId): void {}', 'Service/Exemple.php'];
        yield 'accesseur obligatoire' => ['final class X { public function getSchedulePlanId(): string { return $this->p; } }', 'Service/Exemple.php'];
        yield 'commentaire' => ["// l'ancien socle : schedulePlanId IS NULL, ?string \$planId\n\$x = 1;", 'Service/Exemple.php'];
        yield 'entrée de l\'API' => ['final class XInput { public ?string $schedulePlanId = null; }', 'Dto/XInput.php'];
        yield 'sortie de l\'API' => ['final class XResource { public ?string $schedulePlanId = null; }', 'ApiResource/XResource.php'];
        yield 'la frontière' => ['final class SoclePlan { public static function toApi(string $planId, string $seasonId): ?string { return null; } private function x(): void { $a = [\'schedulePlanId\' => null]; } }', 'Plans/SoclePlan.php'];
        yield 'index partiel des versions' => ["#[ORM\\UniqueConstraint(name: 'u', columns: ['schedule_plan_id', 'version_number'], options: ['where' => '(schedule_plan_id IS NOT NULL AND version_number IS NOT NULL)'])]\nfinal class Schedule {}", 'Entity/Schedule.php'];
    }

    public function testNoNullPlanBranchRemainsInTheBackend(): void
    {
        $found = [];
        foreach ($this->sourceFiles() as $relative => $code) {
            foreach ($this->violationsIn($code, $relative) as [$line, $rule]) {
                $found[] = \sprintf('src/%s:%d — %s', $relative, $line, $rule);
            }
        }

        self::assertSame([], $found, \sprintf(
            "Le socle a son plan : ces sites cherchent ou écrivent encore « plan nul » :\n  - %s\n"
            . "Une colonne obligatoire cherchée à nul rend une liste vide, sans erreur. Lire le plan de saison\n"
            . 'par SoclePlan::idOf($seasonId) ; à la frontière de l\'API seulement, SoclePlan::fromApi / toApi.',
            implode("\n  - ", $found),
        ));
    }

    #[DataProvider('forbiddenForms')]
    public function testTheSweepSeesEveryForbiddenForm(string $snippet, string $rule): void
    {
        self::assertContains($rule, array_column($this->violationsIn("<?php\n" . $snippet . "\n", 'Service/Exemple.php'), 1), 'la forme interdite est vue');
    }

    #[DataProvider('allowedForms')]
    public function testTheSweepLetsCleanFormsThrough(string $snippet, string $file): void
    {
        self::assertSame([], $this->violationsIn("<?php\n" . $snippet . "\n", $file), 'la forme propre passe');
    }

    /**
     * Les violations d'un fichier : [ligne, règle].
     *
     * @return list<array{0: int, 1: string}>
     */
    private function violationsIn(string $code, string $relative): array
    {
        if (self::FRONTIER === $relative) {
            return [];
        }

        $all = token_get_all($code);
        $tokens = array_values(array_filter(
            $all,
            static fn (array|string $token): bool => !\is_array($token) || !\in_array($token[0], [\T_COMMENT, \T_DOC_COMMENT], true),
        ));

        $violations = [];
        foreach ($tokens as $i => $token) {
            if (!\is_array($token)) {
                continue;
            }
            if (\T_CONSTANT_ENCAPSED_STRING === $token[0] && \in_array(substr($token[1], 1, -1), self::PLAN_KEYS, true) && $this->assignsNull($tokens, $i)) {
                $violations[] = [$token[2], self::NULL_VALUE];
            }
            if (\in_array($token[0], [\T_CONSTANT_ENCAPSED_STRING, \T_ENCAPSED_AND_WHITESPACE], true)
                && 1 === preg_match(self::IS_NULL_PATTERN, $token[1])
                && self::PARTIAL_INDEX_OWNER !== $relative) {
                $violations[] = [$token[2], self::IS_NULL];
            }
        }

        foreach ($this->statements($tokens) as [$line, $statement]) {
            if ($this->isNullAnchor($statement)) {
                $violations[] = [$line, self::NULL_ANCHOR];
            }
        }

        if (!$this->isApiFrontier($relative)) {
            $stripped = $this->codeOf($all);
            foreach ([self::NULLABLE_PARAMETER_PATTERN, self::NULLABLE_ACCESSOR_PATTERN] as $pattern) {
                preg_match_all($pattern, $stripped, $matches, \PREG_OFFSET_CAPTURE);
                foreach ($matches[0] as [, $offset]) {
                    $violations[] = [1 + substr_count($stripped, "\n", 0, $offset), self::NULLABLE_DECLARATION];
                }
            }
        }

        usort($violations, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        return $violations;
    }

    /**
     * La clé en position `$i` reçoit-elle une valeur qui contient `null` ? `'k' => valeur` (jusqu'à la virgule
     * ou au crochet qui ferme, au même niveau) ou `['k'] = valeur` (jusqu'au point-virgule).
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private function assignsNull(array $tokens, int $i): bool
    {
        $next = $this->nextSignificant($tokens, $i);
        if (null === $next) {
            return false;
        }
        if (\is_array($tokens[$next]) && \T_DOUBLE_ARROW === $tokens[$next][0]) {
            return $this->valueHasNull($tokens, $next + 1, [',']);
        }
        if (']' === $tokens[$next]) {
            $assign = $this->nextSignificant($tokens, $next);
            if (null !== $assign && ('=' === $tokens[$assign] || (\is_array($tokens[$assign]) && \T_COALESCE_EQUAL === $tokens[$assign][0]))) {
                return $this->valueHasNull($tokens, $assign + 1, [';']);
            }
        }

        return false;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @param list<string>                                  $stops
     */
    private function valueHasNull(array $tokens, int $from, array $stops): bool
    {
        $depth = 0;
        for ($j = $from, $count = \count($tokens); $j < $count; ++$j) {
            $token = $tokens[$j];
            if (\is_string($token)) {
                if (\in_array($token, ['(', '[', '{'], true)) {
                    ++$depth;
                } elseif (\in_array($token, [')', ']', '}'], true)) {
                    if (0 === $depth) {
                        return false;
                    }
                    --$depth;
                } elseif (0 === $depth && (\in_array($token, $stops, true) || ';' === $token)) {
                    return false;
                }

                continue;
            }
            if (\T_STRING === $token[0] && 'null' === strtolower($token[1])) {
                return true;
            }
        }

        return false;
    }

    /** @param list<array{0: int, 1: string, 2: int}|string> $tokens */
    private function nextSignificant(array $tokens, int $i): ?int
    {
        for ($j = $i + 1, $count = \count($tokens); $j < $count; ++$j) {
            if (!\is_array($tokens[$j]) || \T_WHITESPACE !== $tokens[$j][0]) {
                return $j;
            }
        }

        return null;
    }

    /**
     * Les instructions du fichier, coupées aux `;`, `{` et `}` : [ligne de début, jetons].
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     *
     * @return list<array{0: int, 1: list<array{0: int, 1: string, 2: int}|string>}>
     */
    private function statements(array $tokens): array
    {
        $statements = [];
        $current = [];
        $line = 1;
        foreach ($tokens as $token) {
            if (\is_string($token) && \in_array($token, [';', '{', '}'], true)) {
                if ([] !== $current) {
                    $statements[] = [$line, $current];
                }
                $current = [];

                continue;
            }
            if ([] === $current && \is_array($token) && \T_WHITESPACE === $token[0]) {
                continue;
            }
            if ([] === $current && \is_array($token)) {
                $line = $token[2];
            }
            $current[] = $token;
        }
        if ([] !== $current) {
            $statements[] = [$line, $current];
        }

        return $statements;
    }

    /** @param list<array{0: int, 1: string, 2: int}|string> $statement */
    private function isNullAnchor(array $statement): bool
    {
        $significant = array_values(array_filter($statement, static fn (array|string $t): bool => !\is_array($t) || \T_WHITESPACE !== $t[0]));
        $callsAnchor = false;
        foreach ($significant as $k => $token) {
            if (\is_array($token) && \in_array($token[0], [\T_STRING, \T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED], true) && str_ends_with($token[1], 'StructureAnchor')
                && \is_array($significant[$k + 1] ?? null) && \T_DOUBLE_COLON === $significant[$k + 1][0]
                && \is_array($significant[$k + 2] ?? null) && 'of' === $significant[$k + 2][1]) {
                $callsAnchor = true;
            }
        }
        if (!$callsAnchor) {
            return false;
        }
        foreach ($significant as $token) {
            if (!\is_array($token)) {
                continue;
            }
            if (\T_STRING === $token[0] && 'null' === strtolower($token[1])) {
                return true;
            }
            if (\in_array($token[0], [\T_CONSTANT_ENCAPSED_STRING, \T_ENCAPSED_AND_WHITESPACE], true) && 1 === preg_match(self::ANY_IS_NULL_PATTERN, $token[1])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Le code sans ses commentaires, chaque commentaire remplacé par ses seuls retours à la ligne (les
     * numéros de ligne restent justes).
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private function codeOf(array $tokens): string
    {
        $code = '';
        foreach ($tokens as $token) {
            if (!\is_array($token)) {
                $code .= $token;
            } elseif (\in_array($token[0], [\T_COMMENT, \T_DOC_COMMENT], true)) {
                $code .= str_repeat("\n", substr_count($token[1], "\n"));
            } else {
                $code .= $token[1];
            }
        }

        return $code;
    }

    private function isApiFrontier(string $relative): bool
    {
        foreach (self::API_FRONTIER_DIRECTORIES as $directory) {
            if (str_starts_with($relative, $directory)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, string> chemin relatif à `src/` => code */
    private function sourceFiles(): array
    {
        $root = realpath(self::SRC);
        self::assertIsString($root);
        $files = [];
        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ('php' !== $file->getExtension()) {
                continue;
            }
            $files[substr($file->getPathname(), \strlen($root) + 1)] = (string) file_get_contents($file->getPathname());
        }
        ksort($files);
        self::assertGreaterThan(100, \count($files), 'témoin : le balayage lit bien backend/src');

        return $files;
    }
}
