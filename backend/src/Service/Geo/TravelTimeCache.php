<?php

declare(strict_types=1);

namespace App\Service\Geo;

use App\Entity\ClubTravelCache;
use App\Repository\ClubTravelCacheRepository;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;

/**
 * C4 — le cache de trajets {@see ClubTravelCache}, en LECTURE d'abord.
 * Un trajet routier est une CONSTANTE (deux coordonnées + un profil), donc une valeur
 * déjà calculée n'est JAMAIS recalculée : chaque consommateur d'{@see IgnRoutingClient}
 * (résolution des trajets adverses, auto-localisation, matrice des gymnases) regarde
 * ICI avant d'appeler le réseau, et n'appelle IGN que sur un manque.
 *
 * Clé ARRONDIE à 5 décimales (~1 m) et canonique (`%.5f`) des deux côtés — lecture et
 * écriture partagent exactement la même forme, sinon un hit ne retrouverait jamais sa
 * ligne. On ne met JAMAIS un échec en cache ({@see store} n'accepte qu'un `int`), et on
 * n'écrase jamais une valeur existante (INSERT … ON CONFLICT DO NOTHING).
 */
final class TravelTimeCache
{
    public function __construct(
        private readonly ClubTravelCacheRepository $repository,
        private readonly Connection $connection,
        private readonly ClockInterface $clock,
    ) {}

    /** Cached minutes for this exact key, or null on a miss (never computed for this club yet). */
    public function lookup(string $clubId, string $profile, float $originLat, float $originLon, float $destLat, float $destLon): ?int
    {
        return $this->repository->findCached(
            $clubId,
            $profile,
            $this->key($originLat),
            $this->key($originLon),
            $this->key($destLat),
            $this->key($destLon),
        )?->getMinutes();
    }

    /**
     * Record a freshly computed travel time. Idempotent (ON CONFLICT DO NOTHING) — a
     * distance is a constant, an existing row is never touched. `$minutes` is an int by
     * signature: a failed (null) IGN result never reaches the cache.
     */
    public function store(string $clubId, string $profile, float $originLat, float $originLon, float $destLat, float $destLon, int $minutes): void
    {
        $this->connection->executeStatement(
            'INSERT INTO club_travel_cache (id, club_id, profile, origin_lat, origin_lon, dest_lat, dest_lon, minutes, resolved_at)
             VALUES (:id, :club, :profile, :originLat, :originLon, :destLat, :destLon, :minutes, :resolvedAt)
             ON CONFLICT (club_id, profile, origin_lat, origin_lon, dest_lat, dest_lon) DO NOTHING',
            [
                'id' => $this->newUuid(),
                'club' => $clubId,
                'profile' => $profile,
                'originLat' => $this->key($originLat),
                'originLon' => $this->key($originLon),
                'destLat' => $this->key($destLat),
                'destLon' => $this->key($destLon),
                'minutes' => $minutes,
                'resolvedAt' => $this->clock->now()->format('Y-m-d H:i:sP'),
            ],
        );
    }

    /**
     * All cached minutes from one origin (the club siège), keyed on the destination
     * `"lat|lon"` in the canonical 5-decimal form ({@see destKey}). One query — a
     * projection over many opponent gyms reads them all at once, never an N+1.
     *
     * @return array<string, int>
     */
    public function lookupAllFromOrigin(string $clubId, string $profile, float $originLat, float $originLon): array
    {
        $map = [];
        foreach ($this->repository->findAllFromOrigin($clubId, $profile, $this->key($originLat), $this->key($originLon)) as $row) {
            $map[$row->getDestLat() . '|' . $row->getDestLon()] = $row->getMinutes();
        }

        return $map;
    }

    /** The destination key used by {@see lookupAllFromOrigin}'s map — canonical 5-decimal. */
    public function destKey(float $lat, float $lon): string
    {
        return $this->key($lat) . '|' . $this->key($lon);
    }

    /** Canonical 5-decimal form of a coordinate — the exact key shared by read and write. */
    private function key(float $coordinate): string
    {
        return \sprintf('%.5f', $coordinate);
    }

    private function newUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
