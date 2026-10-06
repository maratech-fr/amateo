<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ClubInvitationRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * P4-299 — une invitation nominative à rejoindre un club : un gestionnaire saisit
 * une adresse e-mail + un rôle, l'invité suit un lien personnel et entre membre
 * ACTIF du club (sans file d'approbation). Table TENANT (porte `club_id` → RLS
 * FORCE + SELECT hybride, patron CoachWishToken) : le listing côté gestionnaire ne
 * fuit jamais les invitations d'un autre club, et la page publique peut résoudre le
 * jeton AVANT que le GUC tenant soit posé (c'est la ligne lue qui porte le club).
 *
 * Seul le sha256 du jeton est stocké (patron EmailVerifier) : le raw est e-mailé et
 * jamais persisté — une fuite de base ne rend aucun lien exploitable. Pas de bouton
 * « copier le lien » : le privilège accordé est une ADHÉSION, bien plus qu'une
 * doléance (décision fondateur, cf. cadrage).
 *
 * Unicité (club_id, email) : « renvoyer » régénère le hash et repousse l'expiration
 * sur la MÊME ligne, jamais une seconde. L'acceptation CONSOMME la ligne (single-use,
 * patron EmailVerifier::consume) — après entrée dans le club, l'invitation disparaît
 * du listing, l'invité étant désormais visible dans les membres.
 */
#[ORM\Entity(repositoryClass: ClubInvitationRepository::class)]
#[ORM\Table(name: 'club_invitation')]
#[ORM\Index(name: 'idx_club_invitation_club', columns: ['club_id'])]
#[ORM\UniqueConstraint(name: 'uniq_club_invitation_token', columns: ['token_hash'])]
#[ORM\UniqueConstraint(name: 'uniq_club_invitation_club_email', columns: ['club_id', 'email'])]
class ClubInvitation implements TenantOwnedInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(type: 'guid')]
    private string $clubId;

    /** L'adresse invitée, stockée en minuscules (patron RegisterService). Elle verrouille l'adresse du compte qui en bénéficie. */
    #[ORM\Column(type: 'string', length: 255)]
    private string $email;

    /** Rôle d'adhésion visé (valeur ClubRole : admin/member). Défaut Membre côté contrôleur. */
    #[ORM\Column(type: 'string', length: 20)]
    private string $role;

    /** sha256 du jeton brut e-mailé — seul le hash vit en base. */
    #[ORM\Column(type: 'string', length: 64)]
    private string $tokenHash;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $expiresAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    /** Le gestionnaire émetteur — trace, jamais exposé publiquement. */
    #[ORM\Column(type: 'guid')]
    private string $invitedByUserId;

    public function __construct()
    {
        $this->id = $this->newUuid();
        $this->createdAt = new DateTimeImmutable;
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

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): self
    {
        $this->email = $email;

        return $this;
    }

    public function getRole(): string
    {
        return $this->role;
    }

    public function setRole(string $role): self
    {
        $this->role = $role;

        return $this;
    }

    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    public function setTokenHash(string $tokenHash): self
    {
        $this->tokenHash = $tokenHash;

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

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getInvitedByUserId(): string
    {
        return $this->invitedByUserId;
    }

    public function setInvitedByUserId(string $invitedByUserId): self
    {
        $this->invitedByUserId = $invitedByUserId;

        return $this;
    }

    public function isExpired(DateTimeImmutable $now): bool
    {
        return $this->expiresAt <= $now;
    }

    private function newUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
