<?php

declare(strict_types=1);

namespace App\Tests\CrossStack;

use App\Service\ParcImport\ParcPayloadValidator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Chantier G PR3 — LE garde de parité prompt ⇄ validateur. Le gabarit front
 * (`frontend/src/features/wizard/parcImport/promptTemplate.ts`) dicte au chatbot le format
 * `parc-v1` ; le validateur backend ({@see ParcPayloadValidator}) décide ce qui est recevable.
 * Si les deux dérivent — une version, une section, une énumération de genre ou un champ change
 * d'un côté sans l'autre — le chatbot produit un JSON que le serveur refuse (ou l'app perd des
 * données), en silence. Ce test rend la dérive OPPOSABLE, dans les DEUX sens.
 *
 * VÉRITÉ = le validateur (reflection sur ses constantes privées `FORMAT`/`REQUIRED_SECTIONS`/
 * `GENDERS`, plus ses littéraux de champs). Le gabarit front EXPOSE une constante machine-lisible
 * `PARC_V1_SPEC` (version, sections requises/optionnelles, genres, champs par section) QUE
 * `buildParcPrompt` consomme pour rédiger le prompt — ainsi le prompt et la spec ne peuvent pas
 * diverger côté front. Ce test lit cette constante dans la SOURCE TS (pas d'import JS depuis PHP,
 * patron {@see TsUnionsMatchPhpEnumsTest} / {@see FrontRederivationRegistryTest}) et la confronte
 * au validateur.
 *
 * Portée : prompt ⇄ VALIDATEUR (ce que le serveur accepte). Les champs lus par l'ANALYZER seul
 * (`seances`, `seances_communes`, `equipes` d'une mutualisation) que le prompt doit AUSSI enseigner
 * sont gardés côté front par `promptTemplate.test.ts`, pas ici.
 *
 * Régime : garde de FRONTIÈRE statique — groupe `contract` / job `engine-semantics`, PAS un step
 * `blocking-tests` (aucun invariant runtime multi-tenant).
 */
#[Group('contract')]
final class ParcPromptValidatorParityTest extends TestCase
{
    private const string TEMPLATE = __DIR__ . '/../../../frontend/src/features/wizard/parcImport/promptTemplate.ts';

    /**
     * Les champs que le VALIDATEUR nomme — sa liste blanche opposable. Chacun est vérifié
     * présent À LA FOIS dans la source du validateur (dérive backend) ET dans la spec front
     * (dérive front) : la liste ne peut pas diverger en silence d'un côté.
     *
     * @var list<string>
     */
    private const array VALIDATOR_FIELDS = ['nom', 'genre', 'categorie', 'jour', 'debut', 'duree', 'equipes_en_parallele', 'equipe', 'gymnase'];

    public function testTemplateExposesTheParcV1Spec(): void
    {
        self::assertFileExists(self::TEMPLATE, 'le gabarit promptTemplate.ts doit exister');
        self::assertNotSame('', $this->specBlock(), 'promptTemplate.ts doit exporter une constante PARC_V1_SPEC');
    }

    public function testVersionMatchesTheValidatorFormat(): void
    {
        $block = $this->specBlock();
        self::assertSame(1, preg_match('/version\s*:\s*[\'"]([^\'"]+)[\'"]/', $block, $m), 'PARC_V1_SPEC doit déclarer une « version »');
        self::assertSame($this->validatorString('FORMAT'), $m[1], 'la version du gabarit doit égaler ParcPayloadValidator::FORMAT');
    }

    public function testRequiredSectionsMatchTheValidator(): void
    {
        $expected = $this->validatorList('REQUIRED_SECTIONS');
        $actual = $this->tsList($this->specBlock(), 'requiredSections');
        sort($expected);
        sort($actual);
        self::assertSame($expected, $actual, 'les sections requises du gabarit et du validateur doivent coïncider exactement');
    }

    public function testCoachsIsDeclaredOptional(): void
    {
        // `coachs` est la SEULE section optionnelle (le validateur ne la vérifie que si présente).
        $optional = $this->tsList($this->specBlock(), 'optionalSections');
        self::assertContains('coachs', $optional, 'le gabarit doit déclarer « coachs » comme section optionnelle');
    }

    public function testGendersMatchTheValidator(): void
    {
        $expected = $this->validatorList('GENDERS');
        $actual = $this->tsList($this->specBlock(), 'genders');
        sort($expected);
        sort($actual);
        self::assertSame($expected, $actual, 'l\'énumération de genre du gabarit et du validateur doivent coïncider (M/F/MIXTE)');
    }

    public function testEachValidatorFieldIsTaughtByTheTemplate(): void
    {
        $block = $this->specBlock();
        foreach (self::VALIDATOR_FIELDS as $field) {
            self::assertSame(
                1,
                preg_match('/[\'"]' . preg_quote($field, '/') . '[\'"]/', $block),
                \sprintf('le gabarit doit enseigner le champ « %s » que le validateur impose', $field),
            );
        }
    }

    public function testValidatorStillNamesEachCanonicalField(): void
    {
        // Sens inverse : si le validateur RENOMME un champ, la liste canonique ci-dessus devient
        // fausse et doit rougir ici — avant que le gabarit ne continue d'enseigner un champ mort.
        $source = (string) file_get_contents((string) new ReflectionClass(ParcPayloadValidator::class)->getFileName());
        foreach (self::VALIDATOR_FIELDS as $field) {
            self::assertStringContainsString(
                '\'' . $field . '\'',
                $source,
                \sprintf('ParcPayloadValidator doit toujours nommer le champ « %s » (sinon réaligner VALIDATOR_FIELDS)', $field),
            );
        }
    }

    /**
     * Le corps de la constante `PARC_V1_SPEC` (entre le `{` d'ouverture et le `} as const` final),
     * ou '' si elle est absente.
     */
    private function specBlock(): string
    {
        $source = (string) file_get_contents(self::TEMPLATE);
        if (1 !== preg_match('/PARC_V1_SPEC\s*=\s*\{(.*?)\}\s*as const/s', $source, $m)) {
            return '';
        }

        return $m[1];
    }

    /**
     * Les chaînes citées d'un tableau `clé: [ "a", "b" ]` dans la spec TS.
     *
     * @return list<string>
     */
    private function tsList(string $block, string $key): array
    {
        if (1 !== preg_match('/' . preg_quote($key, '/') . '\s*:\s*\[([^\]]*)\]/s', $block, $m)) {
            self::fail(\sprintf('la clé « %s » est absente de PARC_V1_SPEC', $key));
        }
        preg_match_all('/[\'"]([^\'"]+)[\'"]/', $m[1], $items);

        return $items[1];
    }

    private function validatorString(string $const): string
    {
        $value = new ReflectionClass(ParcPayloadValidator::class)->getConstant($const);
        self::assertIsString($value, \sprintf('ParcPayloadValidator::%s doit être une chaîne', $const));

        return $value;
    }

    /**
     * @return list<string>
     */
    private function validatorList(string $const): array
    {
        $value = new ReflectionClass(ParcPayloadValidator::class)->getConstant($const);
        self::assertIsArray($value, \sprintf('ParcPayloadValidator::%s doit être un tableau', $const));
        $out = [];
        foreach ($value as $item) {
            self::assertIsString($item);
            $out[] = $item;
        }

        return $out;
    }
}
