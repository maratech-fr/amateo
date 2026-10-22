<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Home-fixture placement lifecycle (spec gestion-matchs, workflow 2-temps):
 * UNPLACED → PLACED (venue + kickoff set) → SUBMITTED (entered in FBI, sent to
 * the league) → VALIDATED (league confirmed).
 *
 * L'import FBI crée TOUT en UNPLACED — domicile ET extérieur (`FbiFixtureImporter` :
 * le statut est TOUJOURS UNPLACED à la CRÉATION). PLACED et SUBMITTED sont des gestes du
 * gestionnaire (`FixtureStateProcessor`, ou le placeur pour PLACED) ; une Heure FBI ne
 * fait que pré-remplir `kickoffTime`. VALIDATED s'atteint de quatre façons, jamais depuis
 * le chemin de CRÉATION de l'import :
 *   1. réconciliation : un domicile PLACED/SUBMITTED que la source (xlsx FBI ou API
 *      FFBB) renvoie identique sur date + heure + salle, les trois présents, passe
 *      VALIDATED à l'intégration (`FbiFixtureImporter::reconcileNoDivergence`).
 *      Divergent → écart pendant, et « prendre la source » le fait retomber PLACED
 *      (`demoteSubmitted`) ;
 *   2. auto-validation AU DÉPÔT (chantier A, `FbiFixtureImporter::import`) — une PASSE
 *      SÉPARÉE, APRÈS le flush de création : tout championnat COMMENCÉ voit ses domiciles
 *      validables (heure + gymnase identifié, sans écart) basculer VALIDATED + source
 *      MANUAL, par le MÊME prédicat et la MÊME écriture que le geste en lot
 *      (`LeagueValidationOutlook::fixturesToConfirm` / `markLeagueValidated`) — jamais une
 *      seconde copie. Le chemin de CRÉATION, lui, ne pose jamais VALIDATED ;
 *   3. « validé ligue » en lot (lot L, `LeagueValidatedFixturesController`) — désormais un
 *      RATTRAPAGE : un club démarrant EN COURS de saison rejoue le même prédicat pour les
 *      championnats mûris APRÈS le dépôt (échéance tombée entre deux passages). Même
 *      bascule VALIDATED + source MANUAL ;
 *   4. amicaux passés (lot O, `FriendlyAutoValidator`) — un amical (domicile SANS
 *      compétition, ou compétition dont le LIBELLÉ est un amical) dont la date est
 *      passée n'a plus rien à confirmer : un balayage déclenché par un GESTIONNAIRE le
 *      passe VALIDATED (source MANUAL pour un domicile, intacte pour un extérieur). Le
 *      geste « validé ligue » ne concerne QUE les championnats.
 * Aucun de ces chemins ne pose VALIDATED sur le chemin de CRÉATION de l'import.
 *
 * Ce statut ne dit RIEN de l'engagement de l'équipe : dès que l'import a fait
 * correspondre une rencontre à une de nos équipes, la fédération la connaît — elle est
 * engagée, `UNPLACED` ou non (voir `TeamEngagementGuard`). Ne pas s'en servir pour
 * répondre « cette équipe joue-t-elle ? ».
 */
enum FixtureStatus: string
{
    use HasValues;

    case UNPLACED = 'UNPLACED';
    case PLACED = 'PLACED';
    case SUBMITTED = 'SUBMITTED';
    case VALIDATED = 'VALIDATED';
}
