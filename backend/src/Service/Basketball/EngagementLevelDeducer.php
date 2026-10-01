<?php

declare(strict_types=1);

namespace App\Service\Basketball;

use App\Enum\TeamLevel;

/**
 * « Le niveau d'une équipe JEUNE suit son engagement FFBB » (décision fondateur
 * 2026-10-01). Déduit le {@see TeamLevel} qu'implique une LIGNE d'engagement FFBB
 * d'une catégorie JEUNE (U9–U18) en championnat ou brassage, et ARBITRE le niveau
 * dominant d'une équipe quand ses engagements jeunes divergent.
 *
 * Pur et sans état : il consomme la signature de {@see FbiDivisionSignature}, la
 * MAISON UNIQUE du décodage d'une ligne FFBB — jamais une seconde lecture du
 * niveau/catégorie/type.
 *
 * Règles (D1/D3) : mapping D→DEPARTEMENTAL, R→REGIONAL, N→NATIONAL ; **jamais
 * ELITE** ; PR/PN et tout autre niveau → null (aucun cas TeamLevel, on ne comble
 * rien). Éligibilité : catégorie ∈ U9–U18 ET type ∈ {CHAMPIONSHIP, BRASSAGE} —
 * un senior, un U21, une coupe sortent (null).
 *
 * Relève de l'axe §7.1 « périmètre engagé » : il décide d'un niveau d'équipe.
 */
final readonly class EngagementLevelDeducer
{
    public function __construct(private FbiDivisionSignature $signature) {}

    /**
     * Le niveau qu'implique UNE ligne d'engagement FFBB, ou null si la ligne n'est
     * pas une compétition jeune éligible, ou si son niveau est indécidable (PR/PN).
     */
    public function deduce(?string $category, ?string $level, ?string $gender, string $competitionName): ?TeamLevel
    {
        $signature = $this->signature->fromFfbbRow($category, $level, $gender, $competitionName);

        if (!\in_array($signature['type'], [FbiDivisionSignature::TYPE_CHAMPIONSHIP, FbiDivisionSignature::TYPE_BRASSAGE], true)) {
            return null;
        }
        if (null === $signature['category'] || 1 !== preg_match('/^U(9|1[0-8])$/', $signature['category'])) {
            return null;
        }

        return match ($signature['level']) {
            'D' => TeamLevel::DEPARTEMENTAL,
            'R' => TeamLevel::REGIONAL,
            'N' => TeamLevel::NATIONAL,
            default => null, // PR/PN ou absent — aucun TeamLevel, jamais ELITE
        };
    }

    /**
     * Le niveau DOMINANT d'une équipe depuis ses engagements jeunes éligibles (D4).
     * Un seul niveau distinct → lui, sans qu'une date soit nécessaire. En cas de
     * DIVERGENCE (niveaux différents), la compétition au match le plus TARDIF
     * l'emporte ; indécidable (aucun candidat daté, ou égalité sur la date la plus
     * tardive entre des niveaux différents) → null, jamais un niveau deviné.
     *
     * @param list<array{level: TeamLevel, latestMatchDate: string|null}> $candidates
     */
    public function arbitrate(array $candidates): ?TeamLevel
    {
        if ([] === $candidates) {
            return null;
        }

        $distinct = [];
        foreach ($candidates as $candidate) {
            $distinct[$candidate['level']->value] = $candidate['level'];
        }
        if (1 === \count($distinct)) {
            return reset($distinct);
        }

        // Divergence : la date de match la plus tardive départage. Dates ISO
        // ('Y-m-d H:i:s') comparées lexicalement — même ordre que chronologiquement.
        $bestDate = null;
        $bestLevels = [];
        foreach ($candidates as $candidate) {
            $date = $candidate['latestMatchDate'];
            if (null === $date) {
                continue;
            }
            if (null === $bestDate || $date > $bestDate) {
                $bestDate = $date;
                $bestLevels = [$candidate['level']->value => $candidate['level']];
            } elseif ($date === $bestDate) {
                $bestLevels[$candidate['level']->value] = $candidate['level'];
            }
        }
        if (null === $bestDate || 1 !== \count($bestLevels)) {
            return null; // aucun candidat daté, ou égalité entre niveaux différents
        }

        return reset($bestLevels);
    }
}
