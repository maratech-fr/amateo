<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\OpponentTravelSource;
use App\Repository\OpponentTravelRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Le temps de trajet d'un club vers le lieu d'un adversaire, POUR CE CLUB ET
 * CETTE SAISON (P2-54 RMM-9 PR-3). ⚠ Le trajet DÉPEND du siège d'un club précis
 * → c'est une donnée CLUB-spécifique : elle vit ICI (tenant, RLS FORCE), JAMAIS
 * dans `opponent_directory` (table publique fédérale, partagée entre tous les
 * clubs — y écrire une distance trahirait le siège du club).
 *
 * Keyée sur le code organisme fédéral de l'adversaire (`opponentOrganismeCode`),
 * la même clé que {@see OpponentDirectoryEntry} et {@see Fixture::getOpponentOrganismeCode()}.
 * Un couple (club, saison, code) = une ligne (unique).
 *
 * `travelMinutes` = trajet ALLER SIMPLE en voiture (le radar double pour l'aller-
 * retour), NULLABLE : null = lieu connu mais IGN n'a rien rendu (best-effort),
 * ou pas encore calculé. `source` (AUTO|MANUAL) : le MANUAL n'est jamais écrasé
 * par un recalcul AUTO (patron {@see VenueTravelTime}).
 *
 * `overrideVenue*` (nullable) : le gymnase que le gestionnaire a choisi À LA MAIN
 * pour cet adversaire — il SURCHARGE le lieu du global (le global peut n'avoir
 * qu'une précision ville, ou se tromper de salle). Présent ⇒ le trajet AUTO
 * repart de CE lieu ; absent ⇒ du lieu de l'annuaire global.
 */
#[ORM\Entity(repositoryClass: OpponentTravelRepository::class)]
#[ORM\Table(name: 'opponent_travel')]
#[ORM\UniqueConstraint(name: 'uniq_opponent_travel_code', columns: ['club_id', 'season_id', 'opponent_organisme_code'])]
#[ORM\Index(name: 'idx_opponent_travel_club_season', columns: ['club_id', 'season_id'])]
#[ORM\HasLifecycleCallbacks]
class OpponentTravel implements TenantOwnedInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Version]
    #[ORM\Column(type: 'integer')]
    private int $version = 1;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private DateTimeImmutable $updatedAt;

    #[ORM\Column(type: 'guid')]
    private string $clubId;

    #[ORM\Column(type: 'guid')]
    private string $seasonId;

    #[ORM\Column(name: 'opponent_organisme_code', length: 64)]
    private string $opponentOrganismeCode;

    /** Aller simple en VOITURE, en minutes. Null = non calculé / IGN muet (best-effort). */
    #[ORM\Column(name: 'travel_minutes', type: 'smallint', nullable: true)]
    private ?int $travelMinutes = null;

    #[ORM\Column(length: 10, enumType: OpponentTravelSource::class)]
    private OpponentTravelSource $source = OpponentTravelSource::AUTO;

    /** Le n° de salle FFBB choisi à la main (surcharge le lieu du global). */
    #[ORM\Column(name: 'override_venue_external_ref', length: 64, nullable: true)]
    private ?string $overrideVenueExternalRef = null;

    #[ORM\Column(name: 'override_venue_label', length: 180, nullable: true)]
    private ?string $overrideVenueLabel = null;

    #[ORM\Column(name: 'override_latitude', type: 'float', nullable: true)]
    private ?float $overrideLatitude = null;

    #[ORM\Column(name: 'override_longitude', type: 'float', nullable: true)]
    private ?float $overrideLongitude = null;

    /** Quand le trajet a été (re)calculé — null tant qu'aucun calcul n'a abouti. */
    #[ORM\Column(name: 'resolved_at', type: 'datetimetz_immutable', nullable: true)]
    private ?DateTimeImmutable $resolvedAt = null;

    public function __construct()
    {
        $this->id = $this->newUuid();
        $now = new DateTimeImmutable;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function setId(string $id): self
    {
        $this->id = $id;

        return $this;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(DateTimeImmutable $updatedAt): self
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    #[ORM\PreUpdate]
    public function touchUpdatedAt(): void
    {
        $this->updatedAt = new DateTimeImmutable;
    }

    public function getClubId(): string
    {
        return $this->clubId;
    }

    public function setClubId(string $clubId): self
    {
        $this->clubId = $clubId;

        return $this;
    }

    public function getSeasonId(): string
    {
        return $this->seasonId;
    }

    public function setSeasonId(string $seasonId): self
    {
        $this->seasonId = $seasonId;

        return $this;
    }

    public function getOpponentOrganismeCode(): string
    {
        return $this->opponentOrganismeCode;
    }

    public function setOpponentOrganismeCode(string $opponentOrganismeCode): self
    {
        $this->opponentOrganismeCode = $opponentOrganismeCode;

        return $this;
    }

    public function getTravelMinutes(): ?int
    {
        return $this->travelMinutes;
    }

    public function setTravelMinutes(?int $travelMinutes): self
    {
        $this->travelMinutes = $travelMinutes;

        return $this;
    }

    public function getSource(): OpponentTravelSource
    {
        return $this->source;
    }

    public function setSource(OpponentTravelSource $source): self
    {
        $this->source = $source;

        return $this;
    }

    public function getOverrideVenueExternalRef(): ?string
    {
        return $this->overrideVenueExternalRef;
    }

    public function setOverrideVenueExternalRef(?string $overrideVenueExternalRef): self
    {
        $this->overrideVenueExternalRef = $overrideVenueExternalRef;

        return $this;
    }

    public function getOverrideVenueLabel(): ?string
    {
        return $this->overrideVenueLabel;
    }

    public function setOverrideVenueLabel(?string $overrideVenueLabel): self
    {
        $this->overrideVenueLabel = $overrideVenueLabel;

        return $this;
    }

    public function getOverrideLatitude(): ?float
    {
        return $this->overrideLatitude;
    }

    public function setOverrideLatitude(?float $overrideLatitude): self
    {
        $this->overrideLatitude = $overrideLatitude;

        return $this;
    }

    public function getOverrideLongitude(): ?float
    {
        return $this->overrideLongitude;
    }

    public function setOverrideLongitude(?float $overrideLongitude): self
    {
        $this->overrideLongitude = $overrideLongitude;

        return $this;
    }

    /** True when the manager pinned a specific gym for this opponent. */
    public function hasOverride(): bool
    {
        return null !== $this->overrideLatitude && null !== $this->overrideLongitude;
    }

    public function getResolvedAt(): ?DateTimeImmutable
    {
        return $this->resolvedAt;
    }

    public function setResolvedAt(?DateTimeImmutable $resolvedAt): self
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
