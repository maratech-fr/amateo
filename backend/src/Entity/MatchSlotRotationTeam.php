<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * RMM-5 (P2-49) — un membre ORDONNÉ d'une {@see MatchSlotRotation}. Le lien rotation → équipe,
 * avec club/saison DÉNORMALISÉS (patron {@see SharedTrainingGroupTeam}) : RLS et les listeners
 * lisent la colonne, jamais une jointure. Écrit une fois, jamais mis à jour — une modification
 * de composition = suppression + recréation des lignes (même patron).
 *
 * ``position`` = l'ordre d'affichage A/B/C, purement FICTIF (décision fondateur n°4 du §8 :
 * l'image A/B n'a AUCUN ancrage calendaire) : il ne pilote aucun calendrier, il sert seulement
 * à rendre la liste dans un ordre stable et lisible.
 *
 * Unicité ``(rotation_id, team_id)`` — une équipe ne figure qu'une fois dans une rotation.
 */
#[ORM\Entity]
#[ORM\Table(name: 'match_slot_rotation_team')]
#[ORM\UniqueConstraint(name: 'uniq_match_slot_rotation_team', columns: ['rotation_id', 'team_id'])]
#[ORM\Index(name: 'idx_match_slot_rotation_team_rotation', columns: ['rotation_id'])]
#[ORM\Index(name: 'idx_match_slot_rotation_team_club_season', columns: ['club_id', 'season_id'])]
class MatchSlotRotationTeam implements TenantOwnedInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(type: 'guid')]
    private string $clubId;

    #[ORM\Column(type: 'guid')]
    private string $seasonId;

    #[ORM\Column(type: 'guid')]
    private string $rotationId;

    #[ORM\Column(type: 'guid')]
    private string $teamId;

    /** Fictional A/B/C display order — never drives a calendar (founder decision §8). */
    #[ORM\Column(type: 'integer')]
    private int $position = 0;

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

    public function getRotationId(): string
    {
        return $this->rotationId;
    }

    public function setRotationId(string $rotationId): self
    {
        $this->rotationId = $rotationId;

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

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): self
    {
        $this->position = $position;

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
