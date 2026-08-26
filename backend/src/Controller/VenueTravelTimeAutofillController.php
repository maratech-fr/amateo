<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\Geo\AutofillCapExceededException;
use App\Service\Geo\VenueTravelTimeAutofillService;
use App\Service\ManagementAccessGuard;
use App\Service\SeasonAccessGuard;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * P2-53 RMM-8 — l'autofill de la matrice de trajet. Le serveur relit venues+géos
 * EN BASE (jamais le client) et remplit AUTO les temps voiture/à pied via l'IGN.
 * Management-gated (SEC-07) + saison écrivable (archivée → 409). Une valeur MANUAL
 * n'est JAMAIS écrasée (VenueTravelTimeAutofillService). Cap dur → 422. Rate-limit
 * dédié PAR UTILISATEUR (la route déclenche une rafale d'appels sortants).
 */
#[AsController]
final class VenueTravelTimeAutofillController extends AbstractController
{
    use ResolvesCurrentClubTrait;

    public function __construct(
        private readonly VenueTravelTimeAutofillService $autofiller,
        private readonly ManagementAccessGuard $managementAccessGuard,
        private readonly SeasonAccessGuard $seasonAccessGuard,
        private readonly RequestStack $requestStack,
        private readonly RateLimiterFactory $venueTravelTimeAutofillLimiter,
    ) {}

    #[Route('/api/venue-travel-times/autofill', name: 'api_venue_travel_times_autofill', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        // SEC-07 first so 403 wins over the 409/429.
        $this->managementAccessGuard->assertManager();
        $this->seasonAccessGuard->assertWritable($request);

        $user = $this->getUser();
        if ($user instanceof User && !$this->venueTravelTimeAutofillLimiter->create($user->getId())->consume(1)->isAccepted()) {
            return $this->json(['error' => 'Trop de calculs de trajet — réessayez plus tard.'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $clubId = $this->resolveCurrentClubId($this->requestStack);
        $seasonId = $request->attributes->get('_season_id') ?? $request->headers->get('X-Season-Id');
        if (null === $clubId || !\is_string($seasonId) || '' === $seasonId) {
            return $this->json(['error' => 'Club ou saison introuvable dans le contexte.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $result = $this->autofiller->autofill($clubId, $seasonId);
        } catch (AutofillCapExceededException $e) {
            return $this->json([
                'error' => \sprintf('Trop de gymnases géolocalisés pour un remplissage automatique (%d paires, maximum %d). Renseignez les trajets à la main.', $e->pairs, $e->cap),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json($result);
    }
}
