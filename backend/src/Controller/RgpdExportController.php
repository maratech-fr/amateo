<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ClubUser;
use App\Entity\User;
use App\Enum\AuditAction;
use App\Repository\ClubUserRepository;
use App\Service\AuditTrail;
use App\Service\RgpdExportService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * RGPD — portabilité (art. 20), deux périmètres :
 * - GET /api/me/export : ses propres données de compte (self-only, aucun id).
 * - GET /api/club/export : le workspace du club COURANT (tenant résolu du JWT
 *   par le listener — pas d'id de chemin), réservé aux rôles management
 *   (SEC-07) : c'est le club, responsable de traitement de ses données, qui
 *   exerce la portabilité — pas n'importe quel membre.
 */
#[AsController]
final class RgpdExportController extends AbstractController
{
    use ResolvesCurrentClubTrait;

    public function __construct(
        private readonly RgpdExportService $exportService,
        private readonly ClubUserRepository $clubUserRepository,
        private readonly RequestStack $requestStack,
        private readonly RateLimiterFactory $rgpdExportLimiter,
        private readonly AuditTrail $auditTrail,
    ) {}

    #[Route('/api/me/export', name: 'api_me_export', methods: ['GET'])]
    public function exportMe(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }
        if (($throttled = $this->throttle($user)) instanceof JsonResponse) {
            return $throttled;
        }

        // Audit APRÈS la génération (revue PR-4) : un export qui échoue ne doit
        // pas laisser une trace append-only affirmant qu'il a été remis.
        $data = $this->exportService->exportUser($user);
        $this->auditTrail->record(AuditAction::EXPORT_USER, $user->getId());

        return $this->downloadable($data, 'mes-donnees');
    }

    #[Route('/api/club/export', name: 'api_club_export', methods: ['GET'])]
    public function exportClub(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        if (($throttled = $this->throttle($user)) instanceof JsonResponse) {
            return $throttled;
        }

        $clubId = $this->resolveCurrentClubId($this->requestStack);
        if (null === $clubId) {
            return $this->json(['error' => 'Club not found.'], Response::HTTP_NOT_FOUND);
        }

        // Même sémantique que les autres écritures/gates club (SEC-04/07) :
        // pas de membership actif → 404 ; membre non-management → 403.
        $membership = $this->clubUserRepository->findActiveMembership($user->getId(), $clubId);
        if (!$membership instanceof ClubUser) {
            return $this->json(['error' => 'Club not found.'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->clubUserRepository->isManagementRole($membership->getRole())) {
            return $this->json(['error' => 'Forbidden.'], Response::HTTP_FORBIDDEN);
        }

        $data = $this->exportService->exportClub($clubId);
        $this->auditTrail->record(AuditAction::EXPORT_CLUB, $user->getId(), $clubId);

        return $this->downloadable($data, 'donnees-club');
    }

    /**
     * Encodage UNIQUE (revue PR-2 : ->json() + setEncodingOptions = 3 passes
     * encode/decode/encode sur le plus gros body de l'app). Pas de pretty-print
     * non plus : c'est un export machine-readable, le pretty doublerait la
     * mémoire pour rien.
     *
     * @param array<string, mixed> $data
     */
    private function downloadable(array $data, string $basename): JsonResponse
    {
        $response = JsonResponse::fromJsonString(json_encode($data, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE));
        $response->headers->set('Content-Disposition', \sprintf('attachment; filename="%s-%s.json"', $basename, date('Y-m-d')));
        // Un export de portabilité est NOMINATIF (données personnelles) : jamais mis en cache
        // (navigateur ou cache partagé), sinon il survit sur un poste partagé. Patron
        // ExportPdfDownloadController.
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }

    /** 429 si le quota dédié export (10/h par utilisateur) est épuisé. */
    private function throttle(User $user): ?JsonResponse
    {
        $limit = $this->rgpdExportLimiter->create($user->getId())->consume();
        if (!$limit->isAccepted()) {
            return $this->json(['error' => 'Trop d\'exports — réessayez plus tard.'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        return null;
    }
}
