<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\CalendarEntry;
use App\Entity\Constraint;
use App\Entity\Reservation;
use App\Enum\CalendarEntryPeriodType;
use App\Repository\CalendarEntryRepository;
use App\Repository\ConstraintRepository;
use App\Repository\TeamRepository;
use App\Repository\VenueRepository;
use App\Service\CoachDoubleBookingDetector;
use App\Service\ConstraintValidationService;
use App\Service\ManagementAccessGuard;
use App\Service\PayloadCapacityMirror;
use App\Service\PeriodConstraintSelection;
use App\Service\PeriodConstraintSelector;
use App\Service\PreSolvePreventionWarnings;
use App\Service\ScheduleConstraintBuilder;
use App\Service\SchedulePlanProvisioner;
use App\Service\SeasonResolver;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

/**
 * BW3 — pre-solve gate. Runs the (previously unwired) ConstraintValidationService
 * over the club's constraints so the wizard can flag gross errors (a coach who
 * "starts after he ends", contradictory HARD rules…) BEFORE generating.
 */
final class ValidateConstraintsController extends AbstractController
{
    /** ISO-8601 : 1 = lundi (même table que {@see CoachDoubleBookingDetector}). */
    private const array DAY_NAMES = [1 => 'lundi', 2 => 'mardi', 3 => 'mercredi', 4 => 'jeudi', 5 => 'vendredi', 6 => 'samedi', 7 => 'dimanche'];

    public function __construct(
        private readonly ConstraintRepository $constraintRepository,
        private readonly CalendarEntryRepository $calendarEntryRepository,
        private readonly SeasonResolver $seasonResolver,
        private readonly ConstraintValidationService $validationService,
        private readonly RequestStack $requestStack,
        private readonly ManagementAccessGuard $managementAccessGuard,
        private readonly TeamRepository $teamRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly SchedulePlanProvisioner $schedulePlanProvisioner,
        private readonly VenueRepository $venueRepository,
        private readonly CoachDoubleBookingDetector $coachDoubleBookingDetector,
        private readonly PayloadCapacityMirror $capacityMirror,
        private readonly PeriodConstraintSelector $periodConstraintSelector,
        private readonly ScheduleConstraintBuilder $scheduleConstraintBuilder,
        private readonly LoggerInterface $logger,
        private readonly PreSolvePreventionWarnings $preventionWarnings,
    ) {}

    #[Route('/api/constraints/validate', name: 'api_constraints_validate', methods: ['POST'])]
    public function __invoke(): JsonResponse
    {
        // SEC-12: the pre-solve gate is a management action (part of the cockpit /
        // generation flow) — align it with the rest of the cockpit's role gate.
        $this->managementAccessGuard->assertManager();

        $request = $this->requestStack->getCurrentRequest();
        $clubId = $request?->attributes->get('_club_id') ?? $request?->headers->get('X-Club-Id');
        if (!\is_string($clubId) || '' === $clubId) {
            return $this->json(['error' => 'No club in context.'], Response::HTTP_BAD_REQUEST);
        }

        $seasonId = $this->seasonResolver->selectedOrCurrent($request, $clubId)?->getId();
        if (null === $seasonId) {
            return $this->json(['error' => 'No active season.'], Response::HTTP_BAD_REQUEST);
        }

        $warnings = [];
        $selection = null;
        $calendarEntryId = $this->requestedCalendarEntryId($request);
        if (null !== $calendarEntryId) {
            $calendarEntry = $this->calendarEntryRepository->find($calendarEntryId);
            if (!$calendarEntry instanceof CalendarEntry) {
                return $this->json(['error' => 'No active period.'], Response::HTTP_BAD_REQUEST);
            }

            ['constraints' => $constraints, 'warnings' => $warnings, 'selection' => $selection] = $this->constraintsForPeriod($clubId, $seasonId, $calendarEntry);
        } else {
            /** @var list<Constraint> $constraints */
            $constraints = $this->constraintRepository->findPermanentByClubSeason($clubId, $seasonId);
        }

        // Map teamId → sessions/week for the fail-fast venue-minimum check — only
        // loaded when at least one constraint actually carries a minAtVenueId (the
        // vast majority of validations are TIME/DAY/COACH and never need it).
        $teamSessions = [];
        $needsTeams = array_filter($constraints, static fn (Constraint $c): bool => isset($c->getConfig()['minAtVenueId']));
        if ([] !== $needsTeams) {
            foreach ($this->teamRepository->findBy(['clubId' => $clubId, 'seasonId' => $seasonId]) as $team) {
                $teamSessions[$team->getId()] = $team->getSessionsPerWeek();
            }
        }

        $errors = [];
        foreach ($constraints as $constraint) {
            $messages = $this->validationService->validate($constraint);
            $venueMinError = $this->validationService->venueMinimumError($constraint, $teamSessions[$constraint->getScopeTargetId()] ?? null);
            if (null !== $venueMinError) {
                $messages[] = $venueMinError;
            }
            if ([] !== $messages) {
                // PRÉCISION (retour fondateur 2026-08-05) : une erreur sans le nom de
                // SA contrainte est introuvable dans une liste de 45 — chaque message
                // nomme la ligne à corriger.
                $errors[$constraint->getId()] = array_map(
                    static fn (string $message): string => \sprintf('« %s » : %s', $constraint->getName(), $message),
                    $messages,
                );
            }
        }

        $conflicts = array_map(
            static fn (array $c): array => [
                'constraint1Id' => $c['constraint1']->getId(),
                'constraint2Id' => $c['constraint2']->getId(),
                // Les DEUX contraintes nommées : sans ça le gestionnaire sait qu'il y a
                // contradiction, pas OÙ la corriger.
                'reason' => \sprintf('« %s » et « %s » — %s', $c['constraint1']->getName(), $c['constraint2']->getName(), $c['reason']),
            ],
            $this->validationService->detectConflicts($constraints),
        );

        // P2-9 PR A — LE VOLET CAPACITÉ, dans les deux sens (décision fondateur 2026-07-31).
        // Les nombres viennent du PAYLOAD que le solveur recevra (`buildForClubSeason` /
        // `buildForPeriodPlan`, en lecture seule depuis P2-9ter) — JAMAIS d'un recalcul à la
        // main : trois tentatives sont mortes exactement là-dessus, dont une en perdant des
        // données. Une période sans plan (non génératrice, ou antérieure au lot C1) n'a pas
        // de payload : rien à mesurer.
        // ⚠ Le build ne doit JAMAIS faire tomber le récap : c'est l'écran où le gestionnaire
        // vient corriger les données douteuses, et un 500 l'y enfermerait (même garde que
        // `SchedulePlanProvisioner::currentStructureHash`, seul autre appelant interactif du
        // builder). La capacité est un CONFORT : sans elle, le récap reste utile.
        try {
            $capacityPayload = null;
            if (null !== $calendarEntryId) {
                if ($selection instanceof PeriodConstraintSelection) {
                    // La sélection est déjà calculée pour les warnings : on la PASSE au
                    // builder plutôt que de la lui faire recalculer (revue #341).
                    $capacityPayload = $this->scheduleConstraintBuilder->buildForPeriodPlan($clubId, $seasonId, $selection->schedulePlanId, $calendarEntry, selection: $selection);
                }
            } else {
                $capacityPayload = $this->scheduleConstraintBuilder->buildForClubSeason($clubId, $seasonId);
            }
            if (null !== $capacityPayload) {
                $warnings = [
                    ...$warnings,
                    ...$this->capacityWarnings($capacityPayload),
                    // P4-57 — les préventions calculées depuis CE payload, donc depuis ce que le
                    // solveur va réellement recevoir : gymnase sans créneau, équipe qu'aucun
                    // créneau ne peut accueillir, coach surchargé, contrainte visant une équipe
                    // absente. Chacune ne se voyait qu'APRÈS génération, en diagnostic.
                    ...$this->preventionWarnings->detect($capacityPayload),
                ];
            }
        } catch (Throwable $exception) {
            // Le silence serait indiscernable d'un « la capacité est juste » : on le DIT.
            // `error` et non `warning` : un build qui échoue est une anomalie, pas un cas
            // métier — c'est le seul endroit où on l'apprendra (revue #341 round 2).
            $this->logger->error('Capacity recap skipped: payload build failed.', ['exception' => $exception]);
            $warnings[] = 'La capacité des gymnases n\'a pas pu être vérifiée — les autres contrôles du récap restent valables.';
            $capacityPayload = null;
        }

        $reservations = $this->reservationsInScope($clubId, $seasonId, $calendarEntryId, $selection);

        // PR A (2026-08-06) — deux préventions de plus, décision fondateur :
        // « on peut s'en prémunir » plutôt que le découvrir après génération.
        //
        // 1. Coach indisponible × créneau réservé en dur → AVERTISSEMENT : le verrou est
        //    souverain (ALIGN-07), la génération passera, mais elle posera la séance le
        //    jour de congé du coach — jusqu'ici on ne l'apprenait qu'en INFO post-solve.
        $warnings = [
            ...$warnings,
            ...array_map(
                fn (array $clash): string => $this->coachDoubleBookingDetector->describeUnavailabilityForRecap($clash),
                $this->coachDoubleBookingDetector->detectUnavailabilityClashes($reservations, $constraints, $clubId, $seasonId),
            ),
        ];

        // 2. Saturation des « au moins N ici » par gymnase → BLOQUEUR : le moteur exige
        //    ces séances sur les créneaux NON verrouillés, MAIS crédite le plancher des
        //    jours déjà verrouillés de l'équipe à ce gymnase (P4-97 : effective_min =
        //    minimum − jours verrouillés). Le bloqueur ne tombe donc que sur la demande
        //    RÉELLEMENT restante > places libres = INFEASIBLE certain.
        $saturationBlockers = null !== $capacityPayload ? $this->venueMinimumSaturation($capacityPayload) : [];

        // P2-9 PR B — IMPOSSIBILITÉ PHYSIQUE : un verrou qui met un coach à deux endroits
        // en même temps. Le solveur ne peut PAS l'attraper (un verrou HARD est pré-placé
        // hors modèle, ALIGN-07), et ce n'est pas une préférence bafouée — le gestionnaire
        // n'a jamais choisi que son coach se dédouble. Décision fondateur : ça BLOQUE.
        $blockers = [
            ...$saturationBlockers,
            // ALIGN-09 — « au moins une séance l'un de ces jours » quand AUCUN de ces jours n'a
            // de créneau candidat : INFEASIBLE certain, on BLOQUE (le gate prévient pour un
            // risque, bloque pour une certitude arithmétique). Lu sur le MÊME payload que les
            // avertissements, donc saison ET période.
            ...(null !== $capacityPayload ? $this->preventionWarnings->detectBlockers($capacityPayload) : []),
            // 3. P4-44 — une réservation qui ne retombe sur AUCUN créneau de la grille.
            //    Sur une PÉRIODE, `OrphanPinGuard` refusait déjà la génération, mais
            //    l'écran « Réserver » ne peut pas montrer la ligne fautive (il boucle
            //    sur les créneaux) : le gestionnaire était mis en échec sans recours.
            //    Sur le SOCLE, pire : le moteur PLACE la séance à l'horaire mort et rend
            //    `completed` (mesuré) — le planning distribué envoie les équipes devant
            //    une porte fermée. On le NOMME ici, avec l'heure, et le récap offre de la
            //    retirer : c'est ce qui rend le blocage légitime.
            ...$this->orphanReservationBlockers($reservations, $capacityPayload),
            ...$this->overBookedTeamBlockers($reservations, $clubId, $seasonId),
            ...array_map(
                fn (array $conflict): string => $this->coachDoubleBookingDetector->describeForRecap($conflict),
                $this->coachDoubleBookingDetector->detect($reservations, $clubId, $seasonId),
            ),
        ];

        // #8 (fondateur 2026-07-24) — un avertissement n'invalide RIEN : « SM1 va ailleurs,
        // on ignore la contrainte, mais on AVERTIT ». `valid` et le code HTTP restent
        // calculés sur les seules erreurs et conflits… et désormais les bloqueurs, qui
        // sont l'exact contraire d'un avertissement.
        $valid = [] === $errors && [] === $conflicts && [] === $blockers;

        return $this->json(
            ['valid' => $valid, 'errors' => $errors, 'conflicts' => $conflicts, 'warnings' => $warnings, 'blockers' => $blockers],
            $valid ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    /**
     * P2-9 PR A — demande vs offre, lues sur le PAYLOAD que le solveur recevra.
     *
     * ⚠ L'offre n'est PAS la somme brute des capacités : le moteur lit ce même payload
     * autrement, et trois lectures naïves annonceraient des places qui n'existent pas
     * (revue #341) —
     *
     * 1. `model.slot_capacities` est un **dict** clé `(gymnase, jour, heure)`
     *    (`model.py:54-56`) : deux créneaux au même triplet s'ÉCRASENT, ils ne
     *    s'additionnent pas ;
     * 2. un **verrou HARD** (réservation ou slot épinglé, présents dans `slotTemplates`)
     *    met le triplet dans `blocked_venue_slots` et le moteur ne crée alors AUCUNE
     *    variable pour ce créneau, quelle que soit sa capacité (`model.py:62-63`) — un
     *    créneau verrouillé sert UNE équipe, pas `capacity` ;
     * 3. une contrainte **FACILITY_CAPACITY** rabote la capacité à `min(capacity,
     *    maxTeams)` (`main.py:288-293`, clé sur `config.venueId`).
     *
     * Même après ces trois lectures, l'offre reste un **MAJORANT** : le moteur réduit
     * encore par les indispos coach, les fenêtres horaires, les gymnases interdits… D'où
     * l'asymétrie ASSUMÉE des deux messages, qui est ce qui les rend honnêtes :
     *
     * - **sous-capacité** (`offre < demande`) : l'offre réelle étant ≤ ce majorant, la
     *   conclusion est SÛRE — jamais de fausse alerte. Elle peut sous-avertir, jamais mentir.
     *   Message en « au moins X » : condition nécessaire, jamais suffisante.
     * - **surplus** (`offre > demande`) : la conclusion inverse ne se déduit PAS d'un
     *   majorant. Le message dit donc « jusqu'à » et « pourraient », jamais un fait —
     *   sinon il inviterait à ajouter des séances à un club en réalité sous-capacitaire
     *   (le scénario exact remonté en revue).
     *
     * @param array<string, mixed> $payload
     *
     * @return list<string>
     */
    private function capacityWarnings(array $payload): array
    {
        $demand = 0;
        foreach (\is_array($payload['teams'] ?? null) ? $payload['teams'] : [] as $team) {
            $demand += (int) ($team['sessionsPerWeek'] ?? 0);
        }

        // Aucune équipe : la demande vaut 0 et tout « surplus » serait du bruit sur un club
        // vide — le récap bloque déjà en amont sur « Ajoutez au moins une équipe ».
        if (0 === $demand) {
            return [];
        }

        $offer = $this->payloadOffer($payload);

        if ($offer < $demand) {
            return [\sprintf(
                'Vos équipes demandent %s par semaine, vos gymnases n\'en offrent que %d : au moins %s ne %s pas être placée%s. Ajoutez des créneaux, augmentez leur capacité, ou réduisez des séances.',
                $this->plural($demand, 'séance', 'séances'),
                $offer,
                $this->plural($demand - $offer, 'séance', 'séances'),
                1 === $demand - $offer ? 'pourra' : 'pourront',
                1 === $demand - $offer ? '' : 's',
            )];
        }

        if ($offer > $demand) {
            return [\sprintf(
                'Vos gymnases offrent jusqu\'à %s pour %s demandée%s : %s %s rester libre%s. Vous pouvez agrandir des créneaux (capacité), en ajouter, ou augmenter les séances de certaines équipes.',
                $this->plural($offer, 'place de créneau', 'places de créneau'),
                $this->plural($demand, 'séance', 'séances'),
                1 === $demand ? '' : 's',
                $this->plural($offer - $demand, 'place', 'places'),
                1 === $offer - $demand ? 'pourrait' : 'pourraient',
                1 === $offer - $demand ? '' : 's',
            )];
        }

        return [];
    }

    /**
     * P3-19 — l'algèbre vit dans {@see PayloadCapacityMirror} (avec son HISTOIRE,
     * revue #341), gardée par le test de parité cross-stack contre le vrai moteur.
     *
     * @param array<string, mixed> $payload
     */
    private function payloadOffer(array $payload): int
    {
        return $this->capacityMirror->offer($payload);
    }

    /**
     * PR A — saturation des « au moins N ici » par gymnase. L'ALGÈBRE vit dans
     * {@see PayloadCapacityMirror} (parité épinglée contre le vrai moteur) ; ici,
     * seulement la PHRASE que lit le gestionnaire.
     *
     * @param array<string, mixed> $payload
     *
     * @return list<string>
     */
    private function venueMinimumSaturation(array $payload): array
    {
        return array_map(
            fn (array $s): string => \sprintf(
                'Vos contraintes « au moins » réclament %s à %s, mais il n\'y reste que %s libre%s une fois les réservations déduites : la génération échouera. Ajoutez des créneaux dans ce gymnase, libérez des réservations, ou assouplissez ces contraintes.',
                $this->plural($s['demand'], 'place de créneau', 'places de créneau'),
                $s['venueName'],
                $this->plural($s['free'], 'place', 'places'),
                1 === $s['free'] ? '' : 's',
            ),
            $this->capacityMirror->venueMinimumSaturation($payload),
        );
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
     * @param list<Reservation> $reservations le périmètre EXACT qui partira au solveur
     *
     * @return list<string>
     */
    private function overBookedTeamBlockers(array $reservations, string $clubId, string $seasonId): array
    {
        if ([] === $reservations) {
            return [];
        }

        $countByTeam = [];
        foreach ($reservations as $reservation) {
            $countByTeam[$reservation->getTeamId()] = ($countByTeam[$reservation->getTeamId()] ?? 0) + 1;
        }

        $blockers = [];
        foreach ($this->teamRepository->findBy(['clubId' => $clubId, 'seasonId' => $seasonId]) as $team) {
            $reserved = $countByTeam[$team->getId()] ?? 0;
            $sessions = $team->getSessionsPerWeek();
            if ($reserved <= $sessions) {
                continue;
            }
            $blockers[] = \sprintf(
                '%s a %s pour %s par semaine : retirez %s, ou augmentez le nombre de séances de l\'équipe.',
                $team->getName(),
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
     * le solveur recevra, gymnases désactivés déjà retirés. Sans payload (période sans
     * plan), on ne sait rien — on n'invente pas un bloqueur.
     *
     * @param list<Reservation>         $reservations
     * @param array<string, mixed>|null $payload
     *
     * @return list<string>
     */
    private function orphanReservationBlockers(array $reservations, ?array $payload): array
    {
        if ([] === $reservations || null === $payload) {
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

        // Les noms d'équipe ne sont chargés QUE s'il y a un orphelin à nommer : le cas
        // normal (aucun) ne paie pas la requête.
        $teamNames = [];
        $blockers = [];
        foreach ($reservations as $reservation) {
            $key = $this->capacityMirror->key($reservation->getVenueId(), $reservation->getDayOfWeek(), $reservation->getStartTime()->format('H:i'));
            if (isset($grid[$key])) {
                continue;
            }
            if ([] === $teamNames) {
                foreach ($this->teamRepository->findBy(['clubId' => $reservation->getClubId(), 'seasonId' => $reservation->getSeasonId()]) as $team) {
                    $teamNames[$team->getId()] = $team->getName();
                }
            }
            $blockers[] = \sprintf(
                'La réservation de %s à %s le %s à %s ne correspond à aucun créneau : ce créneau a été déplacé ou supprimé. Retirez-la depuis le récapitulatif, ou recréez le créneau.',
                $teamNames[$reservation->getTeamId()] ?? 'cette équipe',
                $venueNames[$reservation->getVenueId()] ?? 'ce gymnase',
                self::DAY_NAMES[$reservation->getDayOfWeek()] ?? 'ce jour',
                $reservation->getStartTime()->format('H\hi'),
            );
        }

        return $blockers;
    }

    private function plural(int $count, string $singular, string $plural): string
    {
        return \sprintf('%d %s', $count, 1 === $count ? $singular : $plural);
    }

    private function requestedCalendarEntryId(?Request $request): ?string
    {
        $content = $request?->getContent();
        if (!\is_string($content) || '' === $content) {
            return null;
        }
        $data = json_decode($content, true);
        $id = \is_array($data) ? ($data['calendarEntryId'] ?? null) : null;

        return \is_string($id) && '' !== $id ? $id : null;
    }

    /**
     * P2-14 — le gate ne recopie plus la sélection : il la DEMANDE à la source unique
     * (`PeriodConstraintSelector`), la même que `buildForPeriodPlan`. Ce qui reste ici est
     * l'habillage : transformer les drops en messages pour le gestionnaire.
     *
     * @return array{constraints: list<Constraint>, warnings: list<string>, selection: PeriodConstraintSelection|null}
     */
    private function constraintsForPeriod(string $clubId, string $seasonId, CalendarEntry $calendarEntry): array
    {
        // Sans plan à appliquer, le gate valide les datées brutes : période non génératrice
        // (cutoff/event — inv. 9 : elles ne portent jamais de plan), ou période génératrice
        // sur une donnée antérieure au lot C1 (le plan naît du geste).
        $datedOnly = function () use ($clubId, $calendarEntry): array {
            /** @var list<Constraint> $dated */
            $dated = $this->constraintRepository->findBy(['calendarEntryId' => $calendarEntry->datedConstraintSourceId(), 'clubId' => $clubId]);

            return ['constraints' => $dated, 'warnings' => [], 'selection' => null];
        };

        $periodType = $calendarEntry->getPeriodType();
        if (!\in_array($periodType, [CalendarEntryPeriodType::CLOSURE, CalendarEntryPeriodType::HOLIDAY], true)) {
            return $datedOnly();
        }

        $schedulePlanId = $this->schedulePlanProvisioner->periodPlanId($calendarEntry->getId());
        if (null === $schedulePlanId) {
            return $datedOnly();
        }

        $selection = $this->periodConstraintSelector->selectForPeriodPlan($clubId, $seasonId, $schedulePlanId, $calendarEntry);

        // Une contrainte sortie pour gymnase désactivé ne partira pas au solveur : elle
        // sort donc aussi du gate (sinon le récap valide un jeu que le payload n'aura
        // pas), et le dirigeant en est AVERTI plutôt que de la voir disparaître en silence.
        $venueIds = array_values(array_unique(array_map(
            static fn (array $drop): string => $drop['venueId'],
            [...$selection->droppedForDisabledVenue, ...$selection->partiallyAppliedForDisabledVenue],
        )));
        $venueNames = $this->venueNames($venueIds);
        $warnings = array_map(
            static fn (array $drop): string => \sprintf(
                '« %s » vise le gymnase %s, désactivé pour cette période : elle ne sera pas appliquée.',
                $drop['constraint']->getName(),
                $venueNames[$drop['venueId']] ?? $drop['venueId'],
            ),
            $selection->droppedForDisabledVenue,
        );
        // KEEP ne veut pas dire INTACTE : une CLUB+tag gardée pour sa seule exclusivité de
        // gymnase dédié a perdu ses règles par équipe — l'ancien gate avertissait, on aussi.
        foreach ($selection->partiallyAppliedForDisabledVenue as $partial) {
            $warnings[] = \sprintf(
                '« %s » vise le gymnase %s, désactivé pour cette période : ses règles par équipe ne seront pas appliquées (seule l\'exclusivité du gymnase dédié est conservée).',
                $partial['constraint']->getName(),
                $venueNames[$partial['venueId']] ?? $partial['venueId'],
            );
        }
        // Une DATÉE dont le tag ne vise plus aucune équipe active est un geste explicite
        // du gestionnaire pour CETTE période : elle est annoncée, jamais évaporée (#8).
        foreach ($selection->droppedForInertTag as $inert) {
            $warnings[] = \sprintf(
                '« %s » ne vise plus aucune équipe active de la période : elle ne sera pas appliquée.',
                $inert->getName(),
            );
        }

        return ['constraints' => $selection->kept, 'warnings' => $warnings, 'selection' => $selection];
    }

    /**
     * Les réservations qui partiront RÉELLEMENT au solveur — le même périmètre que
     * `ScheduleConstraintBuilder`, jamais un autre : le socle ne lit que les siennes
     * (`schedulePlanId IS NULL`) et une période que les siennes, gymnases désactivés
     * retirés. Mélanger les deux inventerait des conflits entre mondes disjoints ;
     * bloquer sur une réservation qui ne part pas au solveur enfermerait le
     * gestionnaire sur un problème qui n'existe pas.
     *
     * @return list<Reservation>
     */
    private function reservationsInScope(string $clubId, string $seasonId, ?string $calendarEntryId, ?PeriodConstraintSelection $selection): array
    {
        $repository = $this->entityManager->getRepository(Reservation::class);

        if (null === $calendarEntryId) {
            return $repository->findBy(
                ['clubId' => $clubId, 'seasonId' => $seasonId, 'schedulePlanId' => null],
                ['id' => 'ASC'],
            );
        }

        // P2-14 : le plan et ses gymnases désactivés viennent de la SÉLECTION déjà
        // calculée — les re-résoudre ici était la 2e copie du même filtre. Pas de
        // sélection (période sans plan, ou non génératrice) : rien ne partira au solveur.
        if (!$selection instanceof PeriodConstraintSelection) {
            return [];
        }

        return array_values(array_filter(
            $repository->findBy(['schedulePlanId' => $selection->schedulePlanId], ['id' => 'ASC']),
            static fn (Reservation $reservation): bool => !isset($selection->disabledVenueIds[$reservation->getVenueId()]),
        ));
    }

    /**
     * @param list<string> $venueIds
     *
     * @return array<string, string>
     */
    private function venueNames(array $venueIds): array
    {
        if ([] === $venueIds) {
            return [];
        }

        $names = [];
        foreach ($this->venueRepository->findBy(['id' => $venueIds]) as $venue) {
            $names[$venue->getId()] = $venue->getName();
        }

        return $names;
    }
}
