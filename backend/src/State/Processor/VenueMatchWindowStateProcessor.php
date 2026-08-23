<?php

declare(strict_types=1);

namespace App\State\Processor;

use App\ApiResource\VenueMatchWindowResource;
use App\Dto\VenueMatchWindowInput;
use App\Entity\Venue;
use App\Entity\VenueMatchWindow;
use DateTimeImmutable;

/**
 * Wizard-surface structure entity (like VenueTrainingSlot): NOT management
 * gated — the same roles that edit training slots edit match access windows.
 *
 * @extends AbstractStateProcessor<VenueMatchWindow, VenueMatchWindowInput, VenueMatchWindowResource>
 */
class VenueMatchWindowStateProcessor extends AbstractStateProcessor
{
    protected function getEntityClass(): string
    {
        return VenueMatchWindow::class;
    }

    /**
     * @param VenueMatchWindowInput $input
     */
    protected function createEntityFromInput(object $input): VenueMatchWindow
    {
        $entity = new VenueMatchWindow;
        $this->applyInput($entity, $input);

        return $entity;
    }

    /**
     * @param VenueMatchWindow      $entity
     * @param VenueMatchWindowInput $input
     */
    protected function updateEntityFromInput(object $entity, object $input): void
    {
        $this->applyInput($entity, $input);
    }

    /**
     * @param VenueMatchWindow $entity
     */
    protected function mapEntityToOutput(object $entity): VenueMatchWindowResource
    {
        return VenueMatchWindowResource::fromEntity($entity);
    }

    private function applyInput(VenueMatchWindow $entity, VenueMatchWindowInput $input): void
    {
        if (null !== $input->venueId) {
            $entity->setVenueId($input->venueId);
        }
        if (null !== $input->dayOfWeek) {
            $entity->setDayOfWeek($input->dayOfWeek);
        }
        if (null !== $input->startTime) {
            $entity->setStartTime(new DateTimeImmutable($input->startTime));
        }
        if (null !== $input->endTime) {
            $entity->setEndTime(new DateTimeImmutable($input->endTime));
        }

        $this->assertVenueInScope($entity->getVenueId());
        // Same-day window, no midnight crossing (P4-61 precedent) — end is
        // exclusive, so start < end is the whole rule.
        if ($entity->getStartTime()->format('H:i') >= $entity->getEndTime()->format('H:i')) {
            $this->refuse('Une fenêtre d\'accès aux matchs doit se terminer après son début, le même jour.');
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
