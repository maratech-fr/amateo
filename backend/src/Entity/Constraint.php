<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ConstraintFamily;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use App\Repository\ConstraintRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ConstraintRepository::class)]
#[ORM\Table(name: '`constraint`')]
#[ORM\Index(name: 'idx_constraint_club_season', columns: ['club_id', 'season_id'])]
#[ORM\Index(name: 'idx_constraint_scope_family', columns: ['scope', 'family'])]
#[ORM\Index(name: 'idx_constraint_rule_type', columns: ['rule_type'])]
#[ORM\Index(name: 'idx_constraint_calendar_entry', columns: ['calendar_entry_id'])]
#[ORM\HasLifecycleCallbacks]
class Constraint implements TenantOwnedInterface
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

    #[ORM\Column(type: 'string', length: 180)]
    private string $name;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 20, enumType: ConstraintScope::class)]
    private ConstraintScope $scope;

    #[ORM\Column(type: 'guid', nullable: true)]
    private ?string $scopeTargetId = null;

    #[ORM\Column(length: 20, enumType: ConstraintFamily::class)]
    private ConstraintFamily $family;

    #[ORM\Column(length: 20, enumType: ConstraintRuleType::class)]
    private ConstraintRuleType $ruleType;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $config = [];

    #[ORM\Column(type: 'string', length: 80, nullable: true)]
    private ?string $createdBy = null;

    #[ORM\Column(type: 'string', length: 80, nullable: true)]
    private ?string $source = null;

    #[ORM\Column(type: 'string', length: 180, nullable: true)]
    private ?string $sourceOccurrenceId = null;

    /**
     * When set, this constraint is DATED — it belongs to a CalendarEntry (period)
     * and is EXCLUDED from base-plan generation (see
     * ConstraintRepository::findPermanentByClubSeason). null = permanent constraint.
     */
    #[ORM\Column(type: 'guid', nullable: true)]
    private ?string $calendarEntryId = null;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(type: 'integer')]
    private int $sortOrder = 0;

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

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;

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

    public function getFamily(): ConstraintFamily
    {
        return $this->family;
    }

    public function setFamily(ConstraintFamily $family): self
    {
        $this->family = $family;

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

    /** @return array<string, mixed> */
    public function getConfig(): array
    {
        return $this->config;
    }

    /** @param array<string, mixed> $config */
    public function setConfig(array $config): self
    {
        $this->config = $config;

        return $this;
    }

    public function getCreatedBy(): ?string
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?string $createdBy): self
    {
        $this->createdBy = $createdBy;

        return $this;
    }

    public function getSource(): ?string
    {
        return $this->source;
    }

    public function setSource(?string $source): self
    {
        $this->source = $source;

        return $this;
    }

    public function getSourceOccurrenceId(): ?string
    {
        return $this->sourceOccurrenceId;
    }

    public function setSourceOccurrenceId(?string $sourceOccurrenceId): self
    {
        $this->sourceOccurrenceId = $sourceOccurrenceId;

        return $this;
    }

    public function getCalendarEntryId(): ?string
    {
        return $this->calendarEntryId;
    }

    public function setCalendarEntryId(?string $calendarEntryId): self
    {
        $this->calendarEntryId = $calendarEntryId;

        return $this;
    }

    public function getIsActive(): bool
    {
        return $this->isActive;
    }

    public function isIsActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): self
    {
        $this->isActive = $isActive;

        return $this;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): self
    {
        $this->sortOrder = $sortOrder;

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
