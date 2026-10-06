<?php

declare(strict_types=1);

namespace App\Tests\Unit\Documentation;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * QUAND UN DOC A TROP GROSSI — l'alarme de débordement thématique, rendue opposable.
 *
 * ⚑ Ce test naît du reliquat DOC de l'audit 2026-10-03 (DOC-59) : `frontend-spec.md` (1 585 l.)
 * et `backend-inventory.md` (1 093 l.) étaient devenus des monolithes qu'un lecteur — humain ou
 * agent — ne pouvait plus parcourir pour trouver LE sujet qu'il cherchait. Ils ont été découpés
 * PAR THÈME (un sous-doc = un sujet cohérent), et ce garde empêche la dérive de recommencer en
 * silence.
 *
 * Le plafond n'est PAS un objectif de taille : c'est un INDICATEUR de débordement. Quand un doc
 * franchit {@see self::MAX_LINES} lignes, c'est le signe qu'il mélange désormais plusieurs sujets
 * et mérite un nouveau découpage THÉMATIQUE — pas qu'il faut le « réduire » en coupant du fond.
 * Ranger un doc trop gros est une migration relue pour elle-même (skill `documentation-update`,
 * « ranger est une migration one-shot »), jamais un dégraissage à l'aveugle.
 *
 * Même grain que `DocStampFreshnessTest::WATCHED` (globs de zone + specs produit courantes) : un
 * doc de zone créé demain est surveillé d'office. Les exceptions sont NOMMÉES et justifiées —
 * jamais un motif large (patron `DocPlacementTest::ALLOWED`).
 */
#[Group('phase1')]
final class DocSizeCeilingTest extends TestCase
{
    /**
     * Le seuil de débordement thématique. Franchi = « ce doc porte trop de sujets, découpe-le »,
     * pas « ce doc est trop long, raccourcis-le ».
     */
    private const int MAX_LINES = 800;

    /** Les mêmes dossiers que la fraîcheur surveille : doc de zone + produit courant. */
    private const array GLOBS = [
        '/docs/*.md',             // backend/docs
        '/../frontend/docs/*.md',
        '/../engine/docs/*.md',
        '/../specs/courantes/*.md',
    ];

    /**
     * Exceptions NOMMÉES et justifiées. Objectif : ZÉRO. Chaque entrée est une dette visible,
     * pas un droit acquis.
     *
     * - `etat-des-lieux.md` : ce n'est pas un doc thématique mais un REGISTRE append-only (carte
     *   des capacités + décisions fermées + traces datées de livraison). Il grossit par
     *   construction, une livraison après l'autre ; « le découper par thème » ne s'y applique pas
     *   comme pour un inventaire — un découpage par époque serait une décision à part entière.
     * - `module-matchs.md` : grande spec courante d'UN module (FFBB). C'est un vrai CANDIDAT au
     *   découpage thématique, mais hors périmètre de DOC-59 (qui ne visait que `frontend-spec.md`
     *   et `backend-inventory.md`) — à traiter dans sa propre passe de rangement.
     */
    private const array ALLOWED = [
        'etat-des-lieux.md',
        'module-matchs.md',
    ];

    public function testNoLivingDocOverflowsItsThematicCeiling(): void
    {
        $base = __DIR__; // backend/tests/Unit/Documentation
        $backend = \dirname($base, 3); // backend
        $overflowing = [];

        foreach (self::GLOBS as $glob) {
            foreach (glob($backend . $glob) ?: [] as $path) {
                $name = basename($path);
                if (\in_array($name, self::ALLOWED, true)) {
                    continue;
                }
                $count = \count(file($path, \FILE_IGNORE_NEW_LINES) ?: []);
                if ($count > self::MAX_LINES) {
                    $overflowing[] = \sprintf('%s : %d lignes', $name, $count);
                }
            }
        }

        sort($overflowing);

        self::assertSame([], $overflowing, \sprintf(<<<'TXT'
            Ces documents DÉBORDENT le plafond de %d lignes : ils portent désormais trop de sujets.

            DÉCOUPE-les PAR THÈME — un sous-doc = UN sujet cohérent qu'un lecteur (humain ou agent)
            vient chercher, pas un tronçon de lignes arbitraire. Ce n'est PAS une invitation à
            « raccourcir » en coupant du fond : le fond part dans les nouveaux fichiers thématiques,
            les liens entrants vers les sections déplacées sont repointés, et le fichier d'origine
            garde le shell + des pointeurs (patron DOC-59 : `frontend-spec.md`, `backend-inventory.md`).

            Si une exception est légitime (un registre append-only, une migration hors périmètre),
            elle se déclare NOMMÉMENT dans self::ALLOWED, avec sa raison — jamais un motif large,
            et toujours comme une dette visible à résorber.
            TXT, self::MAX_LINES));
    }
}
