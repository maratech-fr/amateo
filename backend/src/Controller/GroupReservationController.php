<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Reservation;
use App\Entity\SharedTrainingGroup;
use App\Entity\SharedTrainingGroupTeam;
use App\Service\ManagementAccessGuard;
use App\Service\PlanVenueClosures;
use App\Service\ReservationGroupOccupancy;
use App\State\Processor\AssertsSchedulePlanExistsTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * P2-46 PR-2 — LE RAIL D'ÉCRITURE BATCH d'un entraînement mutualisé : poser un groupe sur UNE
 * case, c'est écrire N réservations (une par membre) en UN SEUL flush. L'atomicité n'est pas un
 * confort : sans elle, un état semi-écrit rendrait l'exclusivité (règle a) invérifiable — une case
 * à moitié occupée par le groupe n'est ni « libre » ni « groupe-complète ».
 *
 * Le RETRAIT n'a pas de route dédiée : le lot se retire par N `DELETE /reservations/{id}` existants
 * (c'est l'écran qui empilera, PR-3).
 *
 * Gardes RÉUTILISÉES (jamais réécrites) : `AssertsSchedulePlanExistsTrait` (ancre de plan +
 * filet de la course FK), `PlanVenueClosures::assertVenueOpenForPlan` (gymnase fermé), le défaut
 * de rôle d'`AbstractStateProcessor` (SEC-07, via `ManagementAccessGuard`) et le garde de saison
 * archivée (`SeasonScopedWriteInterface`) — parité stricte avec `POST /reservations`.
 */
#[AsController]
final class GroupReservationController extends AbstractController implements SeasonScopedWriteInterface
{
    use AssertsSchedulePlanExistsTrait;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ManagementAccessGuard $managementAccessGuard,
        private readonly PlanVenueClosures $planVenueClosures,
        private readonly ReservationGroupOccupancy $reservationGroupOccupancy,
    ) {}

    /**
     * Écriture de COLLECTION (N réservations de la saison courante) : aucune ressource unique dont
     * dériver la saison, le header/saison courante gouverne — patron `ReorderTeamsController`, et
     * parité avec le POST de réservation unitaire (dont le processor cible aussi la saison courante).
     */
    public function writeTargetSeasonId(Request $request): ?string
    {
        return null;
    }

    #[Route('/api/reservations/group', name: 'api_reservations_group', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        // SEC-07 — parité stricte avec POST /reservations : le processor unitaire passe par le
        // défaut management d'AbstractStateProcessor. On réplique CE seul gate, rien de plus.
        $this->managementAccessGuard->assertManager();

        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            return $this->json(['error' => 'Invalid JSON body.'], Response::HTTP_BAD_REQUEST);
        }

        $groupId = $data['sharedTrainingGroupId'] ?? null;
        $venueId = $data['venueId'] ?? null;
        $dayOfWeek = $data['dayOfWeek'] ?? null;
        $startTimeRaw = $data['startTime'] ?? null;
        $durationRaw = $data['durationMinutes'] ?? 90;
        $schedulePlanId = $data['schedulePlanId'] ?? null;

        if (!\is_string($groupId) || !\is_string($venueId) || !\is_int($dayOfWeek) || !\is_string($startTimeRaw)) {
            return $this->json(['error' => 'Missing required field: sharedTrainingGroupId, venueId, dayOfWeek, startTime.'], Response::HTTP_BAD_REQUEST);
        }
        if (null !== $schedulePlanId && !\is_string($schedulePlanId)) {
            return $this->json(['error' => 'schedulePlanId must be a string.'], Response::HTTP_BAD_REQUEST);
        }
        try {
            $startTime = new DateTimeImmutable($startTimeRaw);
        } catch (Exception) {
            return $this->json(['error' => 'startTime must be a valid time (HH:MM).'], Response::HTTP_BAD_REQUEST);
        }
        $durationMinutes = \is_int($durationRaw) ? $durationRaw : 90;

        // Groupe résolu SOUS le filtre tenant (findOneBy, jamais find — un groupe d'un AUTRE club
        // devient introuvable, jamais un oracle d'existence). Leçon TeamLink « PR B ».
        $group = $this->entityManager->getRepository(SharedTrainingGroup::class)->findOneBy(['id' => $groupId]);
        if (!$group instanceof SharedTrainingGroup) {
            return $this->json(['error' => 'Groupe mutualisé introuvable.'], Response::HTTP_NOT_FOUND);
        }

        // PORTÉE : le groupe doit être celui de CE planning (socle null ↔ null, période ↔ même
        // plan). ⚠ Un groupe socle réservé en période produirait N verrous SANS bloc `sharedTrainings`
        // dans le payload de période (ScheduleConstraintBuilder ne lit que les groupes DU plan) —
        // exactement le faux diagnostic de sur-capacité que PR-1 vient d'éteindre.
        if ($group->getSchedulePlanId() !== $schedulePlanId) {
            return $this->json(['error' => 'Ce groupe mutualisé n\'appartient pas à ce planning : il a été déclaré pour une autre portée. Déclarez-le sur ce planning avant de le réserver ici.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $members = $this->memberTeamIds($group->getId());

        try {
            $this->assertSchedulePlanExists($this->entityManager, $schedulePlanId);
            $this->planVenueClosures->assertVenueOpenForPlan($schedulePlanId, $venueId, $dayOfWeek);
            $this->reservationGroupOccupancy->assertGroupReservationAllowed($group, $members, $venueId, $dayOfWeek, $startTime, $schedulePlanId);

            // Écriture ATOMIQUE : N réservations, UN flush. La transaction annule tout à la moindre
            // erreur ; le filet FK (suppression de plan CONCURRENTE) reprend le patron du processor
            // de réservation. Toute validation ayant précédé le persist, un refus laisse ZÉRO ligne.
            $ids = $this->rejectingConcurrentPlanDeletion(fn (): array => $this->entityManager->wrapInTransaction(
                fn (): array => $this->persistReservations($group, $members, $venueId, $dayOfWeek, $startTime, $durationMinutes, $schedulePlanId),
            ));
        } catch (UnprocessableEntityHttpException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(['ids' => $ids, 'count' => \count($ids)], Response::HTTP_CREATED);
    }

    /**
     * @param list<string> $members
     *
     * @return list<string> the created reservation ids
     */
    private function persistReservations(
        SharedTrainingGroup $group,
        array $members,
        string $venueId,
        int $dayOfWeek,
        DateTimeImmutable $startTime,
        int $durationMinutes,
        ?string $schedulePlanId,
    ): array {
        $created = [];
        foreach ($members as $teamId) {
            $reservation = (new Reservation)
                ->setClubId((string) $group->getClubId())
                ->setSeasonId($group->getSeasonId())
                ->setTeamId($teamId)
                ->setVenueId($venueId)
                ->setDayOfWeek($dayOfWeek)
                ->setStartTime($startTime)
                ->setDurationMinutes($durationMinutes)
                ->setSchedulePlanId($schedulePlanId);
            $this->entityManager->persist($reservation);
            $created[] = $reservation->getId();
        }
        $this->entityManager->flush();

        return $created;
    }

    /**
     * @return list<string>
     */
    private function memberTeamIds(string $groupId): array
    {
        return array_map(
            static fn (SharedTrainingGroupTeam $row): string => $row->getTeamId(),
            $this->entityManager->getRepository(SharedTrainingGroupTeam::class)->findBy(['groupId' => $groupId], ['teamId' => 'ASC']),
        );
    }
}
