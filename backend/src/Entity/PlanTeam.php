<?php

declare(strict_types=1);

namespace App\Entity;

use App\Plans\PlanComposition;
use App\Structure\Family;
use App\Structure\Level;
use App\Structure\OnDelete;
use App\Structure\RefersTo;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Les équipes d'un plan (lot 4, PR 3a) : une ligne par (plan, équipe), socle compris. Elle dit si l'équipe participe
 * au plan, ses séances par semaine et son plafond par jour. La ligne du plan de saison porte les valeurs du socle ;
 * celle d'un plan de période naît aux valeurs du socle du moment et ne les relit jamais ensuite (décision du
 * fondateur du 2026-11-22 : un plan garde ce qu'il avait à sa naissance).
 *
 * Les lignes ne se posent que par {@see PlanComposition::ensureLines}. Pas de clé vers l'équipe : « Charger » efface
 * puis réinsère les équipes ; la suppression d'une équipe passe par la cascade (`CascadePlan`).
 */
#[Family(Level::PLAN, copiedAtPlanBirth: false, cold: ['teamId', 'participates', 'sessionsPerWeek', 'maxSessionsPerDay'], copiedToNextSeason: true)]
#[ORM\Entity]
#[ORM\Table(name: 'plan_team')]
#[ORM\UniqueConstraint(name: 'uniq_plan_team', columns: ['schedule_plan_id', 'team_id'])]
#[ORM\Index(name: 'idx_plan_team_season', columns: ['season_id'])]
#[ORM\Index(name: 'idx_plan_team_team', columns: ['team_id'])]
class PlanTeam implements TenantOwnedInterface
{
    /** Les valeurs d'une ligne du socle posée sans valeur saisie (celles qu'une équipe avait par défaut). */
    public const int DEFAULT_SESSIONS_PER_WEEK = 2;

    public const int DEFAULT_MAX_SESSIONS_PER_DAY = 1;

    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

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
    #[RefersTo(SchedulePlan::class, OnDelete::DELETE)]
    private string $schedulePlanId;

    #[ORM\Column(type: 'guid')]
    #[RefersTo(Team::class, OnDelete::DELETE)]
    private string $teamId;

    #[ORM\Column(type: 'boolean')]
    private bool $participates = true;

    #[ORM\Column(type: 'integer')]
    private int $sessionsPerWeek = self::DEFAULT_SESSIONS_PER_WEEK;

    /** Séances au plus le même jour (1 à 4). */
    #[ORM\Column(type: 'smallint')]
    private int $maxSessionsPerDay = self::DEFAULT_MAX_SESSIONS_PER_DAY;

    /**
     * L'origine d'un réglage posé par un geste nommé (« mutualisation » quand la mutualisation d'une case active une
     * équipe) ; null pour un réglage du gestionnaire. L'écran l'affiche, il ne décide rien avec.
     */
    #[ORM\Column(type: 'string', length: 30, nullable: true)]
    private ?string $source = null;

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

    public function getClubId(): ?string
    {
        return $this->clubId;
    }

    public function setClubId(string $clubId): static
    {
        $this->clubId = $clubId;

        return $this;
    }

    public function getSeasonId(): string
    {
        return $this->seasonId;
    }

    public function setSeasonId(string $seasonId): static
    {
        $this->seasonId = $seasonId;

        return $this;
    }

    public function getSchedulePlanId(): string
    {
        return $this->schedulePlanId;
    }

    public function setSchedulePlanId(string $schedulePlanId): static
    {
        $this->schedulePlanId = $schedulePlanId;

        return $this;
    }

    public function getTeamId(): string
    {
        return $this->teamId;
    }

    public function setTeamId(string $teamId): static
    {
        $this->teamId = $teamId;

        return $this;
    }

    public function isParticipating(): bool
    {
        return $this->participates;
    }

    public function setParticipating(bool $participates): static
    {
        $this->participates = $participates;

        return $this;
    }

    public function getSessionsPerWeek(): int
    {
        return $this->sessionsPerWeek;
    }

    public function setSessionsPerWeek(int $sessionsPerWeek): static
    {
        $this->sessionsPerWeek = $sessionsPerWeek;

        return $this;
    }

    public function getMaxSessionsPerDay(): int
    {
        return $this->maxSessionsPerDay;
    }

    public function setMaxSessionsPerDay(int $maxSessionsPerDay): static
    {
        $this->maxSessionsPerDay = $maxSessionsPerDay;

        return $this;
    }

    public function getSource(): ?string
    {
        return $this->source;
    }

    public function setSource(?string $source): static
    {
        $this->source = $source;

        return $this;
    }

    /** La ligne vaut-elle celle du socle donnée (participe, mêmes séances, même plafond) ? */
    public function sameAs(self $socle): bool
    {
        return $this->participates
            && $this->sessionsPerWeek === $socle->sessionsPerWeek
            && $this->maxSessionsPerDay === $socle->maxSessionsPerDay;
    }

    private function newUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
