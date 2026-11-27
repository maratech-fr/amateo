<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Garde les actions qui se construisent SUR le calendrier de base de la saison :
 * matchs et plans secondaires (ADR-0002 inv. 13).
 *
 * Le calendrier de base = le plan SEASON **et sa version choisie**. Tant que le
 * gestionnaire n'a choisi aucune version, la saison est un espace de travail : il
 * n'y a rien contre quoi placer un match ni sur quoi bâtir un ajustement.
 *
 * 409 : le pipeline de toasts du frontend le remonte déjà.
 */
final class SocleGuard
{
    /** Le refus 409 tant que le plan de saison n'a pas de version choisie — lu tel quel dans un toast. */
    public const string SEASON_PLAN_REQUIRED_MESSAGE = 'Validez le planning de saison avant de créer un match ou un planning de période.';

    public function __construct(
        private readonly SchedulePlanProvisioner $schedulePlanProvisioner,
    ) {}

    /**
     * Limite (héritée) : une saison `null` (aucune saison résolue) LAISSE PASSER — ce garde ne
     * refuse rien sans saison ; c'est au contrôleur appelé ensuite de refuser seul (le contrôle
     * central l'appelle avec `_season_id`, qui peut manquer).
     *
     * @throws ConflictHttpException tant que le plan de saison n'a pas de version choisie
     */
    public function assertSeasonPlanChosen(?string $seasonId): void
    {
        if (null === $seasonId) {
            return; // aucune saison résolue → d'autres gardes s'en chargent
        }

        if (!$this->isSeasonPlanChosen($seasonId)) {
            throw new ConflictHttpException(self::SEASON_PLAN_REQUIRED_MESSAGE);
        }
    }

    /**
     * La même question, sans jeter : pour une LECTURE qui n'écrit qu'avec le socle en vigueur
     * (le balayage « Déjà dans FBI » de l'accueil) et répond quand même sans lui.
     */
    public function isSeasonPlanChosen(string $seasonId): bool
    {
        return null !== $this->schedulePlanProvisioner->chosenOfSeasonPlan($seasonId);
    }
}
