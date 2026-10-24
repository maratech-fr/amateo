<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Schedule;
use App\Entity\Venue;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Shared by the export controllers (PDF + XLSX): read the optional export
 * scope (a single venue) from the request body and validate it belongs to the
 * schedule's club+season, so a foreign/unknown venue id can't be smuggled in.
 * One home for the tenant-scoped venue check keeps both endpoints in lockstep.
 */
trait ResolvesExportScopeTrait
{
    /** @return string|null validated venue id, or null for the all-venues scope */
    private function resolveExportVenueId(EntityManagerInterface $em, RequestStack $requestStack, Schedule $schedule): ?string
    {
        $request = $requestStack->getCurrentRequest();
        if (!$request instanceof Request) {
            return null;
        }
        $body = json_decode($request->getContent() ?: '{}', true);
        $venueId = \is_array($body) ? ($body['venueId'] ?? null) : null;
        if (!\is_string($venueId) || '' === $venueId) {
            return null;
        }

        $venue = $em->getRepository(Venue::class)->findOneBy([
            'id' => $venueId,
            'clubId' => $schedule->getClubId(),
            'seasonId' => $schedule->getSeasonId(),
        ]);
        if (!$venue instanceof Venue) {
            throw new NotFoundHttpException('Gymnase introuvable.');
        }

        return $venueId;
    }

    /**
     * Chantier E — « afficher le nom des coachs » lu du corps en FAIL-CLOSED : les noms ne
     * partent QUE sur le booléen STRICT `true`. Un `"true"`, un `1`, un `"1"`, l'absence du
     * champ, l'absence de corps → false. Un flag laxiste divulguerait des noms de personnes
     * sur un document remis aux familles sans que le gestionnaire l'ait demandé.
     */
    private function resolveShowCoaches(RequestStack $requestStack): bool
    {
        $request = $requestStack->getCurrentRequest();
        if (!$request instanceof Request) {
            return false;
        }
        $body = json_decode($request->getContent() ?: '{}', true);

        return \is_array($body) && true === ($body['showCoaches'] ?? null);
    }
}
