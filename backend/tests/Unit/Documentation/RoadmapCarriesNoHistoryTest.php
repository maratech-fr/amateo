<?php

declare(strict_types=1);

namespace App\Tests\Unit\Documentation;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * LA ROADMAP NE TIENT QUE L'OUVERT — le récit vit dans `etat-des-lieux.md` §2/§3, ou dans git.
 *
 * Décision fondateur du 2026-10-06 (« ça sert à rien ») : `specs/evolution/roadmap.md` avait
 * accumulé ~190 lignes de citations `>` et de cellules Note racontant ce qui avait déjà été LIVRÉ
 * — dates de livraison, « SOLDÉ »/« FERMÉ » datés, renvois « a quitté la roadmap », décisions
 * renversées puis re-tranchées le même jour. Ce n'est pas le rôle de ce fichier : un item livré
 * **quitte** la roadmap (règle déjà gardée par `RoadmapIdentityTest`), il n'y laisse pas une trace
 * narrative — la trace datée vit dans `etat-des-lieux.md` §3, une décision fermée dans son §2.
 *
 * Ce garde est le jumeau de `SpecsCarryNoHistoryTest` (même décision fondateur, même mécanique :
 * sortir le récit, jamais affaiblir le motif), mais BORNÉ à `specs/evolution/roadmap.md` et à un
 * vocabulaire distinct — celui d'une livraison DATÉE, pas celui d'un « superseded »/`<details>`
 * de doc technique. Les motifs exigent une DATE accolée au marqueur de clôture (`livré le
 * 2026-...`, `SOLDÉ (2026-...`) : un `SOLDÉ`/`fermé` nu reste un vocabulaire légitime de la
 * roadmap elle-même (règle d'entretien, décision fermée référencée par pointeur) et n'est pas
 * ferré, pour zéro faux positif sur le contenu nettoyé.
 *
 * Corriger un rouge : sortir la date/le récit de livraison de la ligne — reporter la trace en
 * `etat-des-lieux.md` §3 (ou la décision en §2) si elle n'y est pas déjà, puis ne garder dans la
 * roadmap que ce qui aide à FAIRE l'item encore ouvert.
 */
#[Group('phase1')]
final class RoadmapCarriesNoHistoryTest extends TestCase
{
    private const string ROADMAP = __DIR__ . '/../../../../specs/evolution/roadmap.md';

    /**
     * Motifs d'un récit de LIVRAISON daté. Chacun exige une date `20\d\d-\d\d-\d\d` accolée au
     * marqueur de clôture — un `SOLDÉ`/`livré`/`fermé` nu, sans date, reste le vocabulaire normal
     * de la roadmap (règle d'entretien, pointeur vers une décision fermée) et n'est pas ferré.
     *
     * @var array<string, string> motif PCRE => ce qu'il désigne
     */
    private const array HISTORY_MARKERS = [
        '/\b(livr[ée]e?s?|sold[ée]e?s?|clos|clôtur[ée]e?s?|ferm[ée]e?s?|archiv[ée]e?s?)\b[^.\n|]{0,40}\b20\d\d-\d\d-\d\d/iu' => 'un marqueur de clôture (livré/soldé/clos/fermé/archivé) daté',
        '/a quitté la roadmap/iu' => '« a quitté la roadmap » (récit de MOVE, déjà gardé par ailleurs)',
        '/décidé(e)? ce jour-là/iu' => '« décidé ce jour-là » (chronologie de décision)',
        '/mémoire longue/iu' => '« mémoire longue » (renvoi narratif à une édition d\'audit)',
        '/<details/iu' => 'une balise <details> (repli d\'un raisonnement passé)',
    ];

    public function testRoadmapCarriesNoDatedDeliveryNarrative(): void
    {
        $offenders = [];
        foreach ($this->lines() as $index => $line) {
            foreach (self::HISTORY_MARKERS as $pattern => $what) {
                if (1 === preg_match($pattern, $line)) {
                    $offenders[] = \sprintf('ligne %d — %s : %s', $index + 1, $what, trim($line));
                }
            }
        }

        self::assertSame([], $offenders, \sprintf(
            "La roadmap ne garde que l'ouvert — le récit va dans etat-des-lieux §2/§3 (ou git) :\n  - %s\n\n"
            . "Sortez la date/le récit de livraison de la ligne. N'affaiblissez pas le motif pour\n"
            . 'faire taire un hit ; une vraie exception se déclare nommément dans ce test, avec sa raison.',
            implode("\n  - ", $offenders),
        ));
    }

    public function testRoadmapCarriesNoDatedJournalTable(): void
    {
        $offenders = [];
        foreach ($this->lines() as $index => $line) {
            if (1 === preg_match('/^\| 20\d\d-/', $line)) {
                $offenders[] = \sprintf('ligne %d', $index + 1);
            }
        }

        self::assertSame([], $offenders, \sprintf(
            'Ces lignes ouvrent une table de journal datée — la roadmap n\'en tient pas, '
            . "etat-des-lieux.md §3 est le journal :\n  - %s",
            implode("\n  - ", $offenders),
        ));
    }

    /** @return list<string> */
    private function lines(): array
    {
        $contents = file_get_contents(self::ROADMAP);
        self::assertIsString($contents, 'specs/evolution/roadmap.md doit être lisible');

        return explode("\n", $contents);
    }
}
