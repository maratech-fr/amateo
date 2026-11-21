<?php

declare(strict_types=1);

namespace App\State\Processor;

use App\ApiResource\VenueOccupancyResource;
use App\Dto\VenueOccupancyInput;
use App\Entity\Venue;
use App\Entity\VenueOccupancy;
use App\Service\ManagementAccessGuard;
use App\Service\SeasonAccessGuard;
use App\Service\SeasonResolver;
use App\Service\VenueGridConflict;
use App\Service\VenueGridOverlap;
use App\Support\WeeklySlot;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Écrit une occupation de gymnase. Gardes : rôle de gestion et saison non archivée (socle), plan
 * existant (contrôle puis filet de course), gymnase du club, motif (rogné, obligatoire, 40
 * caractères), fin dans la journée, aucun chevauchement avec un créneau ou une occupation de la
 * même couche. La suppression ne contrôle aucun chevauchement. Aucune garde « planning validé »
 * côté serveur, comme pour les créneaux.
 *
 * @extends AbstractStateProcessor<VenueOccupancy, VenueOccupancyInput, VenueOccupancyResource>
 */
class VenueOccupancyStateProcessor extends AbstractStateProcessor
{
    use AssertsSchedulePlanExistsTrait;

    private const int LABEL_MAX_LENGTH = 40;

    public function __construct(
        EntityManagerInterface $entityManager,
        RequestStack $requestStack,
        SeasonResolver $seasonResolver,
        SeasonAccessGuard $seasonAccessGuard,
        ManagementAccessGuard $managementAccessGuard,
        private readonly VenueGridOverlap $gridOverlap,
    ) {
        parent::__construct($entityManager, $requestStack, $seasonResolver, $seasonAccessGuard, $managementAccessGuard);
    }

    protected function getEntityClass(): string
    {
        return VenueOccupancy::class;
    }

    /**
     * Le flush a lieu dans le socle : la violation de clé étrangère d'une suppression de plan
     * CONCURRENTE ne se rattrape qu'ici, autour de l'appel parent (422, jamais 500).
     *
     * @param VenueOccupancyInput $input
     */
    protected function processPost(object $input, ?string $clubId, ?string $seasonId): object
    {
        return $this->rejectingConcurrentPlanDeletion(fn (): object => parent::processPost($input, $clubId, $seasonId));
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param VenueOccupancyInput  $input
     */
    protected function processPut(object $input, array $uriVariables, ?string $clubId, ?string $seasonId): object
    {
        return $this->rejectingConcurrentPlanDeletion(fn (): object => parent::processPut($input, $uriVariables, $clubId, $seasonId));
    }

    /**
     * @param VenueOccupancyInput $input
     */
    protected function createEntityFromInput(object $input): VenueOccupancy
    {
        $entity = new VenueOccupancy;
        // Saison (null) ou grille d'un plan de période — posé à la création seulement : une
        // occupation ne migre jamais d'une couche à l'autre.
        $this->assertSchedulePlanExists($this->entityManager, $input->schedulePlanId);
        $entity->setSchedulePlanId($input->schedulePlanId);
        $this->applyInput($entity, $input);

        return $entity;
    }

    /**
     * @param VenueOccupancy      $entity
     * @param VenueOccupancyInput $input
     */
    protected function updateEntityFromInput(object $entity, object $input): void
    {
        $this->applyInput($entity, $input);
    }

    /**
     * @param VenueOccupancy $entity
     */
    protected function mapEntityToOutput(object $entity): VenueOccupancyResource
    {
        return VenueOccupancyResource::fromEntity($entity);
    }

    private function applyInput(VenueOccupancy $entity, VenueOccupancyInput $input): void
    {
        if (null !== $input->venueId) {
            $this->assertVenueInScope($input->venueId);
            $entity->setVenueId($input->venueId);
        }
        if (null !== $input->dayOfWeek) {
            $entity->setDayOfWeek($input->dayOfWeek);
        }
        if (null !== $input->startTime) {
            $entity->setStartTime(new DateTimeImmutable($input->startTime));
        }
        if (null !== $input->durationMinutes) {
            $entity->setDurationMinutes($input->durationMinutes);
        }
        $entity->setLabel($this->validLabel($input->label));

        $start = WeeklySlot::minutesOf($entity->getStartTime());
        if ($start + $entity->getDurationMinutes() > WeeklySlot::MINUTES_PER_DAY) {
            $this->refuse('Une occupation doit se terminer dans sa journée : celle-ci déborderait après minuit.');
        }

        $conflict = $this->gridOverlap->firstConflict(
            $entity->getVenueId(),
            $entity->getDayOfWeek(),
            $start,
            $entity->getDurationMinutes(),
            $entity->getSchedulePlanId(),
            exceptOccupancyId: $entity->getId(),
        );
        if ($conflict instanceof VenueGridConflict) {
            $this->refuse($conflict->message(VenueGridConflict::OCCUPANCY));
        }
    }

    /** Le motif rogné ; vide ou au-delà de 40 caractères, refusé. */
    private function validLabel(?string $label): string
    {
        $trimmed = trim($label ?? '');
        if ('' === $trimmed) {
            $this->refuse('Donnez un motif à l\'occupation.');
        }
        if (mb_strlen($trimmed) > self::LABEL_MAX_LENGTH) {
            $this->refuse('Le motif d\'une occupation tient en 40 caractères.');
        }

        return $trimmed;
    }

    /**
     * Un gymnase inconnu ou d'un autre club se résout à null sous les filtres de club → 422.
     * `findOneBy`, PAS `find()` : un chargement par clé primaire peut servir la carte d'identité
     * et sauter les filtres SQL, que ce contrôle existe justement pour traverser.
     */
    private function assertVenueInScope(string $venueId): void
    {
        if (!$this->entityManager->getRepository(Venue::class)->findOneBy(['id' => $venueId]) instanceof Venue) {
            $this->refuse('Gymnase inconnu pour ce club.');
        }
    }
}
