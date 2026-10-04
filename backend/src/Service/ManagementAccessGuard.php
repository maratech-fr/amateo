<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ClubUser;
use App\Entity\User;
use App\Repository\ClubUserRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * SEC-07: authorization guard for management-only write endpoints.
 *
 * The tenant listener already proved the caller holds an *active* membership on
 * the current club (the club is derived from the JWT membership — AUD-SEC-25).
 * This guard adds the missing
 * layer for the write choke points that only compared the club id
 * (validate/reopen/manual-edit/generate/reorder/appearance):
 * the membership must be a **management** role (owner/admin).
 *
 * Benign today (every member is `admin`), but it is the boundary that keeps the
 * cockpit closed to the forthcoming non-management `coach` role. Mirrors
 * SeasonAccessGuard: a single injectable choke point instead of the idiom
 * duplicated across controllers.
 *
 * 403 (not 404): membership existence is guaranteed by the listener, so the
 * only failure here is a member whose role is not management.
 *
 * Known, accepted ordering quirk (review finding): on the custom controllers,
 * the archived-season 409 (kernel listener) fires before this in-controller
 * 403, so a non-manager probing an archived season sees 409 first. Not an info
 * leak — any active member can already read the season's archived state — and
 * inverting it would mean moving this guard into the kernel layer for no
 * security gain. In AbstractStateProcessor the order IS 403-before-409.
 */
final class ManagementAccessGuard
{
    public function __construct(
        private readonly Security $security,
        private readonly RequestStack $requestStack,
        private readonly ClubUserRepository $clubUserRepository,
    ) {}

    public function assertManager(): void
    {
        $request = $this->requestStack->getCurrentRequest();
        $clubId = $request?->attributes->get('_club_id');

        $user = $this->security->getUser();
        $membership = ($user instanceof User && \is_string($clubId) && '' !== $clubId)
            ? $this->clubUserRepository->findActiveMembership($user->getId(), $clubId)
            : null;

        if (!$membership instanceof ClubUser || !$this->clubUserRepository->isManagementRole($membership->getRole())) {
            throw new AccessDeniedHttpException('Cette action est réservée aux gestionnaires.');
        }
    }
}
