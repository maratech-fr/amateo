<?php

declare(strict_types=1);

namespace App\Entity;

use App\EventListener\ResourceChangeStaleScheduleListener;
use Doctrine\ORM\Mapping as ORM;

/**
 * P2-51 — un membre d'un {@see SharedTrainingBlock}. Le lien bloc → équipe, avec
 * club/saison/plan DÉNORMALISÉS : le listener de péremption
 * ({@see ResourceChangeStaleScheduleListener}) et RLS lisent la colonne,
 * jamais une jointure. Écrit une fois, jamais mis à jour (une modification de composition =
 * suppression + recréation des lignes — patron {@see SharedTrainingGroupTeam}).
 *
 * ⚠ Multi-appartenance PERMISE (décision 2026-08-31) : une équipe peut figurer dans PLUSIEURS
 * blocs — l'unicité porte sur le couple ``(block_id, team_id)`` (pas de doublon DANS un bloc),
 * JAMAIS sur ``team_id`` seul (contrairement à la contrainte historique du modèle groupe).
 */
#[ORM\Entity]
#[ORM\Table(name: 'shared_training_block_team')]
#[ORM\UniqueConstraint(name: 'uniq_shared_training_block_team', columns: ['block_id', 'team_id'])]
#[ORM\Index(name: 'idx_shared_training_block_team_block', columns: ['block_id'])]
#[ORM\Index(name: 'idx_shared_training_block_team_club_season', columns: ['club_id', 'season_id'])]
class SharedTrainingBlockTeam implements TenantOwnedInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(type: 'guid')]
    private string $clubId;

    #[ORM\Column(type: 'guid')]
    private string $seasonId;

    /** Dénormalisé du bloc : NULL = socle saison, non-null = plan de période (ADR-0002). */
    #[ORM\Column(type: 'guid', nullable: true)]
    private ?string $schedulePlanId = null;

    #[ORM\Column(type: 'guid')]
    private string $blockId;

    #[ORM\Column(type: 'guid')]
    private string $teamId;

    public function __construct()
    {
        $this->id = $this->newUuid();
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

    public function getSeasonId(): string
    {
        return $this->seasonId;
    }

    public function setSeasonId(string $seasonId): self
    {
        $this->seasonId = $seasonId;

        return $this;
    }

    public function getSchedulePlanId(): ?string
    {
        return $this->schedulePlanId;
    }

    public function setSchedulePlanId(?string $schedulePlanId): self
    {
        $this->schedulePlanId = $schedulePlanId;

        return $this;
    }

    public function getBlockId(): string
    {
        return $this->blockId;
    }

    public function setBlockId(string $blockId): self
    {
        $this->blockId = $blockId;

        return $this;
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

    private function newUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
