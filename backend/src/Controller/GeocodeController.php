<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Geo\BanGeocodingClient;
use App\Service\ManagementAccessGuard;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

/**
 * P2-53 RMM-8 — géocodage d'une adresse (BAN) pour poser la lat/long d'un gymnase.
 * Proxy obligatoire : le frontend n'appelle jamais un tiers directement (frontière
 * §2), et le mapping serveur ne relaie que les champs utiles (label + coords +
 * score), jamais le hit brut ni un identifiant interne. Management-gated (SEC-07)
 * comme les autres proxies ; best-effort : 502 nommé, jamais un geste cassé. Le
 * front la câblera plus tard — la route naît testée.
 */
#[AsController]
final class GeocodeController extends AbstractController
{
    public function __construct(
        private readonly BanGeocodingClient $geocoder,
        private readonly ManagementAccessGuard $managementAccessGuard,
    ) {}

    #[Route('/api/geocode', name: 'api_geocode', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $this->managementAccessGuard->assertManager(); // SEC-07

        $query = (string) $request->query->get('q', '');
        if (!BanGeocodingClient::isValidQuery($query)) {
            return $this->json(['error' => 'Renseignez une adresse à rechercher (3 à 200 caractères).'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $candidates = $this->geocoder->geocode($query);
        } catch (Throwable) {
            return $this->json(['error' => 'Service d\'adresses indisponible, réessayez plus tard.'], Response::HTTP_BAD_GATEWAY);
        }

        return $this->json(['candidates' => $candidates]);
    }

    /**
     * Reverse-geocoding : le LIBELLÉ d'adresse le plus proche de coordonnées posées (seed/FFBB)
     * quand aucune adresse n'a été saisie — pour AFFICHER de quoi vérifier un « Localisé » nu.
     * Management-gated (SEC-07), best-effort : `{label: null}` si la BAN ne renvoie rien ou
     * échoue (jamais un geste cassé), 422 sur des coordonnées invalides. Aucune écriture en base.
     */
    #[Route('/api/geocode/reverse', name: 'api_geocode_reverse', methods: ['GET'])]
    public function reverse(Request $request): JsonResponse
    {
        $this->managementAccessGuard->assertManager(); // SEC-07

        $latRaw = (string) $request->query->get('lat', '');
        $lonRaw = (string) $request->query->get('lon', '');
        if (!is_numeric($latRaw) || !is_numeric($lonRaw)) {
            return $this->json(['error' => 'Coordonnées invalides.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $latitude = (float) $latRaw;
        $longitude = (float) $lonRaw;
        if (!BanGeocodingClient::isValidCoordinate($latitude, $longitude)) {
            return $this->json(['error' => 'Coordonnées hors bornes.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(['label' => $this->geocoder->reverse($latitude, $longitude)]);
    }
}
