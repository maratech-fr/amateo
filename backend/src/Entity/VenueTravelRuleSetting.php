<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\VenueTravelRuleIntensity;
use App\Repository\VenueTravelRuleSettingRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Le levier d'intensité de la règle implicite « Trajet entre gymnases » (P2-53 RMM-8 PR-4).
 *
 * UN réglage par club+saison — un SINGLETON, pas une collection par clé : la règle de trajet est
 * unique. Trois réglages : le cran {@see VenueTravelRuleIntensity} (OFF = inactive / PREFERRED =
 * préférence souple / MANDATORY = obligatoire), le `toleranceMinutes` (battement toléré, retranché
 * du barème pour l'écart exigé) et le `defaultMinutes` (barème d'un couple sans temps). Le
 * vocabulaire d'intensité est DÉDIÉ, PAS `ImplicitRuleIntensity` (HARD/PREFERRED/OFF) ni
 * `TeamLinkIntensity` (passerelles, sans OFF) : d'où un store minimal plutôt qu'une 6ᵉ clé forcée
 * dans `implicit_rule_setting`.
 *
 * ⚠ Portée : club+saison SEULEMENT (patron de la matrice `venue_travel_time`, elle aussi
 * club+saison, jamais copiée au plan — ADR-0002). ABSENCE DE LIGNE = DÉFAUTS (PREFERRED,
 * tolérance 20, défaut 20) : rien n'est semé, une ligne n'existe que quand le gestionnaire a réglé
 * quelque chose. Le payload d'un club qui n'a rien réglé applique les défauts sans ligne.
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
     * OFF : la règle n'est pas émise (planning comme un club sans matrice, matrice conservée).
     * PREFERRED (défaut) : le solveur PRÉFÈRE des enchaînements de gymnases proches, sans s'imposer.
     * MANDATORY : il DOIT les honorer (contrainte dure — peut rendre le planning infaisable).
     */
    #[ORM\Column(name: 'intensity', length: 20, enumType: VenueTravelRuleIntensity::class, options: ['default' => 'PREFERRED'])]
    private VenueTravelRuleIntensity $intensity = VenueTravelRuleIntensity::PREFERRED;

    /**
     * Battement toléré (minutes) : le club accepte qu'on parte un peu avant la fin ou qu'on démarre
     * un peu après l'heure ; ce temps est RETRANCHÉ du barème pour l'écart exigé
     * (`max(0, barème − tolérance)`). Défaut 20 pour tous les clubs (décision fondateur 2026-09-30).
     */
    #[ORM\Column(name: 'tolerance_minutes', type: 'integer', options: ['default' => 20])]
    private int $toleranceMinutes = 20;

    /**
     * Barème appliqué à un couple de gymnases sans temps saisi. Défaut 20 (auparavant en dur dans
     * `ScheduleConstraintBuilder`).
     */
    #[ORM\Column(name: 'default_minutes', type: 'integer', options: ['default' => 20])]
    private int $defaultMinutes = 20;

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

    public function getIntensity(): VenueTravelRuleIntensity
    {
        return $this->intensity;
    }

    public function setIntensity(VenueTravelRuleIntensity $intensity): self
    {
        $this->intensity = $intensity;

        return $this;
    }

    public function getToleranceMinutes(): int
    {
        return $this->toleranceMinutes;
    }

    public function setToleranceMinutes(int $toleranceMinutes): self
    {
        $this->toleranceMinutes = $toleranceMinutes;

        return $this;
    }

    public function getDefaultMinutes(): int
    {
        return $this->defaultMinutes;
    }

    public function setDefaultMinutes(int $defaultMinutes): self
    {
        $this->defaultMinutes = $defaultMinutes;

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
