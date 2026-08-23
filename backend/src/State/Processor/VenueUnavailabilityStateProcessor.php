<?php

declare(strict_types=1);

namespace App\State\Processor;

use App\ApiResource\VenueUnavailabilityResource;
use App\Dto\VenueUnavailabilityInput;
use App\Entity\Venue;
use App\Entity\VenueUnavailability;
use DateTimeImmutable;

/**
 * Cockpit-surface write (the unavailability is posed on the club calendar) —
 * management gated, like the other cockpit writes (SEC-07).
 *
 * @extends AbstractStateProcessor<VenueUnavailability, VenueUnavailabilityInput, VenueUnavailabilityResource>
 */
class VenueUnavailabilityStateProcessor extends AbstractStateProcessor
{
    protected function getEntityClass(): string
    {
        return VenueUnavailability::class;
    }

    protected function requiresManagementRole(): bool
    {
        return true;
    }

    /**
     * @param VenueUnavailabilityInput $input
     */
    protected function createEntityFromInput(object $input): VenueUnavailability
    {
        $entity = new VenueUnavailability;
        $this->applyInput($entity, $input);

        return $entity;
    }

    /**
     * @param VenueUnavailability      $entity
     * @param VenueUnavailabilityInput $input
     */
    protected function updateEntityFromInput(object $entity, object $input): void
    {
        $this->applyInput($entity, $input);
    }

    /**
     * @param VenueUnavailability $entity
     */
    protected function mapEntityToOutput(object $entity): VenueUnavailabilityResource
    {
        return VenueUnavailabilityResource::fromEntity($entity);
    }

    private function applyInput(VenueUnavailability $entity, VenueUnavailabilityInput $input): void
    {
        if (null !== $input->venueId) {
            $entity->setVenueId($input->venueId);
        }
        if (null !== $input->startDate) {
            $entity->setStartDate(new DateTimeImmutable($input->startDate));
        }
        if (null !== $input->endDate) {
            $entity->setEndDate(new DateTimeImmutable($input->endDate));
        }
        // '' explicitly clears the label; absent keeps it (PUT full-replace
        // sends every field in this API, the trim guards stray whitespace).
        if (null !== $input->label) {
            $trimmed = trim($input->label);
            $entity->setLabel('' === $trimmed ? null : $trimmed);
        }

        $this->assertVenueInScope($entity->getVenueId());
        if ($entity->getStartDate() > $entity->getEndDate()) {
            $this->refuse('L\'indisponibilité doit se terminer à sa date de début ou après.');
        }
    }

    /**
     * A foreign/unknown venueId resolves to null through the tenant+season
     * filters → 422, never a dangling reference. `findOneBy`, NOT `find()`:
     * a PK load can serve the identity map / skip the SQL filters, and this
     * check exists precisely to hit the filtered SQL path.
     */
    private function assertVenueInScope(string $venueId): void
    {
        if (!$this->entityManager->getRepository(Venue::class)->findOneBy(['id' => $venueId]) instanceof Venue) {
            $this->refuse('Gymnase inconnu pour ce club.');
        }
    }
}
