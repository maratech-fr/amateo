<?php

declare(strict_types=1);

namespace App\State\Processor;

use App\ApiResource\ReservationResource;
use App\Dto\ReservationInput;
use App\Entity\Reservation;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\User;
use App\Enum\AuditAction;
use App\Enum\LockLevel;
use App\Service\PlanVenueClosures;
use App\Service\ReservationGroupOccupancy;
use DateTimeImmutable;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * @extends AbstractStateProcessor<Reservation, ReservationInput, ReservationResource>
 */
class ReservationStateProcessor extends AbstractStateProcessor
{
    use AssertsSchedulePlanExistsTrait;

    private PlanVenueClosures $planVenueClosures;

    private ReservationGroupOccupancy $reservationGroupOccupancy;

    #[Required]
    public function setPlanVenueClosures(PlanVenueClosures $planVenueClosures): void
    {
        $this->planVenueClosures = $planVenueClosures;
    }

    #[Required]
    public function setReservationGroupOccupancy(ReservationGroupOccupancy $reservationGroupOccupancy): void
    {
        $this->reservationGroupOccupancy = $reservationGroupOccupancy;
    }

    protected function getEntityClass(): string
    {
        return Reservation::class;
    }

    /**
     * Deleting a reservation must UNDO its pin. A reservation is echoed HARD in
     * the solver output and materialised by ScheduleResultImporter as a durable
     * HARD ScheduleSlotTemplate; findBaseSlotTemplates would otherwise re-inject
     * that orphaned pin on every future generation, so purge the matching
     * materialised template(s) alongside the reservation.
     */
    protected function processDelete(array $uriVariables, ?string $clubId): void
    {
        $reservation = $this->entityManager->find(Reservation::class, $uriVariables['id'] ?? null);
        if (!$reservation instanceof Reservation) {
            throw new NotFoundHttpException('Ressource introuvable.');
        }
        if (null !== $clubId && $reservation->getClubId() !== $clubId) {
            throw new AccessDeniedHttpException('Accès refusé.');
        }

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
            $this->entityManager->remove($template);
        }

        $this->entityManager->remove($reservation);
        $this->entityManager->flush();

        // Ce override court-circuite parent::processDelete → il doit émettre
        // lui-même la trace RGPD (revue PR-4 : angle mort du choke point).
        $actor = $this->actorSecurity?->getUser();
        $this->auditTrail?->record(
            AuditAction::ENTITY_DELETED,
            $actor instanceof User ? $actor->getId() : null,
            $clubId,
            'Reservation',
            $reservation->getId(),
        );
    }

    /**
     * @param ReservationInput $input
     */

    /**
     * Le flush a lieu dans le socle : la violation de FK d'une suppression de plan
     * CONCURRENTE ne peut être rattrapée qu'ici, autour de l'appel parent.
     *
     * @param ReservationInput $input
     */
    protected function processPost(object $input, ?string $clubId, ?string $seasonId): object
    {
        return $this->rejectingConcurrentPlanDeletion(fn (): object => parent::processPost($input, $clubId, $seasonId));
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param ReservationInput     $input
     */
    protected function processPut(object $input, array $uriVariables, ?string $clubId, ?string $seasonId): object
    {
        return $this->rejectingConcurrentPlanDeletion(fn (): object => parent::processPut($input, $uriVariables, $clubId, $seasonId));
    }

    protected function createEntityFromInput(object $input): Reservation
    {
        // clubId + seasonId are set by AbstractStateProcessor from the tenant/season
        // context. No SEC-07 management gate — reservations are a wizard write, like
        // constraints and venue slots.
        $entity = new Reservation;
        if (null !== $input->teamId) {
            $entity->setTeamId($input->teamId);
        }
        if (null !== $input->venueId) {
            $entity->setVenueId($input->venueId);
        }
        if (null !== $input->dayOfWeek) {
            $entity->setDayOfWeek($input->dayOfWeek);
        }
        if (null !== $input->startTime) {
            $entity->setStartTime($input->startTime);
        }
        if (null !== $input->durationMinutes) {
            $entity->setDurationMinutes($input->durationMinutes);
        }
        $this->assertSchedulePlanExists($this->entityManager, $input->schedulePlanId);
        $this->assertVenueOpen($input->schedulePlanId, $input->venueId, $input->dayOfWeek);

        // P2-46 PR-2 — la garde symétrique de l'occupation exclusive : une réservation INDIVIDUELLE
        // est refusée si la case porte déjà un groupe COMPLET (règle b) ou si sa capacité est
        // dépassée (règle e).
        $this->assertOccupancy($input);

        $entity->setSchedulePlanId($input->schedulePlanId);

        return $entity;
    }

    /**
     * @param Reservation      $entity
     * @param ReservationInput $input
     */
    protected function updateEntityFromInput(object $entity, object $input): void
    {
        // No PUT operation — reservations are created or deleted, not edited.
    }

    /**
     * @param Reservation $entity
     */
    protected function mapEntityToOutput(object $entity): ReservationResource
    {
        return ReservationResource::fromEntity($entity);
    }

    /**
     * On ne réserve pas un gymnase que la période rend indisponible CE jour-là. Décision fondateur
     * 2026-08-18 : l'indisponibilité est INFORMATIVE, l'état effectif fait foi, on refuse à la
     * SOURCE (422) sans toucher aux réservations déjà posées (l'alerte vit côté récap,
     * `unservedReservationIds`). La logique et la copie sont la MAISON UNIQUE
     * {@see PlanVenueClosures::assertVenueOpenForPlan} — partagée avec le rail batch de mutualisation.
     */
    private function assertVenueOpen(?string $schedulePlanId, ?string $venueId, ?int $dayOfWeek): void
    {
        $this->planVenueClosures->assertVenueOpenForPlan($schedulePlanId, $venueId, $dayOfWeek);
    }

    /**
     * Rules (b)+(e) via la MAISON UNIQUE {@see ReservationGroupOccupancy}. Quatre retours anticipés
     * SÉPARÉS (et non un `&&` que Rector réécrirait en `in_array`, perdant le narrowing non-null
     * dont a besoin `assertIndividualReservationAllowed(string, …)`) : les champs SONT non-null ici,
     * validés par `ReservationInput` avant le processor, mais le garde reste explicite pour PHPStan.
     */
    private function assertOccupancy(ReservationInput $input): void
    {
        if (null === $input->teamId) {
            return;
        }
        if (null === $input->venueId) {
            return;
        }
        if (null === $input->dayOfWeek) {
            return;
        }
        if (!$input->startTime instanceof DateTimeImmutable) {
            return;
        }
        $this->reservationGroupOccupancy->assertIndividualReservationAllowed($input->teamId, $input->venueId, $input->dayOfWeek, $input->startTime, $input->schedulePlanId);
    }
}
