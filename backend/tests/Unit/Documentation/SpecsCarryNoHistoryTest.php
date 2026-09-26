<?php

declare(strict_types=1);

namespace App\Tests\Unit\Documentation;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * LA DOC DÉCRIT LE PRÉSENT — l'historique vit dans git.
 *
 * Décision fondateur du 2026-09-26 (passe doc « le présent ») : un doc dit ce que le code fait
 * AUJOURD'HUI ; le « pourquoi / quand / qui a mordu » se lit dans git (`git log -p`), une décision
 * fermée dans `etat-des-lieux.md` §2, une livraison dans son §3 (règle 7 du skill
 * `documentation-update`). La passe a nettoyé toutes les zones ; ce garde interdit la régression.
 *
 * Périmètre gardé (décision fondateur du 2026-09-26, extension du garde après la passe) :
 *   - `specs/courantes/*.md`         — le produit courant sur l'axe temps ;
 *   - `backend/docs/*.md`, `frontend/docs/*.md`, `engine/docs/*.md` — le métier de chaque zone ;
 *   - `docs/` en récursif (tous sous-dossiers) — la couture entre zones, ADR, sécurité, ops, tests.
 * Les motifs sont INCHANGÉS depuis la version « specs seulement » : seul le périmètre s'élargit.
 *
 * Ce que ce test garde, mécaniquement et sans juger la qualité du fond : aucun doc du présent ne
 * reprend les tournures qui trahissent un journal glissé dans la prose — un `<details>` de repli,
 * un « superseded », un « conservé pour trace », un « instantané daté », un « périmé par », ni une
 * table dont les lignes commencent par une date. La borne est volontairement étroite : le mot nu
 * « historique » (une feature UI vivante en parle), une date nue (la raison d'être d'un garde peut
 * être datée) et « désormais » ne sont PAS ferrés — ce sont des faux positifs assumés.
 *
 * Corriger un rouge : sortir le récit du fichier. Une décision fermée part en `etat-des-lieux.md`
 * §2, une livraison en §3 ; le reste vit dans git. On ne réécrit PAS le motif pour faire taire un
 * hit, et une exemption nouvelle exige sa raison structurelle dans self::EXEMPT / self::EXEMPT_DIRS.
 */
#[Group('phase1')]
final class SpecsCarryNoHistoryTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../../..';

    private const string GUIDANCE = 'le présent seulement — l\'historique vit dans git ; '
        . 'une décision → etat-des-lieux §2, une livraison → §3';

    /**
     * Exemptions NOMINATIVES d'un FICHIER, avec leur raison — jamais un motif large.
     *
     * @var array<string, string> chemin relatif au dépôt => raison
     */
    private const array EXEMPT = [
        // C'est LUI le journal du dépôt : décision fondateur du 2026-09-26, l'unique fichier de
        // `specs/courantes/` autorisé à porter des traces datées (décisions §2, livraisons §3).
        'specs/courantes/etat-des-lieux.md' => 'le journal du dépôt — le seul de specs/courantes à porter des traces datées',
        // Le SECOND journal autorisé par la règle 7 du skill documentation-update, borné aux 6
        // derniers lots par sa propre charte (docs/upgrades.md en tête).
        'docs/upgrades.md' => 'le second journal autorisé (règle 7), borné aux 6 derniers lots par sa charte',
        // « superseded by adr-XXXX » y est un STATUT d'ADR (§Convention), pas un récit glissé dans
        // la prose : c'est le vocabulaire de statut des ADR, pas de l'historique à sortir.
        'docs/architecture/adr-index.md' => 'vocabulaire de statut ADR (« superseded by adr-XXXX ») dans la légende §Convention',
    ];

    /**
     * Exemptions NOMINATIVES d'un DOSSIER (préfixe de chemin relatif), avec leur raison.
     *
     * @var array<string, string> préfixe relatif au dépôt => raison
     */
    private const array EXEMPT_DIRS = [
        // Instantanés datés (compte-rendus de cadrage, snapshots d'audit) cités comme référence
        // historique : hors règle 7 par exception explicite du skill — jamais une source de vérité
        // sur l'état courant, seulement une trace.
        'docs/archive/' => 'instantanés datés — exception explicite de la règle 7 (trace, jamais source de vérité courante)',
    ];

    /**
     * Les marqueurs d'historique interdits, et ce que chacun ferre. Insensibles à la casse et
     * à l'accent (`iu`). Bornés serré exprès : « historique » nu, une date nue et « désormais »
     * restent hors du filet (faux positifs assumés — cf. docblock de classe).
     *
     * @var array<string, string> motif PCRE => ce qu'il désigne
     */
    private const array HISTORY_MARKERS = [
        '/<details/iu' => 'une balise <details> (repli d\'un raisonnement passé)',
        '/superseded/iu' => 'un « superseded » (une version remplacée)',
        '/conservée? pour (la )?trace|traçabilité/iu' => 'un « conservé pour trace » / « traçabilité »',
        '/instantané daté/iu' => 'un « instantané daté »',
        '/périmée? par/iu' => 'un « périmé par »',
    ];

    public function testNoPresentDocCarriesAHistoryMarker(): void
    {
        $offenders = [];
        foreach ($this->presentDocs() as $relative => $absolute) {
            foreach ($this->linesOf($absolute) as $index => $line) {
                foreach (self::HISTORY_MARKERS as $pattern => $what) {
                    if (1 === preg_match($pattern, $line)) {
                        $offenders[] = \sprintf('%s:%d — %s', $relative, $index + 1, $what);
                    }
                }
            }
        }

        self::assertSame([], $offenders, \sprintf(
            "Ces lignes portent un marqueur d'historique dans un fichier qui doit décrire le présent :\n"
            . "  - %s\n\n"
            . "Sortez le récit du fichier (%s). N'affaiblissez pas le motif pour faire taire un hit ;\n"
            . 'une vraie exception se déclare NOMMÉMENT dans self::EXEMPT / self::EXEMPT_DIRS avec sa raison.',
            implode("\n  - ", $offenders),
            self::GUIDANCE,
        ));
    }

    public function testNoPresentDocCarriesADatedJournalTable(): void
    {
        $offenders = [];
        foreach ($this->presentDocs() as $relative => $absolute) {
            foreach ($this->linesOf($absolute) as $index => $line) {
                if (1 === preg_match('/^\| 20\d\d-/', $line)) {
                    $offenders[] = \sprintf('%s:%d', $relative, $index + 1);
                }
            }
        }

        self::assertSame([], $offenders, \sprintf(
            "Ces lignes ouvrent une table de journal datée hors les journaux autorisés :\n"
            . "  - %s\n\n"
            . 'Une table `| YYYY-MM-DD | … |` est un journal : %s.',
            implode("\n  - ", $offenders),
            self::GUIDANCE,
        ));
    }

    /**
     * Tous les docs du présent — clé = chemin relatif au dépôt, pour des messages `fichier:ligne`
     * directement ouvrables. Zones (`specs/courantes`, `<zone>/docs`) balayées à plat ; `docs/`
     * racine récursivement (ADR, sécurité, ops, tests…). Exemptions nominatives (fichier ET
     * dossier) écartées ici.
     *
     * @return array<string, string> chemin relatif => chemin absolu
     */
    private function presentDocs(): array
    {
        $files = [];

        foreach (['specs/courantes', 'backend/docs', 'frontend/docs', 'engine/docs'] as $dir) {
            foreach (glob(self::ROOT . '/' . $dir . '/*.md') ?: [] as $absolute) {
                $this->register($files, $dir . '/' . basename($absolute), $absolute);
            }
        }

        $rootDocs = self::ROOT . '/docs';
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($rootDocs, FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $entry) {
            if (!$entry instanceof SplFileInfo || 'md' !== $entry->getExtension()) {
                continue;
            }
            $absolute = $entry->getPathname();
            $this->register($files, 'docs/' . substr($absolute, \strlen($rootDocs) + 1), $absolute);
        }

        ksort($files);

        self::assertNotEmpty($files, 'Aucun doc du présent trouvé — les globs pointent-ils encore quelque part ?');

        return $files;
    }

    /**
     * Range un fichier sous sa clé relative, sauf s'il est exempté nommément (fichier) ou par
     * dossier (préfixe).
     *
     * @param array<string, string> $files
     */
    private function register(array &$files, string $relative, string $absolute): void
    {
        if (isset(self::EXEMPT[$relative])) {
            return;
        }
        foreach (array_keys(self::EXEMPT_DIRS) as $prefix) {
            if (str_starts_with($relative, $prefix)) {
                return;
            }
        }
        $files[$relative] = $absolute;
    }

    /** @return list<string> */
    private function linesOf(string $absolute): array
    {
        $contents = file_get_contents($absolute);
        self::assertIsString($contents, \sprintf('Illisible : %s', $absolute));

        return explode("\n", $contents);
    }
}
