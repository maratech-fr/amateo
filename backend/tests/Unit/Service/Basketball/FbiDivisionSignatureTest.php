<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Basketball;

use App\Service\Basketball\FbiDivisionSignature;
use App\Service\Basketball\VenueLabelNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * P4-200 (C1) — le pont entre un code de division FBI (le libellé xlsx côté club,
 * « PNM », « RF3», « CRMLSM ») et une ligne d'engagement FFBB (« Régionale
 * masculine seniors - Division 2 » + catégorie/niveau/sexe), réduits à la même
 * signature {niveau, division, sexe, catégorie, type}.
 *
 * Codes réels du fondateur : le « -k » collé après un séparateur est un n° de
 * POULE (ignoré, « DFU15-2 » et « DFU15-6 » → même signature) ; un chiffre COLLÉ
 * est une division (« RF3 », « DM2 ») ; « DFU11 » et « DFU11-2 » ont la même
 * signature (l'ambiguïté « deux équipes » se tranche côté contrôleur). Les coupes
 * (CRML/CMRL/ARA COUPE) et brassages entrent dans le pont ; un amical, jamais.
 */
#[Group('phase1')]
final class FbiDivisionSignatureTest extends TestCase
{
    private FbiDivisionSignature $signature;

    /**
     * @return array<string, array{0: string, 1: array{level: string|null, division: int|null, gender: string|null, category: string|null, type: string}}>
     */
    public static function codes(): array
    {
        return [
            'pré nationale masculine' => ['PNM', ['level' => 'PN', 'division' => null, 'gender' => 'M', 'category' => 'SENIORS', 'type' => 'CHAMPIONSHIP']],
            'pré régionale masculine' => ['PRM', ['level' => 'PR', 'division' => null, 'gender' => 'M', 'category' => 'SENIORS', 'type' => 'CHAMPIONSHIP']],
            'régionale féminine div 3' => ['RF3', ['level' => 'R', 'division' => 3, 'gender' => 'F', 'category' => 'SENIORS', 'type' => 'CHAMPIONSHIP']],
            'départementale masculine div 2' => ['DM2', ['level' => 'D', 'division' => 2, 'gender' => 'M', 'category' => 'SENIORS', 'type' => 'CHAMPIONSHIP']],
            'régionale masculine U21' => ['RMU21', ['level' => 'R', 'division' => null, 'gender' => 'M', 'category' => 'U21', 'type' => 'CHAMPIONSHIP']],
            'départementale masculine vétérans' => ['DMVE', ['level' => 'D', 'division' => null, 'gender' => 'M', 'category' => 'VETERANS', 'type' => 'CHAMPIONSHIP']],
            'départementale féminine loisir' => ['DFLOI', ['level' => 'D', 'division' => null, 'gender' => 'F', 'category' => 'LOISIR', 'type' => 'CHAMPIONSHIP']],
            'poule -2 ignorée' => ['DFU15-2', ['level' => 'D', 'division' => null, 'gender' => 'F', 'category' => 'U15', 'type' => 'CHAMPIONSHIP']],
            'poule -6 ignorée (même signature)' => ['DFU15-6', ['level' => 'D', 'division' => null, 'gender' => 'F', 'category' => 'U15', 'type' => 'CHAMPIONSHIP']],
            'DFU11' => ['DFU11', ['level' => 'D', 'division' => null, 'gender' => 'F', 'category' => 'U11', 'type' => 'CHAMPIONSHIP']],
            'DFU11-2 (même signature que DFU11)' => ['DFU11-2', ['level' => 'D', 'division' => null, 'gender' => 'F', 'category' => 'U11', 'type' => 'CHAMPIONSHIP']],
            'coupe CRML U13 F' => ['CRMLU13F', ['level' => null, 'division' => null, 'gender' => 'F', 'category' => 'U13', 'type' => 'CUP']],
            'coupe CMRL U13 M (coquille)' => ['CMRLU13M', ['level' => null, 'division' => null, 'gender' => 'M', 'category' => 'U13', 'type' => 'CUP']],
            'coupe CRML seniors M' => ['CRMLSM', ['level' => null, 'division' => null, 'gender' => 'M', 'category' => 'SENIORS', 'type' => 'CUP']],
            'ARA COUPE seniors M' => ['ARA COUPE SM', ['level' => null, 'division' => null, 'gender' => 'M', 'category' => 'SENIORS', 'type' => 'CUP']],
            'brassage régional féminin U13' => ['RFU13 Brassage', ['level' => 'R', 'division' => null, 'gender' => 'F', 'category' => 'U13', 'type' => 'BRASSAGE']],
            'amical (jamais ponté)' => ['Amical PNM', ['level' => 'PN', 'division' => null, 'gender' => 'M', 'category' => 'SENIORS', 'type' => 'FRIENDLY']],
        ];
    }

    /**
     * @param array{level: string|null, division: int|null, gender: string|null, category: string|null, type: string} $expected
     */
    #[DataProvider('codes')]
    public function testFromCodeParsesTheFbiDivisionCode(string $code, array $expected): void
    {
        self::assertSame($expected, $this->signature->fromCode($code));
    }

    public function testAnUnknownStringHasNoSignature(): void
    {
        self::assertNull($this->signature->fromCode('XYZ'), 'no sexe → not a real division code');
        self::assertNull($this->signature->fromCode(''), 'empty → no signature');
    }

    public function testFromFfbbRowReadsCategoryLevelGenderAndTheDivisionFromTheName(): void
    {
        self::assertSame(
            ['level' => 'R', 'division' => 2, 'gender' => 'M', 'category' => 'SENIORS', 'type' => 'CHAMPIONSHIP'],
            $this->signature->fromFfbbRow('Seniors', 'Régional', 'Masculin', 'Régionale masculine seniors - Division 2'),
        );
    }

    public function testFromFfbbRowInfersCupFromTheNameAndKeepsNoDivision(): void
    {
        self::assertSame(
            ['level' => null, 'division' => null, 'gender' => 'M', 'category' => 'U18', 'type' => 'CUP'],
            $this->signature->fromFfbbRow('U18', null, 'Masculin', 'Coupe du Rhône U18'),
        );
    }

    public function testBridgeMatchesEqualSignaturesAndToleratesAMissingDivision(): void
    {
        $rm2 = $this->signature->fromCode('RM2');
        $withDivision = $this->signature->fromFfbbRow('Seniors', 'Régional', 'Masculin', 'Régionale masculine seniors - Division 2');
        $withoutDivision = $this->signature->fromFfbbRow('Seniors', 'Régional', 'Masculin', 'Régionale masculine seniors');

        self::assertTrue($this->signature->bridges($rm2, $withDivision), 'exact division match');
        self::assertTrue($this->signature->bridges($rm2, $withoutDivision), 'a side without « Division n » does not block');
    }

    public function testBridgeRefusesADivisionOrGenderMismatch(): void
    {
        $rm2 = $this->signature->fromCode('RM2');
        $division3 = $this->signature->fromFfbbRow('Seniors', 'Régional', 'Masculin', 'Régionale masculine seniors - Division 3');
        $feminine = $this->signature->fromFfbbRow('Seniors', 'Régional', 'Féminin', 'Régionale féminine seniors - Division 2');

        self::assertFalse($this->signature->bridges($rm2, $division3), 'both divisions known and differ');
        self::assertFalse($this->signature->bridges($this->signature->fromCode('RF3'), $feminine), 'gender F vs M — never');
    }

    public function testAnAmicalNeverBridges(): void
    {
        $amical = $this->signature->fromCode('Amical PNM');
        $pn = $this->signature->fromFfbbRow('Seniors', 'Pré National', 'Masculin', 'Pré nationale masculine');

        self::assertFalse($this->signature->bridges($amical, $pn), 'an amical is never bridged');
    }

    public function testACupBridgesACupIgnoringLevel(): void
    {
        $code = $this->signature->fromCode('CRMLSM');
        $ffbb = $this->signature->fromFfbbRow('Seniors', 'Départemental', 'Masculin', 'Coupe du Rhône et Métropole de Lyon - Seniors Masculins');

        self::assertTrue($this->signature->bridges($code, $ffbb), 'a cup bridges on category/gender/type, level ignored');
    }

    protected function setUp(): void
    {
        $this->signature = new FbiDivisionSignature(new VenueLabelNormalizer);
    }
}
