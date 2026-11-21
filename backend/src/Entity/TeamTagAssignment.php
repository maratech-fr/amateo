<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TeamTagAssignmentRepository;
use App\Structure\Family;
use App\Structure\Level;
use App\Structure\OnDelete;
use App\Structure\RefersTo;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[Family(Level::SEASON)]
#[ORM\Entity(repositoryClass: TeamTagAssignmentRepository::class)]
#[ORM\Table(name: 'team_tag_assignment')]
#[ORM\Index(name: 'idx_team_tag_assignment_team', columns: ['team_id'])]
#[ORM\Index(name: 'idx_team_tag_assignment_tag', columns: ['tag_id'])]
#[ORM\Index(name: 'idx_team_tag_assignment_season', columns: ['season_id'])]
#[ORM\Index(name: 'idx_team_tag_assignment_club', columns: ['club_id'])]
#[ORM\HasLifecycleCallbacks]
/**
 * BCK-11 — la table porte son `club_id` depuis le 2026-08-07.
 *
 * C'était le SEUL objet lié à un tenant sans colonne `club_id`, donc le seul
 * hors RLS : son isolation reposait entièrement sur le filtre Doctrine de saison
 * (`season_id = saison courante`, elle-même validée comme appartenant au club).
 * Ça tenait — mais sans **backstop base de données** : la moindre lecture hors
 * filtre (worker, commande, requête forgée à la main) voyait toutes les
 * assignations de tous les clubs, alors que la même erreur sur n'importe quelle
 * autre table est arrêtée par PostgreSQL.
 *
 * En implémentant `TenantOwnedInterface`, la table entre automatiquement dans les
 * gardes existants : `RlsIsolationTest::testEveryClubIdTableIsUnderForcedRls`
 * découvre les tables à `club_id` et exige policy + FORCE ;
 * `TenantOwnedInterfaceCompletenessTest` exige l'interface. Aucun test à écrire
 * pour ça : c'est le filet qui s'étend tout seul.
 */
class TeamTagAssignment implements TenantOwnedInterface
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

    #[ORM\Column(type: 'guid')]
    private string $clubId;

    #[ORM\Column(type: 'guid')]
    #[RefersTo(Team::class, OnDelete::DELETE)]
    private string $teamId;

    #[ORM\Column(type: 'guid')]
    #[RefersTo(TeamTag::class, OnDelete::DELETE)]
    private string $tagId;

    #[ORM\Column(type: 'guid')]
    private string $seasonId;

    public function __construct()
    {
        $this->id = $this->newUuid();
        $now = new DateTimeImmutable;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getClubId(): ?string
    {
        return $this->clubId ?? null;
    }

    public function setClubId(string $clubId): self
    {
        $this->clubId = $clubId;

        return $this;
    }

    public function setId(string $id): self
    {
        $this->id = $id;

        return $this;
    }

    public function getVersion(): int
    {
        return $this->version;
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

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(DateTimeImmutable $updatedAt): self
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    #[ORM\PreUpdate]
    public function touchUpdatedAt(): void
    {
        $this->updatedAt = new DateTimeImmutable;
    }

    public function getTeamId(): string
    {
        return $this->teamId;
    }

    public function setTeamId(string $teamId): self
    {
        $this->teamId = $teamId;

        return $this;
    }

    public function getTagId(): string
    {
        return $this->tagId;
    }

    public function setTagId(string $tagId): self
    {
        $this->tagId = $tagId;

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

    private function newUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
