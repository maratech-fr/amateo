<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\SubscriptionOrigin;
use App\Repository\ClubSubscriptionRepository;
use App\Service\ClubSubscriptionWriter;
use App\Service\PlanEntitlements;
use App\Structure\Family;
use App\Structure\Level;
use App\Structure\OnDelete;
use App\Structure\RefersTo;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * L'abonnement d'un club pour UNE saison : l'offre, réglée ou non, la date du règlement et l'origine
 * de la ligne. Une ligne par (club, année de saison) — l'année est celle du pivot du 15 juillet
 * (2026 = saison 2026-2027). Aucune ligne pour une saison = Découverte ({@see PlanEntitlements}).
 *
 * Lecture seule pour l'application : la base retire `INSERT` et `UPDATE` au rôle applicatif, seule
 * la connexion propriétaire écrit une ligne ({@see ClubSubscriptionWriter}). Le rôle applicatif la
 * lit (sous le locataire, RLS) et la supprime à l'effacement du club. La ligne garde le dernier état
 * seulement : la suite des gestes se lit dans l'historique des actions de la console.
 */
#[Family(Level::CLUB)]
#[ORM\Entity(repositoryClass: ClubSubscriptionRepository::class, readOnly: true)]
#[ORM\Table(name: 'club_subscription')]
#[ORM\UniqueConstraint(name: 'uniq_club_subscription_season', columns: ['club_id', 'season_year'])]
#[ORM\Index(name: 'idx_club_subscription_plan', columns: ['plan_id'])]
class ClubSubscription implements TenantOwnedInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(type: 'guid')]
    private string $clubId;

    #[ORM\Column(type: 'integer')]
    private int $seasonYear;

    #[ORM\Column(type: 'guid')]
    #[RefersTo(SubscriptionPlan::class, OnDelete::KEEP, 'référentiel de plateforme : une offre portée par une ligne ne se supprime pas (clé étrangère restreinte)')]
    private string $planId;

    /** Null = non réglée (une ligne Découverte ne se règle pas). */
    #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
    private ?DateTimeImmutable $paidAt = null;

    #[ORM\Column(length: 20, enumType: SubscriptionOrigin::class)]
    private SubscriptionOrigin $origin;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetimetz_immutable')]
    private DateTimeImmutable $updatedAt;

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

    public function getSeasonYear(): int
    {
        return $this->seasonYear;
    }

    public function getPlanId(): string
    {
        return $this->planId;
    }

    public function getPaidAt(): ?DateTimeImmutable
    {
        return $this->paidAt;
    }

    public function isPaid(): bool
    {
        return $this->paidAt instanceof DateTimeImmutable;
    }

    public function getOrigin(): SubscriptionOrigin
    {
        return $this->origin;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
