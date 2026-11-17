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
     * « Ignorée » : la règle n'est pas appliquée et ne bloque jamais. Une règle opt-in à OFF est
     * OMISE du bloc `implicitRules` du payload (le moteur lit son absence comme « inactive ») ;
     * une règle historique à OFF y part en `OFF` EXPLICITE (contrat 1.11), son absence valant
     * HARD au moteur.
     */
    case OFF = 'OFF';
}
