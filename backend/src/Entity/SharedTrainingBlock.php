<?php

declare(strict_types=1);

namespace App\Entity;

use App\Service\EffectiveTeamSessions;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * P2-51 — le BLOC de mutualisation : un ensemble d'équipes qui se comporte comme UNE équipe à
 * part entière. Il se déclare comme une équipe, avec son propre nombre de séances communes
 * (``commonSessions``) : ses séances lui APPARTIENNENT (le solveur les placera comme celles d'une
 * équipe, PR-3), on ne déduit plus une séance commune de la co-présence — c'était la source du
 * double-comptage du modèle groupe ({@see SharedTrainingGroup}, qui reste INTACT à côté).
 *
 * Modèle arbitré par le fondateur le 2026-08-31 (amende le cadrage du 2026-08-25 — le bloc
 * remplace l'ancrage au créneau). Décisions figées :
 *  - fait de PLAN : ``schedulePlanId`` NULLABLE (NULL = socle saison, non-null = plan de période),
 *    patron exact de {@see SharedTrainingGroup} ; pas de copie socle→période ;
 *  - multi-appartenance PERMISE : une équipe peut être membre de PLUSIEURS blocs (pas d'unicité
 *    un-bloc-par-équipe) — c'est LA capacité qui manquait au modèle groupe ;
 *  - garde centrale (côté processor) : pour chaque équipe, Σ des ``commonSessions`` de ses blocs
 *    de MÊME portée ≤ ses séances/semaine effectives ({@see EffectiveTeamSessions}) ;
 *  - meurt ENTIER : supprimer une équipe membre détruit tous ses blocs (patron du prune group).
 *
 * Détail : specs/evolution/mutualisation-par-creneau.md · specs/evolution/plannings-bccl-2026-08-31.md.
 */
#[ORM\Entity]
#[ORM\Table(name: 'shared_training_block')]
#[ORM\Index(name: 'idx_shared_training_block_club_season', columns: ['club_id', 'season_id'])]
#[ORM\Index(name: 'idx_shared_training_block_plan', columns: ['schedule_plan_id'])]
class SharedTrainingBlock implements TenantOwnedInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(type: 'integer')]
    #[ORM\Version]
    private int $version = 1;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private DateTimeImmutable $updatedAt;

    #[ORM\Column(type: 'guid')]
    private string $clubId;

    #[ORM\Column(type: 'guid')]
    private string $seasonId;

    /** NULL = socle saison (base plan) ; non-null = plan de période (ADR-0002 inv. 5). */
    #[ORM\Column(type: 'guid', nullable: true)]
    private ?string $schedulePlanId = null;

    /** Nombre de séances communes que le bloc POSSÈDE (≥ 1). Le bloc se comporte comme une équipe. */
    #[ORM\Column(type: 'integer')]
    private int $commonSessions = 1;

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

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(): self
    {
        $this->updatedAt = new DateTimeImmutable;

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

    public function getCommonSessions(): int
    {
        return $this->commonSessions;
    }

    public function setCommonSessions(int $commonSessions): self
    {
        $this->commonSessions = $commonSessions;

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
