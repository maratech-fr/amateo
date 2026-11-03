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
use App\Service\GenerationBlockersGuard;
use App\Service\PayloadCapacityMirror;
use App\Service\PeriodConstraintSelection;
use App\Service\PeriodConstraintSelector;
use App\Service\PreSolvePreventionWarnings;
use App\Service\ScheduleConstraintBuilder;
use App\Service\SchedulePlanProvisioner;
use App\Service\SeasonResolver;
use App\Service\SecondSessionSameDayDetector;
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
    use ResolvesCurrentClubTrait;

    public function __construct(
        private readonly ConstraintRepository $constraintRepository,
        private readonly CalendarEntryRepository $calendarEntryRepository,
        private readonly SeasonResolver $seasonResolver,
        private readonly ConstraintValidationService $validationService,
        private readonly RequestStack $requestStack,
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
        private readonly GenerationBlockersGuard $generationBlockersGuard,
        private readonly SecondSessionSameDayDetector $secondSessionSameDayDetector,
    ) {}

    #[Route('/api/constraints/validate', name: 'api_constraints_validate', methods: ['POST'])]
    public function __invoke(): JsonResponse
    {
        // Pure read (nothing is written, nothing is sent): open to every active member — a
        // read-only Member sees the same pre-solve warnings as a manager
        // (MemberReadsAreSideEffectFreeTest guards the "no side effect" claim).
        $request = $this->requestStack->getCurrentRequest();
        $clubId = $this->resolveCurrentClubId($this->requestStack);
        if (null === $clubId) {
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
        // `SchedulePlanProvisioner::structureHashOfPlan`, autre appelant interactif du builder,
        // qui rend `null` plutôt que de lever). La capacité est un CONFORT : sans elle, le récap reste utile.
        $reservations = $this->reservationsInScope($clubId, $seasonId, $calendarEntryId, $selection);
        // Une seule séance par jour, en saison : le Récap désigne aussi les lignes à retirer.
        $secondSession = null === $calendarEntryId ? $this->secondSessionSameDayDetector->detect($reservations) : ['blockers' => [], 'reservationIds' => [], 'teamDays' => []];

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
                    // Le chevauchement d'une équipe déjà couverte par « une seule séance par jour » se tait.
                    ...$this->preventionWarnings->detect(
                        $capacityPayload,
                        array_map(static fn (Reservation $reservation): string => $reservation->getId(), $reservations),
                        $secondSession['teamDays'],
                    ),
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
            // Chantier H — le relais de l'adjoint : une paire dédoublante dont la séance rejointe
            // tolère le retard et qu'un adjoint disponible ouvre est un AVERTISSEMENT, pas un bloqueur.
            ...$this->coachDoubleBookingDetector->detectRelayWarnings($reservations, $constraints, $clubId, $seasonId),
        ];

        // 2. Saturation des « au moins N ici » par gymnase : le moteur exige ces séances sur
        //    les créneaux NON verrouillés, MAIS crédite le plancher des jours déjà verrouillés
        //    de l'équipe à ce gymnase (P4-97 : effective_min = minimum − jours verrouillés).
        //    Décision fondateur 2026-10-30 : si la grille dépasse même sans verrou → BLOQUEUR
        //    (échec certain). Si seules les réservations saturent → AVERTISSEMENT, on génère.
        if (null !== $capacityPayload) {
            $warnings = [...$warnings, ...$this->generationBlockersGuard->venueMinimumSaturation($capacityPayload)['warnings']];
        }

        // P2-9 PR B — IMPOSSIBILITÉ PHYSIQUE : un verrou qui met un coach à deux endroits
        // en même temps. Le solveur ne peut PAS l'attraper (un verrou HARD est pré-placé
        // hors modèle, ALIGN-07), et ce n'est pas une préférence bafouée — le gestionnaire
        // n'a jamais choisi que son coach se dédouble. Décision fondateur : ça BLOQUE.
        $blockers = [
            // La même liste que les guichets `/generate`, `/regenerate` et `/fill` : une seule vérité.
            ...(null !== $capacityPayload
                ? $this->generationBlockersGuard->blockersFor($capacityPayload, $reservations, $constraints, null !== $calendarEntryId)
                : [...$this->generationBlockersGuard->overBookedOnEntities($reservations, $clubId, $seasonId), ...$secondSession['blockers']]),
            ...array_map(
                fn (array $conflict): string => $this->coachDoubleBookingDetector->describeForRecap($conflict),
                // Chantier H — `$constraints` passés : une paire tolérée + adjoint disponible n'est
                // PLUS un bloqueur (elle part en avertissement ci-dessous).
                $this->coachDoubleBookingDetector->detect($reservations, $clubId, $seasonId, $constraints),
            ),
        ];

        // #8 (fondateur 2026-07-24) — un avertissement n'invalide RIEN : « SM1 va ailleurs,
        // on ignore la contrainte, mais on AVERTIT ». `valid` et le code HTTP restent
        // calculés sur les seules erreurs et conflits… et désormais les bloqueurs, qui
        // sont l'exact contraire d'un avertissement.
        $valid = [] === $errors && [] === $conflicts && [] === $blockers;

        // Le volet CAPACITÉ chiffré, ADDITIF : la demande (bloc-aware) et l'offre lues sur le
        // MÊME payload que les avertissements — la source unique du récap. `null` quand aucun
        // payload n'existe (période non génératrice, données antérieures au lot C1, ou build en
        // échec) : le front n'affiche alors rien plutôt qu'un compte inventé. Sert le compteur de
        // carence sur la surface de FERMETURE (PR-4), sans dupliquer le calcul côté front.
        $capacity = null !== $capacityPayload
            ? ['demand' => $this->capacityMirror->demand($capacityPayload), 'offer' => $this->capacityMirror->offer($capacityPayload)]
            : null;

        return $this->json(
            ['valid' => $valid, 'errors' => $errors, 'conflicts' => $conflicts, 'warnings' => $warnings, 'blockers' => $blockers, 'doubledReservationIds' => $secondSession['reservationIds'], 'notices' => $this->coachDoubleBookingDetector->detectHoldNotices($reservations, $constraints, $clubId, $seasonId), 'capacity' => $capacity],
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
        // La demande vit dans le MIROIR partagé (bloc-aware — une séance de bloc réunit N membres
        // sur UNE place, cf. `PayloadCapacityMirror::demand`), gardé par le test de parité.
        $demand = $this->capacityMirror->demand($payload);

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
     * @return array{constraints: list<Constraint>, warnings: list<string>, selection: PeriodConstraintSelection|null}
     */
    private function constraintsForPeriod(string $clubId, string $seasonId, CalendarEntry $calendarEntry): array
    {
        // Sans plan à appliquer, le gate valide les datées brutes : période non génératrice
        // (cutoff/event — inv. 9 : elles ne portent jamais de plan), ou période génératrice
        // sur une donnée antérieure au lot C1 (le plan naît du geste).
        $datedOnly = function () use ($clubId, $calendarEntry): array {
            /** @var list<Constraint> $dated */
            $dated = $this->constraintRepository->findBy(['calendarEntryId' => $calendarEntry->datedConstraintSourceIds(), 'clubId' => $clubId]);

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
