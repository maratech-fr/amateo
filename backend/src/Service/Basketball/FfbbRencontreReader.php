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

            $rows[] = [
                'rencontreId' => $rencontreId,
                'matchDate' => $dateTime->setTime(0, 0),
                'kickoffTime' => $kickoff,
                'homeAway' => $clubIsHome ? FixtureHomeAway::HOME : FixtureHomeAway::AWAY,
                'opponentLabel' => $opponentName,
                'venueLabel' => $this->labelOrNull($this->stringOrNull($venue['libelle'] ?? null)),
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
