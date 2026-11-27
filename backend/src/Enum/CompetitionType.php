<?php

declare(strict_types=1);

namespace App\Enum;

use App\Matches\CompetitionClassifier;

/**
 * La nature d'une compétition (spec gestion-matchs §9), écrite une fois, à la création, par
 * {@see CompetitionClassifier}. `FRIENDLY` : un amical — jamais confié au moteur, jamais jugé contre les
 * fenêtres de ligue, les règles du club ni les accès match, et il n'engage pas l'équipe.
 */
enum CompetitionType: string
{
    use HasValues;

    /** La seule question « amical ? » posée à la nature. */
    public function isFriendly(): bool
    {
        return self::FRIENDLY === $this;
    }

    case CHAMPIONSHIP = 'CHAMPIONSHIP';
    case CUP = 'CUP';
    case BRASSAGE = 'BRASSAGE';
    case FRIENDLY = 'FRIENDLY';
}
