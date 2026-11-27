<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\FederalTracking;
use App\Entity\Fixture;
use App\Matches\FederalFollowUp;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;

/**
 * Poser une salle non nulle sur un match éteint le libellé de salle gardé de son suivi fédéral
 * ({@see FederalTracking::followVenue}) — quel que soit l'écrivain de la salle (formulaire, placement du système,
 * import, canal API, rattachement). Depuis que le libellé gardé a quitté la ligne du match, le setter de la salle ne
 * le voit plus : la règle se tient ici, au flush, sur le changement de `venueId`.
 */
#[AsDoctrineListener(event: Events::onFlush)]
final readonly class KeptVenueLabelListener
{
    public function __construct(private FederalFollowUp $followUp) {}

    public function onFlush(OnFlushEventArgs $args): void
    {
        $entityManager = $args->getObjectManager();
        $unitOfWork = $entityManager->getUnitOfWork();
        foreach ($unitOfWork->getScheduledEntityUpdates() as $fixture) {
            if (!$fixture instanceof Fixture) {
                continue;
            }
            $venueChange = $unitOfWork->getEntityChangeSet($fixture)['venueId'] ?? null;
            if (null === $venueChange || null === $venueChange[1]) {
                continue;
            }
            $tracking = $this->followUp->trackingOf($fixture);
            if (!$tracking instanceof FederalTracking || null === $tracking->getKeptVenueLabel()) {
                continue;
            }
            $tracking->followVenue($venueChange[1]);
            $metadata = $entityManager->getClassMetadata(FederalTracking::class);
            if ($unitOfWork->isScheduledForInsert($tracking) || $unitOfWork->isScheduledForUpdate($tracking)) {
                $unitOfWork->recomputeSingleEntityChangeSet($metadata, $tracking);
            } else {
                $unitOfWork->computeChangeSet($metadata, $tracking);
            }
        }
    }
}
