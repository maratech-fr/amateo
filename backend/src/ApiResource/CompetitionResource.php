<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\Dto\CompetitionInput;
use App\Entity\Competition;
use App\Entity\SharedCompetitionDeadline;
use App\State\Processor\CompetitionStateProcessor;
use App\State\Provider\CompetitionStateProvider;
use DateTimeImmutable;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(shortName: 'Competition', operations: [
    new GetCollection,
    new Get,
    new Post,
    new Put,
    new Delete,
], input: CompetitionInput::class, paginationEnabled: true, paginationItemsPerPage: 50, provider: CompetitionStateProvider::class, processor: CompetitionStateProcessor::class)]
#[ApiFilter(SearchFilter::class, properties: ['seasonId' => 'exact', 'teamId' => 'exact'])]
class CompetitionResource
{
    #[Groups(['read'])]
    public string $id = '';

    #[Groups(['read'])]
    public int $version = 0;

    #[Groups(['read'])]
    public DateTimeImmutable $createdAt;

    #[Groups(['read'])]
    public DateTimeImmutable $updatedAt;

    #[Groups(['read'])]
    public string $teamId = '';

    #[Groups(['read'])]
    public string $seasonId = '';

    #[Groups(['read'])]
    public string $name = '';

    #[Groups(['read'])]
    public string $competitionType = '';

    #[Groups(['read'])]
    public ?string $startDate = null;

    #[Groups(['read'])]
    public ?string $endDate = null;

    /** FBI club-team label — disambiguates two club teams in one division. */
    #[Groups(['read'])]
    public ?string $fbiTeamLabel = null;

    // ── FFBB pairing refs (P1-4 PR F) — read-only: written by the pairing
    // confirm endpoint, never by this CRUD. ──

    #[Groups(['read'])]
    public ?string $ffbbCompetitionId = null;

    #[Groups(['read'])]
    public ?string $ffbbPouleId = null;

    #[Groups(['read'])]
    public ?string $ffbbPouleName = null;

    #[Groups(['read'])]
    public ?string $ffbbCompetitionName = null;

    #[Groups(['read'])]
    public ?int $expectedMatchdays = null;

    // ── Entry deadline (RMM-6) — read-only projection. `entryDeadline` is the
    // club's own value; `effectiveEntryDeadline` applies the rule « club value
    // wins, else the community default »; `deadlineSource` names which one the
    // effective value came from. The backend serves the rule so the front never
    // reinvents « club gagne ». Written via POST /api/competitions/entry-deadlines. ──

    /** The club's own entry deadline (Y-m-d), null when the club has set none. */
    #[Groups(['read'])]
    public ?string $entryDeadline = null;

    /** club value ?? community default (Y-m-d), null when neither exists. */
    #[Groups(['read'])]
    public ?string $effectiveEntryDeadline = null;

    /** 'club' | 'community' | null — the origin of effectiveEntryDeadline. */
    #[Groups(['read'])]
    public ?string $deadlineSource = null;

    public static function fromEntity(Competition $entity, ?SharedCompetitionDeadline $shared = null): self
    {
        $dto = new self;
        $dto->id = $entity->getId();
        $dto->version = $entity->getVersion();
        $dto->createdAt = $entity->getCreatedAt();
        $dto->updatedAt = $entity->getUpdatedAt();
        $dto->teamId = $entity->getTeamId();
        $dto->seasonId = $entity->getSeasonId();
        $dto->name = $entity->getName();
        $dto->competitionType = $entity->getCompetitionType()->value;
        $dto->startDate = $entity->getStartDate()?->format('Y-m-d');
        $dto->endDate = $entity->getEndDate()?->format('Y-m-d');
        $dto->fbiTeamLabel = $entity->getFbiTeamLabel();
        $dto->ffbbCompetitionId = $entity->getFfbbCompetitionId();
        $dto->ffbbPouleId = $entity->getFfbbPouleId();
        $dto->ffbbPouleName = $entity->getFfbbPouleName();
        $dto->ffbbCompetitionName = $entity->getFfbbCompetitionName();
        $dto->expectedMatchdays = $entity->getExpectedMatchdays();

        $clubDeadline = $entity->getEntryDeadline();
        $dto->entryDeadline = $clubDeadline?->format('Y-m-d');
        // « club gagne, sinon le défaut communautaire » — servi par le backend
        // (le front n'invente jamais la règle). Le partagé n'est joint que pour
        // une compétition appariée (ffbbCompetitionId non null → sa clé).
        if ($clubDeadline instanceof DateTimeImmutable) {
            $dto->effectiveEntryDeadline = $clubDeadline->format('Y-m-d');
            $dto->deadlineSource = 'club';
        } elseif ($shared instanceof SharedCompetitionDeadline) {
            $dto->effectiveEntryDeadline = $shared->getEntryDeadline()->format('Y-m-d');
            $dto->deadlineSource = 'community';
        }

        return $dto;
    }
}
