<?php

declare(strict_types=1);

namespace App\Tests\CrossStack;

use App\Service\ParcImport\ParcPayloadValidator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Lot correctif import IA (« G2 ») PR1 — LE garde de parité prompt ⇄ validateur, RECALÉ sur le
 * format `parc-v2`. Le gabarit front
 * (`frontend/src/features/wizard/parcImport/promptTemplate.ts`) dicte au chatbot le format parc-v2 ;
 * le validateur backend ({@see ParcPayloadValidator}) décide ce qui est recevable. Si les deux
 * dérivent — la version, un champ de case, le plafond d'équipes par case — le chatbot produit un
 * JSON que le serveur refuse (ou l'app perd des données), en silence. Ce test rend la dérive
 * OPPOSABLE, dans les DEUX sens.
 *
 * VÉRITÉ = le validateur (reflection sur `FORMAT`/`MAX_TEAMS_PER_CASE`, plus ses littéraux de champ
 * de case). Le gabarit front EXPOSE une constante machine-lisible `PARC_V2_SPEC` (version, champs
 * de case, plafond) que `buildParcPrompt` consomme — prompt et spec ne peuvent pas diverger côté
 * front. Ce test lit cette constante dans la SOURCE TS (pas d'import JS depuis PHP, patron
 * {@see TsUnionsMatchPhpEnumsTest} / {@see FrontRederivationRegistryTest}) et la confronte au validateur.
 *
 * Portée : prompt ⇄ VALIDATEUR (ce que le serveur accepte). Le genre et la catégorie ne sont PLUS
 * dans le format parc-v2 (déduits par {@see \App\Service\ParcImport\TeamNameInterpreter}), ils ne
 * figurent donc plus ici. Les autres promesses du prompt (une entrée par équipe, contexte
 * correcteur, phrase de source) sont gardées côté front par `promptTemplate.test.ts`.
 *
 * Régime : garde de FRONTIÈRE statique — groupe `contract` / job `engine-semantics`, PAS un step
 * `blocking-tests`.
 */
#[Group('contract')]
final class ParcPromptValidatorParityTest extends TestCase
{
    private const string TEMPLATE = __DIR__ . '/../../../frontend/src/features/wizard/parcImport/promptTemplate.ts';

    /**
     * Les champs de CASE que le VALIDATEUR nomme — sa liste blanche opposable. Chacun est vérifié
     * présent À LA FOIS dans la source du validateur (dérive backend) ET dans la spec front (dérive
     * front) : la liste ne peut pas diverger en silence d'un côté.
     *
     * @var list<string>
     */
    private const array CASE_FIELDS = ['gymnase', 'jour', 'debut', 'duree', 'equipes', 'groupe'];

    public function testTemplateExposesTheParcV2Spec(): void
    {
        self::assertFileExists(self::TEMPLATE, 'le gabarit promptTemplate.ts doit exister');
        self::assertNotSame('', $this->specBlock(), 'promptTemplate.ts doit exporter une constante PARC_V2_SPEC');
    }

    public function testVersionMatchesTheValidatorFormat(): void
    {
        $block = $this->specBlock();
        self::assertSame(1, preg_match('/version\s*:\s*[\'"]([^\'"]+)[\'"]/', $block, $m), 'PARC_V2_SPEC doit déclarer une « version »');
        self::assertSame('parc-v2', $m[1], 'la version du gabarit doit être parc-v2');
        self::assertSame($this->validatorString('FORMAT'), $m[1], 'la version du gabarit doit égaler ParcPayloadValidator::FORMAT');
    }

    public function testEachValidatorCaseFieldIsTaughtByTheTemplate(): void
    {
        $block = $this->specBlock();
        foreach (self::CASE_FIELDS as $field) {
            self::assertSame(
                1,
                preg_match('/[\'"]' . preg_quote($field, '/') . '[\'"]/', $block),
                \sprintf('le gabarit doit enseigner le champ de case « %s » que le validateur impose', $field),
            );
        }
    }

    public function testValidatorStillNamesEachCanonicalCaseField(): void
    {
        // Sens inverse : si le validateur RENOMME un champ de case, la liste canonique devient fausse
        // et rougit ici — avant que le gabarit ne continue d'enseigner un champ mort.
        $source = (string) file_get_contents((string) new ReflectionClass(ParcPayloadValidator::class)->getFileName());
        foreach (self::CASE_FIELDS as $field) {
            self::assertStringContainsString(
                '\'' . $field . '\'',
                $source,
                \sprintf('ParcPayloadValidator doit toujours nommer le champ « %s » (sinon réaligner CASE_FIELDS)', $field),
            );
        }
    }

    public function testTeamsPerCaseCapIsTheSameOnBothSides(): void
    {
        // Le plafond d'équipes par case (bombe) : le prompt doit l'enseigner EXACTEMENT comme le
        // validateur l'applique, sinon le chatbot produit une case que le serveur refuse.
        $block = $this->specBlock();
        self::assertSame(1, preg_match('/maxTeamsPerCase\s*:\s*(\d+)/', $block, $m), 'PARC_V2_SPEC doit déclarer « maxTeamsPerCase »');
        self::assertSame($this->validatorInt('MAX_TEAMS_PER_CASE'), (int) $m[1], 'le plafond d\'équipes par case doit coïncider des deux côtés');
    }

    /**
     * Le corps de la constante `PARC_V2_SPEC` (entre le `{` d'ouverture et le `} as const` final),
     * ou '' si elle est absente.
     */
    private function specBlock(): string
    {
        $source = (string) file_get_contents(self::TEMPLATE);
        if (1 !== preg_match('/PARC_V2_SPEC\s*=\s*\{(.*?)\}\s*as const/s', $source, $m)) {
            return '';
        }

        return $m[1];
    }

    private function validatorString(string $const): string
    {
        $value = new ReflectionClass(ParcPayloadValidator::class)->getConstant($const);
        self::assertIsString($value, \sprintf('ParcPayloadValidator::%s doit être une chaîne', $const));

        return $value;
    }

    private function validatorInt(string $const): int
    {
        $value = new ReflectionClass(ParcPayloadValidator::class)->getConstant($const);
        self::assertIsInt($value, \sprintf('ParcPayloadValidator::%s doit être un entier', $const));

        return $value;
    }
}
