<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Genre d'un coach, pour accorder les libellés qui désignent LA personne (joueur·euse,
 * salarié·e…). DISTINCT de {@see Gender} (genre d'ÉQUIPE FFBB M|F|MIXTE) : une personne
 * n'est pas « mixte », et le pivot du besoin est la valeur « non précisé » (double forme).
 * Saisi par le gestionnaire ; défaut UNSPECIFIED (aucune donnée imposée à la création).
 */
enum CoachGender: string
{
    use HasValues;

    case FEMALE = 'FEMALE';
    case MALE = 'MALE';
    case UNSPECIFIED = 'UNSPECIFIED';
}
