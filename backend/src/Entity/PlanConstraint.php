<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\SchedulePlanType;
use App\Plans\PlanComposition;
use App\Structure\Family;
use App\Structure\Level;
use App\Structure\OnDelete;
use App\Structure\RefersTo;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Les règles appliquées par un plan de période (lot 4, PR 4) : une ligne par (plan de période, contrainte permanente de
 * sa saison), aucune au plan de saison ni pour une contrainte datée (elle appartient à sa période). `applies` dit si la
 * règle de saison part au moteur avec la demande de cette période.
 *
 * Le défaut se lit sur la nature du plan et la portée de la règle ({@see SchedulePlanType::keepsSocleConstraintByDefault}) ;
 * une ligne égale au défaut n'est pas un réglage. Les lignes ne se posent que par {@see PlanComposition::ensureLines} ;
 * changer la portée d'une règle réécrit celles restées au défaut ({@see PlanComposition::onScopeChanged}). Pas de clé
 * vers la contrainte : « Charger » efface puis réinsère les contraintes permanentes.
 */
#[Family(Level::PLAN, copiedAtPlanBirth: false, cold: Family::ALL, copiedToNextSeason: false)]
#[ORM\Entity]
#[ORM\Table(name: 'plan_constraint')]
#[ORM\UniqueConstraint(name: 'uniq_plan_constraint', columns: ['schedule_plan_id', 'constraint_id'])]
#[ORM\Index(name: 'idx_plan_constraint_season', columns: ['season_id'])]
#[ORM\Index(name: 'idx_plan_constraint_constraint', columns: ['constraint_id'])]
class PlanConstraint implements TenantOwnedInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(type: 'integer')]
    private int $version = 1;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private DateTimeImmutable $updatedAt;

    #[ORM\Column(type: 'guid')]
    private string $clubId;

    #[ORM\Column(type: 'guid')]
    private string $seasonId;

    #[ORM\Column(type: 'guid')]
    #[RefersTo(SchedulePlan::class, OnDelete::DELETE)]
    private string $schedulePlanId;

    #[ORM\Column(type: 'guid')]
    #[RefersTo(Constraint::class, OnDelete::DELETE)]
    private string $constraintId;

    /** Faux = la règle de saison ne s'applique pas pendant cette période. */
    #[ORM\Column(type: 'boolean')]
    private bool $applies = true;

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

    public function getClubId(): ?string
    {
        return $this->clubId;
    }

    public function setClubId(string $clubId): static
    {
        $this->clubId = $clubId;

        return $this;
    }

    public function getSeasonId(): string
    {
        return $this->seasonId;
    }

    public function setSeasonId(string $seasonId): static
    {
        $this->seasonId = $seasonId;

        return $this;
    }

    public function getSchedulePlanId(): string
    {
        return $this->schedulePlanId;
    }

    public function setSchedulePlanId(string $schedulePlanId): static
    {
        $this->schedulePlanId = $schedulePlanId;

        return $this;
    }

    public function getConstraintId(): string
    {
        return $this->constraintId;
    }

    public function setConstraintId(string $constraintId): static
    {
        $this->constraintId = $constraintId;

        return $this;
    }

    public function applies(): bool
    {
        return $this->applies;
    }

    public function setApplies(bool $applies): static
    {
        $this->applies = $applies;

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
