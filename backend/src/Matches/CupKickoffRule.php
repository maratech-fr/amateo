<?php

declare(strict_types=1);

namespace App\Matches;

/**
 * La règle d'une coupe : pas de fenêtre de ligue, un coup d'envoi entre 08:00 et 21:00, bornes comprises, quel que
 * soit le jour (férié compris). Lue par le radar (« coupe hors horaires ») ; miroir déclaré à l'écran
 * (`frontend/src/features/matches/lib/envelope.ts::cupKickoffInside`), cas partagés `leagueEnvelope.parity.json`
 * (`cupBounds`, `cupCases`), gardés par `LeagueEnvelopeMirrorParityTest`.
 */
final class CupKickoffRule
{
    public const string KICKOFF_MIN = '08:00';

    public const string KICKOFF_MAX = '21:00';

    /** Intervalle fermé : 08:00 et 21:00 sont dans la règle. `$kickoff` en « HH:MM » (ou « HH:MM:SS »). */
    public static function accepts(string $kickoff): bool
    {
        $hhmm = substr($kickoff, 0, 5);

        return $hhmm >= self::KICKOFF_MIN && $hhmm <= self::KICKOFF_MAX;
    }
}
