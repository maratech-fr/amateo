<?php

declare(strict_types=1);

namespace App\Tests\Unit\Documentation;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Nom unique « planning de période » — garde STATIQUE côté serveur (PR 2 « Moyennes »).
 *
 * UNE promesse : **un texte que le serveur envoie (refus 409/422, e-mail, message d'API) appelle le
 * planning d'une période « planning de période » et celui de la saison « planning de saison » —
 * jamais « planning secondaire », « plan de période » ni « planning principal »**. Le gestionnaire
 * lit ces messages tels quels (toast, e-mail) : un second nom y réintroduirait la confusion que
 * l'écran a retirée. Jumeau front : `frontend/src/test/periodPlanNamingGuard.test.ts`.
 *
 * ── LARGEUR (documentée) ─────────────────────────────────────────────────────────────────────
 * Seules les CHAÎNES de `backend/src/**\/*.php` sont lues, par `token_get_all` (jamais une regex de
 * ligne) : `T_CONSTANT_ENCAPSED_STRING`, `T_ENCAPSED_AND_WHITESPACE`, et les chaînes interpolées /
 * heredoc RECOMPOSÉES (`"planning{$s} secondaire"`). Les commentaires et docblocks — vocabulaire de
 * code, conservé (D10) — ne sont jamais lus. Liste fermée, casse ignorée : `plannings? secondaires?`
 * · `plans? secondaires?` · `calendriers? secondaires?` · `planning principal` · `plans? de période`
 * · formes découpées `planning{…}|$x|%s secondaire` / `plan{…}|$x|%s de période`.
 */
final class PeriodPlanNamingTest extends TestCase
{
    private const array FORBIDDEN = [
        '/plannings? secondaires?/iu',
        '/plans? secondaires?/iu',
        '/calendriers? secondaires?/iu',
        '/planning principal/iu',
        '/\bplans? de période/iu',
        '/planning(?:\{[^}]*\}|\$\w+|%s) secondaire/iu',
        '/plan(?:\{[^}]*\}|\$\w+|%s) de période/iu',
    ];

    /** Falsification des deux sens : le détecteur mord chaque forme, épargne commentaires et tournures légitimes. */
    public function testTheDetectorBitesOldNamesAndSparesCommentsAndTheNewName(): void
    {
        $bad = [
            '<?php throw new ConflictHttpException(\'Validez le planning principal avant de créer un match ou un planning secondaire.\');',
            '<?php $s = \\sprintf(\'Elle commence dans %d jour%s et n\\\'a pas encore de plan de période (calendrier secondaire).\', $d, \'s\');',
            '<?php $s = \\sprintf(\'%s dans %d j — pas de plan de période\', $t, $d);',
            '<?php $s = "{$n} planning{$p} secondaire{$p}";',
            '<?php $s = "{$n} plan$p de période";',
            '<?php $s = \\sprintf(\'%d planning%s secondaire%s\', $n, \'s\', \'s\');',
            "<?php \$s = <<<TXT\n  Aucun calendrier secondaire pour {\$x}.\n  TXT;",
            '<?php $s = \'les plans secondaires\';',
        ];
        foreach ($bad as $source) {
            self::assertNotSame([], $this->scan('x.php', $source), 'devrait mordre : ' . $source);
        }

        $good = [
            // SchedulePlanProvisioner.php:904 — un commentaire de fin de ligne, vocabulaire de code (D10).
            "<?php function f() { return null; // inv. 9 : un plan de période a toujours une entrée — défense.\n}",
            '<?php /** Le planning principal n\'est jamais modifié par un plan secondaire. */ $x = 1;',
            "<?php # 'plans de période' en commentaire dièse\n\$x = 1;",
            '<?php throw new ConflictHttpException(\'Validez le planning de saison avant de créer un match ou un planning de période.\');',
            '<?php $s = \'Le planning de la saison est en vigueur — rouvrez-le avant d\\\'en préparer un autre.\';',
            '<?php $s = "2 plannings de période ({$open} en cours)";',
            '<?php $s = \'Aucune équipe n\\\'a de coach principal\';',
        ];
        foreach ($good as $source) {
            self::assertSame([], $this->scan('x.php', $source), 'ne devrait PAS mordre : ' . $source);
        }
    }

    public function testNoServerTextCarriesAnOldName(): void
    {
        $root = realpath(__DIR__ . '/../../../src');
        self::assertIsString($root);

        $offenders = [];
        $scanned = 0;
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            if (!$file instanceof SplFileInfo || 'php' !== $file->getExtension()) {
                continue;
            }
            ++$scanned;
            $relative = substr((string) $file->getRealPath(), \strlen($root) + 1);
            $offenders = [...$offenders, ...$this->scan($relative, (string) file_get_contents((string) $file->getRealPath()))];
        }

        // Témoin de portée : un vert ne doit jamais venir d'un balayage vide.
        self::assertGreaterThan(100, $scanned);
        sort($offenders);
        self::assertSame([], $offenders, \sprintf(
            "Ces textes serveur portent encore un ancien nom :\n  - %s\n\n"
            . "Le planning d'une période s'appelle « planning de période » (pluriel « plannings de période »),\n"
            . 'celui de la saison « planning de saison ». Les commentaires ne sont pas visés.',
            implode("\n  - ", $offenders),
        ));
    }

    /**
     * Les chaînes d'un source PHP, avec leur ligne.
     *
     * @return list<array{int, string}>
     */
    private function strings(string $source): array
    {
        $out = [];
        $buffer = null;
        $bufferLine = 0;
        $line = 1;
        foreach (token_get_all($source) as $token) {
            $text = \is_array($token) ? $token[1] : $token;
            if (\is_array($token)) {
                $line = $token[2];
            }
            if (null !== $buffer) {
                if ('"' === $token || (\is_array($token) && \T_END_HEREDOC === $token[0])) {
                    $out[] = [$bufferLine, $buffer];
                    $buffer = null;
                } else {
                    $buffer .= $text;
                }
                continue;
            }
            if ('"' === $token || (\is_array($token) && \T_START_HEREDOC === $token[0])) {
                $buffer = '';
                $bufferLine = $line;
                continue;
            }
            if (\is_array($token) && \in_array($token[0], [\T_CONSTANT_ENCAPSED_STRING, \T_ENCAPSED_AND_WHITESPACE], true)) {
                $out[] = [$token[2], $token[1]];
            }
        }

        return $out;
    }

    /** @return list<string> les offenses `rel:ligne → chaîne` */
    private function scan(string $relative, string $source): array
    {
        $offenders = [];
        foreach ($this->strings($source) as [$line, $string]) {
            foreach (self::FORBIDDEN as $pattern) {
                if (1 === preg_match($pattern, $string)) {
                    $offenders[] = \sprintf('%s:%d → %s', $relative, $line, $string);
                    break;
                }
            }
        }

        return $offenders;
    }
}
