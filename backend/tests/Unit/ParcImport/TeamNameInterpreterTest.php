<?php

declare(strict_types=1);

namespace App\Tests\Unit\ParcImport;

use App\Service\ParcImport\TeamNameInterpreter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Lot correctif import IA (« G2 ») PR1 — service PUR qui DÉDUIT, du seul NOM d'une équipe écrit dans
 * une case parc-v2, son GENRE (M / F / MIXTE) et sa CATÉGORIE d'âge. En parc-v2 le chatbot ne rend
 * plus de section `equipes` structurée : l'appli reconstruit chaque équipe à partir du libellé lu
 * dans la case (cadrage G2 D1). Ce service est le cœur de cette déduction.
 *
 * Promesses gardées (option A — TANK écrit ROUGE, NEO implémente {@see TeamNameInterpreter}) :
 *  - un token d'âge FFBB « U<n> » suivi d'un marqueur de genre « F »/« M » → catégorie « U<n> »,
 *    genre déduit du marqueur (« U18F3 » → U18 / F) ;
 *  - un préfixe senior « SM »/« SF » → catégorie seniors, genre M / F (« SM2 » → seniors/M,
 *    « SF1 » → seniors/F) ;
 *  - un token d'âge SANS marqueur de genre → genre NULL (« U13 » → U13 / null) — refonte PR2
 *    (§0 point 1 AMENDÉ, « plus MIXTE ») : on ne DEVINE jamais un genre absent, et on ne le replie
 *    JAMAIS en MIXTE d'office (le genre est désormais OBLIGATOIRE, tranché à l'import) ;
 *  - un libellé senior sans marqueur de genre (« Vétérans ») → genre NULL (idem) ;
 *  - un libellé qui n'est PAS une équipe reconnaissable (activité, section : « Section J.Macé »,
 *    « Basket Santé ») → catégorie « À classer », genre NULL — un repère explicite que le
 *    gestionnaire reclasse ET un genre qu'il tranchera, jamais une catégorie/un genre devinés.
 *
 * Signature proposée (NEO, refonte PR2) :
 *   interpret(string $name): array{genre: 'M'|'F'|null, categorie: string}
 * `categorie` est un libellé HUMAIN ; « À classer » est la valeur-repère quand rien n'est reconnu ;
 * `genre` est `null` dès qu'aucun marqueur clair ne le donne (plus jamais MIXTE par défaut).
 *
 * Service PUR : aucune DB, aucun appariement aux entités du club (ça, c'est {@see TeamLabelMatcher}).
 */
#[Group('phase1')]
final class TeamNameInterpreterTest extends TestCase
{
    private TeamNameInterpreter $interpreter;

    /** @return iterable<string, array{string, string, string}> name, genre attendu, sous-chaîne attendue (minuscule) de la catégorie */
    public static function genderAndCategoryProvider(): iterable
    {
        // Jeunes FFBB : U<n> + marqueur de genre.
        yield 'U18F3 → U18 / F' => ['U18F3', 'F', 'u18'];
        yield 'U18M1 → U18 / M' => ['U18M1', 'M', 'u18'];
        yield 'U8F3 → U8 / F' => ['U8F3', 'F', 'u8'];
        yield 'U11M2 → U11 / M' => ['U11M2', 'M', 'u11'];

        // Seniors : SM / SF.
        yield 'SM2 → seniors / M' => ['SM2', 'M', 'senior'];
        yield 'SF1 → seniors / F' => ['SF1', 'F', 'senior'];
    }

    /** @return iterable<string, array{string}> */
    public static function unrecognisedProvider(): iterable
    {
        yield 'section scolaire' => ['Section J.Macé'];
        yield 'activité basket santé' => ['Basket Santé'];
    }

    #[DataProvider('genderAndCategoryProvider')]
    public function testDerivesGenderAndAgeCategoryFromTheName(string $name, string $expectedGender, string $categorieContains): void
    {
        $out = $this->interpreter->interpret($name);

        self::assertSame($expectedGender, $out['genre'], 'le genre est déduit du marqueur du nom, jamais deviné');
        self::assertStringContainsString($categorieContains, mb_strtolower($out['categorie']), 'la catégorie d\'âge est déduite du token du nom');
    }

    public function testAnAgeTokenWithoutAGenderMarkerHasNoGender(): void
    {
        // Refonte PR2 (§0 point 1 AMENDÉ) — « U13 » sans F/M : on ne devine pas un genre absent, et
        // on ne le replie PLUS en MIXTE d'office → genre NULL (à trancher à l'import, désormais
        // OBLIGATOIRE). La catégorie d'âge U13, elle, reste conservée.
        $out = $this->interpreter->interpret('U13');

        self::assertNull($out['genre'], 'un token d\'âge sans marqueur de genre n\'a PAS de genre (null) — jamais deviné, jamais MIXTE d\'office');
        self::assertStringContainsString('u13', mb_strtolower($out['categorie']));
    }

    public function testAnUngenderedSeniorLabelHasNoGender(): void
    {
        // Refonte PR2 — « Vétérans » : catégorie senior sans marqueur de genre → genre NULL
        // (plus MIXTE d'office), le gestionnaire tranchera.
        self::assertNull($this->interpreter->interpret('Vétérans')['genre'], 'un libellé senior sans marqueur de genre n\'a pas de genre (null)');
    }

    #[DataProvider('unrecognisedProvider')]
    public function testAnUnrecognisedActivityIsFiledUnderAClasserWithNoGender(string $name): void
    {
        // Ni token d'âge, ni préfixe senior : rien à déduire sans risque de mentir.
        // Repère explicite « À classer » + genre NULL (refonte PR2, plus MIXTE), jamais une
        // catégorie NI un genre devinés.
        $out = $this->interpreter->interpret($name);

        self::assertSame('À classer', $out['categorie'], 'un libellé non reconnu est classé « À classer », jamais deviné');
        self::assertNull($out['genre'], 'genre non déductible → null (plus MIXTE d\'office)');
    }

    protected function setUp(): void
    {
        $this->interpreter = new TeamNameInterpreter;
    }
}
