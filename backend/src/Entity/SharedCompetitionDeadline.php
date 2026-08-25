<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SharedCompetitionDeadlineRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * The community-shared entry deadline for one FFBB competition (RMM-6). When a
 * club sets the entry deadline of a competition it has PAIRED to the federation
 * (a non-null ffbbCompetitionId), the value is also stored here, keyed on that
 * federation competition id — so the NEXT club engaged in the SAME federation
 * competition inherits a sensible default it can override (décision fondateur :
 * « si le premier club set la date, le club de la même ligue aura une date par
 * défaut qu'il peut surcharger »). Last write wins: any club posting a deadline
 * on the paired competition overwrites this row.
 *
 * NOTE: this is GLOBAL reference data — it holds NO club-identifying column
 * (no club_id, no user_id, no counter, BY DESIGN — EntryDeadlineShareTest
 * asserts the schema against the Postgres catalog). It does NOT implement
 * TenantOwnedInterface, is therefore outside RLS and the season filter (same
 * pattern as ffbb_league / league_match_window), and carries no season column:
 * an FFBB competition id is already season-scoped by the federation
 * (FfbbEngagementReader — an id only exists within its season).
 */
#[ORM\Entity(repositoryClass: SharedCompetitionDeadlineRepository::class)]
#[ORM\Table(name: 'shared_competition_deadline')]
#[ORM\UniqueConstraint(name: 'uniq_shared_competition_deadline_ffbb', columns: ['ffbb_competition_id'])]
#[ORM\HasLifecycleCallbacks]
class SharedCompetitionDeadline
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(name: 'ffbb_competition_id', length: 64)]
    private string $ffbbCompetitionId;

    #[ORM\Column(name: 'entry_deadline', type: 'date_immutable')]
    private DateTimeImmutable $entryDeadline;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private DateTimeImmutable $updatedAt;

    public function __construct(string $ffbbCompetitionId, DateTimeImmutable $entryDeadline)
    {
        $this->id = $this->newUuid();
        $this->ffbbCompetitionId = $ffbbCompetitionId;
        $this->entryDeadline = $entryDeadline;
        $now = new DateTimeImmutable;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getFfbbCompetitionId(): string
    {
        return $this->ffbbCompetitionId;
    }

    public function getEntryDeadline(): DateTimeImmutable
    {
        return $this->entryDeadline;
    }

    public function setEntryDeadline(DateTimeImmutable $entryDeadline): self
    {
        $this->entryDeadline = $entryDeadline;

        return $this;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    #[ORM\PreUpdate]
    public function touchUpdatedAt(): void
    {
        $this->updatedAt = new DateTimeImmutable;
    }

    private function newUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
