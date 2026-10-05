<?php

declare(strict_types=1);

namespace App\Tests\Unit\Documentation;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * AUD-DOC-50 — une citation `engine/app/main.py:NNN` dans un doc VIVANT pourrit à chaque passe.
 *
 * `app/main.py` est le récidiviste de la famille DOC-46→50 : deux éditions d'audit de suite
 * (2026-10 et la précédente) ont trouvé la MÊME poignée de numéros de ligne faux pointant dessus,
 * parce qu'une ligne ajoutée au fichier décale tout ce qui est cité par numéro. La règle (une
 * phrase du skill `documentation-update` §Anti-drift) : une citation vers ce fichier s'ancre sur
 * le SYMBOLE (`app/main.py::nom`), jamais sur un numéro de ligne. Un symbole ne bouge pas quand
 * une ligne est insérée ailleurs ; un commentaire mort sans symbole propre se cite par un
 * marqueur textuel stable (`commentaire FACILITY_CAPACITY, app/main.py`).
 *
 * Ce garde tient deux propriétés sur les docs vivants (le périmètre de `DocStampFreshnessTest`,
 * hors `etat-des-lieux.md` dont le §3 porte des traces datées exemptées) :
 *
 *   (a) AUCUNE occurrence du motif `main.py:<chiffre>` — l'ancrage par ligne est banni ;
 *   (b) chaque `app/main.py::<symbole>` cité désigne un symbole QUI EXISTE dans
 *       `engine/app/main.py` (def ou affectation de module), sinon la citation ment déjà.
 *
 * ⚠ Périmètre VOLONTAIREMENT limité à `main.py` (le récidiviste). Interdire `fichier:ligne`
 * partout contredirait la règle 3 du skill (un fait sécurité EXIGE sa citation `file:line`) et
 * le format de preuve de la roadmap. L'étendre à un autre fichier est une décision à prendre,
 * pas un défaut à combler ici.
 */
#[Group('phase1')]
final class EngineMainCitationsAnchorOnSymbolTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../../..';

    /**
     * Les docs vivants balayés — miroir de `DocStampFreshnessTest::WATCHED`, hors le journal
     * `etat-des-lieux.md` (son §3 garde des traces datées où un `main.py:NNN` historique est
     * légitime, figé dans le temps).
     */
    private const array WATCHED = [
        'specs/courantes/*.md',
        'docs/project-map.md',
        'backend/docs/*.md',
        'engine/docs/*.md',
        'frontend/docs/*.md',
    ];

    /**
     * @var array<string, string>
     */
    private const array EXEMPT = [
        'specs/courantes/etat-des-lieux.md' => 'journal de traces datées : un main.py:NNN historique y est figé, pas vivant',
    ];

    public function testNoLivingDocCitesMainPyByLineNumber(): void
    {
        $offenders = [];
        foreach ($this->watchedFiles() as $relative => $absolute) {
            $contents = $this->contentsOf($absolute);
            foreach (explode("\n", $contents) as $number => $line) {
                if (1 === preg_match('/main\.py:\d/', $line)) {
                    $offenders[] = \sprintf('%s:%d  %s', $relative, $number + 1, trim($line));
                }
            }
        }

        self::assertSame([], $offenders, \sprintf(
            "Ces docs vivants citent `engine/app/main.py` par NUMÉRO DE LIGNE :\n  - %s\n"
            . "Ancre sur le SYMBOLE (`app/main.py::nom`), jamais sur une ligne — DOC-46→50,\n"
            . 'deux récidives sur ce même fichier. Un commentaire sans symbole : marqueur textuel stable.',
            implode("\n  - ", $offenders),
        ));
    }

    public function testEveryCitedSymbolExistsInMainPy(): void
    {
        $source = $this->contentsOf(self::ROOT . '/engine/app/main.py');

        $unknown = [];
        foreach ($this->watchedFiles() as $relative => $absolute) {
            $contents = $this->contentsOf($absolute);
            if (0 === preg_match_all('/main\.py::(\w+)/', $contents, $matches)) {
                continue;
            }
            foreach (array_unique($matches[1]) as $symbol) {
                if (!$this->symbolIsDefinedIn($symbol, $source)) {
                    $unknown[] = \sprintf('%s → app/main.py::%s', $relative, $symbol);
                }
            }
        }

        self::assertSame([], $unknown, \sprintf(
            "Ces docs citent un symbole ABSENT de engine/app/main.py :\n  - %s\n"
            . 'Le symbole a été renommé ou supprimé — recale la citation sur le symbole actuel.',
            implode("\n  - ", $unknown),
        ));
    }

    /** @return array<string, string> chemin relatif au dépôt => chemin absolu */
    private function watchedFiles(): array
    {
        $files = [];
        foreach (self::WATCHED as $pattern) {
            foreach (glob(self::ROOT . '/' . $pattern) ?: [] as $absolute) {
                $relative = $this->relativePath($absolute);
                if (!isset(self::EXEMPT[$relative])) {
                    $files[$relative] = $absolute;
                }
            }
        }
        ksort($files);

        self::assertNotEmpty($files, 'Aucun document surveillé trouvé — self::WATCHED pointe-t-il encore quelque part ?');

        return $files;
    }

    /** Un symbole « existe » s'il est défini (def/async def) ou affecté au niveau module. */
    private function symbolIsDefinedIn(string $symbol, string $source): bool
    {
        $quoted = preg_quote($symbol, '/');

        return 1 === preg_match('/\bdef\s+' . $quoted . '\b/', $source)
            || 1 === preg_match('/^' . $quoted . '\s*(?::[^=\n]+)?=/m', $source);
    }

    private function contentsOf(string $absolute): string
    {
        $contents = file_get_contents($absolute);
        self::assertIsString($contents, \sprintf('Illisible : %s', $absolute));

        return $contents;
    }

    private function relativePath(string $absolute): string
    {
        $root = realpath(self::ROOT);
        self::assertIsString($root);

        return ltrim(str_replace($root, '', (string) realpath($absolute)), '/');
    }
}
