<?php

declare(strict_types=1);

namespace App\State\Processor;

use App\ApiResource\VenueResource;
use App\Dto\VenueInput;
use App\Entity\Venue;
use App\Entity\VenueTrainingSlot;

/**
 * @extends AbstractStateProcessor<Venue, VenueInput, VenueResource>
 */
class VenueStateProcessor extends AbstractStateProcessor
{
    protected function getEntityClass(): string
    {
        return Venue::class;
    }

    protected function cascadeBeforeDelete(object $entity): void
    {
        if ($entity instanceof Venue) {
            $this->cascadeDeleter?->purgeChildrenOfVenue($entity);
        }
    }

    /**
     * @param VenueInput $input
     */
    protected function createEntityFromInput(object $input): Venue
    {
        $entity = new Venue;
        if (null !== $input->name) {
            $entity->setName($input->name);
        }
        if (null !== $input->isExternal) {
            $entity->setIsExternal($input->isExternal);
        }
        if (null !== $input->color) {
            $entity->setColor($input->color);
        }
        if (null !== $input->latitude) {
            $entity->setLatitude($input->latitude);
        }
        if (null !== $input->longitude) {
            $entity->setLongitude($input->longitude);
        }
        if (null !== $input->source) {
            $entity->setSource($input->source);
        }
        if (null !== $input->externalRef) {
            $entity->setExternalRef($input->externalRef);
        }
        if (null !== $input->isActive) {
            $entity->setIsActive($input->isActive);
        }
        if (null !== $input->parentVenueId) {
            $entity->setParentVenueId($input->parentVenueId);
        }
        if (null !== $input->canSplit) {
            $entity->setCanSplit($input->canSplit);
        }

        return $entity;
    }

    /**
     * @param Venue      $entity
     * @param VenueInput $input
     */
    protected function updateEntityFromInput(object $entity, object $input): void
    {
        // Lu AVANT toute mutation : c'est l'état d'origine du gymnase qui dit si on est sur une
        // transition « divisible » true→false (celle qui peut rendre des créneaux incohérents).
        $wasSplit = $entity->getCanSplit();
        if (null !== $input->name) {
            $entity->setName($input->name);
        }
        if (null !== $input->isExternal) {
            $entity->setIsExternal($input->isExternal);
        }
        if (null !== $input->color) {
            $entity->setColor($input->color);
        }
        if (null !== $input->latitude) {
            $entity->setLatitude($input->latitude);
        }
        if (null !== $input->longitude) {
            $entity->setLongitude($input->longitude);
        }
        if (null !== $input->source) {
            $entity->setSource($input->source);
        }
        if (null !== $input->externalRef) {
            $entity->setExternalRef($input->externalRef);
        }
        if (null !== $input->isActive) {
            $entity->setIsActive($input->isActive);
        }
        if (null !== $input->parentVenueId) {
            $entity->setParentVenueId($input->parentVenueId);
        }
        // canSplit was silently dropped on update → toggling "terrain divisible"
        // never persisted, so the per-slot capacity control never appeared and the
        // solver never split the court. A false value must persist (uncheck), so
        // guard on `null !==`, not truthiness.
        if (null !== $input->canSplit) {
            if ($wasSplit && false === $input->canSplit) {
                // La transition « divisible → non divisible » ne peut pas laisser derrière elle
                // des créneaux qui accueillent encore 2 équipes ou plus (incohérent). Refus (422)
                // sans confirmation, cascade avec.
                $this->guardSplitTransition($entity, $input->confirmSplitCascade);
            }
            $entity->setCanSplit($input->canSplit);
        }
    }

    /**
     * @param Venue $entity
     */
    protected function mapEntityToOutput(object $entity): VenueResource
    {
        return VenueResource::fromEntity($entity);
    }

    /**
     * v2 cohérence canSplit — la garde INVERSE (poser capacité ≥ 2 sur un gymnase non divisible)
     * existe déjà côté créneau (VenueTrainingSlotStateProcessor::validateCapacityForVenue). Ici on
     * ferme le sens retour : rendre le gymnase indivisible alors que des créneaux y portent encore
     * une capacité ≥ 2. Toutes couches confondues (saison ET période : `findBy` par venueId ne
     * filtre pas sur `schedulePlanId`).
     *   - sans confirmation → 422, en NOMMANT les créneaux visés (jour + heure, aucun identifiant
     *     interne — cf. `PublicTextIsFreeOfInternalIdentifiersTest`) ;
     *   - avec confirmation (`VenueInput::confirmSplitCascade`) → cascade atomique déléguée à
     *     `EntityCascadeDeleter` : chaque créneau retombe à 1, perd son libellé, et voit ses
     *     réservations (+ verrous HARD matérialisés) vidées. Le planning est marqué périmé par le
     *     seul write du gymnase (ResourceChangeStaleScheduleListener::venueTouched → club+saison).
     */
    private function guardSplitTransition(Venue $venue, bool $confirmed): void
    {
        $overCapacity = array_values(array_filter(
            $this->entityManager->getRepository(VenueTrainingSlot::class)->findBy(['venueId' => $venue->getId()]),
            static fn (VenueTrainingSlot $slot): bool => $slot->getCapacity() >= 2,
        ));

        if ([] === $overCapacity) {
            return; // aucun créneau incohérent : décocher « divisible » passe librement
        }

        if (!$confirmed) {
            // `refuse()` construit une VRAIE liste de violations (le normaliseur d'ApiPlatform
            // reconstruit `detail`/`violations` DEPUIS elle — un message-chaîne nu y ressortirait
            // vide, et le toast du front, qui lit `detail`, n'afficherait rien).
            $this->refuse($this->splitCascadeMessage($venue, $overCapacity));
        }

        $this->cascadeDeleter?->clampSplitSlotsAndClearPins($overCapacity);
    }

    /**
     * @param list<VenueTrainingSlot> $overCapacity
     */
    private function splitCascadeMessage(Venue $venue, array $overCapacity): string
    {
        $days = [1 => 'lundi', 2 => 'mardi', 3 => 'mercredi', 4 => 'jeudi', 5 => 'vendredi', 6 => 'samedi', 7 => 'dimanche'];
        $creneaux = implode(', ', array_map(
            static fn (VenueTrainingSlot $slot): string => ($days[$slot->getDayOfWeek()] ?? 'jour ' . $slot->getDayOfWeek()) . ' ' . $slot->getStartTime()->format('H:i'),
            $overCapacity,
        ));

        return \sprintf(
            'Le gymnase « %s » ne peut pas devenir indivisible : des créneaux y accueillent encore 2 équipes ou plus (%s). Confirmez pour les ramener à 1 équipe et vider leurs réservations, ou laissez « terrain divisible » coché.',
            $venue->getName(),
            $creneaux,
        );
    }
}
