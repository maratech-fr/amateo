<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CalendarEntry;
use App\Entity\Constraint;
use App\Entity\Reservation;
use App\Entity\Schedule;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\SharedTrainingBlockTeam;
use App\Entity\Team;
use App\Enum\LockLevel;
use App\Enum\RecapPinWording;
use App\Plans\PlanComposition;
use App\Plans\SoclePlan;
use App\Repository\ConstraintRepository;
use App\Repository\TeamRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Décision fondateur 2026-11-02 : ce que le Récap dit bloquant, les guichets `/generate`, `/regenerate` et
 * `/fill` le refusent aussi, sans passer par l'écran. Une seule liste, lue sur le payload que le solveur
 * recevrait, un seul texte. Un build qui lève ne ferme pas les guichets : le moteur tranche.
 * Exception : au comblement, la sur-réservation ne compte que les réservations, pas les séances verrouillées.
 * Le coach à deux endroits a sa propre garde, {@see CoachInTwoPlacesGuard}.
 */
final readonly class GenerationBlockersGuard
{
    /** ISO-8601 : 1 = lundi (même table que {@see CoachDoubleBookingDetector}). */
    private const array DAY_NAMES = [1 => 'lundi', 2 => 'mardi', 3 => 'mercredi', 4 => 'jeudi', 5 => 'vendredi', 6 => 'samedi', 7 => 'dimanche'];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private SchedulePlanProvisioner $schedulePlanProvisioner,
        private ScheduleConstraintBuilder $scheduleConstraintBuilder,
        private ConstraintRepository $constraintRepository,
        private PeriodConstraintSelector $periodConstraintSelector,
        private PreSolvePreventionWarnings $preventionWarnings,
        private PayloadCapacityMirror $capacityMirror,
        private LoggerInterface $logger,
        private TeamRepository $teamRepository,
        private SecondSessionSameDayDetector $secondSessionSameDayDetector,
        private PlanVenueClosures $planVenueClosures,
        private SoclePlan $soclePlan,
        private PlanComposition $planComposition,
    ) {}

    /**
     * Le premier motif qui refuse cette génération, mot pour mot celui du Récap, ou null.
     *
     * @param Schedule|null $fillSource la version dont le comblement épingle les placements
     */
    public function firstBlockerMessage(Schedule $schedule, ?Schedule $fillSource = null): ?string
    {
        try {
            $clubId = (string) $schedule->getClubId();
            $seasonId = (string) $schedule->getSeasonId();
            $repository = $this->entityManager->getRepository(Reservation::class);

            if ($this->schedulePlanProvisioner->isSeasonSchedule($schedule)) {
                $payload = $this->scheduleConstraintBuilder->buildForPlan($schedule->getSchedulePlanId());
                $reservations = $repository->findBy(['clubId' => $clubId, 'seasonId' => $seasonId, 'schedulePlanId' => $schedule->getSchedulePlanId()], ['id' => 'ASC']);
                $constraints = $this->constraintRepository->findPermanentByClubSeason($clubId, $seasonId);
            } else {
                $planId = (string) $schedule->getSchedulePlanId();
                $entryId = $this->schedulePlanProvisioner->fetchPlanContext($planId)['calendarEntryId'] ?? null;
                $entry = null !== $entryId ? $this->entityManager->getRepository(CalendarEntry::class)->find($entryId) : null;
                if (!$entry instanceof CalendarEntry) {
                    return null;
                }
                $selection = $this->periodConstraintSelector->selectForPlan($planId);
                $payload = $this->scheduleConstraintBuilder->buildForPlan($planId, selection: $selection);
                $constraints = $selection->kept;
                $reservations = array_values(array_filter(
                    $repository->findBy(['schedulePlanId' => $planId], ['id' => 'ASC']),
                    static fn (Reservation $reservation): bool => !isset($selection->disabledVenueIds[$reservation->getVenueId()]),
                ));
            }

            if ($fillSource instanceof Schedule) {
                $payload = $this->scheduleConstraintBuilder->withPinnedAssignments(
                    $payload,
                    $this->entityManager->getRepository(ScheduleSlotTemplate::class)->findBy(['scheduleId' => $fillSource->getId()], ['id' => 'ASC']),
                );
            }

            return $this->blockersFor($payload, $reservations, $constraints, $schedule->getSchedulePlanId(), $fillSource instanceof Schedule)[0] ?? null;
        } catch (Throwable $exception) {
            $this->logger->error('Generation blockers skipped: payload build failed.', ['exception' => $exception]);

            return null;
        }
    }

    /**
     * Les blocages du Récap, dans son ordre.
     *
     * @param array<string, mixed> $payload        le payload que le solveur recevra
     * @param list<Reservation>    $reservations   le périmètre EXACT qui partira au solveur
     * @param list<Constraint>     $constraints    le jeu en vigueur : sur une période, leur origine se cite dans le motif
     * @param string               $schedulePlanId le plan : de la période, ou le plan de saison pour le socle
     * @param bool                 $fill           comblement : les cases épinglées sont surtout les placements de la
     *                                             version source, déjà tous épinglés ; seules les réservations comptent
     *
     * @return list<string>
     */
    public function blockersFor(array $payload, array $reservations, array $constraints, string $schedulePlanId, bool $fill = false): array
    {
        $forPeriod = !$this->soclePlan->isSeasonPlan($schedulePlanId);
        // « Héritée, désactivable pour cette période » n'a de sens que sur une période.
        $constraintOrigins = [];
        foreach ($forPeriod ? $constraints : [] as $constraint) {
            // `true` = héritée du planning de saison, `false` = posée pour une période.
            $constraintOrigins[$constraint->getId()] = null === $constraint->getCalendarEntryId();
        }
        // Comblement : le motif se dit avec les mots de ce geste, jamais « Retirez une réservation » s'il n'y en a aucune.
        $wording = match (true) {
            !$fill => RecapPinWording::RESERVED,
            [] !== $reservations => RecapPinWording::RESERVED_OR_PLACED,
            default => RecapPinWording::PLACED,
        };

        return [
            ...$this->venueMinimumSaturation($payload)['blockers'],
            // ALIGN-09 — le gate prévient pour un risque, bloque pour une certitude.
            ...$this->preventionWarnings->detectBlockers($payload, $constraintOrigins, $wording),
            // P4-44 — une réservation qui ne retombe sur AUCUN créneau de la grille. Sur le socle, le
            // moteur PLACERAIT la séance à l'horaire mort et rendrait `completed` : on le nomme ici.
            ...$this->orphanReservationBlockers($reservations, $payload),
            ...$this->overBookedTeamBlockers($reservations, $payload, $schedulePlanId, $forPeriod, !$fill),
            // Une équipe ne s'entraîne qu'une fois par jour : la saison bloque, jamais une période ni le comblement.
            ...($forPeriod ? [] : $this->secondSessionSameDayDetector->detect($reservations)['blockers']),
        ];
    }

    /**
     * Le payload n'a pas pu se construire : la sur-réservation reste vérifiable, sur le volume de l'équipe.
     *
     * @param list<Reservation> $reservations
     *
     * @return list<string>
     */
    public function overBookedOnEntities(array $reservations, string $clubId, string $seasonId): array
    {
        // Les séances de saison : la ligne de chaque équipe au socle (lot 4, PR 3a).
        $socleLines = $this->planComposition->teamsOf($this->soclePlan->idOf($seasonId));
        $teams = array_map(
            static fn (Team $team): array => ['id' => $team->getId(), 'name' => $team->getName(), 'sessionsPerWeek' => ($socleLines[$team->getId()] ?? null)?->getSessionsPerWeek() ?? 0],
            $this->teamRepository->findBy(['clubId' => $clubId, 'seasonId' => $seasonId]),
        );

        return $this->overBookedTeamBlockers($reservations, ['teams' => $teams], $this->soclePlan->idOf($seasonId), false, false);
    }

    /**
     * PR A — saturation des « au moins N ici » par gymnase. L'ALGÈBRE vit dans
     * {@see PayloadCapacityMirror} (parité épinglée contre le vrai moteur) ; ici,
     * seulement la PHRASE que lit le gestionnaire, et le partage bloquant/avertissement.
     *
     * @param array<string, mixed> $payload
     *
     * @return array{blockers: list<string>, warnings: list<string>}
     */
    public function venueMinimumSaturation(array $payload): array
    {
        $gridSaturated = $this->capacityMirror->venuesSaturatedWithoutLocks($payload);
        $result = ['blockers' => [], 'warnings' => []];
        foreach ($this->capacityMirror->venueMinimumSaturation($payload) as $s) {
            if (\in_array($s['venueId'], $gridSaturated, true)) {
                $result['blockers'][] = \sprintf(
                    'Vos contraintes « au moins » réclament %s à %s, mais il n\'y reste que %s libre%s une fois les réservations déduites : la génération est refusée tant que ce n\'est pas corrigé. Ajoutez des créneaux dans ce gymnase, libérez des réservations, ou assouplissez ces contraintes.',
                    $this->plural($s['demand'], 'place de créneau', 'places de créneau'),
                    $s['venueName'],
                    $this->plural($s['free'], 'place', 'places'),
                    1 === $s['free'] ? '' : 's',
                );
            } else {
                $result['warnings'][] = $this->reservationSaturationWarning($s);
            }
        }

        return $result;
    }

    /**
     * « Poussins et Benjamins demandent chacune au moins 1 séance à Platane, mais il n'y reste qu'une
     * place : la réservation de Seniors C occupe l'autre. Une équipe restera sans séance. ».
     *
     * @param array{venueName: string, demand: int, free: int, teams: array<string, int>, reservedBy: list<string>, pinnedCases: int, freeElsewhere: int} $s
     */
    private function reservationSaturationWarning(array $s): string
    {
        $names = $this->frenchList(array_map(strval(...), array_keys($s['teams'])));
        $amounts = array_values(array_unique($s['teams']));
        $count = \count($s['teams']);
        if (1 === $count) {
            $asks = \sprintf('%s demande au moins %s', $names, $this->plural($amounts[0], 'séance', 'séances'));
        } elseif (1 === \count($amounts)) {
            $asks = \sprintf('%s demandent chacune au moins %s', $names, $this->plural($amounts[0], 'séance', 'séances'));
        } else {
            $asks = \sprintf('%s demandent ensemble au moins %s', $names, $this->plural($s['demand'], 'séance', 'séances'));
        }

        $left = match ($s['free']) {
            0 => 'il n\'y reste aucune place',
            1 => 'il n\'y reste qu\'une place',
            default => \sprintf('il n\'y reste que %d places', $s['free']),
        };

        $reservedBy = $s['reservedBy'];
        if (1 === $s['pinnedCases'] && 1 === \count($reservedBy)) {
            $taken = \sprintf('la réservation de %s occupe %s', $reservedBy[0], 1 === $s['free'] ? 'l\'autre' : (0 === $s['free'] ? 'la case' : 'une autre'));
        } else {
            $taken = \sprintf('les réservations de %s occupent les autres', $this->frenchList($reservedBy));
        }

        // « Sans séance » n'est dit que si c'est vrai : chaque équipe n'en réclame qu'une, et nul autre
        // gymnase n'a de place. Sinon l'équipe joue ailleurs, seul le minimum manque.
        $missing = $s['demand'] - $s['free'];
        if (0 === $s['freeElsewhere'] && [1] === $amounts) {
            $outcome = 1 === $missing ? 'Une équipe restera sans séance.' : \sprintf('%d équipes resteront sans séance.', $missing);
        } else {
            $outcome = \sprintf('Le minimum ne sera pas tenu : %s %s à %s.', $this->plural($missing, 'séance', 'séances'), 1 === $missing ? 'manquera' : 'manqueront', $s['venueName']);
        }

        return \sprintf('%s à %s, mais %s : %s. %s', $asks, $s['venueName'], $left, $taken, $outcome);
    }

    /** @param list<string> $items */
    private function frenchList(array $items): string
    {
        $last = array_pop($items);

        return [] === $items ? (string) $last : implode(', ', $items) . ' et ' . $last;
    }

    /**
     * P3-20 (décision fondateur 2026-08-06) — « si j'ai déclaré 3 réservations alors que
     * l'équipe ne veut que 2 créneaux, on bloque : c'est une INCOHÉRENCE gestionnaire ».
     *
     * Ce n'est pas une préférence bafouée : le gestionnaire a ordonné plus de séances que
     * ce qu'il a lui-même déclaré pour l'équipe. Le solveur ne peut pas arbitrer — un
     * verrou est pré-placé hors modèle (ALIGN-07), donc les trois s'imposeraient et
     * l'équipe jouerait plus que prévu, en silence. D'où le BLOQUEUR, aux côtés du coach
     * dédoublé.
     *
     * Le cas naît naturellement du geste de désactivation : un gymnase désactivé conserve
     * ses réservations (elles reviennent à la réactivation) ; déplacer la séance ailleurs
     * entre-temps en fabrique une de trop, et c'est ICI qu'on l'attrape.
     *
     * Le volume se lit sur le payload : c'est celui de la période (override compris) que le solveur recevra.
     *
     * Une séance verrouillée de la version chargée compte comme une réservation : le moteur la joue aussi, hors modèle.
     * Elle est lue sur le payload (épingle HARD de `slotTemplates`), sauf celle posée sur la case (gymnase, jour,
     * début) d'une réservation de la même équipe, qui n'est que cette réservation. Ce sont exactement les séances
     * dont le déverrouillage fait baisser le compte : le motif les nomme. Trois cas gardent le comptage des seules
     * réservations : le comblement (il épingle déjà tout le placé de sa source), le Récap sans payload, et une équipe
     * membre d'un groupe de la portée, même en pause (les limites d'un groupe sont un autre chantier).
     *
     * @param list<Reservation>    $reservations   le périmètre EXACT qui partira au solveur
     * @param array<string, mixed> $payload
     * @param string               $schedulePlanId la portée des groupes : le plan de la période, ou de saison
     * @param bool                 $forPeriod      la portée est une période
     * @param bool                 $countLocks     false au comblement et sans payload
     *
     * @return list<string>
     */
    private function overBookedTeamBlockers(array $reservations, array $payload, string $schedulePlanId, bool $forPeriod, bool $countLocks): array
    {
        $countByTeam = [];
        $reservedCases = [];
        foreach ($reservations as $reservation) {
            $countByTeam[$reservation->getTeamId()] = ($countByTeam[$reservation->getTeamId()] ?? 0) + 1;
            if (null !== $reservation->getTeamId()) {
                $reservedCases[$reservation->getTeamId()][$this->capacityMirror->key($reservation->getVenueId(), $reservation->getDayOfWeek(), $reservation->getStartTime()->format('H:i'))] = true;
            }
        }

        $lockedByTeam = $countLocks ? $this->lockedSessionsByTeam($payload, $reservedCases) : [];
        if ([] !== $lockedByTeam) {
            $lockedByTeam = array_diff_key($lockedByTeam, $this->groupedTeamIds($payload, $schedulePlanId));
        }

        $blockers = [];
        foreach (\is_array($payload['teams'] ?? null) ? $payload['teams'] : [] as $team) {
            if (!\is_array($team)) {
                continue;
            }
            $teamId = (string) ($team['id'] ?? '');
            $reserved = $countByTeam[$teamId] ?? 0;
            $locked = $lockedByTeam[$teamId] ?? [];
            $sessions = (int) ($team['sessionsPerWeek'] ?? 0);
            if ($reserved + \count($locked) <= $sessions) {
                continue;
            }
            $blockers[] = [] === $locked
                ? \sprintf(
                    '%s a %s pour %s par semaine : retirez %s, ou augmentez le nombre de séances de l\'équipe.',
                    (string) ($team['name'] ?? 'Cette équipe'),
                    $this->plural($reserved, 'réservation', 'réservations'),
                    $this->plural($sessions, 'séance', 'séances'),
                    $this->plural($reserved - $sessions, 'réservation', 'réservations'),
                )
                : $this->overLockedSentence((string) ($team['name'] ?? 'Cette équipe'), $reserved, $locked, $sessions, $forPeriod);
        }

        return $blockers;
    }

    /**
     * « U13 a 1 réservation et 2 séances verrouillées (lundi 18:00-19:30 à Gymnase A, mercredi 17:00-18:30 à Gymnase B)
     * pour 2 séances par semaine : retirez 1 réservation ou déverrouillez 1 de ces séances, ou augmentez le nombre de
     * séances de l'équipe. ». Les réservations se disent en nombre, les séances verrouillées comptées se nomment.
     *
     * @param list<string> $locked les séances verrouillées comptées, déjà triées et dites
     */
    private function overLockedSentence(string $teamName, int $reserved, array $locked, int $sessions, bool $forPeriod): string
    {
        $lockedCount = \count($locked);
        $excess = $reserved + $lockedCount - $sessions;
        $unlock = 1 === $lockedCount ? 'déverrouillez cette séance' : null;
        if (0 === $reserved) {
            $remedy = $unlock ?? \sprintf('déverrouillez %d de ces séances', $excess);
        } elseif (1 === $excess) {
            $remedy = 'retirez 1 réservation ou ' . ($unlock ?? 'déverrouillez 1 de ces séances');
        } else {
            $remedy = \sprintf('retirez des réservations ou %s, %d en tout', $unlock ?? 'déverrouillez des séances de cette liste', $excess);
        }

        return \sprintf(
            '%s a %s%s (%s) pour %s par semaine : %s, ou augmentez le nombre de séances de l\'équipe%s.',
            $teamName,
            0 === $reserved ? '' : $this->plural($reserved, 'réservation', 'réservations') . ' et ',
            $this->plural($lockedCount, 'séance verrouillée', 'séances verrouillées'),
            implode(', ', $locked),
            $this->plural($sessions, 'séance', 'séances'),
            $remedy,
            $forPeriod ? ' pour cette période' : '',
        );
    }

    /**
     * Les séances verrouillées de chaque équipe qui comptent en plus de ses réservations, dites « lundi 18:00-19:30 à
     * Gymnase A » et triées par jour, heure, gymnase. Une case comptée une fois.
     *
     * @param array<string, mixed>               $payload
     * @param array<string, array<string, true>> $reservedCases équipe → cases de ses réservations
     *
     * @return array<string, list<string>>
     */
    private function lockedSessionsByTeam(array $payload, array $reservedCases): array
    {
        $venueNames = [];
        foreach (\is_array($payload['venues'] ?? null) ? $payload['venues'] : [] as $venue) {
            if (\is_array($venue)) {
                $venueNames[(string) ($venue['id'] ?? '')] = (string) ($venue['name'] ?? ($venue['id'] ?? ''));
            }
        }

        $byTeam = [];
        foreach (\is_array($payload['slotTemplates'] ?? null) ? $payload['slotTemplates'] : [] as $pin) {
            // Le payload de saison porte aussi les séances sans verrou de la version chargée.
            if (!\is_array($pin) || LockLevel::HARD->value !== ($pin['lockLevel'] ?? null)) {
                continue;
            }
            $teamId = (string) ($pin['teamId'] ?? '');
            $venueId = (string) ($pin['venueId'] ?? '');
            $startTime = (string) ($pin['startTime'] ?? '');
            $key = $this->capacityMirror->key($venueId, (int) ($pin['dayOfWeek'] ?? 0), $startTime);
            if ('' === $teamId || isset($reservedCases[$teamId][$key])) {
                continue;
            }
            $start = $this->minutesOf($startTime);
            $byTeam[$teamId][$key] = [
                'day' => (int) ($pin['dayOfWeek'] ?? 0),
                'start' => $start,
                'venue' => $venueNames[$venueId] ?? 'ce gymnase',
                'end' => $start + (int) ($pin['durationMinutes'] ?? 0),
            ];
        }

        $said = [];
        foreach ($byTeam as $teamId => $sessions) {
            usort($sessions, static fn (array $a, array $b): int => [$a['day'], $a['start'], $a['venue']] <=> [$b['day'], $b['start'], $b['venue']]);
            $said[(string) $teamId] = array_map(
                fn (array $session): string => \sprintf('%s %s-%s à %s', self::DAY_NAMES[$session['day']] ?? 'ce jour', $this->clock($session['start']), $this->clock($session['end']), $session['venue']),
                $sessions,
            );
        }

        return $said;
    }

    /**
     * Les équipes membres d'un groupe de la portée, même en pause : elles gardent le comptage des seules réservations.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, true>
     */
    private function groupedTeamIds(array $payload, string $schedulePlanId): array
    {
        $clubId = (string) ($payload['clubId'] ?? '');
        $seasonId = (string) ($payload['seasonId'] ?? '');
        if ('' === $clubId || '' === $seasonId) {
            return [];
        }

        $grouped = [];
        foreach ($this->entityManager->getRepository(SharedTrainingBlockTeam::class)->findBy(['clubId' => $clubId, 'seasonId' => $seasonId, 'schedulePlanId' => $schedulePlanId]) as $member) {
            $grouped[$member->getTeamId()] = true;
        }

        return $grouped;
    }

    private function minutesOf(string $time): int
    {
        return (int) substr($time, 0, 2) * 60 + (int) substr($time, 3, 2);
    }

    private function clock(int $minutes): string
    {
        return \sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    /**
     * P4-44 — les réservations HORS GRILLE, nommées avec leur heure.
     *
     * La grille lue est celle du PAYLOAD (`venues[].trainingSlots`) : exactement ce que
     * le solveur recevra, gymnases désactivés déjà retirés.
     *
     * @param list<Reservation>    $reservations
     * @param array<string, mixed> $payload
     *
     * @return list<string>
     */
    private function orphanReservationBlockers(array $reservations, array $payload): array
    {
        if ([] === $reservations) {
            return [];
        }

        // P3-19 — la grille (triplets servis) vient du MIROIR partagé : même clé que
        // l'offre et la saturation, une seule normalisation d'heure.
        $grid = $this->capacityMirror->gridKeys($payload);
        $venueNames = [];
        foreach (\is_array($payload['venues'] ?? null) ? $payload['venues'] : [] as $venue) {
            if (\is_array($venue)) {
                $venueNames[(string) ($venue['id'] ?? '')] = (string) ($venue['name'] ?? ($venue['id'] ?? ''));
            }
        }

        $teamNames = [];
        foreach (\is_array($payload['teams'] ?? null) ? $payload['teams'] : [] as $team) {
            if (\is_array($team)) {
                $teamNames[(string) ($team['id'] ?? '')] = (string) ($team['name'] ?? '');
            }
        }
        $blockers = [];
        foreach ($reservations as $reservation) {
            // Un créneau libre retire sa case de la grille quand il en prend la dernière place : elle reste occupée, rien à reprocher.
            if (null === $reservation->getTeamId()) {
                continue;
            }
            $key = $this->capacityMirror->key($reservation->getVenueId(), $reservation->getDayOfWeek(), $reservation->getStartTime()->format('H:i'));
            if (isset($grid[$key])) {
                continue;
            }
            // Fermetures de gymnase (correction C) — le motif NOMME la fermeture quand c'en est une.
            $closure = !$this->soclePlan->isSeasonPlan($reservation->getSchedulePlanId()) ? PlanVenueClosures::describeForVenue(array_values(array_filter($this->planVenueClosures->effectiveStateForPlan($reservation->getSchedulePlanId())['summaries'], static fn (array $summary): bool => \in_array($reservation->getDayOfWeek(), $summary['weekdays'], true))), $reservation->getVenueId()) : null;
            $blockers[] = \sprintf(
                null !== $closure ? 'La réservation de %s à %s le %s à %s tombe sur une fermeture du gymnase — ' . str_replace('%', '%%', $closure) . '. Retirez-la depuis le récapitulatif, ou ajustez la fermeture.' : 'La réservation de %s à %s le %s à %s ne correspond à aucun créneau : ce créneau a été déplacé ou supprimé. Retirez-la depuis le récapitulatif, ou recréez le créneau.',
                $teamNames[$reservation->getTeamId()] ?? 'cette équipe',
                $venueNames[$reservation->getVenueId()] ?? 'ce gymnase',
                self::DAY_NAMES[$reservation->getDayOfWeek()] ?? 'ce jour',
                $reservation->getStartTime()->format('H:i'),
            );
        }

        return $blockers;
    }

    private function plural(int $count, string $singular, string $plural): string
    {
        return \sprintf('%d %s', $count, 1 === $count ? $singular : $plural);
    }
}
