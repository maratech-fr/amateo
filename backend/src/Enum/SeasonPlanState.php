<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * État du planning de saison (le socle) tel que le lit le rappel des vacances scolaires pour
 * choisir son texte : validé (CHOSEN), généré mais pas validé (FINISHED_NOT_CHOSEN), ou rien de
 * terminé (NOT_GENERATED — aucun plan SEASON, ou générations toutes en échec).
 */
enum SeasonPlanState: string
{
    /**
     * Depuis le payload de `SchedulePlanProvisioner::seasonPlanPayload` : le pointeur posé
     * l'emporte sur tout, sinon une version terminée suffit à dire « à valider ».
     *
     * @param array{id: string, name: string, chosenScheduleId: string|null, hasFinishedVersion: bool}|null $payload
     */
    public static function fromPayload(?array $payload): self
    {
        if (null === $payload) {
            return self::NOT_GENERATED;
        }
        if (null !== $payload['chosenScheduleId']) {
            return self::CHOSEN;
        }

        return $payload['hasFinishedVersion'] ? self::FINISHED_NOT_CHOSEN : self::NOT_GENERATED;
    }

    case CHOSEN = 'chosen';
    case FINISHED_NOT_CHOSEN = 'finished_not_chosen';
    case NOT_GENERATED = 'not_generated';
}
