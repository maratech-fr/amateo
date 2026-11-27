<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\FixtureHomeAway;
use App\Enum\FixturePlacementSource;
use App\Enum\FixtureReviewState;
use App\Enum\FixtureUnplacedReason;
use App\Enum\PlacementStatus;
use App\Repository\FixtureRepository;
use App\Structure\Family;
use App\Structure\Level;
use App\Structure\OnDelete;
use App\Structure\RefersTo;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * A single dated match (« match » is a PHP keyword → Fixture). The manager
 * places HOME fixtures (venue + kickoff) to answer the league; AWAY fixtures
 * are imposed and only counted (their kickoff is estimated). Season-scoped,
 * tenant-owned. Every fixture has a competition; its nature says whether it is a friendly.
 *
 * The opponent is a plain label here — the enriched global opponent directory
 * (coords, travel) is palier B.
 *
 * @phpstan-type PendingDeviation array{field: 'date'|'kickoff'|'venue', appValue: string|null, sourceValue: string|null, channel: 'FBI_XLSX'|'FFBB_API', seenAt: string, autoApplied: bool, appliedAt?: string|null}
 * @phpstan-type FbiEcho array{field: string, value: string, at: string}
 */
#[Family(Level::SEASON, cold: [], copiedToNextSeason: false)]
#[ORM\Entity(repositoryClass: FixtureRepository::class)]
#[ORM\Table(name: 'fixture')]
#[ORM\Index(name: 'idx_fixture_club_season', columns: ['club_id', 'season_id'])]
#[ORM\Index(name: 'idx_fixture_team', columns: ['team_id'])]
#[ORM\Index(name: 'idx_fixture_date', columns: ['match_date'])]
// Partial-unique: FBI import idempotence — one fixture per FBI number per team
// AND per division (D1/M15): au 2ᵉ tour l'équipe change de poule/division et FBI
// réutilise ses numéros ; sans la division dans la clé, le n°X de la phase 2
// écraserait le n°X de la phase 1 (team-scoped : un derby intra-club existe une
// fois par équipe ; un même numéro sous deux divisions coexiste).
#[ORM\UniqueConstraint(name: 'uniq_fixture_external_ref', columns: ['club_id', 'season_id', 'team_id', 'external_division', 'external_ref'], options: ['where' => '(external_ref IS NOT NULL)'])]
// Partial-unique: FFBB-API channel idempotence (RMM-4 PR-3) — one fixture per
// national rencontre id per team (same team-scoping as external_ref: an
// intra-club amical can exist once per team; re-checking never re-proposes a
// rencontre already created, and a concurrent create collides → clean 409).
// Cible de la clé composée (fixture_id, club_id) du suivi fédéral : un suivi ne désigne jamais le match d'un
// autre club (une clé simple ignorerait la RLS).
#[ORM\UniqueConstraint(name: 'uniq_fixture_id_club', columns: ['id', 'club_id'])]
#[ORM\UniqueConstraint(name: 'uniq_fixture_ffbb_rencontre', columns: ['club_id', 'season_id', 'team_id', 'ffbb_rencontre_id'], options: ['where' => '(ffbb_rencontre_id IS NOT NULL)'])]
#[ORM\HasLifecycleCallbacks]
class Fixture implements TenantOwnedInterface
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
    #[RefersTo(Team::class, OnDelete::DELETE)]
    private string $teamId;

    /**
     * Required in base since lot 7 PR 4a: a friendly lives in a FRIENDLY competition (« Amicaux » by
     * default). With the team, it designates the team's engagement in that competition (composite key
     * `(competition_id, team_id, club_id)` → `engagement`, `ON DELETE RESTRICT`, posed by hand in
     * migration). Null only on a fixture not yet written: the base refuses to write it so.
     */
    #[ORM\Column(type: 'guid')]
    #[RefersTo(Competition::class, OnDelete::DELETE)]
    #[RefersTo(Engagement::class, OnDelete::DELETE)]
    // @phpstan-ignore doctrine.columnType (null avant la première écriture seulement ; la colonne NOT NULL refuse le reste)
    private ?string $competitionId = null;

    #[ORM\Column(type: 'date_immutable')]
    private DateTimeImmutable $matchDate;

    #[ORM\Column(length: 10, enumType: FixtureHomeAway::class)]
    private FixtureHomeAway $homeAway;

    #[ORM\Column(type: 'string', length: 180)]
    private string $opponentLabel;

    /**
     * À placer ou placé (colonne `status`, fermée par un CHECK). Le stade servi — saisi, validé — le compose avec le
     * suivi fédéral : il se lit par `FederalFollowUp::stageOf`, jamais ici.
     */
    #[ORM\Column(name: 'status', length: 20, enumType: PlacementStatus::class, options: ['default' => 'UNPLACED'])]
    private PlacementStatus $placement = PlacementStatus::UNPLACED;

    /** Home only: the venue that receives the match. */
    #[ORM\Column(type: 'guid', nullable: true)]
    #[RefersTo(Venue::class, OnDelete::CUSTOM, 'supprimer un gymnase porteur de matchs est refusé (VenueStateProcessor) ; la validation d\'un planning dépointe le match (FixtureVenueLossMarker)')]
    private ?string $venueId = null;

    /** Home: the placed kickoff. Away: the estimated kickoff (or null). */
    #[ORM\Column(type: 'time_immutable', nullable: true)]
    private ?DateTimeImmutable $kickoffTime = null;

    /**
     * FBI match number (import idempotence key) — null for manually entered
     * fixtures. Unique per (club, season, team) via a partial index.
     */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $externalRef = null;

    /**
     * Le libellé de division FBI du fichier dont vient cette rencontre (colonne
     * « Division » de l'export) — posé À LA CRÉATION à côté de {@see $externalRef},
     * donc non nul SSI `externalRef` l'est. Il entre dans l'identité d'une rencontre
     * importée (équipe + division + numéro, D1/M15) et dans l'index partiel unique :
     * au 2ᵉ tour l'équipe change de poule/division et FBI réutilise ses numéros —
     * sans la division, le n°X de la phase 2 réécrirait le n°X de la phase 1. Null
     * pour une rencontre saisie à la main ou née du canal API (external_ref nul).
     */
    #[ORM\Column(length: 180, nullable: true)]
    private ?string $externalDivision = null;

    /**
     * National FFBB rencontre id (RMM-4 PR-3), when this fixture was created from
     * the FFBB-API channel — the idempotence key so re-checking never re-proposes
     * a rencontre already created. Null for xlsx-imported or hand-entered
     * fixtures. Distinct from externalRef: the API has no FBI match number.
     */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $ffbbRencontreId = null;

    /**
     * The raw « Salle » label from the FBI export — filled HOME and AWAY (the
     * away venue is the travel-footprint raw material, cadrage P1-4 F3). Not a
     * Venue reference: the federation's label, never resolved in PR A.
     */
    #[ORM\Column(length: 180, nullable: true)]
    private ?string $fbiVenueLabel = null;

    /**
     * Who placed it (P1-4 PR D): MANUAL = untouchable anchor at re-solve;
     * SOLVER = re-arrangeable. Null = never placed / legacy (treated MANUAL).
     */
    #[ORM\Column(length: 10, nullable: true, enumType: FixturePlacementSource::class)]
    private ?FixturePlacementSource $placementSource = null;

    /**
     * The AWAY opponent's FFBB organisme code (P2-54 RMM-9 PR-3) — the JOIN KEY
     * between this fixture and both the GLOBAL opponent directory (where the
     * opponent plays) and the TENANT `opponent_venue_link` (this club's
     * label→gym pairing, whose gym feeds the constant travel cache). Stamped best-effort by {@see
     * App\Service\Basketball\OpponentLocationResolver} when it resolves the code
     * it already computes; null = opponent not (yet) located. HOME fixtures never
     * carry it (only an away match locates the opponent's own gym).
     *
     * ⚠ RAFFINEMENT du plan (le plan disait « cache club-side » sans préciser la
     * clé) : ce n'est PAS une donnée fédérale de plus sur la fixture, c'est la
     * clé de jointure vers l'annuaire — aucune écriture au global.
     */
    #[ORM\Column(name: 'opponent_organisme_code', length: 64, nullable: true)]
    private ?string $opponentOrganismeCode = null;

    /**
     * Why this match went back to « à placer » AND its reason must persist across
     * refreshes (P2-52 / RMM-10) — today only VENUE_LOST (its venue is no longer
     * affiliated to the club). Null = placed, or un-placed for a volatile/UI-only
     * reason. Cleared the moment a non-null venue is set again ({@see setVenueId}).
     */
    #[ORM\Column(length: 32, nullable: true, enumType: FixtureUnplacedReason::class)]
    private ?FixtureUnplacedReason $unplacedReason = null;

    /**
     * Le TRAITEMENT de la rencontre par le gestionnaire (espace « Importer »,
     * PR-3a), distinct du placement {@see PlacementStatus}. Défaut NEW. Passé à
     * REVIEWED par un geste explicite (trancher un écart, placer, créer à la
     * main) ; OUT_OF_SYNC dès qu'un écart pendant subsiste.
     */
    #[ORM\Column(length: 20, enumType: FixtureReviewState::class, options: ['default' => 'NEW'])]
    private FixtureReviewState $reviewState = FixtureReviewState::NEW;

    /** Quand la rencontre a été traitée pour la dernière fois (null = jamais). */
    #[ORM\Column(type: 'datetimetz_immutable', nullable: true)]
    private ?DateTimeImmutable $reviewedAt = null;

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

    public function getCompetitionId(): ?string
    {
        return $this->competitionId;
    }

    public function setCompetitionId(?string $competitionId): self
    {
        $this->competitionId = $competitionId;

        return $this;
    }

    public function getMatchDate(): DateTimeImmutable
    {
        return $this->matchDate;
    }

    public function setMatchDate(DateTimeImmutable $matchDate): self
    {
        $this->matchDate = $matchDate;

        return $this;
    }

    public function getHomeAway(): FixtureHomeAway
    {
        return $this->homeAway;
    }

    public function setHomeAway(FixtureHomeAway $homeAway): self
    {
        $this->homeAway = $homeAway;

        return $this;
    }

    public function getOpponentLabel(): string
    {
        return $this->opponentLabel;
    }

    public function setOpponentLabel(string $opponentLabel): self
    {
        $this->opponentLabel = $opponentLabel;

        return $this;
    }

    public function getPlacement(): PlacementStatus
    {
        return $this->placement;
    }

    /** Écrit par `FederalFollowUp` seulement, maison des effets d'un placement (le traitement de la rencontre). */
    public function setPlacement(PlacementStatus $placement): self
    {
        $this->placement = $placement;

        return $this;
    }

    public function getReviewState(): FixtureReviewState
    {
        return $this->reviewState;
    }

    public function setReviewState(FixtureReviewState $reviewState): self
    {
        $this->reviewState = $reviewState;

        return $this;
    }

    public function getReviewedAt(): ?DateTimeImmutable
    {
        return $this->reviewedAt;
    }

    /** Trancher/placer/créer = traiter : REVIEWED + horodaté (horloge de l'appelant). */
    public function markReviewed(DateTimeImmutable $at): self
    {
        $this->reviewState = FixtureReviewState::REVIEWED;
        $this->reviewedAt = $at;

        return $this;
    }

    /** Remet le traitement à zéro : NEW, jamais traitée (rencontre rétablie d'après FBI). */
    public function resetReview(): self
    {
        $this->reviewState = FixtureReviewState::NEW;
        $this->reviewedAt = null;

        return $this;
    }

    public function getVenueId(): ?string
    {
        return $this->venueId;
    }

    public function setVenueId(?string $venueId): self
    {
        $this->venueId = $venueId;
        // Effacement, MAISON UNIQUE (P2-52) : re-placer un match (venue non-null) éteint la
        // raison de dépointage persistante — couvre le processor, l'applier du solveur, tout
        // écrivain qui repose une salle. Le dépointage, lui, POSE la raison via son foyer
        // unique (FixtureVenueLossMarker), jamais par ce setter.
        // Le libellé de salle gardé, lui, vit dans le suivi fédéral : `KeptVenueLabelListener` l'éteint au flush.
        if (null !== $venueId) {
            $this->unplacedReason = null;
        }

        return $this;
    }

    public function getKickoffTime(): ?DateTimeImmutable
    {
        return $this->kickoffTime;
    }

    public function getExternalRef(): ?string
    {
        return $this->externalRef;
    }

    public function setExternalRef(?string $externalRef): self
    {
        $this->externalRef = $externalRef;

        return $this;
    }

    public function getExternalDivision(): ?string
    {
        return $this->externalDivision;
    }

    public function setExternalDivision(?string $externalDivision): self
    {
        $this->externalDivision = $externalDivision;

        return $this;
    }

    public function getFfbbRencontreId(): ?string
    {
        return $this->ffbbRencontreId;
    }

    public function setFfbbRencontreId(?string $ffbbRencontreId): self
    {
        $this->ffbbRencontreId = $ffbbRencontreId;

        return $this;
    }

    public function getFbiVenueLabel(): ?string
    {
        return $this->fbiVenueLabel;
    }

    public function getPlacementSource(): ?FixturePlacementSource
    {
        return $this->placementSource;
    }

    public function setPlacementSource(?FixturePlacementSource $placementSource): self
    {
        $this->placementSource = $placementSource;

        return $this;
    }

    public function getOpponentOrganismeCode(): ?string
    {
        return $this->opponentOrganismeCode;
    }

    public function setOpponentOrganismeCode(?string $opponentOrganismeCode): self
    {
        $this->opponentOrganismeCode = $opponentOrganismeCode;

        return $this;
    }

    public function getUnplacedReason(): ?FixtureUnplacedReason
    {
        return $this->unplacedReason;
    }

    public function setUnplacedReason(?FixtureUnplacedReason $unplacedReason): self
    {
        $this->unplacedReason = $unplacedReason;

        return $this;
    }

    public function setFbiVenueLabel(?string $fbiVenueLabel): self
    {
        $this->fbiVenueLabel = $fbiVenueLabel;

        return $this;
    }

    public function setKickoffTime(?DateTimeImmutable $kickoffTime): self
    {
        $this->kickoffTime = $kickoffTime;

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
