<?php

declare(strict_types=1);

namespace App\Controller;

use App\AdminJob\AdminJobMonitoringService;
use App\Service\AdminAudienceService;
use App\Service\AdminCapacityService;
use App\Service\AdminDataFreshnessService;
use App\Service\AdminHealthService;
use App\Service\AdminMonitoringService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/admin')]
final readonly class AdminMonitoringController
{
    public function __construct(
        private AdminMonitoringService $monitoring,
        private AdminHealthService $health,
        private AdminJobMonitoringService $jobs,
        private AdminDataFreshnessService $freshness,
        private AdminCapacityService $capacity,
        private AdminAudienceService $audience,
    ) {}

    #[Route('/health', methods: ['GET'])]
    public function health(): JsonResponse
    {
        return new JsonResponse($this->health->health());
    }

    /** Data-freshness board : « mes données de référence sont-elles à jour ? » (lecture seule). */
    #[Route('/freshness', methods: ['GET'])]
    public function freshness(): JsonResponse
    {
        return new JsonResponse(['items' => $this->freshness->referentials()]);
    }

    #[Route('/overview', methods: ['GET'])]
    public function overview(): JsonResponse
    {
        return new JsonResponse($this->monitoring->overview());
    }

    /** Capacity board : tenue du système sous charge, lue de la télémétrie (lecture seule). */
    #[Route('/capacity', methods: ['GET'])]
    public function capacity(): JsonResponse
    {
        return new JsonResponse($this->capacity->capacity());
    }

    /** Mesure d'audience de la vitrine (Umami) : répond TOUJOURS 200, même Umami absent/KO. */
    #[Route('/audience', methods: ['GET'])]
    public function audience(): JsonResponse
    {
        return new JsonResponse($this->audience->audience());
    }

    #[Route('/jobs', methods: ['GET'])]
    public function jobs(): JsonResponse
    {
        return new JsonResponse($this->jobs->jobs());
    }

    #[Route('/clubs', methods: ['GET'])]
    public function clubs(Request $request): JsonResponse
    {
        $page = $request->query->getInt('page', 1);
        $limit = $request->query->getInt('limit', 25);
        $query = $request->query->get('query');

        if ($page < 1 || $limit < 1 || $limit > 100 || (null !== $query && mb_strlen($query) > 100)) {
            return new JsonResponse(['error' => 'Invalid pagination or query parameters.'], 400);
        }

        return new JsonResponse($this->monitoring->clubs($page, $limit, $query));
    }
}
