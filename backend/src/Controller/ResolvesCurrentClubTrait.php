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
 * NOTE: the cockpit & planning-lifecycle controllers that duplicated this helper
 * now `use` the trait (those reusing the Request right after add an explicit
 * `null === $request` guard, same 4xx as before, since its non-null-ness no longer
 * flows from the resolved id). One reader stays inline on purpose:
 * LeagueValidatedFixturesController keeps an empty string as a valid id, which the
 * trait would turn into null — a different behaviour, not a dedup.
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
