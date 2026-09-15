<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * D'où vient une suggestion de gymnase d'un club adverse dans la table PARTAGÉE
 * `opponent_venue_suggestion` (P2-54 « adversaire multi-gymnases » PR-2).
 *
 * `FFBB_API` : un gymnase OBSERVÉ dans le calendrier fédéral (une salle portée par
 * un hit rencontre) — donnée fédérale publique, déposée sans référence de salle
 * comparable (le hit ne porte pas le `numero` de l'index salles, seulement un `id`
 * distinct — sondé le 2026-09-15), dédupliquée par libellé.
 * `MANUAL` : un gymnase CHOISI à la main pour cet adversaire (une salle de l'index
 * `/api/ffbb/salles`, keyée sur son `numero` fédéral ; libellé/coordonnées RE-RÉSOLUS
 * côté serveur contre l'index FFBB, jamais le texte du client) — la suggestion compte
 * COMBIEN de fois elle a été choisie (par club/saison/équipe), JAMAIS par QUI
 * (« un compte, jamais un qui »).
 */
enum OpponentVenueSuggestionSource: string
{
    use HasValues;

    case FFBB_API = 'FFBB_API';

    case MANUAL = 'MANUAL';
}
