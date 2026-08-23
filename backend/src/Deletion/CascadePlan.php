<?php

declare(strict_types=1);

namespace App\Deletion;

use App\Entity\Coach;
use App\Entity\CoachPlayerMembership;
use App\Entity\CoachWish;
use App\Entity\Competition;
use App\Entity\Fixture;
use App\Entity\Reservation;
use App\Entity\ScheduleDiagnostic;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\Team;
use App\Entity\TeamCoach;
use App\Entity\TeamLink;
use App\Entity\TeamMatchHabit;
use App\Entity\TeamPeriodOverride;
use App\Entity\TeamTagAssignment;
use App\Entity\Venue;
use App\Entity\VenueMatchWindow;
use App\Entity\VenuePeriodOverride;
use App\Entity\VenueTrainingSlot;
use App\Entity\VenueUnavailability;
use App\Enum\ConstraintScope;

/**
 * **La** liste de ce qu'emporte la suppression d'une équipe, d'un gymnase ou d'un coach.
 *
 * Maison unique (P3-16) : `EntityCascadeDeleter` l'EXÉCUTE, `DeletionImpactCounter` la
 * COMPTE. Ajouter une destruction sans son annonce est devenu impossible — c'est le même
 * objet qui porte les deux, et `DeletionImpactParityTest` le falsifie dans les deux sens.
 *
 * ⚠ **L'ORDRE des étapes est significatif** et reproduit celui de l'implémentation
 * historique : les enfants avant les parents, les bascules de période avant leurs
 * contraintes. Réordonner, c'est risquer l'orphelin.
 *
 * ⚠ Ces entités n'ont **aucune association ORM ni clé étrangère** — chaque lien est une
 * simple colonne guid, donc rien ne cascade tout seul. La liste ci-dessous EST la seule
 * intégrité référentielle du produit.
 */
final class CascadePlan
{
    /** @return list<CascadeStep> */
    public static function forTeam(): array
    {
        return [
            // ⚠ AVANT la purge des réservations de l'équipe : une case n'est « groupe-complète »
            // que tant que la réservation de l'équipe supprimée y figure encore (P2-46 PR-4).
            new SharedGroupReservationPruneStep(new ImpactLabel('team_shared_reservation', 'réservation d\'entraînement mutualisé', 'réservations d\'entraînement mutualisé')),
            new DeleteByFieldStep(Reservation::class, 'teamId', new ImpactLabel('team_reservation', 'créneau réservé', 'créneaux réservés')),
            new DeleteByFieldStep(TeamCoach::class, 'teamId', new ImpactLabel('team_coach', 'coach lié', 'coachs liés')),
            new DeleteByFieldStep(CoachPlayerMembership::class, 'teamId', new ImpactLabel('team_coach_player', 'coach-joueur lié', 'coach-joueurs liés')),
            // P1-4 PR C — habitudes + liens suivent leur équipe (liens des DEUX côtés : le
            // couple est normalisé, l'équipe peut occuper l'une ou l'autre colonne).
            new DeleteByFieldStep(TeamMatchHabit::class, 'teamId', new ImpactLabel('team_match_habit', 'habitude de match', 'habitudes de match')),
            new DeleteByFieldStep(TeamLink::class, 'teamAId', new ImpactLabel('team_link_a', 'lien entre équipes', 'liens entre équipes')),
            new DeleteByFieldStep(TeamLink::class, 'teamBId', new ImpactLabel('team_link_b', 'lien entre équipes', 'liens entre équipes')),
            new DeleteByFieldStep(ScheduleSlotTemplate::class, 'teamId', new ImpactLabel('team_slot', 'séance placée dans vos plannings', 'séances placées dans vos plannings')),
            // Un diagnostic est un constat du solveur sur une génération passée : il ne
            // survit pas à son sujet, et l'annoncer n'aiderait personne à décider.
            new DeleteByFieldStep(ScheduleDiagnostic::class, 'teamId', null),
            // Module matchs. ⚠ Depuis la garde du périmètre engagé, ces deux étapes ne
            // suppriment plus rien PAR L'API — un seul match, même UNPLACED, rend l'équipe
            // indélébile (`TeamStateProcessor::cascadeBeforeDelete` refuse AVANT d'arriver
            // ici). On les garde en filet : si la règle d'engagement se relâche un jour,
            // les matchs partiront avec l'équipe au lieu de pendre sur un team_id mort.
            new DeleteByFieldStep(Fixture::class, 'teamId', new ImpactLabel('team_fixture', 'match', 'matchs')),
            new DeleteByFieldStep(Competition::class, 'teamId', new ImpactLabel('team_competition', 'engagement en compétition', 'engagements en compétition')),
            // TeamTagAssignment porte un season_id mais AUCUN club_id (scopé par la saison).
            new DeleteByFieldStep(TeamTagAssignment::class, 'teamId', new ImpactLabel('team_tag', 'étiquette', 'étiquettes'), clubScoped: false),
            new DeleteByFieldStep(TeamPeriodOverride::class, 'teamId', new ImpactLabel('team_period_override', 'réglage de période', 'réglages de période')),
            // #10 — sans équipe, une doléance n'a plus de sens : elle part.
            new DeleteByFieldStep(CoachWish::class, 'teamId', new ImpactLabel('team_coach_wish', 'demande de coach', 'demandes de coach')),
            new ScopedConstraintStep(ConstraintScope::TEAM, new ImpactLabel('team_constraint', 'contrainte visant cette équipe', 'contraintes visant cette équipe')),
            new SharedTrainingGroupPruneStep(new ImpactLabel('team_shared_group', 'groupe de mutualisation', 'groupes de mutualisation')),
            new ClearFieldStep(Team::class, 'parentTeamId', new ImpactLabel('team_child', 'équipe rattachée qui perdra son équipe parente', 'équipes rattachées qui perdront leur équipe parente')),
        ];
    }

    /** @return list<CascadeStep> */
    public static function forVenue(): array
    {
        return [
            new DeleteByFieldStep(VenueTrainingSlot::class, 'venueId', new ImpactLabel('venue_slot', 'créneau de disponibilité', 'créneaux de disponibilité')),
            new DeleteByFieldStep(VenueMatchWindow::class, 'venueId', new ImpactLabel('venue_match_window', 'fenêtre de match', 'fenêtres de match')),
            new DeleteByFieldStep(VenueUnavailability::class, 'venueId', new ImpactLabel('venue_unavailability', 'indisponibilité déclarée', 'indisponibilités déclarées')),
            new DeleteByFieldStep(VenuePeriodOverride::class, 'venueId', new ImpactLabel('venue_period_override', 'réglage de période', 'réglages de période')),
            new DeleteByFieldStep(Reservation::class, 'venueId', new ImpactLabel('venue_reservation', 'réservation d\'équipe', 'réservations d\'équipe')),
            new DeleteByFieldStep(ScheduleSlotTemplate::class, 'venueId', new ImpactLabel('venue_slot_template', 'séance placée dans vos plannings', 'séances placées dans vos plannings')),
            new DeleteByFieldStep(ScheduleDiagnostic::class, 'venueId', null),
            new ScopedConstraintStep(ConstraintScope::FACILITY, new ImpactLabel('venue_constraint', 'contrainte visant ce gymnase', 'contraintes visant ce gymnase')),
            new ClearFieldStep(Team::class, 'forcedVenueId', new ImpactLabel('venue_forced_team', 'équipe qui perdra son gymnase imposé', 'équipes qui perdront leur gymnase imposé')),
            new ClearFieldStep(Venue::class, 'parentVenueId', new ImpactLabel('venue_child', 'sous-salle qui perdra son gymnase parent', 'sous-salles qui perdront leur gymnase parent')),
            // DOC-2 — le match SURVIT (sa salle est optionnelle : il redevient « à placer »,
            // donc récupérable). C'est pourquoi la décision fondateur laisse le geste passer
            // au lieu de le refuser — mais un match DÉJÀ DÉCLARÉ à la fédération devra être
            // re-soumis, et cela doit être dit AVANT (cf. DeletionImpactCounter).
            new ClearFieldStep(Fixture::class, 'venueId', new ImpactLabel('venue_fixture', 'match qui perdra sa salle', 'matchs qui perdront leur salle')),
        ];
    }

    /**
     * Le CRÉNEAU de disponibilité — ses ÉPINGLAGES, pas ses résultats.
     *
     * Une réservation et le verrou HARD qu'elle a matérialisé sont **les deux faces d'un même
     * épinglage** : en supprimer une sans l'autre est précisément le bug d'orphelin que cette
     * cascade existe pour fermer (le verrou serait ré-injecté à chaque génération, sur un
     * créneau qui n'existe plus). Jamais les placements SOFT/NONE que le solveur a choisis à
     * cet horaire : ce sont des RÉSULTATS, ils appartiennent à leur version.
     *
     * @return list<CascadeStep>
     */
    public static function forSlot(): array
    {
        return [
            new SlotPinStep(Reservation::class, hardOnly: false, label: new ImpactLabel('slot_reservation', 'réservation d\'équipe', 'réservations d\'équipe')),
            new SlotPinStep(ScheduleSlotTemplate::class, hardOnly: true, label: new ImpactLabel('slot_hard_lock', 'créneau verrouillé dans vos plannings', 'créneaux verrouillés dans vos plannings')),
        ];
    }

    /** @return list<CascadeStep> */
    public static function forCoach(): array
    {
        return [
            new DeleteByFieldStep(TeamCoach::class, 'coachId', new ImpactLabel('coach_team', 'équipe coachée', 'équipes coachées')),
            new DeleteByFieldStep(CoachPlayerMembership::class, 'coachId', new ImpactLabel('coach_player', 'équipe où il joue', 'équipes où il joue')),
            new DeleteByFieldStep(ScheduleDiagnostic::class, 'coachId', null),
            new ScopedConstraintStep(ConstraintScope::COACH, new ImpactLabel('coach_constraint', 'contrainte visant ce coach', 'contraintes visant ce coach')),
            // Une séance placée survit sans son coach — le moteur laisse `coachId` vide de
            // toute façon, on le remet à NULL plutôt que de détruire la séance.
            new ClearFieldStep(ScheduleSlotTemplate::class, 'coachId', new ImpactLabel('coach_slot', 'séance qui perdra son coach', 'séances qui perdront leur coach')),
            // #10 — la doléance SURVIT au coach supprimé, dé-attribuée : son info d'équipe
            // reste utile au plan de vacances.
            new ClearFieldStep(CoachWish::class, 'coachId', new ImpactLabel('coach_wish', 'demande qui perdra son auteur', 'demandes qui perdront leur auteur')),
            new ClearFieldStep(Coach::class, 'parentCoachId', new ImpactLabel('coach_child', 'coach rattaché qui perdra son coach parent', 'coachs rattachés qui perdront leur coach parent')),
        ];
    }
}
