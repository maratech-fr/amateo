<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Ce qu'une écriture de grille heurte : un créneau d'entraînement (`slot`) ou une occupation
 * (`occupancy`) du même gymnase, de la même couche, le même jour. Foyer UNIQUE des quatre messages
 * serveur de chevauchement (processeurs des créneaux et des occupations, import du parc).
 */
final readonly class VenueGridConflict
{
    public const string SLOT = 'slot';

    public const string OCCUPANCY = 'occupancy';

    private const array DAYS = [1 => 'lundi', 2 => 'mardi', 3 => 'mercredi', 4 => 'jeudi', 5 => 'vendredi', 6 => 'samedi', 7 => 'dimanche'];

    /**
     * @param self::SLOT|self::OCCUPANCY $kind
     * @param string                     $startTime « H:i »
     * @param string                     $endTime   « H:i »
     * @param string|null                $label     le motif quand `kind` est une occupation
     */
    public function __construct(
        public string $kind,
        public int $dayOfWeek,
        public string $startTime,
        public string $endTime,
        public ?string $label,
    ) {}

    /**
     * Le refus, selon ce qu'on écrit (`slot` ou `occupancy`) et ce qu'on heurte.
     *
     * @param self::SLOT|self::OCCUPANCY $writing
     */
    public function message(string $writing): string
    {
        $when = \sprintf('%s %s–%s', self::DAYS[$this->dayOfWeek] ?? '', $this->startTime, $this->endTime);

        if (self::SLOT === $writing) {
            return self::SLOT === $this->kind
                ? 'Ce créneau en chevauche un autre du même gymnase ce jour-là.'
                : \sprintf('Ce créneau chevauche l\'occupation « %s » (%s) du même gymnase.', $this->label ?? '', $when);
        }

        return self::SLOT === $this->kind
            ? \sprintf('Cette occupation chevauche un créneau du même gymnase (%s).', $when)
            : \sprintf('Cette occupation chevauche l\'occupation « %s » (%s) du même gymnase.', $this->label ?? '', $when);
    }
}
