<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\CalendarEntry;
use App\Entity\Schedule;
use App\Entity\Season;
use App\Service\ManagementAccessGuard;
use App\Service\OverlayManager;
use App\Service\SchedulePlanProvisioner;
use App\Service\WriteTargetSeasonResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

/**
 * Reopen the version a plan points at → the plan un-points it and becomes an
 * "espace de travail" again, editable (ADR-0002 inv. 2). The inverse of
 * ValidateScheduleController. Reopening the season plan's chosen version while
 * period overlays exist destroys them (inv. 14) — guarded by a 409 the client
 * confirms with {"confirmDeleteOverlays": true}. See planning-lifecycle-validated.md.
 */
final class ReopenScheduleController extends AbstractController implements SeasonScopedWriteInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RequestStack $requestStack,
        private readonly OverlayManager $overlayManager,
        private readonly ManagementAccessGuard $managementAccessGuard,
        private readonly SchedulePlanProvisioner $schedulePlanProvisioner,
        private readonly WriteTargetSeasonResolver $writeTargetSeasonResolver,
    ) {}

    // SEC-13 — la cible est le Schedule nommé dans l'URL (id de version).
    public function writeTargetSeasonId(Request $request): ?string
    {
        $id = $request->attributes->get('id');

        return \is_string($id) ? $this->writeTargetSeasonResolver->ofSchedule($id) : null;
    }

    #[Route('/api/schedules/{id}/reopen', name: 'api_schedule_reopen', methods: ['POST'])]
    public function __invoke(string $id): JsonResponse
    {
        $this->managementAccessGuard->assertManager(); // SEC-07

        try {
            $schedule = $this->entityManager->getRepository(Schedule::class)->find($id);
        } catch (Throwable) {
            $schedule = null;
        }

        if (!$schedule instanceof Schedule) {
            return $this->json(['error' => 'Planning introuvable.'], Response::HTTP_NOT_FOUND);
        }

        $currentClubId = $this->resolveCurrentClubId();
        if (null !== $currentClubId && $schedule->getClubId() !== $currentClubId) {
            return $this->json(['error' => 'Accès refusé.'], Response::HTTP_FORBIDDEN);
        }

        // Tout ce qui décide, puis le dépointage, sous le verrou de portée du plan et
        // DANS la transaction — même raison qu'à la validation, qui sérialise sur ce
        // verrou ET supprime des versions dessous. Sans lui, un reopen lisait « je suis
        // la version choisie » sur une photo qu'une validation concurrente invalidait
        // aussitôt, puis annonçait 200 alors que son UPDATE ne touchait plus rien : le
        // gestionnaire croyait son planning rouvert et se faisait refuser chaque édition.
        return $this->entityManager->wrapInTransaction(function () use ($schedule): JsonResponse {
            $this->schedulePlanProvisioner->lockPlanScope($this->schedulePlanProvisioner->planScopeOf($schedule));

            // ADR-0002 inv. 1/2 : « validé » = le plan pointe cette version. Rouvrir =
            // dépointer. Une version que le plan ne pointe pas n'a rien à rouvrir.
            if (!$this->schedulePlanProvisioner->isChosen($schedule->getId())) {
                return $this->json(['error' => 'Seule la version choisie d\'un planning peut être rouverte.'], Response::HTTP_CONFLICT);
            }

            // Garde destructive : dépointer le calendrier de base invalide les plans
            // secondaires bâtis dessus (inv. 14). Clé sur le pointeur, comme le release
            // qu'elle protège — les clé sur deux vérités différentes laissait un reopen
            // orphaniser des plans secondaires vivants sans jamais demander confirmation.
            if ($schedule->getId() === $this->schedulePlanProvisioner->chosenOfSeasonPlan($schedule->getSeasonId())) {
                $overlays = $this->overlayManager->periodPlansInvalidatedBySeasonChange($schedule->getClubId(), $schedule->getSeasonId());
                if ([] !== $overlays) {
                    if (!$this->confirmedDeleteOverlays()) {
                        return $this->json([
                            'code' => 'overlays_exist',
                            'error' => 'Rouvrir le planning de la saison supprime les plannings de période à venir, qui devront être refaits.',
                            'count' => \count($overlays),
                            'overlays' => array_map(static fn (CalendarEntry $e): array => [
                                'entryId' => $e->getId(),
                                'title' => $e->getTitle(),
                            ], $overlays),
                        ], Response::HTTP_CONFLICT);
                    }
                    foreach ($overlays as $entry) {
                        // Le PLAN entier (versions + grille copiée + réglages) : rouvrir le
                        // socle périme la copie dont chaque période était partie.
                        // force: the user explicitly confirmed destroying the overlays,
                        // the one in force included (this IS the authorized destructive path).
                        $this->overlayManager->deletePeriodPlanForEntry($entry, force: true);
                    }
                }
            }

            // inv. 2 : rouvrir dépointe — le plan redevient un espace de travail.
            if (!$this->schedulePlanProvisioner->releaseSchedule($schedule->getId())) {
                // Aucun plan ne la pointait plus : ne pas annoncer une réouverture qui
                // n'a pas eu lieu (le 200 mensonger laissait le gestionnaire éditer un
                // planning que le serveur refusait à chaque geste).
                return $this->json(['error' => 'Ce planning n\'est plus la version en vigueur — rechargez la page.'], Response::HTTP_CONFLICT);
            }

            return $this->json(['id' => $schedule->getId(), 'chosen' => false], Response::HTTP_OK);
        });
    }

    private function confirmedDeleteOverlays(): bool
    {
        $request = $this->requestStack->getCurrentRequest();
        $content = $request?->getContent();
        if (!\is_string($content) || '' === $content) {
            return false;
        }
        $data = json_decode($content, true);

        return \is_array($data) && true === ($data['confirmDeleteOverlays'] ?? false);
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
