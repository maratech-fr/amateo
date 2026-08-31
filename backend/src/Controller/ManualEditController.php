<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Schedule;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\SharedTrainingBlock;
use App\Entity\Team;
use App\Enum\LockLevel;
use App\Exception\DurationMismatchException;
use App\Exception\EngineTimeoutException;
use App\Exception\EvictTargetLockedException;
use App\Exception\EvictTargetMismatchException;
use App\Exception\ScheduleGenerationInProgressException;
use App\Exception\SlotUnavailableException;
use App\Service\ManagementAccessGuard;
use App\Service\ManualEditService;
use App\Service\MoveSlotService;
use App\Service\SchedulePlanProvisioner;
use App\Service\WriteTargetSeasonResolver;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Throwable;

final class ManualEditController extends AbstractController implements SeasonScopedWriteInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ManualEditService $manualEditService,
        private readonly ManagementAccessGuard $managementAccessGuard,
        private readonly LoggerInterface $logger,
        private readonly SchedulePlanProvisioner $schedulePlanProvisioner,
        private readonly MoveSlotService $moveSlotService,
        private readonly WriteTargetSeasonResolver $writeTargetSeasonResolver,
    ) {}

    /**
     * SEC-13 — la cible dépend du geste : place-slot vise un Schedule (id = schedule),
     * lock/move visent un créneau (id = schedule-slot), move-group vise un Schedule dont
     * l'id vit dans le CORPS (route sans `{id}`). On branche sur le nom de route.
     */
    public function writeTargetSeasonId(Request $request): ?string
    {
        if ('api_schedule_slot_move_group' === $request->attributes->get('_route')) {
            $data = json_decode($request->getContent(), true);
            $scheduleId = \is_array($data) && isset($data['scheduleId']) && \is_string($data['scheduleId'])
                ? $data['scheduleId']
                : null;

            return null === $scheduleId ? null : $this->writeTargetSeasonResolver->ofSchedule($scheduleId);
        }

        $id = $request->attributes->get('id');
        if (!\is_string($id)) {
            return null;
        }

        return 'api_schedule_place_slot' === $request->attributes->get('_route')
            ? $this->writeTargetSeasonResolver->ofSchedule($id)
            : $this->writeTargetSeasonResolver->ofScheduleSlot($id);
    }

    #[Route('/api/schedule-slots/{id}/manual-edit/lock', name: 'api_manual_edit_lock', methods: ['POST'])]
    public function applyLock(string $id, Request $request): JsonResponse
    {
        $this->managementAccessGuard->assertManager(); // SEC-07
        $slot = $this->findSlot($id);

        if (!$slot instanceof ScheduleSlotTemplate) {
            return $this->json(['error' => 'Ce créneau n\'existe plus — rechargez le planning.'], Response::HTTP_NOT_FOUND);
        }

        if ($this->scheduleIsLocked($slot)) {
            return $this->json(['error' => 'Ce planning est validé (lecture seule) — rouvrez-le avant de le modifier.'], Response::HTTP_CONFLICT);
        }

        $data = json_decode($request->getContent(), true);

        if (!\is_array($data)) {
            return $this->json(['error' => 'Invalid JSON body.'], Response::HTTP_BAD_REQUEST);
        }

        $lockLevelValue = (string) ($data['lockLevel'] ?? '');

        if ('' === $lockLevelValue) {
            return $this->json(['error' => 'Missing required field: lockLevel.'], Response::HTTP_BAD_REQUEST);
        }

        $lockLevel = LockLevel::tryFrom($lockLevelValue);

        if (null === $lockLevel) {
            return $this->json(['error' => 'Invalid lockLevel.'], Response::HTTP_BAD_REQUEST);
        }

        // ENG-21: SOFT is a placebo — the engine never reads the soft-lock penalty, so a
        // SOFT lock has zero effect on placement. Reject it rather than accept a lock we
        // silently ignore ("déclaré ≠ effectif"). Only NONE/HARD are honored.
        if (LockLevel::SOFT === $lockLevel) {
            return $this->json(['error' => 'SOFT lock is not supported (no solver effect); use NONE or HARD.'], Response::HTTP_BAD_REQUEST);
        }

        $this->manualEditService->applyLock($slot, $lockLevel);

        return $this->json(['message' => 'Lock applied.'], Response::HTTP_OK);
    }

    /**
     * Déplacer un créneau (jour / heure / gymnase) SOUS LE VERDICT DU MOTEUR (F2b).
     *
     * Seul rail de déplacement : le geste ne s'écrit QUE si le moteur l'accepte ;
     * sinon les règles violées reviennent nommées et le planning ne bouge pas.
     */
    #[Route('/api/schedule-slots/{id}/move', name: 'api_schedule_slot_move', methods: ['POST'])]
    public function move(string $id, Request $request): JsonResponse
    {
        $this->managementAccessGuard->assertManager(); // SEC-07
        $slot = $this->findSlot($id);

        if (!$slot instanceof ScheduleSlotTemplate) {
            return $this->json(['error' => 'Ce créneau n\'existe plus — rechargez le planning.'], Response::HTTP_NOT_FOUND);
        }

        if ($this->scheduleIsLocked($slot)) {
            return $this->json(['error' => 'Ce planning est validé (lecture seule) — rouvrez-le avant de le modifier.'], Response::HTTP_CONFLICT);
        }

        $data = json_decode($request->getContent(), true);

        if (!\is_array($data)) {
            return $this->json(['error' => 'Invalid JSON body.'], Response::HTTP_BAD_REQUEST);
        }

        $dayOfWeek = isset($data['dayOfWeek']) ? (int) $data['dayOfWeek'] : 0;
        if ($dayOfWeek < 1 || $dayOfWeek > 7) {
            return $this->json(['error' => 'Missing or invalid field: dayOfWeek.'], Response::HTTP_BAD_REQUEST);
        }

        $venueId = isset($data['venueId']) && \is_string($data['venueId']) ? $data['venueId'] : '';
        if ('' === $venueId) {
            return $this->json(['error' => 'Missing required field: venueId.'], Response::HTTP_BAD_REQUEST);
        }

        $startTime = null;
        if (isset($data['startTime']) && \is_string($data['startTime'])) {
            $startTime = DateTimeImmutable::createFromFormat('!H:i', $data['startTime'])
                ?: DateTimeImmutable::createFromFormat('!H:i:s', $data['startTime'])
                ?: null;
        }
        if (!$startTime instanceof DateTimeImmutable) {
            return $this->json(['error' => 'Missing or invalid field: startTime.'], Response::HTTP_BAD_REQUEST);
        }

        // Éviction OPTIONNELLE : déplacer vers une cible occupée peut demander d'en retirer
        // l'occupant. Le service valide la cible AVANT le moteur (D3 : verrou souverain).
        $evictSlotId = isset($data['evictSlotId']) && \is_string($data['evictSlotId']) && '' !== $data['evictSlotId']
            ? $data['evictSlotId']
            : null;

        // P2-32 — `dryRun` : un ESSAI. Même chemin jusqu'au verdict (gardes pré-moteur comprises),
        // puis retour SANS rien écrire. La réponse porte le verdict et ses compromis nommés.
        $dryRun = isset($data['dryRun']) && true === $data['dryRun'];

        try {
            $result = $this->moveSlotService->move($slot, $dayOfWeek, $startTime, $venueId, $evictSlotId, $dryRun);
        } catch (ScheduleGenerationInProgressException) {
            // Déplacer pendant qu'une génération réécrit le planning écraserait son résultat.
            return $this->json(['code' => 'generation_in_progress'], Response::HTTP_CONFLICT);
        } catch (SlotUnavailableException) {
            // Aucune fenêtre de gymnase à la destination (cible inexistante ou jour fermé) : le
            // créneau n'existe pas, rien à faire juger, rien écrit. Miroir exact de placeSlot.
            return $this->json(['code' => 'slot_unavailable', 'error' => 'Aucun créneau de gymnase n\'est ouvert à cet horaire.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (EvictTargetMismatchException) {
            // La cible d'éviction est incohérente (introuvable, autre planning, ne siège pas là) :
            // rien n'est écrit, le moteur n'est pas appelé.
            return $this->json(['code' => 'evict_target_mismatch', 'error' => 'Le créneau à libérer ne correspond pas à la cible du déplacement.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (EvictTargetLockedException) {
            // D3 : un verrou est souverain — on ne libère pas un créneau verrouillé.
            return $this->json(['code' => 'target_locked', 'error' => 'Ce créneau est verrouillé — déverrouillez-le d\'abord.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (EngineTimeoutException $e) {
            // Le moteur a été TROP LENT (l'amont a répondu trop tard) : 504, PAS 502 — rien n'est
            // écrit. Le code NOMME la cause pour que le front l'affiche et propose de réessayer.
            $this->logger->error('Move validation timed out on the engine.', ['exception' => $e]);

            return $this->json(['code' => EngineTimeoutException::CODE, 'error' => 'The engine did not answer in time — please retry.'], Response::HTTP_GATEWAY_TIMEOUT);
        } catch (TransportExceptionInterface $e) {
            // Le moteur est injoignable/cassé — RIEN n'est écrit, le gestionnaire réessaie.
            $this->logger->error('Move validation could not reach the engine.', ['exception' => $e]);

            return $this->json(['error' => 'The engine did not respond — please retry.'], Response::HTTP_BAD_GATEWAY);
        } catch (Throwable $e) {
            // SEC-08 : on journalise le détail, jamais getMessage() au client.
            $this->logger->error('Slot move failed.', ['exception' => $e]);

            return $this->json(['error' => 'The request could not be processed.'], Response::HTTP_BAD_REQUEST);
        }

        if (isset($result['dryRun'])) {
            // Un essai : 200, le verdict complet (valid + règles violées + compromis nommés),
            // AUCUNE écriture. Le front (PR B) l'affiche avant de confirmer le geste.
            $body = [
                'valid' => $result['valid'],
                'dryRun' => true,
                'violations' => $result['violations'],
                'compromises' => $result['compromises'],
            ];
            if (isset($result['evicted'])) {
                // L'état qui SERAIT évincé (sans suppression).
                $body['evicted'] = $result['evicted'];
            }

            return $this->json($body, Response::HTTP_OK);
        }

        if (false === $result['valid']) {
            // Le moteur refuse : 422 + les règles violées, nommées pour l'UI.
            return $this->json(['valid' => false, 'violations' => $result['violations']], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $body = ['message' => 'Slot moved.', 'valid' => true, 'compromises' => $result['compromises']];
        if (isset($result['evicted'])) {
            // État de l'occupant AVANT sa suppression — le front s'en sert pour proposer un replacement.
            $body['evicted'] = $result['evicted'];
        }

        return $this->json($body, Response::HTTP_OK);
    }

    /**
     * PLACER une séance À LA DÉRIVE — créer une séance qui n'existait pas (surnuméraire ou
     * rattrapage) SOUS LE VERDICT DU MOTEUR (P2-30). Comme /move : géré management, refus 409 si
     * une génération tourne ou si la version est choisie (lecture seule), 422 si le moteur refuse.
     */
    #[Route('/api/schedules/{id}/place-slot', name: 'api_schedule_place_slot', methods: ['POST'])]
    public function placeSlot(string $id, Request $request): JsonResponse
    {
        $this->managementAccessGuard->assertManager(); // SEC-07

        $schedule = $this->findSchedule($id);
        if (!$schedule instanceof Schedule) {
            return $this->json(['error' => 'Planning introuvable.'], Response::HTTP_NOT_FOUND);
        }

        // ADR-0002 inv. 1 : la version choisie du plan est le calendrier — lecture seule.
        if ($this->schedulePlanProvisioner->isChosen($schedule->getId())) {
            return $this->json(['error' => 'Ce planning est validé (lecture seule) — rouvrez-le avant de le modifier.'], Response::HTTP_CONFLICT);
        }

        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            return $this->json(['error' => 'Invalid JSON body.'], Response::HTTP_BAD_REQUEST);
        }

        $teamId = isset($data['teamId']) && \is_string($data['teamId']) ? $data['teamId'] : '';
        if ('' === $teamId) {
            return $this->json(['error' => 'Missing required field: teamId.'], Response::HTTP_BAD_REQUEST);
        }

        $dayOfWeek = isset($data['dayOfWeek']) ? (int) $data['dayOfWeek'] : 0;
        if ($dayOfWeek < 1 || $dayOfWeek > 7) {
            return $this->json(['error' => 'Missing or invalid field: dayOfWeek.'], Response::HTTP_BAD_REQUEST);
        }

        $venueId = isset($data['venueId']) && \is_string($data['venueId']) ? $data['venueId'] : '';
        if ('' === $venueId) {
            return $this->json(['error' => 'Missing required field: venueId.'], Response::HTTP_BAD_REQUEST);
        }

        $startTime = null;
        if (isset($data['startTime']) && \is_string($data['startTime'])) {
            $startTime = DateTimeImmutable::createFromFormat('!H:i', $data['startTime'])
                ?: DateTimeImmutable::createFromFormat('!H:i:s', $data['startTime'])
                ?: null;
        }
        if (!$startTime instanceof DateTimeImmutable) {
            return $this->json(['error' => 'Missing or invalid field: startTime.'], Response::HTTP_BAD_REQUEST);
        }

        // durationMinutes est OPTIONNEL : la durée fait foi CÔTÉ SERVEUR (fenêtre de gymnase), ce
        // champ n'est qu'une assertion du client. Fourni → il doit être un entier > 0 (sinon 400) ;
        // absent → la fenêtre décide ; menteur → 422 duration_mismatch (tranché dans le service).
        $durationMinutes = null;
        if (isset($data['durationMinutes'])) {
            $durationMinutes = (int) $data['durationMinutes'];
            if ($durationMinutes <= 0) {
                return $this->json(['error' => 'Invalid field: durationMinutes.'], Response::HTTP_BAD_REQUEST);
            }
        }

        // L'équipe doit appartenir au club ET à la saison du planning (sinon 422). Le filtre
        // tenant rend déjà invisible l'équipe d'un autre club ; on borne aussi la saison. Un
        // teamId malformé (non-UUID) ne doit pas remonter en 500 driver → try/catch, comme findSlot.
        $team = $this->findTeamInSchedule($teamId, $schedule);
        if (!$team instanceof Team) {
            return $this->json(['error' => 'Équipe inconnue pour ce planning.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // P2-32 — `dryRun` : essai sans écriture (même chemin jusqu'au verdict inclus).
        $dryRun = isset($data['dryRun']) && true === $data['dryRun'];

        try {
            $result = $this->moveSlotService->place($schedule, $teamId, $dayOfWeek, $startTime, $venueId, $durationMinutes, $dryRun);
        } catch (ScheduleGenerationInProgressException) {
            return $this->json(['code' => 'generation_in_progress'], Response::HTTP_CONFLICT);
        } catch (SlotUnavailableException) {
            // Aucune fenêtre de gymnase à ce créneau : rien à créer, rien écrit.
            return $this->json(['code' => 'slot_unavailable', 'error' => 'Aucun créneau de gymnase n\'est ouvert à cet horaire.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (DurationMismatchException) {
            // La durée annoncée contredit la fenêtre : c'est la fenêtre qui fait foi.
            return $this->json(['code' => 'duration_mismatch', 'error' => 'La durée indiquée ne correspond pas au créneau de gymnase.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (EngineTimeoutException $e) {
            // Trop lent (504, pas 502) : rien créé, le code nomme la cause pour le front.
            $this->logger->error('Placement validation timed out on the engine.', ['exception' => $e]);

            return $this->json(['code' => EngineTimeoutException::CODE, 'error' => 'The engine did not answer in time — please retry.'], Response::HTTP_GATEWAY_TIMEOUT);
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Placement validation could not reach the engine.', ['exception' => $e]);

            return $this->json(['error' => 'The engine did not respond — please retry.'], Response::HTTP_BAD_GATEWAY);
        } catch (Throwable $e) {
            $this->logger->error('Slot placement failed.', ['exception' => $e]);

            return $this->json(['error' => 'The request could not be processed.'], Response::HTTP_BAD_REQUEST);
        }

        if (isset($result['dryRun'])) {
            // Essai : 200, verdict complet, AUCUNE ligne créée.
            return $this->json([
                'valid' => $result['valid'],
                'dryRun' => true,
                'violations' => $result['violations'],
                'compromises' => $result['compromises'],
            ], Response::HTTP_OK);
        }

        if (false === $result['valid']) {
            return $this->json(['valid' => false, 'violations' => $result['violations']], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(['valid' => true, 'slotId' => $result['slotId'] ?? null, 'compromises' => $result['compromises']], Response::HTTP_OK);
    }

    /**
     * DÉPLACER UNE SÉANCE DE BLOC ENTIÈRE (P2-51 D11) — les N créneaux des membres du bloc qui
     * siègent à la case SOURCE, d'un coup vers la case CIBLE, SOUS UN SEUL VERDICT et en une
     * transaction (tout-ou-rien). D11 interdit de déplacer un membre seul ; ce rail atomique est le
     * seul geste « déplacer le bloc ». Le serveur résout lui-même les créneaux (jamais des slotIds
     * clients) : le corps ne porte que le bloc, la case source et la case cible.
     */
    #[Route('/api/schedule-slots/move-group', name: 'api_schedule_slot_move_group', methods: ['POST'])]
    public function moveGroup(Request $request): JsonResponse
    {
        $this->managementAccessGuard->assertManager(); // SEC-07

        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            return $this->json(['error' => 'Invalid JSON body.'], Response::HTTP_BAD_REQUEST);
        }

        $scheduleId = isset($data['scheduleId']) && \is_string($data['scheduleId']) ? $data['scheduleId'] : '';
        if ('' === $scheduleId) {
            return $this->json(['error' => 'Missing required field: scheduleId.'], Response::HTTP_BAD_REQUEST);
        }

        $schedule = $this->findSchedule($scheduleId);
        if (!$schedule instanceof Schedule) {
            return $this->json(['error' => 'Planning introuvable.'], Response::HTTP_NOT_FOUND);
        }

        // ADR-0002 inv. 1 : la version choisie du plan est le calendrier — lecture seule.
        if ($this->schedulePlanProvisioner->isChosen($schedule->getId())) {
            return $this->json(['error' => 'Ce planning est validé (lecture seule) — rouvrez-le avant de le modifier.'], Response::HTTP_CONFLICT);
        }

        $blockId = isset($data['blockId']) && \is_string($data['blockId']) ? $data['blockId'] : '';
        if ('' === $blockId) {
            return $this->json(['error' => 'Missing required field: blockId.'], Response::HTTP_BAD_REQUEST);
        }
        $block = $this->findBlock($blockId);
        if (!$block instanceof SharedTrainingBlock) {
            return $this->json(['error' => 'Bloc de mutualisation introuvable.'], Response::HTTP_NOT_FOUND);
        }

        // La case SOURCE (la séance à déplacer) et la case CIBLE, chacune {venueId, dayOfWeek, startTime}.
        $source = $this->parseCase(\is_array($data['source'] ?? null) ? $data['source'] : []);
        if (null === $source) {
            return $this->json(['error' => 'Missing or invalid field: source (venueId, dayOfWeek, startTime).'], Response::HTTP_BAD_REQUEST);
        }
        $target = $this->parseCase(\is_array($data['target'] ?? null) ? $data['target'] : []);
        if (null === $target) {
            return $this->json(['error' => 'Missing or invalid field: target (venueId, dayOfWeek, startTime).'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $result = $this->moveSlotService->moveGroup(
                $schedule,
                $block,
                $source['dayOfWeek'],
                $source['startTime'],
                $source['venueId'],
                $target['dayOfWeek'],
                $target['startTime'],
                $target['venueId'],
            );
        } catch (ScheduleGenerationInProgressException) {
            return $this->json(['code' => 'generation_in_progress'], Response::HTTP_CONFLICT);
        } catch (SlotUnavailableException) {
            return $this->json(['code' => 'slot_unavailable', 'error' => 'Aucune séance de bloc à déplacer à cet horaire, ou créneau cible fermé.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (EngineTimeoutException $e) {
            $this->logger->error('Group move validation timed out on the engine.', ['exception' => $e]);

            return $this->json(['code' => EngineTimeoutException::CODE, 'error' => 'The engine did not answer in time — please retry.'], Response::HTTP_GATEWAY_TIMEOUT);
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Group move validation could not reach the engine.', ['exception' => $e]);

            return $this->json(['error' => 'The engine did not respond — please retry.'], Response::HTTP_BAD_GATEWAY);
        } catch (Throwable $e) {
            $this->logger->error('Group slot move failed.', ['exception' => $e]);

            return $this->json(['error' => 'The request could not be processed.'], Response::HTTP_BAD_REQUEST);
        }

        if (false === $result['valid']) {
            // Le moteur refuse : 422 + les règles violées — AUCUN des N créneaux n'a bougé (atomicité).
            return $this->json(['valid' => false, 'violations' => $result['violations']], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json([
            'message' => 'Group slot moved.',
            'valid' => true,
            'compromises' => $result['compromises'],
            'movedSlotIds' => $result['movedSlotIds'] ?? [],
        ], Response::HTTP_OK);
    }

    /**
     * Une case {venueId, dayOfWeek 1..7, startTime "H:i"} lue d'un sous-objet du corps, ou null si
     * l'un des trois manque/est invalide.
     *
     * @param array<mixed> $raw
     *
     * @return array{venueId: string, dayOfWeek: int, startTime: DateTimeImmutable}|null
     */
    private function parseCase(array $raw): ?array
    {
        $venueId = isset($raw['venueId']) && \is_string($raw['venueId']) && '' !== $raw['venueId'] ? $raw['venueId'] : null;
        $dayOfWeek = isset($raw['dayOfWeek']) ? (int) $raw['dayOfWeek'] : 0;
        $startTime = null;
        if (isset($raw['startTime']) && \is_string($raw['startTime'])) {
            $startTime = DateTimeImmutable::createFromFormat('!H:i', $raw['startTime'])
                ?: DateTimeImmutable::createFromFormat('!H:i:s', $raw['startTime'])
                ?: null;
        }

        if (null === $venueId || $dayOfWeek < 1 || $dayOfWeek > 7 || !$startTime instanceof DateTimeImmutable) {
            return null;
        }

        return ['venueId' => $venueId, 'dayOfWeek' => $dayOfWeek, 'startTime' => $startTime];
    }

    /** Le bloc ciblé, tenant-scopé par le filtre Doctrine : un bloc d'un autre club est invisible (→ 404). */
    private function findBlock(string $id): ?SharedTrainingBlock
    {
        try {
            $block = $this->entityManager->getRepository(SharedTrainingBlock::class)->findOneBy(['id' => $id]);
        } catch (Throwable) {
            $block = null;
        }

        return $block instanceof SharedTrainingBlock ? $block : null;
    }

    private function findSlot(string $id): ?ScheduleSlotTemplate
    {
        try {
            $slot = $this->entityManager->getRepository(ScheduleSlotTemplate::class)->find($id);
        } catch (Throwable) {
            $slot = null;
        }

        return $slot instanceof ScheduleSlotTemplate ? $slot : null;
    }

    /** Le planning ciblé, tenant-scopé par le filtre Doctrine : un planning d'un autre club est invisible (→ 404). */
    private function findSchedule(string $id): ?Schedule
    {
        try {
            $schedule = $this->entityManager->getRepository(Schedule::class)->find($id);
        } catch (Throwable) {
            $schedule = null;
        }

        return $schedule instanceof Schedule ? $schedule : null;
    }

    /**
     * L'équipe, si elle appartient au club ET à la saison du planning. Tenant-scopée par le filtre
     * Doctrine (équipe d'un autre club invisible) ; un id malformé rend null (jamais un 500 driver).
     */
    private function findTeamInSchedule(string $teamId, Schedule $schedule): ?Team
    {
        try {
            $team = $this->entityManager->getRepository(Team::class)->findOneBy([
                'id' => $teamId, 'clubId' => $schedule->getClubId(), 'seasonId' => $schedule->getSeasonId(),
            ]);
        } catch (Throwable) {
            $team = null;
        }

        return $team instanceof Team ? $team : null;
    }

    /** A slot whose parent schedule is VALIDATED is read-only. */
    private function scheduleIsLocked(ScheduleSlotTemplate $slot): bool
    {
        $schedule = $this->entityManager->getRepository(Schedule::class)->find($slot->getScheduleId());

        // ADR-0002 inv. 1 : « verrouillé » = c'est la version choisie du plan.
        return $schedule instanceof Schedule && $this->schedulePlanProvisioner->isChosen($schedule->getId());
    }
}
