<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\OpponentVenueLinkSource;
use App\Repository\OpponentVenueLinkRepository;
use App\Service\Basketball\VenueLabelNormalizer;
use App\Service\ErasedClubPurger;
use App\Service\Geo\TravelTimeCache;
use App\Service\SeasonDataPurger;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * L'appariement d'un LIBELLÉ de salle FBI vers un GYMNASE fédéral, POUR CE CLUB
 * (P2-54 « adversaire multi-gymnases », amendement 2026-09-20 : club-scoped SANS
 * saison). Un adversaire joue dans une salle donnée quelle que soit son équipe ou
 * la saison — le gymnase se rattache au CLUB adverse et au LIBELLÉ vu dans le
 * fichier, jamais à l'équipe (prouvé en base : même équipe, deux salles) ni à la
 * saison (« SALLE TOLA VOLOGE = ce gymnase de Bron » ne dépend d'aucune saison,
 * exactement comme les distances de {@see ClubTravelCache}).
 *
 * ⚠ Le trajet n'est PAS ici : il appartient au gymnase (deux coordonnées + le siège
 * du club) et se sert depuis {@see ClubTravelCache} via {@see TravelTimeCache}.
 * Cette table ne porte que l'appariement libellé → lieu ; le trajet est une CONSTANTE
 * qui vit dans le cache club-scoped, jamais dupliquée par saison.
 *
 * Grain : `(club, code organisme adverse, libellé FBI normalisé)`. Le libellé normalisé
 * (foyer {@see VenueLabelNormalizer::normalize}) est la clé — une
 * rencontre AWAY retrouve SON lien par le libellé de SA salle
 * ({@see Fixture::getFbiVenueLabel()}). Un couple = un lien (unique).
 *
 * `venueExternalRef` (nullable) : le numéro de salle FÉDÉRAL quand le gymnase vient de
 * l'index FFBB — null pour un gymnase choisi par coordonnées seules. `venueLabel` +
 * `latitude`/`longitude` : le snapshot fédéral du gymnase (jamais le texte brut du
 * fichier), qui sert d'origine au trajet dans le cache. `source` (AUTO|MANUAL) : un
 * MANUAL n'est jamais écrasé par une passe AUTO.
 *
 * Purge : club-scoped SANS saison → une purge de SAISON ne le touche jamais
 * ({@see SeasonDataPurger::EXCLUDED_FROM_SEASON_PURGE}) ; sa seule porte de
 * sortie est l'effacement RGPD du club ({@see ErasedClubPurger::PURGED_BY_CLUB},
 * qui décrémente d'abord le compteur partagé des liens MANUAL).
 */
#[ORM\Entity(repositoryClass: OpponentVenueLinkRepository::class)]
#[ORM\Table(name: 'opponent_venue_link')]
#[ORM\UniqueConstraint(name: 'uniq_opponent_venue_link', columns: ['club_id', 'opponent_organisme_code', 'fbi_label_norm'])]
#[ORM\Index(name: 'idx_opponent_venue_link_club_code', columns: ['club_id', 'opponent_organisme_code'])]
#[ORM\HasLifecycleCallbacks]
class OpponentVenueLink implements TenantOwnedInterface
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

    #[ORM\Column(name: 'opponent_organisme_code', length: 64)]
    private string $opponentOrganismeCode;

    /** Le libellé de salle tel qu'il a été VU dans le fichier FBI (brut, pour l'affichage). */
    #[ORM\Column(name: 'fbi_label', length: 180)]
    private string $fbiLabel;

    /** Le libellé normalisé (clé du lien) — foyer {@see VenueLabelNormalizer::normalize}. */
    #[ORM\Column(name: 'fbi_label_norm', length: 180)]
    private string $fbiLabelNorm;

    /** Numéro de salle FÉDÉRAL (index FFBB) — null pour un gymnase choisi par coordonnées seules. */
    #[ORM\Column(name: 'venue_external_ref', length: 64, nullable: true)]
    private ?string $venueExternalRef = null;

    #[ORM\Column(name: 'venue_label', length: 180)]
    private string $venueLabel;

    #[ORM\Column(name: 'latitude', type: 'float')]
    private float $latitude;

    #[ORM\Column(name: 'longitude', type: 'float')]
    private float $longitude;

    #[ORM\Column(length: 10, enumType: OpponentVenueLinkSource::class)]
    private OpponentVenueLinkSource $source = OpponentVenueLinkSource::AUTO;

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

    public function getOpponentOrganismeCode(): string
    {
        return $this->opponentOrganismeCode;
    }

    public function setOpponentOrganismeCode(string $opponentOrganismeCode): self
    {
        $this->opponentOrganismeCode = $opponentOrganismeCode;

        return $this;
    }

    public function getFbiLabel(): string
    {
        return $this->fbiLabel;
    }

    public function setFbiLabel(string $fbiLabel): self
    {
        $this->fbiLabel = $fbiLabel;

        return $this;
    }

    public function getFbiLabelNorm(): string
    {
        return $this->fbiLabelNorm;
    }

    public function setFbiLabelNorm(string $fbiLabelNorm): self
    {
        $this->fbiLabelNorm = $fbiLabelNorm;

        return $this;
    }

    public function getVenueExternalRef(): ?string
    {
        return $this->venueExternalRef;
    }

    public function setVenueExternalRef(?string $venueExternalRef): self
    {
        $this->venueExternalRef = $venueExternalRef;

        return $this;
    }

    public function getVenueLabel(): string
    {
        return $this->venueLabel;
    }

    public function setVenueLabel(string $venueLabel): self
    {
        $this->venueLabel = $venueLabel;

        return $this;
    }

    public function getLatitude(): float
    {
        return $this->latitude;
    }

    public function setLatitude(float $latitude): self
    {
        $this->latitude = $latitude;

        return $this;
    }

    public function getLongitude(): float
    {
        return $this->longitude;
    }

    public function setLongitude(float $longitude): self
    {
        $this->longitude = $longitude;

        return $this;
    }

    public function getSource(): OpponentVenueLinkSource
    {
        return $this->source;
    }

    public function setSource(OpponentVenueLinkSource $source): self
    {
        $this->source = $source;

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
