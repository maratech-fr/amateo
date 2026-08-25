<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\CalendarEntry;
use App\Entity\Coach;
use App\Entity\ImplicitRuleSetting;
use App\Entity\MatchSlotRotation;
use App\Entity\MatchSlotRotationTeam;
use App\Entity\Reservation;
use App\Entity\Schedule;
use App\Entity\SchedulePlan;
use App\Entity\SharedTrainingGroup;
use App\Entity\SharedTrainingGroupTeam;
use App\Entity\Team;
use App\Entity\TeamLink;
use App\Entity\TeamMatchHabit;
use App\Entity\TeamPeriodOverride;
use App\Entity\TeamTag;
use App\Entity\TeamTagAssignment;
use App\Entity\Venue;
use App\Entity\VenuePeriodOverride;
use App\Entity\VenueTrainingSlot;
use App\Enum\SchedulePlanType;
use App\Enum\ScheduleStatus;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;

/**
 * UNE DONNÉE DU CLUB (autre qu'une contrainte) QUI CHANGE PÉRIME LES PLANNINGS — et il faut le DIRE.
 *
 * Une contrainte n'est pas la seule entrée du solveur : gymnases, coachs, créneaux et grilles
 * de période, réservations, overrides de période, tags d'équipe, entrées de calendrier nourrissent
 * tout autant le placement. Modifier l'un d'eux APRÈS génération rend le planning PÉRIMÉ — il
 * décrit un état antérieur des DONNÉES, sans que rien ne le dise. Troisième jumeau de
 * `ConstraintChangeStaleScheduleListener` (dont ceci reprend le patron exact) et du marqueur
 * « retouché à la main » : même colonne unique, même bannière, même remise à zéro à l'import.
 *
 * ⚠ **Listener d'entité, PAS marquage par appelant.** Ces entités s'écrivent depuis des
 * dizaines d'endroits (API, imports FFBB, transition de saison, commandes, `EntityManager` nu).
 * Marquer chaque appelant garantirait d'en oublier un — et un oubli ici, c'est le bug de
 * confiance qui revient en silence. Le listener attrape TOUT writer.
 *
 * ⚠ **UN SEUL marqueur (`resourcesChangedSinceGeneration`) pour toutes ces sources**, pas un
 * booléen par source : une ferme de colonnes que la bannière ne saurait plus lire. La cause
 * affichée reste à granularité utile (« les données du club ont changé »).
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════
 * PÉRIMÈTRE PAR SOURCE — dérivé MÉCANIQUEMENT de la colonne, JAMAIS de la sémantique.
 * La subtilité ADR-0002 est au cœur du lot : la grille d'une période est une COPIE ; modifier la
 * grille SAISON ne périme PAS un plan de période, et inversement. C'est la colonne
 * `schedule_plan_id` de la ligne modifiée qui DIT le plan — pas une devinette sur « quelle
 * contrainte touche quel plan » (là c'était fragile ; ICI la colonne est explicite).
 *
 *   Source (table)              | Colonne portée              | Périmètre marqué
 *   ----------------------------|-----------------------------|-------------------------------------
 *   venue_training_slot         | schedule_plan_id (nullable) | plan si non-NULL, sinon plan SEASON
 *   reservation                 | schedule_plan_id (nullable) | plan si non-NULL, sinon plan SEASON
 *   venue_period_override       | schedule_plan_id (NOT NULL) | ce plan (toujours une période)
 *   team_period_override        | schedule_plan_id (NOT NULL) | ce plan (toujours une période)
 *   venue                       | club_id + season_id         | club + saison
 *   coach                       | club_id + season_id         | club + saison
 *   team                        | club_id + season_id         | club + saison  (cf. structureDiverged)
 *   team_match_habit            | club_id + season_id         | club + saison  (RMM-5 : matchDay dérivé)
 *   match_slot_rotation         | club_id + season_id         | club + saison  (RMM-5 : matchDay dérivé)
 *   match_slot_rotation_team    | club_id + season_id         | club + saison  (RMM-5 : membre de rotation)
 *   team_tag_assignment         | club_id + season_id         | club + saison
 *   implicit_rule_setting       | schedule_plan_id (nullable) | plan si non-NULL, sinon club+saison (**)
 *   calendar_entry              | club_id + season_id (*)     | club + saison  (repli, cf. (*))
 *   team_tag                    | club_id (pas de saison) (*) | club entier    (repli, cf. (*))
 *
 * (*) REPLIS où la dérivation plan-scopée n'est PAS mécanique, assumés et documentés :
 *   - `calendar_entry` : la table ne porte PAS de `schedule_plan_id` ni de FK de période — une
 *     entrée de calendrier (vacances, fermeture, fenêtre) façonne le calendrier de TOUTE la
 *     saison, pas d'un plan isolé. On retombe donc sur club+saison, large et honnête, plutôt
 *     qu'une dérivation fragile via `SchedulePlan.calendarEntryId`.
 *   - `team_tag` : la table ne porte PAS de `season_id` (un tag est club-level, partagé entre
 *     saisons). On ne peut donc pas mécaniquement le scoper à une saison : on marque le club
 *     entier. `team_tag_assignment`, elle, porte la saison → club+saison.
 *
 * (**) `implicit_rule_setting` : une ligne de PÉRIODE (plan non-NULL) est une COPIE matérialisée
 *   → elle ne périme QUE son plan (patron override de période). Une ligne de SAISON (plan NULL)
 *   est le REPLI VIVANT des plans legacy sans copie : tant que `resolveForPlan` retombe sur la
 *   saison, ces plans la suivent VRAIMENT → on marque club+saison (et non seasonPlan). Cela
 *   sur-marque un peu les plans de période nés après la fonctionnalité (qui ont leur copie) :
 *   choix conservateur assumé — un faux « régénérez » coûte moins qu'un planning périmé ignoré.
 *
 * `structureDiverged` (Team) : le marquage ci-dessus RECOUVRE la bannière `structureDiverged`
 * (qui compare `generatedTeamCount` aux équipes du jour). Les deux coexistent volontairement :
 * l'une est un INSTANTANÉ comparé (attrape aussi les écarts ANTÉRIEURS au marqueur), l'autre un
 * ÉVÉNEMENT (attrape la modification, même sans écart de compte — renommer une équipe, changer
 * son niveau). L'écran les affiche dans la MÊME bannière unifiée (cf. `staleness.ts`).
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════
 * CE QUI NE MARQUE PAS (liste fermée — un marquage trop large use la bannière jusqu'à ce que le
 * gestionnaire l'ignore) :
 *   - le PIPELINE lui-même : import de résultat solveur (`ScheduleResultImporter`, qui au
 *     contraire DÉMARQUE), déplacement de créneau (`MoveSlotService`, couvert par son propre
 *     marqueur `manuallyEditedSinceGeneration`) ;
 *   - les entités de RÉSULTAT : `schedule_slot_template`, `schedule_diagnostic`,
 *     `schedule_structure_snapshot`, et `schedule` elle-même ;
 *   - les DOLÉANCES : `coach_wish*` — des SOUHAITS de coach, pas des données que le solveur
 *     consomme (ils sont dérivés en contraintes AVANT, et c'est la contrainte qui marque) ;
 *   - `priority_tier` : table de RÉFÉRENCE GLOBALE (pas de club/saison, PK entière, API en
 *     lecture seule — « seeded via fixtures », cf. `PriorityTierResource`). Immuable au runtime :
 *     un listener n'y déclencherait qu'au SEED, sans club à scoper, et marquerait globalement —
 *     exactement la sur-notification qu'on refuse. On ne l'écoute donc pas ;
 *   - l'audit, les tables admin, les entités de facturation/abonnement.
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════
 * SEULS les plannings COMPLETED sont marqués (un DRAFT/PENDING/… ne porte aucun résultat à
 * périmer), TOUTES versions confondues (le gestionnaire consulte aussi les antérieures).
 * Le WHERE explicite EST la frontière ; RLS la double côté base. Coût du `postFlush` : borné par
 * le nombre de PÉRIMÈTRES DISTINCTS (déduplication `$pending`), pas par le nombre de lignes
 * écrites — un seed qui écrit des centaines de créneaux d'un même plan ne fait QU'UN bulk UPDATE.
 */
#[AsEntityListener(event: Events::postPersist, method: 'venueTrainingSlotTouched', entity: VenueTrainingSlot::class)]
#[AsEntityListener(event: Events::postUpdate, method: 'venueTrainingSlotUpdated', entity: VenueTrainingSlot::class)]
#[AsEntityListener(event: Events::postRemove, method: 'venueTrainingSlotTouched', entity: VenueTrainingSlot::class)]
#[AsEntityListener(event: Events::postPersist, method: 'reservationTouched', entity: Reservation::class)]
#[AsEntityListener(event: Events::postUpdate, method: 'reservationTouched', entity: Reservation::class)]
#[AsEntityListener(event: Events::postRemove, method: 'reservationTouched', entity: Reservation::class)]
#[AsEntityListener(event: Events::postPersist, method: 'venuePeriodOverrideTouched', entity: VenuePeriodOverride::class)]
#[AsEntityListener(event: Events::postUpdate, method: 'venuePeriodOverrideTouched', entity: VenuePeriodOverride::class)]
#[AsEntityListener(event: Events::postRemove, method: 'venuePeriodOverrideTouched', entity: VenuePeriodOverride::class)]
#[AsEntityListener(event: Events::postPersist, method: 'teamPeriodOverrideTouched', entity: TeamPeriodOverride::class)]
#[AsEntityListener(event: Events::postUpdate, method: 'teamPeriodOverrideTouched', entity: TeamPeriodOverride::class)]
#[AsEntityListener(event: Events::postRemove, method: 'teamPeriodOverrideTouched', entity: TeamPeriodOverride::class)]
#[AsEntityListener(event: Events::postPersist, method: 'venueTouched', entity: Venue::class)]
#[AsEntityListener(event: Events::postUpdate, method: 'venueTouched', entity: Venue::class)]
#[AsEntityListener(event: Events::postRemove, method: 'venueTouched', entity: Venue::class)]
#[AsEntityListener(event: Events::postPersist, method: 'coachTouched', entity: Coach::class)]
#[AsEntityListener(event: Events::postUpdate, method: 'coachTouched', entity: Coach::class)]
#[AsEntityListener(event: Events::postRemove, method: 'coachTouched', entity: Coach::class)]
#[AsEntityListener(event: Events::postPersist, method: 'teamTouched', entity: Team::class)]
#[AsEntityListener(event: Events::postUpdate, method: 'teamTouched', entity: Team::class)]
#[AsEntityListener(event: Events::postRemove, method: 'teamTouched', entity: Team::class)]
#[AsEntityListener(event: Events::postPersist, method: 'teamLinkTouched', entity: TeamLink::class)]
#[AsEntityListener(event: Events::postUpdate, method: 'teamLinkTouched', entity: TeamLink::class)]
#[AsEntityListener(event: Events::postRemove, method: 'teamLinkTouched', entity: TeamLink::class)]
#[AsEntityListener(event: Events::postPersist, method: 'teamTagAssignmentTouched', entity: TeamTagAssignment::class)]
#[AsEntityListener(event: Events::postUpdate, method: 'teamTagAssignmentTouched', entity: TeamTagAssignment::class)]
#[AsEntityListener(event: Events::postRemove, method: 'teamTagAssignmentTouched', entity: TeamTagAssignment::class)]
#[AsEntityListener(event: Events::postPersist, method: 'implicitRuleSettingTouched', entity: ImplicitRuleSetting::class)]
#[AsEntityListener(event: Events::postUpdate, method: 'implicitRuleSettingTouched', entity: ImplicitRuleSetting::class)]
#[AsEntityListener(event: Events::postRemove, method: 'implicitRuleSettingTouched', entity: ImplicitRuleSetting::class)]
#[AsEntityListener(event: Events::postPersist, method: 'calendarEntryTouched', entity: CalendarEntry::class)]
#[AsEntityListener(event: Events::postUpdate, method: 'calendarEntryTouched', entity: CalendarEntry::class)]
#[AsEntityListener(event: Events::postRemove, method: 'calendarEntryTouched', entity: CalendarEntry::class)]
#[AsEntityListener(event: Events::postPersist, method: 'teamTagTouched', entity: TeamTag::class)]
#[AsEntityListener(event: Events::postUpdate, method: 'teamTagTouched', entity: TeamTag::class)]
#[AsEntityListener(event: Events::postRemove, method: 'teamTagTouched', entity: TeamTag::class)]
#[AsEntityListener(event: Events::postPersist, method: 'sharedTrainingGroupTouched', entity: SharedTrainingGroup::class)]
#[AsEntityListener(event: Events::postUpdate, method: 'sharedTrainingGroupTouched', entity: SharedTrainingGroup::class)]
#[AsEntityListener(event: Events::postRemove, method: 'sharedTrainingGroupTouched', entity: SharedTrainingGroup::class)]
#[AsEntityListener(event: Events::postPersist, method: 'sharedTrainingGroupTeamTouched', entity: SharedTrainingGroupTeam::class)]
#[AsEntityListener(event: Events::postRemove, method: 'sharedTrainingGroupTeamTouched', entity: SharedTrainingGroupTeam::class)]
#[AsEntityListener(event: Events::postPersist, method: 'teamMatchHabitTouched', entity: TeamMatchHabit::class)]
#[AsEntityListener(event: Events::postUpdate, method: 'teamMatchHabitTouched', entity: TeamMatchHabit::class)]
#[AsEntityListener(event: Events::postRemove, method: 'teamMatchHabitTouched', entity: TeamMatchHabit::class)]
#[AsEntityListener(event: Events::postPersist, method: 'matchSlotRotationTouched', entity: MatchSlotRotation::class)]
#[AsEntityListener(event: Events::postUpdate, method: 'matchSlotRotationTouched', entity: MatchSlotRotation::class)]
#[AsEntityListener(event: Events::postRemove, method: 'matchSlotRotationTouched', entity: MatchSlotRotation::class)]
#[AsEntityListener(event: Events::postPersist, method: 'matchSlotRotationTeamTouched', entity: MatchSlotRotationTeam::class)]
#[AsEntityListener(event: Events::postRemove, method: 'matchSlotRotationTeamTouched', entity: MatchSlotRotationTeam::class)]
#[AsDoctrineListener(event: Events::postFlush)]
final class ResourceChangeStaleScheduleListener
{
    private const string SET_STALE = 'UPDATE ' . Schedule::class . ' s SET s.resourcesChangedSinceGeneration = true';

    /**
     * Champs d'un créneau dont la SEULE modification ne périme rien : `groupLabel` (le libellé
     * « CEC3 » est esthétique — renommer un groupe ne change RIEN à ce que le solveur place),
     * plus les techniques `updatedAt`/`version` qui accompagnent toute écriture. Modifier
     * l'heure, le jour, la capacité, le gymnase… reste hors de cette liste, donc marque.
     *
     * @var list<string>
     */
    private const array COSMETIC_SLOT_FIELDS = ['groupLabel', 'updatedAt', 'version'];

    /** @var array<string, array{scope: string, planId: ?string, clubId: ?string, seasonId: ?string}> périmètres à marquer, dédupliqués par clé */
    private array $pending = [];

    public function venueTrainingSlotTouched(VenueTrainingSlot $entity): void
    {
        $this->markPlanScoped($entity->getSchedulePlanId(), $entity->getClubId(), $entity->getSeasonId());
    }

    /**
     * postUpdate d'un créneau — la seule source qui FILTRE son changeset. Un renommage du libellé
     * de groupe (« CEC3 ») est esthétique pur : le planning reste FIDÈLE aux données que le
     * solveur a consommées, une bannière « régénérez » serait un faux signal qui use la bannière.
     * On lit donc le changeset (dispo en postUpdate) : si les seuls champs modifiés sont
     * cosmétiques, on ne marque pas. Tout vrai changement (heure, jour, capacité…) marque comme
     * avant. Réservé au postUpdate : le postPersist (créneau neuf) et le postRemove (créneau
     * supprimé) passent par `venueTrainingSlotTouched` et marquent toujours — il n'y a pas là de
     * changeset « seul champ cosmétique » à considérer.
     */
    public function venueTrainingSlotUpdated(VenueTrainingSlot $entity, PostUpdateEventArgs $args): void
    {
        $changed = array_keys($args->getObjectManager()->getUnitOfWork()->getEntityChangeSet($entity));
        if ([] === array_diff($changed, self::COSMETIC_SLOT_FIELDS)) {
            return;
        }

        $this->venueTrainingSlotTouched($entity);
    }

    public function reservationTouched(Reservation $entity): void
    {
        $this->markPlanScoped($entity->getSchedulePlanId(), $entity->getClubId(), $entity->getSeasonId());
    }

    public function venuePeriodOverrideTouched(VenuePeriodOverride $entity): void
    {
        // schedule_plan_id NOT NULL : un override appartient toujours à un plan de période.
        $this->markPlan($entity->getSchedulePlanId());
    }

    public function teamPeriodOverrideTouched(TeamPeriodOverride $entity): void
    {
        $this->markPlan($entity->getSchedulePlanId());
    }

    public function venueTouched(Venue $entity): void
    {
        $this->markClubSeason($entity->getClubId(), $entity->getSeasonId());
    }

    public function coachTouched(Coach $entity): void
    {
        $this->markClubSeason($entity->getClubId(), $entity->getSeasonId());
    }

    public function teamTouched(Team $entity): void
    {
        $this->markClubSeason($entity->getClubId(), $entity->getSeasonId());
    }

    public function teamLinkTouched(TeamLink $entity): void
    {
        // Passerelle = STRUCTURE de club+saison (patron teamTouched) : elle nourrit /generate
        // pour TOUS les plans du club+saison (socle ET périodes, mêmes lignes). L'invalidation du
        // cache payload est portée à part par CacheInvalidationListener (générique, via getClubId).
        $this->markClubSeason($entity->getClubId(), $entity->getSeasonId());
    }

    public function teamTagAssignmentTouched(TeamTagAssignment $entity): void
    {
        $this->markClubSeason($entity->getClubId(), $entity->getSeasonId());
    }

    public function implicitRuleSettingTouched(ImplicitRuleSetting $entity): void
    {
        $planId = $entity->getSchedulePlanId();
        if (null !== $planId) {
            // Réglage de PÉRIODE (copie matérialisée, ADR-0002) : il ne vaut que pour SON plan —
            // marquer CE plan seulement (patron venue_period_override).
            $this->markPlan($planId);

            return;
        }

        // Réglage de SAISON (plan NULL) : REPLI VIVANT des plans legacy sans copie. Tant que ce
        // repli existe (resolveForPlan retombe sur la saison), un plan legacy suit RÉELLEMENT la
        // saison → on marque club+saison, PAS seulement le plan SEASON (à la différence de la
        // grille, dont la période est toujours une copie franche). Sur-marque légèrement les plans
        // de période nés APRÈS la fonctionnalité (qui ont leur copie) : conservateur assumé.
        $this->markClubSeason($entity->getClubId(), $entity->getSeasonId());
    }

    public function calendarEntryTouched(CalendarEntry $entity): void
    {
        $this->markClubSeason($entity->getClubId(), $entity->getSeasonId());
    }

    public function teamTagTouched(TeamTag $entity): void
    {
        $this->markClub($entity->getClubId());
    }

    public function sharedTrainingGroupTouched(SharedTrainingGroup $entity): void
    {
        // P2-27 — schedule_plan_id NULLABLE (patron reservation/venue_training_slot) : plan si
        // non-NULL, sinon le plan SEASON du club+saison (jamais les copies de période, ADR-0002).
        $clubId = $entity->getClubId();
        if (null === $clubId) {
            return;
        }
        $this->markPlanScoped($entity->getSchedulePlanId(), $clubId, $entity->getSeasonId());
    }

    public function sharedTrainingGroupTeamTouched(SharedTrainingGroupTeam $entity): void
    {
        // Ajouter/retirer un membre change la composition du groupe → même périmètre que le parent
        // (colonnes dénormalisées : le listener n'a pas à joindre).
        $clubId = $entity->getClubId();
        if (null === $clubId) {
            return;
        }
        $this->markPlanScoped($entity->getSchedulePlanId(), $clubId, $entity->getSeasonId());
    }

    public function teamMatchHabitTouched(TeamMatchHabit $entity): void
    {
        // RMM-5 PR-3 — une habitude porte un jour ISO qui entre dans le max dérivé du matchDay
        // (ScheduleConstraintBuilder::deriveMatchDay) et donc dans le payload /generate hashé.
        // La modifier périme les COMPLETED du club+saison (patron STRUCTURE, teamTouched).
        $this->markClubSeason($entity->getClubId(), $entity->getSeasonId());
    }

    public function matchSlotRotationTouched(MatchSlotRotation $entity): void
    {
        // RMM-5 PR-3 — le jour de match ISO d'une équipe est DÉRIVÉ de ses habitudes ∪ rotations
        // (ScheduleConstraintBuilder::deriveMatchDay) et entre dans le payload /generate hashé.
        // Modifier une rotation (jour/heure/gymnase) peut donc bouger le matchDay émis → périme
        // les COMPLETED du club+saison, patron STRUCTURE (teamTouched). La rotation est
        // club+saison (hors plan de période) → socle ET périodes suivent la même déclaration.
        $this->markClubSeason($entity->getClubId(), $entity->getSeasonId());
    }

    public function matchSlotRotationTeamTouched(MatchSlotRotationTeam $entity): void
    {
        // Ajouter/retirer un membre change quelles équipes tirent leur matchDay de la rotation
        // (écriture des membres = delete+recreate, patron SharedTrainingGroupTeam : postPersist +
        // postRemove suffisent). Colonnes club/saison dénormalisées → pas de jointure.
        $clubId = $entity->getClubId();
        if (null === $clubId) {
            return;
        }
        $this->markClubSeason($clubId, $entity->getSeasonId());
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        $pending = $this->pending;
        // Vidé AVANT toute écriture : bulk UPDATE via execute() ne redéclenche pas postFlush
        // (pas de récursion), mais on vide en tête pour rester robuste si un autre listener
        // flushe derrière nous. Jumeau exact de ConstraintChangeStaleScheduleListener.
        $this->pending = [];

        if ([] === $pending) {
            return;
        }

        $manager = $args->getObjectManager();
        foreach ($pending as $mark) {
            $this->apply($manager, $mark);
        }
    }

    private function markPlan(string $planId): void
    {
        $this->pending['plan:' . $planId] = ['scope' => 'plan', 'planId' => $planId, 'clubId' => null, 'seasonId' => null];
    }

    private function markPlanScoped(?string $planId, string $clubId, string $seasonId): void
    {
        if (null !== $planId) {
            $this->markPlan($planId);

            return;
        }

        // schedule_plan_id NULL = ligne de la grille SAISON. Le périmètre est le plan SEASON du
        // club+saison, JAMAIS les plans de période (leurs grilles sont des COPIES, ADR-0002).
        $this->pending['seasonPlan:' . $clubId . ':' . $seasonId] = ['scope' => 'seasonPlan', 'planId' => null, 'clubId' => $clubId, 'seasonId' => $seasonId];
    }

    private function markClubSeason(?string $clubId, string $seasonId): void
    {
        if (null === $clubId) {
            return;
        }

        $this->pending['clubSeason:' . $clubId . ':' . $seasonId] = ['scope' => 'clubSeason', 'planId' => null, 'clubId' => $clubId, 'seasonId' => $seasonId];
    }

    private function markClub(string $clubId): void
    {
        // Clé « club-wide » (pas « club: » — un garde textuel réserve `'club:' .` au topic Mercure).
        $this->pending['club-wide:' . $clubId] = ['scope' => 'club', 'planId' => null, 'clubId' => $clubId, 'seasonId' => null];
    }

    /**
     * @param array{scope: string, planId: ?string, clubId: ?string, seasonId: ?string} $mark
     */
    private function apply(EntityManagerInterface $manager, array $mark): void
    {
        // Bulk UPDATE : on ne charge pas les plannings (ils peuvent être nombreux), on pose le
        // drapeau en une requête. Le WHERE explicite EST la frontière ; RLS la double.
        $query = match ($mark['scope']) {
            'plan' => $manager->createQuery(self::SET_STALE . ' WHERE s.schedulePlanId = :planId AND s.status = :status')
                ->setParameter('planId', $mark['planId'])
                ->setParameter('status', ScheduleStatus::COMPLETED),
            // ADR-0002 : le plan SEASON du club+saison SEULEMENT — la grille saison ne touche
            // pas les COPIES de période. Sous-requête sur le type de plan (une seule vérité).
            'seasonPlan' => $manager->createQuery(
                self::SET_STALE
                . ' WHERE s.status = :status AND s.schedulePlanId IN ('
                . 'SELECT p.id FROM ' . SchedulePlan::class . ' p'
                . ' WHERE p.clubId = :clubId AND p.seasonId = :seasonId AND p.type = :seasonType)',
            )
                ->setParameter('status', ScheduleStatus::COMPLETED)
                ->setParameter('clubId', $mark['clubId'])
                ->setParameter('seasonId', $mark['seasonId'])
                ->setParameter('seasonType', SchedulePlanType::SEASON),
            'clubSeason' => $manager->createQuery(self::SET_STALE . ' WHERE s.clubId = :clubId AND s.seasonId = :seasonId AND s.status = :status')
                ->setParameter('clubId', $mark['clubId'])
                ->setParameter('seasonId', $mark['seasonId'])
                ->setParameter('status', ScheduleStatus::COMPLETED),
            'club' => $manager->createQuery(self::SET_STALE . ' WHERE s.clubId = :clubId AND s.status = :status')
                ->setParameter('clubId', $mark['clubId'])
                ->setParameter('status', ScheduleStatus::COMPLETED),
            default => null,
        };

        $query?->execute();
    }
}
