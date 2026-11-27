<?php

declare(strict_types=1);

namespace App\State\Processor;

use App\ApiResource\CompetitionResource;
use App\Dto\CompetitionInput;
use App\Entity\Competition;
use App\Entity\Team;
use App\Enum\CompetitionSource;
use App\Enum\CompetitionType;
use App\Matches\CompetitionRegistry;
use App\Service\ManagementAccessGuard;
use App\Service\SeasonAccessGuard;
use App\Service\SeasonResolver;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * `POST /api/competitions` : une compétition neuve de la saison, l'équipe engagée, par la porte
 * ({@see CompetitionRegistry::create} puis {@see CompetitionRegistry::engage}, un flush). Refusé 422 si
 * l'équipe n'est pas du club et de la saison, ou si elle a déjà une compétition de ce nom (comparaison
 * normalisée, {@see CompetitionRegistry::findForTeam}).
 *
 * @extends AbstractStateProcessor<Competition, CompetitionInput, CompetitionResource>
 */
class CompetitionStateProcessor extends AbstractStateProcessor
{
    public function __construct(
        EntityManagerInterface $entityManager,
        RequestStack $requestStack,
        SeasonResolver $seasonResolver,
        SeasonAccessGuard $seasonAccessGuard,
        ManagementAccessGuard $managementAccessGuard,
        private readonly CompetitionRegistry $competitionRegistry,
    ) {
        parent::__construct($entityManager, $requestStack, $seasonResolver, $seasonAccessGuard, $managementAccessGuard);
    }

    protected function getEntityClass(): string
    {
        return Competition::class;
    }

    /**
     * @param CompetitionInput $input
     */
    protected function processPost(object $input, ?string $clubId, ?string $seasonId): object
    {
        $resolvedSeasonId = $this->resolveSeasonId($clubId, $seasonId);
        // Les filtres club et saison rendent invisible l'équipe d'un autre club ou d'une autre saison.
        $team = null === $input->teamId ? null : $this->entityManager->find(Team::class, $input->teamId);
        if (null === $clubId || null === $resolvedSeasonId || !$team instanceof Team || $team->getClubId() !== $clubId || $team->getSeasonId() !== $resolvedSeasonId) {
            $this->refuse('Équipe inconnue pour ce club et cette saison.');
        }
        $name = (string) $input->name;
        $twin = $this->competitionRegistry->findForTeam($team->getId(), $name);
        if ($twin instanceof Competition) {
            $this->refuse(\sprintf('L\'équipe %s a déjà une compétition « %s ».', $team->getName(), $twin->getName()));
        }

        // Créée à la main : ses matchs ne sont pas suivis dans FBI (lot 7, PR 6b).
        $competition = $this->competitionRegistry->create($clubId, $resolvedSeasonId, $name, CompetitionType::from((string) $input->competitionType), CompetitionSource::MANUAL);
        $competition->setStartDate($this->parseDate($input->startDate));
        $competition->setEndDate($this->parseDate($input->endDate));
        $this->competitionRegistry->engage($competition, $team->getId());
        $this->entityManager->flush();

        return $this->mapEntityToOutput($competition);
    }

    /**
     * La création passe par {@see processPost} : l'abstraction impose seulement que ce foyer existe.
     */
    protected function createEntityFromInput(object $input): Competition
    {
        throw new LogicException('Une compétition se crée par la porte des engagements.');
    }

    /**
     * PUT retiré de cette ressource (nettoyage API) — l'abstraction impose seulement
     * que ce foyer existe ; il n'est plus atteignable par l'API.
     */
    protected function updateEntityFromInput(object $entity, object $input): void
    {
        throw new LogicException('La modification (PUT) n\'est pas exposée pour les compétitions.');
    }

    /**
     * @param Competition $entity
     */
    protected function mapEntityToOutput(object $entity): CompetitionResource
    {
        return CompetitionResource::fromEntity($entity, $this->competitionRegistry->engagementsOf($entity));
    }

    private function parseDate(?string $value): ?DateTimeImmutable
    {
        return null === $value || '' === $value ? null : new DateTimeImmutable($value);
    }
}
