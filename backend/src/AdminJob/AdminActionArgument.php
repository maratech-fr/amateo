<?php

declare(strict_types=1);

namespace App\AdminJob;

/**
 * One RUNTIME argument of a support action (SA4), bounded by construction: a JSON
 * body key, the CLI option it maps to, and a CLOSED enum of accepted values (each
 * with a human label for the console). No free-text type is representable — an
 * argument is ALWAYS one of a finite, catalogue-declared set.
 *
 * Presence is part of the schema, not a hidden branch in the controller:
 *  - $gateArgument === null → the argument is unconditionally REQUIRED ;
 *  - $gateArgument !== null → the argument is FORBIDDEN (400 if present) when the
 *    gate argument holds a value in $forbiddenWhenGateIn, and REQUIRED otherwise.
 * The live rules are set-plan's `paidSeason` (required for every paid offer,
 * forbidden for `decouverte`) and `tier` (required for `complete`, forbidden for
 * `decouverte` and `beta`).
 */
final readonly class AdminActionArgument
{
    /**
     * @param array<string, string> $choices             Closed enum: value => human label. Nothing outside the keys is representable.
     * @param list<string>          $forbiddenWhenGateIn gate values for which THIS argument must be ABSENT (present → 400)
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $option,
        public array $choices,
        public ?string $gateArgument = null,
        public array $forbiddenWhenGateIn = [],
    ) {}

    public function isConditional(): bool
    {
        return null !== $this->gateArgument;
    }

    public function allows(string $value): bool
    {
        return \array_key_exists($value, $this->choices);
    }
}
