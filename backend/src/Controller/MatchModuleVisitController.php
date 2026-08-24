<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\MatchModuleVisit;
use App\Entity\Season;
use App\Entity\User;
use App\Repository\MatchModuleVisitRepository;
use App\Service\MatchModuleDeltaComputer;
use App\Service\SeasonResolver;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Le « gardien » à l'ouverture du module matchs (RMM-3) — le geste net-neuf : à
 * chaque visite, l'app dit ce qui a CHANGÉ depuis la dernière fois (matchs arrivés,
 * conflits neufs, planning de saison qui a bougé). Le radar de conflits est
 * stateless ; ce contrôleur lui adjoint la persistance légère qui lui manquait —
 * un instantané de référence par (club, saison, UTILISATEUR).
 *
 * UN SEUL endpoint POST (pas de GET séparé : deux appels ouvriraient une course F5
 * — le delta doit être calculé PUIS la référence tournée dans le même geste). La
 * sémantique de rotation vit ici, le calcul du delta dans
 * {@see MatchModuleDeltaComputer} (coupé pour que RMM-6 lise sans stamper) :
 *  - PREMIÈRE visite : on fige la référence en SILENCE (rien à comparer) ;
 *  - hors fenêtre de grâce (last_opened_at > 30 min) : NOUVELLE visite — le delta
 *    est calculé contre l'ancienne référence PUIS la référence tourne (snapshot :=
 *    courant, reference_taken_at := maintenant) ;
 *  - dans la grâce : MÊME visite (fenêtre glissante) — le delta est calculé contre
 *    la référence NON tournée, seul last_opened_at avance. Un F5 rejoue donc les
 *    mêmes badges (idempotent), il ne les éteint pas.
 *
 * PAS de garde management : la visite est celle du Membre aussi (patron
 * FeedbackController — n'importe quel membre ouvre le module). La saison archivée
 * n'empêche PAS l'écriture : c'est un bookkeeping utilisateur, pas une mutation du
 * planning (décision fondateur).
 */
#[AsController]
final class MatchModuleVisitController extends AbstractController
{
    use ResolvesCurrentClubTrait;

    /** Fenêtre glissante d'une même visite : au-delà, l'ouverture suivante est une NOUVELLE visite. */
    private const int GRACE_MINUTES = 30;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RequestStack $requestStack,
        private readonly SeasonResolver $seasonResolver,
        private readonly MatchModuleVisitRepository $visitRepository,
        private readonly MatchModuleDeltaComputer $deltaComputer,
        private readonly ClockInterface $clock,
    ) {}

    #[Route('/api/matches/module-visit', name: 'api_match_module_visit', methods: ['POST'])]
    public function __invoke(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $clubId = $this->resolveCurrentClubId($this->requestStack);
        if (null === $clubId) {
            return $this->json(['error' => 'No club in context.'], Response::HTTP_BAD_REQUEST);
        }

        $season = $this->seasonResolver->selectedOrCurrent($this->requestStack->getCurrentRequest(), $clubId);
        if (!$season instanceof Season) {
            return $this->json(['error' => 'No season in context.'], Response::HTTP_BAD_REQUEST);
        }
        $seasonId = $season->getId();

        $now = DateTimeImmutable::createFromInterface($this->clock->now());
        $visit = $this->visitRepository->findForUser($user->getId());

        // Première visite : on fige la référence en silence, aucun badge.
        if (!$visit instanceof MatchModuleVisit) {
            $snapshot = $this->deltaComputer->currentSnapshot($clubId, $seasonId);
            $this->entityManager->persist(new MatchModuleVisit($clubId, $seasonId, $user->getId(), $snapshot, $now));
            $this->entityManager->flush();

            return $this->json([
                'firstVisit' => true,
                'newFixturesCount' => 0,
                'newConflictFingerprints' => [],
                'planningChanged' => false,
                'referenceTakenAt' => $now->format(DateTimeImmutable::ATOM),
            ]);
        }

        // Le delta est TOUJOURS calculé contre la référence en vigueur AVANT rotation.
        $referenceTakenAt = $visit->getReferenceTakenAt();
        $delta = $this->deltaComputer->computeDelta($clubId, $seasonId, $visit->getReferenceSnapshot(), $referenceTakenAt);

        // Hors grâce → NOUVELLE visite : la référence tourne sur l'état courant.
        if (($now->getTimestamp() - $visit->getLastOpenedAt()->getTimestamp()) > self::GRACE_MINUTES * 60) {
            $visit->setReferenceSnapshot($delta['currentSnapshot']);
            $visit->setReferenceTakenAt($now);
        }
        $visit->setLastOpenedAt($now);
        $this->entityManager->flush();

        return $this->json([
            'firstVisit' => false,
            'newFixturesCount' => $delta['newFixturesCount'],
            'newConflictFingerprints' => $delta['newConflictFingerprints'],
            'planningChanged' => $delta['planningChanged'],
            'referenceTakenAt' => $referenceTakenAt->format(DateTimeImmutable::ATOM),
        ]);
    }
}
