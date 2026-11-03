<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Schedule;

/**
 * Une version se modifie-t-elle ? Maison unique du verdict, lue par les neuf guichets de retouche,
 * la validation et le bloc `capabilities` : « capacité affichée == verdict du refus ».
 *
 * Seule la version CHARGÉE se modifie ({@see SchedulePlanProvisioner::lockSourceScheduleIdOfPlan},
 * plan de saison comme plan de période) ; les autres se consultent. La version en vigueur a son
 * propre motif : le geste qui en sort est « Rouvrir », pas « Charger ».
 */
final class VersionEditability
{
    public const string NOT_LOADED = 'not_loaded_version';

    public const string CHOSEN = 'chosen_version';

    public const string NOT_LOADED_EDIT_MESSAGE = 'Chargez cette version pour la modifier.';

    public const string NOT_LOADED_VALIDATE_MESSAGE = 'Chargez cette version pour la valider.';

    public const string CHOSEN_EDIT_MESSAGE = 'Ce planning est validé (lecture seule) — rouvrez-le avant de le modifier.';

    public function __construct(
        private readonly SchedulePlanProvisioner $schedulePlanProvisioner,
    ) {}

    /** Le verdict sur des faits déjà lus : le batch des capacités passe par ici, sans requête par version. */
    public static function verdict(string $scheduleId, bool $isChosen, ?string $loadedScheduleId): ?string
    {
        if ($isChosen) {
            return self::CHOSEN;
        }

        return null !== $loadedScheduleId && $loadedScheduleId !== $scheduleId ? self::NOT_LOADED : null;
    }

    /** Le motif du refus (`not_loaded_version`, `chosen_version`), ou null si la version se modifie. */
    public function check(Schedule $schedule): ?string
    {
        return self::verdict(
            $schedule->getId(),
            $this->schedulePlanProvisioner->isChosen($schedule->getId()),
            $this->schedulePlanProvisioner->lockSourceScheduleIdOfPlan($schedule->getSchedulePlanId()),
        );
    }
}
