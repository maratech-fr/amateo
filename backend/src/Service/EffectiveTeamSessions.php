<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Team;
use App\Entity\TeamPeriodOverride;
use Doctrine\ORM\EntityManagerInterface;

/**
 * MAISON UNIQUE du « nombre de séances hebdomadaires EFFECTIF » d'une équipe pour une portée
 * de plan donnée : l'override de PÉRIODE ({@see TeamPeriodOverride}) l'emporte quand il fixe une
 * valeur, sinon le volume de base de l'équipe. Deux consommateurs en dépendent — la déclaration de
 * mutualisation ({@see SharedTrainingBlockStateProcessor}, qui borne les séances communes) et la garde d'occupation
 * ({@see ReservationGroupOccupancy}, qui borne le nombre de réservations par membre) — et deux
 * définitions qui divergeraient laisseraient passer, ici ou là, une réservation que le solveur
 * refusera ensuite loin de sa cause.
 */
final class EffectiveTeamSessions
{
    public function __construct(private readonly EntityManagerInterface $entityManager) {}

    public function perWeek(Team $team, ?string $schedulePlanId): int
    {
        if (null !== $schedulePlanId) {
            $override = $this->entityManager->getRepository(TeamPeriodOverride::class)
                ->findOneBy(['schedulePlanId' => $schedulePlanId, 'teamId' => $team->getId()]);
            if ($override instanceof TeamPeriodOverride && null !== $override->getSessionsPerWeek()) {
                return $override->getSessionsPerWeek();
            }
        }

        return $team->getSessionsPerWeek();
    }
}
