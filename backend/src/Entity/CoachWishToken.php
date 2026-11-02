<?php

declare(strict_types=1);

namespace App\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Le lien personnel d'un coach vers la page publique de collecte (feature #10, lot C2).
 *
 * `token` est un SECRET stocké EN CLAIR (décision fondateur 2026-07-26) : le gestionnaire
 * doit pouvoir « copier le lien » à tout moment pour le renvoyer en WhatsApp, ce qu'un hash
 * interdirait. Le privilège du token est minuscule et borné par construction — il n'écrit
 * que des SOUHAITS (jamais une contrainte, aucun effet solveur), dans le PÉRIMÈTRE du token
 * (ce coach, ses équipes ∩ campagne, les semaines de la campagne), et meurt à la deadline.
 * (Cf. security-review : anti-énumération + rate limit + RLS-write tenant complètent la
 * défense.)
 *
 * `club_id` est porté sur le token pour poser le GUC `app.club_id` AVANT toute écriture (la
 * requête publique n'a pas de JWT). La table est sous RLS HYBRIDE (SELECT ouvert pour le
 * lookup pré-GUC, écritures tenant — cf. la migration).
 */
#[ORM\Entity]
#[ORM\Table(name: 'coach_wish_token')]
#[ORM\UniqueConstraint(name: 'uniq_coach_wish_token_value', columns: ['token'])]
#[ORM\UniqueConstraint(name: 'uniq_coach_wish_token_campaign_coach', columns: ['campaign_id', 'coach_id'])]
#[ORM\Index(name: 'idx_coach_wish_token_campaign', columns: ['campaign_id'])]
class CoachWishToken implements TenantOwnedInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(type: 'string', length: 64)]
    private string $token;

    #[ORM\Column(type: 'guid')]
    private string $campaignId;

    #[ORM\Column(type: 'guid')]
    private string $coachId;

    #[ORM\Column(type: 'guid')]
    private string $clubId;

    /** Horodatage de la DERNIÈRE soumission du coach ; null = n'a pas encore répondu. */
    #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
    private ?DateTimeImmutable $respondedAt = null;

    /** Dernier envoi du lien par email (C3, décision D1 : par token) ; null = jamais envoyé. */
    #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
    private ?DateTimeImmutable $sentAt = null;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private DateTimeImmutable $createdAt;

    /**
     * Les équipes pour lesquelles ce coach répond dans cette campagne : vérité persistée, posée
     * par la règle du destinataire (CoachWishCampaignTokenSync). Vide = ex-destinataire : son lien répond encore,
     * sans équipe.
     *
     * @var list<string>
     */
    #[ORM\Column(type: 'json', options: ['default' => '[]'])]
    private array $teamIds = [];

    public function __construct()
    {
        $this->id = $this->newUuid();
        // 32 octets aléatoires → 64 hex : secret non-devinable, forme validée côté public.
        $this->token = bin2hex(random_bytes(32));
        $this->createdAt = new DateTimeImmutable;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function getCampaignId(): string
    {
        return $this->campaignId;
    }

    public function setCampaignId(string $campaignId): self
    {
        $this->campaignId = $campaignId;

        return $this;
    }

    public function getCoachId(): string
    {
        return $this->coachId;
    }

    public function setCoachId(string $coachId): self
    {
        $this->coachId = $coachId;

        return $this;
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

    public function getSentAt(): ?DateTimeImmutable
    {
        return $this->sentAt;
    }

    /** Horodatage du DERNIER envoi du lien par email (C3) — null = jamais envoyé. */
    public function markSent(DateTimeImmutable $at): self
    {
        $this->sentAt = $at;

        return $this;
    }

    public function getRespondedAt(): ?DateTimeImmutable
    {
        return $this->respondedAt;
    }

    public function markResponded(DateTimeImmutable $at): self
    {
        $this->respondedAt = $at;

        return $this;
    }

    /** Le coach répond pour une équipe de plus : il repasse « à envoyer », même lien. */
    public function backToSend(): self
    {
        $this->sentAt = null;
        $this->respondedAt = null;

        return $this;
    }

    /** @return list<string> */
    public function getTeamIds(): array
    {
        return $this->teamIds;
    }

    /** @param list<string> $teamIds */
    public function setTeamIds(array $teamIds): self
    {
        $this->teamIds = $teamIds;

        return $this;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    private function newUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
