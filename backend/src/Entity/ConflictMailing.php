<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ConflictMailingRepository;
use App\Service\ConflictCoachMailing;
use App\Service\SeasonDataPurger;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Envoi des conflits aux coachs — la mémoire du DERNIER envoi d'un club pour UNE saison (une
 * ligne par (club, saison) ; deux saisons = deux mémoires indépendantes).
 *
 *  - `sentAt` / `sentByUserId` / `mailedCount` / `skippedCoachIds` : la ligne « Envoyé le … »
 *    de l'onglet Conflits ; l'auteur est un guid SANS FK, résolu à la lecture (disparu → sans
 *    « par ») ;
 *  - `recipientCoachIds` : les coachs qui détiennent encore une liste de conflits — ceux à
 *    prévenir d'un « plus aucun conflit » quand leurs conflits disparaissent. Règle unique :
 *    {@see ConflictCoachMailing::nextMemory}.
 *
 * Écrite seulement quand au moins un mail a été enfilé, par upsert SQL
 * ({@see ConflictMailingRepository::upsert}) ; purgée avec la saison ({@see SeasonDataPurger}).
 */
#[ORM\Entity(repositoryClass: ConflictMailingRepository::class)]
#[ORM\Table(name: 'conflict_mailing')]
#[ORM\UniqueConstraint(name: 'uniq_conflict_mailing_club_season', columns: ['club_id', 'season_id'])]
class ConflictMailing implements TenantOwnedInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(type: 'guid')]
    private string $clubId;

    #[ORM\Column(type: 'guid')]
    private string $seasonId;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private DateTimeImmutable $sentAt;

    #[ORM\Column(type: 'guid', nullable: true)]
    private ?string $sentByUserId = null;

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $recipientCoachIds = [];

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $skippedCoachIds = [];

    #[ORM\Column(type: 'integer')]
    private int $mailedCount = 0;

    public function __construct()
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);
        $this->id = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
        $this->sentAt = new DateTimeImmutable;
    }

    public function getId(): string
    {
        return $this->id;
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

    public function getSeasonId(): string
    {
        return $this->seasonId;
    }

    public function setSeasonId(string $seasonId): self
    {
        $this->seasonId = $seasonId;

        return $this;
    }

    public function getSentAt(): DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function setSentAt(DateTimeImmutable $sentAt): self
    {
        $this->sentAt = $sentAt;

        return $this;
    }

    public function getSentByUserId(): ?string
    {
        return $this->sentByUserId;
    }

    public function setSentByUserId(?string $sentByUserId): self
    {
        $this->sentByUserId = $sentByUserId;

        return $this;
    }

    /** @return list<string> */
    public function getRecipientCoachIds(): array
    {
        return array_values(array_filter($this->recipientCoachIds, is_string(...)));
    }

    /** @param list<string> $recipientCoachIds */
    public function setRecipientCoachIds(array $recipientCoachIds): self
    {
        $this->recipientCoachIds = $recipientCoachIds;

        return $this;
    }

    /** @return list<string> */
    public function getSkippedCoachIds(): array
    {
        return array_values(array_filter($this->skippedCoachIds, is_string(...)));
    }

    /** @param list<string> $skippedCoachIds */
    public function setSkippedCoachIds(array $skippedCoachIds): self
    {
        $this->skippedCoachIds = $skippedCoachIds;

        return $this;
    }

    public function getMailedCount(): int
    {
        return $this->mailedCount;
    }

    public function setMailedCount(int $mailedCount): self
    {
        $this->mailedCount = $mailedCount;

        return $this;
    }
}
