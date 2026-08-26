<?php

declare(strict_types=1);

namespace App\State\Processor;

use App\ApiResource\VenueTravelTimeResource;
use App\Dto\VenueTravelTimeInput;
use App\Entity\Venue;
use App\Entity\VenueTravelTime;
use App\Enum\VenueTravelTimeSource;

/**
 * CRUD d'un barème de trajet entre deux gymnases (P2-53 RMM-8). Management-gated
 * par défaut (AbstractStateProcessor). Normalise le couple (venueAId < venueBId)
 * pour que A–B ≡ B–A, et TOUTE valeur écrite ICI est une saisie gestionnaire →
 * source MANUAL : le cœur de la feature, une valeur MANUAL n'est jamais écrasée
 * par l'autofill (VenueTravelTimeAutofillService).
 *
 * @extends AbstractStateProcessor<VenueTravelTime, VenueTravelTimeInput, VenueTravelTimeResource>
 */
class VenueTravelTimeStateProcessor extends AbstractStateProcessor
{
    protected function getEntityClass(): string
    {
        return VenueTravelTime::class;
    }

    /**
     * @param VenueTravelTimeInput $input
     */
    protected function createEntityFromInput(object $input): VenueTravelTime
    {
        $entity = new VenueTravelTime;
        $this->applyInput($entity, $input);

        return $entity;
    }

    /**
     * @param VenueTravelTime      $entity
     * @param VenueTravelTimeInput $input
     */
    protected function updateEntityFromInput(object $entity, object $input): void
    {
        $this->applyInput($entity, $input);
    }

    /**
     * @param VenueTravelTime $entity
     */
    protected function mapEntityToOutput(object $entity): VenueTravelTimeResource
    {
        return VenueTravelTimeResource::fromEntity($entity);
    }

    private function applyInput(VenueTravelTime $entity, VenueTravelTimeInput $input): void
    {
        $venueAId = $input->venueAId;
        $venueBId = $input->venueBId;
        if (null === $venueAId || null === $venueBId) {
            $this->refuse('Les deux gymnases sont requis.');
        }
        if ($venueAId === $venueBId) {
            $this->refuse('Un gymnase ne peut pas être lié à lui-même.');
        }
        // SYMMETRIC couple: normalize so A–B and B–A are the SAME row (the DB
        // unique then makes the duplicate a clean 422 below).
        if (strcasecmp($venueAId, $venueBId) > 0) {
            [$venueAId, $venueBId] = [$venueBId, $venueAId];
        }
        $entity->setVenueAId($venueAId);
        $entity->setVenueBId($venueBId);

        // Foreign/unknown venues resolve to null through the tenant+season
        // filters → 422 (`findOneBy`, never `find()` — the tenant idiom).
        $venueRepository = $this->entityManager->getRepository(Venue::class);
        foreach ([$venueAId, $venueBId] as $venueId) {
            if (!$venueRepository->findOneBy(['id' => $venueId]) instanceof Venue) {
                $this->refuse('Gymnase inconnu pour ce club.');
            }
        }

        // TOUTE valeur saisie ICI est une correction gestionnaire → MANUAL, jamais
        // écrasée par l'autofill. Le PUT est partiel (null = inchangé, patron
        // VenueInput/CoachInput) : seule une valeur PRÉSENTE écrit et pose MANUAL.
        if (null !== $input->drivingMinutes) {
            $entity->setDrivingMinutes($input->drivingMinutes);
            $entity->setDrivingSource(VenueTravelTimeSource::MANUAL);
        }
        if (null !== $input->walkingMinutes) {
            $entity->setWalkingMinutes($input->walkingMinutes);
            $entity->setWalkingSource(VenueTravelTimeSource::MANUAL);
        }

        // One couple = one bracket (readable 422; the DB unique is the backstop).
        $existing = $this->entityManager->getRepository(VenueTravelTime::class)->findOneBy([
            'venueAId' => $venueAId,
            'venueBId' => $venueBId,
        ]);
        if ($existing instanceof VenueTravelTime && $existing->getId() !== $entity->getId()) {
            $this->refuse('Ces deux gymnases ont déjà un barème de trajet — modifiez le barème existant.');
        }
    }
}
