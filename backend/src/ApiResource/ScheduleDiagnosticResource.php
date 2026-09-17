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
use App\Dto\DiagnosticCause;
use App\Dto\ScheduleDiagnosticInput;
use App\Entity\ScheduleDiagnostic;
use App\Enum\ScheduleDiagnosticSeverity;
use App\State\Processor\ScheduleDiagnosticStateProcessor;
use App\State\Provider\ScheduleDiagnosticStateProvider;
use DateTimeImmutable;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(shortName: 'ScheduleDiagnostic', operations: [
    new GetCollection,
    new Get,
    new Post,
    new Put,
    new Delete,
], input: ScheduleDiagnosticInput::class, paginationEnabled: true, paginationItemsPerPage: 30, provider: ScheduleDiagnosticStateProvider::class, processor: ScheduleDiagnosticStateProcessor::class)]
#[ApiFilter(SearchFilter::class, properties: ['scheduleId' => 'exact'])]
class ScheduleDiagnosticResource
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
    public string $scheduleId = '';

    #[Groups(['read'])]
    public string $type = '';

    #[Groups(['read'])]
    public ScheduleDiagnosticSeverity $severity;

    #[Groups(['read'])]
    public ?string $teamId = null;

    #[Groups(['read'])]
    public ?string $coachId = null;

    #[Groups(['read'])]
    public ?string $venueId = null;

    #[Groups(['read'])]
    public ?int $dayOfWeek = null;

    #[Groups(['read'])]
    public ?string $startTime = null;

    #[Groups(['read'])]
    public ?string $ruleKey = null;

    #[Groups(['read'])]
    public string $message = '';

    /** @var array<string, mixed> */
    #[Groups(['read'])]
    public array $suggestions = [];

    // P4-101 — typé par une CLASSE (DiagnosticCause), pas un tableau nu : c'est ce qui rend
    // le snapshot OpenAPI juste (`$ref` vers la forme réelle) au lieu du
    // `additionalProperties: string|null` qu'un `array` laissait déduire — et qui mentait
    // sur `count`.
    /**
     * Les causes structurées du diagnostic : chaque entrée décrit un facteur ayant empêché
     * ou compliqué le placement des équipes.
     *
     * @var list<DiagnosticCause>
     */
    #[Groups(['read'])]
    public array $causes = [];

    #[Groups(['read'])]
    public ?int $openCandidates = null;

    public static function fromEntity(ScheduleDiagnostic $entity): self
    {
        $dto = new self;
        $dto->id = $entity->getId();
        $dto->version = $entity->getVersion();
        $dto->createdAt = $entity->getCreatedAt();
        $dto->updatedAt = $entity->getUpdatedAt();
        $dto->scheduleId = $entity->getScheduleId();
        $dto->type = $entity->getType();
        $dto->severity = $entity->getSeverity();
        $dto->teamId = $entity->getTeamId();
        $dto->coachId = $entity->getCoachId();
        $dto->venueId = $entity->getVenueId();
        $dto->dayOfWeek = $entity->getDayOfWeek();
        $dto->startTime = $entity->getStartTime();
        $dto->ruleKey = $entity->getRuleKey();
        $dto->message = $entity->getMessage();
        $dto->suggestions = $entity->getSuggestions();
        $dto->causes = array_map(DiagnosticCause::fromArray(...), $entity->getCauses());
        $dto->openCandidates = $entity->getOpenCandidates();

        return $dto;
    }
}
