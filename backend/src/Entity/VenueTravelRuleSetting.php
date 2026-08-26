<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\TeamLinkIntensity;
use App\Repository\VenueTravelRuleSettingRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Le levier d'intensité de la règle implicite « Trajet entre gymnases » (P2-53 RMM-8 PR-4).
 *
 * UN réglage par club+saison — un SINGLETON, pas une collection par clé : la règle de trajet est
 * unique, l'intensité est son seul cran (PREFERRED = préférence souple / MANDATORY = obligatoire).
 * Le vocabulaire est celui des passerelles ({@see TeamLinkIntensity}), déjà émis par
 * `ScheduleConstraintBuilder`, PAS `ImplicitRuleIntensity` (HARD/PREFERRED/OFF) — les 5 règles
 * bien-être et cette règle-ci ne parlent pas la même langue, d'où un store DÉDIÉ minimal plutôt
 * qu'une 6ᵉ clé forcée dans `implicit_rule_setting` (dont la colonne `intensity` est typée
 * `enumType: ImplicitRuleIntensity`, incapable de porter MANDATORY sans altérer les 5 autres).
 *
 * ⚠ Portée : club+saison SEULEMENT (patron de la matrice `venue_travel_time`, elle aussi
 * club+saison, jamais copiée au plan — ADR-0002). ABSENCE DE LIGNE = DÉFAUT (PREFERRED) : rien
 * n'est semé, une ligne n'existe que quand le gestionnaire a choisi Obligatoire (ou est repassé à
 * Préféré après coup). Le payload d'un club qui n'a rien réglé est byte-identique à avant.
 * Recopiée à la bascule de saison (`SeasonTransitionService`), comme la matrice qu'elle gouverne.
 */
#[ORM\Entity(repositoryClass: VenueTravelRuleSettingRepository::class)]
#[ORM\Table(name: 'venue_travel_rule_setting')]
#[ORM\UniqueConstraint(name: 'uniq_venue_travel_rule_club_season', columns: ['club_id', 'season_id'])]
#[ORM\Index(name: 'idx_venue_travel_rule_club_season', columns: ['club_id', 'season_id'])]
#[ORM\HasLifecycleCallbacks]
class VenueTravelRuleSetting implements TenantOwnedInterface
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
    private string $seasonId;

    /**
     * PREFERRED (défaut) : le solveur PRÉFÈRE des enchaînements de gymnases proches, sans jamais
     * s'imposer. MANDATORY : il DOIT les honorer (contrainte dure — peut rendre le planning
     * infaisable). Défaut PREFERRED reproduit le comportement d'avant PR-4 (émission en dur).
     */
    #[ORM\Column(name: 'intensity', length: 20, enumType: TeamLinkIntensity::class, options: ['default' => 'PREFERRED'])]
    private TeamLinkIntensity $intensity = TeamLinkIntensity::PREFERRED;

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

    public function getIntensity(): TeamLinkIntensity
    {
        return $this->intensity;
    }

    public function setIntensity(TeamLinkIntensity $intensity): self
    {
        $this->intensity = $intensity;

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
