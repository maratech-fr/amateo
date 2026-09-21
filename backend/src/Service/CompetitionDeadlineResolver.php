<?php

declare(strict_types=1);

namespace App\Service;

use App\ApiResource\CompetitionResource;
use App\Controller\LeagueValidatedFixturesController;
use App\Entity\Competition;
use App\Entity\SharedCompetitionDeadline;
use DateTimeImmutable;

/**
 * MAISON UNIQUE de la règle d'échéance effective d'une compétition (RMM-6) : la valeur
 * du CLUB l'emporte, sinon le défaut COMMUNAUTAIRE (le partagé n'est fourni que pour une
 * compétition APPARIÉE — la clé fédérale, résolue par l'appelant qui seul sait laquelle
 * lui passer). Sans l'un ni l'autre : pas d'échéance.
 *
 * Extraite parce qu'elle vivait en DEUX copies — {@see EntryDeadlineOutlook::effectiveDeadline}
 * (le cockpit) et {@see CompetitionResource::fromEntity} (la lecture des
 * compétitions) — et qu'un troisième consommateur naît avec le « validé ligue » piloté par
 * l'échéance ({@see LeagueValidatedFixturesController}) : une troisième
 * redérivation serait exactement ce qu'on interdit ailleurs. Le PAIRING (quel partagé
 * fournir) reste chez l'appelant ; ce résolveur ne fait que « club, sinon partagé ».
 */
final class CompetitionDeadlineResolver
{
    /**
     * @return array{0: DateTimeImmutable|null, 1: string|null} [échéance effective, provenance 'club'|'community'|null]
     */
    public static function resolve(Competition $competition, ?SharedCompetitionDeadline $shared): array
    {
        $club = $competition->getEntryDeadline();
        if ($club instanceof DateTimeImmutable) {
            return [$club, 'club'];
        }
        if ($shared instanceof SharedCompetitionDeadline) {
            return [$shared->getEntryDeadline(), 'community'];
        }

        return [null, null];
    }
}
