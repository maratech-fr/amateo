<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\SchedulePlanProvisioner;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

/**
 * L'empreinte de la structure COURANTE d'un plan — le signal « structure modifiée » du cockpit,
 * PAR PLAN (ADR-0002). La valeur est EXACTEMENT le `snapshotHash` qu'une version fraîchement
 * générée (socle) ou transcrite (période) pose : empreinte identique ⇒ la structure n'a pas bougé
 * depuis la version pointée (bouton « Régénérer » honnêtement grisé) ; différente ⇒ elle a bougé.
 * La dérivation vit en MAISON UNIQUE dans {@see SchedulePlanProvisioner::structureHashOfPlan}.
 *
 * ── LECTURE, ouverte au Membre ─────────────────────────────────────────────────────────────────
 * PAS de garde management (patron {@see SocleDeviationController}), PAS d'écriture (ni persist ni
 * flush), donc PAS de `SeasonScopedWriteInterface`. Le contexte est lu en SQL brut (filter-free) :
 * la RLS scope déjà le club au niveau base — un plan d'un AUTRE club rend un contexte null. On
 * re-vérifie le club en défense, et on répond 404 (jamais 403) dans les DEUX cas — plan inconnu ET
 * plan d'un autre club — pour ne jamais devenir un oracle d'existence. Un id malformé (22P02 sur la
 * colonne guid) est attrapé et rendu comme le même 404.
 */
#[AsController]
final class SchedulePlanStructureHashController extends AbstractController
{
    use ResolvesCurrentClubTrait;

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly SchedulePlanProvisioner $schedulePlanProvisioner,
    ) {}

    #[Route('/api/schedule_plans/{id}/structure-hash', name: 'api_schedule_plan_structure_hash', methods: ['GET'])]
    public function __invoke(string $id): JsonResponse
    {
        try {
            $context = $this->schedulePlanProvisioner->fetchPlanContext($id);
        } catch (Throwable) {
            $context = null; // id malformé → 22P02 : même 404, jamais un 500 Postgres.
        }

        $currentClubId = $this->resolveCurrentClubId($this->requestStack);
        if (null === $context || (null !== $currentClubId && $context['clubId'] !== $currentClubId)) {
            return $this->json(['error' => 'Plan introuvable.'], Response::HTTP_NOT_FOUND);
        }

        return $this->json(['currentStructureHash' => $this->schedulePlanProvisioner->structureHashOfPlan($id)]);
    }
}
