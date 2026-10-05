<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ClubUser;
use App\Entity\User;
use App\Enum\AuditAction;
use App\Repository\ClubUserRepository;
use App\Service\AuditTrail;
use App\Service\SeasonAccessGuard;
use App\Service\SeasonDataPurger;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
#[Route('/api/reset-season', name: 'reset_season', methods: ['DELETE'])]
final class ResetSeasonController extends AbstractController
{
    use ResolvesCurrentClubTrait;

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly ClubUserRepository $clubUserRepository,
        private readonly SeasonDataPurger $seasonDataPurger,
        private readonly SeasonAccessGuard $seasonAccessGuard,
        private readonly AuditTrail $auditTrail,
    ) {}

    public function __invoke(): JsonResponse
    {
        $request = $this->requestStack->getCurrentRequest();
        // Club resolved server-side from the caller's JWT membership (no header
        // since AUD-SEC-25); X-Season-Id stays a legitimate selector.
        $clubId = $this->resolveCurrentClubId($this->requestStack);
        $seasonId = $this->resolveIdentifier($request?->attributes->get('_season_id'), $request?->headers->get('X-Season-Id'));

        if (null === $clubId || null === $seasonId) {
            return $this->json(['error' => 'Missing club or season context.'], Response::HTTP_BAD_REQUEST);
        }

        // Wiping the whole club is a management action: gate on an active
        // management membership (mirrors ImportController/ClubStateProcessor).
        // The tenant listener already resolved _club_id from the caller's JWT
        // membership, so a non-member cannot reach another club here.
        $user = $this->getUser();
        $membership = $user instanceof User
            ? $this->clubUserRepository->findActiveMembership($user->getId(), $clubId)
            : null;
        if (!$membership instanceof ClubUser || !$this->clubUserRepository->isManagementRole($membership->getRole())) {
            return $this->json(['error' => 'Cette action est réservée aux gestionnaires.'], Response::HTTP_FORBIDDEN);
        }

        // Archived-season write refused (409) — AFTER the management-role gate
        // so authorization (403) precedes the state conflict. Inline (not the
        // SeasonReadonlyGuardListener) precisely because this endpoint has its
        // own 403 the listener would otherwise shadow.
        $this->seasonAccessGuard->assertWritable($request);

        // Wipe the season's contents but KEEP the Season row (the club keeps
        // its current season, only re-emptied).
        $deleted = $this->seasonDataPurger->purge($clubId, $seasonId, deleteSeasonRow: false);
        $this->auditTrail->record(AuditAction::SEASON_RESET, $user->getId(), $clubId, 'Season', $seasonId, ['rowsDeleted' => $deleted]);

        return $this->json([
            'status' => 'ok',
            'clubId' => $clubId,
            'seasonId' => $seasonId,
            'deleted' => $deleted,
        ]);
    }

    private function resolveIdentifier(mixed $attribute, mixed $header = null): ?string
    {
        foreach ([$attribute, $header] as $value) {
            if (\is_string($value) && '' !== $value) {
                return $value;
            }
        }

        return null;
    }
}
