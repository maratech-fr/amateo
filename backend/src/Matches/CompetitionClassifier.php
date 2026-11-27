<?php

declare(strict_types=1);

namespace App\Matches;

use App\Entity\Competition;
use App\Enum\CompetitionType;

/**
 * La seule règle de nature d'une compétition, lue dans son libellé, casse et accents ignorés :
 * premier mot exact « amical » → amicale ; « coupe », ou un début « crml » / « cmrl » → coupe ; « brassage » →
 * brassage ; sinon la nature par défaut du chemin (championnat ; coupe pour le canal API).
 *
 * Appelé à la CRÉATION d'une compétition seulement (import par fichier, appariement, canal API). Une compétition
 * existante ne devient jamais amicale et une amicale ne change jamais de nature : {@see reclassOf} ne rend qu'un
 * reclassement vers coupe ou brassage d'une compétition non amicale.
 */
final class CompetitionClassifier
{
    public static function classify(string $label, CompetitionType $default = CompetitionType::CHAMPIONSHIP): CompetitionType
    {
        $normalized = self::normalize($label);
        if ('amical' === strtok($normalized, ' ')) {
            return CompetitionType::FRIENDLY;
        }
        if (str_contains($normalized, 'coupe') || str_starts_with($normalized, 'crml') || str_starts_with($normalized, 'cmrl')) {
            return CompetitionType::CUP;
        }
        if (str_contains($normalized, 'brassage')) {
            return CompetitionType::BRASSAGE;
        }

        return $default;
    }

    /** Le reclassement d'une compétition existante d'après un libellé : coupe ou brassage, jamais vers ni depuis amical. */
    public static function reclassOf(Competition $existing, string $label): ?CompetitionType
    {
        if ($existing->getCompetitionType()->isFriendly()) {
            return null;
        }
        $nature = self::classify($label);

        return CompetitionType::CUP === $nature || CompetitionType::BRASSAGE === $nature ? $nature : null;
    }

    private static function normalize(string $value): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        $lower = mb_strtolower(false === $ascii ? $value : $ascii, 'UTF-8');

        return trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^a-z0-9]+/', ' ', $lower)));
    }
}
