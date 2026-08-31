<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Reservation;
use App\Entity\SharedTrainingBlock;
use App\Entity\SharedTrainingBlockTeam;
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
 *
 * P2-51 PR-5 — RÉ-ANCRAGE sur le {@see SharedTrainingBlock} (convergence PR-5/PR-7). Le rail vise
 * D'ABORD un bloc, puis RETOMBE sur un {@see SharedTrainingGroup} (transition douce, option a) : le
 * front actuel poste encore des groupes K sous `sharedTrainingGroupId` (`SlotReservationModal.tsx`,
 * PR-6 non encore livrée), une bascule sèche les casserait AVANT la PR-6. Un id de bloc arrive sous
 * le nouveau champ `sharedTrainingBlockId` (PR-6) ; le retrait du modèle groupe est PR-7. Les deux
 * portées, les deux jeux de gardes (`assertBlockReservationAllowed`/`assertGroupReservationAllowed`)
 * et l'atomicité N-réservations/1-flush sont IDENTIQUES entre bloc et groupe.
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

        $blockId = $data['sharedTrainingBlockId'] ?? null;
        $groupId = $data['sharedTrainingGroupId'] ?? null;
        $venueId = $data['venueId'] ?? null;
        $dayOfWeek = $data['dayOfWeek'] ?? null;
        $startTimeRaw = $data['startTime'] ?? null;
        $durationRaw = $data['durationMinutes'] ?? 90;
        $schedulePlanId = $data['schedulePlanId'] ?? null;

        // Option (a) transition PR-5/PR-7 — un id arrive sous `sharedTrainingBlockId` (PR-6) OU, le
        // temps de la transition, sous `sharedTrainingGroupId` (front actuel). Le bloc a priorité.
        $targetId = null;
        if (\is_string($blockId) && '' !== $blockId) {
            $targetId = $blockId;
        } elseif (\is_string($groupId) && '' !== $groupId) {
            $targetId = $groupId;
        }

        if (null === $targetId || !\is_string($venueId) || !\is_int($dayOfWeek) || !\is_string($startTimeRaw)) {
            return $this->json(['error' => 'Missing required field: sharedTrainingBlockId (or sharedTrainingGroupId), venueId, dayOfWeek, startTime.'], Response::HTTP_BAD_REQUEST);
        }
        // ⚠ FORME de l'UUID pré-validée : un id malformé ne doit JAMAIS atteindre Postgres.
        // Les colonnes visées sont des `uuid` natifs — `WHERE id = 'abc'` y lève un 22P02, donc
        // un 500 là où le rail unitaire rend un 422 propre (`ReservationInput` porte
        // `#[Assert\Uuid]`). C'est une classe de défaut que le dépôt documente DEUX fois
        // (`AssertsSchedulePlanExistsTrait`, `TenantFilterListener::findClubSeason`) ; ce rail
        // la réintroduisait. Relevé en revue de sécurité, 2026-08-23.
        if (!$this->isUuid($targetId) || !$this->isUuid($venueId)) {
            return $this->json(['error' => 'Identifiant invalide.'], Response::HTTP_BAD_REQUEST);
        }
        if (null !== $schedulePlanId && (!\is_string($schedulePlanId) || !$this->isUuid($schedulePlanId))) {
            return $this->json(['error' => 'Identifiant de planning invalide.'], Response::HTTP_BAD_REQUEST);
        }
        // PARITÉ des bornes avec `ReservationInput` (#[Assert\Range]) : sans elles, `dayOfWeek: 8`
        // ou une durée de 5000 min s'écrivent en base et DÉGRADENT le solve en silence (le schéma
        // moteur ne borne pas `day_of_week`), tandis qu'un entier hors SMALLINT lève un 500 au
        // flush. Un rail batch ne peut pas être plus permissif que son rail unitaire.
        if ($dayOfWeek < 1 || $dayOfWeek > 7) {
            return $this->json(['error' => 'Le jour doit être compris entre 1 (lundi) et 7 (dimanche).'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $durationMinutes = \is_int($durationRaw) ? $durationRaw : 90;
        if ($durationMinutes < 15 || $durationMinutes > 300) {
            return $this->json(['error' => 'La durée doit être comprise entre 15 et 300 minutes.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        try {
            $startTime = new DateTimeImmutable($startTimeRaw);
        } catch (Exception) {
            return $this->json(['error' => 'startTime must be a valid time (HH:MM).'], Response::HTTP_BAD_REQUEST);
        }

        // Option (a) — le BLOC d'abord (sous le filtre tenant), sinon on retombe sur le GROUPE.
        $block = $this->entityManager->getRepository(SharedTrainingBlock::class)->findOneBy(['id' => $targetId]);
        if ($block instanceof SharedTrainingBlock) {
            return $this->reserveBlock($block, $venueId, $dayOfWeek, $startTime, $durationMinutes, $schedulePlanId);
        }

        // Groupe résolu SOUS le filtre tenant (findOneBy, jamais find — un groupe d'un AUTRE club
        // devient introuvable, jamais un oracle d'existence). Leçon TeamLink « PR B ».
        $group = $this->entityManager->getRepository(SharedTrainingGroup::class)->findOneBy(['id' => $targetId]);
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
                fn (): array => $this->persistReservations((string) $group->getClubId(), $group->getSeasonId(), $members, $venueId, $dayOfWeek, $startTime, $durationMinutes, $schedulePlanId),
            ));
        } catch (UnprocessableEntityHttpException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(['ids' => $ids, 'count' => \count($ids)], Response::HTTP_CREATED);
    }

    /**
     * Forme d'UUID — motif canonique du dépôt (`TenantFilterListener::isUuid`). Ne juge QUE la
     * forme : l'existence et la portée restent tranchées par les lookups sous filtre tenant.
     */
    private function isUuid(string $value): bool
    {
        return 1 === preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value);
    }

    /**
     * @param list<string> $members
     *
     * @return list<string> the created reservation ids
     */
    private function persistReservations(
        string $clubId,
        string $seasonId,
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
                ->setClubId($clubId)
                ->setSeasonId($seasonId)
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

    /**
     * Réserver un BLOC (P2-51 PR-5) : parité STRICTE avec le rail groupe inline — même portée, même
     * jeu de gardes, même atomicité N-réservations/1-flush. Seuls diffèrent l'entité résolue, le
     * chargement des membres et le garde d'occupation ({@see ReservationGroupOccupancy::assertBlockReservationAllowed}).
     */
    private function reserveBlock(SharedTrainingBlock $block, string $venueId, int $dayOfWeek, DateTimeImmutable $startTime, int $durationMinutes, ?string $schedulePlanId): JsonResponse
    {
        // PORTÉE : le bloc doit être celui de CE planning (socle null ↔ null, période ↔ même plan) —
        // un bloc socle réservé en période produirait N verrous SANS son bloc `sharedBlocks` dans le
        // payload de période (faux diagnostic de sur-capacité), exactement comme le groupe.
        if ($block->getSchedulePlanId() !== $schedulePlanId) {
            return $this->json(['error' => 'Cette mutualisation n\'appartient pas à ce planning : elle a été déclarée pour une autre portée. Déclarez-la sur ce planning avant de la réserver ici.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $members = $this->blockMemberTeamIds($block->getId());

        try {
            $this->assertSchedulePlanExists($this->entityManager, $schedulePlanId);
            $this->planVenueClosures->assertVenueOpenForPlan($schedulePlanId, $venueId, $dayOfWeek);
            $this->reservationGroupOccupancy->assertBlockReservationAllowed($block, $members, $venueId, $dayOfWeek, $startTime, $schedulePlanId);

            // Écriture ATOMIQUE : N réservations, UN flush (patron du rail groupe). Un refus laisse ZÉRO ligne.
            $ids = $this->rejectingConcurrentPlanDeletion(fn (): array => $this->entityManager->wrapInTransaction(
                fn (): array => $this->persistReservations((string) $block->getClubId(), $block->getSeasonId(), $members, $venueId, $dayOfWeek, $startTime, $durationMinutes, $schedulePlanId),
            ));
        } catch (UnprocessableEntityHttpException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(['ids' => $ids, 'count' => \count($ids)], Response::HTTP_CREATED);
    }

    /**
     * @return list<string>
     */
    private function blockMemberTeamIds(string $blockId): array
    {
        return array_map(
            static fn (SharedTrainingBlockTeam $row): string => $row->getTeamId(),
            $this->entityManager->getRepository(SharedTrainingBlockTeam::class)->findBy(['blockId' => $blockId], ['teamId' => 'ASC']),
        );
    }
}
