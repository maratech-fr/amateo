<?php

declare(strict_types=1);

namespace App\Controller;

use App\Deletion\DeletionImpact;
use App\Deletion\DeletionImpactCounter;
use App\Entity\Coach;
use App\Entity\Team;
use App\Entity\Venue;
use App\Entity\VenueTrainingSlot;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * P3-16 — « qu'est-ce que je détruis si je supprime ça ? », répondu par le SERVEUR.
 *
 * ⚑ Ces routes existent parce que l'écran ne peut pas répondre : sur les étapes du wizard il
 * n'a chargé ni les matchs, ni les contraintes, ni les séances des autres plannings. Il
 * comptait donc depuis son cache react-query et annonçait une fraction de ce que la cascade
 * emporte. La règle maison est claire (`.claude/rules/frontend.md`) : le front AFFICHE ce que
 * le backend a calculé, il ne le redérive pas.
 *
 * Lecture seule, sans effet de bord. La frontière tenant est explicite ici (le club courant
 * doit posséder l'entité) EN PLUS de RLS et du filtre Doctrine — même défense en profondeur
 * que les contrôleurs d'export.
 */
final class DeletionImpactController extends AbstractController
{
    use ResolvesCurrentClubTrait;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RequestStack $requestStack,
        private readonly DeletionImpactCounter $counter,
    ) {}

    #[Route('/api/venues/{id}/deletion-impact', name: 'api_venue_deletion_impact', methods: ['GET'])]
    public function venue(string $id): JsonResponse
    {
        $venue = $this->entityManager->getRepository(Venue::class)->find($id);
        if (!$venue instanceof Venue) {
            return $this->notFound();
        }
        if (($denied = $this->denyForeignClub($venue->getClubId())) instanceof JsonResponse) {
            return $denied;
        }

        return $this->serialize($this->counter->forVenue($venue));
    }

    #[Route('/api/teams/{id}/deletion-impact', name: 'api_team_deletion_impact', methods: ['GET'])]
    public function team(string $id): JsonResponse
    {
        $team = $this->entityManager->getRepository(Team::class)->find($id);
        if (!$team instanceof Team) {
            return $this->notFound();
        }
        if (($denied = $this->denyForeignClub($team->getClubId())) instanceof JsonResponse) {
            return $denied;
        }

        return $this->serialize($this->counter->forTeam($team));
    }

    #[Route('/api/coaches/{id}/deletion-impact', name: 'api_coach_deletion_impact', methods: ['GET'])]
    public function coach(string $id): JsonResponse
    {
        $coach = $this->entityManager->getRepository(Coach::class)->find($id);
        if (!$coach instanceof Coach) {
            return $this->notFound();
        }
        if (($denied = $this->denyForeignClub($coach->getClubId())) instanceof JsonResponse) {
            return $denied;
        }

        return $this->serialize($this->counter->forCoach($coach));
    }

    #[Route('/api/venue_training_slots/{id}/deletion-impact', name: 'api_venue_training_slot_deletion_impact', methods: ['GET'])]
    public function slot(string $id): JsonResponse
    {
        $slot = $this->entityManager->getRepository(VenueTrainingSlot::class)->find($id);
        if (!$slot instanceof VenueTrainingSlot) {
            return $this->notFound();
        }
        if (($denied = $this->denyForeignClub($slot->getClubId())) instanceof JsonResponse) {
            return $denied;
        }

        return $this->serialize($this->counter->forSlot($slot));
    }

    private function notFound(): JsonResponse
    {
        return $this->json(['error' => 'Not found.'], Response::HTTP_NOT_FOUND);
    }

    /** `null` quand l'entité appartient bien au club courant. */
    private function denyForeignClub(?string $ownerClubId): ?JsonResponse
    {
        $currentClubId = $this->resolveCurrentClubId($this->requestStack);

        // AUD-BCK-17 — fail-CLOSED sur contexte dégradé. Avant, un club non résolu faisait
        // passer la garde en silence : une défense en profondeur qui s'éteint sans bruit ne
        // défend rien le jour où on compte sur elle. Le TenantFilter et RLS couvrent déjà ce
        // cas (sans GUC posé, la base ne rend rien), mais cette garde-ci est justement là
        // pour ne pas dépendre d'eux. Même réponse que le contrôleur voisin dans la même
        // situation (`VenueUsageStatsController:63-65`) : on répond pareil à une même cause.
        if (null === $currentClubId) {
            return $this->json(['error' => 'No club in context.'], Response::HTTP_BAD_REQUEST);
        }

        if ($ownerClubId !== $currentClubId) {
            return $this->json(['error' => 'Accès refusé.'], Response::HTTP_FORBIDDEN);
        }

        return null;
    }

    private function serialize(DeletionImpact $impact): JsonResponse
    {
        return $this->json([
            'blocked' => $impact->blocked,
            'reason' => $impact->reason,
            'lines' => $impact->lines,
            'slotsInForce' => $impact->slotsInForce,
            'placedFixtures' => $impact->placedFixtures,
            'declaredFixtures' => $impact->declaredFixtures,
        ]);
    }
}
