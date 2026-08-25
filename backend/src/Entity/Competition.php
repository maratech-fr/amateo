<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\CompetitionType;
use App\Repository\CompetitionRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * A team's competition/phase (FFBB championship / cup / brassage). N per team —
 * a team may run several with distinct windows (spec gestion-matchs §9).
 * Season-scoped, tenant-owned. Friendly matches carry no competition (a Fixture
 * with a null competitionId).
 */
#[ORM\Entity(repositoryClass: CompetitionRepository::class)]
#[ORM\Table(name: 'competition')]
#[ORM\Index(name: 'idx_competition_club_season', columns: ['club_id', 'season_id'])]
#[ORM\Index(name: 'idx_competition_team', columns: ['team_id'])]
// P4-67 — deux « DF2 » pour la MÊME équipe rendaient la résolution d'import
// arbitraire (candidates[0]). Deux équipes en DF2 restent légitimes : la clé
// porte le teamId. (L'unicité de la réf FFBB est un index PARTIEL — WHERE NOT
// NULL — posé en migration : Doctrine ne sait pas le déclarer en attribut.)
#[ORM\UniqueConstraint(name: 'uniq_competition_team_name', columns: ['club_id', 'season_id', 'team_id', 'name'])]
#[ORM\HasLifecycleCallbacks]
class Competition implements TenantOwnedInterface
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
    private string $teamId;

    #[ORM\Column(type: 'string', length: 180)]
    private string $name;

    #[ORM\Column(length: 20, enumType: CompetitionType::class)]
    private CompetitionType $competitionType;

    /**
     * The club-side team label as the FBI export writes it (e.g. « B CHARPENNES
     * CROIX LUIZET - 2 ») — the disambiguator when TWO club teams play in the
     * same division (cadrage P1-4, décision fondateur 2026-08-02 : le suffixe
     * appareille la 2ᵉ équipe). Null when the division alone identifies the team.
     */
    #[ORM\Column(length: 180, nullable: true)]
    private ?string $fbiTeamLabel = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?DateTimeImmutable $startDate = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?DateTimeImmutable $endDate = null;

    // ── FFBB pairing refs (P1-4 PR F, appariement §3) — written ONLY by the
    // pairing confirm endpoint (never by the CRUD), re-paired at each phase. ──

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $ffbbCompetitionId = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $ffbbPouleId = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $ffbbPouleName = null;

    /** Canonical FFBB competition name (« Pré régionale masculine ») — the pre-fill key across phases. */
    #[ORM\Column(length: 180, nullable: true)]
    private ?string $ffbbCompetitionName = null;

    /** 2×(N−1) for a poule of N clubs — frozen at pairing time (never re-fetched at read). */
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $expectedMatchdays = null;

    /**
     * The poule's club names, copied at pairing time — the import poule guard's
     * offline data (tenant-scoped snapshot born from this club's own on-demand
     * consultation; NOT the forbidden global directory).
     *
     * @var list<string>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $ffbbPouleOpponents = null;

    /**
     * The league/committee entry deadline for this competition (RMM-6) — the date
     * by which the club must have entered its home matches in FBI. Set ONLY by the
     * dedicated bulk endpoint (POST /api/competitions/entry-deadlines), never by
     * the CRUD (same out-of-CRUD pattern as the FFBB pairing refs above). Null =
     * no club-set deadline; the read model then falls back to the community
     * default (SharedCompetitionDeadline) when this competition is paired.
     */
    #[ORM\Column(name: 'entry_deadline', type: 'date_immutable', nullable: true)]
    private ?DateTimeImmutable $entryDeadline = null;

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

    public function getTeamId(): string
    {
        return $this->teamId;
    }

    public function setTeamId(string $teamId): self
    {
        $this->teamId = $teamId;

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

    public function getCompetitionType(): CompetitionType
    {
        return $this->competitionType;
    }

    public function setCompetitionType(CompetitionType $competitionType): self
    {
        $this->competitionType = $competitionType;

        return $this;
    }

    public function getFbiTeamLabel(): ?string
    {
        return $this->fbiTeamLabel;
    }

    public function setFbiTeamLabel(?string $fbiTeamLabel): self
    {
        $this->fbiTeamLabel = $fbiTeamLabel;

        return $this;
    }

    public function getStartDate(): ?DateTimeImmutable
    {
        return $this->startDate;
    }

    public function setStartDate(?DateTimeImmutable $startDate): self
    {
        $this->startDate = $startDate;

        return $this;
    }

    public function getEndDate(): ?DateTimeImmutable
    {
        return $this->endDate;
    }

    public function setEndDate(?DateTimeImmutable $endDate): self
    {
        $this->endDate = $endDate;

        return $this;
    }

    public function getFfbbCompetitionId(): ?string
    {
        return $this->ffbbCompetitionId;
    }

    public function setFfbbCompetitionId(?string $ffbbCompetitionId): self
    {
        $this->ffbbCompetitionId = $ffbbCompetitionId;

        return $this;
    }

    public function getFfbbPouleId(): ?string
    {
        return $this->ffbbPouleId;
    }

    public function setFfbbPouleId(?string $ffbbPouleId): self
    {
        $this->ffbbPouleId = $ffbbPouleId;

        return $this;
    }

    public function getFfbbPouleName(): ?string
    {
        return $this->ffbbPouleName;
    }

    public function setFfbbPouleName(?string $ffbbPouleName): self
    {
        $this->ffbbPouleName = $ffbbPouleName;

        return $this;
    }

    public function getFfbbCompetitionName(): ?string
    {
        return $this->ffbbCompetitionName;
    }

    public function setFfbbCompetitionName(?string $ffbbCompetitionName): self
    {
        $this->ffbbCompetitionName = $ffbbCompetitionName;

        return $this;
    }

    public function getExpectedMatchdays(): ?int
    {
        return $this->expectedMatchdays;
    }

    public function setExpectedMatchdays(?int $expectedMatchdays): self
    {
        $this->expectedMatchdays = $expectedMatchdays;

        return $this;
    }

    /** @return list<string>|null */
    public function getFfbbPouleOpponents(): ?array
    {
        return $this->ffbbPouleOpponents;
    }

    /** @param list<string>|null $ffbbPouleOpponents */
    public function setFfbbPouleOpponents(?array $ffbbPouleOpponents): self
    {
        $this->ffbbPouleOpponents = $ffbbPouleOpponents;

        return $this;
    }

    public function getEntryDeadline(): ?DateTimeImmutable
    {
        return $this->entryDeadline;
    }

    public function setEntryDeadline(?DateTimeImmutable $entryDeadline): self
    {
        $this->entryDeadline = $entryDeadline;

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
