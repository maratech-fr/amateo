<?php

declare(strict_types=1);

namespace App\Service\ParcImport;

use App\Entity\Club;
use App\Entity\Reservation;
use App\Entity\Season;
use App\Entity\SharedTrainingBlock;
use App\Entity\SharedTrainingBlockTeam;
use App\Entity\Team;
use App\Entity\Venue;
use App\Service\Basketball\VenueAliasResolver;
use App\Service\Basketball\VenueLabelNormalizer;
use App\Service\PlanEntitlements;
use App\Service\SoloReservationBudget;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Lot correctif import IA (« G2 ») PR1 — le DRY-RUN de l'import de parc, RECALÉ sur `parc-v2`. Il
 * prend un parc parc-v2 DÉJÀ validé (forme) par {@see ParcPayloadValidator}, en DÉRIVE les sections
 * via {@see ParcCaseDeriver} (contrat de décisions INCHANGÉ : gymnases / equipes / creneaux /
 * mutualisations / reservations), puis rend PAR SECTION le statut d'appariement de chaque ligne, un
 * récap chiffré, un bloc de FIABILITÉ et les ALERTES transverses. Il N'ÉCRIT RIEN — c'est l'aperçu
 * cochable sur lequel le gestionnaire tranche avant l'import réel ({@see ParcImportExecutor}).
 *
 * Statuts d'une ligne :
 *  - `apparie`  : un unique existant correspond (exact), coché, cibleId posé ;
 *  - `suggere`  : un unique candidat flou (Levenshtein ≤ 2, {@see TeamLabelMatcher}), coché, cibleId posé ;
 *  - `aCreer`   : aucun existant, coché, cibleId null ;
 *  - `decoche`  : ambiguïté (plusieurs candidats) ou idempotence (déjà présent) — décoché, avec raison ;
 *  - `alerte`   : collision à trancher à la main — décoché, jamais un 500.
 *
 * Appariement : gymnases via {@see VenueAliasResolver} ; équipes via {@see TeamLabelMatcher}
 * (correcteur de nommage, jamais filtre). L'étanchéité tenant est assurée par Doctrine/RLS.
 */
final class ParcImportAnalyzer
{
    private const string FORMAT = 'parc-v2';

    /** @var list<array{id: string, nom: string}>|null */
    private ?array $teamPool = null;

    /** @var list<Team>|null */
    private ?array $teams = null;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly VenueAliasResolver $venueResolver,
        private readonly VenueLabelNormalizer $normalizer,
        private readonly SoloReservationBudget $soloReservationBudget,
        private readonly PlanEntitlements $planEntitlements,
        private readonly ParcCaseDeriver $deriver,
        private readonly TeamLabelMatcher $matcher,
    ) {}

    /**
     * @param array<string, mixed> $parc     le parc parc-v2 décodé, déjà validé en FORME
     * @param string               $seasonId la saison résolue ('' si aucune)
     *
     * @return array{format: string, fiabilite: array{taux: float, anomalies: int, bandeau: bool}, recap: array<string, array<string, int>>, sections: array<string, list<array<string, mixed>>>, alertes: list<array{type: string, message: string}>}
     */
    public function analyze(array $parc, Club $club, string $seasonId): array
    {
        $derived = $this->deriver->derive($parc);
        $season = '' !== $seasonId ? $this->entityManager->find(Season::class, $seasonId) : null;

        $sections = [
            'gymnases' => $this->analyzeVenues($derived['gymnases']),
            'equipes' => $this->analyzeTeams($derived['equipes'], $club, $season),
            'creneaux' => $this->analyzeSlots($derived['creneaux']),
            'mutualisations' => $this->analyzeMutualisations($derived['mutualisations'], $club->getId(), $seasonId),
            'reservations' => $this->analyzeReservations($derived['reservations'], $derived['creneaux'], $derived['mutualisations']),
        ];

        $recap = [];
        foreach ($sections as $name => $lines) {
            $recap[$name] = $this->recap($lines);
        }

        $alertes = $derived['alertes'];

        return [
            'format' => self::FORMAT,
            'fiabilite' => $this->fiabilite($sections, $alertes),
            'recap' => $recap,
            'sections' => $sections,
            'alertes' => $alertes,
        ];
    }

    /**
     * D10 — le bloc de fiabilité. `taux` = part des lignes appariables (équipes + gymnases) apparié
     * ou suggéré ; `anomalies` = nombre d'alertes transverses ; `bandeau` « peu fiable » si le taux
     * < 50 % OU au moins 3 anomalies (jamais bloquant).
     *
     * @param array<string, list<array<string, mixed>>>  $sections
     * @param list<array{type: string, message: string}> $alertes
     *
     * @return array{taux: float, anomalies: int, bandeau: bool}
     */
    private function fiabilite(array $sections, array $alertes): array
    {
        $appariable = array_merge($sections['equipes'], $sections['gymnases']);
        $total = \count($appariable);
        $matched = \count(array_filter(
            $appariable,
            static fn (array $line): bool => \in_array($line['statut'] ?? null, ['apparie', 'suggere'], true),
        ));
        $taux = $total > 0 ? round($matched / $total, 4) : 1.0;
        $anomalies = \count($alertes);

        return ['taux' => $taux, 'anomalies' => $anomalies, 'bandeau' => $taux < 0.5 || $anomalies >= 3];
    }

    /**
     * @param list<array{nom: string}> $venues
     *
     * @return list<array<string, mixed>>
     */
    private function analyzeVenues(array $venues): array
    {
        $out = [];
        foreach ($venues as $i => $entry) {
            $nom = $this->stringField($entry, 'nom');
            [$statut, $cibleId, $raison] = $this->verdict($this->venueResolver->exactMatch($nom), $this->venueResolver->candidates($nom));
            $out[] = $this->line($i, $nom, $statut, $cibleId, $raison);
        }

        return $out;
    }

    /**
     * @param list<array{nom: string, genre: string, categorie: string, seances: int}> $teams
     *
     * @return list<array<string, mixed>>
     */
    private function analyzeTeams(array $teams, Club $club, ?Season $season): array
    {
        $byId = [];
        foreach ($this->activeTeams() as $team) {
            $byId[$team->getId()] = $team;
        }

        $rows = [];
        foreach ($teams as $i => $entry) {
            $nom = $this->stringField($entry, 'nom');
            [$statut, $cibleId, $raison] = $this->teamVerdict($nom);

            $extra = [];
            if ('apparie' === $statut && null !== $cibleId && isset($byId[$cibleId])) {
                $current = $byId[$cibleId]->getSessionsPerWeek();
                if ($current !== $entry['seances']) {
                    $extra['miseAJour'] = ['champ' => 'seances', 'de' => $current, 'a' => $entry['seances']];
                }
            }

            $rows[] = ['index' => $i, 'nom' => $nom, 'statut' => $statut, 'cibleId' => $cibleId, 'raison' => $raison, 'extra' => $extra];
        }

        $this->applyTeamCap($rows, $club, $season);

        return array_map(
            fn (array $row): array => $this->line($row['index'], $row['nom'], $row['statut'], $row['cibleId'], $row['raison'], $row['extra']),
            $rows,
        );
    }

    /**
     * Le verdict d'une équipe via {@see TeamLabelMatcher} (correcteur, jamais filtre) : apparié /
     * suggéré / à créer tels quels ; une AMBIGUÏTÉ (≥ 2 candidats dans le seuil) devient `decoche`.
     *
     * @return array{0: string, 1: string|null, 2: string|null} [statut, cibleId, raison]
     */
    private function teamVerdict(string $label): array
    {
        $verdict = $this->matcher->match($label, $this->teamPool());
        if ('ambigu' === $verdict['statut']) {
            return ['decoche', null, 'Plusieurs correspondances possibles — appariement ambigu, à choisir à la main.'];
        }

        return [$verdict['statut'], $verdict['cibleId'], null];
    }

    /**
     * Passe les équipes « à créer » au-delà des places restantes de l'offre en DÉCOCHÉ.
     *
     * @param list<array{index: int, nom: string, statut: string, cibleId: string|null, raison: string|null, extra: array<string, mixed>}> $rows
     */
    private function applyTeamCap(array &$rows, Club $club, ?Season $season): void
    {
        if (!$season instanceof Season) {
            return;
        }
        $remaining = $this->planEntitlements->remainingTeamSlots($club, $season);
        if (null === $remaining) {
            return;
        }

        $toCreate = \count(array_filter($rows, static fn (array $row): bool => 'aCreer' === $row['statut']));
        if ($toCreate <= $remaining) {
            return;
        }

        $message = $this->planEntitlements->teamCapExceededMessage($club, $season, $toCreate)
            ?? 'Le nombre d\'équipes dépasse la limite de votre offre.';
        $allowed = $remaining;
        foreach ($rows as &$row) {
            if ('aCreer' !== $row['statut']) {
                continue;
            }
            if ($allowed > 0) {
                --$allowed;

                continue;
            }
            $row['statut'] = 'decoche';
            $row['cibleId'] = null;
            $row['raison'] = $message;
        }
        unset($row);
    }

    /**
     * @param list<array{gymnase: string, jour: int, debut: string, duree: int, equipes_en_parallele: int}> $slots
     *
     * @return list<array<string, mixed>>
     */
    private function analyzeSlots(array $slots): array
    {
        $windows = [];
        foreach ($slots as $i => $slot) {
            $windows[$i] = $this->slotWindow($slot);
        }

        $out = [];
        foreach ($slots as $i => $slot) {
            $libelle = $this->slotLabel($slot);
            if ($this->slotOverlapsAnother($i, $windows)) {
                $out[] = $this->line($i, $libelle, 'alerte', null, 'Ce créneau en chevauche un autre du même gymnase le même jour — à trancher à la main.');

                continue;
            }
            if ($this->splitOnNonSplittableVenue($slot)) {
                $out[] = $this->line($i, $libelle, 'alerte', null, 'Ce créneau accueille 2 équipes ou plus, mais le gymnase existant n\'est pas déclaré divisible — rendez-le divisible ou ramenez la capacité à 1.');

                continue;
            }
            $out[] = $this->line($i, $libelle, 'aCreer', null, null);
        }

        return $out;
    }

    private function splitOnNonSplittableVenue(mixed $slot): bool
    {
        if (!\is_array($slot)) {
            return false;
        }
        $parallel = $slot['equipes_en_parallele'] ?? null;
        if (!\is_int($parallel) || $parallel < 2) {
            return false;
        }

        $venueId = $this->resolveVenueId($this->stringField($slot, 'gymnase'));
        if (null === $venueId) {
            return false; // gymnase à créer : canSplit dérivé, jamais une alerte
        }

        $venue = $this->entityManager->find(Venue::class, $venueId);

        return $venue instanceof Venue && !$venue->getCanSplit();
    }

    /**
     * @param list<array{equipes: list<string>, groupe: string|null, gymnase: string, jour: int, debut: string, duree: int, seances_communes: int}> $mutualisations
     *
     * @return list<array<string, mixed>>
     */
    private function analyzeMutualisations(array $mutualisations, string $clubId, string $seasonId): array
    {
        $existingBlocks = '' !== $seasonId ? $this->socleBlocks($clubId, $seasonId) : [];

        $out = [];
        foreach ($mutualisations as $i => $entry) {
            $libelle = $this->mutualisationLabel($entry);
            $groupe = $entry['groupe'];
            // P4-363 — gymnase RÉSOLU depuis le NOM de la case (null si à créer) : amorce le sélecteur
            // de gymnase de la ligne côté front, SANS jamais confondre ce gymnase avec le `cibleId`
            // (qui, pour une mutualisation, désigne le BLOC apparié).
            $extra = ['groupe' => $groupe, 'gymnaseId' => $this->resolveVenueId($this->stringField($entry, 'gymnase'))];
            $teamNames = $this->stringList($entry['equipes']);
            $commonSessions = $entry['seances_communes'];

            $teamIds = [];
            $allResolved = true;
            foreach ($teamNames as $name) {
                $id = $this->resolveTeamId($name);
                if (null === $id) {
                    $allResolved = false;
                } else {
                    $teamIds[] = $id;
                }
            }

            $matchedBlockId = null;
            if ($allResolved && [] !== $teamIds) {
                $set = $this->sortedUnique($teamIds);
                foreach ($existingBlocks as $block) {
                    if ($block['members'] === $set) {
                        $matchedBlockId = $block['blockId'];

                        break;
                    }
                }
            }

            if ($allResolved && [] !== $teamIds && '' !== $seasonId
                && $this->blockExceedsMemberSessions($teamIds, $clubId, $seasonId, $commonSessions, $matchedBlockId)) {
                $out[] = $this->line($i, $libelle, 'alerte', null, 'Les séances communes de cette mutualisation dépassent les séances hebdomadaires d\'une des équipes — à ajuster à la main.', $extra);

                continue;
            }

            if (null !== $matchedBlockId) {
                $out[] = $this->line($i, $libelle, 'apparie', $matchedBlockId, null, $extra);

                continue;
            }
            $out[] = $this->line($i, $libelle, 'aCreer', null, null, $extra);
        }

        return $out;
    }

    /**
     * @return list<array{blockId: string, members: list<string>}>
     */
    private function socleBlocks(string $clubId, string $seasonId): array
    {
        $blocks = $this->entityManager->getRepository(SharedTrainingBlock::class)
            ->findBy(['clubId' => $clubId, 'seasonId' => $seasonId, 'schedulePlanId' => null]);
        $memberRepository = $this->entityManager->getRepository(SharedTrainingBlockTeam::class);

        $out = [];
        foreach ($blocks as $block) {
            $members = [];
            foreach ($memberRepository->findBy(['blockId' => $block->getId()]) as $member) {
                $members[] = $member->getTeamId();
            }
            $out[] = ['blockId' => $block->getId(), 'members' => $this->sortedUnique($members)];
        }

        return $out;
    }

    /**
     * @param list<string> $teamIds
     */
    private function blockExceedsMemberSessions(array $teamIds, string $clubId, string $seasonId, int $commonSessions, ?string $matchedBlockId): bool
    {
        $budgets = $this->soloReservationBudget->forTeamsWithBlockSubstituted(
            $teamIds,
            $clubId,
            $seasonId,
            null,
            $matchedBlockId,
            $commonSessions,
        );
        foreach ($budgets as $budget) {
            if ($budget->block > $budget->effective) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array{equipe: string, gymnase: string, jour: int, debut: string, duree: int}>                                                    $reservations
     * @param list<array{gymnase: string, jour: int, debut: string, duree: int, equipes_en_parallele: int}>                                         $slots
     * @param list<array{equipes: list<string>, groupe: string|null, gymnase: string, jour: int, debut: string, duree: int, seances_communes: int}> $mutualisations
     *
     * @return list<array<string, mixed>>
     */
    private function analyzeReservations(array $reservations, array $slots, array $mutualisations): array
    {
        $contexts = [];
        $byCase = [];
        foreach ($reservations as $i => $entry) {
            $contexts[$i] = $this->reservationContext($entry);
            $case = $contexts[$i]['caseKey'];
            if (null !== $case) {
                $byCase[$case][] = $i;
            }
        }

        $splitCases = $this->caseKeysOf($slots, onlySplit: true);
        $mutualisedCases = $this->caseKeysOf($mutualisations, onlySplit: false);

        $out = [];
        foreach ($reservations as $i => $entry) {
            $ctx = $contexts[$i];
            $libelle = $this->reservationLabel($entry);
            $case = $ctx['caseKey'];

            $contested = null !== $case
                && \count($byCase[$case] ?? []) >= 2
                && !\in_array($case, $splitCases, true)
                && !\in_array($case, $mutualisedCases, true);
            if ($contested) {
                $out[] = $this->line(
                    $i,
                    $libelle,
                    'alerte',
                    null,
                    'Deux équipes sur la même case sans mutualisation ni terrain divisé déclaré : mutualisation ? terrain divisé en deux ? erreur de lecture ? À trancher à la main.',
                );

                continue;
            }

            if ($this->reservationAlreadyExists($ctx)) {
                $out[] = $this->line($i, $libelle, 'decoche', null, 'Réservation déjà présente dans le planning.');

                continue;
            }

            $out[] = $this->line($i, $libelle, 'aCreer', null, null);
        }

        return $out;
    }

    /**
     * Le verdict d'appariement commun aux gymnases : exact unique → apparié ; un candidat flou →
     * suggéré ; deux ou plus → décoché (ambigu) ; aucun → à créer.
     *
     * @param list<string> $candidates
     *
     * @return array{0: string, 1: string|null, 2: string|null}
     */
    private function verdict(?string $exact, array $candidates): array
    {
        if (null !== $exact) {
            return ['apparie', $exact, null];
        }

        $candidates = array_values(array_unique($candidates));
        if (1 === \count($candidates)) {
            return ['suggere', $candidates[0], null];
        }
        if (\count($candidates) >= 2) {
            return ['decoche', null, 'Plusieurs correspondances possibles — appariement ambigu, à choisir à la main.'];
        }

        return ['aCreer', null, null];
    }

    /**
     * @param array<int, array{venueKey: string, day: int, start: int, end: int}|null> $windows
     */
    private function slotOverlapsAnother(int $index, array $windows): bool
    {
        $window = $windows[$index] ?? null;
        if (null === $window) {
            return false;
        }

        foreach ($windows as $j => $other) {
            if ($j === $index || null === $other) {
                continue;
            }
            if ($other['venueKey'] === $window['venueKey']
                && $other['day'] === $window['day']
                && $window['start'] < $other['end']
                && $other['start'] < $window['end']) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{venueKey: string, day: int, start: int, end: int}|null
     */
    private function slotWindow(mixed $slot): ?array
    {
        if (!\is_array($slot)) {
            return null;
        }
        $day = $slot['jour'] ?? null;
        $duration = $slot['duree'] ?? null;
        $start = $this->minutesOfTime($this->stringField($slot, 'debut'));
        if (!\is_int($day) || !\is_int($duration) || null === $start) {
            return null;
        }

        return [
            'venueKey' => $this->venueKey($this->stringField($slot, 'gymnase')),
            'day' => $day,
            'start' => $start,
            'end' => $start + $duration,
        ];
    }

    /**
     * @param list<array<string, mixed>> $entries
     *
     * @return list<string>
     */
    private function caseKeysOf(array $entries, bool $onlySplit): array
    {
        $keys = [];
        foreach ($entries as $entry) {
            if ($onlySplit) {
                $parallel = $entry['equipes_en_parallele'] ?? null;
                if (!\is_int($parallel) || $parallel < 2) {
                    continue;
                }
            }
            $day = $entry['jour'] ?? null;
            $start = $this->minutesOfTime($this->stringField($entry, 'debut'));
            if (!\is_int($day) || null === $start) {
                continue;
            }
            $keys[] = $this->caseKey($this->venueKey($this->stringField($entry, 'gymnase')), $day, $start);
        }

        return $keys;
    }

    /**
     * @return array{teamId: string|null, venueId: string|null, day: int|null, startTime: string, caseKey: string|null}
     */
    private function reservationContext(mixed $reservation): array
    {
        $r = \is_array($reservation) ? $reservation : [];
        $day = $r['jour'] ?? null;
        $day = \is_int($day) ? $day : null;
        $startTime = $this->stringField($r, 'debut');
        $startMinutes = $this->minutesOfTime($startTime);

        $venueId = $this->resolveVenueId($this->stringField($r, 'gymnase'));
        $venueKey = $this->venueKey($this->stringField($r, 'gymnase'));
        $teamId = $this->resolveTeamId($this->stringField($r, 'equipe'));

        $caseKey = (null !== $day && null !== $startMinutes) ? $this->caseKey($venueKey, $day, $startMinutes) : null;

        return ['teamId' => $teamId, 'venueId' => $venueId, 'day' => $day, 'startTime' => $startTime, 'caseKey' => $caseKey];
    }

    /**
     * @param array{teamId: string|null, venueId: string|null, day: int|null, startTime: string, caseKey: string|null} $ctx
     */
    private function reservationAlreadyExists(array $ctx): bool
    {
        if (null === $ctx['teamId'] || null === $ctx['venueId'] || null === $ctx['day'] || '' === $ctx['startTime']) {
            return false;
        }

        /** @var list<Reservation> $existing */
        $existing = $this->entityManager->getRepository(Reservation::class)->findBy([
            'teamId' => $ctx['teamId'],
            'venueId' => $ctx['venueId'],
            'dayOfWeek' => $ctx['day'],
            'schedulePlanId' => null,
        ]);

        foreach ($existing as $reservation) {
            if ($reservation->getStartTime()->format('H:i') === $ctx['startTime']) {
                return true;
            }
        }

        return false;
    }

    private function resolveVenueId(string $gym): ?string
    {
        $exact = $this->venueResolver->exactMatch($gym);
        if (null !== $exact) {
            return $exact;
        }
        $candidates = $this->venueResolver->candidates($gym);

        return 1 === \count($candidates) ? $candidates[0] : null;
    }

    private function resolveTeamId(string $name): ?string
    {
        // Résolution AUTOMATIQUE (contexte des blocs / réservations) : SEUL un appariement EXACT
        // résout l'équipe. Un candidat FLOU (« suggere ») ne s'applique jamais d'office (D5) — il
        // n'apparie une ligne qu'avec la confirmation du gestionnaire (cibleId de la décision).
        $verdict = $this->matcher->match($name, $this->teamPool());

        return 'apparie' === $verdict['statut'] ? $verdict['cibleId'] : null;
    }

    private function venueKey(string $gym): string
    {
        return $this->resolveVenueId($gym) ?? 'name:' . $this->normalizer->normalize($gym);
    }

    private function caseKey(string $venueKey, int $day, int $startMinutes): string
    {
        return $venueKey . '|' . $day . '|' . $startMinutes;
    }

    private function minutesOfTime(string $hhmm): ?int
    {
        if (1 !== preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $hhmm, $m)) {
            return null;
        }

        return (int) $m[1] * 60 + (int) $m[2];
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function line(int $index, string $libelle, string $statut, ?string $cibleId, ?string $raison, array $extra = []): array
    {
        return [
            'index' => $index,
            'libelle' => $libelle,
            'statut' => $statut,
            'coche' => \in_array($statut, ['apparie', 'suggere', 'aCreer'], true),
            'cibleId' => $cibleId,
            'raison' => $raison,
        ] + $extra;
    }

    /**
     * @param list<array<string, mixed>> $lines
     *
     * @return array<string, int>
     */
    private function recap(array $lines): array
    {
        $recap = ['total' => \count($lines), 'apparie' => 0, 'suggere' => 0, 'aCreer' => 0, 'decoche' => 0, 'alerte' => 0];
        foreach ($lines as $line) {
            $statut = $line['statut'] ?? null;
            if (\is_string($statut) && isset($recap[$statut])) {
                ++$recap[$statut];
            }
        }

        return $recap;
    }

    private function stringField(mixed $entry, string $field): string
    {
        if (\is_array($entry) && \is_string($entry[$field] ?? null)) {
            return $entry[$field];
        }

        return '';
    }

    private function slotLabel(mixed $slot): string
    {
        $gym = $this->stringField($slot, 'gymnase');
        $debut = $this->stringField($slot, 'debut');
        $day = \is_array($slot) && \is_int($slot['jour'] ?? null) ? (string) $slot['jour'] : '';

        return trim($gym . ' · jour ' . $day . ' ' . $debut);
    }

    private function reservationLabel(mixed $reservation): string
    {
        $equipe = $this->stringField($reservation, 'equipe');
        $gym = $this->stringField($reservation, 'gymnase');
        $debut = $this->stringField($reservation, 'debut');

        return trim($equipe . ' · ' . $gym . ' ' . $debut);
    }

    private function mutualisationLabel(mixed $mutualisation): string
    {
        $teams = \is_array($mutualisation) ? $this->stringList($mutualisation['equipes'] ?? null) : [];
        if ([] !== $teams) {
            return implode(' + ', $teams);
        }

        return $this->stringField($mutualisation, 'gymnase');
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (\is_string($item) && '' !== trim($item)) {
                $out[] = $item;
            }
        }

        return $out;
    }

    /**
     * @param list<string> $ids
     *
     * @return list<string>
     */
    private function sortedUnique(array $ids): array
    {
        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }

    /**
     * @return list<array{id: string, nom: string}>
     */
    private function teamPool(): array
    {
        if (null === $this->teamPool) {
            $this->teamPool = array_map(
                static fn (Team $team): array => ['id' => $team->getId(), 'nom' => $team->getName()],
                $this->activeTeams(),
            );
        }

        return $this->teamPool;
    }

    /**
     * @return list<Team>
     */
    private function activeTeams(): array
    {
        if (null === $this->teams) {
            $this->teams = array_values(array_filter(
                $this->entityManager->getRepository(Team::class)->findAll(),
                static fn (Team $team): bool => $team->getIsActive(),
            ));
        }

        return $this->teams;
    }
}
