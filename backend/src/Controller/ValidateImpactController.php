<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Schedule;
use App\Service\OrphanedFixtureFinder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

/**
 * P2-52 (RMM-10) — ce que la VALIDATION d'un planning va faire perdre : les matchs pointant une
 * salle disparue du club (gymnase supprimé, ou pointeur laissé pendouillant par une exploration
 * « Charger cette version »). L'écran interroge cette route AVANT de confirmer : N=0 → « Valider »
 * part direct comme aujourd'hui, aucun bruit ; N>0 → l'annonce « N matchs (dont X déjà déclarés à
 * la fédération) perdront leur salle ».
 *
 * PARITÉ PAR CONSTRUCTION : le MÊME prédicat ({@see OrphanedFixtureFinder}) sert cette annonce ET
 * la gâchette de validation ({@see ValidateScheduleController}) — la route ne peut pas annoncer
 * autre chose que ce que la validation dépointe.
 *
 * ── LECTURE, ouverte au Membre (patron {@see DeletionImpactController}/{@see SocleDeviationController})
 * PAS de garde management, aucune écriture. Défense club en profondeur : RLS + filtre Doctrine
 * scopent déjà le club (find rend null pour un autre club → 404), et on re-vérifie le club (403).
 * AUCUN identifiant interne servi — seulement deux comptes.
 */
#[AsController]
final class ValidateImpactController extends AbstractController
{
    use ResolvesCurrentClubTrait;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RequestStack $requestStack,
        private readonly OrphanedFixtureFinder $orphanedFixtureFinder,
    ) {}

    #[Route('/api/schedules/{id}/validate-impact', name: 'api_schedule_validate_impact', methods: ['GET'])]
    public function __invoke(string $id): JsonResponse
    {
        try {
            $schedule = $this->entityManager->getRepository(Schedule::class)->find($id);
        } catch (Throwable) {
            $schedule = null;
        }
        if (!$schedule instanceof Schedule) {
            return $this->json(['error' => 'Planning introuvable.'], Response::HTTP_NOT_FOUND);
        }

        // FAIL-CLOSED (revue sécurité 2026-08-26, patron durci AUD-BCK-17 de
        // DeletionImpactController) : un contexte club IRRÉSOLU refuse — une défense
        // en profondeur qui s'éteint sans bruit ne défend rien le jour où on compte
        // sur elle (si le listener tenant ne stampe plus, le filtre Doctrine est
        // probablement éteint aussi, et ce guard devient la seule couche applicative).
        $currentClubId = $this->resolveCurrentClubId($this->requestStack);
        if (null === $currentClubId) {
            return $this->json(['error' => 'Contexte club introuvable.'], Response::HTTP_BAD_REQUEST);
        }
        if ($schedule->getClubId() !== $currentClubId) {
            return $this->json(['error' => 'Accès refusé.'], Response::HTTP_FORBIDDEN);
        }

        return $this->json($this->orphanedFixtureFinder->impact($schedule->getClubId(), $schedule->getSeasonId()));
    }
}
