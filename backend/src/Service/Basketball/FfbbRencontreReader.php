<?php

declare(strict_types=1);

namespace App\Service\Basketball;

use App\Enum\FixtureHomeAway;
use App\Service\FbiFixtureImporter;
use DateTimeImmutable;
use Exception;

/**
 * Reads the club's FFBB rencontres for ONE season from the `ffbbserver_rencontres`
 * index (RMM-4 PR-3, canal API FFBB). On-demand only — no cache, no cron (closed
 * legal decision, {@see FfbbApiClient}).
 *
 * The API is a CONVENIENCE, never the truth: FBI (the xlsx) fait foi. For a real
 * club the index carries only AMICAUX (measured 2026-08-24 — 36 hits, all
 * friendlies, zero championship), which the xlsx never lists: that is exactly the
 * value the API adds, proposed at creation. Coverage is NEVER promised.
 *
 * Each hit is mapped to a row of the SAME shape the reconciliation deviation
 * engine consumes ({@see FbiFixtureImporter}) so the two channels
 * reconcile identically. HOME/AWAY derives from the side carrying the club code
 * (équipe 1 = home, like FBI); the opponent is the OTHER organisme's name; the
 * kickoff « 00:00 » is treated as « not set » (null), the same FBI sentinel.
 * Double-encoded UTF-8 labels are repaired at this boundary.
 *
 * The season is filtered HERE (the hit carries `saison.code`) — a rencontre of
 * another season is dropped, never shown half-joined.
 */
final class FfbbRencontreReader
{
    public function __construct(private readonly FfbbApiClient $apiClient) {}

    /**
     * @return list<array{
     *     rencontreId: string,
     *     matchDate: DateTimeImmutable,
     *     kickoffTime: DateTimeImmutable|null,
     *     homeAway: FixtureHomeAway,
     *     opponentLabel: string,
     *     venueLabel: string|null,
     *     competitionFfbbId: string|null,
     *     competitionName: string,
     *     numeroJournee: string|null,
     *     numero: string,
     * }>
     */
    public function read(string $clubCode, int $seasonYear): array
    {
        $seasonCode = FfbbSeasonCode::fromSeasonYear($seasonYear);
        $rows = [];

        foreach ($this->apiClient->searchRencontres($clubCode) as $hit) {
            if (!FfbbSeasonCode::matchesSeasonYear($this->seasonCode($hit), $seasonYear)) {
                continue; // another season — never mixed in
            }
            $rencontreId = $this->stringOrNull($hit['id'] ?? null);
            $dateTime = $this->parseDateTime($this->stringOrNull($hit['date_rencontre'] ?? null));
            if (null === $rencontreId || !$dateTime instanceof DateTimeImmutable) {
                continue; // no idempotence key / no date → cannot place, cannot create
            }

            $equipe1 = \is_array($hit['idOrganismeEquipe1'] ?? null) ? $hit['idOrganismeEquipe1'] : [];
            $equipe2 = \is_array($hit['idOrganismeEquipe2'] ?? null) ? $hit['idOrganismeEquipe2'] : [];
            $clubIsHome = $this->stringOrNull($equipe1['code'] ?? null) === $clubCode;
            $opponent = $clubIsHome ? $equipe2 : $equipe1;
            $opponentName = $this->fixEncoding($this->stringOrNull($opponent['nom'] ?? null) ?? '');
            if ('' === $opponentName) {
                continue; // an unnamed opponent is not a usable row
            }

            $competition = \is_array($hit['competitionId'] ?? null) ? $hit['competitionId'] : [];
            $venue = \is_array($hit['salle'] ?? null) ? $hit['salle'] : [];
            $kickoff = $this->kickoffOf($dateTime);

            // Les chaînes FFBB sont de la donnée EXTERNE non bornée ; les colonnes,
            // elles, le sont (opponent/venue 180, ffbb_rencontre_id 64). Sans clamp
            // ici, UNE ligne trop longue publiée par la fédé ferait échouer l'apply
            // ENTIER en 502 au flush (revue de sécurité 2026-08-24 — robustesse,
            // pas une faille : SQL paramétré, React échappe).
            if (mb_strlen($rencontreId) > 64) {
                continue; // une clé d'idempotence intronquable ne se tronque pas
            }
            $rows[] = [
                'rencontreId' => $rencontreId,
                'matchDate' => $dateTime->setTime(0, 0),
                'kickoffTime' => $kickoff,
                'homeAway' => $clubIsHome ? FixtureHomeAway::HOME : FixtureHomeAway::AWAY,
                'opponentLabel' => mb_substr($opponentName, 0, 180),
                'venueLabel' => $this->clamp180($this->labelOrNull($this->stringOrNull($venue['libelle'] ?? null))),
                'competitionFfbbId' => $this->stringOrNull($competition['id'] ?? null),
                'competitionName' => $this->fixEncoding($this->stringOrNull($competition['nom'] ?? null) ?? 'Amical'),
                'numeroJournee' => $this->stringOrNull($hit['numeroJournee'] ?? null),
                // The FBI match number does not exist on the API — the shared
                // deviation engine never reads it, so an empty repere is fine.
                'numero' => '',
            ];
        }

        return $rows;
    }

    /**
     * The DISTINCT away opponents of the club for one season, for the global
     * opponent directory (P2-54 RMM-9). For every rencontre where the club is the
     * VISITOR (équipe 2), the opponent is the HOME organisme (équipe 1) and the
     * `salle` is the opponent's home gym — so the hit carries, for free, both the
     * opponent's federal organisme code (the directory KEY) and the exact venue.
     *
     * ⚠ Sondé 2026-08-27 : l'objet `salle` d'un hit rencontres porte
     * `{id, libelle, adresse, cartographie:{ville, codePostal, coordonnees:{coordinates:[lng,lat]}}}`
     * — donc l'étage 1 (« salle du hit ») donne les coordonnées EXACTES sans appel
     * supplémentaire. Pas de `_geo` ni de `commune` à ce niveau (contrairement à
     * l'index salles/organismes) : les coordonnées vivent dans
     * `cartographie.coordonnees.coordinates` (ordre GeoJSON [lng, lat]).
     *
     * Best-effort, jamais une promesse : un hit sans salle exploitable rend un
     * `directVenue` null (le résolveur retombe alors sur la ville — VENUE réservé au
     * `directVenue` autoritatif, revue sécurité 2026-08-28).
     *
     * @return list<array{organismeCode: string, name: string, directVenue: array{libelle: string, city: string|null, postalCode: string|null, latitude: float, longitude: float}|null}>
     */
    public function readAwayOpponents(string $clubCode, int $seasonYear): array
    {
        $opponents = [];

        foreach ($this->apiClient->searchRencontres($clubCode) as $hit) {
            if (!FfbbSeasonCode::matchesSeasonYear($this->seasonCode($hit), $seasonYear)) {
                continue; // another season
            }

            $equipe1 = \is_array($hit['idOrganismeEquipe1'] ?? null) ? $hit['idOrganismeEquipe1'] : [];
            $equipe2 = \is_array($hit['idOrganismeEquipe2'] ?? null) ? $hit['idOrganismeEquipe2'] : [];
            // The club is the VISITOR when it is NOT équipe 1 (home). Only then is
            // the hit's salle the OPPONENT's gym; a home rencontre locates our own
            // venue, never the opponent's.
            if ($this->stringOrNull($equipe1['code'] ?? null) === $clubCode) {
                continue;
            }
            $opponent = $equipe1;
            $code = $this->stringOrNull($opponent['code'] ?? null);
            $name = $this->fixEncoding($this->stringOrNull($opponent['nom'] ?? null) ?? '');
            if (null === $code || mb_strlen($code) > 64 || '' === $name) {
                continue; // no key / no name → not a usable directory row
            }

            $salle = \is_array($hit['salle'] ?? null) ? $hit['salle'] : [];
            $opponents[] = [
                'organismeCode' => $code,
                'name' => mb_substr($name, 0, 180),
                'directVenue' => $this->rencontreSalleVenue($salle),
            ];
        }

        return $opponents;
    }

    /**
     * The exact venue carried by a rencontre hit's `salle` (étage 1). Returns null
     * when no usable coordinates are present.
     *
     * @param array<string, mixed> $salle
     *
     * @return array{libelle: string, city: string|null, postalCode: string|null, latitude: float, longitude: float}|null
     */
    private function rencontreSalleVenue(array $salle): ?array
    {
        $libelle = $this->clamp180($this->labelOrNull($this->stringOrNull($salle['libelle'] ?? null)));
        if (null === $libelle) {
            return null;
        }
        $carto = \is_array($salle['cartographie'] ?? null) ? $salle['cartographie'] : [];
        $point = \is_array($carto['coordonnees'] ?? null) ? $carto['coordonnees'] : [];
        $coordinates = \is_array($point['coordinates'] ?? null) ? $point['coordinates'] : [];
        // GeoJSON order is [longitude, latitude].
        $longitude = $coordinates[0] ?? null;
        $latitude = $coordinates[1] ?? null;
        if (!is_numeric($latitude) || !is_numeric($longitude)) {
            return null;
        }

        return [
            'libelle' => $libelle,
            'city' => $this->clamp180($this->labelOrNull($this->stringOrNull($carto['ville'] ?? null))),
            'postalCode' => $this->postalOrNull($this->stringOrNull($carto['codePostal'] ?? null)),
            'latitude' => (float) $latitude,
            'longitude' => (float) $longitude,
        ];
    }

    private function postalOrNull(?string $value): ?string
    {
        return null === $value ? null : mb_substr($value, 0, 16);
    }

    private function seasonCode(mixed $hit): ?string
    {
        $saison = \is_array($hit) && \is_array($hit['saison'] ?? null) ? $hit['saison'] : [];

        return $this->stringOrNull($saison['code'] ?? null);
    }

    /** The date carries the kickoff; « 00:00 » is the FBI « not set » sentinel → null. */
    private function kickoffOf(DateTimeImmutable $dateTime): ?DateTimeImmutable
    {
        if ('00:00' === $dateTime->format('H:i')) {
            return null;
        }

        return new DateTimeImmutable('2000-01-01')->setTime((int) $dateTime->format('H'), (int) $dateTime->format('i'));
    }

    /** Parses an ISO-ish datetime string (« 2026-08-27T20:30:00 » or « 2026-08-27 20:30:00 »). */
    private function parseDateTime(?string $value): ?DateTimeImmutable
    {
        if (null === $value) {
            return null;
        }
        try {
            return new DateTimeImmutable($value);
        } catch (Exception) {
            return null;
        }
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (\is_int($value)) {
            return (string) $value;
        }

        return \is_string($value) && '' !== $value ? $value : null;
    }

    private function labelOrNull(?string $value): ?string
    {
        return null === $value ? null : $this->fixEncoding($value);
    }

    /** Borne un libellé externe à la capacité des colonnes (180) — voir le commentaire du clamp dans read(). */
    private function clamp180(?string $value): ?string
    {
        return null === $value ? null : mb_substr($value, 0, 180);
    }

    /** Repair double-encoded UTF-8 (« PrÃ© rÃ©gionale » → « Pré régionale »), measured on the real index. */
    private function fixEncoding(string $value): string
    {
        if (1 !== preg_match('/\x{00C3}[\x{0080}-\x{00BF}]/u', $value)) {
            return $value;
        }
        $decoded = mb_convert_encoding($value, 'ISO-8859-1', 'UTF-8');

        return mb_check_encoding($decoded, 'UTF-8') ? $decoded : $value;
    }
}
