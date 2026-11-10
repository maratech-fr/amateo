<?php

declare(strict_types=1);

namespace App\Tests\CrossStack;

use App\Exception\InvalidRichDocException;
use App\Service\ReleaseNoteRichDoc;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Promesse (Actualités PR B, schéma éditeur = liste blanche serveur, « un seul dessin ») : **tout
 * ce que l'éditeur de la console PRODUIT — frappe, gras/italique/5 couleurs, saut de ligne dans du
 * gras, titre + listes + emoji, collage web, collage Word Windows, tout le catalogue d'emoji — est
 * ACCEPTÉ tel quel par le validateur serveur** ; le seul écart admis (déclaré) est un collage de 4
 * niveaux de listes, que l'éditeur accepte et que le serveur refuse avec le message exact.
 *
 * Les fixtures sont la sortie `getJSON()` de l'éditeur réel, assertée égale par
 * `frontend/src/features/admin/releaseNoteEditor/editorOutput.test.ts` (jumeau côté front : si
 * l'éditeur change de forme, la fixture change, et ce test rejoue la nouvelle forme ici). La forme
 * fermée elle-même est prouvée cas par cas par `Unit/ReleaseNoteRichDocTest`.
 *
 * Régime : HORS groupe `contract` (patron `FrontMockDateFormatMatchesContractTest`) — il ne lit que
 * des fichiers de `frontend/src`, sans moteur.
 */
final class ReleaseNoteRichDocEditorFixturesTest extends TestCase
{
    private const string FIXTURES = __DIR__ . '/../../../frontend/src/features/admin/releaseNoteEditor/fixtures';

    private const array ACCEPTED = [
        'frappe.json',
        'marques-5-couleurs.json',
        'saut-dans-gras.json',
        'titre-listes-emoji.json',
        'collage-web.json',
        'collage-word-windows.json',
        'emoji-catalogue.json',
    ];

    private const string REFUSED_DEPTH_4 = 'collage-profondeur-4.json';

    /** @return iterable<string, array{0: string}> */
    public static function acceptedFixtures(): iterable
    {
        foreach (self::ACCEPTED as $name) {
            yield $name => [$name];
        }
    }

    /** Le jeu de fixtures est FERMÉ : une fixture ajoutée sans être rangée ici (ou retirée) rougit. */
    public function testFixtureSetIsExactlyTheDeclaredOne(): void
    {
        $found = array_map(basename(...), glob(self::FIXTURES . '/*.json') ?: []);
        sort($found);
        $expected = [...self::ACCEPTED, self::REFUSED_DEPTH_4];
        sort($expected);

        self::assertSame($expected, $found, 'fixtures de ' . self::FIXTURES);
    }

    #[DataProvider('acceptedFixtures')]
    public function testEditorOutputIsAcceptedByTheServerWhitelist(string $name): void
    {
        $doc = $this->read($name);

        $validated = new ReleaseNoteRichDoc()->validate($doc);

        // La reconstruction est idempotente : relue, la valeur stockée repasse à l'identique.
        self::assertEquals($validated, new ReleaseNoteRichDoc()->validate($validated), $name . ' : reconstruction non idempotente');
        self::assertNotSame('', new ReleaseNoteRichDoc()->toPlainText($validated), $name . ' : miroir texte vide');
    }

    public function testFourPastedListLevelsAreRefusedWithTheDepthMessage(): void
    {
        $this->expectException(InvalidRichDocException::class);
        $this->expectExceptionMessage('Les listes ne peuvent pas dépasser 3 niveaux.');

        new ReleaseNoteRichDoc()->validate($this->read(self::REFUSED_DEPTH_4));
    }

    /** @return array<string, mixed> */
    private function read(string $name): array
    {
        $path = self::FIXTURES . '/' . $name;
        self::assertFileExists($path);
        $doc = json_decode((string) file_get_contents($path), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($doc, $name . ' : fixture non objet');

        return $doc;
    }
}
