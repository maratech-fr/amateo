<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * The source season already has a successor (a season in the next
 * season-year). Carries the existing successor id so the API response lets
 * the frontend simply switch to it instead of failing blindly.
 */
final class SeasonAlreadyTransitionedException extends ConflictHttpException
{
    public function __construct(
        private readonly string $existingSeasonId,
    ) {
        parent::__construct('La saison suivante existe déjà.');
    }

    public function getExistingSeasonId(): string
    {
        return $this->existingSeasonId;
    }
}
