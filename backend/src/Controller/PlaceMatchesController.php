<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Club;
use App\Entity\MatchPlacementRun;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\MatchPlacementRunStatus;
use App\Message\PlaceMatchesMessage;
use App\MessageHandler\PlaceMatchesHandler;
use App\Repository\MatchPlacementRunRepository;
use App\Service\DeploymentGate;
use App\Service\FriendlyAutoValidator;
use App\Service\LeagueValidationOutlook;
use App\Service\ManagementAccessGuard;
use App\Service\MatchPlacementLock;
use App\Service\MatchPlacementPayloadBuilder;
use App\Service\PlacementConflictText;
use App\Service\PlanEntitlements;
use App\Service\SeasonAccessGuard;
use App\Service\SeasonResolver;
use App\Service\SocleGuard;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

/**
 * POST /api/fixtures/place — « Placer automatiquement » (P1-4 PR D, ADR-0003).
 * Rail ASYNCHRONE (patron de la génération) : le contrôleur garde toutes ses gardes
 * (SEC-07 → socle 409 → saison archivée 409 → fenêtre Découverte 403), balaie « Déjà dans
 * FBI » les domiciles datés des championnats commencés (après les gardes de saison et de
 * socle, avant de décider quoi placer), prend le verrou
 * anti-double-demande, ENFILE un {@see PlaceMatchesMessage} et répond 202 avec l'id du
 * run. Le worker ({@see PlaceMatchesHandler}) solve, applique, décompte
 * le crédit au SUCCÈS et pousse la bascule terminale sur `/clubs/{clubId}/placement`.
 *
 * Verrou : pris ICI (TTL = budget du run = nb de semaines ISO × budget/semaine + marge),
 * tenu pendant tout le run, RELÂCHÉ par le worker (token porté par le message). Une seconde
 * demande du même club pendant un run ouvert échoue à l'acquisition → 409.
 *
 * P4-240 ④ — un corps OPTIONNEL `{from, to}` (dates AAAA-MM-JJ) restreint le placement à cette
 * fenêtre (« Placer ce week-end ») ; pas de corps = tout le club. En Découverte (restricted,
 * non démo, pool > 0) la fenêtre est OBLIGATOIRE et d'au plus une semaine (403 sinon) — défense
 * serveur derrière le bouton global désactivé côté front. Le crédit (1 clic = 1 crédit) est
 * décompté par le worker au COMPLETED, plus par ce contrôleur.
 */
#[AsController]
final class PlaceMatchesController extends AbstractController
{
    use ResolvesCurrentClubTrait;

    /**
     * Largeur MAX (en jours d'écart from→to) d'une fenêtre acceptée en mode crédit :
     * 6 = une semaine Lun→Dim (7 jours calendaires inclus). Au-delà, le petit bouton
     * « Placer ce week-end » contournerait la règle week-end-par-week-end → 403.
     */
    private const int MAX_WINDOW_DAYS = 6;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RequestStack $requestStack,
        private readonly SeasonResolver $seasonResolver,
        private readonly ManagementAccessGuard $managementAccessGuard,
        private readonly DeploymentGate $deploymentGate,
        private readonly SeasonAccessGuard $seasonAccessGuard,
        private readonly SocleGuard $socleGuard,
        private readonly FriendlyAutoValidator $friendlyAutoValidator,
        private readonly LeagueValidationOutlook $leagueValidationOutlook,
        private readonly MatchPlacementLock $lock,
        private readonly MatchPlacementPayloadBuilder $payloadBuilder,
        private readonly PlanEntitlements $planEntitlements,
        private readonly MessageBusInterface $messageBus,
        // Horloge DÉCORÉE : le balayage « Déjà dans FBI » date les rencontres (`reviewedAt`, date
        // MÉTIER) au jour du club — simulé pour un club de démonstration, comme l'écran.
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        // Horloge RÉELLE, réservée à `createdAt` du run : il sert des DURÉES (grâce du rattrapage,
        // seuil d'e-mail, ordre du dernier run) qui ne suivent jamais une date simulée.
        #[Autowire(service: 'app.clock.real')]
        private readonly ClockInterface $realClock,
        private readonly MatchPlacementRunRepository $runRepository,
    ) {}

    // priority > 0: the static path must win over API Platform's /api/fixtures/{id}.
    #[Route('/api/fixtures/place', name: 'api_fixtures_place', methods: ['POST'], priority: 10)]
    public function __invoke(Request $request): JsonResponse
    {
        $clubId = $this->resolveCurrentClubId($this->requestStack);
        if (null === $clubId) {
            return $this->json(['error' => 'No club in context.'], Response::HTTP_BAD_REQUEST);
        }

        // SEC-07 first so 403 wins over the 409s (import idiom).
        $this->managementAccessGuard->assertManager();
        // P4-350 (axe generation pipeline) : gel des guichets pendant une mise en production
        // imminente — AVANT la garde saison/socle, uniforme avec les trois autres portes de solve.
        if ($this->deploymentGate->isFrozen()) {
            return $this->json(['error' => DeploymentGate::FREEZE_MESSAGE], Response::HTTP_CONFLICT);
        }
        $this->seasonAccessGuard->assertWritable($request);
        $this->socleGuard->assertSeasonPlanChosen($request->attributes->get('_season_id') ?? $request->headers->get('X-Season-Id'));

        // Balayage « Déjà dans FBI » AVANT de décider quoi placer (gardes passées : gestionnaire,
        // hors gel, saison écrivable, socle en vigueur) : une page montée avant que le
        // championnat ne commence n'a peut-être rien relu — un domicile daté d'un championnat
        // commencé ne doit jamais partir TO_PLACE. Amicaux passés PUIS championnats.
        $sweepSeasonId = $request->attributes->get('_season_id');
        if (\is_string($sweepSeasonId) && '' !== $sweepSeasonId) {
            $this->friendlyAutoValidator->sweep($clubId, $sweepSeasonId);
            $this->leagueValidationOutlook->sweepStartedChampionships($sweepSeasonId, DateTimeImmutable::createFromInterface($this->clock->now()));
        }

        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'No user in context.'], Response::HTTP_BAD_REQUEST);
        }

        $club = $this->entityManager->getRepository(Club::class)->find($clubId);
        if (!$club instanceof Club) {
            return $this->json(['error' => 'No club in context.'], Response::HTTP_BAD_REQUEST);
        }

        // P4-240 ④ — fenêtre de placement optionnelle {from, to} (dates AAAA-MM-JJ).
        $raw = trim($request->getContent());
        $window = null;
        $windowDays = 0;
        if ('' !== $raw) {
            $decoded = json_decode($raw, true);
            if (!\is_array($decoded)) {
                return $this->json(['error' => 'Le corps de la requête doit être un objet JSON.'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $from = $decoded['from'] ?? null;
            $to = $decoded['to'] ?? null;
            if (null !== $from || null !== $to) {
                $fromDate = \is_string($from) ? $this->asDate($from) : null;
                $toDate = \is_string($to) ? $this->asDate($to) : null;
                if (!$fromDate instanceof DateTimeImmutable || !$toDate instanceof DateTimeImmutable) {
                    return $this->json(['error' => 'Semaine invalide : indiquez deux dates au format AAAA-MM-JJ.'], Response::HTTP_UNPROCESSABLE_ENTITY);
                }
                if ($fromDate > $toDate) {
                    return $this->json(['error' => 'Semaine invalide : la date de début est postérieure à la date de fin.'], Response::HTTP_UNPROCESSABLE_ENTITY);
                }
                $window = ['from' => $fromDate->format('Y-m-d'), 'to' => $toDate->format('Y-m-d')];
                $windowDays = (int) $fromDate->diff($toDate)->days;
            }
        }

        // Mode crédit (Découverte effective, club non démo, pool > 0) : placement week-end par
        // week-end. Défense SERVEUR (le front désactive le bouton global). La saison est résolue
        // ici (avant le verrou), réutilisée pour le build.
        $season = $this->seasonResolver->selectedOrCurrent($request, $clubId);
        if ($season instanceof Season
            && $this->planEntitlements->outputBudget($club, $season)['restricted']
            && (null === $window || $windowDays > self::MAX_WINDOW_DAYS)) {
            throw new AccessDeniedHttpException('En offre Découverte, le placement automatique se fait week-end par week-end — sélectionnez un week-end.');
        }

        // Le payload builder connaît les matchs : il sert ICI à décider s'il y a quelque chose à
        // placer et à compter les semaines ISO (dimensionnement du verrou/timeout). Le worker
        // RE-construit un payload FRAIS au moment du solve (l'état a pu bouger) — ce build-ci ne
        // voyage pas.
        $built = $this->payloadBuilder->build($club, $season?->getId(), $window);
        if (0 === $built['toPlaceCount']) {
            return $this->json([
                'placed' => 0,
                'skipped' => 0,
                'unplaced' => [],
                'diagnostics' => $built['infoDiagnostics'],
                'message' => 'Aucun match à placer.',
            ]);
        }

        /** @var list<array<string, mixed>> $matches */
        $matches = $built['payload']['matches'];
        $weeksCount = $this->distinctIsoWeeksToPlace($matches);

        // Verrou pris ICI pour tout le run (TTL = budget du run) : une 2ᵉ demande pendant un run
        // ouvert échoue à l'acquisition → 409, dont le texte suit le dernier run du club (en file :
        // aucun délai promis ; en cours : le délai restant du verrou). Rendu par le worker.
        $token = $this->lock->acquire($clubId, PlaceMatchesMessage::budgetSecondsFor($weeksCount));
        if (null === $token) {
            $latest = $this->runRepository->latestForCurrentScope();

            return $this->json(
                ['error' => PlacementConflictText::for($latest?->getStatus(), $this->lock->remainingSeconds($clubId))],
                Response::HTTP_CONFLICT,
            );
        }

        $run = null;

        try {
            $run = new MatchPlacementRun(
                clubId: $clubId,
                seasonId: $season?->getId(),
                requestedByUserId: $user->getId(),
                createdAt: DateTimeImmutable::createFromInterface($this->realClock->now()),
            );
            $this->entityManager->persist($run);
            $this->entityManager->flush();

            $this->messageBus->dispatch(new PlaceMatchesMessage(
                runId: $run->getId(),
                clubId: $clubId,
                seasonId: $season?->getId(),
                weeksCount: $weeksCount,
                lockToken: $token,
                window: $window,
            ));
        } catch (Throwable $exception) {
            // L'enfilage a échoué : aucun worker ne traitera ce run ni ne rendra le verrou. Le run
            // déjà écrit passe FAILED (l'écran le relit et rouvre le bouton), puis on rend le verrou.
            $this->failUnqueuedRun($run);
            $this->lock->release($clubId, $token);
            $this->logger->error('Failed to enqueue match placement', ['clubId' => $clubId, 'exception' => $exception]);

            throw $exception;
        }

        return $this->json([
            'runId' => $run->getId(),
            'status' => $run->getStatus()->value,
        ], Response::HTTP_ACCEPTED);
    }

    /**
     * Best-effort : un run flushé dont le message n'a pas été enfilé passe FAILED « n'a pas pu être
     * lancé » (jamais un PENDING que personne ne traitera). Run non écrit, ou EM fermé : rien à
     * faire ici — le rattrapage du worker fermera un PENDING resté sans message.
     */
    private function failUnqueuedRun(?MatchPlacementRun $run): void
    {
        if (!$run instanceof MatchPlacementRun || !$this->entityManager->isOpen() || !$this->entityManager->contains($run)) {
            return;
        }

        try {
            $run->setStatus(MatchPlacementRunStatus::FAILED)
                ->setFinishedAt(DateTimeImmutable::createFromInterface($this->realClock->now()))
                ->setResultData(['error' => PlaceMatchesHandler::ERROR_NOT_STARTED]);
            $this->entityManager->flush();
        } catch (Throwable $exception) {
            $this->logger->warning('Failed to mark an unqueued match placement run as failed', ['runId' => $run->getId(), 'exception' => $exception]);
        }
    }

    /**
     * Le nombre de semaines ISO DISTINCTES portant au moins un match à placer — la grandeur
     * qui dimensionne le budget (le moteur découpe et solve semaine par semaine, ENG-50).
     *
     * @param list<array<string, mixed>> $matches
     */
    private function distinctIsoWeeksToPlace(array $matches): int
    {
        $weeks = [];
        foreach ($matches as $match) {
            if ('TO_PLACE' !== ($match['kind'] ?? null)) {
                continue;
            }
            $date = $match['date'] ?? null;
            if (!\is_string($date)) {
                continue;
            }
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if (false === $parsed) {
                continue;
            }
            // Clé (année ISO, semaine ISO) : « o-W » — un week-end Samedi/Dimanche partage toujours
            // une clé, et la semaine 1 de janvier n'est jamais confondue avec la 53 de décembre.
            $weeks[$parsed->format('o-W')] = true;
        }

        return max(1, \count($weeks));
    }

    /**
     * Parse STRICTE d'une date AAAA-MM-JJ (le `!` remet l'heure à 00:00, le round-trip
     * rejette « 2026-13-40 », « 2026-2-3 », une chaîne à rallonge…). null = invalide.
     */
    private function asDate(string $value): ?DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return false !== $date && $date->format('Y-m-d') === $value ? $date : null;
    }
}
