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
use App\Exception\EvictTargetIsGroupException;
use App\Exception\EvictTargetLockedException;
use App\Exception\EvictTargetMismatchException;
use App\Exception\MutualizationRefusedException;
use App\Exception\ScheduleGenerationInProgressException;
use App\Exception\SlotUnavailableException;
use App\Exception\SwapCrossScheduleException;
use App\Exception\SwapWithSelfException;
use App\Exception\TeamAlreadyAtCaseException;
use App\Service\ManagementAccessGuard;
use App\Service\ManualEditService;
use App\Service\MoveSlotService;
use App\Service\MutualizeSlotService;
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
        private readonly MutualizeSlotService $mutualizeSlotService,
        private readonly WriteTargetSeasonResolver $writeTargetSeasonResolver,
    ) {}

    /**
     * SEC-13 — la cible dépend du geste : place-slot vise un Schedule (id = schedule),
     * lock/move/remove visent un créneau (id = schedule-slot), move-group/remove-group visent un
     * Schedule dont l'id vit dans le CORPS (route sans `{id}`), swap vise deux créneaux dont le
     * premier (`slotId`) vit dans le CORPS. On branche sur le nom de route.
     */
    public function writeTargetSeasonId(Request $request): ?string
    {
        if (\in_array($request->attributes->get('_route'), ['api_schedule_slot_move_group', 'api_schedule_slot_remove_group'], true)) {
            $data = json_decode($request->getContent(), true);
            $scheduleId = \is_array($data) && isset($data['scheduleId']) && \is_string($data['scheduleId'])
                ? $data['scheduleId']
                : null;

            return null === $scheduleId ? null : $this->writeTargetSeasonResolver->ofSchedule($scheduleId);
        }

        if ('api_schedule_slot_swap' === $request->attributes->get('_route')) {
            $data = json_decode($request->getContent(), true);
            $slotId = \is_array($data) && isset($data['slotId']) && \is_string($data['slotId'])
                ? $data['slotId']
                : null;

            return null === $slotId ? null : $this->writeTargetSeasonResolver->ofScheduleSlot($slotId);
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
        } catch (TeamAlreadyAtCaseException $e) {
            // Règle fondateur : l'équipe siège déjà à la cible et aucune éviction n'a été demandée —
            // 422 NOMMÉ (message humain, noms réels), rien n'est écrit. Le moteur n'a pas été appelé.
            return $this->json(['code' => 'team_already_at_case', 'error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
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
            if (true === ($result['indeterminate'] ?? false)) {
                // ENG-51 — le solveur n'a pas tranché dans le temps imparti : verdict NEUTRE.
                $body['indeterminate'] = true;
            }
            if (isset($result['evicted'])) {
                // L'état qui SERAIT évincé (sans suppression).
                $body['evicted'] = $result['evicted'];
            }

            return $this->json($body, Response::HTTP_OK);
        }

        if (true === ($result['indeterminate'] ?? false)) {
            // ENG-51 — le solveur n'a PAS tranché dans le temps imparti (ni oui ni non) : 200 +
            // drapeau NEUTRE, aucune écriture. Ce n'est pas un refus (422) : l'UI invite à réessayer.
            return $this->json(['valid' => false, 'indeterminate' => true], Response::HTTP_OK);
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
        } catch (TeamAlreadyAtCaseException $e) {
            // Règle fondateur : l'équipe siège déjà à cette case — 422 NOMMÉ, rien n'est créé.
            return $this->json(['code' => 'team_already_at_case', 'error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
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
            $body = [
                'valid' => $result['valid'],
                'dryRun' => true,
                'violations' => $result['violations'],
                'compromises' => $result['compromises'],
            ];
            if (true === ($result['indeterminate'] ?? false)) {
                // ENG-51 — verdict NEUTRE : le solveur n'a pas tranché dans le temps imparti.
                $body['indeterminate'] = true;
            }

            return $this->json($body, Response::HTTP_OK);
        }

        if (true === ($result['indeterminate'] ?? false)) {
            // ENG-51 — ni oui ni non : 200 + drapeau NEUTRE, rien créé ; l'UI invite à réessayer.
            return $this->json(['valid' => false, 'indeterminate' => true], Response::HTTP_OK);
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

        // Éviction OPTIONNELLE (P4-329) : déposer le groupe sur une case occupée peut demander d'en
        // retirer l'occupant (séance d'équipe non verrouillée). Le service valide la cible AVANT le
        // moteur (D3 : verrou souverain ; une séance de groupe occupante est refusée nommément).
        $evictSlotId = isset($data['evictSlotId']) && \is_string($data['evictSlotId']) && '' !== $data['evictSlotId']
            ? $data['evictSlotId']
            : null;

        // P2-32 — `dryRun` : un ESSAI. Même chemin jusqu'au verdict (gardes pré-moteur comprises),
        // puis retour SANS rien écrire. La réponse porte le verdict, ses compromis et l'état évincé.
        $dryRun = isset($data['dryRun']) && true === $data['dryRun'];

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
                $evictSlotId,
                $dryRun,
            );
        } catch (ScheduleGenerationInProgressException) {
            return $this->json(['code' => 'generation_in_progress'], Response::HTTP_CONFLICT);
        } catch (SlotUnavailableException) {
            return $this->json(['code' => 'slot_unavailable', 'error' => 'Aucune séance de bloc à déplacer à cet horaire, ou créneau cible fermé.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (TeamAlreadyAtCaseException $e) {
            // Règle fondateur : un membre déplacé siège déjà à la case cible par une AUTRE séance que
            // l'éviction ne libère pas — 422 NOMMÉ (consigne « déplacez-la ou retirez-la d'abord »),
            // aucun des N créneaux ne bouge.
            return $this->json(['code' => 'team_already_at_case', 'error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (EvictTargetMismatchException) {
            // La cible d'éviction est incohérente (introuvable, autre planning, une source du groupe,
            // ne siège pas là) : rien n'est écrit, le moteur n'est pas appelé.
            return $this->json(['code' => 'evict_target_mismatch', 'error' => 'Le créneau à libérer ne correspond pas à la cible du déplacement.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (EvictTargetIsGroupException) {
            // L'occupant à évincer est lui-même une séance de GROUPE : l'échange de deux groupes
            // arrive dans un lot ultérieur — ici on refuse NOMMÉMENT, rien n'est écrit.
            return $this->json(['code' => 'evict_target_is_group', 'error' => 'Cette case est occupée par un autre groupe — videz-la d\'abord.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (EvictTargetLockedException) {
            // D3 : un verrou est souverain — on ne libère pas un créneau verrouillé.
            return $this->json(['code' => 'target_locked', 'error' => 'Ce créneau est verrouillé — déverrouillez-le d\'abord.'], Response::HTTP_UNPROCESSABLE_ENTITY);
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

        if (isset($result['dryRun'])) {
            // Un essai : 200, le verdict complet (valid + règles violées + compromis), AUCUNE
            // écriture. Le front l'affiche avant de confirmer l'éviction (EvictConfirmDialog).
            $body = [
                'valid' => $result['valid'],
                'dryRun' => true,
                'violations' => $result['violations'],
                'compromises' => $result['compromises'],
            ];
            if (true === ($result['indeterminate'] ?? false)) {
                $body['indeterminate'] = true;
            }
            if (isset($result['evicted'])) {
                // L'état qui SERAIT évincé (sans suppression).
                $body['evicted'] = $result['evicted'];
            }

            return $this->json($body, Response::HTTP_OK);
        }

        if (true === ($result['indeterminate'] ?? false)) {
            // ENG-51 — le solveur n'a pas tranché dans le temps imparti : 200 + drapeau NEUTRE,
            // AUCUN des N créneaux n'a bougé ; l'UI invite à réessayer.
            return $this->json(['valid' => false, 'indeterminate' => true], Response::HTTP_OK);
        }

        if (false === $result['valid']) {
            // Le moteur refuse : 422 + les règles violées — AUCUN des N créneaux n'a bougé (atomicité).
            return $this->json(['valid' => false, 'violations' => $result['violations']], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $body = [
            'message' => 'Group slot moved.',
            'valid' => true,
            'compromises' => $result['compromises'],
            'movedSlotIds' => $result['movedSlotIds'] ?? [],
        ];
        if (isset($result['evicted'])) {
            // État de l'occupant AVANT sa suppression — le front propose de le replacer (« à replacer »).
            $body['evicted'] = $result['evicted'];
        }

        return $this->json($body, Response::HTTP_OK);
    }

    /**
     * ÉCHANGER DEUX SÉANCES ATOMIQUEMENT (P4-327) — chaque créneau prend la case de l'autre, SOUS UN
     * SEUL VERDICT et en une transaction (tout-ou-rien). Pendant « sans perte » de l'éviction : rien
     * n'est supprimé, les deux côtés restent placés. Le corps ne porte que `{slotId, otherSlotId,
     * dryRun?}` — les positions sont résolues CÔTÉ SERVEUR (la case réelle de chaque créneau). La
     * source (`slotId`) peut être une séance d'équipe OU de groupe ; l'occupant (`otherSlotId`) doit
     * être une séance d'équipe non verrouillée (occupant de groupe refusé, lot ultérieur).
     */
    #[Route('/api/schedule-slots/swap', name: 'api_schedule_slot_swap', methods: ['POST'])]
    public function swap(Request $request): JsonResponse
    {
        $this->managementAccessGuard->assertManager(); // SEC-07

        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            return $this->json(['error' => 'Invalid JSON body.'], Response::HTTP_BAD_REQUEST);
        }

        $slotId = isset($data['slotId']) && \is_string($data['slotId']) ? $data['slotId'] : '';
        if ('' === $slotId) {
            return $this->json(['error' => 'Missing required field: slotId.'], Response::HTTP_BAD_REQUEST);
        }
        $otherSlotId = isset($data['otherSlotId']) && \is_string($data['otherSlotId']) ? $data['otherSlotId'] : '';
        if ('' === $otherSlotId) {
            return $this->json(['error' => 'Missing required field: otherSlotId.'], Response::HTTP_BAD_REQUEST);
        }

        $source = $this->findSlot($slotId);
        if (!$source instanceof ScheduleSlotTemplate) {
            return $this->json(['error' => 'Ce créneau n\'existe plus — rechargez le planning.'], Response::HTTP_NOT_FOUND);
        }
        $target = $this->findSlot($otherSlotId);
        if (!$target instanceof ScheduleSlotTemplate) {
            return $this->json(['error' => 'Ce créneau n\'existe plus — rechargez le planning.'], Response::HTTP_NOT_FOUND);
        }

        // Lecture seule : la version choisie du plan est le calendrier (ADR-0002 inv. 1).
        if ($this->scheduleIsLocked($source)) {
            return $this->json(['error' => 'Ce planning est validé (lecture seule) — rouvrez-le avant de le modifier.'], Response::HTTP_CONFLICT);
        }

        // P2-32 — `dryRun` : un ESSAI. Même chemin jusqu'au verdict (gardes pré-moteur comprises),
        // puis retour SANS rien écrire.
        $dryRun = isset($data['dryRun']) && true === $data['dryRun'];

        try {
            $result = $this->moveSlotService->swap($source, $target, $dryRun);
        } catch (ScheduleGenerationInProgressException) {
            return $this->json(['code' => 'generation_in_progress'], Response::HTTP_CONFLICT);
        } catch (SwapCrossScheduleException) {
            // Les deux créneaux ne vivent pas dans le même planning : rien écrit, moteur jamais appelé.
            return $this->json(['code' => 'swap_cross_schedule', 'error' => 'Les deux créneaux n\'appartiennent pas au même planning.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (SwapWithSelfException) {
            // Même créneau, ou une équipe commune aux deux côtés : l'échange n'a pas de sens.
            return $this->json(['code' => 'swap_with_self', 'error' => 'On ne peut pas échanger un créneau avec lui-même ou avec un autre créneau de la même équipe.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (EvictTargetIsGroupException) {
            // L'occupant est lui-même une séance de GROUPE : l'échange de deux groupes arrive dans un
            // lot ultérieur — ici on refuse NOMMÉMENT, rien n'est écrit.
            return $this->json(['code' => 'evict_target_is_group', 'error' => 'Cette case est occupée par un autre groupe — l\'échange de deux groupes n\'est pas encore possible.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (EvictTargetLockedException) {
            // D4 : un verrou est souverain — on n'échange pas un créneau verrouillé.
            return $this->json(['code' => 'target_locked', 'error' => 'Ce créneau est verrouillé — déverrouillez-le d\'abord.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (SlotUnavailableException) {
            // Une des deux cases n'a plus de fenêtre de gymnase ouverte : le créneau n'existe pas.
            return $this->json(['code' => 'slot_unavailable', 'error' => 'Aucun créneau de gymnase n\'est ouvert à l\'un des deux horaires.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (TeamAlreadyAtCaseException $e) {
            // Règle fondateur : un côté déplacé siège déjà à sa case d'arrivée par une AUTRE séance —
            // 422 NOMMÉ, rien n'est écrit, le moteur n'a pas été appelé.
            return $this->json(['code' => 'team_already_at_case', 'error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (EngineTimeoutException $e) {
            $this->logger->error('Swap validation timed out on the engine.', ['exception' => $e]);

            return $this->json(['code' => EngineTimeoutException::CODE, 'error' => 'The engine did not answer in time — please retry.'], Response::HTTP_GATEWAY_TIMEOUT);
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Swap validation could not reach the engine.', ['exception' => $e]);

            return $this->json(['error' => 'The engine did not respond — please retry.'], Response::HTTP_BAD_GATEWAY);
        } catch (Throwable $e) {
            $this->logger->error('Slot swap failed.', ['exception' => $e]);

            return $this->json(['error' => 'The request could not be processed.'], Response::HTTP_BAD_REQUEST);
        }

        if (isset($result['dryRun'])) {
            // Un essai : 200, le verdict complet, AUCUNE écriture.
            $body = [
                'valid' => $result['valid'],
                'dryRun' => true,
                'violations' => $result['violations'],
                'compromises' => $result['compromises'],
            ];
            if (true === ($result['indeterminate'] ?? false)) {
                $body['indeterminate'] = true;
            }
            if (isset($result['movedSlotIds'])) {
                $body['movedSlotIds'] = $result['movedSlotIds'];
            }

            return $this->json($body, Response::HTTP_OK);
        }

        if (true === ($result['indeterminate'] ?? false)) {
            // ENG-51 — le solveur n'a pas tranché dans le temps imparti : 200 + drapeau NEUTRE, aucun
            // des deux côtés n'a bougé ; l'UI invite à réessayer.
            return $this->json(['valid' => false, 'indeterminate' => true], Response::HTTP_OK);
        }

        if (false === $result['valid']) {
            // Le moteur refuse : 422 + les règles violées — AUCUN des deux côtés n'a bougé (atomicité).
            return $this->json(['valid' => false, 'violations' => $result['violations']], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json([
            'message' => 'Slots swapped.',
            'valid' => true,
            'compromises' => $result['compromises'],
            'movedSlotIds' => $result['movedSlotIds'] ?? [],
        ], Response::HTTP_OK);
    }

    /**
     * RETIRER UNE SÉANCE PLACÉE (« vider un créneau ») — la ligne du créneau DISPARAÎT, son verrou
     * éventuel part avec elle, la séance passe « non placée » (écart calculé, rien de stocké). Mêmes
     * gardes que lock/move : management (403), 409 si la version est choisie (lecture seule) ou si
     * une génération tourne. AUCUN appel moteur (un retrait n'ouvre pas de conflit, il creuse un
     * trou — signalé par le drift + `unmetPresence`). `dryRun` : appel à blanc du ConfirmDialog.
     */
    #[Route('/api/schedule-slots/{id}/remove', name: 'api_schedule_slot_remove', methods: ['POST'])]
    public function remove(string $id, Request $request): JsonResponse
    {
        $this->managementAccessGuard->assertManager(); // SEC-07
        $slot = $this->findSlot($id);

        if (!$slot instanceof ScheduleSlotTemplate) {
            return $this->json(['error' => 'Ce créneau n\'existe plus — rechargez le planning.'], Response::HTTP_NOT_FOUND);
        }

        if ($this->scheduleIsLocked($slot)) {
            return $this->json(['error' => 'Ce planning est validé (lecture seule) — rouvrez-le avant de le modifier.'], Response::HTTP_CONFLICT);
        }

        // Corps OPTIONNEL : `{}` (retrait réel) ou `{dryRun:true}` (appel à blanc). Le retrait n'a
        // aucun champ obligatoire — un corps absent/mal typé vaut un retrait réel (dryRun = false).
        $data = json_decode($request->getContent(), true);
        $dryRun = \is_array($data) && isset($data['dryRun']) && true === $data['dryRun'];

        try {
            $result = $this->moveSlotService->remove($slot, $dryRun);
        } catch (ScheduleGenerationInProgressException) {
            // Retirer pendant qu'une génération réécrit le planning écraserait son résultat.
            return $this->json(['code' => 'generation_in_progress'], Response::HTTP_CONFLICT);
        } catch (Throwable $e) {
            // SEC-08 : on journalise le détail, jamais getMessage() au client.
            $this->logger->error('Slot removal failed.', ['exception' => $e]);

            return $this->json(['error' => 'The request could not be processed.'], Response::HTTP_BAD_REQUEST);
        }

        return $this->json($result, Response::HTTP_OK);
    }

    /**
     * RETIRER UNE SÉANCE DE GROUPE ENTIÈRE (« vider le créneau du groupe ») — c'est le groupe qui
     * occupe la case, donc tout-ou-rien : les N créneaux des membres du bloc à la case source sont
     * retirés ensemble, en UNE transaction. Le bloc de mutualisation SURVIT. Le serveur résout les
     * créneaux membres depuis la case source (jamais des slotIds clients), patron move-group.
     */
    #[Route('/api/schedule-slots/remove-group', name: 'api_schedule_slot_remove_group', methods: ['POST'])]
    public function removeGroup(Request $request): JsonResponse
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

        // La case SOURCE (la séance de groupe à retirer) : {venueId, dayOfWeek, startTime}.
        $source = $this->parseCase(\is_array($data['source'] ?? null) ? $data['source'] : []);
        if (null === $source) {
            return $this->json(['error' => 'Missing or invalid field: source (venueId, dayOfWeek, startTime).'], Response::HTTP_BAD_REQUEST);
        }

        $dryRun = isset($data['dryRun']) && true === $data['dryRun'];

        try {
            $result = $this->moveSlotService->removeGroup($schedule, $block, $source['dayOfWeek'], $source['startTime'], $source['venueId'], $dryRun);
        } catch (ScheduleGenerationInProgressException) {
            return $this->json(['code' => 'generation_in_progress'], Response::HTTP_CONFLICT);
        } catch (SlotUnavailableException) {
            // Aucun membre du bloc à cette case : il n'y a pas de séance de groupe à retirer ici.
            return $this->json(['code' => 'slot_unavailable', 'error' => 'Aucune séance de groupe à retirer à cet horaire.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (Throwable $e) {
            $this->logger->error('Group slot removal failed.', ['exception' => $e]);

            return $this->json(['error' => 'The request could not be processed.'], Response::HTTP_BAD_REQUEST);
        }

        return $this->json($result, Response::HTTP_OK);
    }

    /**
     * MUTUALISER DEPUIS LA GÉNÉRATION EN GARDANT LE CRÉNEAU (lot 9) — depuis la fiche d'une séance
     * (la case d'ancrage, `{id}`), déclarer un bloc de mutualisation ANCRÉ à cette case : un
     * ensemble d'équipes s'entraînant ensemble là, une séance commune, sans quitter le créneau.
     * Retouche EN PLACE d'un plan de PÉRIODE, SANS verdict moteur (l'ancrage = séances co-localisées
     * HARD/MANUAL que le solveur comprend déjà). Management (403), 409 si la version est choisie
     * (lecture seule) ou si une génération tourne, 422 pour chaque refus NOMMÉ.
     */
    #[Route('/api/schedule-slots/{id}/mutualize', name: 'api_schedule_slot_mutualize', methods: ['POST'])]
    public function mutualize(string $id, Request $request): JsonResponse
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

        $teamIds = $this->stringList($data['teamIds'] ?? null);
        if (null === $teamIds || [] === $teamIds) {
            return $this->json(['error' => 'Missing or invalid field: teamIds.'], Response::HTTP_BAD_REQUEST);
        }

        $label = null;
        if (isset($data['label']) && \is_string($data['label'])) {
            $label = $data['label'];
            if (mb_strlen($label) > 40) {
                return $this->json(['error' => 'Invalid field: label (40 characters max).'], Response::HTTP_BAD_REQUEST);
            }
        }

        $replacedSlotIds = $this->stringList($data['replacedSlotIds'] ?? []) ?? null;
        if (null === $replacedSlotIds) {
            return $this->json(['error' => 'Invalid field: replacedSlotIds.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $result = $this->mutualizeSlotService->mutualize($slot, $teamIds, $label, $replacedSlotIds);
        } catch (ScheduleGenerationInProgressException) {
            return $this->json(['code' => 'generation_in_progress'], Response::HTTP_CONFLICT);
        } catch (MutualizationRefusedException $e) {
            // Refus NOMMÉ (capacité, Σ, inactive, ensemble déjà déclaré, socle, séance non désignée) :
            // 422 avec le message humain, RIEN n'est écrit (la transaction a été annulée).
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (Throwable $e) {
            // SEC-08 : on journalise le détail, jamais getMessage() au client.
            $this->logger->error('Slot mutualization failed.', ['exception' => $e]);

            return $this->json(['error' => 'The request could not be processed.'], Response::HTTP_BAD_REQUEST);
        }

        return $this->json([
            'message' => 'Block mutualized.',
            'blockId' => $result['blockId'],
            'movedSlotIds' => $result['movedSlotIds'],
            'createdSlotIds' => $result['createdSlotIds'],
            'activatedTeamIds' => $result['activatedTeamIds'],
        ], Response::HTTP_OK);
    }

    /**
     * Une liste de chaînes non vides lue d'un champ du corps : null si ce n'est pas un tableau de
     * chaînes (le champ est présent mais mal typé), une liste éventuellement vide sinon.
     *
     * @return list<string>|null
     */
    private function stringList(mixed $raw): ?array
    {
        if (!\is_array($raw)) {
            return null;
        }

        $out = [];
        foreach ($raw as $value) {
            if (!\is_string($value) || '' === $value) {
                return null;
            }
            $out[] = $value;
        }

        return $out;
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
