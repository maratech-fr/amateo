<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CalendarEntry;
use App\Entity\Constraint;
use App\Entity\Reservation;
use App\Entity\Schedule;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\Team;
use App\Repository\ConstraintRepository;
use App\Repository\TeamRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Décision fondateur 2026-11-02 : ce que le Récap dit bloquant, les guichets `/generate`, `/regenerate` et
 * `/fill` le refusent aussi, sans passer par l'écran. Une seule liste, lue sur le payload que le solveur
 * recevrait, un seul texte. Un build qui lève ne ferme pas les guichets : le moteur tranche.
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
                $payload = $this->scheduleConstraintBuilder->buildForClubSeason($clubId, $seasonId);
                $reservations = $repository->findBy(['clubId' => $clubId, 'seasonId' => $seasonId, 'schedulePlanId' => null], ['id' => 'ASC']);
                $constraints = $this->constraintRepository->findPermanentByClubSeason($clubId, $seasonId);
            } else {
                $planId = (string) $schedule->getSchedulePlanId();
                $entryId = $this->schedulePlanProvisioner->fetchPlanContext($planId)['calendarEntryId'] ?? null;
                $entry = null !== $entryId ? $this->entityManager->getRepository(CalendarEntry::class)->find($entryId) : null;
                if (!$entry instanceof CalendarEntry) {
                    return null;
                }
                $selection = $this->periodConstraintSelector->selectForPeriodPlan($clubId, $seasonId, $planId, $entry);
                $payload = $this->scheduleConstraintBuilder->buildForPeriodPlan($clubId, $seasonId, $planId, $entry, selection: $selection);
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

            $first = $this->blockersFor($payload, $reservations, $constraints, !$this->schedulePlanProvisioner->isSeasonSchedule($schedule))[0] ?? null;

            return $fillSource instanceof Schedule && null !== $first ? $this->sayingThePinsComeFromTheSource($first, [] !== $reservations) : $first;
        } catch (Throwable $exception) {
            $this->logger->error('Generation blockers skipped: payload build failed.', ['exception' => $exception]);

            return null;
        }
    }

    /**
     * Les blocages du Récap, dans son ordre.
     *
     * @param array<string, mixed> $payload      le payload que le solveur recevra
     * @param list<Reservation>    $reservations le périmètre EXACT qui partira au solveur
     * @param list<Constraint>     $constraints  le jeu en vigueur : sur une période, leur origine se cite dans le motif
     *
     * @return list<string>
     */
    public function blockersFor(array $payload, array $reservations, array $constraints, bool $forPeriod): array
    {
        // « Héritée, désactivable pour cette période » n'a de sens que sur une période.
        $constraintOrigins = [];
        foreach ($forPeriod ? $constraints : [] as $constraint) {
            // `true` = héritée du planning de saison, `false` = posée pour une période.
            $constraintOrigins[$constraint->getId()] = null === $constraint->getCalendarEntryId();
        }

        return [
            ...$this->venueMinimumSaturation($payload)['blockers'],
            // ALIGN-09 — le gate prévient pour un risque, bloque pour une certitude.
            ...$this->preventionWarnings->detectBlockers($payload, $constraintOrigins),
            // P4-44 — une réservation qui ne retombe sur AUCUN créneau de la grille. Sur le socle, le
            // moteur PLACERAIT la séance à l'horaire mort et rendrait `completed` : on le nomme ici.
            ...$this->orphanReservationBlockers($reservations, $payload),
            ...$this->overBookedTeamBlockers($reservations, $payload),
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
        $teams = array_map(
            static fn (Team $team): array => ['id' => $team->getId(), 'name' => $team->getName(), 'sessionsPerWeek' => $team->getSessionsPerWeek()],
            $this->teamRepository->findBy(['clubId' => $clubId, 'seasonId' => $seasonId]),
        );

        return $this->overBookedTeamBlockers($reservations, ['teams' => $teams]);
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
     * Comblement : les cases réunies sont surtout les placements de la version source, pas des réservations.
     * Le motif du Récap se dit alors avec les mots de ce geste, jamais « Retirez une réservation » s'il n'y
     * en a aucune.
     */
    private function sayingThePinsComeFromTheSource(string $message, bool $hasReservations): string
    {
        if ($hasReservations) {
            return str_replace('Retirez une réservation ou ajustez', 'Retirez une réservation, déplacez une séance de cette version, ou ajustez', $message);
        }

        return str_replace(
            ['Retirez une réservation ou ajustez', 'réservé sur', 'une seule case réservée', 'cases réservées', 'case réservée'],
            ['Déplacez une séance de cette version ou ajustez', 'placé sur', 'une seule case placée', 'cases déjà placées', 'case déjà placée'],
            $message,
        );
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
     * @param list<Reservation>    $reservations le périmètre EXACT qui partira au solveur
     * @param array<string, mixed> $payload
     *
     * @return list<string>
     */
    private function overBookedTeamBlockers(array $reservations, array $payload): array
    {
        if ([] === $reservations) {
            return [];
        }

        $countByTeam = [];
        foreach ($reservations as $reservation) {
            $countByTeam[$reservation->getTeamId()] = ($countByTeam[$reservation->getTeamId()] ?? 0) + 1;
        }

        $blockers = [];
        foreach (\is_array($payload['teams'] ?? null) ? $payload['teams'] : [] as $team) {
            if (!\is_array($team)) {
                continue;
            }
            $reserved = $countByTeam[(string) ($team['id'] ?? '')] ?? 0;
            $sessions = (int) ($team['sessionsPerWeek'] ?? 0);
            if ($reserved <= $sessions) {
                continue;
            }
            $blockers[] = \sprintf(
                '%s a %s pour %s par semaine : retirez %s, ou augmentez le nombre de séances de l\'équipe.',
                (string) ($team['name'] ?? 'Cette équipe'),
                $this->plural($reserved, 'réservation', 'réservations'),
                $this->plural($sessions, 'séance', 'séances'),
                $this->plural($reserved - $sessions, 'réservation', 'réservations'),
            );
        }

        return $blockers;
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
            $closure = null !== $reservation->getSchedulePlanId() ? PlanVenueClosures::describeForVenue(array_values(array_filter($this->planVenueClosures->effectiveStateForPlan($reservation->getSchedulePlanId())['summaries'], static fn (array $summary): bool => \in_array($reservation->getDayOfWeek(), $summary['weekdays'], true))), $reservation->getVenueId()) : null;
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
