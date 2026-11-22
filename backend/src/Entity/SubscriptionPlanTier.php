<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SubscriptionPlanTierRepository;
use App\Structure\Family;
use App\Structure\Level;
use App\Structure\OnDelete;
use App\Structure\RefersTo;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un palier d'une offre payante : son plafond d'équipes (0 = illimité) et la place de son prix.
 * L'offre complète en porte trois (`palier-1` 20, `palier-2` 34, `palier-3` illimité) ; Découverte
 * et la Bêta n'en ont pas. Le plafond d'équipes d'une saison est celui du palier de sa ligne
 * d'abonnement ({@see ClubSubscription}).
 *
 * Le prix (`priceCents`, un seul prix courant par palier) n'est jamais servi par l'API ; aucun
 * écran ni commande ne l'écrit : il se pose à la main en base.
 */
#[Family(Level::PLATFORM)]
#[ORM\Entity(repositoryClass: SubscriptionPlanTierRepository::class)]
#[ORM\Table(name: 'subscription_plan_tier')]
#[ORM\Index(name: 'idx_subscription_plan_tier_plan', columns: ['plan_id'])]
#[ORM\HasLifecycleCallbacks]
class SubscriptionPlanTier
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private DateTimeImmutable $updatedAt;

    #[ORM\Column(type: 'guid')]
    #[RefersTo(SubscriptionPlan::class, OnDelete::KEEP, 'référentiel de plateforme : une offre qui porte des paliers ne se supprime pas (clé étrangère restreinte)')]
    private string $planId;

    // Clé stable (jamais affichée) : `palier-1`, `palier-2`, `palier-3`.
    #[ORM\Column(type: 'string', length: 60, unique: true)]
    private string $code;

    /** Rang du palier dans son offre, croissant avec le plafond. */
    #[ORM\Column(type: 'integer')]
    private int $position;

    /** Plafond d'équipes de la saison ; 0 = illimité. */
    #[ORM\Column(type: 'integer')]
    private int $maxTeams;

    /** Prix courant du palier, en centimes ; null = non posé. Jamais servi par l'API. */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $priceCents = null;

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

    public function getPlanId(): string
    {
        return $this->planId;
    }

    public function setPlanId(string $planId): self
    {
        $this->planId = $planId;

        return $this;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): self
    {
        $this->code = $code;

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

    public function getMaxTeams(): int
    {
        return $this->maxTeams;
    }

    public function setMaxTeams(int $maxTeams): self
    {
        $this->maxTeams = $maxTeams;

        return $this;
    }

    public function getPriceCents(): ?int
    {
        return $this->priceCents;
    }

    private function newUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
