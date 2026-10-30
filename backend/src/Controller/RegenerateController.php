<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Schedule;
use App\Enum\ScheduleStatus;
use App\Message\GenerateScheduleMessage;
use App\Service\CoachInTwoPlacesGuard;
use App\Service\DeploymentGate;
use App\Service\GenerationComplexityGuard;
use App\Service\ManagementAccessGuard;
use App\Service\SchedulePlanProvisioner;
use App\Service\SocleGuard;
use App\Service\WriteTargetSeasonResolver;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

/**
 * planning-versions (décision 6): the plain "Régénérer" creates a NEW linear
 * version (V2, V3…) with the CURRENT club structure — never regenerates a
 * version in place (that would silently overwrite it, so no new version appears).
 * HARD locks survive without any copy here: the generation payload
 * (ScheduleConstraintBuilder::findBaseSlotTemplates) already feeds every base
 * version's HARD slots as pins, so the solver re-honours them — exactly as the
 * in-place regeneration used to. Contrast RegenerateFromVersion, which RESTORES
 * a past version's structure photo first.
 */
#[AsController]
final class RegenerateController extends AbstractController implements SeasonScopedWriteInterface
{
    use ResolvesCurrentClubTrait;

    private const IN_FLIGHT = [ScheduleStatus::PENDING, ScheduleStatus::GENERATING];

    public function __construct(
        private readonly CoachInTwoPlacesGuard $coachInTwoPlacesGuard,
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $messageBus,
        private readonly RequestStack $requestStack,
        private readonly ManagementAccessGuard $managementAccessGuard,
        private readonly GenerationComplexityGuard $complexityGuard,
        private readonly SchedulePlanProvisioner $schedulePlanProvisioner,
        private readonly SocleGuard $socleGuard,
        private readonly WriteTargetSeasonResolver $writeTargetSeasonResolver,
        private readonly DeploymentGate $deploymentGate,
    ) {}

    // SEC-13 — la cible est le Schedule nommé dans l'URL (id de version).
    public function writeTargetSeasonId(Request $request): ?string
    {
        $id = $request->attributes->get('id');

        return \is_string($id) ? $this->writeTargetSeasonResolver->ofSchedule($id) : null;
    }

    #[Route('/api/schedules/{id}/regenerate', name: 'api_schedule_regenerate', methods: ['POST'])]
    public function __invoke(string $id): JsonResponse
    {
        $this->managementAccessGuard->assertManager(); // SEC-07

        // P4-350 (axe generation pipeline) : gel des guichets pendant une mise en production
        // imminente — refus AVANT tout lookup/état/socle.
        if ($this->deploymentGate->isFrozen()) {
            return $this->json(['error' => DeploymentGate::FREEZE_MESSAGE], Response::HTTP_CONFLICT);
        }

        try {
            $source = $this->entityManager->getRepository(Schedule::class)->find($id);
        } catch (Throwable) {
            $source = null;
        }
        if (!$source instanceof Schedule) {
            return $this->json(['error' => 'Planning introuvable.'], Response::HTTP_NOT_FOUND);
        }

        $currentClubId = $this->resolveCurrentClubId($this->requestStack);
        if (null !== $currentClubId && $source->getClubId() !== $currentClubId) {
            return $this->json(['error' => 'Accès refusé.'], Response::HTTP_FORBIDDEN);
        }

        // Season plans only — an overlay is regenerated via its own cockpit flow.
        // ADR-0002 C4 : « socle ? » = plan.type === SEASON, plus calendarEntryId.
        if (!$this->schedulePlanProvisioner->isSeasonSchedule($source)) {
            return $this->json(['error' => 'Un overlay de période se régénère depuis le cockpit.'], Response::HTTP_CONFLICT);
        }
        // Only an already-generated version yields a new version: a DRAFT/in-flight
        // has none to branch from (the first generation is the wizard's in-place path).
        if (!\in_array($source->getStatus(), [ScheduleStatus::COMPLETED, ScheduleStatus::FAILED], true)) {
            return $this->json(['error' => 'Seule une version terminée peut être régénérée.'], Response::HTTP_CONFLICT);
        }
        // ADR-0002 inv. 1 — la version CHOISIE est le planning en vigueur : on n'y
        // travaille pas, on le rouvre d'abord. Le statut VALIDATED portait cette
        // garde ; il n'existe plus, seul le pointeur dit « en vigueur ».
        if ($this->schedulePlanProvisioner->isChosen($source->getId())) {
            return $this->json(['error' => 'La version choisie est le planning en vigueur. Rouvrez-le avant de régénérer.'], Response::HTTP_CONFLICT);
        }
        // Never branch while any version of the season is still solving.
        $inFlight = $this->entityManager->getRepository(Schedule::class)->count([
            'clubId' => $source->getClubId(),
            'seasonId' => $source->getSeasonId(),
            'status' => self::IN_FLIGHT,
        ]);
        if ($inFlight > 0) {
            return $this->json(['error' => 'Une génération est déjà en cours — attendez sa fin.'], Response::HTTP_CONFLICT);
        }

        // A10: reject an over-complex problem before queuing (same caps as the
        // normal generation path — a direct dispatch would otherwise bypass it).
        $violation = $this->complexityGuard->firstViolation($source->getClubId(), $source->getSeasonId());
        if (null !== $violation) {
            return $this->json([
                'error' => \sprintf('Génération bloquée : trop de %s (%d, limite %d).', $violation['cap'], $violation['count'], $violation['limit']),
                'cap' => $violation['cap'], 'count' => $violation['count'], 'limit' => $violation['limit'],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $coachInTwoPlaces = $this->coachInTwoPlacesGuard->firstConflictMessage($source);
        if (null !== $coachInTwoPlaces) {
            return $this->json(['error' => $coachInTwoPlaces], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // New PENDING version, then dispatch (a dispatch failure only strands a
        // PENDING the watchdog reconciles — same trade-off as
        // GenerateScheduleController). No slot copy: the generation payload
        // re-pins the base versions' HARD locks on its own.
        // ADR-0002 inv. 12 : le nom vit sur LE PLAN. Régénérer produit une V+1 du MÊME plan,
        // elle en porte donc le nom — comme la création par POST depuis P4-41. Ce site
        // fabriquait « Planning {date-heure} », un QUATRIÈME libellé inventé pour le même
        // objet ; il ressortait dans le nom du fichier exporté quand le plan n'était pas
        // encore résolu. Repli sobre si le plan a disparu (course avec un reset) : la
        // transaction plus bas échouera de toute façon.
        $newSchedule = (new Schedule)
            ->setClubId($source->getClubId())
            ->setSeasonId($source->getSeasonId())
            ->setName($this->schedulePlanProvisioner->versionNameFor($source->getSchedulePlanId()))
            ->setStatus(ScheduleStatus::PENDING)
            // P5-10 — instant de mise en file (attente queue → generating mesurable).
            ->setQueuedAt(new DateTimeImmutable)
            // ADR-0002 C4 : la nouvelle version rejoint LE MÊME plan que la source
            // (régénérer = une V+1 du même plan) ; linkSchedule ne fait que la numéroter.
            ->setSchedulePlanId($source->getSchedulePlanId());
        $seasonId = $source->getSeasonId();
        $this->entityManager->wrapInTransaction(function () use ($newSchedule, $seasonId): void {
            // P2-9bis (planning lifecycle) : défense en profondeur du socle en vigueur.
            // SOUS le verrou de plan-scope de la saison (tenu jusqu'au commit — une garde
            // qui lit hors verrou n'est qu'un TOCTOU) : tant que le plan SEASON pointe une
            // version choisie, on refuse d'en préparer une autre. Miroir de processPost.
            $this->schedulePlanProvisioner->lockPlanScope(SchedulePlanProvisioner::seasonScopeKey($seasonId));
            $this->socleGuard->assertSeasonPlanNotChosen($seasonId);
            $this->entityManager->persist($newSchedule);
            $this->schedulePlanProvisioner->linkSchedule($newSchedule);
            $this->entityManager->flush();
        });

        $this->messageBus->dispatch(new GenerateScheduleMessage(
            scheduleId: $newSchedule->getId(),
            clubId: $newSchedule->getClubId(),
            // P3-21 — la source de `previousAssignments` est la version que le gestionnaire
            // REGARDE ($source), pas la dernière du plan : je regarde V2 alors que V5 existe,
            // la convergence se mesure à V2. Le repli « dernière COMPLETED » n'a lieu que
            // faute de source explicite (première génération wizard).
            sourceScheduleId: $source->getId(),
        ));

        return $this->json(['id' => $newSchedule->getId(), 'status' => ScheduleStatus::PENDING->value], Response::HTTP_ACCEPTED);
    }
}
