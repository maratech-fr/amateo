<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\LeagueWindowInterface;
use App\Entity\SportCategory;
use App\Entity\Team;

/**
 * Server-side port of the tolerant team↔league-window join (P1-4 PR D — the
 * placement solve needs the SAME resolution the placement screen shows,
 * `frontend/src/features/matches/lib/envelope.ts`). The join matches the
 * team's (category name, level, gender) against the catalog labels, all
 * normalized (case/accents/spacing agnostic).
 *
 * Tolerant by decision: an unresolved team gets [] — NO league HARD, plus an
 * INFO diagnostic emitted by the payload builder (« on accompagne, on ne
 * décide pas »). Hardening the join key is roadmap debt (iv), PR E.
 */
final class LeagueEnvelopeResolver
{
    /**
     * @param list<Team>                  $teams
     * @param list<SportCategory>         $categories
     * @param list<LeagueWindowInterface> $windows
     *
     * @return array<string, list<LeagueWindowInterface>> teamId → applicable windows ([] = unmapped)
     */
    public function resolve(array $teams, array $categories, array $windows): array
    {
        $categoryNames = [];
        foreach ($categories as $category) {
            $categoryNames[$category->getId()] = $category->getName();
        }

        $result = [];
        foreach ($teams as $team) {
            $categoryName = $categoryNames[$team->getSportCategoryId()] ?? null;
            $matched = [];
            if (null !== $categoryName) {
                foreach ($windows as $window) {
                    if ($this->normalize($window->getCategory()) !== $this->normalize($categoryName)) {
                        continue;
                    }
                    // An unknown team level/gender must NOT match every window
                    // (mirror of envelope.ts): require the axis known and equal;
                    // a gender-null window is catalog-wide and still applies.
                    $level = $team->getLevel();
                    if (null === $level || $this->normalize($window->getLevel()) !== $this->normalize($level->value)) {
                        continue;
                    }
                    $windowGender = $window->getGender();
                    $teamGender = $team->getGender();
                    if (null !== $windowGender
                        && (null === $teamGender || $this->normalize($windowGender) !== $this->normalize($teamGender->value))
                    ) {
                        continue;
                    }
                    $matched[] = $window;
                }
            }
            $result[$team->getId()] = $matched;
        }

        return $result;
    }

    private function normalize(string $value): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        $lower = mb_strtolower(false === $ascii ? $value : $ascii, 'UTF-8');

        return (string) preg_replace('/\s+/', '', $lower);
    }
}
