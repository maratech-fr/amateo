<?php

declare(strict_types=1);

namespace App\Controller\Basketball;

use App\Access\Audience;
use App\Access\ClubFunction;
use App\Access\Module;
use App\Controller\ResolvesCurrentClubTrait;
use App\Repository\ClubRepository;
use App\Service\Basketball\FfbbClubPopulator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

/**
 * Lot C: on-demand FFBB (re)fill of the caller's club institutional data, from
 * its FFBB code. Same service as the async register hook (FfbbClubPopulator).
 * Management-gated (SEC-07). Intended re-use: the forthcoming superadmin refresh.
 */
#[AsController]
#[ClubFunction(module: Module::CLUB, audience: Audience::MANAGER)]
final class FfbbImportController extends AbstractController
{
    use ResolvesCurrentClubTrait;

    public function __construct(
        private readonly ClubRepository $clubRepository,
        private readonly FfbbClubPopulator $populator,
        private readonly RequestStack $requestStack,
    ) {}

    #[Route('/api/club/ffbb-import', name: 'club_ffbb_import', methods: ['POST'])]
    public function __invoke(): JsonResponse
    {
        $clubId = $this->resolveCurrentClubId($this->requestStack);
        if (null === $clubId) {
            return $this->json(['error' => 'No club in context.'], Response::HTTP_BAD_REQUEST);
        }
        $club = $this->clubRepository->find($clubId);
        if (null === $club) {
            return $this->json(['error' => 'Club not found.'], Response::HTTP_NOT_FOUND);
        }

        try {
            // Explicit action → refresh: re-fetch and overwrite stale reference data.
            $populated = $this->populator->populate($club, refresh: true);
        } catch (Throwable) {
            // Best-effort: an external failure is not the caller's fault.
            return $this->json(['populated' => false, 'error' => 'FFBB indisponible, réessayez plus tard.'], Response::HTTP_BAD_GATEWAY);
        }

        return $this->json([
            'populated' => $populated,
            'club' => [
                'address' => $club->getAddress(),
                'postalCode' => $club->getPostalCode(),
                'city' => $club->getCity(),
                'contactPhone' => $club->getContactPhone(),
                'contactEmail' => $club->getContactEmail(),
                'website' => $club->getWebsite(),
                'committeeCode' => $club->getCommitteeCode(),
                'logoUrl' => $club->getLogoUrl(),
            ],
        ]);
    }
}
