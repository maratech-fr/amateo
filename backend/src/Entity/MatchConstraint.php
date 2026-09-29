<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use App\Repository\MatchConstraintRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * P4-272 ③ — une RÈGLE DE MATCH propre au club (« écran unique des contraintes de
 * match », section Club). Tenant + season owned, renouvelée avec la saison.
 *
 * Patron de {@see Constraint} SANS sa table : un `scope` (CLUB | TEAM | COACH — seul
 * CLUB est saisi ici ; ④/⑤ réutiliseront TEAM/COACH), un `scopeTargetId` nullable (la
 * cible quand le scope l'exige), un `ruleType` HARD | PREFERRED (jamais LOCK ici), des
 * `daysOfWeek` (ISO 1=lundi..7=dimanche, PLUSIEURS jours par règle) et une fourchette
 * de coup d'envoi `kickoffMin`/`kickoffMax` (HH:MM, chacune nullable — « pas après
 * 21h » = max seul). `venueId` nullable est réservé à ④.
 *
 * Une règle HARD est HONORÉE par le solveur (le coup d'envoi doit tomber dans la
 * fourchette les jours couverts) ; une règle PREFERRED est une PÉNALITÉ (le solveur
 * l'évite si possible, ne la subit jamais comme un blocage). Les amicaux en sont
 * exemptés (structurel : un amical n'est jamais confié au solveur). La pose MANUELLE
 * hors d'une règle HARD reste PERMISE — le radar la SIGNALE (CLUB_RULE_VIOLATION), il
 * ne la bloque pas.
 *
 * ⚠ AUCUNE unicité en base : plusieurs lignes par cible/jour sont légitimes (⑤ en
 * aura besoin), et deux règles club peuvent se recouvrir sans être un doublon.
 */
#[ORM\Entity(repositoryClass: MatchConstraintRepository::class)]
#[ORM\Table(name: 'match_constraint')]
#[ORM\Index(name: 'idx_match_constraint_club_season', columns: ['club_id', 'season_id'])]
#[ORM\Index(name: 'idx_match_constraint_scope', columns: ['scope', 'scope_target_id'])]
#[ORM\HasLifecycleCallbacks]
class MatchConstraint implements TenantOwnedInterface
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

    #[ORM\Column(length: 20, enumType: ConstraintScope::class)]
    private ConstraintScope $scope = ConstraintScope::CLUB;

    #[ORM\Column(type: 'guid', nullable: true)]
    private ?string $scopeTargetId = null;

    #[ORM\Column(length: 20, enumType: ConstraintRuleType::class)]
    private ConstraintRuleType $ruleType = ConstraintRuleType::HARD;

    /**
     * Jours ISO couverts par la règle (1=lundi..7=dimanche), plusieurs par règle.
     *
     * @var list<int>
     */
    #[ORM\Column(type: 'json')]
    private array $daysOfWeek = [];

    #[ORM\Column(name: 'kickoff_min', type: 'time_immutable', nullable: true)]
    private ?DateTimeImmutable $kickoffMin = null;

    #[ORM\Column(name: 'kickoff_max', type: 'time_immutable', nullable: true)]
    private ?DateTimeImmutable $kickoffMax = null;

    #[ORM\Column(type: 'guid', nullable: true)]
    private ?string $venueId = null;

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

    #[ORM\PreUpdate]
    public function touchUpdatedAt(): void
    {
        $this->updatedAt = new DateTimeImmutable;
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

    public function getSeasonId(): string
    {
        return $this->seasonId;
    }

    public function setSeasonId(string $seasonId): self
    {
        $this->seasonId = $seasonId;

        return $this;
    }

    public function getScope(): ConstraintScope
    {
        return $this->scope;
    }

    public function setScope(ConstraintScope $scope): self
    {
        $this->scope = $scope;

        return $this;
    }

    public function getScopeTargetId(): ?string
    {
        return $this->scopeTargetId;
    }

    public function setScopeTargetId(?string $scopeTargetId): self
    {
        $this->scopeTargetId = $scopeTargetId;

        return $this;
    }

    public function getRuleType(): ConstraintRuleType
    {
        return $this->ruleType;
    }

    public function setRuleType(ConstraintRuleType $ruleType): self
    {
        $this->ruleType = $ruleType;

        return $this;
    }

    /**
     * @return list<int>
     */
    public function getDaysOfWeek(): array
    {
        return $this->daysOfWeek;
    }

    /**
     * @param list<int> $daysOfWeek
     */
    public function setDaysOfWeek(array $daysOfWeek): self
    {
        $this->daysOfWeek = $daysOfWeek;

        return $this;
    }

    public function getKickoffMin(): ?DateTimeImmutable
    {
        return $this->kickoffMin;
    }

    public function setKickoffMin(?DateTimeImmutable $kickoffMin): self
    {
        $this->kickoffMin = $kickoffMin;

        return $this;
    }

    public function getKickoffMax(): ?DateTimeImmutable
    {
        return $this->kickoffMax;
    }

    public function setKickoffMax(?DateTimeImmutable $kickoffMax): self
    {
        $this->kickoffMax = $kickoffMax;

        return $this;
    }

    public function getVenueId(): ?string
    {
        return $this->venueId;
    }

    public function setVenueId(?string $venueId): self
    {
        $this->venueId = $venueId;

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
