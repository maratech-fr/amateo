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
use App\Access\Audience;
use App\Access\ClubResource;
use App\Access\Module;
use App\Dto\TeamPeriodOverrideInput;
use App\Entity\PlanTeam;
use App\State\Processor\TeamPeriodOverrideStateProcessor;
use App\State\Provider\TeamPeriodOverrideStateProvider;
use DateTimeImmutable;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Le réglage d'une équipe pour une période : la ligne de l'équipe dans le plan de période, vue de l'écran comme un
 * réglage épars. La liste ne rend que les lignes qui diffèrent de la saison ou qu'un geste nommé a posées ; `null` en
 * sortie = la valeur de la saison. Publier le réglage d'un couple (plan, équipe) met sa ligne à jour ; le supprimer rend
 * à la ligne les valeurs de la saison. La saison ne se règle jamais par cette route.
 */
#[ApiResource(shortName: 'TeamPeriodOverride', operations: [
    new GetCollection,
    new Get,
    new Post,
    new Put,
    new Delete,
], input: TeamPeriodOverrideInput::class, paginationEnabled: false, provider: TeamPeriodOverrideStateProvider::class, processor: TeamPeriodOverrideStateProcessor::class)]
#[ApiFilter(SearchFilter::class, properties: ['schedulePlanId' => 'exact', 'teamId' => 'exact'])]
#[ClubResource(module: Module::PLANNING, read: Audience::MEMBER, write: Audience::MANAGER)]
class TeamPeriodOverrideResource
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
    public string $schedulePlanId = '';

    #[Groups(['read'])]
    public string $teamId = '';

    #[Groups(['read'])]
    public bool $isActive = true;

    #[Groups(['read'])]
    public ?int $sessionsPerWeek = null;

    #[Groups(['read'])]
    public ?int $maxSessionsPerDay = null;

    /**
     * Lot 9 — l'origine de ce réglage quand il a été posé par un geste nommé (``mutualisation``
     * pour une équipe activée par la mutualisation d'une case) ; NULL pour un réglage ordinaire du
     * wizard. Le planning l'AFFICHE (pastille « via mutualisation »), il ne décide rien avec.
     */
    #[Groups(['read'])]
    public ?string $source = null;

    /** La ligne de période, lue contre la ligne du socle de la même équipe : une valeur égale au socle sort `null`. */
    public static function fromEntity(PlanTeam $entity, PlanTeam $socleLine): self
    {
        $dto = new self;
        $dto->id = $entity->getId();
        $dto->version = $entity->getVersion();
        $dto->createdAt = $entity->getCreatedAt();
        $dto->updatedAt = $entity->getUpdatedAt();
        $dto->schedulePlanId = $entity->getSchedulePlanId();
        $dto->teamId = $entity->getTeamId();
        $dto->isActive = $entity->isParticipating();
        $dto->sessionsPerWeek = $entity->getSessionsPerWeek() === $socleLine->getSessionsPerWeek() ? null : $entity->getSessionsPerWeek();
        $dto->maxSessionsPerDay = $entity->getMaxSessionsPerDay() === $socleLine->getMaxSessionsPerDay() ? null : $entity->getMaxSessionsPerDay();
        $dto->source = $entity->getSource();

        return $dto;
    }
}
