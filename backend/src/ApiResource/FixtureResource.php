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
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use ApiPlatform\OpenApi\Model\RequestBody as OpenApiRequestBody;
use App\Dto\FixtureInput;
use App\Entity\Fixture;
use App\State\Processor\FixtureStateProcessor;
use App\State\Provider\FixtureStateProvider;
use ArrayObject;
use DateTimeImmutable;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(shortName: 'Fixture', operations: [
    new GetCollection,
    new Get,
    new Post,
    new Put,
    new Delete,
    // FBI import, one-pass flow (cadrage P1-4, 2026-08-02): the real export is
    // CLUB-WIDE, so both endpoints hang off the fixtures collection — no team
    // in the URL, the tenant context says which club. The openapi context
    // overrides the generated doc (otherwise described as JSON Fixture ops).
    new Post(
        uriTemplate: '/fixtures/import/analyze',
        controller: 'App\Controller\ImportFixturesAnalyzeController',
        openapi: new OpenApiOperation(
            summary: 'Analyze an FBI club export (.xlsx) — dry-run',
            description: 'Multipart upload (field "file"). Parses the club-wide FBI export and returns its division groups resolved against the persisted Division↔team mappings, plus the reconciliation deviations — home matches already placed whose date/heure/salle differ from the file (state app VS state file), each to be decided at import: {divisions[{name, fbiTeamLabel, rowCount, teamId, competitionId}], totalRows, exempted, errors[], deviations[{fixtureId, externalRef, division, teamId, status, persisting, fields{date?,kickoff?,venue? each {app,file}}}]}. Writes nothing. 404 no club/membership · 403 non-management member · 409 archived season or plan not validated · 400 missing/invalid file or columns.',
            requestBody: new OpenApiRequestBody(
                content: new ArrayObject([
                    'multipart/form-data' => [
                        'schema' => ['type' => 'object', 'properties' => ['file' => ['type' => 'string', 'format' => 'binary']], 'required' => ['file']],
                    ],
                ]),
            ),
        ),
        read: false,
        deserialize: false,
        name: 'analyze_fixtures_import',
    ),
    new Post(
        uriTemplate: '/fixtures/import',
        controller: 'App\Controller\ImportFixturesController',
        openapi: new OpenApiOperation(
            summary: 'Import an FBI club export (.xlsx) with validated mappings',
            description: 'Multipart upload: field "file" + optional field "mappings" (JSON list of {division, fbiTeamLabel?, teamId} — the manager\'s choices from the analyze step; persisted as Competition rows) + optional field "decisions" (JSON list of {fixtureId, field: date|kickoff|venue, choice: keep_app|take_file} — the per-écart verdicts from the reconciliation screen). Creates new fixtures and DIFF-UPDATES known ones (by FBI number per team): a rescheduled date or a HOME↔AWAY switch un-places the match and lands in warnings[]. A date/heure/salle difference on a home match already placed is NOT overwritten by default — without a decision it is left intact and reported in unresolvedDeviations. Every deposit is dated. Report {message, created, updated, unchanged, exempted, errors[], warnings[{type, division, externalRef, message}], unmappedDivisions[], unresolvedDeviations[{fixtureId, externalRef, division, teamId, status, persisting, fields}], depositedAt}. 404 no club/membership · 403 non-management member · 409 archived season, plan not validated or concurrent duplicate import · 400 missing/invalid file, columns, mappings or decisions.',
            requestBody: new OpenApiRequestBody(
                content: new ArrayObject([
                    'multipart/form-data' => [
                        'schema' => ['type' => 'object', 'properties' => [
                            'file' => ['type' => 'string', 'format' => 'binary'],
                            'mappings' => ['type' => 'string', 'description' => 'JSON list of {division, fbiTeamLabel?, teamId}'],
                            'decisions' => ['type' => 'string', 'description' => 'JSON list of {fixtureId, field: date|kickoff|venue, choice: keep_app|take_file}'],
                        ], 'required' => ['file']],
                    ],
                ]),
            ),
        ),
        read: false,
        deserialize: false,
        name: 'import_fixtures',
    ),
], input: FixtureInput::class, paginationEnabled: true, paginationItemsPerPage: 100, provider: FixtureStateProvider::class, processor: FixtureStateProcessor::class)]
#[ApiFilter(SearchFilter::class, properties: ['seasonId' => 'exact', 'teamId' => 'exact', 'competitionId' => 'exact', 'homeAway' => 'exact', 'status' => 'exact'])]
class FixtureResource
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
    public ?string $competitionId = null;

    #[Groups(['read'])]
    public string $matchDate = '';

    #[Groups(['read'])]
    public string $homeAway = '';

    #[Groups(['read'])]
    public string $opponentLabel = '';

    #[Groups(['read'])]
    public string $status = '';

    #[Groups(['read'])]
    public ?string $venueId = null;

    #[Groups(['read'])]
    public ?string $kickoffTime = null;

    /** FBI match number (import idempotence key) — null for manual entries. */
    #[Groups(['read'])]
    public ?string $externalRef = null;

    /** Raw FBI « Salle » label, HOME and AWAY — never a Venue reference. */
    #[Groups(['read'])]
    public ?string $fbiVenueLabel = null;

    /** MANUAL | SOLVER | null — who placed it (re-solve anchor marker, PR D). */
    #[Groups(['read'])]
    public ?string $placementSource = null;

    /**
     * Why it went back to « à placer », when the reason must persist: `venue_lost`
     * (its venue is no longer affiliated to the club), else null. Distinct from the
     * volatile auto-placement reason held only in the UI.
     */
    #[Groups(['read'])]
    public ?string $unplacedReason = null;

    public static function fromEntity(Fixture $entity): self
    {
        $dto = new self;
        $dto->id = $entity->getId();
        $dto->version = $entity->getVersion();
        $dto->createdAt = $entity->getCreatedAt();
        $dto->updatedAt = $entity->getUpdatedAt();
        $dto->teamId = $entity->getTeamId();
        $dto->seasonId = $entity->getSeasonId();
        $dto->competitionId = $entity->getCompetitionId();
        $dto->matchDate = $entity->getMatchDate()->format('Y-m-d');
        $dto->homeAway = $entity->getHomeAway()->value;
        $dto->opponentLabel = $entity->getOpponentLabel();
        $dto->status = $entity->getStatus()->value;
        $dto->venueId = $entity->getVenueId();
        $dto->kickoffTime = $entity->getKickoffTime()?->format('H:i');
        $dto->externalRef = $entity->getExternalRef();
        $dto->fbiVenueLabel = $entity->getFbiVenueLabel();
        $dto->placementSource = $entity->getPlacementSource()?->value;
        $dto->unplacedReason = $entity->getUnplacedReason()?->value;

        return $dto;
    }
}
