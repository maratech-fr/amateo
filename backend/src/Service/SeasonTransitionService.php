<?php

declare(strict_types=1);

namespace App\Service;

use App\Access\DemoRegime;
use App\Entity\Club;
use App\Entity\ClubLeagueWindow;
use App\Entity\Coach;
use App\Entity\CoachPlayerMembership;
use App\Entity\Constraint;
use App\Entity\ImplicitRuleSetting;
use App\Entity\MatchConstraint;
use App\Entity\PlanTeam;
use App\Entity\Reservation;
use App\Entity\Season;
use App\Entity\Team;
use App\Entity\TeamCoach;
use App\Entity\TeamLink;
use App\Entity\TeamMatchHabit;
use App\Entity\Venue;
use App\Entity\VenueMatchWindow;
use App\Entity\VenueOccupancy;
use App\Entity\VenueTrainingSlot;
use App\Entity\VenueTravelRuleSetting;
use App\Enum\ConstraintScope;
use App\Enum\SeasonStatus;
use App\Matches\FriendlyCompetitions;
use App\Plans\InForce;
use App\Plans\PlanComposition;
use App\Structure\FamilyCatalog;
use App\Structure\PersonDirectory;
use App\Structure\PlaceDirectory;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Season transition (spec transition-de-saison §2-3, P1): copies the ENTRIES
 * of season N into a fresh N+1 — venues, slots, coaches, teams, links and
 * PERMANENT constraints — never a generated plan (no
 * Schedule/SlotTemplate/Diagnostic and no CalendarEntry: events are re-dated
 * by the P2 guided review). Team tags are computed at read time from the
 * copied teams (see the note below).
 * The copy is a starting point, fully editable via the existing wizard;
 * no link is kept back to season N.
 *
 * N+1 starts with baseline/socle null → the existing cockpit gate forces the
 * baseline work. Its `status` is 'draft' (display metadata only — resolution
 * is calendar-derived, see SeasonResolver).
 *
 * Lot 5, PR 7 — ce qui est repris est la liste du catalogue (`#[Family(…, copiedToNextSeason: true)]`), une routine
 * par famille ({@see self::ROUTINES}). La source est le socle EN VIGUEUR, lu par la porte {@see InForce} : la copie
 * de la version validée (une modification qui attend la validation ne suit pas), ou l'état de travail si le planning
 * de saison n'a jamais été validé. Ni les groupes mutualisés ni l'entente ne suivent.
 *
 * @phpstan-type Run array{
 *     club: string,
 *     source: string,
 *     target: string,
 *     plan: string,
 *     venues: array<string, string>,
 *     coaches: array<string, string>,
 *     teams: array<string, string>,
 *     counts: array{venueTrainingSlots: int, teamCoaches: int, coachPlayerMemberships: int, constraints: int},
 *     socleValues: array<string, array{sessionsPerWeek: int, maxSessionsPerDay: int}>,
 * }
 */
final class SeasonTransitionService
{
    /**
     * La routine de reprise de chaque famille que la saison suivante reprend (famille => méthode), dans l'ordre où
     * elles s'enchaînent : un gymnase, un coach ou une équipe avant ce qui les désigne. Les familles sont exactement
     * celles que le catalogue déclare reprises : la reprise lève sinon, et `FamilyDeclarationSweepTest` le garde.
     */
    public const array ROUTINES = [
        Venue::class => 'copyVenues',
        VenueTrainingSlot::class => 'copyTrainingSlots',
        VenueOccupancy::class => 'copyOccupancies',
        VenueMatchWindow::class => 'copyMatchWindows',
        Coach::class => 'copyCoaches',
        Team::class => 'copyTeams',
        PlanTeam::class => 'copyTeamLines',
        TeamCoach::class => 'copyTeamCoaches',
        CoachPlayerMembership::class => 'copyMemberships',
        TeamMatchHabit::class => 'copyMatchHabits',
        TeamLink::class => 'copyTeamLinks',
        ClubLeagueWindow::class => 'copyLeagueWindows',
        MatchConstraint::class => 'copyMatchConstraints',
        Constraint::class => 'copyConstraints',
        ImplicitRuleSetting::class => 'copyImplicitRuleSettings',
        VenueTravelRuleSetting::class => 'copyTravelRule',
        Reservation::class => 'copyReservations',
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SeasonResolver $seasonResolver,
        private readonly ClockInterface $clock,
        private readonly SchedulePlanProvisioner $schedulePlanProvisioner,
        private readonly ConstraintConfigValidator $constraintConfigValidator,
        private readonly ClubLeagueWindowSeeder $clubLeagueWindowSeeder,
        private readonly DemoRegime $demoRegime,
        private readonly PersonDirectory $personDirectory,
        private readonly PlaceDirectory $placeDirectory,
        private readonly PlanEntitlements $planEntitlements,
        private readonly PlanComposition $planComposition,
        private readonly FriendlyCompetitions $friendlyCompetitions,
        private readonly InForce $inForce,
        private readonly FamilyCatalog $familyCatalog,
    ) {}

    /**
     * Preconditions: the source must be the CURRENT season, and no season of
     * the next season-year may exist yet (409 carrying the existing id — the
     * caller just switches to it).
     */
    public function transition(Season $source, ?DateTimeImmutable $today = null): Season
    {
        // Resolve "today" once from the clock (dev simulator can pin it) and
        // thread it to BOTH the current-season guard AND the target-year anchor
        // in copy() — otherwise a rehearsal transitions to a real-time year.
        $today ??= DateTimeImmutable::createFromInterface($this->clock->now());
        $clubId = $source->getClubId();

        $current = $this->seasonResolver->currentSeason($clubId, $today);
        if (!$current instanceof Season || $current->getId() !== $source->getId()) {
            throw new ConflictHttpException('Seule la saison en cours peut être basculée.');
        }

        // La saison suivante se prépare à partir du 1er mai (borne BASSE seule : la borne haute du
        // 15 juillet reste à l'écran). Un club qui n'a rien préparé avant le pivot attend le 1er mai
        // suivant. « Aujourd'hui » vient de l'horloge du club (simulée pour un club de démonstration).
        if ($today < $this->preparationOpensOn($today)) {
            throw new ConflictHttpException('La saison suivante se prépare à partir du 1er mai.');
        }

        // P1-5 (décision fondateur 2026-08-04) — l'abonnement se paie PAR SAISON :
        // la bascule vers N+1 exige la ligne d'abonnement de N+1, réglée, d'une offre
        // autre que Découverte (une ligne Découverte n'ouvre jamais la bascule). Le gate
        // est LA BASCULE, pas la génération — générer sa saison suivante dès mai puis
        // résilier était la fuite. Ligne écrite par le geste « Offre » du support.
        // MÊME année cible que copy() — un club dormant bascule vers l'année de
        // « demain », pas vers source+1 : le gate doit exiger le paiement de la
        // saison qui sera réellement créée.
        $targetYear = max(
            SeasonResolver::seasonYear($source->getStartDate()) + 1,
            SeasonResolver::seasonYear($today) + 1,
        );
        $club = $this->entityManager->getRepository(Club::class)->find($clubId);
        // P2-4 (fondateur 2026-08-07) — un club de DÉMONSTRATION a un abonnement
        // illimité : la bascule de saison se montre en rendez-vous (souvent sous
        // horloge simulée), jamais bloquée par un paiement qui n'existe pas.
        $isDemo = $club instanceof Club && $this->demoRegime->isUnlimited($club);
        if (!$club instanceof Club || (!$isDemo && !$this->planEntitlements->isSeasonPaid($clubId, $targetYear))) {
            throw new ConflictHttpException(\sprintf('La saison %d-%d n\'est pas réglée — la bascule est réservée aux saisons payées.', $targetYear, $targetYear + 1));
        }

        // The caller's request may have the season_filter enabled on ANY
        // selected season; the copy reads the SOURCE season explicitly
        // (clubId + seasonId in every query, tenant filter + RLS still on).
        // Not re-enabled: the request ends right after the response.
        $filters = $this->entityManager->getFilters();
        if ($filters->isEnabled('season_filter')) {
            $filters->disable('season_filter');
        }

        return $this->entityManager->wrapInTransaction(function () use ($source, $clubId, $today): Season {
            // Serialize concurrent transitions of the same club: without this,
            // two requests both pass the successor check and fork the club into
            // duplicate N+1 seasons (no DB unique on club_id + season-year).
            $this->entityManager->getConnection()->executeStatement(
                'SELECT pg_advisory_xact_lock(hashtext(:club))',
                ['club' => 'season-transition:' . $clubId],
            );

            // Re-check successor existence INSIDE the lock (the other request
            // may have committed its N+1 while we waited on the lock).
            $sourceYear = SeasonResolver::seasonYear($source->getStartDate());
            foreach ($this->seasonResolver->seasonsForClub($clubId) as $existing) {
                if (SeasonResolver::seasonYear($existing->getStartDate()) === $sourceYear + 1) {
                    throw new SeasonAlreadyTransitionedException($existing->getId());
                }
            }

            return $this->copy($source, $today);
        });
    }

    /**
     * Le jour où la saison suivante s'ouvre à la préparation : le 1er mai qui précède le pivot à
     * venir, soit `(année de saison d'aujourd'hui + 1)-05-01`. Servi par `/api/me`.
     */
    public function preparationOpensOn(DateTimeImmutable $today): DateTimeImmutable
    {
        return $today->setTime(0, 0)->setDate(SeasonResolver::seasonYear($today) + 1, 5, 1);
    }

    /**
     * Les clés de `config` qui portent un id d'entité, DÉRIVÉES de la liste blanche.
     *
     * ⚑ Cette liste était écrite à la main et avait dérivé dans les deux sens (audit D-08,
     * 2026-08-08) : elle **oubliait `forcedVenueId` et `minAtVenueId`** — une contrainte
     * « impose ce gymnase » ou « au moins N séances ici » recopiée en saison N+1 gardait
     * l'uuid du gymnase de l'ANCIENNE saison, et comme la clé n'était pas parcourue,
     * `$configDangling` restait `false` : aucun skip, aucun log, un pointeur mort en base.
     * Elle traînait par ailleurs trois clés qui n'existent plus (`venueId`, `teamId`) ou
     * qui ont été supprimées (`coachId`, doublon du scope retiré par SEC-13).
     *
     * Toutes les clés uuid connues visent aujourd'hui un gymnase ; le jour où une clé uuid
     * d'une autre famille apparaît, `uuidKeys()` la remonte et ce mapping doit la nommer —
     * c'est ce que `SeasonTransitionConfigKeysTest` exige.
     *
     * @return array<string, string> clé de config => nom de la carte d'ids
     */
    private function configIdKeys(): array
    {
        return array_fill_keys($this->constraintConfigValidator->uuidKeys(), 'venues');
    }

    private function copy(Season $source, ?DateTimeImmutable $today = null): Season
    {
        $clubId = $source->getClubId();
        $sourceId = $source->getId();

        // Shift the source window by whole years so the target lands in the
        // NEXT season-year (source-year + 1). Anchored to today so a dormant
        // club whose stale current season is years old still gets a next
        // season that is actually upcoming, not another past one.
        $today ??= DateTimeImmutable::createFromInterface($this->clock->now());
        $targetYear = max(
            SeasonResolver::seasonYear($source->getStartDate()) + 1,
            SeasonResolver::seasonYear($today) + 1,
        );
        $yearsToAdd = $targetYear - SeasonResolver::seasonYear($source->getStartDate());
        $shift = \sprintf('+%d year', $yearsToAdd);

        $target = new Season;
        $target->setClubId($clubId);
        $target->setName($this->nextName($source->getName(), $yearsToAdd));
        $target->setStartDate($source->getStartDate()->modify($shift));
        $target->setEndDate($source->getEndDate()->modify($shift));
        $target->setStatus(SeasonStatus::DRAFT);
        $target->setTransitionData([]);
        $this->entityManager->persist($target);

        // ADR-0002 Lot A: the copied season starts with its empty SEASON plan — lot 4 : il porte la
        // structure partagée reprise (grille, occupations, règles de bien-être), comme celui de la source.
        $targetPlanId = $this->schedulePlanProvisioner->ensureSeasonPlan($target)->getId();
        // Lot 7, PR 4a — « Amicaux », la compétition d'office, naît avec la saison suivante.
        $this->friendlyCompetitions->ensureDefault($clubId, $target->getId());

        // La liste de ce qui est repris est celle du catalogue ; une famille déclarée reprise sans routine, ou une
        // routine d'une famille qui ne l'est pas, ne passe jamais en silence.
        $declared = $this->familyCatalog->copiedToNextSeason();
        $gaps = [...array_diff($declared, array_keys(self::ROUTINES)), ...array_diff(array_keys(self::ROUTINES), $declared)];
        if ([] !== $gaps) {
            throw new LogicException(\sprintf('La reprise de la saison suivante ne suit pas la déclaration des familles : %s.', implode(', ', $gaps)));
        }

        /** @var Run $run */
        $run = [
            'club' => $clubId,
            'source' => $sourceId,
            'target' => $target->getId(),
            'plan' => $targetPlanId,
            'venues' => [],
            'coaches' => [],
            'teams' => [],
            'counts' => ['venueTrainingSlots' => 0, 'teamCoaches' => 0, 'coachPlayerMemberships' => 0, 'constraints' => 0],
            'socleValues' => [],
        ];
        foreach (self::ROUTINES as $routine) {
            $this->{$routine}($run);
        }

        $counts = [
            'venues' => \count($run['venues']),
            'venueTrainingSlots' => $run['counts']['venueTrainingSlots'],
            'coaches' => \count($run['coaches']),
            'teams' => \count($run['teams']),
            'teamCoaches' => $run['counts']['teamCoaches'],
            'coachPlayerMemberships' => $run['counts']['coachPlayerMemberships'],
            'constraints' => $run['counts']['constraints'],
        ];

        $target->setTransitionData([
            'sourceSeasonId' => $sourceId,
            'copiedAt' => (new DateTimeImmutable)->format(\DATE_ATOM),
            'counts' => $counts,
        ]);
        $source->setTransitionData(array_merge($source->getTransitionData(), [
            'transitionedTo' => $target->getId(),
        ]));

        // Team tags are NOT copied: they are computed at read time from each
        // copied team's category, gender and level (TeamTagService::tagNamesOf).
        // Nothing stores them, and the closed catalogue has no custom tag.

        $this->entityManager->flush();
        $this->planComposition->ensureLines($target->getId(), $run['socleValues']);

        return $target;
    }

    /**
     * Les lignes en vigueur d'une famille dans la saison source : la copie de la version validée du socle, ou les
     * lignes de travail si rien n'a été validé ({@see InForce::rows}). Une famille de niveau plan y est lue sur le
     * plan de saison seul : un créneau PRÊTÉ, une occupation ou un réglage d'un plan de période appartiennent à la
     * période (« la mairie me prête ce gymnase POUR cet ajustement d'octobre » n'a aucun sens en N+1 : copié sur le
     * plan de saison, il deviendrait un créneau SAISONNIER PERMANENT du club).
     *
     * @template T of object
     *
     * @param Run             $run
     * @param class-string<T> $familyClass
     *
     * @return list<T>
     */
    private function sourceRows(array $run, string $familyClass): array
    {
        return $this->inForce->rows($run['source'], $familyClass);
    }

    /** @param Run $run */
    private function copyVenues(array &$run): void
    {
        foreach ($this->sourceRows($run, Venue::class) as $venue) {
            // Le gymnase de la saison suivante est l'usage du même lieu : adresse et position ne se
            // recopient pas, elles vivent une fois au club.
            $copy = new Venue($this->placeDirectory->of($venue));
            $copy->setClubId($run['club']);
            $copy->setSeasonId($run['target']);
            $copy->setName($venue->getName());
            $copy->setColor($venue->getColor());
            $copy->setCanSplit($venue->getCanSplit());
            $copy->setSource($venue->getSource());
            $copy->setExternalRef($venue->getExternalRef());
            // P4-187a — les alias de salle confirmés sont une convention permanente
            // du gymnase (comme son nom) : ils suivent la copie N+1.
            $copy->setExternalLabels($venue->getExternalLabels());
            $copy->setIsActive($venue->getIsActive());
            $this->entityManager->persist($copy);
            $run['venues'][$venue->getId()] = $copy->getId();
        }
    }

    /** @param Run $run */
    private function copyTrainingSlots(array &$run): void
    {
        // Seule la structure PARTAGÉE se transmet (le plan de saison, inv. 6).
        foreach ($this->sourceRows($run, VenueTrainingSlot::class) as $slot) {
            $newVenueId = $run['venues'][$slot->getVenueId()] ?? null;
            if (null === $newVenueId) {
                continue; // dangling venue reference — nothing to attach to.
            }
            $copy = new VenueTrainingSlot;
            $copy->setClubId($run['club']);
            $copy->setSeasonId($run['target']);
            $copy->setVenueId($newVenueId);
            $copy->setDayOfWeek($slot->getDayOfWeek());
            $copy->setStartTime($slot->getStartTime());
            $copy->setDurationMinutes($slot->getDurationMinutes());
            $copy->setCapacity($slot->getCapacity());
            $copy->setSchedulePlanId($run['plan']);
            $this->entityManager->persist($copy);
            ++$run['counts']['venueTrainingSlots'];
        }
    }

    /** @param Run $run */
    private function copyOccupancies(array &$run): void
    {
        // Les occupations de SAISON suivent les créneaux vers les gymnases copiés ; celles d'une
        // période restent à leur plan, une dormante (gymnase non copié) est ignorée. Sans contrôle.
        foreach ($this->sourceRows($run, VenueOccupancy::class) as $occupancy) {
            $newVenueId = $run['venues'][$occupancy->getVenueId()] ?? null;
            if (null === $newVenueId) {
                continue;
            }
            $this->entityManager->persist(
                (new VenueOccupancy)->setClubId($run['club'])->setSeasonId($run['target'])->setVenueId($newVenueId)
                    ->setDayOfWeek($occupancy->getDayOfWeek())->setStartTime($occupancy->getStartTime())
                    ->setDurationMinutes($occupancy->getDurationMinutes())->setLabel($occupancy->getLabel())
                    ->setSchedulePlanId($run['plan']),
            );
        }
    }

    /** @param Run $run */
    private function copyMatchWindows(array &$run): void
    {
        // P1-4 PR B — les fenêtres d'accès MATCH se recopient comme les créneaux :
        // la convention mairie se renouvelle.
        foreach ($this->sourceRows($run, VenueMatchWindow::class) as $window) {
            // Une ouverture datée est un fait d'un jour, comme une indisponibilité : jamais recopiée.
            if ($window->getOpeningDate() instanceof DateTimeImmutable) {
                continue;
            }
            $newVenueId = $run['venues'][$window->getVenueId()] ?? null;
            if (null === $newVenueId) {
                continue; // dangling venue reference — nothing to attach to.
            }
            $copy = new VenueMatchWindow;
            $copy->setClubId($run['club']);
            $copy->setSeasonId($run['target']);
            $copy->setVenueId($newVenueId);
            $copy->setDayOfWeek($window->getDayOfWeek());
            // PR2 — la ligne « Jours fériés » (dayOfWeek nul + ce drapeau) se recopie comme les autres.
            $copy->setAppliesOnPublicHolidays($window->isAppliesOnPublicHolidays());
            $copy->setStartTime($window->getStartTime());
            $copy->setEndTime($window->getEndTime());
            $this->entityManager->persist($copy);
        }
    }

    /** @param Run $run */
    private function copyCoaches(array &$run): void
    {
        foreach ($this->sourceRows($run, Coach::class) as $coach) {
            // La saison suivante réutilise la fiche de la personne (nom, mail, téléphone, genre) :
            // seul le rôle de la saison se recopie.
            $copy = new Coach($this->personDirectory->personOf($coach));
            $copy->setClubId($run['club']);
            $copy->setSeasonId($run['target']);
            $copy->setMaxDaysOverride($coach->getMaxDaysOverride());
            $copy->setIsActive($coach->getIsActive());
            $copy->setIsEmployee($coach->isEmployee());
            // La case « véhiculé » (le coach peut transporter) suit la copie N+1 comme les autres
            // options du rôle.
            $copy->setIsVehicled($coach->isVehicled());
            $this->entityManager->persist($copy);
            $run['coaches'][$coach->getId()] = $copy->getId();
        }
    }

    /**
     * L'entente (`isCtc`, `ctcPartner`) ne suit pas : la saison suivante la redit, ou non.
     *
     * @param Run $run
     */
    private function copyTeams(array &$run): void
    {
        foreach ($this->sourceRows($run, Team::class) as $team) {
            $copy = new Team;
            $copy->setClubId($run['club']);
            $copy->setSeasonId($run['target']);
            // Club-scoped referentials (category, tier) are shared across seasons.
            $copy->setSportCategoryId($team->getSportCategoryId());
            $copy->setPriorityTierId($team->getPriorityTierId());
            $copy->setTierOrder($team->getTierOrder());
            // Free label, copied as-is — NEVER auto-renamed (spec §6.7).
            $copy->setName($team->getName());
            $copy->setLevel($team->getLevel());
            $copy->setGender($team->getGender());
            $copy->setSize($team->getSize());
            $copy->setMinSessionsOverride($team->getMinSessionsOverride());
            $copy->setMatchDay($team->getMatchDay());
            $forcedVenueId = $team->getForcedVenueId();
            $copy->setForcedVenueId(null !== $forcedVenueId ? ($run['venues'][$forcedVenueId] ?? null) : null);
            $copy->setIsActive($team->getIsActive());
            $this->entityManager->persist($copy);
            $run['teams'][$team->getId()] = $copy->getId();
        }
    }

    /**
     * Lot 4, PR 3a — les séances et le plafond de chaque équipe : sa ligne au socle de la source, reprise sur la ligne
     * du socle de la nouvelle saison (posée à la fin, par la seule porte).
     *
     * @param Run $run
     */
    private function copyTeamLines(array &$run): void
    {
        foreach ($this->sourceRows($run, PlanTeam::class) as $line) {
            $teamId = $run['teams'][$line->getTeamId()] ?? null;
            if (null !== $teamId) {
                $run['socleValues'][$teamId] = ['sessionsPerWeek' => $line->getSessionsPerWeek(), 'maxSessionsPerDay' => $line->getMaxSessionsPerDay()];
            }
        }
    }

    /** @param Run $run */
    private function copyTeamCoaches(array &$run): void
    {
        foreach ($this->sourceRows($run, TeamCoach::class) as $link) {
            $teamId = $run['teams'][$link->getTeamId()] ?? null;
            $coachId = $run['coaches'][$link->getCoachId()] ?? null;
            if (null === $teamId || null === $coachId) {
                continue;
            }
            $copy = new TeamCoach;
            $copy->setClubId($run['club']);
            $copy->setSeasonId($run['target']);
            $copy->setTeamId($teamId);
            $copy->setCoachId($coachId);
            $copy->setRole($link->getRole());
            // Le premier lié reste le défaut, et le choix du gestionnaire reste retenu.
            $copy->setCreatedAt($link->getCreatedAt());
            $copy->setWishRecipient($link->isWishRecipient());
            $this->entityManager->persist($copy);
            ++$run['counts']['teamCoaches'];
        }
    }

    /** @param Run $run */
    private function copyMemberships(array &$run): void
    {
        foreach ($this->sourceRows($run, CoachPlayerMembership::class) as $membership) {
            $teamId = $run['teams'][$membership->getTeamId()] ?? null;
            $coachId = $run['coaches'][$membership->getCoachId()] ?? null;
            if (null === $teamId || null === $coachId) {
                continue;
            }
            $copy = new CoachPlayerMembership;
            $copy->setClubId($run['club']);
            $copy->setSeasonId($run['target']);
            $copy->setCoachId($coachId);
            $copy->setTeamId($teamId);
            $copy->setPosition($membership->getPosition());
            $copy->setIsActive($membership->getIsActive());
            $this->entityManager->persist($copy);
            ++$run['counts']['coachPlayerMemberships'];
        }
    }

    /**
     * P1-4 PR C — « nous sommes des êtres d'habitude » : habitudes ET passerelles suivent la saison (remap équipe +
     * gymnase), même statut que les fenêtres d'accès mairie.
     *
     * @param Run $run
     */
    private function copyMatchHabits(array &$run): void
    {
        foreach ($this->sourceRows($run, TeamMatchHabit::class) as $habit) {
            $teamId = $run['teams'][$habit->getTeamId()] ?? null;
            if (null === $teamId) {
                continue;
            }
            $copy = new TeamMatchHabit;
            $copy->setClubId($run['club']);
            $copy->setSeasonId($run['target']);
            $copy->setTeamId($teamId);
            $copy->setDayOfWeek($habit->getDayOfWeek());
            $copy->setKickoffTime($habit->getKickoffTime());
            // A dangling venue reference degrades to a day+time habit — the
            // habit survives, its venue anchor does not.
            $copy->setVenueId(null !== $habit->getVenueId() ? ($run['venues'][$habit->getVenueId()] ?? null) : null);
            // P4-271 — le tag de semaine A/B se renouvelle avec la saison.
            $copy->setWeek($habit->getWeek());
            $this->entityManager->persist($copy);
        }
    }

    /** @param Run $run */
    private function copyTeamLinks(array &$run): void
    {
        foreach ($this->sourceRows($run, TeamLink::class) as $link) {
            $teamAId = $run['teams'][$link->getTeamAId()] ?? null;
            $teamBId = $run['teams'][$link->getTeamBId()] ?? null;
            if (null === $teamAId || null === $teamBId) {
                continue;
            }
            // The copies keep the normalized order invariant (teamAId < teamBId):
            // the remapped uuids are new, so re-normalize.
            if (strcasecmp($teamAId, $teamBId) > 0) {
                [$teamAId, $teamBId] = [$teamBId, $teamAId];
            }
            $copy = new TeamLink;
            $copy->setClubId($run['club']);
            $copy->setSeasonId($run['target']);
            $copy->setTeamAId($teamAId);
            $copy->setTeamBId($teamBId);
            $copy->setLinkType($link->getLinkType());
            // Passerelles PR-1 : l'intensité côté entraînement se recopie en N+1 (l'oublier
            // serait une régression silencieuse — gardé par le test de transition).
            $copy->setTrainingIntensity($link->getTrainingIntensity());
            $this->entityManager->persist($copy);
        }
    }

    /**
     * P4-272 ① — la COPIE club de l'enveloppe ligue suit la saison : les corrections du gestionnaire (fenêtres
     * éditées/ajoutées/supprimées) se renouvellent en N+1, comme les fenêtres d'accès mairie. Recopie verbatim (aucune
     * référence gymnase/équipe à remapper). Si la source n'en a aucune (club antérieur au backfill), on retombe sur la
     * recopie de la ligue effective pour ne jamais laisser N+1 sans envelope.
     *
     * @param Run $run
     */
    private function copyLeagueWindows(array &$run): void
    {
        $leagueWindowCopies = 0;
        foreach ($this->sourceRows($run, ClubLeagueWindow::class) as $window) {
            $copy = new ClubLeagueWindow;
            $copy->setClubId($run['club']);
            $copy->setSeasonId($run['target']);
            $copy->setLeague($window->getLeague());
            $copy->setCategory($window->getCategory());
            $copy->setLevel($window->getLevel());
            $copy->setGender($window->getGender());
            $copy->setDayOfWeek($window->getDayOfWeek());
            $copy->setKickoffMin($window->getKickoffMin());
            $copy->setKickoffMax($window->getKickoffMax());
            $this->entityManager->persist($copy);
            ++$leagueWindowCopies;
        }
        if (0 === $leagueWindowCopies) {
            $club = $this->entityManager->getRepository(Club::class)->find($run['club']);
            $this->clubLeagueWindowSeeder->seedForSeason($run['club'], $run['target'], $club?->getLeague());
        }
    }

    /**
     * P4-272 ③+④+⑤ — les RÈGLES DE MATCH suivent la saison, comme les corrections du gestionnaire. CLUB (③) : recopie
     * VERBATIM (ni gymnase ni équipe à remapper — scopeTargetId/venueId nuls). TEAM (④, interdiction de gymnase) :
     * remap de l'équipe ET du gymnase. COACH (⑤, indisponibilité) : remap du coach, jours + fourchette conservés. Une
     * référence PENDANTE (l'entité visée n'existe plus en N+1, comme une habitude à gymnase disparu) fait ABANDONNER la
     * ligne — jamais un pointeur mort en base. FACILITY : pas encore émis.
     *
     * @param Run $run
     */
    private function copyMatchConstraints(array &$run): void
    {
        foreach ($this->sourceRows($run, MatchConstraint::class) as $rule) {
            $copy = new MatchConstraint;
            $copy->setClubId($run['club']);
            $copy->setSeasonId($run['target']);
            $copy->setRuleType($rule->getRuleType());
            $copy->setDaysOfWeek($rule->getDaysOfWeek());
            $copy->setKickoffMin($rule->getKickoffMin());
            $copy->setKickoffMax($rule->getKickoffMax());
            if (ConstraintScope::CLUB === $rule->getScope()) {
                $copy->setScope(ConstraintScope::CLUB);
                $copy->setScopeTargetId(null);
                $copy->setVenueId(null);
            } elseif (ConstraintScope::TEAM === $rule->getScope()) {
                $sourceTeamId = $rule->getScopeTargetId();
                $sourceVenueId = $rule->getVenueId();
                $teamId = null !== $sourceTeamId ? ($run['teams'][$sourceTeamId] ?? null) : null;
                $venueId = null !== $sourceVenueId ? ($run['venues'][$sourceVenueId] ?? null) : null;
                // Référence pendante des deux côtés obligatoires → ligne abandonnée.
                if (null === $teamId || null === $venueId) {
                    continue;
                }
                $copy->setScope(ConstraintScope::TEAM);
                $copy->setScopeTargetId($teamId);
                $copy->setVenueId($venueId);
            } elseif (ConstraintScope::COACH === $rule->getScope()) {
                $sourceCoachId = $rule->getScopeTargetId();
                $coachId = null !== $sourceCoachId ? ($run['coaches'][$sourceCoachId] ?? null) : null;
                // Coach pendant (supprimé en N) → ligne abandonnée.
                if (null === $coachId) {
                    continue;
                }
                $copy->setScope(ConstraintScope::COACH);
                $copy->setScopeTargetId($coachId);
                $copy->setVenueId(null);
            } else {
                // FACILITY : non émis aujourd'hui (le processeur le refuse) ; une ligne héritée
                // d'un état antérieur ne se propage pas sans règle de remap.
                continue;
            }
            $this->entityManager->persist($copy);
        }
    }

    /**
     * Permanent constraints only: dated ones (calendarEntryId set) belong to season-N periods and die with them. Les
     * temps de trajet vivent au club entre deux lieux (lot 3, PR 5) : les gymnases copiés pointent les mêmes lieux, la
     * saison suivante lit les mêmes temps, rien n'est recopié.
     *
     * @param Run $run
     */
    private function copyConstraints(array &$run): void
    {
        $maps = ['venues' => $run['venues'], 'coaches' => $run['coaches'], 'teams' => $run['teams']];
        foreach ($this->sourceRows($run, Constraint::class) as $constraint) {
            if (null !== $constraint->getCalendarEntryId()) {
                continue;
            }
            [$scopeTargetId, $scopeDangling] = $this->remapScopeTarget($constraint, $maps);
            // A dangling scope target (referenced entity already gone in N) →
            // the constraint is broken in N already; do NOT propagate an
            // invalid row (it would fail validation and skip enforcement).
            if ($scopeDangling) {
                continue;
            }
            [$config, $configDangling] = $this->remapConfig($constraint->getConfig(), $maps);
            if ($configDangling) {
                continue;
            }
            $copy = new Constraint;
            $copy->setClubId($run['club']);
            $copy->setSeasonId($run['target']);
            $copy->setName($constraint->getName());
            $copy->setDescription($constraint->getDescription());
            $copy->setScope($constraint->getScope());
            $copy->setScopeTargetId($scopeTargetId);
            $copy->setFamily($constraint->getFamily());
            $copy->setRuleType($constraint->getRuleType());
            $copy->setConfig($config);
            $copy->setCreatedBy($constraint->getCreatedBy());
            $copy->setSource($constraint->getSource());
            $copy->setSourceOccurrenceId($constraint->getSourceOccurrenceId());
            $copy->setIsActive($constraint->getIsActive());
            $copy->setSortOrder($constraint->getSortOrder());
            $this->entityManager->persist($copy);
            ++$run['counts']['constraints'];
        }
    }

    /**
     * Réglages des règles implicites (bien-être) du plan de saison : recopiés tels quels — aucune référence d'entité à
     * remapper, une règle est un cran d'intensité + un seuil. Une saison N+1 hérite donc des assouplissements décidés
     * en N (au lieu de repartir tout HARD). Les COPIES de période ne suivent pas : leurs plans N n'existent pas en N+1.
     * La copie porte le plan de saison de la nouvelle saison. Pas comptabilisé dans `counts`.
     *
     * @param Run $run
     */
    private function copyImplicitRuleSettings(array &$run): void
    {
        foreach ($this->sourceRows($run, ImplicitRuleSetting::class) as $setting) {
            $copy = new ImplicitRuleSetting;
            $copy->setClubId($run['club']);
            $copy->setSeasonId($run['target']);
            $copy->setSchedulePlanId($run['plan']);
            $copy->setRuleKey($setting->getRuleKey());
            $copy->setIntensity($setting->getIntensity());
            $copy->setParams($setting->getParams());
            $this->entityManager->persist($copy);
        }
    }

    /**
     * P2-53 RMM-8 PR-4 — le levier d'intensité de la règle de trajet (PREFERRED|MANDATORY) suit la saison, comme la
     * matrice qu'il gouverne : un club qui a mis « Obligatoire » en N le retrouve en N+1. Seul le cran suit : battement
     * et temps par défaut repartent à leurs défauts. Absence de ligne = défaut, rien à copier. Pas comptabilisé dans
     * `counts`.
     *
     * @param Run $run
     */
    private function copyTravelRule(array &$run): void
    {
        $travelRule = $this->sourceRows($run, VenueTravelRuleSetting::class)[0] ?? null;
        if ($travelRule instanceof VenueTravelRuleSetting) {
            $copy = new VenueTravelRuleSetting;
            $copy->setClubId($run['club']);
            $copy->setSeasonId($run['target']);
            $copy->setIntensity($travelRule->getIntensity());
            $this->entityManager->persist($copy);
        }
    }

    /**
     * Lot 5, PR 7 — les réservations du planning de saison suivent : celle d'une équipe vers l'équipe copiée, un créneau
     * libre avec son libellé, au gymnase copié, sur le plan de saison de la nouvelle saison. Une référence pendante
     * (équipe ou gymnase non copié) fait abandonner la ligne. Pas comptabilisé dans `counts`.
     *
     * @param Run $run
     */
    private function copyReservations(array &$run): void
    {
        foreach ($this->sourceRows($run, Reservation::class) as $reservation) {
            $venueId = $run['venues'][$reservation->getVenueId()] ?? null;
            $sourceTeamId = $reservation->getTeamId();
            $teamId = null !== $sourceTeamId ? ($run['teams'][$sourceTeamId] ?? null) : null;
            if (null === $venueId || (null !== $sourceTeamId && null === $teamId)) {
                continue;
            }
            $this->entityManager->persist(
                (new Reservation)->setClubId($run['club'])->setSeasonId($run['target'])->setSchedulePlanId($run['plan'])
                    ->setTeamId($teamId)->setLabel($reservation->getLabel())->setVenueId($venueId)
                    ->setDayOfWeek($reservation->getDayOfWeek())->setStartTime($reservation->getStartTime())
                    ->setDurationMinutes($reservation->getDurationMinutes())
                    ->setCoachLateToleranceMinutes($reservation->getCoachLateToleranceMinutes()),
            );
        }
    }

    /**
     * @param array{venues: array<string,string>, coaches: array<string,string>, teams: array<string,string>} $maps
     *
     * @return array{0: ?string, 1: bool} [remapped id | null, dangling?] —
     *                                    dangling = a non-CLUB scope whose target has no copy (broken in N)
     */
    private function remapScopeTarget(Constraint $constraint, array $maps): array
    {
        $targetId = $constraint->getScopeTargetId();
        if (null === $targetId) {
            return [null, false]; // CLUB scope (or targetless) — nothing to remap.
        }

        $mapped = match ($constraint->getScope()) {
            ConstraintScope::TEAM => $maps['teams'][$targetId] ?? null,
            ConstraintScope::COACH => $maps['coaches'][$targetId] ?? null,
            ConstraintScope::FACILITY => $maps['venues'][$targetId] ?? null,
            default => null,
        };

        return [$mapped, null === $mapped];
    }

    /**
     * @param array<string, mixed>                                                                            $config
     * @param array{venues: array<string,string>, coaches: array<string,string>, teams: array<string,string>} $maps
     *
     * @return array{0: array<string, mixed>, 1: bool} [remapped config, dangling?] —
     *                                                 dangling = a config id key points at an entity with no copy
     */
    private function remapConfig(array $config, array $maps): array
    {
        foreach ($this->configIdKeys() as $key => $mapName) {
            if (isset($config[$key]) && \is_string($config[$key])) {
                $mapped = $maps[$mapName][$config[$key]] ?? null;
                if (null === $mapped) {
                    // Never carry a season-N id into N+1 — the reference is dead.
                    return [$config, true];
                }
                $config[$key] = $mapped;
            }
        }

        return [$config, false];
    }

    /** "2025" → "2026" · "2025-2026" → "2026-2027" · shifts each 4-digit year by $yearsToAdd; other labels kept. */
    private function nextName(string $name, int $yearsToAdd): string
    {
        $bumped = preg_replace_callback('/\d{4}/', static fn (array $m): string => (string) ((int) $m[0] + $yearsToAdd), $name);

        return $bumped ?? $name;
    }
}
