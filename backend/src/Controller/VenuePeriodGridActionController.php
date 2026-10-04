<?php

declare(strict_types=1);

namespace App\Controller;

use App\Enum\SchedulePlanType;
use App\Service\ManagementAccessGuard;
use App\Service\SchedulePlanProvisioner;
use App\Service\VenuePeriodGrid;
use App\Service\WriteTargetSeasonResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;

/**
 * Les deux ACTIONS de grille d'un gymnase pour UNE période (#8, PR-B), servies par le
 * même contrôleur car elles ne diffèrent que par le geste final :
 *  - `reset-grid` : vider PUIS recopier le modèle de saison (« reprendre la grille du
 *    planning principal ») ;
 *  - `clear-grid` : vider seulement (« partir d'une grille vierge »).
 *
 * Ce sont des ACTIONS, pas des états. Router « vider » par un PUT de mode BLANK serait
 * piégé : au build, seul DISABLED agit — BLANK est un no-op — et le processeur ignore
 * volontairement un PUT de mode INCHANGÉ (garde d'idempotence, revue #8 round 4). Vider
 * un gymnase déjà « vierge » ne ferait donc RIEN, tout en affichant « vidé » : un mensonge
 * d'écran (revue #8 PR-B). Une action dédiée, atomique et idempotente, l'évite par
 * construction — elle vide à chaque appel, quel que soit le mode en place.
 *
 * DESTRUCTIF et assumé : vider ou reprendre emporte les créneaux du gymnase pour cette
 * période, donc leurs réservations et les verrous qu'elles avaient matérialisés. C'est
 * l'UI qui l'annonce avant.
 */
#[AsController]
final class VenuePeriodGridActionController extends AbstractController implements SeasonScopedWriteInterface
{
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly ManagementAccessGuard $managementAccessGuard,
        private readonly SchedulePlanProvisioner $schedulePlanProvisioner,
        private readonly VenuePeriodGrid $venuePeriodGrid,
        private readonly WriteTargetSeasonResolver $writeTargetSeasonResolver,
    ) {}

    /**
     * SEC-13 — ⚠ la cible (le plan de période) vit dans le CORPS, pas dans l'URL :
     * c'est ce cas qui interdit un mécanisme fondé sur la seule route. On dérive la
     * saison du plan nommé par `schedulePlanId` (les deux actions le portent).
     */
    public function writeTargetSeasonId(Request $request): ?string
    {
        $decoded = json_decode((string) $request->getContent(), true);
        $planId = \is_array($decoded) ? ($decoded['schedulePlanId'] ?? null) : null;

        return \is_string($planId) && '' !== $planId ? $this->writeTargetSeasonResolver->ofSchedulePlan($planId) : null;
    }

    public function __invoke(): JsonResponse
    {
        $this->managementAccessGuard->assertManager(); // SEC-07

        $request = $this->requestStack->getCurrentRequest();
        $payload = $this->decode($request);
        $schedulePlanId = $payload['schedulePlanId'] ?? null;
        $venueId = $payload['venueId'] ?? null;
        if (!\is_string($schedulePlanId) || '' === $schedulePlanId || !\is_string($venueId) || '' === $venueId) {
            return $this->json(['error' => 'schedulePlanId et venueId sont requis.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $context = $this->schedulePlanProvisioner->fetchPlanContext($schedulePlanId);
        if (null === $context) {
            return $this->json(['error' => 'Planning introuvable.'], Response::HTTP_NOT_FOUND);
        }

        // Le club vient du PLAN, jamais du corps de la requête : accepter un plan d'un
        // autre club recopierait/viderait sa grille chez nous (et inversement).
        $currentClubId = $this->resolveCurrentClubId();
        if (null !== $currentClubId && $context['clubId'] !== $currentClubId) {
            return $this->json(['error' => 'Accès refusé.'], Response::HTTP_FORBIDDEN);
        }

        // Invariant fondateur n°1 : le planning principal n'est JAMAIS modifié par une
        // période. Visées sur le plan de SAISON, ces actions videraient la grille du club.
        if (SchedulePlanType::SEASON === $context['type']) {
            return $this->json(['error' => 'La grille se règle sur une période, pas sur le planning de la saison.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ('reset_venue_period_grid' === $request?->attributes->get('_route')) {
            $this->venuePeriodGrid->resetFromSeason($schedulePlanId, $venueId);
        } else {
            $this->venuePeriodGrid->clearInTransaction($schedulePlanId, $venueId);
        }

        return $this->json(['schedulePlanId' => $schedulePlanId, 'venueId' => $venueId], Response::HTTP_OK);
    }

    /** @return array<string, mixed> */
    private function decode(?Request $request): array
    {
        $decoded = json_decode((string) $request?->getContent(), true);

        return \is_array($decoded) ? $decoded : [];
    }

    private function resolveCurrentClubId(): ?string
    {
        $request = $this->requestStack->getCurrentRequest();

        $clubId = $request?->attributes->get('_club_id');
        if (\is_string($clubId) && '' !== $clubId) {
            return $clubId;
        }

        return null;
    }
}
