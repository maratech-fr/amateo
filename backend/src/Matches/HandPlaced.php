<?php

declare(strict_types=1);

namespace App\Matches;

use App\Entity\Competition;
use App\Entity\Fixture;
use App\Enum\CompetitionType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * La seule porte vers « ce match est-il placé à la main ? » : un amical ou une coupe n'est jamais confié au moteur
 * (lot 7, Q6 et Q7). Non placé, il sort de la demande de placement ; posé (par le système compris), c'est une ancre ;
 * le retour du moteur n'écrit jamais dessus. Lue par `MatchPlacementPayloadBuilder` et `MatchPlacementResultApplier`.
 * Un match dont la compétition est introuvable n'est pas placé à la main : le doute le laisse au moteur, comme avant.
 */
final readonly class HandPlaced
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function is(Fixture $fixture): bool
    {
        $competitionId = $fixture->getCompetitionId();
        if (null === $competitionId) {
            return false;
        }
        $type = $this->entityManager->find(Competition::class, $competitionId)?->getCompetitionType();

        return CompetitionType::FRIENDLY === $type || CompetitionType::CUP === $type;
    }
}
