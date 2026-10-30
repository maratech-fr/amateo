<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Club;
use App\Entity\Schedule;
use App\Entity\ScheduleDiagnostic;
use App\Entity\SchedulePlan;
use App\Entity\Season;
use App\Entity\Venue;
use App\Enum\ScheduleDiagnosticSeverity;
use App\Enum\SchedulePlanType;
use App\Enum\ScheduleStatus;
use App\Repository\ClubRepository;
use App\Repository\ClubUserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Throwable;

/**
 * Chantier F PR1 — le CONTEXTE AUTOMATIQUE large d'un signalement. « On donne
 * l'erreur, mais c'est le contexte qui permet de se resituer et de corriger au
 * plus vite » (intention fondateur) : chaque signalement, planning nommé ou non,
 * porte ce que le support lirait sinon en interrogeant l'auteur — rôle, saison,
 * offre, compteurs, socle, période courante, versions, génération en cours,
 * horloge simulée.
 *
 * Tout est résolu CÔTÉ SERVEUR depuis le club AUTHENTIFIÉ : jamais un `clubId`/
 * `seasonId`/`planId` venu du client (ces clés ne sont pas whitelistées, donc déjà
 * tombées avant d'arriver ici — NR d'axe tenant FeedbackContextIsolationTest).
 * Les lectures passent par les repositories sous le filtre tenant / RLS.
 *
 * Best-effort : une lecture qui échoue ne doit JAMAIS perdre le signalement — on
 * trace sans PII et on stocke ce qui a pu être calculé.
 */
final readonly class FeedbackContextEnricher
{
    /**
     * Diagnostics d'INFRASTRUCTURE émis par le backend (le moteur a planté, timeout,
     * entrée disparue…) — nature « technical ». Tout autre type (`conflict`,
     * `session_below_effective_min`, `implicit_rule_not_honored`…) est une INFÉASIBILITÉ
     * du solveur, donc « business » : le gestionnaire a posé des contraintes incompatibles.
     *
     * @var list<string>
     */
    private const array TECHNICAL_FAILURE_TYPES = [
        'engine_timeout', 'engine_error', 'engine_rejected', 'internal_error',
        'engine_status', 'engine_failed', 'stuck_timeout', 'overlay_entry_missing',
        'club_mismatch', 'generation_failed',
    ];

    /** Un échec n'est joint que s'il est FRAIS — au-delà, il embrouille le support (pensé pour ~20 clubs). */
    private const int FRESH_FAILURE_WINDOW_HOURS = 12;

    /** Le 1ᵉʳ message d'erreur est borné : un message moteur peut faire des milliers de caractères. */
    private const int FIRST_ERROR_MAX = 300;

    public function __construct(
        private ClubRepository $clubRepository,
        private ClubUserRepository $clubUserRepository,
        private SeasonResolver $seasonResolver,
        private PlanEntitlements $planEntitlements,
        private ClubGenerationLock $generationLock,
        private EntityManagerInterface $entityManager,
        private RequestStack $requestStack,
        private ClockInterface $clock,
        private PayloadCapacityMirror $capacityMirror,
        private LoggerInterface $logger,
        private string $apiVersion,
    ) {}

    /**
     * Rend le contexte reçu ENRICHI d'une clé `auto` (le contexte serveur). Le
     * contexte d'entrée (contexte léger déjà whitelisté + copie éventuelle du
     * planning nommé) est conservé tel quel.
     *
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public function enrich(string $clubId, string $userId, array $context): array
    {
        $auto = [];
        try {
            $auto = $this->computeAuto($clubId, $userId, $context);
        } catch (Throwable $e) {
            // Enrichissement avalé (le signalement partira avec ce qu'on a) mais tracé
            // sans PII — seulement le message d'erreur, aucun identifiant de club/user.
            $this->logger->warning('Feedback context enrichment failed', ['error' => $e->getMessage()]);
        }

        if ([] !== $auto) {
            $context['auto'] = $auto;
        }

        return $context;
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private function computeAuto(string $clubId, string $userId, array $context): array
    {
        $club = $this->clubRepository->find($clubId);
        $season = $this->seasonResolver->selectedOrCurrent($this->requestStack->getCurrentRequest(), $clubId);

        $membership = $this->clubUserRepository->findActiveMembership($userId, $clubId);

        $auto = [
            'role' => $membership?->getRole(),
            'season' => $season instanceof Season ? [
                'id' => $season->getId(),
                'name' => $season->getName(),
                'startDate' => $season->getStartDate()->format('Y-m-d'),
                'endDate' => $season->getEndDate()->format('Y-m-d'),
            ] : null,
            'counters' => $this->counters($clubId, $season),
            'socle' => $this->socle($season),
            'period' => $this->period($season),
            'versions' => $this->versions($clubId, $season, $context),
            'generation' => ['running' => $this->generationLock->isGenerating($clubId)],
            // Version de l'API résolue CÔTÉ SERVEUR (env VERSION, repli « dev ») — le support
            // compare « App vX · API vY » sans rien demander à l'auteur.
            'apiVersion' => $this->apiVersion,
            // Horloge simulée du club (démos/dev) — null pour tout club à l'heure réelle.
            'simulatedToday' => $club?->getSimulatedToday()?->format('Y-m-d'),
        ];

        // La RAISON d'un échec de génération RÉCENT (< 12 h), résolue côté serveur depuis la
        // dernière génération de la saison AUTHENTIFIÉE — jointe seulement s'il y en a une.
        $failure = $this->generationFailure($clubId, $season, $context);
        if (null !== $failure) {
            $auto['generationFailure'] = $failure;
        }

        // L'offre EFFECTIVE (Découverte par défaut) — même calcul que /api/me.
        if ($club instanceof Club && $season instanceof Season) {
            $entitlements = $this->planEntitlements->forClub($club, $season);
            $auto['offer'] = ['plan' => $entitlements['planCode'], 'name' => $entitlements['planName']];
            $auto['counters']['teams'] = $entitlements['teamsUsed'];
        } else {
            $auto['offer'] = null;
        }

        return $auto;
    }

    /**
     * Compteurs d'inventaire du club pour la saison courante : équipes (renseigné
     * par l'offre, voir computeAuto) et gymnases. Lus sous le filtre tenant.
     *
     * @return array{teams: int, venues: int}
     */
    private function counters(string $clubId, ?Season $season): array
    {
        $venues = $season instanceof Season
            ? $this->entityManager->getRepository(Venue::class)->count(['clubId' => $clubId, 'seasonId' => $season->getId()])
            : 0;

        return ['teams' => 0, 'venues' => $venues];
    }

    /**
     * Le socle (plan SEASON, ADR-0002) : en vigueur dès qu'une version est pointée
     * (`chosenScheduleId`), et l'instant de sa PREMIÈRE validation (null tant qu'il
     * n'est pas validé).
     *
     * @return array{inForce: bool, validatedAt: string|null}
     */
    private function socle(?Season $season): array
    {
        $plan = $season instanceof Season ? $this->seasonPlan($season) : null;

        return [
            'inForce' => null !== $plan?->getChosenScheduleId(),
            'validatedAt' => $plan?->getFirstChosenAt()?->format(\DATE_ATOM),
        ];
    }

    /**
     * La période courante : le plan de période (CLOSURE/HOLIDAY) dont la fenêtre
     * contient AUJOURD'HUI (horloge du club). null hors période — « pas de période »
     * est une information que le support lit.
     *
     * @return array{type: string, name: string, startDate: string, endDate: string, progress: float}|null
     */
    private function period(?Season $season): ?array
    {
        if (!$season instanceof Season) {
            return null;
        }

        $today = $this->clock->now()->setTime(0, 0);
        $plan = $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from(SchedulePlan::class, 'p')
            ->where('p.seasonId = :sid')
            ->andWhere('p.type IN (:types)')
            ->andWhere('p.startDate <= :today')
            ->andWhere('p.endDate >= :today')
            ->setParameter('sid', $season->getId())
            ->setParameter('types', [SchedulePlanType::CLOSURE, SchedulePlanType::HOLIDAY])
            ->setParameter('today', $today)
            ->orderBy('p.startDate', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$plan instanceof SchedulePlan) {
            return null;
        }

        $start = $plan->getStartDate();
        $end = $plan->getEndDate();
        $span = $end->getTimestamp() - $start->getTimestamp();
        // Avancement de la fenêtre à la date du club : part du temps écoulé, bornée
        // à [0, 1] (fenêtre d'un seul jour → considérée pleine).
        $progress = $span > 0
            ? max(0.0, min(1.0, round(($today->getTimestamp() - $start->getTimestamp()) / $span, 2)))
            : 1.0;

        return [
            'type' => $plan->getType()->value,
            'name' => $plan->getName(),
            'startDate' => $start->format('Y-m-d'),
            'endDate' => $end->format('Y-m-d'),
            'progress' => $progress,
        ];
    }

    /**
     * Les versions utiles au support : celle AFFICHÉE (le `scheduleId` que le client
     * a joint, DÉJÀ validé tenant par le contrôleur — étranger → 404) et celle
     * POINTÉE (la version choisie du plan SEASON, vérité serveur). null quand il n'y
     * en a pas.
     *
     * @param array<string, mixed> $context
     *
     * @return array{displayed: array{id: string, number: int, name: string}|null, pointed: array{id: string, number: int, name: string}|null}
     */
    private function versions(string $clubId, ?Season $season, array $context): array
    {
        $displayedId = \is_string($context['scheduleId'] ?? null) ? $context['scheduleId'] : null;

        $plan = $season instanceof Season ? $this->seasonPlan($season) : null;
        $pointedId = $plan?->getChosenScheduleId();

        return [
            'displayed' => null !== $displayedId ? $this->scheduleLabel($clubId, $displayedId) : null,
            'pointed' => null !== $pointedId ? $this->scheduleLabel($clubId, $pointedId) : null,
        ];
    }

    /**
     * Libellé humain d'une version (ce que l'UI montre : son numéro et son nom) + son
     * id. Re-vérifie l'appartenance au club authentifié par prudence (aucun id client
     * n'est accepté sans contrôle tenant).
     *
     * @return array{id: string, number: int, name: string}|null
     */
    private function scheduleLabel(string $clubId, string $scheduleId): ?array
    {
        $schedule = $this->entityManager->getRepository(Schedule::class)->find($scheduleId);
        if (!$schedule instanceof Schedule || $schedule->getClubId() !== $clubId) {
            return null;
        }

        return [
            'id' => $schedule->getId(),
            'number' => $schedule->getVersionNumber(),
            'name' => $schedule->getName(),
        ];
    }

    /**
     * La RAISON d'un échec de génération récent, résumée pour le support. Résout CÔTÉ
     * SERVEUR la DERNIÈRE génération terminée (FAILED|COMPLETED) de la saison du club
     * AUTHENTIFIÉ, datée par COALESCE(solveStartedAt, createdAt) — un échec AVANT le
     * passage en GENERATING (club_mismatch/overlay_entry_missing) n'a pas de
     * solveStartedAt et retombe sur createdAt.
     *
     * Joint seulement si cette dernière version est FAILED ET fraîche (< 12 h) : une
     * COMPLETED postérieure l'efface (le gestionnaire a re-généré avec succès). La requête
     * est scopée au `clubId` (défense en profondeur AU-DESSUS du filtre tenant / RLS) : un
     * échec frais d'un AUTRE club ne doit jamais remonter — angle tenant prouvé bloquant par
     * un NR de sécurité dédié (tests/Security/FeedbackContextIsolationTest, hors src).
     *
     * D17 (PR B « Signaler ») — ÉTENDUE (jamais réécrite) : au résumé s'ajoutent, résolus côté
     * serveur, l'id du planning, le verdict du solveur, le plan (socle/période), le résumé de grille
     * et — sauf doublon avec un planning déjà nommé par le client — la COPIE LOURDE (snapshot réel +
     * diagnostics détaillés).
     *
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>|null
     */
    private function generationFailure(string $clubId, ?Season $season, array $context): ?array
    {
        if (!$season instanceof Season) {
            return null;
        }

        $last = $this->entityManager->createQueryBuilder()
            ->select('s', 'COALESCE(s.solveStartedAt, s.createdAt) AS HIDDEN failedAtSort')
            ->from(Schedule::class, 's')
            ->where('s.clubId = :cid')
            ->andWhere('s.seasonId = :sid')
            ->andWhere('s.status IN (:statuses)')
            ->setParameter('cid', $clubId)
            ->setParameter('sid', $season->getId())
            ->setParameter('statuses', [ScheduleStatus::FAILED, ScheduleStatus::COMPLETED])
            ->orderBy('failedAtSort', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$last instanceof Schedule || ScheduleStatus::FAILED !== $last->getStatus()) {
            return null;
        }

        $failedAt = $last->getSolveStartedAt() ?? $last->getCreatedAt();
        if ($failedAt < $this->clock->now()->modify('-' . self::FRESH_FAILURE_WINDOW_HOURS . ' hours')) {
            return null;
        }

        $diagnostics = $this->entityManager->getRepository(ScheduleDiagnostic::class)
            ->findBy(['scheduleId' => $last->getId()]);

        $countsByType = [];
        $firstError = '';
        $firstErrorType = null;
        foreach ($diagnostics as $diagnostic) {
            $type = $diagnostic->getType();
            $countsByType[$type] = ($countsByType[$type] ?? 0) + 1;
            // Le 1ᵉʳ message d'erreur (severity ERROR) — le reste n'est qu'un agrégat par type.
            if ('' === $firstError && ScheduleDiagnosticSeverity::ERROR === $diagnostic->getSeverity()) {
                $firstError = mb_substr($diagnostic->getMessage(), 0, self::FIRST_ERROR_MAX);
                $firstErrorType = $type;
            }
        }

        // Repli si (improbable) un FAILED ne porte aucun diagnostic ERROR : le 1ᵉʳ diagnostic.
        if ('' === $firstError && [] !== $diagnostics) {
            $firstError = mb_substr($diagnostics[0]->getMessage(), 0, self::FIRST_ERROR_MAX);
            $firstErrorType ??= $diagnostics[0]->getType();
        }

        $type = $firstErrorType ?? 'unknown';

        $gf = [
            'status' => 'failed',
            'kind' => \in_array($type, self::TECHNICAL_FAILURE_TYPES, true) ? 'technical' : 'business',
            'type' => $type,
            'failedAt' => $failedAt->format(\DATE_ATOM),
            'version' => ['number' => $last->getVersionNumber(), 'name' => $last->getName()],
            'firstError' => $firstError,
            'countsByType' => $countsByType,
            // D17 (1) — l'id du planning FAILED, résolu côté serveur : pivot de toute la copie lourde.
            'scheduleId' => $last->getId(),
            // D17 (3) — le verdict du solveur, lu sans ouvrir le planning.
            'solver' => [
                'version' => $last->getSolverVersion(),
                'wallTimeMs' => $last->getSolverWallTimeMs(),
                'timeoutSeconds' => $last->getSolverTimeoutSeconds(),
                'nbVariables' => $last->getSolverNbVariables(),
                'nbConstraints' => $last->getSolverNbConstraints(),
                'nbConflicts' => $last->getSolverNbConflicts(),
            ],
            // D17 (4) — le plan (socle SEASON / période CLOSURE|HOLIDAY) auquel appartient l'échec.
            'plan' => $this->planSummary($last->getSchedulePlanId()),
            // D17 (2) — résumé de grille, calculé sur le SNAPSHOT figé (fonction pure du miroir de capacité).
            'gridSummary' => $this->capacityMirror->gridSummary($last->engineInput()),
        ];

        // D17 (1) — COPIE LOURDE : le snapshot RÉEL + les diagnostics détaillés, sautés SANS DOUBLON
        // si le client nomme déjà CE planning (le contrôleur a alors joint snapshot + diagnostics à la
        // racine du contexte — on ne les recopie pas une seconde fois sous `generationFailure`).
        $clientScheduleId = \is_string($context['scheduleId'] ?? null) ? $context['scheduleId'] : null;
        if ($clientScheduleId !== $last->getId()) {
            $gf['snapshot'] = $last->engineInput();
            $gf['diagnostics'] = $this->detailedDiagnostics($diagnostics);
        }

        return $gf;
    }

    /**
     * Les diagnostics d'un planning, sérialisés comme {@see App\Controller\FeedbackController::diagnosticsOf}
     * — même forme, pour que le support ne lise qu'une structure.
     *
     * @param list<ScheduleDiagnostic> $diagnostics
     *
     * @return list<array<string, mixed>>
     */
    private function detailedDiagnostics(array $diagnostics): array
    {
        return array_map(static fn (ScheduleDiagnostic $diagnostic): array => [
            'type' => $diagnostic->getType(),
            'severity' => $diagnostic->getSeverity()->value,
            'message' => $diagnostic->getMessage(),
            'teamId' => $diagnostic->getTeamId(),
            'coachId' => $diagnostic->getCoachId(),
            'venueId' => $diagnostic->getVenueId(),
            'suggestions' => $diagnostic->getSuggestions(),
        ], $diagnostics);
    }

    /**
     * Le plan (socle/période) d'un planning : son type, son nom et ses dates — ce que le support lit
     * pour situer l'échec. null si le plan a disparu.
     *
     * @return array{type: string, name: string, startDate: string, endDate: string}|null
     */
    private function planSummary(?string $planId): ?array
    {
        if (null === $planId) {
            return null;
        }

        $plan = $this->entityManager->getRepository(SchedulePlan::class)->find($planId);
        if (!$plan instanceof SchedulePlan) {
            return null;
        }

        return [
            'type' => $plan->getType()->value,
            'name' => $plan->getName(),
            'startDate' => $plan->getStartDate()->format('Y-m-d'),
            'endDate' => $plan->getEndDate()->format('Y-m-d'),
        ];
    }

    /** Le plan SEASON de la saison (socle) — null pour une donnée antérieure au plan de base. */
    private function seasonPlan(Season $season): ?SchedulePlan
    {
        return $this->entityManager->getRepository(SchedulePlan::class)
            ->findOneBy(['seasonId' => $season->getId(), 'type' => SchedulePlanType::SEASON]);
    }
}
