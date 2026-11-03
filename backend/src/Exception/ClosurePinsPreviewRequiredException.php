<?php

declare(strict_types=1);

namespace App\Exception;

use App\Service\ClosurePinRemover;
use RuntimeException;

/**
 * Fermetures de gymnase (correction E2a) — re-dater une fermeture qui RETIRERAIT des verrous ou des
 * réservations ({@see ClosurePinRemover}) exige que le gestionnaire ait lu l'annonce : sans
 * `confirmPinRemoval`, le PUT est refusé en 409 `closure_pins_preview_required` avec les nombres, et
 * rien ne change (la transaction est annulée).
 */
final class ClosurePinsPreviewRequiredException extends RuntimeException
{
    /**
     * @param array{locks: int, reservations: int}                                                                                                                                       $toRemove
     * @param array{venueName: string, plans: list<array{entryId: string, title: string, locks: int, reservations: list<array{teamId: string|null, teamName: string, dayOfWeek: int}>}>} $pinRemoval l'annonce détaillée
     */
    public function __construct(public readonly array $toRemove, public readonly array $pinRemoval)
    {
        parent::__construct('Ce re-datage retire des séances verrouillées ou des réservations : confirmez après avoir lu l’annonce.');
    }
}
