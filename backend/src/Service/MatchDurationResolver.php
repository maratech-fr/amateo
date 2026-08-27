<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\SportCategory;

/**
 * Resolves a {@see MatchDurationProfile} for a sport category (P2-54 RMM-9).
 *
 * Two questions, one home:
 * - {@see familyDefault()} — the default a category INHERITS from its age
 *   family, ignoring any per-category override. It is what the UI shows as the
 *   placeholder / group header, so the front never re-derives the defaults
 *   (front rederivation is forbidden — FrontRederivationRegistryTest).
 * - {@see resolve()} — the EFFECTIVE profile: the category's own columns when
 *   set, else the family default. This is what the footprint/radar consumes.
 *
 * Families key off the U-token in the category NAME — the same convention the
 * catalog uses for the JEUNE/SENIOR and U-x tags (CategoryCatalog: "the U-x
 * tags key off the name token"). Buckets (match / warm-up minutes):
 * - U7–U11   → 75 / 30
 * - U13–U15  → 90 / 30
 * - U18–U21  → 105 / 30
 * - Seniors and anything that maps to NO youth bucket (Vétéran, Loisir, Baby
 *   basket, U5, a custom name with no U-token, a gap like U12/U16) →
 *   the documented fallback 105 / 30 ({@see MatchDurationProfile::fallback()}).
 *   The Seniors family value is intentionally equal to that fallback, so an
 *   adult category and an unmapped one both land on 105 / 30.
 */
final class MatchDurationResolver
{
    public function familyDefault(SportCategory $category): MatchDurationProfile
    {
        $youth = $this->youthNumber($category->getName());
        if (null !== $youth) {
            if ($youth >= 7 && $youth <= 11) {
                return new MatchDurationProfile(75, 30);
            }
            if ($youth >= 13 && $youth <= 15) {
                return new MatchDurationProfile(90, 30);
            }
            if ($youth >= 18 && $youth <= 21) {
                return new MatchDurationProfile(105, 30);
            }
        }

        return MatchDurationProfile::fallback();
    }

    /**
     * The category's own values when both are set, else the family default. A
     * category stores its overrides independently, but a footprint needs BOTH
     * numbers: a half-filled row (only the match minutes overridden) still
     * borrows the missing half from its family, never a stray 0.
     */
    public function resolve(SportCategory $category): MatchDurationProfile
    {
        $default = $this->familyDefault($category);

        return new MatchDurationProfile(
            $category->getMatchMinutes() ?? $default->matchMinutes,
            $category->getWarmupMinutes() ?? $default->warmupMinutes,
        );
    }

    /** The number in a "U15"-style name token, or null when the name carries none. */
    private function youthNumber(string $name): ?int
    {
        if (1 === preg_match('/\bU(\d{1,2})\b/i', $name, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }
}
