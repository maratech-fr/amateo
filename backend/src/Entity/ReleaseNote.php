<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ReleaseNoteRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Product changelog entry (« quoi de neuf ») shown to every member and a
 * "what's new" modal. GLOBAL, not tenant-owned: same for every club, no
 * club_id → no RLS (public product reference, like `public_holiday`). Only a
 * GRANT to amateo_app; the super-admin console authors it, members read the
 * PUBLISHED ones.
 *
 * `publishedAt` null = draft (invisible to members). `noteDate` is EDITORIAL:
 * it is the date shown in the list and can be antedated — it never drives the
 * "what's new" gate (that reads `publishedAt`). The announced product version
 * lives in `productVersion` (nullable); credit details, if any, live free-form in
 * `body`.
 */
#[ORM\Entity(repositoryClass: ReleaseNoteRepository::class)]
#[ORM\Table(name: 'release_note')]
#[ORM\Index(name: 'idx_release_note_published_at', columns: ['published_at'])]
#[ORM\HasLifecycleCallbacks]
class ReleaseNote
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

    #[ORM\Column(type: 'string', length: 160)]
    private string $title;

    #[ORM\Column(type: 'text')]
    private string $body;

    #[ORM\Column(name: 'note_date', type: 'date_immutable')]
    private DateTimeImmutable $noteDate;

    #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
    private ?DateTimeImmutable $publishedAt = null;

    /**
     * Version du produit annoncée par la note (« 1.3.5 »), null = note sans version. Distincte
     * de `$version` (compteur de verrou optimiste Doctrine) ; exposée en JSON sous la clé `version`.
     */
    #[ORM\Column(name: 'product_version', type: 'string', length: 40, nullable: true)]
    private ?string $productVersion = null;

    public function __construct()
    {
        $this->id = $this->newUuid();
        $now = new DateTimeImmutable;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    #[ORM\PreUpdate]
    public function touchUpdatedAt(): void
    {
        $this->updatedAt = new DateTimeImmutable;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
    {
        $this->title = $title;

        return $this;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function setBody(string $body): self
    {
        $this->body = $body;

        return $this;
    }

    public function getNoteDate(): DateTimeImmutable
    {
        return $this->noteDate;
    }

    public function setNoteDate(DateTimeImmutable $noteDate): self
    {
        $this->noteDate = $noteDate;

        return $this;
    }

    public function getPublishedAt(): ?DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function setPublishedAt(?DateTimeImmutable $publishedAt): self
    {
        $this->publishedAt = $publishedAt;

        return $this;
    }

    public function getProductVersion(): ?string
    {
        return $this->productVersion;
    }

    public function setProductVersion(?string $productVersion): self
    {
        $this->productVersion = $productVersion;

        return $this;
    }

    public function isPublished(): bool
    {
        return $this->publishedAt instanceof DateTimeImmutable;
    }

    private function newUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
