<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ScheduleDiagnosticSeverity;
use App\Repository\ScheduleDiagnosticRepository;
use App\Structure\Family;
use App\Structure\Level;
use App\Structure\OnDelete;
use App\Structure\RefersTo;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[Family(Level::VERSION)]
#[ORM\Entity(repositoryClass: ScheduleDiagnosticRepository::class)]
#[ORM\Table(name: 'schedule_diagnostic')]
#[ORM\Index(name: 'idx_schedule_diagnostic_club_season', columns: ['club_id', 'season_id'])]
#[ORM\Index(name: 'idx_schedule_diagnostic_schedule', columns: ['schedule_id'])]
#[ORM\Index(name: 'idx_schedule_diagnostic_team', columns: ['team_id'])]
#[ORM\Index(name: 'idx_schedule_diagnostic_coach', columns: ['coach_id'])]
#[ORM\Index(name: 'idx_schedule_diagnostic_venue', columns: ['venue_id'])]
#[ORM\HasLifecycleCallbacks]
class ScheduleDiagnostic implements TenantOwnedInterface
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

    #[ORM\Column(type: 'guid')]
    #[RefersTo(Schedule::class, OnDelete::DELETE)]
    private string $scheduleId;

    #[ORM\Column(type: 'string', length: 50)]
    private string $type;

    #[ORM\Column(length: 20, enumType: ScheduleDiagnosticSeverity::class)]
    private ScheduleDiagnosticSeverity $severity;

    #[ORM\Column(type: 'guid', nullable: true)]
    #[RefersTo(Team::class, OnDelete::DELETE)]
    private ?string $teamId = null;

    #[ORM\Column(type: 'guid', nullable: true)]
    #[RefersTo(Coach::class, OnDelete::DELETE)]
    private ?string $coachId = null;

    #[ORM\Column(type: 'guid', nullable: true)]
    #[RefersTo(Venue::class, OnDelete::DELETE)]
    private ?string $venueId = null;

    // Only a `conflict` (of 11 diagnostic types) pinpoints a slot: it carries the
    // day + start time the offending session sits at, so the UI can open THAT slot
    // on the grid. Nullable because every other type leaves them absent.
    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $dayOfWeek = null;

    #[ORM\Column(type: 'string', length: 5, nullable: true)]
    private ?string $startTime = null;

    // `implicit_rule_not_honored` (of the diagnostic types) carries the wellness rule it
    // concerns — the contract ruleKey (coachRestDay/…), so the UI can name it. Nullable
    // because every other type leaves it absent.
    #[ORM\Column(type: 'string', length: 40, nullable: true)]
    private ?string $ruleKey = null;

    #[ORM\Column(type: 'text')]
    private string $message;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $suggestions = [];

    // `session_below_effective_min` (of the diagnostic types) carries the MEASURED
    // causes of a missing session: one `{kind, constraintId, label, count}` row per
    // rule that closed candidate slots. Non-nullable, default [] — every other type
    // leaves it empty, existing rows backfill to []. The `constraintId` here is
    // NORMALISED to the entity UUID (the recorder strips the `:teamId` suffix the
    // builder adds when it expands a CLUB constraint into N TEAM rows), so the wizard
    // deep-link `?edit=<id>` resolves.
    /** @var list<array<string, mixed>> */
    #[ORM\Column(type: 'json')]
    private array $causes = [];

    // `session_below_effective_min` also carries how many candidate slots stayed OPEN
    // (nothing closed them, the solver simply placed something else). Nullable on
    // purpose: 0 means "no slot stayed open", null means "not measured" — the
    // distinction is product signal, never collapse it.
    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $openCandidates = null;

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

    public function getScheduleId(): string
    {
        return $this->scheduleId;
    }

    public function setScheduleId(string $scheduleId): self
    {
        $this->scheduleId = $scheduleId;

        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getSeverity(): ScheduleDiagnosticSeverity
    {
        return $this->severity;
    }

    public function setSeverity(ScheduleDiagnosticSeverity $severity): self
    {
        $this->severity = $severity;

        return $this;
    }

    public function getTeamId(): ?string
    {
        return $this->teamId;
    }

    public function setTeamId(?string $teamId): self
    {
        $this->teamId = $teamId;

        return $this;
    }

    public function getCoachId(): ?string
    {
        return $this->coachId;
    }

    public function setCoachId(?string $coachId): self
    {
        $this->coachId = $coachId;

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

    public function getDayOfWeek(): ?int
    {
        return $this->dayOfWeek;
    }

    public function setDayOfWeek(?int $dayOfWeek): self
    {
        $this->dayOfWeek = $dayOfWeek;

        return $this;
    }

    public function getStartTime(): ?string
    {
        return $this->startTime;
    }

    public function setStartTime(?string $startTime): self
    {
        $this->startTime = $startTime;

        return $this;
    }

    public function getRuleKey(): ?string
    {
        return $this->ruleKey;
    }

    public function setRuleKey(?string $ruleKey): self
    {
        $this->ruleKey = $ruleKey;

        return $this;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function setMessage(string $message): self
    {
        $this->message = $message;

        return $this;
    }

    /** @return array<string, mixed> */
    public function getSuggestions(): array
    {
        return $this->suggestions;
    }

    /** @param array<string, mixed> $suggestions */
    public function setSuggestions(array $suggestions): self
    {
        $this->suggestions = $suggestions;

        return $this;
    }

    /** @return list<array<string, mixed>> */
    public function getCauses(): array
    {
        return $this->causes;
    }

    /** @param list<array<string, mixed>> $causes */
    public function setCauses(array $causes): self
    {
        $this->causes = $causes;

        return $this;
    }

    public function getOpenCandidates(): ?int
    {
        return $this->openCandidates;
    }

    public function setOpenCandidates(?int $openCandidates): self
    {
        $this->openCandidates = $openCandidates;

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
