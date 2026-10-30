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
use App\Service\OrphanPinGuard;
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
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

/**
 * P2-44 PR-3 (ADR-0004) — LE COMBLEMENT : un solve PARTIEL d'une version de plan de PÉRIODE.
 *
 * Après « Partir du planning de saison » (transcription — {@see TranscribePeriodPlanController}),
 * le gestionnaire a une V1 = copie du socle où des séances restent « à replacer » (un gymnase a
 * fermé, une équipe a repris son volume). Combler crée une V+1 (savepoint — « génération =
 * savepoint », jamais in-place) et dispatch le rail de génération EXISTANT avec un mode « fill » :
 * les placements de la version source sont ÉPINGLÉS HARD dans le payload — tout ce qui est déjà
 * placé reste EXACTEMENT en place — et le solveur ne place QUE les trous (les équipes sous leur
 * `sessionsPerWeek`). AUCUN changement moteur : un HARD n'a pas de variable côté solveur.
 *
 * Outil d'appoint : le solve complet (« Régénérer ») reste offert à côté ; si les trous comblés ne
 * conviennent pas, le gestionnaire bouge son modèle lui-même (move).
 *
 * Miroir PÉRIODE de {@see RegenerateController} (qui, lui, ne vaut QUE pour le socle) : mêmes
 * gardes de cycle de vie (COMPLETED, non pointée, aucune génération en cours), plus celles propres
 * à un overlay (socle pointé, épinglage non orphelin) et le cap de solve PAR CLUB
 * (`ClubQuotaSubscriber`, route `api_schedule_fill`).
 */
#[AsController]
final class FillPeriodPlanController extends AbstractController implements SeasonScopedWriteInterface
{
    use ResolvesCurrentClubTrait;

    private const array IN_FLIGHT = [ScheduleStatus::PENDING, ScheduleStatus::GENERATING];

    public function __construct(
        private readonly CoachInTwoPlacesGuard $coachInTwoPlacesGuard,
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $messageBus,
        private readonly RequestStack $requestStack,
        private readonly ManagementAccessGuard $managementAccessGuard,
        private readonly SchedulePlanProvisioner $schedulePlanProvisioner,
        private readonly SocleGuard $socleGuard,
        private readonly GenerationComplexityGuard $complexityGuard,
        private readonly OrphanPinGuard $orphanPinGuard,
        private readonly WriteTargetSeasonResolver $writeTargetSeasonResolver,
        private readonly DeploymentGate $deploymentGate,
    ) {}

    // SEC-13 — la cible est le Schedule (version) nommé dans l'URL.
    public function writeTargetSeasonId(Request $request): ?string
    {
        $id = $request->attributes->get('id');

        return \is_string($id) ? $this->writeTargetSeasonResolver->ofSchedule($id) : null;
    }

    #[Route('/api/schedules/{id}/fill', name: 'api_schedule_fill', methods: ['POST'])]
    public function __invoke(string $id): JsonResponse
    {
        $this->managementAccessGuard->assertManager(); // SEC-07

        // P4-350 (axe generation pipeline) : gel des guichets pendant une mise en production
        // imminente — le refus de gel précède la garde « période » et tout le reste.
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

        // Périodes SEULEMENT : le comblement épingle une version puis place les trous — le socle,
        // lui, se régénère via /regenerate. ADR-0002 C4 : « socle ? » = plan.type === SEASON.
        $entryId = $this->schedulePlanProvisioner->periodEntryIdOf($source);
        if ($this->schedulePlanProvisioner->isSeasonSchedule($source) || null === $entryId) {
            return $this->json(['error' => 'Le comblement ne s\'applique qu\'à un plan de période.'], Response::HTTP_CONFLICT);
        }

        // On n'épingle qu'une version terminée : un DRAFT/FAILED/en vol n'a pas de placement stable
        // à figer.
        if (ScheduleStatus::COMPLETED !== $source->getStatus()) {
            return $this->json(['error' => 'Seule une version terminée peut être comblée.'], Response::HTTP_CONFLICT);
        }

        // ADR-0002 inv. 1 — la version CHOISIE est le planning en vigueur : on la rouvre avant d'en
        // dériver une autre (miroir de /regenerate).
        if ($this->schedulePlanProvisioner->isChosen($source->getId())) {
            return $this->json(['error' => 'La version choisie est le planning en vigueur. Rouvrez-le avant de combler.'], Response::HTTP_CONFLICT);
        }

        // Jamais dériver une version pendant qu'une génération de la saison tourne (un import
        // concurrent référencerait des entités que ce solve ne connaît pas).
        $inFlight = $this->entityManager->getRepository(Schedule::class)->count([
            'clubId' => $source->getClubId(),
            'seasonId' => $source->getSeasonId(),
            'status' => self::IN_FLIGHT,
        ]);
        if ($inFlight > 0) {
            return $this->json(['error' => 'Une génération est déjà en cours — attendez sa fin.'], Response::HTTP_CONFLICT);
        }

        // Un overlay ne se génère que si le socle est en vigueur (on épingle par-dessus lui).
        $this->socleGuard->assertSeasonPlanChosen($source->getSeasonId());

        // A10 : refuser un problème trop complexe AVANT de mettre en file (mêmes caps que /generate
        // — un dispatch direct les contournerait sinon).
        $violation = $this->complexityGuard->firstViolation($source->getClubId(), $source->getSeasonId());
        if (null !== $violation) {
            return $this->json([
                'error' => \sprintf('Génération bloquée : trop de %s (%d, limite %d).', $violation['cap'], $violation['count'], $violation['limit']),
                'cap' => $violation['cap'], 'count' => $violation['count'], 'limit' => $violation['limit'],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // #8 — un épinglage de la SOURCE (ce qu'on va figer) qui ne retombe sur aucun créneau de la
        // grille de la période : on NOMME le problème plutôt que de le replacer en silence.
        $orphanPin = $this->orphanPinGuard->firstOrphanMessage($source);
        if (null !== $orphanPin) {
            return $this->json(['error' => $orphanPin], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $coachInTwoPlaces = $this->coachInTwoPlacesGuard->firstConflictMessage($source);
        if (null !== $coachInTwoPlaces) {
            return $this->json(['error' => $coachInTwoPlaces], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Nouvelle version PENDING du MÊME plan (V+1), puis dispatch. Pas de copie de créneau : le
        // payload du solve épingle les placements de la source à la volée (handler, mode fill).
        // ADR-0002 inv. 12 : le nom vit sur LE PLAN — la V+1 en porte donc le nom.
        $newSchedule = (new Schedule)
            ->setClubId($source->getClubId())
            ->setSeasonId($source->getSeasonId())
            ->setName($this->schedulePlanProvisioner->versionNameFor($source->getSchedulePlanId()))
            ->setStatus(ScheduleStatus::PENDING)
            ->setQueuedAt(new DateTimeImmutable)
            ->setSchedulePlanId($source->getSchedulePlanId());
        $this->entityManager->wrapInTransaction(function () use ($newSchedule, $entryId, $source): void {
            // Sous le verrou de portée du plan de PÉRIODE (sa clé = l'entrée de calendrier, comme la
            // transcription et la validation) : sans lui, deux comblements concurrents créeraient
            // deux V+1 avec le même numéro. `linkSchedule` numérote sous ce verrou.
            $this->schedulePlanProvisioner->lockPlanScope($entryId);
            // Re-vérification SOUS le verrou (TOCTOU, miroir de /regenerate qui re-vérifie dans
            // lockPlanScope) : un « Valider » concurrent entre le check d'entrée et ce point
            // ferait dériver une V+1 d'une version devenue EN VIGUEUR.
            if ($this->schedulePlanProvisioner->isChosen($source->getId())) {
                throw new ConflictHttpException('La version choisie est le planning en vigueur. Rouvrez-le avant de combler.');
            }
            $this->entityManager->persist($newSchedule);
            $this->schedulePlanProvisioner->linkSchedule($newSchedule);
            $this->entityManager->flush();
        });

        $this->messageBus->dispatch(new GenerateScheduleMessage(
            scheduleId: $newSchedule->getId(),
            clubId: $newSchedule->getClubId(),
            // Mode comblement : la SOURCE dont les placements sont épinglés HARD dans le payload.
            fillSourceScheduleId: $source->getId(),
        ));

        return $this->json(['id' => $newSchedule->getId(), 'status' => ScheduleStatus::PENDING->value], Response::HTTP_ACCEPTED);
    }
}
