<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Home-fixture placement lifecycle (spec gestion-matchs, workflow 2-temps):
 * UNPLACED → PLACED (venue + kickoff set) → SUBMITTED (entered in FBI, sent to
 * the league) → VALIDATED (league confirmed).
 *
 * L'import FBI crée TOUT en UNPLACED — domicile ET extérieur (`FbiFixtureImporter` :
 * « Status is always UNPLACED »). PLACED et SUBMITTED sont des gestes du gestionnaire
 * (`FixtureStateProcessor`, ou le placeur pour PLACED) ; une Heure FBI ne fait que
 * pré-remplir `kickoffTime`. VALIDATED n'est PAS un geste (rien côté club ne
 * l'atteste, décision fondateur 2026-09-08, PR-3a) : un domicile PLACED/SUBMITTED
 * que la source (xlsx FBI ou API FFBB) renvoie identique sur date + heure + salle,
 * les trois présents, passe VALIDATED à l'intégration
 * (`FbiFixtureImporter::reconcileNoDivergence`). Divergent → écart pendant, et
 * « prendre la source » le fait retomber PLACED (`demoteSubmitted`).
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
