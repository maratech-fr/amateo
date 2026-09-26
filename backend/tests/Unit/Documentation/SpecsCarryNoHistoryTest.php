<?php

declare(strict_types=1);

namespace App\Tests\Unit\Documentation;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * LES SPECS COURANTES DÉCRIVENT LE PRÉSENT — l'historique vit dans git.
 *
 * Décision fondateur du 2026-09-26 (passe doc « le présent ») : un SEUL fichier de
 * `specs/courantes/` porte un journal, `etat-des-lieux.md` ; tous les autres décrivent
 * l'application telle qu'elle est aujourd'hui, sans récit du chemin parcouru (règle 7 du
 * skill `documentation-update`). Le « pourquoi / quand / qui a mordu » se lit dans git
 * (`git log -p`), une décision fermée dans `etat-des-lieux.md` §2, une livraison dans son §3.
 *
 * Ce que ce test garde, mécaniquement et sans juger la qualité du fond : aucun fichier du
 * présent ne reprend les tournures qui trahissent un journal glissé dans la prose —
 * un `<details>` de repli, un « superseded », un « conservé pour trace », un « instantané
 * daté », un « périmé par », ni une table dont les lignes commencent par une date. La borne
 * est volontairement étroite : le mot nu « historique » (une feature UI vivante en parle),
 * une date nue (la raison d'être d'un garde peut être datée) et « désormais » ne sont PAS
 * ferrés — ce sont des faux positifs assumés.
 *
 * Corriger un rouge : sortir le récit du fichier. Une décision fermée part en
 * `etat-des-lieux.md` §2, une livraison en §3 ; le reste vit dans git. On ne réécrit PAS le
 * motif pour faire taire un hit, et une exemption nouvelle exige sa raison structurelle
 * dans self::EXEMPT.
 */
#[Group('phase1')]
final class SpecsCarryNoHistoryTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../../..';

    private const string GUIDANCE = 'le présent seulement — l\'historique vit dans git ; '
        . 'une décision → etat-des-lieux §2, une livraison → §3';

    /**
     * Exemption NOMINATIVE, avec sa raison — jamais un motif large.
     *
     * @var array<string, string>
     */
    private const array EXEMPT = [
        // C'est LUI le journal du dépôt : décision fondateur du 2026-09-26, l'unique fichier
        // de `specs/courantes/` autorisé à porter des traces datées (décisions §2, livraisons §3).
        'etat-des-lieux.md' => 'le journal du dépôt — le seul autorisé à porter des traces datées',
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

    public function testNoCurrentSpecCarriesAHistoryMarker(): void
    {
        $offenders = [];
        foreach ($this->currentSpecs() as $relative => $absolute) {
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
            . 'une vraie exception se déclare NOMMÉMENT dans self::EXEMPT avec sa raison.',
            implode("\n  - ", $offenders),
            self::GUIDANCE,
        ));
    }

    public function testNoCurrentSpecCarriesADatedJournalTable(): void
    {
        $offenders = [];
        foreach ($this->currentSpecs() as $relative => $absolute) {
            foreach ($this->linesOf($absolute) as $index => $line) {
                if (1 === preg_match('/^\| 20\d\d-/', $line)) {
                    $offenders[] = \sprintf('%s:%d', $relative, $index + 1);
                }
            }
        }

        self::assertSame([], $offenders, \sprintf(
            "Ces lignes ouvrent une table de journal datée hors etat-des-lieux :\n"
            . "  - %s\n\n"
            . 'Une table `| YYYY-MM-DD | … |` est un journal : %s.',
            implode("\n  - ", $offenders),
            self::GUIDANCE,
        ));
    }

    /**
     * `specs/courantes/*.md` sauf le journal — clé = chemin relatif au dépôt, pour des messages
     * `fichier:ligne` directement ouvrables.
     *
     * @return array<string, string> chemin relatif => chemin absolu
     */
    private function currentSpecs(): array
    {
        $files = [];
        foreach (glob(self::ROOT . '/specs/courantes/*.md') ?: [] as $absolute) {
            if (isset(self::EXEMPT[basename($absolute)])) {
                continue;
            }
            $files['specs/courantes/' . basename($absolute)] = $absolute;
        }
        ksort($files);

        self::assertNotEmpty($files, 'Aucun spec courant trouvé — le glob pointe-t-il encore quelque part ?');

        return $files;
    }

    /** @return list<string> */
    private function linesOf(string $absolute): array
    {
        $contents = file_get_contents($absolute);
        self::assertIsString($contents, \sprintf('Illisible : %s', $absolute));

        return explode("\n", $contents);
    }
}
