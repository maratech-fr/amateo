<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * L'intensité d'une règle implicite « bien-être ». `HARD` la pose en
 * contrainte dure (comportement historique, défaut d'ABSENCE de réglage) ; `PREFERRED` la
 * retire du dur et la pénalise dans l'objectif — le solveur la respecte quand il peut, la
 * transgresse quand il doit, et le DIT (diagnostic `implicit_rule_not_honored`).
 */
enum ImplicitRuleIntensity: string
{
    use HasValues;

    case HARD = 'HARD';
    case PREFERRED = 'PREFERRED';
    /**
     * Règle PROPOSÉE mais non appliquée (P2-42). N'a de sens que pour une règle opt-in :
     * les quatre règles historiques s'appliquent dès qu'un club existe et n'ont donc pas
     * d'état éteint. Le bloc `implicitRules` du payload OMET une règle à OFF — le moteur
     * lit l'absence comme « inactive », il n'a pas à connaître ce cran.
     */
    case OFF = 'OFF';
}
