<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\User;
use App\Repository\ClubUserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * The shared gate of the two FFBB team-import endpoints (analyze + import).
 *
 * Extracted from ImportController so the dry-run (analyze) and the write (import)
 * answer BYTE-IDENTICALLY on every refusal. It is the SEC-04 sequence, keyed on
 * the {id} in the path (the operation is per-club):
 *   404 no active membership → 403 non-management → 409 archived season →
 *   429 rate-limited → then the XlsxUploadGuard bounds (400/413) and, last, the
 *   body seasonId ⇄ X-Season-Id match.
 *
 * ⚠ Unlike {@see FixtureImportGate}, it does NOT run the SocleGuard: importing
 * a club's teams is an onboarding gesture that happens BEFORE any season plan is
 * chosen — requiring a validated socle would 409 the very case it serves.
 *
 * Returns the tenant Club on success, or the error JsonResponse to relay.
 */
final class TeamImportGate
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ClubUserRepository $clubUserRepository,
        private readonly SeasonAccessGuard $seasonAccessGuard,
        private readonly Security $security,
        private readonly RateLimiterFactory $xlsxImportLimiter,
        private readonly XlsxUploadGuard $xlsxUploadGuard,
    ) {}

    public function gate(Request $request, string $id): Club|JsonResponse
    {
        // SEC-04: the tenant listener validates the header/JWT club, not the {id}
        // in the path. Gate on the caller's active membership FIRST — checking
        // club existence before membership would leak which club ids exist
        // (403 for an existing club vs 404 for a random uuid). Semantics mirror
        // ClubStateProcessor: no active membership → 404, member but not a
        // management role → 403.
        $user = $this->security->getUser();
        $membership = $user instanceof User
            ? $this->clubUserRepository->findActiveMembership($user->getId(), $id)
            : null;
        if (!$membership instanceof ClubUser) {
            return new JsonResponse(['error' => 'Club not found.'], Response::HTTP_NOT_FOUND);
        }
        if (!$this->clubUserRepository->isManagementRole($membership->getRole())) {
            return new JsonResponse(['error' => 'Forbidden.'], Response::HTTP_FORBIDDEN);
        }

        // Archived-season write refused (409) — AFTER auth so 403 wins first.
        $this->seasonAccessGuard->assertWritable($request);

        $club = $this->entityManager->getRepository(Club::class)->find($id);
        if (!$club instanceof Club) {
            return new JsonResponse(['error' => 'Club not found.'], Response::HTTP_NOT_FOUND);
        }

        // Anti-abus : borne PAR UTILISATEUR (JWT), consommée APRÈS l'auth pour que
        // 403/404/409 gagnent d'abord (SEC-22, même patron que FixtureImportGate).
        // $user est ici forcément un User (la garde de membership l'a établi).
        if (!$this->xlsxImportLimiter->create($user->getId())->consume(1)->isAccepted()) {
            return new JsonResponse(['error' => 'Trop d\'imports — réessayez plus tard.'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        return $club;
    }

    public function requireXlsxFile(Request $request): UploadedFile|JsonResponse
    {
        // Maison unique des bornes d'upload (SEC-22) : mime/extension + octets +
        // décompressé + lignes. Refus byte-identiques sur les deux endpoints.
        return $this->xlsxUploadGuard->requireXlsxFile($request);
    }

    /**
     * The body seasonId must match the listener-resolved season: the
     * season_filter scopes every read to the resolved one, so analysing/importing
     * into another season would defeat the importer's dedupe lookups (duplicates)
     * and produce rows invisible to the selected season. Shared so both endpoints
     * refuse byte-identically.
     */
    public function requireMatchingSeasonId(Request $request): string|JsonResponse
    {
        $seasonId = $request->request->get('seasonId');
        if (!\is_string($seasonId) || '' === $seasonId) {
            return new JsonResponse(['error' => 'seasonId is required.'], Response::HTTP_BAD_REQUEST);
        }

        $resolvedSeasonId = $request->attributes->get('_season_id');
        if (!\is_string($resolvedSeasonId) || $seasonId !== $resolvedSeasonId) {
            return new JsonResponse(
                ['error' => 'seasonId does not match the selected season (X-Season-Id).'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        return $seasonId;
    }
}
