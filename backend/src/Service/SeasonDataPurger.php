<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CalendarEntry;
use App\Entity\Coach;
use App\Entity\CoachPlayerMembership;
use App\Entity\CoachWish;
use App\Entity\CoachWishCampaign;
use App\Entity\Competition;
use App\Entity\ConflictResolution;
use App\Entity\Constraint;
use App\Entity\ConstraintConflict;
use App\Entity\ConstraintPeriodOverride;
use App\Entity\FbiCorrection;
use App\Entity\FbiIngestion;
use App\Entity\Fixture;
use App\Entity\ImplicitRuleSetting;
use App\Entity\MatchModuleVisit;
use App\Entity\MatchSlotRotation;
use App\Entity\MatchSlotRotationTeam;
use App\Entity\OpponentTravel;
use App\Entity\PeriodReminderLog;
use App\Entity\Reservation;
use App\Entity\Schedule;
use App\Entity\ScheduleDiagnostic;
use App\Entity\SchedulePlan;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\ScheduleStructureSnapshot;
use App\Entity\Season;
use App\Entity\SharedTrainingBlock;
use App\Entity\SharedTrainingBlockTeam;
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
use App\Entity\VenueTravelRuleSetting;
use App\Entity\VenueTravelTime;
use App\Entity\VenueUnavailability;
use App\Repository\OpponentVenueSuggestionRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Deletes all data of a single (club, season): the canonical delete-order list,
 * shared by ResetSeasonController (wipe a season's contents, keep the row) and
 * PurgeSeasonsCommand (retention purge — deletes the Season row too).
 *
 * Runs under the caller's tenant context (RLS GUC must already be set to the
 * club). Disables the tenant/season Doctrine filters for the bulk DQL DELETEs
 * (they alias the table name, which is invalid SQL for the reserved-word
 * `constraint` table); the deletes are explicitly scoped by clubId + seasonId
 * and RLS still enforces the club boundary at the DB.
 */
final class SeasonDataPurger
{
    use DisablesTenantFilters;

    /**
     * Tenant, mais purgées AUTREMENT que par la boucle générique — chacune avec sa raison.
     * Le test PurgeCompletenessTest exige que toute entité tenant soit couverte ici,
     * dans une des deux boucles, ou nommément exclue.
     *
     * @var array<string, string> table => comment / pourquoi
     */
    public const HANDLED_APART = [
        'team_tag_assignment' => 'purgé par season_id seul (BCK-11 : porte un club_id mais le périmètre reste la saison, RLS borne le club)',
        'season' => 'la ligne pivot elle-même — supprimée conditionnellement ($deleteSeasonRow : purge de rétention / effacement, jamais le reset)',
    ];

    /**
     * Tenant, VOLONTAIREMENT hors de la purge de SAISON — chacune avec sa raison. Une
     * exclusion est une décision, pas un constat (patron RgpdExportService).
     *
     * @var array<string, string> table => pourquoi
     */
    public const EXCLUDED_FROM_SEASON_PURGE = [
        // Ces cinq tables sont club-scoped SANS saison : les borner par la saison n'a pas
        // de sens. Leur seule porte de sortie est l'effacement RGPD du club (ErasedClubPurger,
        // delete par clubId), pas la purge de saison.
        'solver_metrics' => 'club-scoped sans saison — porte de sortie ErasedClubPurger (télémétrie append-only, décision fondateur 2026-07-18)',
        'feedback' => 'club-scoped sans saison — porte de sortie ErasedClubPurger',
        'team_tag' => 'club-scoped sans saison — porte de sortie ErasedClubPurger',
        'sport_category' => 'club-scoped sans saison — porte de sortie ErasedClubPurger',
        'club_user' => 'club-scoped sans saison — porte de sortie ErasedClubPurger',
        'audit_log' => 'accountability : rétention propre (app:audit:purge) ; l\'effacement écrit une ligne d\'audit APRÈS la purge',
        'coach_wish_token' => 'part par la FK ON DELETE CASCADE de sa campagne (jamais supprimé directement)',
    ];

    /**
     * Enfants SANS colonne club/season, résolus par leur PARENT (sous-requête sur
     * le club+saison du parent) : entityClass => [champ de référence, classe parente].
     * L'ordre est l'ordre de suppression — les enfants avant le DELETE de leur parent,
     * sinon ils orphelinent en silence. La boucle de purge itère cette constante.
     *
     * @var array<class-string, array{0: string, 1: class-string}>
     */
    private const PURGED_VIA_PARENT = [
        // conflicts hang off schedules
        ConstraintConflict::class => ['scheduleId', Schedule::class],
        // reminder logs off calendar entries
        PeriodReminderLog::class => ['calendarEntryId', CalendarEntry::class],
        // #10 — doléances coachs (ancrées à l'entrée de vacances). Table club_id, mais on
        // borne par la saison via l'entrée, comme le reminder log.
        CoachWish::class => ['calendarEntryId', CalendarEntry::class],
        // #10 C2 — campagnes de collecte (leurs tokens partent par FK CASCADE).
        CoachWishCampaign::class => ['calendarEntryId', CalendarEntry::class],
    ];

    /**
     * Entités tenant purgées par (club_id, season_id) en DQL de masse. L'ordre est
     * l'ordre de suppression (un enfant avant son parent — aucune FK en base, l'ordre
     * est l'invariant applicatif). La boucle de purge itère cette constante.
     *
     * @var list<class-string>
     */
    private const PURGED_BY_CLUB_SEASON = [
        ScheduleDiagnostic::class,
        ScheduleStructureSnapshot::class,
        ScheduleSlotTemplate::class,
        Constraint::class,
        // Réglages des règles implicites (club_id+season_id, aucun enfant) : purgés avec
        // la saison comme les contraintes.
        ImplicitRuleSetting::class,
        // P2-53 RMM-8 PR-4 — le levier d'intensité de la règle de trajet (club_id+season_id,
        // aucun enfant) : purgé avec la saison, comme les autres réglages tenant+saison.
        VenueTravelRuleSetting::class,
        Reservation::class,
        TeamPeriodOverride::class,
        ConstraintPeriodOverride::class,
        VenuePeriodOverride::class,
        // P2-51 — mutualisation par BLOC : les lignes membres avant le parent (aucune FK,
        // ordre cosmétique). Deux tables club_id+season_id, purgées avec la saison.
        SharedTrainingBlockTeam::class,
        SharedTrainingBlock::class,
        // RMM-5 — rotation A/B : les lignes membres avant le parent (aucune FK, ordre
        // cosmétique). Deux tables club_id+season_id, purgées avec la saison.
        MatchSlotRotationTeam::class,
        MatchSlotRotation::class,
        // Module matchs (ajouté après ce purger — gap RGPD constaté PR-1) :
        // Fixture avant Competition (competitionId y pointe). Changement
        // ASSUMÉ pour ResetSeasonController aussi : « réinitialiser la
        // saison » supprime désormais matchs/compétitions/réservations —
        // l'ancien comportement les gardait ORPHELINS (fixtures pointant
        // des équipes supprimées), ce qui était le vrai bug.
        Fixture::class,
        Competition::class,
        // RMM-3 — instantané de visite du module matchs (club_id+season_id, aucun
        // enfant) : purgé avec la saison comme les autres tables tenant+saison.
        MatchModuleVisit::class,
        // P4-207 — statut de traitement d'un conflit (club_id+season_id, aucun
        // enfant) : purgé avec la saison. C'est la SEULE porte de sortie d'une
        // ligne orpheline (empreinte disparue du flux) — jamais nettoyée à la volée.
        ConflictResolution::class,
        // RMM-4 — ingestions FBI datées (club_id+season_id, aucun enfant) :
        // purgées avec la saison ; ErasedClubPurger les suit via ce purger.
        FbiIngestion::class,
        // Registre « à corriger dans FBI » (club_id+season_id, aucun enfant) : purgé
        // avec la saison. C'est la SEULE porte de sortie d'une entrée FERMÉE (trace) —
        // les ouvertes disparaissent aussi, la saison partant.
        FbiCorrection::class,
        // P2-54 RMM-9 — temps de trajet vers les adversaires (club_id+season_id, aucun
        // enfant) : purgé avec la saison. Les lignes MANUAL qui portent un gymnase épinglé
        // décrémentent d'abord le compteur PARTAGÉ ({@see decrementSharedVenueChoices}).
        OpponentTravel::class,
        TeamCoach::class,
        CoachPlayerMembership::class,
        // P1-4 PR C — préférences matchs, pointent team_id : avant Team.
        TeamMatchHabit::class,
        TeamLink::class,
        CalendarEntry::class,
        // ADR-0002: the named container of a season/period's versions — a
        // club_id+season_id table, so it must be purged with the season
        // (RGPD erasure + retention purge + season reset). No DB FK cascades.
        SchedulePlan::class,
        Schedule::class,
        Team::class,
        Coach::class,
        VenueTrainingSlot::class,
        // P1-4 PR B — capacité matchs : les deux tables pointent venue_id,
        // purgées AVANT Venue (aucune FK en base, même règle que le reste).
        VenueMatchWindow::class,
        VenueUnavailability::class,
        // P2-53 RMM-8 — la matrice de trajet (club_id+season_id, aucun enfant)
        // avant Venue (elle pointe venue_a_id/venue_b_id, aucune FK en base).
        VenueTravelTime::class,
        Venue::class,
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SchedulePlanProvisioner $schedulePlanProvisioner,
        private readonly OpponentVenueSuggestionRepository $venueSuggestions,
    ) {}

    /**
     * @return int number of child rows deleted (excludes the Season row itself)
     */
    public function purge(string $clubId, string $seasonId, bool $deleteSeasonRow = false): int
    {
        $this->disableTenantFilters($this->entityManager);

        // ADR-0002 inv. 12 : le nom du planning vit sur le plan — et le plan est
        // supprimé plus bas. Avant la bascule il vivait sur la saison et survivait donc
        // au reset ; il faut le capturer AVANT la purge, sinon « réinitialiser la
        // saison » renomme silencieusement le planning du gestionnaire.
        $seasonPlanName = $deleteSeasonRow ? null : $this->currentSeasonPlanName($seasonId);

        $deleted = 0;

        // Children WITHOUT club/season columns first, resolved through their
        // parent (self::PURGED_VIA_PARENT). They must go before their parents'
        // bulk DELETE or they orphan silently.
        // SolverMetric n'est VOLONTAIREMENT pas purgé au reset : télémétrie append-only
        // (décision fondateur 2026-07-18) — c'est de l'usage constaté, pas de la donnée
        // de saison ; il survit au reset en nommant des plannings supprimés (assumé, les
        // dimensions sont dénormalisées à la capture). L'effacement RGPD du club reste
        // sa seule porte de sortie (ErasedClubPurger, delete par clubId).
        foreach (self::PURGED_VIA_PARENT as $entityClass => [$parentRefField, $parentClass]) {
            $deleted += $this->deleteBySubQuery($entityClass, $parentRefField, $parentClass, $clubId, $seasonId);
        }

        // BCK-11 : la table porte désormais un club_id ; la purge reste par saison
        // (c'est son périmètre), et RLS borne la requête au club courant.
        $deleted += (int) $this->entityManager->createQueryBuilder()
            ->delete(TeamTagAssignment::class, 'e')
            ->where('e.seasonId = :seasonId')
            ->setParameter('seasonId', $seasonId)
            ->getQuery()
            ->execute();

        // P4-209(b) — les lignes opponent_travel MANUAL portant un gymnase épinglé ont
        // incrémenté le compteur PARTAGÉ (opponent_venue_suggestion). Les purger sans
        // décrémenter volerait le compte des autres clubs — même sémantique que
        // revertToAuto/deleteTeamOverride (MANUAL + ref effectif seuls). Pré-passe AVANT
        // le DELETE de masse ci-dessous (les lignes doivent encore exister).
        $this->decrementSharedVenueChoices($clubId, $seasonId);

        foreach (self::PURGED_BY_CLUB_SEASON as $entityClass) {
            $deleted += $this->deleteByClubSeason($entityClass, $clubId, $seasonId);
        }

        if ($deleteSeasonRow) {
            $this->entityManager->createQueryBuilder()
                ->delete(Season::class, 's')
                ->where('s.clubId = :clubId')
                ->andWhere('s.id = :seasonId')
                ->setParameter('clubId', $clubId)
                ->setParameter('seasonId', $seasonId)
                ->getQuery()
                ->execute();
        } else {
            // Keep the Season row but drop its loaded-context star: it names a
            // schedule the purge just deleted. The plan's pointer goes with the
            // plan rows above — a plan naming a deleted planning would keep the
            // cockpit "unlocked" with nothing behind it.
            $season = $this->entityManager->getRepository(Season::class)->find($seasonId);
            if ($season instanceof Season && $season->getClubId() === $clubId) {
                $season->setLiveContextScheduleId(null);
                // ADR-0002: the reset wiped the season's SchedulePlan above, but the
                // season row survives — re-provision its empty SEASON plan so the
                // invariant "a SEASON plan exists as soon as the season does" holds.
                $plan = $this->schedulePlanProvisioner->ensureSeasonPlan($season);
                // Le reset vide les DONNÉES de la saison ; il ne rebaptise pas son
                // planning. Le nom re-provisionné est un défaut — on rend au plan celui
                // que le gestionnaire avait choisi.
                if (null !== $seasonPlanName && '' !== $seasonPlanName) {
                    $plan->setName($seasonPlanName);
                }
                $this->entityManager->flush();
            }
        }

        $this->entityManager->clear();

        return $deleted;
    }

    /**
     * Le nom du plan SEASON tel qu'il est AVANT la purge. SQL brut : le plan est
     * supprimé en DQL de masse juste après, et on ne veut pas d'une entité gérée qui
     * ressusciterait au flush.
     */
    private function currentSeasonPlanName(string $seasonId): ?string
    {
        $name = $this->entityManager->getConnection()->fetchOne(
            'SELECT name FROM schedule_plan WHERE season_id = :sid AND type = \'SEASON\'',
            ['sid' => $seasonId],
        );

        return \is_string($name) ? $name : null;
    }

    /**
     * P4-209(b) — décrémente le compteur PARTAGÉ pour chaque ligne opponent_travel
     * MANUAL du club+saison qui épingle un gymnase fédéral, AVANT que la purge ne
     * supprime ces lignes. Sémantique identique à {@see OpponentTravelResolver::revertToAuto}
     * (MANUAL + ref effectif seuls ; le décrément est idempotent, GREATEST(0, …)). SQL brut :
     * les lignes sont supprimées en DQL de masse juste après, on ne veut pas d'entités gérées.
     * Tourne sous le GUC du club (RLS borne la table tenant).
     */
    private function decrementSharedVenueChoices(string $clubId, string $seasonId): void
    {
        /** @var list<array{opponent_organisme_code: string, override_venue_external_ref: string}> $rows */
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT opponent_organisme_code, override_venue_external_ref FROM opponent_travel'
            . ' WHERE club_id = :clubId AND season_id = :seasonId'
            . ' AND source = \'MANUAL\' AND override_venue_external_ref IS NOT NULL',
            ['clubId' => $clubId, 'seasonId' => $seasonId],
        );

        foreach ($rows as $row) {
            $this->venueSuggestions->decrement($row['opponent_organisme_code'], $row['override_venue_external_ref']);
        }
    }

    /**
     * Delete rows of $entityClass whose $parentRefField points at a parent row
     * (of $parentClass) belonging to this club+season. DQL DELETE with subquery.
     */
    private function deleteBySubQuery(string $entityClass, string $parentRefField, string $parentClass, string $clubId, string $seasonId): int
    {
        $sub = $this->entityManager->createQueryBuilder()
            ->select('p.id')
            ->from($parentClass, 'p')
            ->where('p.clubId = :clubId')
            ->andWhere('p.seasonId = :seasonId')
            ->getDQL();

        return (int) $this->entityManager->createQueryBuilder()
            ->delete($entityClass, 'e')
            ->where(\sprintf('e.%s IN (%s)', $parentRefField, $sub))
            ->setParameter('clubId', $clubId)
            ->setParameter('seasonId', $seasonId)
            ->getQuery()
            ->execute();
    }

    private function deleteByClubSeason(string $entityClass, string $clubId, string $seasonId): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->delete($entityClass, 'e')
            ->where('e.clubId = :clubId')
            ->andWhere('e.seasonId = :seasonId')
            ->setParameter('clubId', $clubId)
            ->setParameter('seasonId', $seasonId)
            ->getQuery()
            ->execute();
    }
}
