<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Resolves the current club id from the request — the tenant listener's
 * `_club_id` attribute, derived server-side from the authenticated user's
 * membership (no client-supplied header since AUD-SEC-25). Shared by cockpit
 * controllers so this security-sensitive idiom lives in one place.
 *
 * NOTE: several older controllers (Validate/Reopen/Generate…) still inline the
 * same helper — migrating them is a separate cleanup.
 */
trait ResolvesCurrentClubTrait
{
    private function resolveCurrentClubId(RequestStack $requestStack): ?string
    {
        $request = $requestStack->getCurrentRequest();

        $clubId = $request?->attributes->get('_club_id');
        if (\is_string($clubId) && '' !== $clubId) {
            return $clubId;
        }

        return null;
    }
}
