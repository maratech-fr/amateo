<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ClubMailboxMessageRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un e-mail INTERCEPTÉ : rangé dans la « boîte aux lettres » du club au lieu de partir
 * réellement, parce que ce club vit à une horloge SIMULÉE (P4-16). Un club à horloge active
 * ne doit jamais envoyer de vrai e-mail — l'animateur de démo rejoue « à trois semaines des
 * vacances » sans spammer les gestionnaires (option A validée fondateur 2026-10-02).
 *
 * Fait DATÉ, append-only : jamais modifié après l'enfilage, donc ni `version` ni `updated_at`.
 * `simulated_date` = le jour SIMULÉ du club au moment de l'interception (ClubDay) ; `created_at`
 * = l'instant RÉEL d'écriture (tri chronologique fiable, indépendant de l'horloge rejouée).
 * Le corps est capté À L'ENFILAGE, AVANT la signature de marque (posée chez le worker, qui ne
 * tourne jamais ici) : `body_text` porte le texte métier, `body_html` est en général nul.
 *
 * Tenant + RLS comme toute table club_id : la boîte d'un club n'est lisible que sous son GUC
 * (RlsIsolationTest / ReadOnlyRoleTest la découvrent dynamiquement). Vidée au reset de la démo
 * et à la désactivation de l'horloge (purge prospect = CASCADE sur club_id).
 */
#[ORM\Entity(repositoryClass: ClubMailboxMessageRepository::class)]
#[ORM\Table(name: 'club_mailbox_message')]
#[ORM\Index(name: 'idx_club_mailbox_message_club_created', columns: ['club_id', 'created_at'])]
class ClubMailboxMessage implements TenantOwnedInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(type: 'guid')]
    private string $clubId;

    /** L'instant RÉEL d'écriture — tri chronologique, jamais l'horloge simulée. */
    #[ORM\Column(type: 'datetimetz_immutable')]
    private DateTimeImmutable $createdAt;

    /** Le jour SIMULÉ du club à l'interception (ClubDay) — « le jour » affiché dans la boîte. */
    #[ORM\Column(type: 'date_immutable')]
    private DateTimeImmutable $simulatedDate;

    #[ORM\Column(type: 'string', length: 255)]
    private string $fromAddress;

    /** Destinataires, joints par « , » quand il y en a plusieurs. */
    #[ORM\Column(type: 'string', length: 1000)]
    private string $toAddress;

    #[ORM\Column(type: 'string', length: 998)]
    private string $subject;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $bodyText = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $bodyHtml = null;

    public function __construct()
    {
        $this->id = $this->newUuid();
        $this->createdAt = new DateTimeImmutable;
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

    public function getClubId(): ?string
    {
        return $this->clubId;
    }

    public function setClubId(string $clubId): self
    {
        $this->clubId = $clubId;

        return $this;
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

    public function getSimulatedDate(): DateTimeImmutable
    {
        return $this->simulatedDate;
    }

    public function setSimulatedDate(DateTimeImmutable $simulatedDate): self
    {
        $this->simulatedDate = $simulatedDate;

        return $this;
    }

    public function getFromAddress(): string
    {
        return $this->fromAddress;
    }

    public function setFromAddress(string $fromAddress): self
    {
        $this->fromAddress = $fromAddress;

        return $this;
    }

    public function getToAddress(): string
    {
        return $this->toAddress;
    }

    public function setToAddress(string $toAddress): self
    {
        $this->toAddress = $toAddress;

        return $this;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function setSubject(string $subject): self
    {
        $this->subject = $subject;

        return $this;
    }

    public function getBodyText(): ?string
    {
        return $this->bodyText;
    }

    public function setBodyText(?string $bodyText): self
    {
        $this->bodyText = $bodyText;

        return $this;
    }

    public function getBodyHtml(): ?string
    {
        return $this->bodyHtml;
    }

    public function setBodyHtml(?string $bodyHtml): self
    {
        $this->bodyHtml = $bodyHtml;

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
