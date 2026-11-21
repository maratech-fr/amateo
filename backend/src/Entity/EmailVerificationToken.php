<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\EmailVerificationTokenRepository;
use App\Structure\Family;
use App\Structure\Level;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * A single-use email-verification token. Registration is deferred: the account
 * is created unverified (User.emailVerifiedAt = null) and the tenant (club +
 * seed) is materialised ONLY when the token is consumed — so the club-creation
 * intent (ffbb code + optional club name) rides on the token until then. The
 * raw token is emailed; only its sha256 hash is stored.
 */
#[Family(Level::PLATFORM)]
#[ORM\Entity(repositoryClass: EmailVerificationTokenRepository::class)]
#[ORM\Table(name: 'email_verification_token')]
#[ORM\UniqueConstraint(name: 'uniq_email_verification_hashed_token', columns: ['hashed_token'])]
class EmailVerificationToken
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

    /** FFBB club code the pending account registered against (drives club resolution on verify). */
    #[ORM\Column(type: 'string', length: 20)]
    private string $ara;

    /** Club name to create when the ffbb code is new; null when joining an existing club. */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $clubName = null;

    public function __construct(User $user, string $hashedToken, DateTimeImmutable $expiresAt, DateTimeImmutable $createdAt, string $ara, ?string $clubName)
    {
        $this->user = $user;
        $this->hashedToken = $hashedToken;
        $this->expiresAt = $expiresAt;
        $this->createdAt = $createdAt;
        $this->ara = $ara;
        $this->clubName = $clubName;
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

    public function getAra(): string
    {
        return $this->ara;
    }

    public function getClubName(): ?string
    {
        return $this->clubName;
    }

    public function isExpired(DateTimeImmutable $now): bool
    {
        return $this->expiresAt <= $now;
    }
}
