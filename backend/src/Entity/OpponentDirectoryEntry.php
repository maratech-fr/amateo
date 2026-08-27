<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\OpponentLocationPrecision;
use App\Repository\OpponentDirectoryEntryRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * The community-shared LOCATION of an FFBB opponent (P2-54 RMM-9). Keyed on the
 * opponent's FFBB organisme code — a PUBLIC federal identifier, jamais du texte
 * libre (décision fondateur) — it caches where an away opponent plays so that a
 * club engaged against the SAME opponent inherits the resolved venue/city instead
 * of resolving it again. Written best-effort after an FBI import or an FFBB-API
 * apply, and by the catch-up route; a more precise resolution (VENUE) replaces a
 * less precise one (CITY), never the reverse.
 *
 * NOTE: this is GLOBAL reference data — it holds NO club-identifying column
 * (no club_id, no user_id, no counter, no provenance, BY DESIGN —
 * OpponentDirectoryShareTest asserts the schema against the Postgres catalog).
 * It does NOT implement TenantOwnedInterface, is therefore outside RLS and the
 * season filter (same pattern as ffbb_league / shared_competition_deadline), and
 * carries no season column: a salle/organisme location is season-agnostic.
 *
 * ⚠ COROLLAIRE OPPOSABLE (revue sécurité 2026-08-25 F-2, patron
 * `SharedCompetitionDeadline`) : n'enrichir JAMAIS cette table — provenance
 * (quel club l'a écrite), compteurs d'usage, texte libre saisi par un club —
 * sans REPASSER la revue sécurité. Le code organisme est PUBLIC et auto-attribué
 * par la fédération ; tout ce qu'on ajouterait ici deviendrait, via ce partage
 * hors-tenant, un vecteur de fuite entre clubs. Données FÉDÉRALES PUBLIQUES
 * seulement (nom, ville, code postal, coordonnées, libellé de salle).
 */
#[ORM\Entity(repositoryClass: OpponentDirectoryEntryRepository::class)]
#[ORM\Table(name: 'opponent_directory')]
#[ORM\UniqueConstraint(name: 'uniq_opponent_directory_code', columns: ['ffbb_organisme_code'])]
#[ORM\HasLifecycleCallbacks]
class OpponentDirectoryEntry
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'ffbb_organisme_code', length: 64)]
    private string $ffbbOrganismeCode;

    #[ORM\Column(length: 180)]
    private string $name;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $city = null;

    #[ORM\Column(name: 'postal_code', length: 16, nullable: true)]
    private ?string $postalCode = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $latitude = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $longitude = null;

    #[ORM\Column(length: 8, enumType: OpponentLocationPrecision::class)]
    private OpponentLocationPrecision $precision;

    /** The salle name when a VENUE-precise location was resolved; null for a CITY. */
    #[ORM\Column(name: 'venue_label', length: 180, nullable: true)]
    private ?string $venueLabel = null;

    #[ORM\Column(name: 'resolved_at', type: 'datetimetz_immutable')]
    private DateTimeImmutable $resolvedAt;

    public function __construct(string $ffbbOrganismeCode, string $name, OpponentLocationPrecision $precision)
    {
        $this->id = $this->newUuid();
        $this->ffbbOrganismeCode = $ffbbOrganismeCode;
        $this->name = $name;
        $this->precision = $precision;
        $this->resolvedAt = new DateTimeImmutable;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getFfbbOrganismeCode(): string
    {
        return $this->ffbbOrganismeCode;
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

    public function getCity(): ?string
    {
        return $this->city;
    }

    public function setCity(?string $city): self
    {
        $this->city = $city;

        return $this;
    }

    public function getPostalCode(): ?string
    {
        return $this->postalCode;
    }

    public function setPostalCode(?string $postalCode): self
    {
        $this->postalCode = $postalCode;

        return $this;
    }

    public function getLatitude(): ?float
    {
        return $this->latitude;
    }

    public function setLatitude(?float $latitude): self
    {
        $this->latitude = $latitude;

        return $this;
    }

    public function getLongitude(): ?float
    {
        return $this->longitude;
    }

    public function setLongitude(?float $longitude): self
    {
        $this->longitude = $longitude;

        return $this;
    }

    public function getPrecision(): OpponentLocationPrecision
    {
        return $this->precision;
    }

    public function setPrecision(OpponentLocationPrecision $precision): self
    {
        $this->precision = $precision;

        return $this;
    }

    public function getVenueLabel(): ?string
    {
        return $this->venueLabel;
    }

    public function setVenueLabel(?string $venueLabel): self
    {
        $this->venueLabel = $venueLabel;

        return $this;
    }

    public function getResolvedAt(): DateTimeImmutable
    {
        return $this->resolvedAt;
    }

    public function touchResolvedAt(): self
    {
        $this->resolvedAt = new DateTimeImmutable;

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
