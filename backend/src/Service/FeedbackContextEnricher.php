<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Club;
use App\Entity\Schedule;
use App\Entity\SchedulePlan;
use App\Entity\Season;
use App\Entity\Venue;
use App\Enum\SchedulePlanType;
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
    public function __construct(
        private ClubRepository $clubRepository,
        private ClubUserRepository $clubUserRepository,
        private SeasonResolver $seasonResolver,
        private PlanEntitlements $planEntitlements,
        private ClubGenerationLock $generationLock,
        private EntityManagerInterface $entityManager,
        private RequestStack $requestStack,
        private ClockInterface $clock,
        private LoggerInterface $logger,
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
            // Horloge simulée du club (démos/dev) — null pour tout club à l'heure réelle.
            'simulatedToday' => $club?->getSimulatedToday()?->format('Y-m-d'),
        ];

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

    /** Le plan SEASON de la saison (socle) — null pour une donnée antérieure au plan de base. */
    private function seasonPlan(Season $season): ?SchedulePlan
    {
        return $this->entityManager->getRepository(SchedulePlan::class)
            ->findOneBy(['seasonId' => $season->getId(), 'type' => SchedulePlanType::SEASON]);
    }
}
