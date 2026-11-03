<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ClubCreationRequestRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * P3-4 — demande de CRÉATION d'un club (anti-squatting). Le premier inscrivant
 * d'un ARA inconnu ne matérialise plus le club à la vérification d'email : sa
 * demande attend l'approbation du CLUB lui-même, via un lien envoyé au mail
 * institutionnel FFBB (`clubEmail`) — ou le superadmin quand ce mail manque
 * (décision fondateur 2026-08-05). L'approbation crée le club (provisioning
 * complet) ; le refus clôt.
 *
 * Pas de `club_id` (le club n'existe pas encore) → hors RLS, comme les tables
 * de référence. Le `token` est un secret de 32 octets stocké EN CLAIR (patron
 * CoachWishToken : page publique sans compte, pas d'endpoint de listing, 404
 * byte-identique pour inconnu et malformé).
 */
#[ORM\Entity(repositoryClass: ClubCreationRequestRepository::class)]
#[ORM\Table(name: 'club_creation_request')]
#[ORM\Index(name: 'idx_club_creation_request_ara', columns: ['ara'])]
#[ORM\Index(name: 'idx_club_creation_request_status', columns: ['status'])]
class ClubCreationRequest
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REFUSED = 'refused';
    public const STATUS_EXPIRED = 'expired';
    /** Close sans décision : compte du demandeur effacé (aucun mail, lien mort). */
    public const STATUS_CANCELLED = 'cancelled';
    /** Dépassée : un gestionnaire a repris le club avant l'examen de la demande. */
    public const STATUS_SUPERSEDED = 'superseded';

    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(type: 'string', length: 64, unique: true)]
    private string $token;

    #[ORM\Column(type: 'guid')]
    private string $userId;

    #[ORM\Column(type: 'string', length: 20)]
    private string $ara;

    #[ORM\Column(type: 'string', length: 255)]
    private string $clubName;

    /** Mail institutionnel FFBB du club — null : introuvable → file superadmin. */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $clubEmail = null;

    #[ORM\Column(type: 'string', length: 20)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $expiresAt;

    /** Dernière relance envoyée (PR B : 3 j restants, puis le jour J). */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $remindedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $decidedAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->id = $this->newUuid();
        $this->token = bin2hex(random_bytes(32));
        $this->createdAt = new DateTimeImmutable;
        $this->expiresAt = new DateTimeImmutable('+7 days');
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function setUserId(string $userId): self
    {
        $this->userId = $userId;

        return $this;
    }

    public function getAra(): string
    {
        return $this->ara;
    }

    public function setAra(string $ara): self
    {
        $this->ara = $ara;

        return $this;
    }

    public function getClubName(): string
    {
        return $this->clubName;
    }

    public function setClubName(string $clubName): self
    {
        $this->clubName = $clubName;

        return $this;
    }

    public function getClubEmail(): ?string
    {
        return $this->clubEmail;
    }

    public function setClubEmail(?string $clubEmail): self
    {
        $this->clubEmail = $clubEmail;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getExpiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(DateTimeImmutable $expiresAt): self
    {
        $this->expiresAt = $expiresAt;

        return $this;
    }

    public function getRemindedAt(): ?DateTimeImmutable
    {
        return $this->remindedAt;
    }

    public function setRemindedAt(?DateTimeImmutable $remindedAt): self
    {
        $this->remindedAt = $remindedAt;

        return $this;
    }

    public function getDecidedAt(): ?DateTimeImmutable
    {
        return $this->decidedAt;
    }

    public function setDecidedAt(?DateTimeImmutable $decidedAt): self
    {
        $this->decidedAt = $decidedAt;

        return $this;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    private function newUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = \chr(\ord($data[6]) & 0x0F | 0x40);
        $data[8] = \chr(\ord($data[8]) & 0x3F | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
