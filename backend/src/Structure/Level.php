<?php

declare(strict_types=1);

namespace App\Structure;

/**
 * Le niveau d'une famille : ce dont ses lignes dépendent. Il dit quelles colonnes de niveau elle
 * porte (`clubId`, `seasonId`), qui ne sont jamais des références (`#[RefersTo]`).
 *
 *  - PLATFORM : commune à tous les clubs (comptes, référentiels, fédération, console) ;
 *  - CLUB : d'un club, sans saison ;
 *  - SEASON : d'une saison d'un club (éventuellement rattachée à un plan de période) ;
 *  - PLAN : d'un plan (saison ou période) ;
 *  - VERSION : d'une version de planning.
 */
enum Level
{
    case PLATFORM;
    case CLUB;
    case SEASON;
    case PLAN;
    case VERSION;
}
