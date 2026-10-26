<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\PriorityTier;
use App\Enum\Gender;
use App\Service\Basketball\TeamCreationSuggester;
use App\Tests\TenantGucTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Refonte import IA PR2 (D9) — MAISON UNIQUE de la règle FBI de création d'équipe
 * ({@see TeamCreationSuggester}, nom indicatif). Les TROIS imports (FBI Excel, API FFBB, parc IA)
 * en dérivent leurs suggestions, de sorte qu'une équipe créée reçoit PARTOUT le même rang / genre /
 * entente proposés — fin des trois barèmes divergents (défaut D9).
 *
 * Promesses (option A — TANK écrit ROUGE, NEO implémente le service) :
 *  - RANG par catégorie + numéro : seniors n°1/2/3/4+ → S/A/B/C ; jeunes (toute catégorie d'âge)
 *    n°1/2/3+ → A/B/C ; loisir → D ; SANS numéro → C (jamais le défaut D) ;
 *  - le NUMÉRO est lu dans le NOM quand la colonne est absente/vide (« U18F3 » → 3, « SM2 » → 2) ;
 *  - le GENRE est lu au nom sur un MOTIF CLAIR (« U13F1 »/« SF2 » → F, « U18M1 »/« SM1 » → M) et
 *    vaut NULL sinon (« U13 », « Loisir Détente ») — refonte PR2 « plus MIXTE » : jamais deviné,
 *    jamais MIXTE d'office (le genre est tranché à l'import, désormais obligatoire) ;
 *  - l'ENTENTE (CTC) est SUGGÉRÉE quand le libellé porte « CTC » ou « ENTENTE » (casse indifférente),
 *    sinon jamais ;
 *  - les LABELS de rang sont résolus en ids VIA le repository {@see PriorityTier} (jamais un id en
 *    dur) : un rang S/A/B/C/D rend l'id seedé correspondant.
 *
 * Signature proposée (NEO) — service (repo PriorityTier injecté pour label → id) :
 *   suggest(string $name, string $category, string $number): array{tierId: int, gender: ?Gender, ctc: bool}
 * `number` est la valeur de la colonne (« » si absente → lu dans le nom). NEO peut renommer la
 * méthode / adapter la forme MÉCANIQUEMENT ; la RÈGLE (le barème ci-dessous) ne bouge pas.
 *
 * Rangs résolus PAR LABEL (jamais un id en dur) : on seede la table canonique label→id puis on lit
 * l'id attendu via {@see tierIdForLabel} — patron {@see FfbbExcelImporterTest::seedPriorityTiers}.
 */
#[Group('integration')]
final class TeamCreationSuggesterTest extends KernelTestCase
{
    use TenantGucTrait;

    private EntityManagerInterface $em;

    private TeamCreationSuggester $suggester;

    /** @return iterable<string, array{string, string, string, string}> name, category, numberColumn, label de rang attendu */
    public static function rankProvider(): iterable
    {
        // Seniors n°1/2/3/4+ → S/A/B/C (numéro en colonne).
        yield 'senior n°1 → S' => ['SM1', 'Seniors', '1', 'S'];
        yield 'senior n°2 → A' => ['SF2', 'Seniors', '2', 'A'];
        yield 'senior n°3 → B' => ['SM3', 'Seniors', '3', 'B'];
        yield 'senior n°4 → C' => ['SM4', 'Seniors', '4', 'C'];
        yield 'senior n°5 → C (n°4 et +)' => ['SM5', 'Seniors', '5', 'C'];
        // Jeunes n°1/2/3+ → A/B/C.
        yield 'jeune n°1 → A' => ['U13M1', 'U13', '1', 'A'];
        yield 'jeune n°2 → B' => ['U13F2', 'U13', '2', 'B'];
        yield 'jeune n°3 → C' => ['U15M3', 'U15', '3', 'C'];
        yield 'jeune n°4 → C (n°3 et +)' => ['U15M4', 'U15', '4', 'C'];
        // Loisir → D.
        yield 'loisir → D' => ['Loisir Détente', 'Loisir', '1', 'D'];
        // Sans numéro → C (JAMAIS le défaut D).
        yield 'senior sans numéro → C' => ['Vétérans', 'Seniors', '', 'C'];
        yield 'jeune sans numéro → C' => ['U17', 'U17', '', 'C'];
    }

    /** @return iterable<string, array{string, ?Gender}> name, genre attendu (null si aucun motif clair) */
    public static function genderProvider(): iterable
    {
        yield 'U13F1 → F' => ['U13F1', Gender::F];
        yield 'U18M2 → M' => ['U18M2', Gender::M];
        yield 'SF2 → F' => ['SF2', Gender::F];
        yield 'SM1 → M' => ['SM1', Gender::M];
        yield 'U13 sans marqueur → null' => ['U13', null];
        yield 'Loisir Détente → null' => ['Loisir Détente', null];
    }

    #[DataProvider('rankProvider')]
    public function testRankFollowsTheFbiScaleResolvedToTierIdByLabel(string $name, string $category, string $number, string $expectedLabel): void
    {
        $out = $this->suggester->suggest($name, $category, $number);

        self::assertSame($this->tierIdForLabel($expectedLabel), $out['tierId'], \sprintf('« %s » (%s n°%s) → rang %s', $name, $category, '' === $number ? '—' : $number, $expectedLabel));
    }

    #[DataProvider('genderProvider')]
    public function testGenderIsReadFromTheNameOnAClearPatternElseNull(string $name, ?Gender $expected): void
    {
        self::assertSame($expected, $this->suggester->suggest($name, 'Seniors', '1')['gender'], 'le genre est lu sur motif clair, sinon null (plus MIXTE d\'office)');
    }

    public function testNumberIsReadFromTheNameWhenTheColumnIsBlank(): void
    {
        // Pas de colonne numéro (« ») : le rang se lit dans le NOM. « U18F3 » → n°3 → jeune C ;
        // « SM2 » → n°2 → senior A.
        self::assertSame($this->tierIdForLabel('C'), $this->suggester->suggest('U18F3', 'U18', '')['tierId'], '« U18F3 » sans colonne → numéro 3 lu dans le nom → C');
        self::assertSame($this->tierIdForLabel('A'), $this->suggester->suggest('SM2', 'Seniors', '')['tierId'], '« SM2 » sans colonne → numéro 2 lu dans le nom → A');
    }

    public function testCtcIsSuggestedWhenTheLabelCarriesCtcOrEntenteElseNever(): void
    {
        self::assertTrue($this->suggester->suggest('U15F CTC Rillieux', 'U15', '1')['ctc'], '« CTC » dans le libellé → entente suggérée');
        self::assertTrue($this->suggester->suggest('Entente Nord U13M', 'U13', '1')['ctc'], '« Entente » (casse indifférente) → entente suggérée');
        self::assertFalse($this->suggester->suggest('SM1', 'Seniors', '1')['ctc'], 'aucun marqueur CTC/ENTENTE → entente jamais suggérée');
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->suggester = self::getContainer()->get(TeamCreationSuggester::class);
        $this->seedPriorityTiers();
    }

    /**
     * Les 5 rangs de référence (mapping canonique label→id), idempotents : `priority_tier` est GLOBAL.
     * Patron {@see FfbbExcelImporterTest::seedPriorityTiers}.
     */
    private function seedPriorityTiers(): void
    {
        foreach ([[1, 'S', 'Elite'], [2, 'A', 'Régional+'], [3, 'B', 'Régional'], [4, 'C', 'Départemental'], [5, 'D', 'Loisir']] as [$id, $label, $name]) {
            if ($this->em->getRepository(PriorityTier::class)->find($id) instanceof PriorityTier) {
                continue;
            }
            $tier = (new PriorityTier)->setId($id)->setLabel($label)->setName($name)
                ->setColor('#000000')->setOrToolsWeight(1)->setDefaultMinSessions(1);
            $this->em->persist($tier);
        }
        $this->em->flush();
    }

    private function tierIdForLabel(string $label): int
    {
        $tier = $this->em->getRepository(PriorityTier::class)->findOneBy(['label' => $label]);
        self::assertInstanceOf(PriorityTier::class, $tier, \sprintf('le rang « %s » doit être seedé', $label));

        return $tier->getId();
    }
}
