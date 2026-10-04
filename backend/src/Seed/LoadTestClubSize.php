<?php

declare(strict_types=1);

namespace App\Seed;

/**
 * Les trois tailles de club de charge (rail placement), mélangées dans un tir pour
 * couvrir un éventail réaliste de charges du solveur : la solve-size dépend du
 * NOMBRE d'équipes et de gymnases réellement persistés (le builder lit tout le
 * club), pas du nombre de matchs — d'où des clubs vraiment plus ou moins gros.
 *
 *  - SMALL  : ~10 équipes, 2 gymnases — un petit club.
 *  - MEDIUM : ~21 équipes, 5 gymnases — un club moyen.
 *  - LARGE  : ~49 équipes, 9 gymnases — un gros club (ordre de grandeur BCCL,
 *             ~290 matchs à domicile sur la saison).
 */
enum LoadTestClubSize: string
{
    /**
     * Taille d'un club de charge d'après son ordinal dans la rafale (1-based) :
     * cycle SMALL → MEDIUM → LARGE, pour que `run-load-test.sh --mode placement`
     * répartisse N clubs sur les trois tailles sans paramètre par club.
     */
    public static function forIndex(int $index): self
    {
        return match (($index - 1) % 3) {
            0 => self::SMALL,
            1 => self::MEDIUM,
            default => self::LARGE,
        };
    }

    public function teamCount(): int
    {
        return match ($this) {
            self::SMALL => 10,
            self::MEDIUM => 21,
            self::LARGE => 49,
        };
    }

    public function venueCount(): int
    {
        return match ($this) {
            self::SMALL => 2,
            self::MEDIUM => 5,
            self::LARGE => 9,
        };
    }
    case SMALL = 'small';
    case MEDIUM = 'medium';
    case LARGE = 'large';
}
