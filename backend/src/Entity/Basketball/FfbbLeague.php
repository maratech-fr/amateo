<?php

declare(strict_types=1);

namespace App\Entity\Basketball;

use App\Repository\Basketball\FfbbLeagueRepository;
use App\Structure\Family;
use App\Structure\Level;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * FFBB league (ligue régionale), public reference data pulled from the FFBB API
 * (lot C). Shared across clubs — keyed on the FFBB league code (e.g. "ARA"), so
 * a league already fetched by one club is reused for the next (cache-first).
 *
 * NOTE: this is GLOBAL reference data — it has NO club_id, is NOT tenant-owned
 * (does not implement TenantOwnedInterface) and is therefore outside RLS, like
 * the `club`/`user` tables. See specs/evolution/import-ffbb-autofill.md §4.
 */
#[Family(Level::PLATFORM)]
#[ORM\Entity(repositoryClass: FfbbLeagueRepository::class)]
#[ORM\Table(name: 'ffbb_league')]
#[ORM\UniqueConstraint(name: 'uniq_ffbb_league_code', columns: ['code'])]
class FfbbLeague
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(type: 'string', length: 24)]
    private string $code;

    #[ORM\Column(type: 'string', length: 255)]
    private string $name;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $address = null;

    #[ORM\Column(type: 'string', length: 16, nullable: true)]
    private ?string $postalCode = null;

    #[ORM\Column(type: 'string', length: 120, nullable: true)]
    private ?string $city = null;

    #[ORM\Column(type: 'string', length: 32, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(type: 'string', length: 180, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $logoUrl = null;

    // Site web officiel (`urlSiteWeb` Meilisearch) — cadrage api-ffbb-completion-club §2.
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $website = null;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private DateTimeImmutable $fetchedAt;

    public function __construct()
    {
        $this->id = $this->newUuid();
        $this->fetchedAt = new DateTimeImmutable;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): self
    {
        $this->code = $code;

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

    public function getAddress(): ?string
    {
        return $this->address;
    }

    public function setAddress(?string $address): self
    {
        $this->address = $address;

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

    public function getCity(): ?string
    {
        return $this->city;
    }

    public function setCity(?string $city): self
    {
        $this->city = $city;

        return $this;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): self
    {
        $this->phone = $phone;

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): self
    {
        $this->email = $email;

        return $this;
    }

    public function getLogoUrl(): ?string
    {
        return $this->logoUrl;
    }

    public function setLogoUrl(?string $logoUrl): self
    {
        $this->logoUrl = $logoUrl;

        return $this;
    }

    public function getWebsite(): ?string
    {
        return $this->website;
    }

    public function setWebsite(?string $website): self
    {
        $this->website = $website;

        return $this;
    }

    public function getFetchedAt(): DateTimeImmutable
    {
        return $this->fetchedAt;
    }

    public function setFetchedAt(DateTimeImmutable $fetchedAt): self
    {
        $this->fetchedAt = $fetchedAt;

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
