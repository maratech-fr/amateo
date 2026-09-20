<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ClubTravelCacheRepository;
use App\Service\Geo\IgnRoutingClient;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * C4 — cache de temps de trajet AU NIVEAU CLUB (jamais par saison). Un trajet routier
 * entre deux points est une CONSTANTE : il ne dépend ni de la saison, ni d'une équipe,
 * seulement des deux coordonnées (arrondies à 5 décimales, ~1 m) et du profil
 * (voiture/à pied). Une fois calculé par IGN, il n'est JAMAIS recalculé — d'où ce
 * cache, dont la clé est (club, profil, origine, destination).
 *
 * ⚠ Pourquoi TENANT (RLS) et non global : la clé porte des coordonnées qui, croisées,
 * trahissent le SIÈGE d'un club précis (donnée club-spécifique, jamais dans l'annuaire
 * fédéral partagé). Le cache est donc club-scoped, RLS FORCE.
 *
 * `minutes` est NON NULL : on ne met JAMAIS en cache un échec (un IGN muet peut réussir
 * plus tard). Pas de source AUTO/MANUAL : un MANUAL est un CHOIX de gymnase, pas une
 * distance — il n'entre pas ici, seul le trajet calculé le fait.
 */
#[ORM\Entity(repositoryClass: ClubTravelCacheRepository::class)]
#[ORM\Table(name: 'club_travel_cache')]
#[ORM\UniqueConstraint(name: 'uniq_club_travel_cache_key', columns: ['club_id', 'profile', 'origin_lat', 'origin_lon', 'dest_lat', 'dest_lon'])]
#[ORM\Index(name: 'idx_club_travel_cache_club', columns: ['club_id'])]
class ClubTravelCache implements TenantOwnedInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(type: 'guid')]
    private string $clubId;

    /** Profil de déplacement — {@see IgnRoutingClient::PROFILE_CAR}/`PROFILE_PEDESTRIAN`. */
    #[ORM\Column(length: 16)]
    private string $profile;

    #[ORM\Column(name: 'origin_lat', type: 'decimal', precision: 9, scale: 5)]
    private string $originLat;

    #[ORM\Column(name: 'origin_lon', type: 'decimal', precision: 9, scale: 5)]
    private string $originLon;

    #[ORM\Column(name: 'dest_lat', type: 'decimal', precision: 9, scale: 5)]
    private string $destLat;

    #[ORM\Column(name: 'dest_lon', type: 'decimal', precision: 9, scale: 5)]
    private string $destLon;

    /** Trajet en minutes (aller simple), TOUJOURS présent — un échec n'entre pas au cache. */
    #[ORM\Column(type: 'smallint')]
    private int $minutes;

    #[ORM\Column(name: 'resolved_at', type: 'datetimetz_immutable')]
    private DateTimeImmutable $resolvedAt;

    public function __construct()
    {
        $this->id = $this->newUuid();
        $this->resolvedAt = new DateTimeImmutable;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getClubId(): ?string
    {
        return $this->clubId;
    }

    public function setClubId(string $clubId): self
    {
        $this->clubId = $clubId;

        return $this;
    }

    public function getProfile(): string
    {
        return $this->profile;
    }

    public function setProfile(string $profile): self
    {
        $this->profile = $profile;

        return $this;
    }

    public function getOriginLat(): string
    {
        return $this->originLat;
    }

    public function setOriginLat(string $originLat): self
    {
        $this->originLat = $originLat;

        return $this;
    }

    public function getOriginLon(): string
    {
        return $this->originLon;
    }

    public function setOriginLon(string $originLon): self
    {
        $this->originLon = $originLon;

        return $this;
    }

    public function getDestLat(): string
    {
        return $this->destLat;
    }

    public function setDestLat(string $destLat): self
    {
        $this->destLat = $destLat;

        return $this;
    }

    public function getDestLon(): string
    {
        return $this->destLon;
    }

    public function setDestLon(string $destLon): self
    {
        $this->destLon = $destLon;

        return $this;
    }

    public function getMinutes(): int
    {
        return $this->minutes;
    }

    public function setMinutes(int $minutes): self
    {
        $this->minutes = $minutes;

        return $this;
    }

    public function getResolvedAt(): DateTimeImmutable
    {
        return $this->resolvedAt;
    }

    public function setResolvedAt(DateTimeImmutable $resolvedAt): self
    {
        $this->resolvedAt = $resolvedAt;

        return $this;
    }

    private function newUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
