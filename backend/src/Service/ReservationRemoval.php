<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Reservation;
use App\Entity\ScheduleSlotTemplate;
use App\Enum\LockLevel;
use App\Enum\SchedulePlanType;
use App\State\Processor\ReservationStateProcessor;
use Doctrine\ORM\EntityManagerInterface;

/**
 * MAISON UNIQUE du retrait d'une réservation — partagée par le DELETE de l'API
 * ({@see ReservationStateProcessor}) et le retrait système d'une fermeture de gymnase
 * ({@see ClosurePinRemover}, « Enregistrer vaut geste »).
 *
 * Retirer une réservation, c'est (1) emporter TOUTE sa case si elle est « bloc-complète »
 * (décision fondateur P2-62, discernement de {@see ReservationGroupOccupancy::blockCompleteCaseSiblings}),
 * et (2) défaire son épinglage : une réservation revient HARD de la sortie du solveur et
 * `ScheduleResultImporter` la matérialise en verrou HARD durable ; sans purge, la greffe des
 * verrous la réinjecterait à chaque génération.
 *
 * ⚠ La purge des verrous matérialisés est BORNÉE aux versions du PLAN de la réservation (le socle
 * pour une réservation de saison) : le même triplet équipe·gymnase·jour·heure peut porter un verrou
 * HARD dans une AUTRE portée — typiquement le socle, quand c'est une réservation de période qui part
 * — et ce verrou-là n'appartient pas à la réservation retirée.
 *
 * Écrit dans l'UnitOfWork (`remove`), jamais de flush : l'appelant flushe une fois, dans sa
 * transaction.
 */
final class ReservationRemoval
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ReservationGroupOccupancy $reservationGroupOccupancy,
    ) {}

    /**
     * @return list<Reservation> les réservations effectivement retirées (la case entière si bloc-complète)
     */
    public function remove(Reservation $reservation): array
    {
        $toRemove = $this->reservationGroupOccupancy->blockCompleteCaseSiblings($reservation);
        if ([] === $toRemove) {
            $toRemove = [$reservation];
        }
        foreach ($toRemove as $target) {
            $this->purgeMaterialisedHardTemplates($target);
            $this->entityManager->remove($target);
        }

        return $toRemove;
    }

    /**
     * Les verrous HARD matérialisés par l'import sur la même case — jamais ceux d'une version d'un
     * AUTRE plan (le socle, quand c'est une réservation de période qui part ; une période, quand
     * c'est une réservation du socle).
     */
    private function purgeMaterialisedHardTemplates(Reservation $reservation): void
    {
        // Lot 4bis — un créneau LIBRE (teamId null) n'a jamais été matérialisé en verrou HARD (il
        // n'entre pas dans `slotTemplates`) : rien à purger.
        if (null === $reservation->getTeamId()) {
            return;
        }
        $foreign = array_flip($this->scheduleIdsOfOtherPlans($reservation));
        $materialised = $this->entityManager->getRepository(ScheduleSlotTemplate::class)->findBy([
            'clubId' => $reservation->getClubId(),
            'seasonId' => $reservation->getSeasonId(),
            'teamId' => $reservation->getTeamId(),
            'venueId' => $reservation->getVenueId(),
            'dayOfWeek' => $reservation->getDayOfWeek(),
            'startTime' => $reservation->getStartTime(),
            'lockLevel' => LockLevel::HARD,
        ]);
        foreach ($materialised as $template) {
            if (!isset($foreign[$template->getScheduleId()])) {
                $this->entityManager->remove($template);
            }
        }
    }

    /**
     * Les versions des AUTRES plans de la saison que celui qui porte la réservation (son plan de
     * période, ou le plan SEASON pour une réservation du socle). SQL brut (hors `season_filter`),
     * RLS scope le club.
     *
     * @return list<string>
     */
    private function scheduleIdsOfOtherPlans(Reservation $reservation): array
    {
        $connection = $this->entityManager->getConnection();
        $planId = $reservation->getSchedulePlanId() ?? $connection->fetchOne(
            'SELECT id FROM schedule_plan WHERE season_id = :sid AND type = :season',
            ['sid' => $reservation->getSeasonId(), 'season' => SchedulePlanType::SEASON->value],
        );

        return array_map(strval(...), $connection->fetchFirstColumn(
            'SELECT s.id FROM schedule s JOIN schedule_plan p ON p.id = s.schedule_plan_id WHERE p.season_id = :sid AND p.id <> CAST(:pid AS uuid)',
            ['sid' => $reservation->getSeasonId(), 'pid' => \is_string($planId) ? $planId : '00000000-0000-0000-0000-000000000000'],
        ));
    }
}
