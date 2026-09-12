<?php

declare(strict_types=1);

namespace App\Service\Basketball;

use App\Entity\Competition;

/**
 * The bridge between an FBI division CODE (the club-side label the xlsx import
 * stores as {@see Competition}::name, e.g. « PNM », « RF3 »,
 * « CRMLSM ») and a FFBB engagement row (« Régionale masculine seniors -
 * Division 2 » + catégorie/niveau/sexe). Both sides are reduced to the SAME
 * signature — {level, division, gender, category, type} — so the pairing dialog
 * can SUGGEST the team a manager already mapped at import time, instead of
 * making him redo the appariement.
 *
 * Stateless, one dependency ({@see VenueLabelNormalizer}, the single home of
 * FBI/FFBB label folding — never a second normalisation). Unit-testable alone.
 *
 * Signature = array{level: ?string, division: ?int, gender: ?string,
 * category: ?string, type: string}. `type` is a string (not CompetitionType)
 * because FRIENDLY — an amical, NEVER bridged — has no enum case.
 *
 * @phpstan-type Signature array{level: string|null, division: int|null, gender: string|null, category: string|null, type: string}
 */
final readonly class FbiDivisionSignature
{
    public const string TYPE_CHAMPIONSHIP = 'CHAMPIONSHIP';
    public const string TYPE_CUP = 'CUP';
    public const string TYPE_BRASSAGE = 'BRASSAGE';
    public const string TYPE_FRIENDLY = 'FRIENDLY';

    public function __construct(private VenueLabelNormalizer $normalizer) {}

    /**
     * Parse an FBI division code into its signature. Null when the code carries
     * no sexe (M/F) — not a real division code, so it can never bridge.
     *
     * @phpstan-return Signature|null
     */
    public function fromCode(string $code): ?array
    {
        // The FBI code appends a POULE number after a separator (« DFU15-2 » →
        // poule 2, dropped) — a GLUED digit (« RF3 ») is the DIVISION, kept.
        $withoutPoule = (string) preg_replace('/[\s\-]+\d+\s*$/u', '', trim($code));
        $normalized = $this->normalizer->normalize($withoutPoule);
        if ('' === $normalized) {
            return null;
        }

        $type = self::TYPE_CHAMPIONSHIP;
        if (str_starts_with($normalized, 'amical')) {
            $type = self::TYPE_FRIENDLY;
        } elseif (str_contains($normalized, 'brassage')) {
            $type = self::TYPE_BRASSAGE;
        } elseif (str_starts_with($normalized, 'crml') || str_starts_with($normalized, 'cmrl') || str_contains($normalized, 'coupe')) {
            $type = self::TYPE_CUP;
        }

        // Compact core = the code minus its type words and every space.
        $core = str_replace([' ', 'amical', 'brassage', 'crml', 'cmrl', 'coupe', 'ara'], '', $normalized);

        $level = null;
        if (str_starts_with($core, 'pn')) {
            $level = 'PN';
            $core = substr($core, 2);
        } elseif (str_starts_with($core, 'pr')) {
            $level = 'PR';
            $core = substr($core, 2);
        } elseif ('' !== $core && \in_array($core[0], ['n', 'r', 'd'], true)) {
            $level = strtoupper($core[0]);
            $core = substr($core, 1);
        }

        $category = 'SENIORS';
        if (1 === preg_match('/u(\d+)/', $core, $matches)) {
            $category = 'U' . $matches[1];
            $core = (string) preg_replace('/u\d+/', '', $core, 1);
        } elseif (str_contains($core, 've')) {
            $category = 'VETERANS';
            $core = (string) preg_replace('/ve/', '', $core, 1);
        } elseif (str_contains($core, 'loi')) {
            $category = 'LOISIR';
            $core = (string) preg_replace('/loi/', '', $core, 1);
        } elseif (str_starts_with($core, 's')) {
            // « SM »/« SF » in a cup code: a standalone seniors marker.
            $core = substr($core, 1);
        }

        $gender = null;
        if (str_contains($core, 'f')) {
            $gender = 'F';
            $core = (string) preg_replace('/f/', '', $core, 1);
        } elseif (str_contains($core, 'm')) {
            $gender = 'M';
            $core = (string) preg_replace('/m/', '', $core, 1);
        }
        if (null === $gender) {
            return null; // no sexe → not a real division code
        }

        $division = null;
        if (1 === preg_match('/\d+/', $core, $digits)) {
            $division = (int) $digits[0];
        }

        return ['level' => $level, 'division' => $division, 'gender' => $gender, 'category' => $category, 'type' => $type];
    }

    /**
     * The equivalent signature of a FFBB engagement row. Never null: a FFBB
     * engagement always carries a sexe and a competition name.
     *
     * @phpstan-return Signature
     */
    public function fromFfbbRow(?string $category, ?string $level, ?string $gender, string $competitionName): array
    {
        $normalizedName = $this->normalizer->normalize($competitionName);
        $type = self::TYPE_CHAMPIONSHIP;
        if (str_contains($normalizedName, 'coupe')) {
            $type = self::TYPE_CUP;
        } elseif (str_contains($normalizedName, 'brassage')) {
            $type = self::TYPE_BRASSAGE;
        }

        $genderText = $this->normalizer->normalize(($gender ?? '') . ' ' . $competitionName);
        $genderSig = null;
        if (str_contains($genderText, 'femin')) {
            $genderSig = 'F';
        } elseif (str_contains($genderText, 'mascul')) {
            $genderSig = 'M';
        }

        $levelText = $this->normalizer->normalize(($level ?? '') . ' ' . $competitionName);
        $levelSig = null;
        if (str_contains($levelText, 'pre national')) {
            $levelSig = 'PN';
        } elseif (str_contains($levelText, 'pre regional')) {
            $levelSig = 'PR';
        } elseif (str_contains($levelText, 'national')) {
            $levelSig = 'N';
        } elseif (str_contains($levelText, 'regional')) {
            $levelSig = 'R';
        } elseif (str_contains($levelText, 'departemental')) {
            $levelSig = 'D';
        }

        $categoryText = $this->normalizer->normalize(($category ?? '') . ' ' . $competitionName);
        $categorySig = 'SENIORS';
        if (1 === preg_match('/u\s*(\d+)/', $categoryText, $matches)) {
            $categorySig = 'U' . $matches[1];
        } elseif (str_contains($categoryText, 'veteran')) {
            $categorySig = 'VETERANS';
        } elseif (str_contains($categoryText, 'loisir')) {
            $categorySig = 'LOISIR';
        }

        $divisionSig = null;
        if (1 === preg_match('/division\s+(\d+)/', $normalizedName, $division)) {
            $divisionSig = (int) $division[1];
        }

        return ['level' => $levelSig, 'division' => $divisionSig, 'gender' => $genderSig, 'category' => $categorySig, 'type' => $type];
    }

    /**
     * Does a code signature bridge to a FFBB one? Type, sexe and category must
     * be equal; level and division are compared ONLY when both sides carry them
     * (« RF3 » ⇄ « … Division 3 » is exact, but a name without « Division n »
     * does not block). An amical (FRIENDLY) never bridges.
     *
     * @phpstan-param Signature|null $code
     * @phpstan-param Signature      $ffbb
     */
    public function bridges(?array $code, array $ffbb): bool
    {
        if (null === $code || self::TYPE_FRIENDLY === $code['type']) {
            return false;
        }
        if ($code['type'] !== $ffbb['type'] || $code['gender'] !== $ffbb['gender'] || $code['category'] !== $ffbb['category']) {
            return false;
        }
        if (null !== $code['level'] && null !== $ffbb['level'] && $code['level'] !== $ffbb['level']) {
            return false;
        }

        return null === $code['division'] || null === $ffbb['division'] || $code['division'] === $ffbb['division'];
    }
}
