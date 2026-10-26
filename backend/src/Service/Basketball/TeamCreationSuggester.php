<?php

declare(strict_types=1);

namespace App\Service\Basketball;

use App\Entity\PriorityTier;
use App\Enum\Gender;
use App\Service\ParcImport\ParcImportAnalyzer;
use App\Service\ParcImport\ParcImportExecutor;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Refonte import IA PR2 (D9) — la MAISON UNIQUE de la règle FBI de création d'équipe. Les TROIS
 * imports (FBI Excel {@see FfbbExcelImporter}, API FFBB {@see FfbbTeamImporter}, parc IA
 * {@see ParcImportAnalyzer}/{@see ParcImportExecutor})
 * en dérivent leurs suggestions, de sorte qu'une équipe créée reçoit PARTOUT le même rang / genre /
 * entente proposés — fin des trois barèmes divergents. Prouvée par {@see TeamCreationSuggesterTest}.
 *
 * Barème :
 *  - RANG par catégorie + numéro : seniors n°1/2/3/4+ → S/A/B/C ; jeunes (toute catégorie d'âge)
 *    n°1/2/3+ → A/B/C ; loisir → D ; SANS numéro → C (jamais le défaut D) ;
 *  - le NUMÉRO est lu dans le NOM quand la colonne est absente/vide : le groupe de chiffres qui SUIT
 *    le marqueur de genre (« U18F3 » → 3, « SM2 » → 2) ;
 *  - le GENRE est lu au nom sur un MOTIF CLAIR (« U13F1 »/« SF2 » → F, « U18M1 »/« SM1 » → M) et
 *    vaut NULL sinon (« U13 », « Loisir Détente ») — « plus MIXTE d'office » : jamais deviné ;
 *  - l'ENTENTE (CTC) est SUGGÉRÉE quand le libellé porte « CTC » ou « ENTENTE » (casse indifférente) ;
 *  - les LABELS de rang sont résolus en ids VIA le repository {@see PriorityTier} (jamais un id en dur) ;
 *    à défaut d'un rang canonique seedé (installation nue), repli sur le rang par défaut historique.
 */
final class TeamCreationSuggester
{
    /** Rang par défaut (D) quand le label calculé n'est pas seedé — miroir du défaut historique FBI. */
    private const int DEFAULT_PRIORITY_TIER_ID = 5;

    /** @var array<string, int>|null table canonique label → id, chargée une fois (priority_tier est GLOBAL). */
    private ?array $tierIdByLabel = null;

    public function __construct(private readonly EntityManagerInterface $entityManager) {}

    /**
     * La suggestion de création pour une équipe : rang (id résolu par label), genre (null si aucun
     * motif clair) et entente. `$number` est la valeur de la colonne numéro (« » si absente → lu
     * dans le nom).
     *
     * @return array{tierId: int, gender: ?Gender, ctc: bool}
     */
    public function suggest(string $name, string $category, string $number): array
    {
        return [
            'tierId' => $this->resolveTierId($this->tierLabel($category, $this->effectiveNumber($name, $number))),
            'gender' => $this->readGender($name),
            'ctc' => $this->suggestsCtc($name),
        ];
    }

    /**
     * Le LABEL de rang d'après la catégorie et le numéro. Seniors n°1/2/3/4+ → S/A/B/C ; jeunes
     * n°1/2/3+ → A/B/C ; loisir → D ; sans numéro (0) → C (jamais le défaut D).
     */
    private function tierLabel(string $category, int $rank): string
    {
        $cat = mb_strtolower($category, 'UTF-8');
        if (str_contains($cat, 'loisir')) {
            return 'D';
        }

        if (str_contains($cat, 'senior')) {
            return match (true) {
                1 === $rank => 'S',
                2 === $rank => 'A',
                3 === $rank => 'B',
                default => 'C', // n°4 et +, ET sans numéro
            };
        }

        // Jeunes (toute catégorie d'âge) : n°1/2/3+ → A/B/C ; sans numéro → C.
        return match (true) {
            1 === $rank => 'A',
            2 === $rank => 'B',
            default => 'C',
        };
    }

    /**
     * Le numéro de l'équipe : la colonne si elle porte un entier, sinon LU dans le nom (le groupe de
     * chiffres qui suit le marqueur de genre : « U18F3 » → 3, « SM2 » → 2). Aucun → 0 (sans numéro).
     */
    private function effectiveNumber(string $name, string $numberColumn): int
    {
        if ('' !== $numberColumn && ctype_digit($numberColumn)) {
            return (int) $numberColumn;
        }
        if (1 === preg_match('/[MF](\d+)/i', $name, $m)) {
            return (int) $m[1];
        }

        return 0;
    }

    /**
     * Le genre LU dans le nom, seulement dans un motif clair : une catégorie d'âge suivie de la lettre
     * (`U13F1`, `U18M`), ou `SM…`/`SF…`. Jamais la 1re lettre M/F au hasard, jamais MIXTE d'office : à
     * défaut de motif, `null` (le genre est tranché à l'import, désormais obligatoire).
     */
    private function readGender(string $name): ?Gender
    {
        if (1 === preg_match('/\bU\d+\s*([MF])/i', $name, $m)) {
            return Gender::from(mb_strtoupper($m[1], 'UTF-8'));
        }
        if (1 === preg_match('/\bS([MF])/i', $name, $m)) {
            return Gender::from(mb_strtoupper($m[1], 'UTF-8'));
        }

        return null;
    }

    /**
     * L'entente (CTC) est SUGGÉRÉE quand le libellé porte « CTC » ou « ENTENTE » (casse indifférente),
     * sans RIEN décider : le gestionnaire reste libre de décocher. Aucun marqueur → jamais suggérée.
     */
    private function suggestsCtc(string $name): bool
    {
        $upper = mb_strtoupper($name, 'UTF-8');

        return str_contains($upper, 'CTC') || str_contains($upper, 'ENTENTE');
    }

    private function resolveTierId(string $label): int
    {
        return $this->tierIdByLabel()[$label] ?? self::DEFAULT_PRIORITY_TIER_ID;
    }

    /**
     * @return array<string, int>
     */
    private function tierIdByLabel(): array
    {
        if (null === $this->tierIdByLabel) {
            $map = [];
            foreach ($this->entityManager->getRepository(PriorityTier::class)->findAll() as $tier) {
                $map[$tier->getLabel()] = $tier->getId();
            }
            $this->tierIdByLabel = $map;
        }

        return $this->tierIdByLabel;
    }
}
