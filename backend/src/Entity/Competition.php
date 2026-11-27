<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\CompetitionSource;
use App\Enum\CompetitionType;
use App\Matches\CompetitionRegistry;
use App\Repository\CompetitionRepository;
use App\Structure\Family;
use App\Structure\Level;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * A competition/phase of the season (FFBB championship / cup / brassage). The teams that play it
 * are its engagements ({@see Engagement}, read and written by {@see CompetitionRegistry} only) —
 * one per competition today, except friendlies later (Q8). No uniqueness on the name: two
 * competitions of the same name are legitimate (two club teams in one division); « no two
 * competitions of the same name for one team » is held by the registry (normalized compare).
 * Season-scoped, tenant-owned. Every match has a competition: a friendly lives in a FRIENDLY one —
 * its federal one (« AMICAL PNM ») or the season's default « Amicaux » ({@see $defaultFriendly},
 * one per club and season, several engagements, never pruned).
 */
#[Family(Level::SEASON, cold: [], copiedToNextSeason: false)]
#[ORM\Entity(repositoryClass: CompetitionRepository::class)]
#[ORM\Table(name: 'competition')]
#[ORM\Index(name: 'idx_competition_club_season', columns: ['club_id', 'season_id'])]
// Cible de la clé composée (competition_id, club_id) de l'engagement : un engagement ne désigne
// jamais la compétition d'un autre club (une clé simple ignorerait la RLS).
#[ORM\UniqueConstraint(name: 'uniq_competition_id_club', columns: ['id', 'club_id'])]
// Une seule compétition d'office « Amicaux » par club et saison.
#[ORM\UniqueConstraint(name: 'uniq_competition_default_friendly', columns: ['club_id', 'season_id'], options: ['where' => '(default_friendly = true)'])]
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

    #[ORM\Column(type: 'string', length: 180)]
    private string $name;

    #[ORM\Column(length: 20, enumType: CompetitionType::class)]
    private CompetitionType $competitionType;

    /** FBI : ses matchs sont suivis dans FBI ; MANUAL : ils s'arrêtent à « Placé » ({@see CompetitionSource}). */
    #[ORM\Column(length: 10, enumType: CompetitionSource::class)]
    private CompetitionSource $source = CompetitionSource::MANUAL;

    /** La compétition d'office « Amicaux » de la saison, écrite par `FriendlyCompetitions` seulement. */
    #[ORM\Column(name: 'default_friendly', type: 'boolean', options: ['default' => false])]
    private bool $defaultFriendly = false;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?DateTimeImmutable $startDate = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?DateTimeImmutable $endDate = null;

    // ── FFBB pairing refs (P1-4 PR F, appariement §3) — written by the pairing
    // confirm endpoint (never by the CRUD), re-paired at each phase. Since P4-194
    // the FFBB-API reconciler ALSO sets ffbbCompetitionId (when it resolves-or-
    // creates the CUP of an imported rencontre). The poule, its opponents and the
    // expected matchdays live on the engagement. ──

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $ffbbCompetitionId = null;

    /** Canonical FFBB competition name (« Pré régionale masculine ») — the pre-fill key across phases. */
    #[ORM\Column(length: 180, nullable: true)]
    private ?string $ffbbCompetitionName = null;

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

    public function getSource(): CompetitionSource
    {
        return $this->source;
    }

    public function setSource(CompetitionSource $source): self
    {
        $this->source = $source;

        return $this;
    }

    /**
     * La fédération range des matchs dans cette compétition : elle devient `FBI`. Jamais « Amicaux », la compétition
     * d'office, qui reste `MANUAL` ; jamais de `FBI` vers `MANUAL`.
     */
    public function markFromFederation(): self
    {
        if (!$this->defaultFriendly) {
            $this->source = CompetitionSource::FBI;
        }

        return $this;
    }

    public function isDefaultFriendly(): bool
    {
        return $this->defaultFriendly;
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

    public function getFfbbCompetitionName(): ?string
    {
        return $this->ffbbCompetitionName;
    }

    public function setFfbbCompetitionName(?string $ffbbCompetitionName): self
    {
        $this->ffbbCompetitionName = $ffbbCompetitionName;

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
