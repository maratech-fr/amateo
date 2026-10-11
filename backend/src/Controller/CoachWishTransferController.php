<?php

declare(strict_types=1);

namespace App\Controller;

use App\Exception\ScheduleGenerationInProgressException;
use App\Service\CoachWishTransferService;
use App\Service\ManagementAccessGuard;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * P2-63 PR 5 — « Transférer au planning » : convertit en masse les doléances COCHÉES par le
 * gestionnaire en réglages du plan de leur semaine ({@see CoachWishTransferService}).
 *
 * Geste GESTIONNAIRE seulement (SEC-07, `assertManager` FALSIFIÉE par un test d'abus) : la
 * page publique à jeton n'écrit que des souhaits, jamais un objet de plan (§3bis). 409 si une
 * génération tourne (Q-B). Écriture de COLLECTION : la saison courante gouverne
 * (`writeTargetSeasonId` → null, choix écrit — SeasonScopedWriteInterface).
 */
#[AsController]
final class CoachWishTransferController extends AbstractController implements SeasonScopedWriteInterface
{
    use ResolvesCurrentClubTrait;

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly ManagementAccessGuard $managementAccessGuard,
        private readonly CoachWishTransferService $transferService,
    ) {}

    // SEC-13 — écriture de COLLECTION : aucune ressource ciblée dans l'URL, la saison courante
    // (header) gouverne, comme les autres POST de collection.
    public function writeTargetSeasonId(Request $request): ?string
    {
        return null;
    }

    #[Route('/api/coach-wishes/transfer', name: 'api_coach_wishes_transfer', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $this->managementAccessGuard->assertManager(); // SEC-07

        $clubId = $this->resolveCurrentClubId($this->requestStack);
        if (null === $clubId) {
            return $this->json(['error' => 'Club introuvable.'], Response::HTTP_FORBIDDEN);
        }

        // B5 — la saison du gestionnaire (en-tête X-Season-Id, validée côté serveur par
        // TenantFilterListener qui pose `_season_id`). Le service ignore toute doléance d'une
        // autre saison. Absente = anomalie sur une écriture authentifiée → refus.
        $seasonId = $request->attributes->get('_season_id');
        if (!\is_string($seasonId) || '' === $seasonId) {
            return $this->json(['error' => 'Saison introuvable.'], Response::HTTP_FORBIDDEN);
        }

        $wishIds = $this->readWishIds($request);
        if (null === $wishIds) {
            return $this->json(['error' => 'La liste des doléances à transférer est invalide.'], Response::HTTP_BAD_REQUEST);
        }
        // B3 — plafond dur : un lot au-delà de 500 doléances n'est pas un geste de gestionnaire,
        // c'est un abus O(N) sur un endpoint d'écriture. 400 NOMMÉ avant toute transaction.
        if (\count($wishIds) > 500) {
            return $this->json(['error' => 'Trop de doléances à transférer (maximum 500).'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $recap = $this->transferService->transfer($clubId, $seasonId, $wishIds);
        } catch (ScheduleGenerationInProgressException) {
            // Q-B — transférer pendant une génération écraserait son résultat.
            return $this->json([
                'code' => 'generation_in_progress',
                'error' => 'Une génération est en cours — attendez sa fin avant de transférer.',
            ], Response::HTTP_CONFLICT);
        }

        return $this->json($recap, Response::HTTP_OK);
    }

    /**
     * Le corps attend `{ "wishIds": ["…", "…"] }` — une liste non vide de chaînes. Rend null
     * si le corps est absent/mal formé, une liste (filtrée des non-chaînes) sinon.
     *
     * @return list<string>|null
     */
    private function readWishIds(Request $request): ?array
    {
        $content = $request->getContent();
        if ('' === $content) {
            return null;
        }
        $data = json_decode($content, true);
        if (!\is_array($data) || !\is_array($data['wishIds'] ?? null)) {
            return null;
        }

        $ids = [];
        foreach ($data['wishIds'] as $id) {
            if (\is_string($id) && '' !== $id) {
                $ids[] = $id;
            }
        }

        return [] === $ids ? null : $ids;
    }
}
