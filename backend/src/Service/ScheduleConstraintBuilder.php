<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CalendarEntry;
use App\Entity\Coach;
use App\Entity\CoachPlayerMembership;
use App\Entity\Constraint;
use App\Entity\MatchSlotRotation;
use App\Entity\MatchSlotRotationTeam;
use App\Entity\PriorityTier;
use App\Entity\Reservation;
use App\Entity\Schedule;
use App\Entity\SchedulePlan;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\SharedTrainingGroup;
use App\Entity\SharedTrainingGroupTeam;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\TeamCoach;
use App\Entity\TeamLink;
use App\Entity\TeamMatchHabit;
use App\Entity\TeamTag;
use App\Entity\TeamTagAssignment;
use App\Entity\Venue;
use App\Entity\VenueTrainingSlot;
use App\Entity\VenueTravelTime;
use App\Enum\ConstraintFamily;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use App\Enum\LockLevel;
use App\Enum\SchedulePlanType;
use App\Enum\TeamLinkIntensity;
use App\Repository\VenueTrainingSlotRepository;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\ItemInterface;

final class ScheduleConstraintBuilder
{
    /**
     * Les clés de `config` qui nomment un gymnase. Partagées avec le gate pré-solve
     * (ValidateConstraintsController) : les deux doivent voir le MÊME jeu de contraintes,
     * sans quoi le récap annonce applicable ce que le solveur ne recevra pas.
     */
    public const VENUE_CONFIG_KEYS = ['forcedVenueId', 'preferredVenueId', 'minAtVenueId', 'forbiddenVenueId', 'setVenueId'];
    /**
     * Version du CONTRAT backend⇄engine que ce payload s'attribue. Ce n'est pas
     * une version « de schéma » indépendante : c'est la MÊME chose que
     * `engine/CONTRACT_VERSION`, que l'engine compare au champ `version` reçu.
     * Elle DOIT valoir exactement la valeur du fichier — gardé par
     * `PayloadVersionMatchesContractVersionTest`.
     */
    public const string CONTRACT_VERSION = '2.16';
    private const CACHE_TTL_SECONDS = 14_400;
    private const DEFAULT_SOLVER_SEED = 42;
    /**
     * P2-53 RMM-8 PR-2 — le barème de trajet appliqué à un couple de gymnases jamais arbitré
     * (« défaut 20 min pour une paire jamais arbitrée », arbitrage fondateur). Émis dans la
     * règle implicite `travelTime` ; le moteur l'applique quand la colonne voiture/à pied d'un
     * couple est nulle ou absente.
     */
    private const TRAVEL_TIME_DEFAULT_MINUTES = 20;
    /**
     * Upper bound on the solve budget (seconds), aligned with the engine input
     * schema default (`solver_timeout_seconds` = 650). The engine derives an
     * adaptive timeout from problem size and caps it at this ceiling — this is
     * the maximum a manager can be made to wait, not a fixed solve time.
     */
    private const DEFAULT_SOLVER_TIMEOUT_SECONDS = 650;

    /** @var array<string, array<VenueTrainingSlot>> */
    private array $currentAvailabilitiesByVenue = [];

    /**
     * Period-editable structure: teamId → period sessions-per-week override, set
     * only during an overlay build (serializeTeam reads it, else the seasonal value).
     *
     * @var array<string, int>
     */
    private array $currentSessionOverrides = [];

    /**
     * BCK-04 (assumed by design): the three enrichment deps are nullable ON
     * PURPOSE, not by omission. In production the container always autowires
     * them (no runtime null risk). Nullability enables the **light, DB-free
     * mode**: passing only the logger lets a caller build a payload purely from
     * the entities handed to `buildPayload(...)`, skipping cache / tag /
     * sport-category / venue-slot enrichment via the `instanceof` guards below.
     * The blocking `CrossStack/ContractSchemaTest` relies on this to assert the
     * backend↔engine payload SHAPE without a database. Forcing them non-nullable
     * would only push mocks into that critical test for zero prod benefit.
     *
     * P2-9ter : `TeamTagService` a été RETIRÉ d'ici — le builder ne synchronise
     * plus les tags, il les lit (cf. serializeTeam). Le service reste injecté
     * dans `TeamTagSyncListener`, qui les maintient au write-path.
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ?EntityManagerInterface $entityManager = null,
        #[Autowire(service: 'cache.schedule')]
        private readonly ?CacheItemPoolInterface $scheduleCachePool = null,
        private readonly ?VenueTrainingSlotRepository $venueTrainingSlotRepository = null,
        // P2-14 : la résolution de tag et la sélection de période sont EXTERNES (sources
        // uniques partagées avec le gate pré-solve). Nullables pour le même mode léger
        // sans DB que les trois deps ci-dessus — le chemin overlay, lui, les exige.
        private readonly ?TeamTagResolver $tagResolver = null,
        private readonly ?PeriodConstraintSelector $periodConstraintSelector = null,
        // Résout le bloc `implicitRules` (règles bien-être réglables, contrat 2.7). Nullable
        // pour le mode léger sans DB : le chemin `build()` en mémoire retombe alors sur les
        // défauts (tout HARD), byte-identique au payload historique de PR 1 côté absence.
        private readonly ?ImplicitRuleResolver $implicitRuleResolver = null,
    ) {}

    /**
     * P2-9ter — la clé porte la SAISON : `buildForClubSeason` en reçoit une, et sans elle
     * un club à deux saisons se voyait resservir le payload de l'autre.
     */
    public static function cacheKey(string $clubId, string $seasonId): string
    {
        return \sprintf('club.%s.season.%s.schedule_input', $clubId, $seasonId);
    }

    /**
     * Le tag porté par TOUTES les entrées payload d'un club, saisons confondues.
     *
     * ⚠ C'est ce qui rend la clé par saison SÛRE. `CacheInvalidationListener` purge par
     * CLUB, or deux entités du payload (`SportCategory`, `TeamTag`) portent un `clubId`
     * mais AUCUN `seasonId` : les éditer ne pourrait plus viser aucune clé. Le listener
     * invalide donc ce tag plutôt que de reconstruire une clé — d'où source unique, et
     * plus de « changer la clé d'un seul côté casse l'invalidation en silence ».
     */
    public static function cacheTag(string $clubId): string
    {
        return \sprintf('club.%s.schedule_input', $clubId);
    }

    private static function formatNullableTime(?DateTimeInterface $time): ?string
    {
        return $time?->format('H:i:s');
    }

    /** @return array<string, mixed> */
    public function buildForClubSeason(string $clubId, string $seasonId, int $solverSeed = self::DEFAULT_SOLVER_SEED, ?EntityManagerInterface $entityManager = null): array
    {
        $em = $entityManager ?? $this->entityManager;
        if (!$em instanceof EntityManagerInterface) {
            throw new LogicException('ScheduleConstraintBuilder requires Doctrine for club/season builds.');
        }

        $cacheItem = null;
        if ($this->scheduleCachePool instanceof CacheItemPoolInterface) {
            $cacheItem = $this->scheduleCachePool->getItem(self::cacheKey($clubId, $seasonId));
            if ($cacheItem->isHit()) {
                $cached = $cacheItem->get();
                if (\is_array($cached)) {
                    return $cached;
                }
            }
        }

        // Base plan only: dated constraints (attached to a CalendarEntry period)
        // are excluded from generation. See accueil-cockpit-temporel.md §9ter.c.
        $constraints = $em->getRepository(Constraint::class)->findPermanentByClubSeason($clubId, $seasonId);

        // Pre-load venue availabilities to avoid N+1 queries in serializeVenue().
        // Base plan only: SEASONAL slots (calendarEntryId IS NULL) — a period's own
        // slots (a gym lent for a window) must never leak into the base generation.
        $availabilitiesByVenue = [];
        if ($this->venueTrainingSlotRepository instanceof VenueTrainingSlotRepository) {
            $rows = $this->venueTrainingSlotRepository->findBy(['clubId' => $clubId, 'seasonId' => $seasonId, 'schedulePlanId' => null]);
            foreach ($rows as $row) {
                $availabilitiesByVenue[$row->getVenueId()][] = $row;
            }
        }

        $this->currentAvailabilitiesByVenue = $availabilitiesByVenue;
        // The base plan has no period session overrides. Reset here (not just at the
        // end of buildForOverlay) so a prior overlay build that threw mid-payload on
        // the long-lived worker can never leak its overrides into a base generation.
        $this->currentSessionOverrides = [];

        $payload = $this->buildPayload(
            clubId: $clubId,
            seasonId: $seasonId,
            venues: $this->findByClubSeason(Venue::class, $clubId, $seasonId, $em),
            teams: $this->findByClubSeason(Team::class, $clubId, $seasonId, $em),
            coaches: $this->findByClubSeason(Coach::class, $clubId, $seasonId, $em),
            teamCoaches: $this->findByClubSeason(TeamCoach::class, $clubId, $seasonId, $em),
            coachPlayerMemberships: $this->findByClubSeason(CoachPlayerMembership::class, $clubId, $seasonId, $em),
            // Base plan only: exclude OVERLAY schedules' slot templates (palier B),
            // otherwise an overlay's locks would leak into the base generation.
            slotTemplates: $this->findBaseSlotTemplates($clubId, $seasonId, $em),
            priorityTiers: $em->getRepository(PriorityTier::class)->findBy([], ['id' => 'ASC']),
            solverSeed: $solverSeed,
            constraints: $constraints,
            // Base-plan reservations (schedulePlanId IS NULL) — durable HARD pins.
            reservations: $em->getRepository(Reservation::class)->findBy(['clubId' => $clubId, 'seasonId' => $seasonId, 'schedulePlanId' => null], ['id' => 'ASC']),
            // Base-plan mutualisation groups (schedulePlanId IS NULL, P2-27).
            sharedTrainingGroups: $em->getRepository(SharedTrainingGroup::class)->findBy(['clubId' => $clubId, 'seasonId' => $seasonId, 'schedulePlanId' => null], ['id' => 'ASC']),
            // Passerelles (lot PASSERELLES) : STRUCTURE de club+saison (patron Team/Coach, PAS
            // ancré au plan) — la même déclaration nourrit socle et périodes. Filtrée au roster
            // dans serializeTeamLinks. Inerte en PR-1 (le moteur l'accepte, ne la consomme pas).
            teamLinks: $em->getRepository(TeamLink::class)->findBy(['clubId' => $clubId, 'seasonId' => $seasonId], ['id' => 'ASC']),
            // P2-53 RMM-8 — matrice de trajet : STRUCTURE de club+saison (patron TeamLink), la
            // même nourrit socle et périodes. Triée à la sérialisation ; sa présence (≥1 ligne)
            // active la règle implicite `travelTime` (opt-in au premier geste).
            venueTravelTimes: $em->getRepository(VenueTravelTime::class)->findBy(['clubId' => $clubId, 'seasonId' => $seasonId], ['id' => 'ASC']),
        );

        $this->currentAvailabilitiesByVenue = [];

        if ($cacheItem instanceof CacheItemInterface) {
            $cacheItem->set($payload);
            $cacheItem->expiresAfter(self::CACHE_TTL_SECONDS);
            // Taguer par CLUB : c'est le tag, et non la clé, que le listener invalide.
            // ⚠ La garde porte sur le POOL, pas sur l'item : `CacheItem::tag()` lève une
            // LogicException si l'item ne vient pas d'un pool tag-aware, et son drapeau
            // `isTaggable` est protected — un `instanceof ItemInterface` ne dirait rien.
            if ($this->scheduleCachePool instanceof TagAwareAdapterInterface && $cacheItem instanceof ItemInterface) {
                $cacheItem->tag(self::cacheTag($clubId));
            }
            $this->scheduleCachePool->save($cacheItem);
        }

        return $payload;
    }

    /**
     * Build the engine payload for a period OVERLAY (palier B). BYPASSES the
     * schedule-input cache (overlays are rare; the base key stays clean).
     *
     * - closure (fermeture): ALL permanent constraints kept by default (minus those
     *   explicitly disabled) + dated.
     * - holiday (reprise): permanent constraints inherited with a SMART default that
     *   follows the team selection (inheritedPermanents, reprise predicate) + dated.
     *
     * A dated `config.type=venue_closed` entry produces NO constraint at all: since
     * P2-5 5b the closed venue simply LOSES its training slots on the weekdays it is
     * actually closed (VenueClosureDays, incident ∩ window). No slot ⇒ no variable ⇒
     * the solver cannot place there that day, and stays free the other days. The old
     * per-team `forbiddenVenueId` expansion is GONE — the engine's forbidden_assignments
     * is day-blind, so it closed the venue for the WHOLE week.
     *
     * In both, a permanent/dated TEAM-scoped constraint whose team was deactivated for
     * the period is dropped (its team is absent from the payload — no ghost teamId).
     *
     * slotTemplates are scoped to THIS overlay schedule (its own locks), not the
     * base plan's — the base build (buildForClubSeason) is untouched.
     *
     * @return array<string, mixed>
     */
    public function buildForOverlay(Schedule $schedule, CalendarEntry $entry): array
    {
        // Adaptateur STRICT sur le chemin scalaire — aucune logique ici. Le `Schedule`
        // n'apportait que ces cinq scalaires ; les extraire est ce qui rend le payload
        // d'une période calculable AVANT toute génération (P2-9ter).
        return $this->buildForPeriodPlan(
            $schedule->getClubId(),
            $schedule->getSeasonId(),
            // ADR-0002 inv. 5 — les réglages de période (coches équipes/contraintes,
            // créneaux prêtés) s'ancrent au PLAN. Le plan est non-nullable (lot D) : une
            // version a toujours le sien.
            $schedule->getSchedulePlanId(),
            $entry,
            $schedule->getSolverSeed(),
            $schedule->getId(),
        );
    }

    /**
     * Le payload d'une période à partir de ses SEULS scalaires — aucun `Schedule` requis.
     *
     * Chemin de calcul UNIQUE de l'overlay : `buildForOverlay` n'en est qu'un adaptateur.
     * C'est ce qui permet à un appelant PRÉ-GÉNÉRATION (le récap) d'obtenir les nombres
     * exacts du solveur au lieu de les recalculer à la main — les trois tentatives qui
     * ont recopié cette logique ont toutes divergé (P2-9ter).
     *
     * `$scheduleId` null = avant génération : aucun `ScheduleSlotTemplate` n'existe encore,
     * et c'est SÉMANTIQUEMENT JUSTE — les verrous durables sont les `Reservation`, portées
     * par le `schedulePlanId`, qui sont lues dans les deux cas.
     *
     * @return array<string, mixed>
     */
    public function buildForPeriodPlan(
        string $clubId,
        string $seasonId,
        string $schedulePlanId,
        CalendarEntry $entry,
        int $solverSeed = self::DEFAULT_SOLVER_SEED,
        ?string $scheduleId = null,
        ?PeriodConstraintSelection $selection = null,
    ): array {
        $em = $this->entityManager;
        if (!$em instanceof EntityManagerInterface) {
            throw new LogicException('ScheduleConstraintBuilder requires Doctrine for overlay builds.');
        }

        if (!$this->periodConstraintSelector instanceof PeriodConstraintSelector) {
            throw new LogicException('ScheduleConstraintBuilder requires the period constraint selector for overlay builds.');
        }

        // P2-14 — LA sélection (quelles entités partent au solveur, pourquoi les autres
        // non) est calculée par la source UNIQUE partagée avec le gate pré-solve. Ce qui
        // suit ne fait plus que SÉRIALISER cette sélection ; les post-filtres sur lignes
        // sérialisées plus bas restent en défense en profondeur (ils attrapent les
        // expansions par équipe, invisibles au niveau entité).
        // Les équipes servent DEUX fois (la sélection dérive ses actives, le payload les
        // sérialise) : une seule requête, passée à la sélection (revue #340 round 1).
        $clubSeasonTeams = array_values($this->findByClubSeason(Team::class, $clubId, $seasonId, $em));
        // Sélection RÉUTILISÉE quand l'appelant la tient déjà (le gate pré-solve la calcule
        // pour ses warnings) : la recalculer coûtait ~7 requêtes par ouverture de récap pour
        // une valeur en main (revue #341).
        // Une sélection fournie DOIT être celle de ce plan : sinon on mélangerait deux
        // périodes en silence (revue #341 round 2). Une incohérence ici est une faute de
        // programmation, pas une donnée douteuse — on lève.
        if ($selection instanceof PeriodConstraintSelection && $selection->schedulePlanId !== $schedulePlanId) {
            throw new LogicException('The provided period selection belongs to another schedule plan.');
        }
        $selection ??= $this->periodConstraintSelector->selectForPeriodPlan($clubId, $seasonId, $schedulePlanId, $entry, $clubSeasonTeams);
        $deactivatedTeamIds = $selection->deactivatedTeamIds;
        $disabledVenueIds = $selection->disabledVenueIds;
        $this->currentSessionOverrides = $selection->sessionOverrides;
        $constraints = $selection->kept;

        // P2-5 5b / P2-38 / indispo informative — granularité JOUR des fermetures : les jours de
        // semaine EFFECTIVEMENT fermés par gymnase (incident × masque manuel), portés par la
        // SÉLECTION. La composition vit dans la MAISON UNIQUE `PlanVenueClosures`, TRANSVERSALE
        // (toutes entrées confondues) : le gate et le garde partagent la même — sinon payload,
        // récap et OrphanPinGuard diraient trois choses des mêmes fermetures. Pas de créneau ⇒ pas
        // de variable ⇒ le solveur ne peut pas placer là ; il PEUT les autres jours (dont ceux
        // rouverts OPEN par le gestionnaire, l'incident étant désormais informatif).
        $closedWeekdaysByVenue = $selection->effectiveClosedWeekdaysByVenue;

        // La grille de la PÉRIODE, et elle seule — aucune union avec les créneaux de
        // saison. C'est ce qui rend le modèle sûr : un gymnase n'a jamais deux jeux de
        // créneaux dans une période, donc rien ne peut se chevaucher entre couches ni
        // rendre un verrou ambigu (deux créneaux au même horaire, l'un « de saison »
        // l'autre « de période »). P2-5 5b : un gymnase fermé perd ses créneaux sur ses
        // jours FERMÉS uniquement (day-précis) — le reste passe.
        $availabilitiesByVenue = [];
        if ($this->venueTrainingSlotRepository instanceof VenueTrainingSlotRepository) {
            foreach ($this->venueTrainingSlotRepository->findBy(['schedulePlanId' => $schedulePlanId]) as $row) {
                $rowVenueId = $row->getVenueId();
                if (isset($disabledVenueIds[$rowVenueId])) {
                    continue; // gymnase désactivé : il ne sert pas cette période
                }
                if (isset($closedWeekdaysByVenue[$rowVenueId][$row->getDayOfWeek()])) {
                    continue; // gymnase fermé ce jour-là : créneau retiré du payload
                }
                $availabilitiesByVenue[$rowVenueId][] = $row;
            }
        }
        $this->currentAvailabilitiesByVenue = $availabilitiesByVenue;

        // Deactivated teams (computed by the selection) are dropped from the payload.
        $teams = array_values(array_filter(
            $clubSeasonTeams,
            static fn (Team $team): bool => !isset($deactivatedTeamIds[$team->getId()]),
        ));

        $payload = $this->buildPayload(
            clubId: $clubId,
            seasonId: $seasonId,
            // Un gymnase DÉSACTIVÉ sort du payload : l'y laisser avec 0 créneau serait
            // inoffensif pour le solveur, mais toute contrainte le nommant deviendrait un
            // id fantôme (cf. post-filtre plus bas) — on le retire à la source.
            venues: array_values(array_filter(
                $this->findByClubSeason(Venue::class, $clubId, $seasonId, $em),
                static fn (Venue $venue): bool => !isset($disabledVenueIds[$venue->getId()]),
            )),
            teams: $teams,
            coaches: $this->findByClubSeason(Coach::class, $clubId, $seasonId, $em),
            teamCoaches: $this->findByClubSeason(TeamCoach::class, $clubId, $seasonId, $em),
            coachPlayerMemberships: $this->findByClubSeason(CoachPlayerMembership::class, $clubId, $seasonId, $em),
            // Overlay's OWN slot templates (its work-loop locks), not the base plan's.
            // Filtre défensif : un verrou sur un gymnase désactivé pointerait un gymnase
            // absent du payload. Un verrou ORPHELIN (plus aucun créneau à cet horaire) est,
            // lui, une ERREUR annoncée AVANT la génération (GenerateScheduleController) —
            // on ne l'escamote pas en silence.
            // ⚠ Court-circuit d'INTENTION et d'économie, pas une branche de comportement :
            // `scheduleId` est NOT NULL sur l'entité, donc `findBy(['scheduleId' => null])`
            // rendrait `[]` de toute façon. Le retirer ne changerait aucun résultat — il
            // évite une requête inutile à chaque appel pré-génération et dit au lecteur
            // que « pas de version » n'est pas un cas dégradé. Aucun test ne peut donc le
            // faire tomber : ne pas en écrire un qui prétendrait le contraire.
            slotTemplates: null === $scheduleId ? [] : array_values(array_filter(
                $em->getRepository(ScheduleSlotTemplate::class)->findBy(['scheduleId' => $scheduleId], ['id' => 'ASC']),
                static fn (ScheduleSlotTemplate $template): bool => !isset($disabledVenueIds[$template->getVenueId()]),
            )),
            priorityTiers: $em->getRepository(PriorityTier::class)->findBy([], ['id' => 'ASC']),
            solverSeed: $solverSeed,
            constraints: $constraints,
            // Overlay reservations: this period's own pins (base ones don't leak in,
            // mirroring how HOLIDAY overlays use only dated constraints).
            reservations: array_values(array_filter(
                $em->getRepository(Reservation::class)->findBy(['schedulePlanId' => $schedulePlanId], ['id' => 'ASC']),
                static fn (Reservation $reservation): bool => !isset($disabledVenueIds[$reservation->getVenueId()]),
            )),
            // Period mutualisation groups: this plan's own (schedulePlanId = planId, P2-27). Un
            // membre désactivé pour la période est filtré au roster dans serializeSharedTrainings.
            sharedTrainingGroups: $em->getRepository(SharedTrainingGroup::class)->findBy(['schedulePlanId' => $schedulePlanId], ['id' => 'ASC']),
            // Passerelles : STRUCTURE de club+saison (patron Team/Coach) — mêmes lignes que le
            // socle, jamais des copies ancrées au plan. Le roster de période filtre : une équipe
            // désactivée pour la période sort du payload, son lien est abandonné (serializeTeamLinks).
            teamLinks: $em->getRepository(TeamLink::class)->findBy(['clubId' => $clubId, 'seasonId' => $seasonId], ['id' => 'ASC']),
            // P2-53 RMM-8 — matrice de trajet : STRUCTURE de club+saison, mêmes lignes que le
            // socle (jamais une copie ancrée au plan) — le trajet entre deux gymnases ne change
            // pas parce qu'on est en période. Sa présence active `travelTime` (opt-in).
            venueTravelTimes: $em->getRepository(VenueTravelTime::class)->findBy(['clubId' => $clubId, 'seasonId' => $seasonId], ['id' => 'ASC']),
            // ADR-0002 inv. 5 — le bloc `implicitRules` résout la COPIE de CE plan (repli saison
            // si legacy), jamais la portée saison directement : c'est ce que buildForClubSeason
            // (plan null) fait, ici on porte la période.
            schedulePlanId: $schedulePlanId,
        );

        $this->currentAvailabilitiesByVenue = [];
        $this->currentSessionOverrides = [];

        // P2-5 5b : les gymnases fermés sont retirés de l'availability day-précisément
        // (ci-dessus) — le forbid tous-jours `expandClosedVenues` est supprimé (l'engine
        // forbidden_assignments était day-blind, il fermait le gymnase TOUTE la semaine
        // même si l'incident n'en couvrait qu'une partie).

        // Drop any SERIALIZED TEAM row targeting a team deactivated for the period — an
        // original TEAM constraint, OR a CLUB/tag constraint expanded per-team during
        // serialization (serializeConstraintRow emits scope=TEAM + scopeTargetId=teamId).
        // The team is absent from the payload roster, so a ghost teamId here could turn the
        // solve INFEASIBLE. Filtering the serialized payload (not the entity list) catches
        // the CLUB+targetTag expansion the entity-level scope check would miss.
        if ([] !== $deactivatedTeamIds) {
            $payload['constraints'] = array_values(array_filter(
                $payload['constraints'],
                static fn (mixed $row): bool => !\is_array($row)
                    || ConstraintScope::TEAM->value !== ($row['scope'] ?? null)
                    || !isset($deactivatedTeamIds[(string) ($row['scopeTargetId'] ?? '')]),
            ));
        }

        // Miroir du filtre équipes, côté GYMNASES : une contrainte qui nomme un gymnase
        // absent du payload est un id fantôme. Le gestionnaire en est AVERTI au récap
        // (ValidateConstraintsController) — ignorer sans le dire était le défaut de #285.
        if ([] !== $disabledVenueIds) {
            $payload['constraints'] = array_values(array_filter(
                $payload['constraints'],
                static function (mixed $row) use ($disabledVenueIds): bool {
                    if (!\is_array($row)) {
                        return true;
                    }
                    if (ConstraintScope::FACILITY->value === ($row['scope'] ?? null)
                        && isset($disabledVenueIds[(string) ($row['scopeTargetId'] ?? '')])) {
                        return false;
                    }
                    $config = \is_array($row['config'] ?? null) ? $row['config'] : [];
                    foreach (self::VENUE_CONFIG_KEYS as $venueKey) {
                        if (isset($disabledVenueIds[(string) ($config[$venueKey] ?? '')])) {
                            return false;
                        }
                    }

                    return true;
                },
            ));
        }

        return $payload;
    }

    /**
     * In-memory builder kept for existing cross-stack contract coverage.
     *
     * @param array<Venue>                 $venues
     * @param array<Team>                  $teams
     * @param array<Coach>                 $coaches
     * @param array<TeamCoach>             $teamCoaches
     * @param array<CoachPlayerMembership> $coachPlayerMemberships
     * @param array<ScheduleSlotTemplate>  $slotTemplates
     * @param array<PriorityTier>          $priorityTiers
     * @param array<Constraint>            $constraints
     *
     * @return array<string, mixed>
     */
    public function build(
        array $venues,
        array $teams,
        array $coaches,
        array $teamCoaches = [],
        array $coachPlayerMemberships = [],
        array $slotTemplates = [],
        array $priorityTiers = [],
        array $constraints = [],
    ): array {
        // In-memory (DB-free) builder: no preloaded availabilities, no period
        // overrides. Reset both for symmetry with the DB entry points so a reused
        // instance can never leak a stale map into serializeVenue/serializeTeam.
        $this->currentAvailabilitiesByVenue = [];
        $this->currentSessionOverrides = [];

        return $this->buildPayload(
            clubId: $this->firstString($venues, 'getClubId')
                ?? $this->firstString($teams, 'getClubId')
                ?? $this->firstString($coaches, 'getClubId')
                ?? '',
            seasonId: $this->firstString($venues, 'getSeasonId')
                ?? $this->firstString($teams, 'getSeasonId')
                ?? $this->firstString($coaches, 'getSeasonId')
                ?? '',
            venues: $venues,
            teams: $teams,
            coaches: $coaches,
            teamCoaches: $teamCoaches,
            coachPlayerMemberships: $coachPlayerMemberships,
            slotTemplates: $slotTemplates,
            priorityTiers: $priorityTiers,
            constraints: $constraints,
        );
    }

    /**
     * @param array<Venue>                 $venues
     * @param array<Team>                  $teams
     * @param array<Coach>                 $coaches
     * @param array<TeamCoach>             $teamCoaches
     * @param array<CoachPlayerMembership> $coachPlayerMemberships
     * @param array<ScheduleSlotTemplate>  $slotTemplates
     * @param array<PriorityTier>          $priorityTiers
     * @param array<Constraint>            $constraints
     * @param array<Reservation>           $reservations           persistent team→slot HARD pins (base/overlay)
     * @param array<SharedTrainingGroup>   $sharedTrainingGroups   P2-27 mutualisation groups (base: plan NULL / period: = planId)
     * @param array<TeamLink>              $teamLinks              lot PASSERELLES — club+season bridges (roster-filtered)
     * @param array<VenueTravelTime>       $venueTravelTimes       P2-53 RMM-8 — travel matrix rows (club+season, symmetric)
     *
     * @return array<string, mixed>
     */
    public function buildPayload(
        string $clubId,
        string $seasonId,
        array $venues = [],
        array $teams = [],
        array $coaches = [],
        array $teamCoaches = [],
        array $coachPlayerMemberships = [],
        array $slotTemplates = [],
        array $priorityTiers = [],
        int $solverSeed = self::DEFAULT_SOLVER_SEED,
        array $constraints = [],
        array $reservations = [],
        array $sharedTrainingGroups = [],
        array $teamLinks = [],
        array $venueTravelTimes = [],
        ?string $schedulePlanId = null,
    ): array {
        $serializedConstraints = array_merge(
            $this->serializeTeamCoachConstraints($teamCoaches),
            $this->serializeCoachPlayerMembershipConstraints($coachPlayerMemberships),
            $this->serializePriorityTierConstraints($priorityTiers),
            $this->serializeUnifiedConstraints($constraints, $seasonId, $clubId, $teams),
        );

        // Reservations feed the SAME engine `slotTemplates` payload (HARD pins) —
        // they are just sourced from the durable Reservation entity instead of the
        // ephemeral, schedule-bound ScheduleSlotTemplate.
        $serializedSlots = array_merge(
            array_filter(
                array_map($this->serializeSlotTemplate(...), $slotTemplates),
                static fn (?array $slotTemplate): bool => null !== $slotTemplate,
            ),
            array_map($this->serializeReservation(...), $reservations),
        );

        // Règles implicites « bien-être » (contrat 2.7) : le bloc RÉSOLU des 4 clés, réglages
        // stockés par-dessus les défauts. PORTÉE (ADR-0002 inv. 5) : un build de PÉRIODE
        // (`schedulePlanId` non-null) résout la COPIE du plan (`resolveForPlan`, repli saison si
        // le plan est legacy) ; un build de BASE (null) résout la portée saison (`resolve`). Sans
        // résolveur (mode léger sans DB) ou sans club/saison → défauts (tout HARD), payload
        // d'absence de PR 1.
        $implicitRules = ($this->implicitRuleResolver instanceof ImplicitRuleResolver && '' !== $clubId && '' !== $seasonId)
            ? (null !== $schedulePlanId
                ? $this->implicitRuleResolver->resolveForPlan($clubId, $seasonId, $schedulePlanId)
                : $this->implicitRuleResolver->resolve($clubId, $seasonId))
            : ImplicitRuleResolver::defaults();

        // P2-53 RMM-8 PR-2 — la règle implicite `travelTime` naît OPT-IN : elle n'est émise ACTIVE
        // que si le club a AU MOINS une ligne de matrice de trajet. C'est « l'activation au premier
        // geste sur la matrice » (précédent opt-in P2-42) : un club qui n'a rien saisi voit un
        // payload byte-identique à avant (ni bloc `venueTravelTimes`, ni règle). Choix de
        // conception : la règle est DÉRIVÉE ICI de la présence de matrice — pas un
        // `ImplicitRuleSetting` stocké — ce qui garde `implicitRules` (bien-être) == sortie du
        // résolveur pour les 5 clés historiques (invariant `ImplicitRulePayloadParityTest`), et
        // laisse le réglage d'intensité/seuil (PREFERRED↔MANDATORY, 20) au futur écran (PR-3). Le
        // moteur, lui, sait déjà consommer MANDATORY : PR-3 n'aura qu'à basculer le cran émis.
        $serializedTravelTimes = $this->serializeVenueTravelTimes($venueTravelTimes);
        if ([] !== $serializedTravelTimes) {
            $implicitRules['travelTime'] = [
                'intensity' => TeamLinkIntensity::PREFERRED->value,
                'defaultMinutes' => self::TRAVEL_TIME_DEFAULT_MINUTES,
            ];
        }

        return [
            'version' => self::CONTRACT_VERSION,
            'clubId' => $clubId,
            'seasonId' => $seasonId,
            'solverSeed' => $solverSeed,
            'solverTimeoutSeconds' => self::DEFAULT_SOLVER_TIMEOUT_SECONDS,
            'venues' => array_map($this->serializeVenue(...), $venues),
            'teams' => array_map(fn (Team $team): array => $this->serializeTeam($team, $seasonId), $teams),
            'coaches' => array_map($this->serializeCoach(...), $coaches),
            'constraints' => $serializedConstraints,
            'slotTemplates' => array_values($serializedSlots),
            'implicitRules' => $implicitRules,
            // P2-27 — mutualisation : ce que le STOCKE le club (groupes du bon plan) devient le
            // bloc `sharedTrainings` du payload. Vide (aucun groupe) ⇒ [] : chemin byte-identique
            // côté moteur (default_factory=list). Gardé par SharedTrainingPayloadParityTest.
            'sharedTrainings' => $this->serializeSharedTrainings($sharedTrainingGroups, $teams),
            // Lot PASSERELLES — ce que le club STOCKE (passerelles club+saison) devient le bloc
            // `teamLinks` du payload, FILTRÉ au roster (les deux équipes présentes). Vide ⇒ [] :
            // chemin byte-identique côté moteur (default_factory=list). Inerte en PR-1 (accepté,
            // non consommé). Gardé par TeamLinkPayloadParityTest.
            'teamLinks' => $this->serializeTeamLinks($teamLinks, $teams),
            // P2-53 RMM-8 PR-2 — matrice de trajet entre gymnases (barème voiture/à pied par
            // couple), TRIÉE (venueAId, venueBId). Vide ⇒ [] : chemin byte-identique côté moteur
            // (default_factory=list) ET règle `travelTime` inactive (ci-dessus). Gardé par
            // VenueTravelTimePayloadParityTest.
            'venueTravelTimes' => $serializedTravelTimes,
        ];
    }

    /**
     * P3-21 — greffe le bloc `previousAssignments` (terme de STABILITÉ moteur : faire
     * CONVERGER une régénération vers son placement précédent, en départageant les ex æquo
     * exacts de score, jamais en arbitrant) sur un payload DÉJÀ construit.
     *
     * ⚠ Ce bloc n'entre PAS dans le payload construit/caché par `buildForClubSeason`
     * (`buildPayload`) : le payload y est caché par club+saison, sa clé ne connaît pas la
     * version source. Le handler l'injecte donc APRÈS coup — et, décision B, APRÈS le hash
     * de snapshot : le précédent est une préférence de CONVERGENCE, pas une donnée de
     * STRUCTURE. L'inclure dans `snapshotHash` le ferait diverger de `currentStructureHash`
     * (recalculé sans lui) à chaque régénération.
     *
     * Chaque placement = `{teamId, venueId, dayOfWeek, startTime}` (H:i:s — l'engine
     * normalise via `_time_to_minutes`, H:i et H:i:s convergent). TOUS les placements de la
     * source, HARD compris : un créneau HARD n'a pas de variable côté solveur (il est
     * épinglé), le terme de stabilité le saute donc naturellement — aucun double paiement.
     * Liste vide ⇒ clé absente : chemin byte-identique à l'historique (première génération).
     *
     * @param array<string, mixed>        $payload
     * @param array<ScheduleSlotTemplate> $sourceSlots placements de la version source
     *
     * @return array<string, mixed>
     */
    public function withPreviousAssignments(array $payload, array $sourceSlots): array
    {
        if ([] === $sourceSlots) {
            return $payload;
        }

        $payload['previousAssignments'] = array_map(
            static fn (ScheduleSlotTemplate $slot): array => [
                'teamId' => $slot->getTeamId(),
                'venueId' => $slot->getVenueId(),
                'dayOfWeek' => $slot->getDayOfWeek(),
                'startTime' => $slot->getStartTime()->format('H:i:s'),
            ],
            array_values($sourceSlots),
        );

        return $payload;
    }

    /**
     * P2-44 PR-3 (comblement) — greffe, DANS LE PAYLOAD SEUL, les placements d'une version
     * SOURCE de la même période comme épingles HARD (des `slotTemplates`), sur un payload
     * d'overlay DÉJÀ construit. « Tout ce qui est déjà placé reste EXACTEMENT en place, seuls
     * les trous — les équipes sous leur volume de séances — sont placés par le solveur. ».
     *
     * Rien n'est persisté en base (décision fondateur : les verrous de la copie sont déjà HARD
     * révocables) ; ces épingles ne vivent QUE le temps de ce solve partiel. `lockLevel` est
     * FORCÉ HARD quel que soit celui de la source — un HARD n'a pas de variable côté moteur
     * (`objective.py`), donc les placements figés ne bougent pas ET ne re-paient pas le terme de
     * stabilité (c'est pourquoi le handler saute `withPreviousAssignments` en mode fill). Le
     * comblement n'a besoin d'AUCUN changement moteur : le solveur place le RESTE des séances par
     * équipe (`sessionsPerWeek` du payload) une fois les épingles honorées.
     *
     * Deux garde-fous, mêmes doctrines que `buildForPeriodPlan` :
     *  - FILTRÉ au roster du payload (équipes + gymnases) : une source qui nomme une entité hors
     *    sélection de période serait un id fantôme rendant le solve INFEASIBLE ;
     *  - DÉDUPLIQUÉ par placement (équipe, gymnase, jour, heure) contre les épingles DÉJÀ
     *    présentes — les `Reservation` du plan sortent en HARD depuis `buildForOverlay`, et la
     *    copie transcrite reprend ces mêmes placements : sans dédup, l'équipe se retrouverait
     *    avec DEUX épingles pour UNE séance (compte de séances faussé / sur-contrainte).
     *
     * @param array<string, mixed>        $payload
     * @param array<ScheduleSlotTemplate> $sourceSlots placements de la version source à figer
     *
     * @return array<string, mixed>
     */
    public function withPinnedAssignments(array $payload, array $sourceSlots): array
    {
        if ([] === $sourceSlots) {
            return $payload;
        }

        $rosterTeams = [];
        foreach ($payload['teams'] ?? [] as $team) {
            if (\is_array($team) && isset($team['id'])) {
                $rosterTeams[(string) $team['id']] = true;
            }
        }
        $rosterVenues = [];
        foreach ($payload['venues'] ?? [] as $venue) {
            if (\is_array($venue) && isset($venue['id'])) {
                $rosterVenues[(string) $venue['id']] = true;
            }
        }

        $existingSlots = \is_array($payload['slotTemplates'] ?? null) ? $payload['slotTemplates'] : [];
        $seen = [];
        foreach ($existingSlots as $pin) {
            if (\is_array($pin)) {
                $seen[$this->pinKey((string) ($pin['teamId'] ?? ''), (string) ($pin['venueId'] ?? ''), (int) ($pin['dayOfWeek'] ?? -1), (string) ($pin['startTime'] ?? ''))] = true;
            }
        }

        $pins = [];
        foreach ($sourceSlots as $slot) {
            if (!isset($rosterTeams[$slot->getTeamId()], $rosterVenues[$slot->getVenueId()])) {
                continue; // hors sélection de période : jamais un id fantôme au moteur
            }
            $serialized = $this->serializeSlotTemplate($slot);
            $serialized['lockLevel'] = LockLevel::HARD->value; // le comblement FIGE le placé
            $key = $this->pinKey((string) $serialized['teamId'], (string) $serialized['venueId'], (int) $serialized['dayOfWeek'], (string) $serialized['startTime']);
            if (isset($seen[$key])) {
                continue; // déjà épinglé (réservation) — pas de double épingle pour une séance
            }
            $seen[$key] = true;
            $pins[] = $serialized;
        }

        $payload['slotTemplates'] = array_merge($existingSlots, $pins);

        return $payload;
    }

    /** Identité d'un placement épinglé (heure normalisée H:i — la source peut porter les secondes). */
    private function pinKey(string $teamId, string $venueId, int $dayOfWeek, string $startTime): string
    {
        return $teamId . '|' . $venueId . '|' . $dayOfWeek . '|' . substr($startTime, 0, 5);
    }

    /**
     * Sérialise les groupes de mutualisation en `{id, teamIds, commonSessions}`. Les membres
     * sont filtrés au ROSTER du payload (une équipe désactivée pour la période sort du payload,
     * son id serait fantôme dans le groupe) ; un groupe qui tombe sous 2 membres est ABANDONNÉ
     * (le moteur exige ≥ 2). teamIds triés = déterminisme du hash de snapshot.
     *
     * @param array<SharedTrainingGroup> $groups
     * @param array<Team>                $teams
     *
     * @return array<int, array{id: string, teamIds: list<string>, commonSessions: int}>
     */
    private function serializeSharedTrainings(array $groups, array $teams): array
    {
        if ([] === $groups || !$this->entityManager instanceof EntityManagerInterface) {
            return [];
        }

        $rosterIds = [];
        foreach ($teams as $team) {
            $rosterIds[$team->getId()] = true;
        }

        $result = [];
        foreach ($groups as $group) {
            $rows = $this->entityManager->getRepository(SharedTrainingGroupTeam::class)
                ->findBy(['groupId' => $group->getId()], ['teamId' => 'ASC']);
            $teamIds = [];
            foreach ($rows as $row) {
                if (isset($rosterIds[$row->getTeamId()])) {
                    $teamIds[] = $row->getTeamId();
                }
            }
            sort($teamIds);
            if (\count($teamIds) < 2) {
                continue; // moins de 2 membres actifs : la déclaration n'a plus de sens
            }
            $result[] = [
                'id' => $group->getId(),
                'teamIds' => $teamIds,
                'commonSessions' => $group->getCommonSessions(),
            ];
        }

        return $result;
    }

    /**
     * Sérialise les passerelles en `{id, teamAId, teamBId, intensity}`. FILTRÉ au ROSTER du
     * payload : une passerelle dont l'une des deux équipes est absente du roster (désactivée pour
     * la période) est ABANDONNÉE — jamais un teamId fantôme (miroir de serializeSharedTrainings).
     * `intensity` = l'intensité CÔTÉ ENTRAÎNEMENT (PREFERRED/MANDATORY). Inerte en PR-1.
     *
     * @param array<TeamLink> $links
     * @param array<Team>     $teams
     *
     * @return array<int, array{id: string, teamAId: string, teamBId: string, intensity: string}>
     */
    private function serializeTeamLinks(array $links, array $teams): array
    {
        if ([] === $links) {
            return [];
        }

        $rosterIds = [];
        foreach ($teams as $team) {
            $rosterIds[$team->getId()] = true;
        }

        $result = [];
        foreach ($links as $link) {
            if (!isset($rosterIds[$link->getTeamAId()], $rosterIds[$link->getTeamBId()])) {
                continue; // une équipe hors roster : le lien n'a pas de sens dans ce payload
            }
            $result[] = [
                'id' => $link->getId(),
                'teamAId' => $link->getTeamAId(),
                'teamBId' => $link->getTeamBId(),
                'intensity' => $link->getTrainingIntensity()->value,
            ];
        }

        return $result;
    }

    /**
     * Sérialise la matrice de trajet en `{venueAId, venueBId, drivingMinutes, walkingMinutes}`,
     * TRIÉE par (venueAId, venueBId) — tri déterministe pour un payload stable. Les lignes sont
     * déjà normalisées venueAId < venueBId à la saisie (VenueTravelTimeStateProcessor) ; on ne
     * filtre PAS au roster des gymnases (la matrice est une STRUCTURE de club+saison, comme les
     * passerelles côté équipes — le moteur ne lit un couple que si les deux gymnases portent des
     * séances). Les minutes NULLABLES passent telles quelles : le moteur applique le défaut 20.
     *
     * @param array<VenueTravelTime> $rows
     *
     * @return array<int, array{venueAId: string, venueBId: string, drivingMinutes: ?int, walkingMinutes: ?int}>
     */
    private function serializeVenueTravelTimes(array $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'venueAId' => $row->getVenueAId(),
                'venueBId' => $row->getVenueBId(),
                'drivingMinutes' => $row->getDrivingMinutes(),
                'walkingMinutes' => $row->getWalkingMinutes(),
            ];
        }

        usort(
            $result,
            static fn (array $a, array $b): int => [$a['venueAId'], $a['venueBId']] <=> [$b['venueAId'], $b['venueBId']],
        );

        return $result;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $className
     *
     * @return array<T>
     */
    private function findByClubSeason(string $className, string $clubId, string $seasonId, ?EntityManagerInterface $entityManager = null): array
    {
        $em = $entityManager ?? $this->entityManager;
        if (!$em instanceof EntityManagerInterface) {
            throw new LogicException('Entity manager is not available.');
        }

        return $em->getRepository($className)->findBy(
            ['clubId' => $clubId, 'seasonId' => $seasonId],
            ['id' => 'ASC'],
        );
    }

    /**
     * Slot templates of the club/season that belong to a BASE schedule (not an
     * overlay). Excludes overlay slots — and orphan slots whose schedule row is
     * gone — from base-plan generation. See palier B.
     *
     * @return array<ScheduleSlotTemplate>
     */
    private function findBaseSlotTemplates(string $clubId, string $seasonId, EntityManagerInterface $em): array
    {
        return $em->getRepository(ScheduleSlotTemplate::class)->createQueryBuilder('s')
            ->andWhere('s.clubId = :clubId')
            ->andWhere('s.seasonId = :seasonId')
            // ADR-0002 C4 : « base » = la version d'un plan SEASON (plus de sch.calendarEntryId).
            // Le socle a un unique plan SEASON par saison (inv. 3).
            ->andWhere('s.scheduleId IN (SELECT sch.id FROM ' . Schedule::class . ' sch WHERE sch.clubId = :clubId AND sch.seasonId = :seasonId AND sch.schedulePlanId IN (SELECT p.id FROM ' . SchedulePlan::class . ' p WHERE p.seasonId = :seasonId AND p.type = :seasonType))')
            ->setParameter('clubId', $clubId)
            ->setParameter('seasonId', $seasonId)
            ->setParameter('seasonType', SchedulePlanType::SEASON)
            ->orderBy('s.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return array<string, mixed> */
    private function serializeVenue(Venue $venue): array
    {
        return [
            'id' => $venue->getId(),
            'name' => $venue->getName(),
            'isExternal' => $venue->getIsExternal(),
            'color' => $venue->getColor(),
            'latitude' => $venue->getLatitude(),
            'longitude' => $venue->getLongitude(),
            'source' => $venue->getSource(),
            'externalRef' => $venue->getExternalRef(),
            'isActive' => $venue->getIsActive(),
            'parentVenueId' => $venue->getParentVenueId(),
            'trainingSlots' => $this->buildTrainingSlots($this->currentAvailabilitiesByVenue[$venue->getId()] ?? [], $venue->getCanSplit()),
        ];
    }

    /**
     * @param array<VenueTrainingSlot> $slots
     *
     * @return array<int, array{dayOfWeek: int, startTime: string, durationMinutes: int, capacity: int}>
     */
    private function buildTrainingSlots(array $slots, bool $canSplit): array
    {
        if ([] === $slots) {
            return [];
        }

        $result = [];
        foreach ($slots as $slot) {
            // Divisibility is a venue property: an indivisible venue (single
            // court) can host at most one team per slot, whatever the slot's
            // stored capacity. Only a splittable venue may expose capacity > 1.
            $capacity = $canSplit ? $slot->getCapacity() : 1;
            $result[] = [
                'dayOfWeek' => $slot->getDayOfWeek(),
                'startTime' => $slot->getStartTime()->format('H:i'),
                'durationMinutes' => $slot->getDurationMinutes(),
                'capacity' => $capacity,
            ];
        }

        usort($result, static fn (array $a, array $b): int => $a['dayOfWeek'] <=> $b['dayOfWeek'] ?: strcmp($a['startTime'], $b['startTime']));

        return $result;
    }

    /** @return array<string, mixed> */
    private function serializeTeam(Team $team, string $seasonId): array
    {
        $tags = [];
        $sportCategory = null;
        if ($this->entityManager instanceof EntityManagerInterface) {
            $sportCategory = $this->entityManager->getRepository(SportCategory::class)->find($team->getSportCategoryId());
        }
        // P2-9ter — LECTURE SEULE. Les tags sont LUS ici, jamais resynchronisés : c'est
        // `TeamTagSyncListener` (postPersist/postUpdate sur Team, resync au postFlush) qui
        // les maintient au write-path. `determineTagNames` dérive des champs de l'équipe
        // — sa `SportCategory`, son genre et son niveau — donc tout changement de tag
        // passe par un update de `Team`… SAUF l'édition de la `SportCategory` elle-même,
        // que le listener n'écoute pas (dette inscrite en roadmap, ligne `team_tags`).
        // ⚠ L'appel à `syncTeamTags` qui vivait ici SUPPRIMAIT puis recréait les
        // assignations avec un flush INTERMÉDIAIRE (TeamTagService : le flush de
        // getOrCreateSystemTags commit les remove, les persist restent en attente). La
        // relecture ci-dessous tombait donc sur une table vidée : `tags` sortait VIDE pour
        // les 49 équipes des générations réelles, alors que la base portait 160
        // assignations. Et sans flush ultérieur (le récap n'en fait aucun), les
        // suppressions restaient définitives — la perte de données de la 3e tentative.
        if ($this->entityManager instanceof EntityManagerInterface) {
            $tagAssignments = $this->entityManager->getRepository(TeamTagAssignment::class)->findBy([
                'teamId' => $team->getId(),
                'seasonId' => $seasonId,
            ]);

            foreach ($tagAssignments as $assignment) {
                $tag = $this->entityManager->getRepository(TeamTag::class)->find($assignment->getTagId());
                if ($tag instanceof TeamTag) {
                    $tags[] = $tag->getName();
                }
            }
            // Tri sur le NOM — la seule valeur qui parte réellement dans le payload.
            // ⚠ Trier la REQUÊTE sur `id` ne suffirait pas : l'id d'une
            // `TeamTagAssignment` est un UUID v4 tiré à la construction, et
            // `TeamTagSyncListener` supprime puis recrée les lignes à CHAQUE écriture sur
            // l'équipe — l'ordre changerait donc à chaque édition. Or `snapshotHash` et
            // `currentStructureHash` sont deux sha256 du payload sérialisé : une simple
            // permutation les ferait diverger sans qu'aucune structure n'ait bougé, et le
            // cockpit annoncerait « structure modifiée » de façon permanente.
            sort($tags);
        }

        return [
            'id' => $team->getId(),
            'sportCategoryId' => $team->getSportCategoryId(),
            'ageMin' => $sportCategory?->getAgeMin(),
            'ageMax' => $sportCategory?->getAgeMax(),
            'priorityTierId' => $team->getPriorityTierId(),
            'name' => $team->getName(),
            'gender' => $team->getGender()?->value,
            'level' => $team->getLevel()?->value,
            'sessionsPerWeek' => $this->currentSessionOverrides[$team->getId()] ?? $team->getSessionsPerWeek(),
            'minSessionsOverride' => $team->getMinSessionsOverride(),
            'matchDay' => $this->deriveMatchDay($team, $seasonId),
            'forcedVenueId' => $team->getForcedVenueId(),
            'isActive' => $team->getIsActive(),
            'parentTeamId' => $team->getParentTeamId(),
            'tags' => $tags,
        ];
    }

    /**
     * matchDay ÉMIS = image A/B DÉRIVÉE (RMM-5 PR-3, 3ᵉ décision fondateur §8 : « le repos suit
     * l'image ») : le DERNIER jour de match ISO de la semaine =
     * `max(jours ISO des habitudes de l'équipe ∪ jours ISO des rotations dont elle est membre)`.
     * Le repos qui compte est celui d'APRÈS ce dernier match ; le moteur en dérive
     * `rest_day = match_day % 7 + 1`, formule JUSTE en ISO uniquement
     * (`engine/app/solver/objective.py`, `_rest_day_terms` : « the day after (m mod 7 + 1) »,
     * « a SUNDAY match makes Monday the rest day »). La valeur émise reste donc dans le format
     * que l'ENGINE attend — ISO 1..7. Dérivation déterministe (`max`, insensible à l'ordre).
     *
     * Repli — équipe SANS image (ni habitude ni rotation) : le champ déclaré `Team.matchDay`,
     * stocké 0-based (0 = lundi, `TeamInput` valide 0..6), CONVERTI en ISO (+1) pour alimenter la
     * MÊME formule moteur (sans conversion, un `matchDay=5` samedi produisait un repos samedi au
     * lieu de dimanche — bug dormant). Sans champ déclaré non plus → null (comportement
     * d'aujourd'hui). Le champ n'est PAS supprimé (repli legacy, zéro migration, conversion à
     * l'émission SEULE).
     */
    private function deriveMatchDay(Team $team, string $seasonId): ?int
    {
        $days = [];
        if ($this->entityManager instanceof EntityManagerInterface) {
            foreach ($this->entityManager->getRepository(TeamMatchHabit::class)->findBy([
                'teamId' => $team->getId(),
                'seasonId' => $seasonId,
            ]) as $habit) {
                $days[] = $habit->getDayOfWeek();
            }

            $rotationIds = array_map(
                static fn (MatchSlotRotationTeam $member): string => $member->getRotationId(),
                $this->entityManager->getRepository(MatchSlotRotationTeam::class)->findBy([
                    'teamId' => $team->getId(),
                    'seasonId' => $seasonId,
                ]),
            );
            if ([] !== $rotationIds) {
                foreach ($this->entityManager->getRepository(MatchSlotRotation::class)->findBy(['id' => $rotationIds]) as $rotation) {
                    $days[] = $rotation->getDayOfWeek();
                }
            }
        }

        if ([] !== $days) {
            return max($days);
        }

        // Repli legacy : champ déclaré 0-based (0 = lundi) → ISO (+1) ; null reste null.
        $declared = $team->getMatchDay();

        return null === $declared ? null : $declared + 1;
    }

    /** @return array<string, mixed> */
    private function serializeCoach(Coach $coach): array
    {
        return [
            'id' => $coach->getId(),
            'firstName' => $coach->getFirstName(),
            'lastName' => $coach->getLastName(),
            'email' => $coach->getEmail(),
            'phone' => $coach->getPhone(),
            'maxDaysOverride' => $coach->getMaxDaysOverride(),
            'acceptableLateMinutes' => $coach->getAcceptableLateMinutes(),
            'isActive' => $coach->getIsActive(),
            'isEmployee' => $coach->isEmployee(),
            'parentCoachId' => $coach->getParentCoachId(),
            // P2-53 RMM-8 PR-2 — statut véhiculé : décide du barème de trajet du coach
            // (véhiculé → voiture, sinon à pied) dans la règle implicite `travelTime`.
            'isVehicled' => $coach->isVehicled(),
        ];
    }

    /** @return array<string, mixed> */
    private function serializeSlotTemplate(ScheduleSlotTemplate $slotTemplate): array
    {
        // ENG-21: no SOFT-lock penalty is built — the engine never consumes it (placebo),
        // and SOFT locks are rejected at the write endpoint. Only NONE/HARD reach here.
        $pendingConstraintSuggestion = $slotTemplate->getPendingConstraintSuggestion();

        return [
            'id' => $slotTemplate->getId(),
            'teamId' => $slotTemplate->getTeamId(),
            'venueId' => $slotTemplate->getVenueId(),
            'coachId' => $slotTemplate->getCoachId(),
            'dayOfWeek' => $slotTemplate->getDayOfWeek(),
            'startTime' => $this->formatTime($slotTemplate->getStartTime()),
            'durationMinutes' => $slotTemplate->getDurationMinutes(),
            'lockLevel' => $slotTemplate->getLockLevel()->value,
            'pendingConstraintSuggestion' => $pendingConstraintSuggestion,
        ];
    }

    /**
     * A Reservation is a HARD team→slot pin — same engine payload shape as a
     * HARD ScheduleSlotTemplate, minus the pending suggestion which reservations
     * never carry.
     *
     * @return array<string, mixed>
     */
    private function serializeReservation(Reservation $reservation): array
    {
        return [
            'id' => $reservation->getId(),
            'teamId' => $reservation->getTeamId(),
            'venueId' => $reservation->getVenueId(),
            'coachId' => null,
            'dayOfWeek' => $reservation->getDayOfWeek(),
            'startTime' => $this->formatTime($reservation->getStartTime()),
            'durationMinutes' => $reservation->getDurationMinutes(),
            'lockLevel' => LockLevel::HARD->value,
            'pendingConstraintSuggestion' => null,
        ];
    }

    /**
     * @param array<TeamCoach> $teamCoaches
     *
     * @return array<array<string, mixed>>
     */
    private function serializeTeamCoachConstraints(array $teamCoaches): array
    {
        return array_map(static fn (TeamCoach $teamCoach): array => [
            'id' => \sprintf('team-coach:%s', $teamCoach->getId()),
            'teamId' => $teamCoach->getTeamId(),
            'type' => 'TEAM_COACH',
            'severity' => $teamCoach->getIsRequired() ? 'HARD' : 'SOFT',
            'value' => $teamCoach->getCoachId(),
            'metadata' => [
                'coachId' => $teamCoach->getCoachId(),
                'role' => $teamCoach->getRole(),
                'isRequired' => $teamCoach->getIsRequired(),
            ],
        ], $teamCoaches);
    }

    /**
     * @param array<CoachPlayerMembership> $memberships
     *
     * @return array<array<string, mixed>>
     */
    private function serializeCoachPlayerMembershipConstraints(array $memberships): array
    {
        return array_map(static fn (CoachPlayerMembership $membership): array => [
            'id' => \sprintf('coach-player-unavailability:%s', $membership->getId()),
            'teamId' => $membership->getTeamId(),
            'type' => 'COACH_PLAYER_UNAVAILABILITY',
            'severity' => $membership->getIsActive() ? 'HARD' : 'SOFT',
            'value' => $membership->getCoachId(),
            'metadata' => [
                'coachId' => $membership->getCoachId(),
                'teamId' => $membership->getTeamId(),
                'position' => $membership->getPosition(),
                'isActive' => $membership->getIsActive(),
            ],
        ], $memberships);
    }

    /**
     * @param array<PriorityTier> $priorityTiers
     *
     * @return array<array<string, mixed>>
     */
    private function serializePriorityTierConstraints(array $priorityTiers): array
    {
        // orToolsWeight is intentionally NOT sent: the solver enforces tier
        // priority with fixed hardcoded weights (S=10000/A=1000/B=100/C=10/D=1),
        // so a per-tier weight would be accepted then ignored. The engine reads
        // only metadata.id + metadata.defaultMinSessions from this constraint.
        return array_map(static fn (PriorityTier $priorityTier): array => [
            'id' => \sprintf('priority-tier:%d', $priorityTier->getId()),
            'teamId' => '*',
            'type' => 'PRIORITY_TIER',
            'severity' => 'SOFT',
            'value' => null,
            'metadata' => [
                'id' => $priorityTier->getId(),
                'label' => $priorityTier->getLabel(),
                'defaultMinSessions' => $priorityTier->getDefaultMinSessions(),
            ],
        ], $priorityTiers);
    }

    private function formatTime(DateTimeInterface $time): string
    {
        return $time->format('H:i:s');
    }

    /**
     * @param array<Constraint> $constraints
     * @param array<Team>       $teams
     *
     * @return array<array<string, mixed>>
     */
    private function serializeUnifiedConstraints(array $constraints, string $seasonId, string $clubId, array $teams = []): array
    {
        $result = [];

        foreach ($constraints as $constraint) {
            $scope = $constraint->getScope();
            $config = $constraint->getConfig();

            // Resolve a CLUB constraint targeted by tag(s) into N TEAM constraints.
            // P2-29 : targetTag (legacy, one tag), targetTags (INTERSECTION) or
            // excludeTags (UNION subtracted; alone = every team of the season, D8).
            if (ConstraintScope::CLUB === $scope && TeamTagResolver::targetsTags($config)) {
                $teamIds = $this->resolveTagToTeamIds($config, $seasonId, $clubId, $teams);

                // An empty resolution (typo, tags not re-applied after a season
                // rollover, teams deactivated) must be a NO-OP: running the
                // "forbidden outside the tag" loop below with zero tagged teams
                // would ban the venue for EVERY team of the club (audit review).
                // Kept as a BACKSTOP for the new form too (a resolution can go
                // empty AFTER the write-time 422 — teams disabled since).
                if ([] === $teamIds) {
                    $this->logger->warning('Tag targeting "{label}" resolves to no team — constraint {id} skipped.', [
                        'label' => TeamTagResolver::tagTargetLabel($config),
                        'id' => $constraint->getId(),
                    ]);

                    continue;
                }

                foreach ($teamIds as $teamId) {
                    $resolvedConfig = $config;
                    // P2-29 D11 : le contrat ne bouge pas — aucune clé de tag ne
                    // part au moteur (grep `targetTag*` dans engine/ = zéro).
                    foreach (TeamTagResolver::TAG_CONFIG_KEYS as $tagKey) {
                        unset($resolvedConfig[$tagKey]);
                    }

                    $result[] = $this->serializeConstraintRow($constraint, $constraint->getId() . ':' . $teamId, $teamId, $resolvedConfig);
                }

                // When HARD + a forced venue (preferredVenueId in HARD, or the
                // explicit forcedVenueId "impose" mode): the venue is DEDICATED to
                // the tag → also forbid it for every team NOT in the tag. Both keys
                // force the tag onto the venue engine-side, so exclusivity must
                // cover both, else "impose" would be weaker than HARD "préfère".
                $dedicatedVenueId = $config['forcedVenueId'] ?? $config['preferredVenueId'] ?? null;
                // `is_string && '' !==` et non `null !==` (revue #340 round 2) : un
                // `preferredVenueId` vidé en '' par un client émettait des lignes
                // `forbiddenVenueId: ''` — un gymnase inexistant interdit à tout le club.
                // Même garde que le verdict du sélecteur : les deux doivent coïncider.
                if (ConstraintRuleType::HARD === $constraint->getRuleType() && \is_string($dedicatedVenueId) && '' !== $dedicatedVenueId) {
                    $tagTeamIdSet = array_flip($teamIds);
                    foreach ($teams as $team) {
                        if (isset($tagTeamIdSet[$team->getId()])) {
                            continue;
                        }
                        $result[] = $this->serializeConstraintRow(
                            $constraint,
                            $constraint->getId() . ':forbidden:' . $team->getId(),
                            $team->getId(),
                            ['forbiddenVenueId' => $dedicatedVenueId],
                            name: $constraint->getName() . ' (interdit hors tag)',
                            ruleType: ConstraintRuleType::HARD->value,
                        );
                    }
                }

                continue;
            }

            // Resolve a club-wide TIME/DAY/FACILITY rule ("Toutes les équipes")
            // into one TEAM constraint per team: the engine only applies these
            // families to a team target — a CLUB-scope one was a silent no-op
            // (audit P0.1, dead "all teams" cell). Same expansion pattern as
            // CLUB+targetTag above. COACH_AVAILABILITY is coach-scoped and
            // FACILITY_CAPACITY venue-keyed → both pass through untouched.
            $expandableFamilies = [ConstraintFamily::TIME, ConstraintFamily::DAY, ConstraintFamily::FACILITY];
            if (ConstraintScope::CLUB === $scope && \in_array($constraint->getFamily(), $expandableFamilies, true)) {
                foreach ($teams as $team) {
                    $result[] = $this->serializeConstraintRow($constraint, $constraint->getId() . ':' . $team->getId(), $team->getId(), $config);
                }

                continue;
            }

            // Pass through as-is (TEAM, COACH, or CLUB variants handled above)
            $result[] = [
                'id' => $constraint->getId(),
                'scope' => $scope->value,
                'scopeTargetId' => $constraint->getScopeTargetId(),
                'family' => $constraint->getFamily()->value,
                'ruleType' => $constraint->getRuleType()->value,
                'name' => $constraint->getName(),
                'config' => $config,
                'sortOrder' => $constraint->getSortOrder(),
                'isActive' => $constraint->getIsActive(),
            ];
        }

        return $result;
    }

    /**
     * One serialized TEAM-scope constraint row (the shared shape of the three
     * expansion paths — tag, forbidden-outside-tag, club-wide).
     *
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function serializeConstraintRow(Constraint $constraint, string $id, string $teamId, array $config, ?string $name = null, ?string $ruleType = null): array
    {
        return [
            'id' => $id,
            'scope' => ConstraintScope::TEAM->value,
            'scopeTargetId' => $teamId,
            'family' => $constraint->getFamily()->value,
            'ruleType' => $ruleType ?? $constraint->getRuleType()->value,
            'name' => $name ?? $constraint->getName(),
            'config' => $config,
            'sortOrder' => $constraint->getSortOrder(),
            'isActive' => $constraint->getIsActive(),
        ];
    }

    /**
     * Resolve a tag-targeted CLUB config to its final list of team IDs for the season
     * — (∩ targetTags) − (∪ excludeTags), D8 exclude-only base = every season team.
     *
     * @param array<string, mixed> $config
     * @param array<Team>          $teams  the season roster (D8 base for exclude-only)
     *
     * @return list<string>
     */
    private function resolveTagToTeamIds(array $config, string $seasonId, string $clubId, array $teams): array
    {
        // P2-14 / P2-29 : la résolution vit dans TeamTagResolver (source unique, partagée
        // avec la sélection de période — le gate en dépend transitivement, d'où la parité).
        // Le mode léger sans DB (ContractSchemaTest) n'a pas de résolveur : aucune ligne.
        if (!$this->tagResolver instanceof TeamTagResolver) {
            return [];
        }

        $seasonTeamIds = array_values(array_map(static fn (Team $team): string => $team->getId(), $teams));

        return $this->tagResolver->resolveConstraintTeamIds($config, $seasonId, $clubId, $seasonTeamIds);
    }

    /** @param array<object> $entities */
    private function firstString(array $entities, string $method): ?string
    {
        foreach ($entities as $entity) {
            if (!method_exists($entity, $method)) {
                continue;
            }

            $value = $entity->$method();
            if (\is_string($value) && '' !== $value) {
                return $value;
            }
        }

        return null;
    }
}
