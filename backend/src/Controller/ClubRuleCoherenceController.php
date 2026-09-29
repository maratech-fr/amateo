<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\MatchConstraint;
use App\Entity\Team;
use App\Entity\TeamMatchHabit;
use App\Enum\ConstraintScope;
use App\Service\ClubRuleCoherenceChecker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * P4-272 ③ (ajout fondateur) — l'ALERTE DE COHÉRENCE règles club ⇄ créneaux idéaux,
 * lecture seule et calculée (rien stocké, rien bloqué). MAISON UNIQUE partagée par
 * les deux écrans : section Club de l'écran des contraintes (`byRule`) et écran
 * Semaine type / créneaux idéaux (`byHabit`). Scopé club+saison par les filtres
 * Doctrine ; le croisement vit dans {@see ClubRuleCoherenceChecker}.
 */
final class ClubRuleCoherenceController extends AbstractController
{
    use ResolvesCurrentClubTrait;

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClubRuleCoherenceChecker $checker,
    ) {}

    #[Route('/api/match-constraints/coherence', name: 'api_match_constraints_coherence', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $clubId = $this->resolveCurrentClubId($this->requestStack);
        if (null === $clubId) {
            return $this->json(['error' => 'No club in context.'], Response::HTTP_BAD_REQUEST);
        }

        /** @var list<MatchConstraint> $clubRules */
        $clubRules = $this->entityManager->getRepository(MatchConstraint::class)->findBy(['scope' => ConstraintScope::CLUB]);
        /** @var list<TeamMatchHabit> $habits */
        $habits = $this->entityManager->getRepository(TeamMatchHabit::class)->findBy([]);
        /** @var list<Team> $teams */
        $teams = $this->entityManager->getRepository(Team::class)->findBy([]);

        return $this->json($this->checker->check($clubRules, $habits, $teams));
    }
}
