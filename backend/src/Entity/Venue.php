<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\VenueRepository;
use App\Service\Basketball\VenueLabelNormalizer;
use App\Structure\Family;
use App\Structure\Level;
use App\Structure\OnDelete;
use App\Structure\PlaceDirectory;
use App\Structure\RefersTo;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Le gymnase d'une saison : nom, couleur, terrain divisible, numéro fédéral et libellés FBI. Son
 * adresse et sa position vivent sur le lieu au club ({@see Place}) que `placeId` désigne, et se lisent
 * par {@see PlaceDirectory}, jamais ici. Un lieu a au plus un gymnase par saison (`uniq_venue_season_place`).
 */
#[Family(Level::SEASON, cold: ['canSplit', 'isActive'], ofSocle: true, copiedToNextSeason: true)]
#[ORM\Entity(repositoryClass: VenueRepository::class)]
#[ORM\Table(name: 'venue')]
#[ORM\Index(name: 'idx_venue_club_season', columns: ['club_id', 'season_id'])]
#[ORM\UniqueConstraint(name: 'uniq_venue_season_place', columns: ['season_id', 'place_id'])]
#[ORM\HasLifecycleCallbacks]
class Venue implements TenantOwnedInterface
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

    #[ORM\Column(type: 'string', length: 180)]
    private string $name;

    /**
     * Le lieu du gymnase au club (adresse, position). Clé étrangère composée `(place_id, club_id)` →
     * `place (id, club_id)`, posée à la main en migration (invisible de Doctrine), `ON DELETE RESTRICT`.
     */
    #[ORM\Column(type: 'guid')]
    #[RefersTo(Place::class, OnDelete::CUSTOM, 'un lieu pointé par un gymnase ne s\'efface pas (ON DELETE RESTRICT) : le gymnase part d\'abord, le lieu sans gymnase est balayé par UnusedIdentitySweeper')]
    private string $placeId;

    /**
     * Le lieu passé à la création ou au changement de lieu, tant que ce gymnase n'a pas été relu de
     * la base (non mappé) : {@see PlaceDirectory} le lit sans requête. Un gymnase chargé par Doctrine
     * ne le porte pas.
     */
    private ?Place $place = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $canSplit = false;

    #[ORM\Column(type: 'string', length: 20, nullable: true)]
    private ?string $color = null;

    #[ORM\Column(type: 'string', length: 20)]
    private string $source;

    #[ORM\Column(type: 'string', length: 180, nullable: true)]
    private ?string $externalRef = null;

    /**
     * Libellés FBI/FFBB confirmés qui désignent ce gymnase (P4-187a) — normalisés
     * ({@see VenueLabelNormalizer}) et dédupliqués. Un
     * domicile importé portant l'un d'eux retrouve son `venueId` automatiquement.
     *
     * @var list<string>
     */
    #[ORM\Column(type: 'json', options: ['default' => '[]'])]
    private array $externalLabels = [];

    #[ORM\Column(type: 'boolean')]
    private bool $isActive = true;

    public function __construct(Place $place)
    {
        $this->id = $this->newUuid();
        $now = new DateTimeImmutable;
        $this->createdAt = $now;
        $this->updatedAt = $now;
        $this->moveToPlace($place);
    }

    public function getPlaceId(): string
    {
        return $this->placeId;
    }

    /** Le gymnase devient l'usage d'un autre lieu du club (dissociation de sa salle fédérale, « Charger »). */
    public function moveToPlace(Place $place): self
    {
        $this->placeId = $place->getId();
        $this->place = $place;

        return $this;
    }

    /**
     * @internal lu par {@see PlaceDirectory} seulement — tout autre lecteur passe par lui
     */
    public function placeGivenAtCreation(): ?Place
    {
        return $this->place;
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

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getColor(): ?string
    {
        return $this->color;
    }

    public function setColor(?string $color): self
    {
        $this->color = $color;

        return $this;
    }

    public function getCanSplit(): bool
    {
        return $this->canSplit;
    }

    public function isCanSplit(): bool
    {
        return $this->canSplit;
    }

    public function setCanSplit(bool $canSplit): self
    {
        $this->canSplit = $canSplit;

        return $this;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function setSource(string $source): self
    {
        $this->source = $source;

        return $this;
    }

    public function getExternalRef(): ?string
    {
        return $this->externalRef;
    }

    public function setExternalRef(?string $externalRef): self
    {
        $this->externalRef = $externalRef;

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getExternalLabels(): array
    {
        return $this->externalLabels;
    }

    /**
     * @param list<string> $externalLabels
     */
    public function setExternalLabels(array $externalLabels): self
    {
        $this->externalLabels = array_values(array_unique($externalLabels));

        return $this;
    }

    /** Ajoute un alias déjà normalisé (idempotent). Vrai = nouvel alias posé. */
    public function addExternalLabel(string $normalizedLabel): bool
    {
        if (\in_array($normalizedLabel, $this->externalLabels, true)) {
            return false;
        }
        $this->externalLabels[] = $normalizedLabel;

        return true;
    }

    /** Retire un alias déjà normalisé (idempotent). Vrai = un alias a été retiré. */
    public function removeExternalLabel(string $normalizedLabel): bool
    {
        $filtered = array_values(array_filter(
            $this->externalLabels,
            static fn (string $label): bool => $label !== $normalizedLabel,
        ));
        if (\count($filtered) === \count($this->externalLabels)) {
            return false;
        }
        $this->externalLabels = $filtered;

        return true;
    }

    public function getIsActive(): bool
    {
        return $this->isActive;
    }

    public function isIsActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): self
    {
        $this->isActive = $isActive;

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
