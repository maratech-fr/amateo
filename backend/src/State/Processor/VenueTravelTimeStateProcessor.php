<?php

declare(strict_types=1);

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use App\ApiResource\VenueTravelTimeResource;
use App\Dto\VenueTravelTimeInput;
use App\Entity\PlaceTravelTime;
use App\Entity\Venue;
use App\Enum\VenueTravelTimeSource;
use LogicException;

/**
 * CRUD d'un temps de trajet entre deux gymnases (P2-53 RMM-8). Management-gated par défaut
 * (AbstractStateProcessor). Le temps vit au club, entre les LIEUX des deux gymnases de la saison de la
 * requête (lot 3, PR 5) : le corriger dans une saison le corrige dans toutes celles où ces lieux ont un
 * gymnase. Le couple est rangé (A–B ≡ B–A) et TOUTE valeur écrite ICI est une saisie gestionnaire →
 * source MANUAL : une valeur MANUAL n'est jamais écrasée par le calcul automatique
 * (VenueTravelTimeAutofillService).
 *
 * Une saison archivée n'écrit pas : le temps ne porte pas de saison, la garde de la classe mère (qui ne
 * s'applique qu'aux entités de saison) est donc posée ici, sur la saison de la requête.
 *
 * @extends AbstractStateProcessor<PlaceTravelTime, VenueTravelTimeInput, VenueTravelTimeResource>
 */
class VenueTravelTimeStateProcessor extends AbstractStateProcessor
{
    /** @var array{0: string, 1: string}|null les deux gymnases de la saison qui nomment le couple écrit, rangés */
    private ?array $venues = null;

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        $this->seasonAccessGuard->assertWritable($this->requestStack->getCurrentRequest());

        return parent::process($data, $operation, $uriVariables, $context);
    }

    protected function getEntityClass(): string
    {
        return PlaceTravelTime::class;
    }

    /**
     * @param VenueTravelTimeInput $input
     */
    protected function createEntityFromInput(object $input): PlaceTravelTime
    {
        $entity = new PlaceTravelTime;
        $this->applyInput($entity, $input);

        return $entity;
    }

    /**
     * @param PlaceTravelTime      $entity
     * @param VenueTravelTimeInput $input
     */
    protected function updateEntityFromInput(object $entity, object $input): void
    {
        $this->applyInput($entity, $input);
    }

    /**
     * @param PlaceTravelTime $entity
     */
    protected function mapEntityToOutput(object $entity): VenueTravelTimeResource
    {
        if (null === $this->venues) {
            throw new LogicException('Le couple de gymnases du temps de trajet écrit est inconnu.');
        }

        return VenueTravelTimeResource::fromEntity($entity, $this->venues[0], $this->venues[1]);
    }

    private function applyInput(PlaceTravelTime $entity, VenueTravelTimeInput $input): void
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

        // Foreign/unknown venues resolve to null through the tenant+season
        // filters → 422 (`findOneBy`, never `find()` — the tenant idiom).
        $venueRepository = $this->entityManager->getRepository(Venue::class);
        $venues = [];
        foreach ([$venueAId, $venueBId] as $venueId) {
            $venue = $venueRepository->findOneBy(['id' => $venueId]);
            if (!$venue instanceof Venue) {
                $this->refuse('Gymnase inconnu pour ce club.');
            }
            $venues[] = $venue;
        }
        $entity->setClubId($venues[0]->getClubId())->setPlaces($venues[0]->getPlaceId(), $venues[1]->getPlaceId());
        $this->venues = [$venueAId, $venueBId];

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

        // One couple = one travel time (readable 422; the DB unique is the backstop).
        $existing = $this->entityManager->getRepository(PlaceTravelTime::class)->findOneBy([
            'placeAId' => $entity->getPlaceAId(),
            'placeBId' => $entity->getPlaceBId(),
        ]);
        if ($existing instanceof PlaceTravelTime && $existing->getId() !== $entity->getId()) {
            $this->refuse('Ces deux gymnases ont déjà un barème de trajet — modifiez le barème existant.');
        }
    }
}
