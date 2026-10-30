<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CalendarEntry;
use App\Entity\Schedule;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\SharedTrainingBlock;
use App\Entity\SharedTrainingBlockTeam;
use App\Entity\Team;
use App\Enum\ConstraintFamily;
use App\Enum\LockLevel;
use App\Exception\DurationMismatchException;
use App\Exception\EngineTimeoutException;
use App\Exception\EvictTargetIsGroupException;
use App\Exception\EvictTargetLockedException;
use App\Exception\EvictTargetMismatchException;
use App\Exception\ScheduleGenerationInProgressException;
use App\Exception\SlotUnavailableException;
use App\Exception\SwapCrossScheduleException;
use App\Exception\SwapWithSelfException;
use App\Exception\TeamAlreadyAtCaseException;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * Déplacer un créneau SOUS LE VERDICT DU MOTEUR (P2-2 F2b).
 *
 * Le geste de déplacement passait par le rail `manual-edit/one-time`, qui n'inspecte que
 * les chevauchements bruts — jamais capacité, fenêtres ni repos. Un gestionnaire pouvait
 * donc déplacer une équipe vers un créneau illégal sans que rien ne l'arrête. Ici, dans
 * l'ordre : (1) refus 409 si une génération tourne, (2) baseline figée SANS la source,
 * (3) verdict moteur (`POST /validate-assignments`, contrat F2a), (4) si « non » les
 * règles violées NOMMÉES pour l'UI, (5) si « oui » l'écriture + le marqueur « retouché à
 * la main » + la publication Mercure.
 *
 * ⚠ La re-validation a lieu AU MOMENT D'ÉCRIRE (décision fondateur) : le verdict n'est pas
 * un cache. Chaque appel reconstruit la baseline courante et redemande au moteur.
 *
 * P2-30 PR A — deux gestes de plus, MÊME rail (verdict souverain, écriture sous « oui ») :
 *  - {@see move()} accepte une ÉVICTION (`evictSlotId`) : retirer l'occupant de la cible, sous D3
 *    (un occupant verrouillé refuse l'éviction avant tout appel moteur) — suppression dans la
 *    même transaction que le déplacement ;
 *  - {@see place()} CRÉE une séance à la dérive (surnuméraire / rattrapage) : pas de source, la
 *    baseline reste complète, et la ligne naît déverrouillée.
 */
final class MoveSlotService
{
    /** Contrat backend⇄engine du endpoint de validation (F2a). Un seul contrat, 3 endpoints. */
    private const string CONTRACT_VERSION = '1.8';

    /**
     * Budget SOLVEUR court PAR solve : la baseline est entièrement figée, le moteur ne place
     * qu'UN candidat déjà épinglé. C'est la valeur portée par `solverTimeoutSeconds` du payload.
     */
    private const int VALIDATE_TIMEOUT_SECONDS = 2;

    /**
     * Timeout HTTP de l'appel `/validate-assignments` — DISTINCT du budget solveur : depuis P2-32
     * un candidat ACCEPTÉ déclenche JUSQU'À TROIS solves côté moteur (le verdict + les deux états
     * figés « avant »/« après » du DELTA de compromis), chacun sous son budget de 2 s. Le timeout
     * transport doit donc les couvrir tous — un `timeout` calé sur le seul verdict rendait un 502
     * sur un dataset dense (ex. BCCL, 90 séances) alors que le moteur répondait juste après.
     *
     * MAISON UNIQUE des DEUX rails de retouche (`move()` ET `place()`) : ils partagent le sujet
     * (même endpoint, même profil de coût), ils partagent donc la valeur. Calé sur MESURE : 9 à
     * 9,6 s de calcul réel constatés sur le club réel (49 équipes, 90 séances) — 20 s laisse la
     * marge d'un club plus gros ou d'une machine plus lente sans transformer une lenteur bénigne
     * en 502. Au-delà, un vrai dépassement remonte NOMMÉ (504 `engine_timeout`), jamais avalé.
     */
    private const int VALIDATE_HTTP_TIMEOUT_SECONDS = 20;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ClubGenerationLock $clubGenerationLock,
        private readonly ScheduleConstraintBuilder $constraintBuilder,
        private readonly SchedulePlanProvisioner $schedulePlanProvisioner,
        private readonly EngineClient $engineClient,
        private readonly ScheduleProgressPublisher $progressPublisher,
        private readonly LoggerInterface $logger,
        private readonly TeamCaseOccupancyGuard $teamCaseOccupancyGuard,
        private readonly PeriodConstraintSelector $periodConstraintSelector,
    ) {}

    /**
     * @throws ScheduleGenerationInProgressException une génération tourne pour ce club (→ 409)
     * @throws SlotUnavailableException              aucune fenêtre de gymnase à la destination — cible
     *                                               inexistante ou jour fermé (→ 422 `slot_unavailable`)
     * @throws TeamAlreadyAtCaseException            l'équipe siège déjà à la cible, sans éviction (→ 422)
     * @throws EvictTargetMismatchException          l'occupant à évincer ne siège pas à la cible (→ 422)
     * @throws EvictTargetLockedException            l'occupant à évincer est verrouillé, D3 (→ 422)
     * @throws EngineTimeoutException                le moteur a été trop lent (→ 504 `engine_timeout`)
     * @throws TransportExceptionInterface           le moteur est injoignable/cassé (→ 502)
     * @throws InvalidArgumentException              le créneau n'a pas de planning parent (état incohérent)
     *
     * `$dryRun` (P2-32) : même chemin JUSQU'AU VERDICT INCLUS (gardes pré-moteur comprises — un
     * verrou refuse l'essai, D3), puis retour AVANT toute écriture/flush/marqueur/Mercure. La
     * réponse porte alors `dryRun => true` et, si une éviction était demandée, l'état qui SERAIT
     * évincé (`evicted`, sans suppression).
     *
     * Q2 (lot 2) — l'occupant évincé peut être une SÉANCE DE GROUPE : ses N séances membres à la case
     * partent alors ENSEMBLE (le bloc SURVIT, elles passent « à replacer »), et la réponse porte
     * `evictedGroup` (bloc + N états) au lieu d'`evicted` (un seul état, occupant équipe). Un verrou
     * d'ANCRAGE de groupe n'est PAS un verrou gestionnaire (pas de D3 ici) ; un occupant ÉQUIPE
     * verrouillé reste souverain (D3, `EvictTargetLockedException`).
     *
     * @return array{valid: bool, violations: list<array{rule: string, message: string, teamId: ?string, coachId: ?string, venueId: ?string, dayOfWeek: ?int, startTime: ?string, conflictingTeamId: ?string}>, compromises: list<array{family: string, effect: string, message: string, teamId: ?string, coachId: ?string, venueId: ?string, dayOfWeek: ?int, startTime: ?string}>, indeterminate?: bool, dryRun?: bool, evicted?: array{slotId: string, teamId: string, dayOfWeek: int, startTime: string, venueId: string, durationMinutes: int}, evictedGroup?: array{blockId: string, slots: list<array{slotId: string, teamId: string, dayOfWeek: int, startTime: string, venueId: string, durationMinutes: int}>}}
     */
    public function move(ScheduleSlotTemplate $slot, int $dayOfWeek, DateTimeImmutable $startTime, string $venueId, ?string $evictSlotId = null, bool $dryRun = false): array
    {
        $schedule = $this->entityManager->getRepository(Schedule::class)->find($slot->getScheduleId());
        if (!$schedule instanceof Schedule) {
            throw new InvalidArgumentException('The slot has no parent schedule.');
        }

        // (1) Une génération réécrit le planning : déplacer maintenant écraserait un
        //     résultat en vol. On CONSTATE l'état sans toucher au verrou (sonde de lecture).
        if ($this->clubGenerationLock->isGenerating($slot->getClubId())) {
            throw new ScheduleGenerationInProgressException;
        }

        // (2) Baseline figée SANS la source → et la GRILLE de gymnase (`venues`) du MÊME payload,
        //     dont on tire la durée de la fenêtre. UN SEUL payload construit : tous les refus
        //     pré-moteur lisent CETTE grille, jamais un second calcul. L'évincé, lui, est retiré
        //     de la baseline APRÈS coup (il dépend de la durée de fenêtre, résolue juste en dessous).
        $payload = $this->buildBasePayload($schedule, $slot, null);

        // (2bis) LA RÈGLE : un emplacement est défini à l'écran Gymnases, et lui seul dit sa taille.
        //        La durée d'une séance ne VOYAGE donc JAMAIS avec l'équipe déplacée — elle est celle
        //        de la FENÊTRE de destination (venueId + dayOfWeek + startTime), lue dans le payload
        //        du moteur. Aucune fenêtre à ce triplet — cible inexistante OU jour fermé, la grille
        //        de `buildForOverlay`/`buildForClubSeason` excluant DÉJÀ les deux (jour fermé, gymnase
        //        désactivé), saison comme période — le créneau n'existe pas : on tranche AVANT le
        //        moteur, y compris en dryRun (un essai doit refuser vite).
        $durationMinutes = $this->resolveWindowDurationMinutes($payload, $venueId, $dayOfWeek, $startTime);
        if (null === $durationMinutes) {
            throw new SlotUnavailableException;
        }

        // (2ter) Éviction éventuelle, RÉSOLUE APRÈS la durée de fenêtre : l'occupant à évincer est
        //        jugé sur l'occupation RÉELLE du candidat [début, début + durée de la FENÊTRE[ — pas
        //        la durée de la source, qui ne s'applique plus (LA RÈGLE). D3 : un occupant verrouillé
        //        fait échouer ici sans jamais consulter le moteur. L'évincé est alors retiré de la
        //        baseline (sinon le candidat se heurterait à l'occupant qu'il libère).
        $eviction = null === $evictSlotId
            ? null
            : $this->resolveEvictionTarget($slot, $schedule, $evictSlotId, $dayOfWeek, $startTime, $venueId, $durationMinutes);
        $evictedSlots = null === $eviction ? [] : $eviction['slots'];
        $evictedBlockId = null === $eviction ? null : $eviction['blockId'];
        if ([] !== $evictedSlots) {
            $evictedIds = array_fill_keys(array_map(static fn (ScheduleSlotTemplate $s): string => $s->getId(), $evictedSlots), true);
            $currentTemplates = \is_array($payload['slotTemplates'] ?? null) ? $payload['slotTemplates'] : [];
            $payload['slotTemplates'] = array_values(array_filter(
                $currentTemplates,
                static fn (mixed $entry): bool => !\is_array($entry) || !isset($evictedIds[(string) ($entry['id'] ?? '')]),
            ));
        }

        // (2quater) Règle fondateur : la MÊME équipe ne siège jamais deux fois à la même case. Si une
        //           AUTRE séance de l'équipe déplacée (≠ la source, ≠ l'évincé) occupe déjà la cible,
        //           le déplacement est refusé AVANT le moteur (qui fusionne par case et ne verrait pas
        //           le doublon) — rien n'est écrit. Évincer SA propre autre séance reste un échange
        //           légal : l'évincé est exclu, la garde ne le compte pas.
        $excludedSlotIds = [$slot->getId(), ...array_map(static fn (ScheduleSlotTemplate $s): string => $s->getId(), $evictedSlots)];
        $this->teamCaseOccupancyGuard->assertTeamNotAlreadyAtCase(
            $schedule->getId(),
            $slot->getTeamId(),
            $venueId,
            $dayOfWeek,
            $startTime,
            $excludedSlotIds,
        );

        // (3) Le candidat : l'équipe posée dans l'emplacement de destination, à la durée de la
        //     FENÊTRE. Le solveur ne lit pas `candidate.durationMinutes` (il dérive tout de la
        //     grille) — cette valeur ne sert qu'à l'écriture serveur qui suivra un « oui ».
        //     Contrat 2.18 : `candidates` est une LISTE (ici à UN élément — un déplacement simple).
        $payload['candidates'] = [[
            'teamId' => $slot->getTeamId(),
            'venueId' => $venueId,
            'dayOfWeek' => $dayOfWeek,
            'startTime' => $startTime->format('H:i'),
            'durationMinutes' => $durationMinutes,
        ]];
        // P2-32 — l'état « AVANT » du candidat pour le DELTA de compromis : le placement d'ORIGINE
        // de la source (elle est retirée de la baseline, cf. buildBasePayload). Lu du slot AVANT
        // toute mutation. Contrat 2.18 : `references` est une LISTE appariée par index à
        // `candidates` (ici un seul couple). Une CRÉATION (place()) n'en pose aucune → baseline nue.
        $payload['references'] = [[
            'teamId' => $slot->getTeamId(),
            'venueId' => $slot->getVenueId(),
            'dayOfWeek' => $slot->getDayOfWeek(),
            'startTime' => $slot->getStartTime()->format('H:i'),
            'durationMinutes' => $slot->getDurationMinutes(),
        ]];
        $result = $this->validateUnderTimeout($payload);

        if (true !== ($result['valid'] ?? false)) {
            // (4) Le déplacement N'A PAS LIEU (ni l'éviction) — les règles violées sont rendues nommées.
            $refused = ['valid' => false, 'violations' => $this->namedViolations($result), 'compromises' => []];
            // ENG-51 — verdict « indéterminé » (le solveur a expiré sans trancher) : on propage le
            // drapeau pour que l'UI montre un bandeau NEUTRE « réessayez », jamais un style conflit.
            // Rien n'est écrit (ce chemin ne persistait déjà rien).
            if (true === ($result['indeterminate'] ?? false)) {
                $refused['indeterminate'] = true;
            }
            if ($dryRun) {
                $refused['dryRun'] = true;
            }

            return $refused;
        }

        $compromises = $this->namedCompromises($result);

        // (dryRun) Le verdict est rendu (compromis compris), MAIS rien n'est écrit : ni le
        // déplacement, ni la suppression de l'occupant, ni le marqueur, ni Mercure. `evicted` /
        // `evictedGroup` décrit l'état qui SERAIT évincé (sans le supprimer).
        if ($dryRun) {
            $dryResponse = ['valid' => true, 'violations' => [], 'compromises' => $compromises, 'dryRun' => true];

            return [...$dryResponse, ...$this->evictedPayload($evictedSlots, $evictedBlockId)];
        }

        // (5) Écrire le déplacement, supprimer l'occupant évincé, poser le marqueur (score désormais
        //     périmé), publier — le tout en UNE transaction (tout-ou-rien). L'ORDRE compte quand on
        //     évince : on SUPPRIME l'occupant et on flush AVANT de déplacer la source. Lors d'un
        //     ÉCHANGE (l'évincé est une AUTRE séance de la MÊME équipe), l'index UNIQUE (schedule,
        //     équipe, gymnase, jour, heure) rejetterait sinon l'UPDATE de la source tant que l'évincé
        //     y siège encore — Doctrine ordonnant les UPDATE avant les DELETE. Libérer la case d'abord
        //     lève l'ambiguïté sans affaiblir l'atomicité.
        $response = ['valid' => true, 'violations' => [], 'compromises' => $compromises];

        return $this->entityManager->wrapInTransaction(function () use ($slot, $schedule, $dayOfWeek, $startTime, $venueId, $durationMinutes, $evictedSlots, $evictedBlockId, $response): array {
            if ([] !== $evictedSlots) {
                // Capturer l'état d'AVANT suppression : le front s'en sert pour proposer de replacer
                // (`evicted` pour un occupant équipe ; `evictedGroup` — bloc + N états — pour un groupe,
                // dont les N séances membres partent ensemble ; le bloc lui-même SURVIT, Q2).
                $response = [...$response, ...$this->evictedPayload($evictedSlots, $evictedBlockId)];
                foreach ($evictedSlots as $evictedSlot) {
                    $this->entityManager->remove($evictedSlot);
                }
                $this->entityManager->flush();
            }
            $slot
                ->setDayOfWeek($dayOfWeek)
                ->setStartTime($startTime)
                ->setVenueId($venueId)
                // LA RÈGLE : la durée écrite est TOUJOURS celle de l'emplacement de destination, jamais
                // celle que la source portait — sinon la séance emporterait sa taille d'un gymnase à l'autre.
                ->setDurationMinutes($durationMinutes);
            $schedule->setManuallyEditedSinceGeneration(true);
            $this->entityManager->flush();

            // Les autres gestionnaires voient le planning bouger (best-effort, comme la génération).
            $this->progressPublisher->publishSafely($schedule, []);

            return $response;
        });
    }

    /**
     * PLACER une séance À LA DÉRIVE (P2-30) sous le verdict — création, pas déplacement. Il n'y a
     * pas de source : la baseline reste COMPLÈTE (moins les frères d'une autre version du plan).
     * Refus → RIEN créé ; accepté → une ligne `ScheduleSlotTemplate` DÉVERROUILLÉE (lockLevel
     * NONE, lockOrigin null, coachId null), le marqueur « retouché », la publication Mercure.
     *
     * ⚠ Aucune garde de comptage ici : placer une séance surnuméraire reste permis — le verdict
     * moteur est le seul juge de la légalité (capacité, fenêtre, repos coach…).
     *
     * ⚠ La DURÉE ne vient JAMAIS du client : elle est résolue de la fenêtre de gymnase visée, dans
     * le MÊME payload que le moteur (le solveur ne lit pas `candidate.durationMinutes`, il dérive
     * tout de la fenêtre — un client qui posterait 600 min sur une fenêtre de 90 écrirait sinon une
     * occupation jamais validée). `$clientDurationMinutes` n'est donc qu'une ASSERTION : s'il ment
     * → 422 `duration_mismatch` ; s'il est null → la fenêtre fait foi.
     *
     * @throws ScheduleGenerationInProgressException une génération tourne pour ce club (→ 409)
     * @throws SlotUnavailableException              aucune fenêtre de gymnase à ce créneau (→ 422)
     * @throws TeamAlreadyAtCaseException            l'équipe siège déjà à cette case (→ 422)
     * @throws DurationMismatchException             la durée du client contredit la fenêtre (→ 422)
     * @throws EngineTimeoutException                le moteur a été trop lent (→ 504 `engine_timeout`)
     * @throws TransportExceptionInterface           le moteur est injoignable/cassé (→ 502)
     *
     * `$dryRun` (P2-32) : même chemin JUSQU'AU VERDICT INCLUS (résolution de durée/fenêtre
     * comprise), puis retour AVANT toute écriture — aucune ligne créée, aucun marqueur, aucun
     * Mercure ; la réponse porte le verdict, ses compromis et `dryRun => true`
     *
     * @return array{valid: bool, violations: list<array{rule: string, message: string, teamId: ?string, coachId: ?string, venueId: ?string, dayOfWeek: ?int, startTime: ?string, conflictingTeamId: ?string}>, compromises: list<array{family: string, effect: string, message: string, teamId: ?string, coachId: ?string, venueId: ?string, dayOfWeek: ?int, startTime: ?string}>, indeterminate?: bool, dryRun?: bool, slotId?: string}
     */
    public function place(Schedule $schedule, string $teamId, int $dayOfWeek, DateTimeImmutable $startTime, string $venueId, ?int $clientDurationMinutes = null, bool $dryRun = false): array
    {
        if ($this->clubGenerationLock->isGenerating($schedule->getClubId())) {
            throw new ScheduleGenerationInProgressException;
        }

        // Baseline + fenêtres de gymnase, AVANT tout appel moteur. La durée se résout ici, de la
        // fenêtre — jamais du client.
        $payload = $this->buildBasePayload($schedule, null, null);

        $durationMinutes = $this->resolveWindowDurationMinutes($payload, $venueId, $dayOfWeek, $startTime);
        if (null === $durationMinutes) {
            // Aucune fenêtre à (gymnase, jour, heure) : le créneau n'existe pas. Rien à faire juger,
            // aucune durée à propager — on tranche avant l'appel.
            throw new SlotUnavailableException;
        }
        if (null !== $clientDurationMinutes && $clientDurationMinutes !== $durationMinutes) {
            // Le client a menti sur la durée de la fenêtre : refus avant le moteur, rien écrit.
            throw new DurationMismatchException;
        }

        // Règle fondateur : la MÊME équipe ne siège jamais deux fois à la même case. Créer une séance
        // là où l'équipe siège DÉJÀ est un refus sec AVANT le moteur (qui fusionne par case) — rien
        // n'est créé. Une AUTRE équipe à capacité restante reste permise (le moteur en juge).
        $this->teamCaseOccupancyGuard->assertTeamNotAlreadyAtCase(
            $schedule->getId(),
            $teamId,
            $venueId,
            $dayOfWeek,
            $startTime,
        );

        // Contrat 2.18 : `candidates` est une LISTE (ici à UN élément — une création à la dérive) ;
        // aucune `references` (pas de source d'origine → « avant » = baseline nue).
        $payload['candidates'] = [[
            'teamId' => $teamId,
            'venueId' => $venueId,
            'dayOfWeek' => $dayOfWeek,
            'startTime' => $startTime->format('H:i'),
            'durationMinutes' => $durationMinutes,
        ]];
        $result = $this->validateUnderTimeout($payload);

        if (true !== ($result['valid'] ?? false)) {
            $refused = ['valid' => false, 'violations' => $this->namedViolations($result), 'compromises' => []];
            // ENG-51 — verdict indéterminé (solveur expiré) propagé pour le bandeau NEUTRE ; rien écrit.
            if (true === ($result['indeterminate'] ?? false)) {
                $refused['indeterminate'] = true;
            }
            if ($dryRun) {
                $refused['dryRun'] = true;
            }

            return $refused;
        }

        $compromises = $this->namedCompromises($result);

        // (dryRun) Verdict rendu, RIEN créé : aucune ligne, aucun marqueur, aucun Mercure.
        if ($dryRun) {
            return ['valid' => true, 'violations' => [], 'compromises' => $compromises, 'dryRun' => true];
        }

        $slot = (new ScheduleSlotTemplate)
            ->setClubId($schedule->getClubId())
            ->setSeasonId($schedule->getSeasonId())
            ->setScheduleId($schedule->getId())
            ->setTeamId($teamId)
            ->setVenueId($venueId)
            ->setDayOfWeek($dayOfWeek)
            ->setStartTime($startTime)
            // La durée PERSISTÉE est celle de la fenêtre (résolue serveur), pas celle du client.
            ->setDurationMinutes($durationMinutes);
        // Déverrouillée par construction : lockLevel NONE / lockOrigin null / coachId null (défauts
        // d'entité). L'API ne pose JAMAIS un verrou ici — c'est le rail manual-edit/lock qui le fait.
        $this->entityManager->persist($slot);
        $schedule->setManuallyEditedSinceGeneration(true);
        $this->entityManager->flush();

        $this->progressPublisher->publishSafely($schedule, []);

        return ['valid' => true, 'violations' => [], 'compromises' => $compromises, 'slotId' => $slot->getId()];
    }

    /**
     * DÉPLACER UNE SÉANCE DE BLOC ENTIÈRE (P2-51 D11, PR-5b) — les N créneaux des membres du bloc
     * qui siègent à la case SOURCE, d'un coup vers la case CIBLE, SOUS UN SEUL VERDICT et en UNE
     * transaction (tout-ou-rien). D11 interdit de déplacer un membre seul : le rail simple `move()`
     * refuserait le premier déplacement (`shared_block_broken`) au deuxième appel séquentiel — d'où
     * ce rail ATOMIQUE, un verdict à N candidats (contrat 2.18).
     *
     * ⚠ Le serveur résout LUI-MÊME les créneaux de la séance (jamais des slotIds clients) : les
     * membres du bloc qui siègent EXACTEMENT à `(sourceVenueId, sourceDayOfWeek, sourceStartTime)`
     * dans CE planning. Un bloc à `commonSessions > 1` a plusieurs séances : la case source désigne
     * CELLE à déplacer. Aucun créneau à cette case → 422 `slot_unavailable` (rien à déplacer).
     *
     * La durée écrite est celle de la FENÊTRE cible (LA RÈGLE), pas celle des sources. Refus verdict
     * → 422, RIEN déplacé. Accepté → les N créneaux mutés puis UN flush (atomicité Doctrine), le
     * marqueur « retouché » et la publication Mercure.
     *
     * P4-329 — comme {@see move()}, ce rail accepte désormais une ÉVICTION (`evictSlotId`) : déposer
     * le groupe sur une case OCCUPÉE en retire l'occupant (qui passe « à replacer »), dans la MÊME
     * transaction que le déplacement des N membres. L'occupant évincable en PR courante est une
     * séance d'ÉQUIPE non verrouillée SEULEMENT — une séance de GROUPE occupante (son verrou
     * d'ancrage n'est pas un verrou gestionnaire, cf. Q2) sera échangeable dans un lot ultérieur,
     * ici elle est refusée NOMMÉMENT avant le moteur. `$dryRun` : même chemin JUSQU'AU VERDICT
     * INCLUS (gardes pré-moteur comprises), puis retour AVANT toute écriture — la réponse porte
     * `dryRun => true` et, si une éviction était demandée, l'état qui SERAIT évincé (`evicted`).
     *
     * @throws ScheduleGenerationInProgressException une génération tourne pour ce club (→ 409)
     * @throws SlotUnavailableException              aucune séance de bloc à la source, ou fenêtre cible absente (→ 422)
     * @throws TeamAlreadyAtCaseException            un membre déplacé siège déjà à la case cible (→ 422)
     * @throws EvictTargetMismatchException          l'occupant à évincer ne siège pas à la cible, ou est une source du groupe (→ 422)
     * @throws EvictTargetIsGroupException           l'occupant à évincer est lui-même une séance de groupe (→ 422)
     * @throws EvictTargetLockedException            l'occupant à évincer (équipe) est verrouillé, D3 (→ 422)
     * @throws EngineTimeoutException                le moteur a été trop lent (→ 504)
     * @throws TransportExceptionInterface           le moteur est injoignable/cassé (→ 502)
     *
     * @return array{valid: bool, violations: list<array{rule: string, message: string, teamId: ?string, coachId: ?string, venueId: ?string, dayOfWeek: ?int, startTime: ?string, conflictingTeamId: ?string}>, compromises: list<array{family: string, effect: string, message: string, teamId: ?string, coachId: ?string, venueId: ?string, dayOfWeek: ?int, startTime: ?string}>, indeterminate?: bool, dryRun?: bool, movedSlotIds?: list<string>, evicted?: array{slotId: string, teamId: string, dayOfWeek: int, startTime: string, venueId: string, durationMinutes: int}}
     */
    public function moveGroup(
        Schedule $schedule,
        SharedTrainingBlock $block,
        int $sourceDayOfWeek,
        DateTimeImmutable $sourceStartTime,
        string $sourceVenueId,
        int $dayOfWeek,
        DateTimeImmutable $startTime,
        string $venueId,
        ?string $evictSlotId = null,
        bool $dryRun = false,
    ): array {
        // (1) Une génération réécrit le planning : déplacer maintenant écraserait un résultat en vol.
        if ($this->clubGenerationLock->isGenerating($schedule->getClubId())) {
            throw new ScheduleGenerationInProgressException;
        }

        // (2) Les membres du bloc, puis les créneaux de CE planning qui siègent à la case source.
        //     C'est le serveur qui résout la séance — jamais des slotIds du client (D11).
        $memberTeamIds = $this->blockMemberTeamIds($block);
        $sources = $this->sourceSlotsAtCase($schedule, $memberTeamIds, $sourceVenueId, $sourceDayOfWeek, $sourceStartTime);
        if ([] === $sources) {
            // Aucun membre du bloc à cette case : il n'y a pas de séance de bloc à déplacer ici.
            throw new SlotUnavailableException;
        }

        // (3) Baseline figée SANS les N sources (sinon chaque membre entrerait en conflit avec
        //     lui-même) + la grille de gymnase, d'où l'on tire la durée de la fenêtre CIBLE.
        $sourceIds = array_map(static fn (ScheduleSlotTemplate $s): string => $s->getId(), $sources);
        $payload = $this->buildBasePayloadExcluding($schedule, $sourceIds);

        $durationMinutes = $this->resolveWindowDurationMinutes($payload, $venueId, $dayOfWeek, $startTime);
        if (null === $durationMinutes) {
            throw new SlotUnavailableException;
        }

        // (3bis) Éviction éventuelle, RÉSOLUE APRÈS la durée de fenêtre (patron move()) : l'occupant à
        //        évincer est jugé sur l'occupation RÉELLE du groupe [début, début + durée de la FENÊTRE[.
        //        Seule une séance d'ÉQUIPE non verrouillée est évincable ici (une séance de groupe
        //        occupante est refusée NOMMÉMENT ; un verrou est souverain, D3). L'évincé est retiré de
        //        la baseline (sinon le groupe se heurterait à l'occupant qu'il libère).
        $evicted = null === $evictSlotId
            ? null
            : $this->resolveGroupEvictionTarget($sourceIds, $schedule, $evictSlotId, $dayOfWeek, $startTime, $venueId, $durationMinutes);
        if ($evicted instanceof ScheduleSlotTemplate) {
            $evictedId = $evicted->getId();
            $currentTemplates = \is_array($payload['slotTemplates'] ?? null) ? $payload['slotTemplates'] : [];
            $payload['slotTemplates'] = array_values(array_filter(
                $currentTemplates,
                static fn (mixed $entry): bool => !\is_array($entry) || (string) ($entry['id'] ?? '') !== $evictedId,
            ));
        }

        // (3ter) Règle fondateur : aucun membre déplacé ne doit déjà siéger à la case cible par une
        //        AUTRE séance (hors les sources — elles SONT ce qui bouge — et hors l'évincé, qui va
        //        partir : évincer SA propre autre séance reste un échange légal). Un conflit résiduel
        //        refuse AVANT le moteur, NOMMÉ, et aucun des N créneaux ne bouge.
        $guardExcluded = $sourceIds;
        if ($evicted instanceof ScheduleSlotTemplate) {
            $guardExcluded[] = $evicted->getId();
        }
        foreach ($sources as $source) {
            $this->teamCaseOccupancyGuard->assertTeamNotAlreadyAtCase(
                $schedule->getId(),
                $source->getTeamId(),
                $venueId,
                $dayOfWeek,
                $startTime,
                $guardExcluded,
                ' — déplacez-la ou retirez-la d\'abord.',
            );
        }

        // (4) UN verdict, N candidats : chaque membre posé à la case cible, chaque référence = sa
        //     case d'origine (appariées par index, contrat 2.18). Le solveur ne lit pas
        //     `durationMinutes` du candidat (il dérive tout de la grille) — il ne sert qu'à l'écriture.
        $candidates = [];
        $references = [];
        foreach ($sources as $source) {
            $candidates[] = [
                'teamId' => $source->getTeamId(),
                'venueId' => $venueId,
                'dayOfWeek' => $dayOfWeek,
                'startTime' => $startTime->format('H:i'),
                'durationMinutes' => $durationMinutes,
            ];
            $references[] = [
                'teamId' => $source->getTeamId(),
                'venueId' => $source->getVenueId(),
                'dayOfWeek' => $source->getDayOfWeek(),
                'startTime' => $source->getStartTime()->format('H:i'),
                'durationMinutes' => $source->getDurationMinutes(),
            ];
        }
        $payload['candidates'] = $candidates;
        $payload['references'] = $references;

        $result = $this->validateUnderTimeout($payload);

        if (true !== ($result['valid'] ?? false)) {
            // (5a) Le déplacement N'A PAS LIEU (ni l'éviction) — aucun des N créneaux ne bouge
            //      (atomicité par le refus).
            $refused = ['valid' => false, 'violations' => $this->namedViolations($result), 'compromises' => []];
            // ENG-51 — verdict indéterminé (solveur expiré) propagé pour le bandeau NEUTRE ; rien écrit.
            if (true === ($result['indeterminate'] ?? false)) {
                $refused['indeterminate'] = true;
            }
            if ($dryRun) {
                $refused['dryRun'] = true;
            }

            return $refused;
        }

        $compromises = $this->namedCompromises($result);

        // (dryRun) Le verdict est rendu (compromis compris), MAIS rien n'est écrit : ni les
        // déplacements, ni la suppression de l'occupant, ni le marqueur, ni Mercure. L'`evicted`
        // décrit l'état qui SERAIT évincé (sans le supprimer).
        if ($dryRun) {
            $dryResponse = ['valid' => true, 'violations' => [], 'compromises' => $compromises, 'dryRun' => true];
            if ($evicted instanceof ScheduleSlotTemplate) {
                $dryResponse['evicted'] = $this->describeEvicted($evicted);
            }

            return $dryResponse;
        }

        // (5b) Écrire les N déplacements, supprimer l'occupant évincé, poser le marqueur, publier —
        //      le tout en UNE transaction (tout-ou-rien). L'ORDRE compte quand on évince : on SUPPRIME
        //      l'occupant et on flush AVANT de déplacer les membres, sinon l'index UNIQUE (schedule,
        //      équipe, gymnase, jour, heure) rejetterait l'UPDATE d'un membre qui échange avec sa
        //      PROPRE autre séance (l'évincé) — Doctrine ordonnant les UPDATE avant les DELETE.
        $response = ['valid' => true, 'violations' => [], 'compromises' => $compromises, 'movedSlotIds' => $sourceIds];

        return $this->entityManager->wrapInTransaction(function () use ($sources, $schedule, $dayOfWeek, $startTime, $venueId, $durationMinutes, $evicted, $response): array {
            if ($evicted instanceof ScheduleSlotTemplate) {
                // Capturer l'état d'AVANT suppression : le front s'en sert pour proposer de replacer.
                $response['evicted'] = $this->describeEvicted($evicted);
                $this->entityManager->remove($evicted);
                $this->entityManager->flush();
            }
            // La durée écrite est TOUJOURS celle de la fenêtre cible.
            foreach ($sources as $source) {
                $source
                    ->setDayOfWeek($dayOfWeek)
                    ->setStartTime($startTime)
                    ->setVenueId($venueId)
                    ->setDurationMinutes($durationMinutes);
            }
            $schedule->setManuallyEditedSinceGeneration(true);
            $this->entityManager->flush();

            $this->progressPublisher->publishSafely($schedule, []);

            return $response;
        });
    }

    /**
     * ÉCHANGER DEUX SÉANCES ATOMIQUEMENT (P4-327) — deux créneaux qui prennent chacun la case de
     * l'autre, SOUS UN SEUL VERDICT (un payload `/validate-assignments` apparié) et en UNE
     * transaction (tout-ou-rien). C'est le pendant « sans perte » de l'éviction : personne n'est
     * retiré, les deux côtés restent placés, ils troquent simplement leurs cases.
     *
     * PÉRIMÈTRE (décision fondateur lot 2, Q2) : CHAQUE côté peut être une séance d'ÉQUIPE (le seul
     * créneau) ou une séance de GROUPE (ses N / M membres à la case partent ENSEMBLE, patron
     * {@see swapSourceSessions()}). L'échange est donc N↔M : les N membres de la source atterrissent à
     * la case de l'occupant, les M membres de l'occupant à la case de la source. Un occupant ÉQUIPE
     * verrouillé (gestionnaire) reste souverain (D4) ; un occupant GROUPE porte un verrou d'ANCRAGE
     * (pas gestionnaire) et s'échange.
     *
     * ⚠ Les POSITIONS viennent du SERVEUR, jamais du client : chaque côté troque la case RÉELLE
     * qu'il occupe. La durée écrite de chaque côté est celle de la FENÊTRE d'arrivée (LA RÈGLE),
     * jamais celle qu'il portait — la source prend la durée de la fenêtre de l'occupant, l'occupant
     * celle de la fenêtre de la source.
     *
     * Ordre des refus, TOUS avant le moteur : deux plannings ≠ ({@see SwapCrossScheduleException}),
     * même créneau / équipe commune aux deux côtés ({@see SwapWithSelfException}), occupant ÉQUIPE
     * verrouillé (D4 souverain, {@see EvictTargetLockedException}), fenêtre absente d'un côté
     * ({@see SlotUnavailableException}), doublon d'équipe ailleurs ({@see TeamAlreadyAtCaseException}).
     *
     * @throws ScheduleGenerationInProgressException une génération tourne pour ce club (→ 409)
     * @throws SwapCrossScheduleException            les deux créneaux ne sont pas du même planning (→ 422)
     * @throws SwapWithSelfException                 l'occupant est la source / une équipe déjà engagée côté source (→ 422)
     * @throws EvictTargetLockedException            l'occupant ÉQUIPE est verrouillé, D4 (→ 422)
     * @throws SlotUnavailableException              aucune fenêtre de gymnase à l'une des deux cases (→ 422)
     * @throws TeamAlreadyAtCaseException            un côté déplacé siège déjà à la case d'arrivée (→ 422)
     * @throws EngineTimeoutException                le moteur a été trop lent (→ 504)
     * @throws TransportExceptionInterface           le moteur est injoignable/cassé (→ 502)
     * @throws InvalidArgumentException              un créneau source de groupe sans bloc résoluble (état incohérent)
     *
     * `$dryRun` (patron {@see move()}) : même chemin JUSQU'AU VERDICT INCLUS (gardes pré-moteur
     * comprises), puis retour AVANT toute écriture — la réponse porte `dryRun => true`
     *
     * @return array{valid: bool, violations: list<array{rule: string, message: string, teamId: ?string, coachId: ?string, venueId: ?string, dayOfWeek: ?int, startTime: ?string, conflictingTeamId: ?string}>, compromises: list<array{family: string, effect: string, message: string, teamId: ?string, coachId: ?string, venueId: ?string, dayOfWeek: ?int, startTime: ?string}>, indeterminate?: bool, dryRun?: bool, movedSlotIds?: list<string>}
     */
    public function swap(ScheduleSlotTemplate $source, ScheduleSlotTemplate $target, bool $dryRun = false): array
    {
        // (0) Les deux côtés doivent vivre dans LE MÊME planning (sinon l'appariement n'a pas de
        //     sens et casserait la baseline figée par planning), et ne pas être le même créneau.
        if ($source->getScheduleId() !== $target->getScheduleId()) {
            throw new SwapCrossScheduleException;
        }
        if ($source->getId() === $target->getId()) {
            throw new SwapWithSelfException;
        }

        $schedule = $this->entityManager->getRepository(Schedule::class)->find($source->getScheduleId());
        if (!$schedule instanceof Schedule) {
            throw new InvalidArgumentException('The slot has no parent schedule.');
        }

        // (1) Une génération réécrit le planning : échanger maintenant écraserait un résultat en vol.
        if ($this->clubGenerationLock->isGenerating($schedule->getClubId())) {
            throw new ScheduleGenerationInProgressException;
        }

        // (2) Le côté SOURCE : une séance d'équipe (le seul créneau) OU une séance de groupe (les N
        //     membres du bloc qui siègent À LA CASE SOURCE partent ensemble, patron moveGroup()).
        $sources = $this->swapSourceSessions($schedule, $source);
        $sourceIds = array_map(static fn (ScheduleSlotTemplate $s): string => $s->getId(), $sources);
        $sourceTeamIds = array_fill_keys(array_map(static fn (ScheduleSlotTemplate $s): string => $s->getTeamId(), $sources), true);

        // (3) L'OCCUPANT : une séance d'ÉQUIPE (le seul créneau) OU une séance de GROUPE (ses M membres
        //     à la case occupant partent ENSEMBLE, patron swapSourceSessions — Q2). L'échange est N↔M.
        $targets = $this->swapSourceSessions($schedule, $target);
        $targetIds = array_map(static fn (ScheduleSlotTemplate $s): string => $s->getId(), $targets);
        // Aucun créneau des deux côtés, ni équipe engagée des DEUX côtés : l'échange formerait un cycle
        // d'écriture sur l'index UNIQUE (une même équipe des deux côtés), et serait un non-geste.
        foreach ($targets as $targetSlot) {
            if (\in_array($targetSlot->getId(), $sourceIds, true) || isset($sourceTeamIds[$targetSlot->getTeamId()])) {
                throw new SwapWithSelfException;
            }
        }
        // D4 — un verrou gestionnaire est souverain : on n'échange jamais un occupant ÉQUIPE verrouillé
        // sans le déverrouiller d'abord. Un occupant GROUPE porte un verrou d'ANCRAGE (pas gestionnaire)
        // → on ne le refuse PAS sur son verrou (Q2) ; il s'échange comme la source groupe.
        if (null === $target->getSharedTrainingBlockId() && LockLevel::NONE !== $target->getLockLevel()) {
            throw new EvictTargetLockedException;
        }

        // (4) Baseline figée SANS les deux côtés (sinon chacun entrerait en conflit avec lui-même) +
        //     la grille de gymnase, d'où l'on tire les durées des DEUX fenêtres d'arrivée.
        $payload = $this->buildBasePayloadExcluding($schedule, [...$sourceIds, ...$targetIds]);

        // Chaque côté prend la durée de la FENÊTRE où il atterrit (LA RÈGLE) : la source → la fenêtre
        // de l'occupant, l'occupant → la fenêtre de la source. Fenêtre absente d'un côté → le créneau
        // n'existe pas, on tranche avant le moteur.
        $sourceWindowDuration = $this->resolveWindowDurationMinutes($payload, $source->getVenueId(), $source->getDayOfWeek(), $source->getStartTime());
        $targetWindowDuration = $this->resolveWindowDurationMinutes($payload, $target->getVenueId(), $target->getDayOfWeek(), $target->getStartTime());
        if (null === $sourceWindowDuration || null === $targetWindowDuration) {
            throw new SlotUnavailableException;
        }

        // (5) Aucun côté déplacé ne doit DÉJÀ siéger à sa case d'arrivée par une AUTRE séance (hors
        //     les deux côtés eux-mêmes, qui SONT le geste). La source atterrit à la case de l'occupant,
        //     l'occupant à la case de la source. Un doublon résiduel refuse AVANT le moteur, NOMMÉ.
        $guardExcluded = [...$sourceIds, ...$targetIds];
        foreach ($sources as $sourceSlot) {
            $this->teamCaseOccupancyGuard->assertTeamNotAlreadyAtCase(
                $schedule->getId(),
                $sourceSlot->getTeamId(),
                $target->getVenueId(),
                $target->getDayOfWeek(),
                $target->getStartTime(),
                $guardExcluded,
            );
        }
        foreach ($targets as $targetSlot) {
            $this->teamCaseOccupancyGuard->assertTeamNotAlreadyAtCase(
                $schedule->getId(),
                $targetSlot->getTeamId(),
                $source->getVenueId(),
                $source->getDayOfWeek(),
                $source->getStartTime(),
                $guardExcluded,
            );
        }

        // Les DEUX cases, CAPTURÉES avant toute mutation : la source EST l'un des `$sources`, donc
        // la lire après coup dans le closure d'écriture rendrait la case de l'occupant déjà écrite
        // (chacun prendrait sa propre case au lieu de celle de l'autre).
        $sourceCaseDay = $source->getDayOfWeek();
        $sourceCaseStart = $source->getStartTime();
        $sourceCaseVenue = $source->getVenueId();
        $targetCaseDay = $target->getDayOfWeek();
        $targetCaseStart = $target->getStartTime();
        $targetCaseVenue = $target->getVenueId();

        // (6) UN verdict apparié : chaque séance source posée à la case de l'occupant, l'occupant posé
        //     à la case de la source ; chaque référence = sa case d'origine (contrat 2.18, candidates
        //     et references appariés par index). Le solveur ne lit pas `durationMinutes` du candidat.
        $candidates = [];
        $references = [];
        foreach ($sources as $sourceSlot) {
            $candidates[] = [
                'teamId' => $sourceSlot->getTeamId(),
                'venueId' => $target->getVenueId(),
                'dayOfWeek' => $target->getDayOfWeek(),
                'startTime' => $target->getStartTime()->format('H:i'),
                'durationMinutes' => $targetWindowDuration,
            ];
            $references[] = [
                'teamId' => $sourceSlot->getTeamId(),
                'venueId' => $sourceSlot->getVenueId(),
                'dayOfWeek' => $sourceSlot->getDayOfWeek(),
                'startTime' => $sourceSlot->getStartTime()->format('H:i'),
                'durationMinutes' => $sourceSlot->getDurationMinutes(),
            ];
        }
        foreach ($targets as $targetSlot) {
            $candidates[] = [
                'teamId' => $targetSlot->getTeamId(),
                'venueId' => $source->getVenueId(),
                'dayOfWeek' => $source->getDayOfWeek(),
                'startTime' => $source->getStartTime()->format('H:i'),
                'durationMinutes' => $sourceWindowDuration,
            ];
            $references[] = [
                'teamId' => $targetSlot->getTeamId(),
                'venueId' => $targetSlot->getVenueId(),
                'dayOfWeek' => $targetSlot->getDayOfWeek(),
                'startTime' => $targetSlot->getStartTime()->format('H:i'),
                'durationMinutes' => $targetSlot->getDurationMinutes(),
            ];
        }
        $payload['candidates'] = $candidates;
        $payload['references'] = $references;

        $result = $this->validateUnderTimeout($payload);

        if (true !== ($result['valid'] ?? false)) {
            // (7a) L'échange N'A PAS LIEU — aucun des deux côtés ne bouge (atomicité par le refus).
            $refused = ['valid' => false, 'violations' => $this->namedViolations($result), 'compromises' => []];
            if (true === ($result['indeterminate'] ?? false)) {
                $refused['indeterminate'] = true;
            }
            if ($dryRun) {
                $refused['dryRun'] = true;
            }

            return $refused;
        }

        $compromises = $this->namedCompromises($result);
        $movedSlotIds = [...$sourceIds, ...$targetIds];

        // (dryRun) Le verdict est rendu, MAIS rien n'est écrit : ni l'échange, ni le marqueur, ni Mercure.
        if ($dryRun) {
            return ['valid' => true, 'violations' => [], 'compromises' => $compromises, 'dryRun' => true, 'movedSlotIds' => $movedSlotIds];
        }

        // (7b) Écrire les deux côtés, poser le marqueur, publier — le tout en UNE transaction
        //      (tout-ou-rien). Aucune suppression : contrairement à l'éviction, l'occupant n'est pas
        //      retiré, il prend la case de la source. Les deux côtés portent des ÉQUIPES DISTINCTES
        //      (garde (3) « équipe commune »), donc l'index UNIQUE (schedule, équipe, gymnase, jour,
        //      heure) ne se heurte jamais entre les UPDATE : chacun écrit une clé d'équipe différente.
        $response = ['valid' => true, 'violations' => [], 'compromises' => $compromises, 'movedSlotIds' => $movedSlotIds];

        return $this->entityManager->wrapInTransaction(function () use ($sources, $targets, $schedule, $sourceCaseDay, $sourceCaseStart, $sourceCaseVenue, $targetCaseDay, $targetCaseStart, $targetCaseVenue, $sourceWindowDuration, $targetWindowDuration, $response): array {
            foreach ($sources as $sourceSlot) {
                $sourceSlot
                    ->setDayOfWeek($targetCaseDay)
                    ->setStartTime($targetCaseStart)
                    ->setVenueId($targetCaseVenue)
                    ->setDurationMinutes($targetWindowDuration);
            }
            foreach ($targets as $targetSlot) {
                $targetSlot
                    ->setDayOfWeek($sourceCaseDay)
                    ->setStartTime($sourceCaseStart)
                    ->setVenueId($sourceCaseVenue)
                    ->setDurationMinutes($sourceWindowDuration);
            }
            $schedule->setManuallyEditedSinceGeneration(true);
            $this->entityManager->flush();

            $this->progressPublisher->publishSafely($schedule, []);

            return $response;
        });
    }

    /**
     * RETIRER une séance placée (« vider un créneau ») — la ligne {@see ScheduleSlotTemplate}
     * DISPARAÎT, son éventuel verrou part avec elle, et la séance passe « non placée » (un ÉCART
     * calculé — rien n'est stocké pour ça). AUCUN appel moteur : un retrait ne crée pas de conflit,
     * il OUVRE un trou. Le verdict n'a donc rien à juger (décision fondateur 2026-10-12).
     *
     * Le trou est SIGNALÉ, jamais silencieux : le front le lit via l'existant (drift/DriftBanner),
     * et ce retour porte en plus `unmetPresence` — les contraintes « garder ses créneaux de saison »
     * (SEASON_SLOTS) ACTIVES de l'équipe retirée, qui n'agissent qu'en PÉRIODE (`[]` sur le socle).
     *
     * `$dryRun` (appel à blanc du ConfirmDialog) : même calcul (conséquences nommées comprises),
     * puis retour AVANT toute écriture/flush/marqueur/Mercure.
     *
     * @throws ScheduleGenerationInProgressException une génération tourne pour ce club (→ 409)
     * @throws InvalidArgumentException              le créneau n'a pas de planning parent (état incohérent)
     *
     * @return array{removed: array{slotId: string, teamId: string, dayOfWeek: int, startTime: string, venueId: string, durationMinutes: int}, lockLevel: string, unmetPresence: list<array{family: string, teamId: string, message: string}>, dryRun?: bool}
     */
    public function remove(ScheduleSlotTemplate $slot, bool $dryRun = false): array
    {
        $schedule = $this->entityManager->getRepository(Schedule::class)->find($slot->getScheduleId());
        if (!$schedule instanceof Schedule) {
            throw new InvalidArgumentException('The slot has no parent schedule.');
        }

        // Une génération réécrit le planning : retirer maintenant écraserait son résultat en vol.
        if ($this->clubGenerationLock->isGenerating($slot->getClubId())) {
            throw new ScheduleGenerationInProgressException;
        }

        // Conséquences NOMMÉES, calculées AVANT toute écriture (identiques en essai et en vrai) :
        // l'état de la séance qui part (forme `evicted`, pour le bandeau « à replacer »), le verrou
        // perdu, et les présences SEASON_SLOTS que le retrait n'honore plus.
        $removed = $this->describeEvicted($slot);
        $lockLevel = $slot->getLockLevel()->value;
        $unmetPresence = $this->unmetPresenceForRemoval($schedule, [$slot->getTeamId()]);

        if ($dryRun) {
            return ['removed' => $removed, 'lockLevel' => $lockLevel, 'unmetPresence' => $unmetPresence, 'dryRun' => true];
        }

        return $this->entityManager->wrapInTransaction(function () use ($slot, $schedule, $removed, $lockLevel, $unmetPresence): array {
            $this->entityManager->remove($slot);
            $schedule->setManuallyEditedSinceGeneration(true);
            $this->entityManager->flush();

            // Les autres gestionnaires voient le planning bouger (best-effort, comme la génération).
            $this->progressPublisher->publishSafely($schedule, []);

            return ['removed' => $removed, 'lockLevel' => $lockLevel, 'unmetPresence' => $unmetPresence];
        });
    }

    /**
     * RETIRER UNE SÉANCE DE BLOC ENTIÈRE (« vider le créneau du groupe ») — c'est le GROUPE qui
     * occupe la case, donc tout-ou-rien : les N créneaux des membres du bloc qui siègent à la case
     * source DISPARAISSENT ensemble, en UNE transaction. Le {@see SharedTrainingBlock} et ses
     * adhésions SURVIVENT (le groupe existe toujours ; seule la séance de cette case est retirée).
     * AUCUN appel moteur, comme {@see remove()}. Le serveur résout lui-même les créneaux depuis la
     * case source (jamais des slotIds clients), patron {@see moveGroup()}.
     *
     * @throws ScheduleGenerationInProgressException une génération tourne pour ce club (→ 409)
     * @throws SlotUnavailableException              aucun membre du bloc à cette case (→ 422 `slot_unavailable`)
     *
     * @return array{removedSlotIds: list<string>, removed: list<array{slotId: string, teamId: string, dayOfWeek: int, startTime: string, venueId: string, durationMinutes: int}>, unmetPresence: list<array{family: string, teamId: string, message: string}>, dryRun?: bool}
     */
    public function removeGroup(
        Schedule $schedule,
        SharedTrainingBlock $block,
        int $dayOfWeek,
        DateTimeImmutable $startTime,
        string $venueId,
        bool $dryRun = false,
    ): array {
        if ($this->clubGenerationLock->isGenerating($schedule->getClubId())) {
            throw new ScheduleGenerationInProgressException;
        }

        // Les membres du bloc, puis les créneaux de CE planning qui siègent à la case source : la
        // séance de groupe à retirer. C'est le serveur qui la résout (jamais des slotIds clients).
        $memberTeamIds = $this->blockMemberTeamIds($block);
        $sources = $this->sourceSlotsAtCase($schedule, $memberTeamIds, $venueId, $dayOfWeek, $startTime);
        if ([] === $sources) {
            // Aucun membre du bloc à cette case : il n'y a pas de séance de groupe à retirer ici.
            throw new SlotUnavailableException;
        }

        $removed = array_map(fn (ScheduleSlotTemplate $s): array => $this->describeEvicted($s), $sources);
        $removedSlotIds = array_map(static fn (ScheduleSlotTemplate $s): string => $s->getId(), $sources);
        $teamIds = array_map(static fn (ScheduleSlotTemplate $s): string => $s->getTeamId(), $sources);
        $unmetPresence = $this->unmetPresenceForRemoval($schedule, $teamIds);

        if ($dryRun) {
            return ['removedSlotIds' => $removedSlotIds, 'removed' => $removed, 'unmetPresence' => $unmetPresence, 'dryRun' => true];
        }

        return $this->entityManager->wrapInTransaction(function () use ($sources, $schedule, $removed, $removedSlotIds, $unmetPresence): array {
            foreach ($sources as $source) {
                $this->entityManager->remove($source);
            }
            $schedule->setManuallyEditedSinceGeneration(true);
            $this->entityManager->flush();

            $this->progressPublisher->publishSafely($schedule, []);

            return ['removedSlotIds' => $removedSlotIds, 'removed' => $removed, 'unmetPresence' => $unmetPresence];
        });
    }

    /**
     * Les séances du côté SOURCE d'un échange : le seul créneau si la source est une séance d'équipe,
     * ou les N créneaux des membres du bloc qui siègent À LA CASE SOURCE si c'est une séance de groupe
     * (patron {@see moveGroup()} — le serveur résout lui-même les membres, jamais des slotIds client).
     *
     * @return list<ScheduleSlotTemplate>
     */
    private function swapSourceSessions(Schedule $schedule, ScheduleSlotTemplate $source): array
    {
        $blockId = $source->getSharedTrainingBlockId();
        if (null === $blockId) {
            return [$source];
        }

        $block = $this->entityManager->getRepository(SharedTrainingBlock::class)->find($blockId);
        if (!$block instanceof SharedTrainingBlock) {
            // Un créneau porte un blockId dont le bloc n'existe plus : état incohérent, on ne devine pas.
            throw new InvalidArgumentException('The source slot references a missing shared training block.');
        }

        $sources = $this->sourceSlotsAtCase(
            $schedule,
            $this->blockMemberTeamIds($block),
            $source->getVenueId(),
            $source->getDayOfWeek(),
            $source->getStartTime(),
        );

        // Le créneau source EST membre de ce bloc à cette case : la liste n'est jamais vide.
        return [] === $sources ? [$source] : $sources;
    }

    /**
     * Les présences « garder ses créneaux de saison » (SEASON_SLOTS) ACTIVES que le retrait des
     * équipes données n'honore plus. `[]` sur le socle de saison (la famille n'agit qu'en PÉRIODE,
     * via les placements de socle émis en `keep` — cf. `ScheduleConstraintBuilder:364-373`, dont ce
     * filtre est le miroir). Sinon : la sélection de contraintes de la période (source UNIQUE
     * `PeriodConstraintSelector`), restreinte aux SEASON_SLOTS actives visant une équipe retirée, un
     * message composé CÔTÉ SERVEUR (le front l'affiche verbatim — jamais redérivé).
     *
     * @param list<string> $removedTeamIds
     *
     * @return list<array{family: string, teamId: string, message: string}>
     */
    private function unmetPresenceForRemoval(Schedule $schedule, array $removedTeamIds): array
    {
        if ($this->schedulePlanProvisioner->isSeasonSchedule($schedule)) {
            return [];
        }
        $overlayEntryId = $this->schedulePlanProvisioner->periodEntryIdOf($schedule);
        $overlayEntry = null === $overlayEntryId
            ? null
            : $this->entityManager->getRepository(CalendarEntry::class)->find($overlayEntryId);
        if (!$overlayEntry instanceof CalendarEntry) {
            return [];
        }

        $selection = $this->periodConstraintSelector->selectForPeriodPlan(
            $schedule->getClubId(),
            $schedule->getSeasonId(),
            $schedule->getSchedulePlanId(),
            $overlayEntry,
        );

        $removedSet = array_fill_keys($removedTeamIds, true);
        $teamRepository = $this->entityManager->getRepository(Team::class);
        $out = [];
        $seen = [];
        foreach ($selection->kept as $constraint) {
            if (ConstraintFamily::SEASON_SLOTS !== $constraint->getFamily() || !$constraint->getIsActive()) {
                continue;
            }
            $teamId = $constraint->getScopeTargetId();
            if (null === $teamId || !isset($removedSet[$teamId]) || isset($seen[$teamId])) {
                continue;
            }
            $seen[$teamId] = true;
            $team = $teamRepository->find($teamId);
            $teamName = $team instanceof Team ? $team->getName() : 'une équipe';
            $out[] = [
                'family' => ConstraintFamily::SEASON_SLOTS->value,
                'teamId' => $teamId,
                'message' => \sprintf('%s : la demande « garder les créneaux de saison » n\'est plus honorée, sa séance ayant été retirée.', $teamName),
            ];
        }

        return $out;
    }

    /**
     * Les ids d'équipe membres d'un bloc (patron `GroupReservationController::blockMemberTeamIds`).
     *
     * @return list<string>
     */
    private function blockMemberTeamIds(SharedTrainingBlock $block): array
    {
        return array_map(
            static fn (SharedTrainingBlockTeam $row): string => $row->getTeamId(),
            $this->entityManager->getRepository(SharedTrainingBlockTeam::class)
                ->findBy(['blockId' => $block->getId()], ['teamId' => 'ASC']),
        );
    }

    /**
     * Les créneaux de CE planning qui siègent EXACTEMENT à la case source, parmi les équipes
     * données — la séance de bloc à déplacer. Tenant-scopé par le filtre Doctrine.
     *
     * @param list<string> $teamIds
     *
     * @return list<ScheduleSlotTemplate>
     */
    private function sourceSlotsAtCase(Schedule $schedule, array $teamIds, string $venueId, int $dayOfWeek, DateTimeImmutable $startTime): array
    {
        if ([] === $teamIds) {
            return [];
        }

        return $this->entityManager->getRepository(ScheduleSlotTemplate::class)->findBy([
            'scheduleId' => $schedule->getId(),
            'venueId' => $venueId,
            'dayOfWeek' => $dayOfWeek,
            'startTime' => $startTime,
            'teamId' => $teamIds,
        ]);
    }

    /**
     * Le verdict moteur sous le délai transport commun (`move` ET `place`), avec la traduction du
     * timeout : un moteur TROP LENT lève un {@see EngineTimeoutException} PORTANT son code (→ 504
     * `engine_timeout`), DISTINCT d'un moteur injoignable/cassé qui reste un `TransportException`
     * nu (→ 502). Rien n'est écrit dans les deux cas — l'appel précède toute mutation. On ne
     * traduit QUE le timeout : les autres pannes transport gardent leur mapping historique.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function validateUnderTimeout(array $payload): array
    {
        try {
            return $this->engineClient->validateAssignment($payload, self::VALIDATE_HTTP_TIMEOUT_SECONDS);
        } catch (TimeoutExceptionInterface $e) {
            throw new EngineTimeoutException('The engine did not answer in time.', 0, $e);
        }
    }

    /**
     * L'état d'un occupant à évincer, tel que le front s'en sert pour proposer un replacement.
     *
     * @return array{slotId: string, teamId: string, dayOfWeek: int, startTime: string, venueId: string, durationMinutes: int}
     */
    private function describeEvicted(ScheduleSlotTemplate $evicted): array
    {
        return [
            'slotId' => $evicted->getId(),
            'teamId' => $evicted->getTeamId(),
            'dayOfWeek' => $evicted->getDayOfWeek(),
            'startTime' => $evicted->getStartTime()->format('H:i'),
            'venueId' => $evicted->getVenueId(),
            'durationMinutes' => $evicted->getDurationMinutes(),
        ];
    }

    /**
     * Valide et retourne le créneau OCCUPANT désigné par `evictSlotId`, ou lève. TOUTE la
     * validation a lieu AVANT le moteur : introuvable / d'un autre planning / égal à la source /
     * ne siégeant pas à la cible → {@see EvictTargetMismatchException} ; verrouillé →
     * {@see EvictTargetLockedException} (D3 : un verrou est souverain, le moteur n'est pas appelé).
     *
     * ⚠ `$candidateDurationMinutes` est la durée de la FENÊTRE de destination (LA RÈGLE), PAS celle
     * de la source : le candidat occupera `[startTime, startTime + durée de la fenêtre[`, et c'est
     * cette occupation-là qui décide s'il chevauche l'occupant. Juger sur la durée source raterait
     * un occupant que la fenêtre (plus longue) recouvre, ou en accuserait un que la fenêtre (plus
     * courte) laisse tranquille.
     *
     * Q2 (lot 2) — l'occupant peut être une SÉANCE DE GROUPE (`sharedTrainingBlockId` non NULL) : on
     * renvoie alors les N séances membres qui siègent à SA case (elles partent ensemble, le bloc
     * survit), sans contrôle de verrou (son verrou d'ANCRAGE n'est pas un verrou gestionnaire). Un
     * occupant ÉQUIPE verrouillé reste souverain (D3). Le drapeau `blockId` distingue les deux cas.
     *
     * @return array{slots: list<ScheduleSlotTemplate>, blockId: ?string}
     */
    private function resolveEvictionTarget(ScheduleSlotTemplate $source, Schedule $schedule, string $evictSlotId, int $dayOfWeek, DateTimeImmutable $startTime, string $venueId, int $candidateDurationMinutes): array
    {
        $evicted = $this->entityManager->getRepository(ScheduleSlotTemplate::class)->find($evictSlotId);
        if (!$evicted instanceof ScheduleSlotTemplate
            || $evicted->getScheduleId() !== $schedule->getId()
            || $evicted->getId() === $source->getId()) {
            throw new EvictTargetMismatchException;
        }

        // L'occupant doit SIÉGER à la cible : même gymnase + même jour + chevauchement horaire
        // avec la fenêtre du candidat [startTime, startTime + durée de la FENÊTRE de destination[.
        if ($evicted->getVenueId() !== $venueId
            || $evicted->getDayOfWeek() !== $dayOfWeek
            || !$this->overlaps($startTime, $candidateDurationMinutes, $evicted->getStartTime(), $evicted->getDurationMinutes())) {
            throw new EvictTargetMismatchException;
        }

        $blockId = $evicted->getSharedTrainingBlockId();
        if (null === $blockId) {
            // Occupant ÉQUIPE — D3 : un verrou gestionnaire est souverain, on ne l'évince pas.
            if (LockLevel::NONE !== $evicted->getLockLevel()) {
                throw new EvictTargetLockedException;
            }

            return ['slots' => [$evicted], 'blockId' => null];
        }

        // Occupant GROUPE (Q2) — les N séances membres à la case partent ENSEMBLE (bloc survit). Le
        // verrou d'ANCRAGE n'est pas un verrou gestionnaire, donc pas de D3 ici.
        $block = $this->entityManager->getRepository(SharedTrainingBlock::class)->find($blockId);
        if (!$block instanceof SharedTrainingBlock) {
            // blockId orphelin (ne devrait pas survenir, FK) : on traite comme une séance simple (D3).
            if (LockLevel::NONE !== $evicted->getLockLevel()) {
                throw new EvictTargetLockedException;
            }

            return ['slots' => [$evicted], 'blockId' => null];
        }
        $members = $this->sourceSlotsAtCase($schedule, $this->blockMemberTeamIds($block), $evicted->getVenueId(), $evicted->getDayOfWeek(), $evicted->getStartTime());

        return ['slots' => [] === $members ? [$evicted] : $members, 'blockId' => $blockId];
    }

    /**
     * Le fragment de réponse décrivant l'occupant évincé : `evicted` (un seul état) pour un occupant
     * ÉQUIPE, `evictedGroup` (bloc + N états) pour un occupant GROUPE (Q2). Vide s'il n'y a pas
     * d'éviction. Fusionné dans la réponse par l'appelant.
     *
     * @param list<ScheduleSlotTemplate> $slots
     *
     * @return array{}|array{evicted: array{slotId: string, teamId: string, dayOfWeek: int, startTime: string, venueId: string, durationMinutes: int}}|array{evictedGroup: array{blockId: string, slots: list<array{slotId: string, teamId: string, dayOfWeek: int, startTime: string, venueId: string, durationMinutes: int}>}}
     */
    private function evictedPayload(array $slots, ?string $blockId): array
    {
        if ([] === $slots) {
            return [];
        }
        if (null === $blockId) {
            return ['evicted' => $this->describeEvicted($slots[0])];
        }

        return ['evictedGroup' => [
            'blockId' => $blockId,
            'slots' => array_map(fn (ScheduleSlotTemplate $s): array => $this->describeEvicted($s), $slots),
        ]];
    }

    /**
     * Valide et retourne la séance OCCUPANT désignée par `evictSlotId` sur un déplacement de GROUPE,
     * ou lève — toute la validation AVANT le moteur (patron {@see resolveEvictionTarget}, adapté aux
     * N sources du groupe) :
     *  - introuvable / d'un autre planning / ÉGALE À L'UNE DES SOURCES du groupe → {@see EvictTargetMismatchException} ;
     *  - ne siégeant pas à la cible (même gymnase + même jour + chevauchement avec la fenêtre) → idem ;
     *  - séance de GROUPE (`sharedTrainingBlockId` non NULL) → {@see EvictTargetIsGroupException}
     *    (son verrou d'ancrage n'est pas un verrou gestionnaire ; l'échange de deux groupes est un
     *    geste d'un lot ultérieur — refusé NOMMÉMENT ici, AVANT le contrôle de verrou qui la verrait
     *    verrouillée par son ancrage) ;
     *  - verrouillée (équipe, lockLevel ≠ NONE) → {@see EvictTargetLockedException} (D3).
     *
     * ⚠ `$candidateDurationMinutes` est la durée de la FENÊTRE cible (LA RÈGLE), pas celle des
     * sources : le groupe occupera `[startTime, startTime + durée de la fenêtre[`, et c'est cette
     * occupation-là qui décide s'il chevauche l'occupant.
     *
     * @param list<string> $sourceIds les ids des N créneaux membres du groupe qui bougent (une séance
     *                                du groupe n'est jamais sa propre cible d'éviction)
     */
    private function resolveGroupEvictionTarget(array $sourceIds, Schedule $schedule, string $evictSlotId, int $dayOfWeek, DateTimeImmutable $startTime, string $venueId, int $candidateDurationMinutes): ScheduleSlotTemplate
    {
        $evicted = $this->entityManager->getRepository(ScheduleSlotTemplate::class)->find($evictSlotId);
        if (!$evicted instanceof ScheduleSlotTemplate
            || $evicted->getScheduleId() !== $schedule->getId()
            || \in_array($evicted->getId(), $sourceIds, true)) {
            throw new EvictTargetMismatchException;
        }

        // L'occupant doit SIÉGER à la cible : même gymnase + même jour + chevauchement horaire
        // avec la fenêtre du groupe [startTime, startTime + durée de la FENÊTRE de destination[.
        if ($evicted->getVenueId() !== $venueId
            || $evicted->getDayOfWeek() !== $dayOfWeek
            || !$this->overlaps($startTime, $candidateDurationMinutes, $evicted->getStartTime(), $evicted->getDurationMinutes())) {
            throw new EvictTargetMismatchException;
        }

        // L'occupant évincable en PR courante est une séance d'ÉQUIPE seulement : une séance de
        // GROUPE porte un `sharedTrainingBlockId` — on la refuse AVANT le contrôle de verrou (son
        // verrou d'ancrage la ferait tomber en `target_locked`, message trompeur).
        if (null !== $evicted->getSharedTrainingBlockId()) {
            throw new EvictTargetIsGroupException;
        }

        // D3 — un verrou gestionnaire est souverain : on n'évince jamais une séance d'équipe
        // verrouillée sans la déverrouiller d'abord.
        if (LockLevel::NONE !== $evicted->getLockLevel()) {
            throw new EvictTargetLockedException;
        }

        return $evicted;
    }

    /** Deux fenêtres [start, start+durée[ (minutes depuis minuit) se chevauchent-elles ? */
    private function overlaps(DateTimeImmutable $startA, int $durationA, DateTimeImmutable $startB, int $durationB): bool
    {
        $aStart = (int) $startA->format('G') * 60 + (int) $startA->format('i');
        $bStart = (int) $startB->format('G') * 60 + (int) $startB->format('i');

        return $aStart < $bStart + $durationB && $bStart < $aStart + $durationA;
    }

    /**
     * Le payload `/validate-assignments` : le vocabulaire de `/generate` (venues/teams/
     * coaches/constraints/slotTemplates) + un `candidate` + la version du contrat.
     *
     * ⚠ Deux retraits sur la baseline, sinon un déplacement PARFAITEMENT LÉGAL serait
     * refusé (le piège signalé par F2a) :
     *  - la SOURCE : sans elle, l'équipe entre en conflit AVEC ELLE-MÊME
     *    (`team_no_overlap` / `one_session_per_day`) — elle serait figée mardi ET candidate
     *    jeudi ;
     *  - les créneaux des AUTRES versions du même plan : `buildForClubSeason` fige toutes
     *    les versions du plan de saison ; l'équipe se heurterait à son propre placement
     *    dans un brouillon voisin. On ne garde donc de la baseline QUE les créneaux de CE
     *    planning (moins les ids exclus) et les réservations durables — jamais ceux d'un frère.
     *
     * `$source` (déplacement) et `$evicted` (occupant à évincer) sont retirés de la baseline
     * en plus des frères ; à la CRÉATION (place), les deux sont null → baseline complète moins
     * les frères.
     *
     * Le `candidate` n'est PAS posé ici : chaque appelant l'ajoute une fois sa durée résolue
     * CÔTÉ SERVEUR (le déplacement la tient du slot ; la création la lit de la fenêtre de gymnase
     * de CE payload — jamais du client). Le payload rendu porte déjà les fenêtres (`venues`).
     *
     * @return array<string, mixed>
     */
    private function buildBasePayload(Schedule $schedule, ?ScheduleSlotTemplate $source, ?ScheduleSlotTemplate $evicted): array
    {
        $excludedIds = [];
        if ($source instanceof ScheduleSlotTemplate) {
            $excludedIds[] = $source->getId();
        }
        if ($evicted instanceof ScheduleSlotTemplate) {
            $excludedIds[] = $evicted->getId();
        }

        return $this->buildBasePayloadExcluding($schedule, $excludedIds);
    }

    /**
     * Le même payload de base que {@see buildBasePayload}, mais en retirant de la baseline un
     * ENSEMBLE d'ids (une seule source pour le rail simple ; les N sources d'une séance de bloc pour
     * le rail `move-group`). C'est la maison unique de l'assemblage — le rail de groupe ne réécrit
     * pas la construction, il ne fait que fournir SES ids exclus.
     *
     * @param list<string> $excludedIds
     *
     * @return array<string, mixed>
     */
    private function buildBasePayloadExcluding(Schedule $schedule, array $excludedIds): array
    {
        $overlayEntryId = $this->schedulePlanProvisioner->periodEntryIdOf($schedule);
        $overlayEntry = null === $overlayEntryId
            ? null
            : $this->entityManager->getRepository(CalendarEntry::class)->find($overlayEntryId);

        $payload = $overlayEntry instanceof CalendarEntry
            ? $this->constraintBuilder->buildForOverlay($schedule, $overlayEntry)
            : $this->constraintBuilder->buildForClubSeason($schedule->getClubId(), $schedule->getSeasonId());

        $currentSlotTemplates = \is_array($payload['slotTemplates'] ?? null) ? $payload['slotTemplates'] : [];
        $payload['slotTemplates'] = $this->baselineWithoutSiblings($currentSlotTemplates, $schedule, $excludedIds);
        // Le bloc `implicitRules` (P2-28) reste dans le payload : parité génération ⇄ verdict —
        // un geste sur un planning généré en PREFERRED doit être jugé au MÊME réglage,
        // sinon le verdict tout-HARD refuserait à tort ce que la génération a permis.
        $payload['version'] = self::CONTRACT_VERSION;
        $payload['solverTimeoutSeconds'] = self::VALIDATE_TIMEOUT_SECONDS;

        return $payload;
    }

    /**
     * La durée d'une séance NE VIENT JAMAIS DU CLIENT : c'est celle de la fenêtre de gymnase visée
     * (venueId + dayOfWeek + startTime), lue dans le MÊME payload que le moteur — `venues[].trainingSlots`
     * dérive de `VenueTrainingSlot`, exactement la source dont le solveur tire `slot_durations`.
     * Aucune fenêtre à ce triplet → null (le créneau n'existe pas).
     *
     * @param array<string, mixed> $payload
     */
    private function resolveWindowDurationMinutes(array $payload, string $venueId, int $dayOfWeek, DateTimeImmutable $startTime): ?int
    {
        $venues = \is_array($payload['venues'] ?? null) ? $payload['venues'] : [];
        $wanted = $startTime->format('H:i');

        foreach ($venues as $venue) {
            if (!\is_array($venue) || ($venue['id'] ?? null) !== $venueId) {
                continue;
            }
            $slots = \is_array($venue['trainingSlots'] ?? null) ? $venue['trainingSlots'] : [];
            foreach ($slots as $slot) {
                if (\is_array($slot)
                    && ($slot['dayOfWeek'] ?? null) === $dayOfWeek
                    && ($slot['startTime'] ?? null) === $wanted) {
                    return (int) $slot['durationMinutes'];
                }
            }
        }

        return null;
    }

    /**
     * Retire de la baseline sérialisée les ids exclus (source et/ou occupant évincé) ET tout
     * créneau d'une AUTRE version du plan de saison — en gardant réservations et créneaux de CE
     * planning. On identifie les « frères » par leur id de `ScheduleSlotTemplate` (une
     * réservation n'y figure pas), donc filtrer sur cet ensemble laisse intacts les pins durables.
     *
     * @param array<int, mixed> $slotTemplates
     * @param list<string>      $excludedIds
     *
     * @return array<int, mixed>
     */
    private function baselineWithoutSiblings(array $slotTemplates, Schedule $schedule, array $excludedIds): array
    {
        /** @var list<array{id: string}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('s.id')
            ->from(ScheduleSlotTemplate::class, 's')
            ->where('s.clubId = :clubId')
            ->andWhere('s.seasonId = :seasonId')
            ->andWhere('s.scheduleId <> :scheduleId')
            ->setParameter('clubId', $schedule->getClubId())
            ->setParameter('seasonId', $schedule->getSeasonId())
            ->setParameter('scheduleId', $schedule->getId())
            ->getQuery()
            ->getScalarResult();

        $excluded = [];
        foreach ($excludedIds as $id) {
            $excluded[$id] = true;
        }
        foreach ($rows as $row) {
            $excluded[$row['id']] = true;
        }

        return array_values(array_filter(
            $slotTemplates,
            static fn (mixed $entry): bool => !\is_array($entry) || !isset($excluded[(string) ($entry['id'] ?? '')]),
        ));
    }

    /**
     * Les règles violées, réduites à ce que l'UI exploite : un code de règle (pour brancher),
     * un message déjà humain (le moteur y nomme coach/gymnase/heure) ET les ids des entités
     * fautives, tels que le moteur les émet — pour surligner le conflit côté front. Ce ne sont
     * pas des ids « internes » cachés : ils désignent des entités que le client possède déjà
     * (ses équipes/coachs/gymnases). `conflictingTeamId` porte l'équipe DÉJÀ en place qui
     * bloque le candidat. Chaque champ est null-safe : absent du verdict → null, jamais une
     * clé manquante.
     *
     * @param array<string, mixed> $result
     *
     * @return list<array{rule: string, message: string, teamId: ?string, coachId: ?string, venueId: ?string, dayOfWeek: ?int, startTime: ?string, conflictingTeamId: ?string}>
     */
    private function namedViolations(array $result): array
    {
        $raw = $result['violations'] ?? null;
        if (!\is_array($raw)) {
            $this->logger->warning('Engine returned an invalid verdict with no violations array.');

            return [[
                'rule' => 'unknown_hard_conflict',
                'message' => 'Ce déplacement casse une règle du moteur qui n\'a pas pu être nommée.',
                'teamId' => null, 'coachId' => null, 'venueId' => null,
                'dayOfWeek' => null, 'startTime' => null, 'conflictingTeamId' => null,
            ]];
        }

        $violations = [];
        foreach ($raw as $entry) {
            if (!\is_array($entry)) {
                continue;
            }
            $violations[] = [
                'rule' => (string) ($entry['rule'] ?? 'unknown_hard_conflict'),
                'message' => (string) ($entry['message'] ?? 'Ce déplacement casse une règle du moteur.'),
                'teamId' => $this->nullableString($entry['teamId'] ?? null),
                'coachId' => $this->nullableString($entry['coachId'] ?? null),
                'venueId' => $this->nullableString($entry['venueId'] ?? null),
                'dayOfWeek' => isset($entry['dayOfWeek']) ? (int) $entry['dayOfWeek'] : null,
                'startTime' => $this->nullableString($entry['startTime'] ?? null),
                'conflictingTeamId' => $this->nullableString($entry['conflictingTeamId'] ?? null),
            ];
        }

        return $violations;
    }

    /**
     * Les COMPROMIS d'un candidat ACCEPTÉ (P2-32), réduits à ce que l'UI exploite (patron
     * {@see namedViolations}) : la famille (pour brancher), l'effet (`broken`/`gained`), un
     * message déjà humain (le moteur y nomme équipe/coach/gymnase, AUCUN identifiant interne) et
     * les ids des entités concernées, pour le surlignage. Chaque champ est null-safe. Absent du
     * verdict (refus, ou moteur muet) → liste vide, jamais une clé manquante.
     *
     * @param array<string, mixed> $result
     *
     * @return list<array{family: string, effect: string, message: string, teamId: ?string, coachId: ?string, venueId: ?string, dayOfWeek: ?int, startTime: ?string}>
     */
    private function namedCompromises(array $result): array
    {
        $raw = $result['compromises'] ?? null;
        if (!\is_array($raw)) {
            return [];
        }

        $compromises = [];
        foreach ($raw as $entry) {
            if (!\is_array($entry)) {
                continue;
            }
            $compromises[] = [
                'family' => (string) ($entry['family'] ?? ''),
                'effect' => (string) ($entry['effect'] ?? ''),
                'message' => (string) ($entry['message'] ?? ''),
                'teamId' => $this->nullableString($entry['teamId'] ?? null),
                'coachId' => $this->nullableString($entry['coachId'] ?? null),
                'venueId' => $this->nullableString($entry['venueId'] ?? null),
                'dayOfWeek' => isset($entry['dayOfWeek']) ? (int) $entry['dayOfWeek'] : null,
                'startTime' => $this->nullableString($entry['startTime'] ?? null),
            ];
        }

        return $compromises;
    }

    /** Passe l'id tel quel (string) ou null s'il est absent/null — jamais un cast d'array/objet. */
    private function nullableString(mixed $value): ?string
    {
        return \is_scalar($value) ? (string) $value : null;
    }
}
