<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Geo\VenueGeoCheck;
use App\Service\ManagementAccessGuard;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Contrôle de cohérence de la position des gymnases rattachés à une salle FFBB
 * (étude BCCL 2026-09-30). LECTURE SEULE, best-effort : renvoie les gymnases du
 * club+saison courant dont le point enregistré paraît incohérent avec l'adresse
 * fédérale de leur salle (autre rue, ou trop loin du point exact). Management-gated
 * (SEC-07) comme les autres proxies géo ; le tenant vient du JWT (aucun en-tête
 * client, AUD-SEC-25). Aucune écriture, jamais bloquant : le verdict est calculé
 * côté serveur, le front l'AFFICHE sans rien recalculer.
 */
#[AsController]
final class VenueGeoCheckController extends AbstractController
{
    use ResolvesCurrentClubTrait;

    public function __construct(
        private readonly VenueGeoCheck $venueGeoCheck,
        private readonly ManagementAccessGuard $managementAccessGuard,
        private readonly RequestStack $requestStack,
    ) {}

    // priority: 10 — sinon « geo-check » serait avalé comme un {id} par la route item
    // `/api/venues/{id}` d'API Platform (même piège que `/api/venues/fbi-labels`).
    #[Route('/api/venues/geo-check', name: 'api_venues_geo_check', methods: ['GET'], priority: 10)]
    public function __invoke(Request $request): JsonResponse
    {
        $this->managementAccessGuard->assertManager(); // SEC-07

        $clubId = $this->resolveCurrentClubId($this->requestStack);
        $seasonId = $request->attributes->get('_season_id') ?? $request->headers->get('X-Season-Id');
        if (null === $clubId || !\is_string($seasonId) || '' === $seasonId) {
            return $this->json(['error' => 'Club ou saison introuvable dans le contexte.'], Response::HTTP_BAD_REQUEST);
        }

        return $this->json($this->venueGeoCheck->check($clubId, $seasonId));
    }
}
