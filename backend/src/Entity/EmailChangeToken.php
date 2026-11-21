<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\EmailChangeTokenRepository;
use App\Structure\Family;
use App\Structure\Level;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * A single-use e-mail-CHANGE confirmation token (P4-74).
 *
 * Kept deliberately separate from {@see EmailVerificationToken}: that one is
 * structurally coupled to the deferred club-creation intent (required `ara` +
 * `clubName`) and is consumed by the public /api/register/verify path. This one
 * only proves the holder controls the NEW address; the pending address itself
 * lives on {@see User::$pendingEmail}. Same recipe as the verification token —
 * sha256 hash stored (never the raw), TTL, single-use — so the mechanism is
 * reused without overloading the register token with a purpose flag.
 */
#[Family(Level::PLATFORM)]
#[ORM\Entity(repositoryClass: EmailChangeTokenRepository::class)]
#[ORM\Table(name: 'email_change_token')]
#[ORM\UniqueConstraint(name: 'uniq_email_change_hashed_token', columns: ['hashed_token'])]
class EmailChangeToken
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: 'string', length: 64)]
    private string $hashedToken;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $expiresAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    public function __construct(User $user, string $hashedToken, DateTimeImmutable $expiresAt, DateTimeImmutable $createdAt)
    {
        $this->user = $user;
        $this->hashedToken = $hashedToken;
        $this->expiresAt = $expiresAt;
        $this->createdAt = $createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getHashedToken(): string
    {
        return $this->hashedToken;
    }

    public function getExpiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isExpired(DateTimeImmutable $now): bool
    {
        return $this->expiresAt <= $now;
    }
}
