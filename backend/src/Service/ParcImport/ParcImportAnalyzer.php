<?php

declare(strict_types=1);

namespace App\Service\ParcImport;

use App\Entity\Club;
use App\Entity\Coach;
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
 * Chantier G — le DRY-RUN de l'import de parc : il prend un parc `parc-v1` DÉJÀ validé
 * (forme) par {@see ParcPayloadValidator} et rend, PAR SECTION, le statut d'appariement de
 * chaque ligne plus un récap chiffré. Il N'ÉCRIT RIEN — c'est l'aperçu cochable sur lequel
 * le gestionnaire tranche avant l'import réel (PR suivante). L'écriture transactionnelle et
 * le rejeu complet des gardes de persistance sont le métier de cette PR suivante.
 *
 * Statuts d'une ligne :
 *  - `apparie`  : un unique existant correspond (nom normalisé exact), coché, cibleId posé ;
 *  - `suggere`  : un unique candidat flou, coché, cibleId posé (le gestionnaire confirme) ;
 *  - `aCreer`   : aucun existant, coché, cibleId null (il sera créé) ;
 *  - `decoche`  : rien à faire sans décision humaine — ambiguïté (plusieurs candidats) ou
 *                 idempotence (déjà présent) — décoché, avec une raison ;
 *  - `alerte`   : collision à trancher à la main (deux équipes sur une même case non déclarée,
 *                 chevauchement de créneaux) — décoché, jamais un 500.
 *
 * Appariement : gymnases via {@see VenueAliasResolver} (nom/alias, exact puis flou unique) ;
 * équipes et coachs via la MÊME normalisation ({@see VenueLabelNormalizer}). L'étanchéité
 * tenant est assurée par Doctrine/RLS (les dépôts ne voient que le club+saison résolus) :
 * un homonyme d'un autre club ne s'apparie jamais.
 */
final class ParcImportAnalyzer
{
    private const string FORMAT = 'parc-v1';

    /** @var list<array{id: string, name: string}>|null */
    private ?array $teamPool = null;

    /** @var list<Team>|null */
    private ?array $teams = null;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly VenueAliasResolver $venueResolver,
        private readonly VenueLabelNormalizer $normalizer,
        private readonly SoloReservationBudget $soloReservationBudget,
        private readonly PlanEntitlements $planEntitlements,
    ) {}

    /**
     * @param array<string, mixed> $parc     le parc décodé, déjà validé en FORME
     * @param string               $seasonId la saison résolue ('' si aucune) — pour le cap payant
     *                                       et le rejeu des gardes de mutualisation
     *
     * @return array{format: string, recap: array<string, array<string, int>>, sections: array<string, list<array<string, mixed>>>}
     */
    public function analyze(array $parc, Club $club, string $seasonId): array
    {
        $season = '' !== $seasonId ? $this->entityManager->find(Season::class, $seasonId) : null;

        $sections = [
            'equipes' => $this->analyzeTeams($this->section($parc, 'equipes'), $club, $season),
            'gymnases' => $this->analyzeVenues($this->section($parc, 'gymnases')),
            'creneaux' => $this->analyzeSlots($this->section($parc, 'creneaux')),
            'mutualisations' => $this->analyzeMutualisations($this->section($parc, 'mutualisations'), $club->getId(), $seasonId),
            'coachs' => $this->analyzeCoaches($this->section($parc, 'coachs')),
            'reservations' => $this->analyzeReservations(
                $this->section($parc, 'reservations'),
                $this->section($parc, 'creneaux'),
                $this->section($parc, 'mutualisations'),
            ),
        ];

        $recap = [];
        foreach ($sections as $name => $lines) {
            $recap[$name] = $this->recap($lines);
        }

        return ['format' => self::FORMAT, 'recap' => $recap, 'sections' => $sections];
    }

    /**
     * @param array<string, mixed> $parc
     *
     * @return list<mixed>
     */
    private function section(array $parc, string $name): array
    {
        $value = $parc[$name] ?? null;

        return \is_array($value) && array_is_list($value) ? $value : [];
    }

    /**
     * @param list<mixed> $teams
     *
     * @return list<array<string, mixed>>
     */
    private function analyzeTeams(array $teams, Club $club, ?Season $season): array
    {
        $byId = [];
        foreach ($this->activeTeams() as $team) {
            $byId[$team->getId()] = $team;
        }

        // On calcule d'abord le verdict d'appariement de chaque ligne, puis on applique le cap
        // payant aux SEULES lignes « à créer » (une équipe appariée existe déjà, elle ne consomme
        // aucune place neuve). Le cap vient du MÊME foyer que le refus d'écriture (PlanEntitlements) :
        // les équipes en surplus sont DÉCOCHÉES avec une raison qui nomme l'offre — dire par ligne
        // lesquelles ne seront pas créées, plutôt qu'une alerte de récap sans ancrage.
        $rows = [];
        foreach ($teams as $i => $entry) {
            $nom = $this->stringField($entry, 'nom');
            [$statut, $cibleId, $raison] = $this->poolVerdict($nom, $this->teamPool());

            $extra = [];
            $seances = \is_array($entry) ? ($entry['seances'] ?? null) : null;
            if ('apparie' === $statut && null !== $cibleId && \is_int($seances)) {
                $current = $byId[$cibleId]->getSessionsPerWeek();
                if ($current !== $seances) {
                    $extra['miseAJour'] = ['champ' => 'seances', 'de' => $current, 'a' => $seances];
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
     * Passe les équipes « à créer » au-delà des places restantes de l'offre en DÉCOCHÉ, avec le
     * message de l'offre. `remainingTeamSlots` null = offre illimitée → rien à borner. La raison
     * réutilise {@see PlanEntitlements::teamCapExceededMessage} (le même texte que le refus à
     * l'écriture, même palier supérieur nommé).
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
     * @param list<mixed> $venues
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
     * @param list<mixed> $slots
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

    /**
     * La garde de capacité ({@see VenueTrainingSlotStateProcessor}) rejouée au dry-run : une
     * capacité ≥ 2 n'est permise que sur un gymnase divisible. On ne l'applique qu'à un gymnase
     * qui EXISTE DÉJÀ et dont `canSplit` est false ; un gymnase À CRÉER dérivera `canSplit` de la
     * capacité demandée (il naîtra divisible), donc aucune alerte — sinon on bloquerait un terrain
     * qu'on s'apprête justement à créer divisible.
     */
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
     * @param list<mixed> $mutualisations
     *
     * @return list<array<string, mixed>>
     */
    private function analyzeMutualisations(array $mutualisations, string $clubId, string $seasonId): array
    {
        $existingBlocks = '' !== $seasonId ? $this->socleBlocks($clubId, $seasonId) : [];

        $out = [];
        foreach ($mutualisations as $i => $entry) {
            $libelle = $this->mutualisationLabel($entry);
            $teamNames = \is_array($entry) ? $this->stringList($entry['equipes'] ?? null) : [];
            $commonSessions = \is_array($entry) && \is_int($entry['seances_communes'] ?? null) ? $entry['seances_communes'] : 1;

            // Appariement : un groupe dont l'ensemble d'équipes RÉSOUT exactement un bloc existant
            // s'apparie à ce bloc. Si une équipe membre reste à créer, aucun bloc ne peut matcher.
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

            // Garde du bloc (Σ séances communes ≤ séances d'un membre) rejouée depuis sa MAISON
            // ({@see SoloReservationBudget}, celle que SharedTrainingBlockStateProcessor consulte) :
            // une violation passe en alerte, jamais un 500.
            if ($allResolved && [] !== $teamIds && '' !== $seasonId
                && $this->blockExceedsMemberSessions($teamIds, $clubId, $seasonId, $commonSessions, $matchedBlockId)) {
                $out[] = $this->line($i, $libelle, 'alerte', null, 'Les séances communes de cette mutualisation dépassent les séances hebdomadaires d\'une des équipes — à ajuster à la main.');
                continue;
            }

            if (null !== $matchedBlockId) {
                $out[] = $this->line($i, $libelle, 'apparie', $matchedBlockId, null);
                continue;
            }
            $out[] = $this->line($i, $libelle, 'aCreer', null, null);
        }

        return $out;
    }

    /**
     * Les blocs de mutualisation du SOCLE (plan null) avec leur ensemble d'équipes trié — pour
     * apparier un groupe du parc à un bloc déjà formé.
     *
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
     * La garde centrale B(T) ≤ S(T) rejouée pour un bloc proposé : vraie si, pour une équipe du
     * groupe, la somme des séances communes de ses blocs (celui-ci substitué) dépasse ses séances
     * effectives. Délègue au foyer unique {@see SoloReservationBudget::forTeamsWithBlockSubstituted}.
     *
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
     * @param list<mixed> $coaches
     *
     * @return list<array<string, mixed>>
     */
    private function analyzeCoaches(array $coaches): array
    {
        $pool = [];
        foreach ($this->activeCoaches() as $coach) {
            $pool[] = ['id' => $coach->getId(), 'name' => trim($coach->getFirstName() . ' ' . $coach->getLastName())];
        }

        $out = [];
        foreach ($coaches as $i => $entry) {
            $nom = $this->stringField($entry, 'nom');
            [$statut, $cibleId, $raison] = $this->poolVerdict($nom, $pool);
            $out[] = $this->line($i, $nom, $statut, $cibleId, $raison);
        }

        return $out;
    }

    /**
     * @param list<mixed> $reservations
     * @param list<mixed> $slots
     * @param list<mixed> $mutualisations
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
     * Le verdict d'appariement commun : exact unique → apparié ; sinon un candidat flou →
     * suggéré ; deux candidats ou plus → décoché (ambigu) ; aucun → à créer.
     *
     * @param list<string> $candidates
     *
     * @return array{0: string, 1: string|null, 2: string|null} [statut, cibleId, raison]
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
     * Le verdict sur un POOL (équipes, coachs) : même décision que {@see verdict}, l'exact et
     * le flou calculés sur la MÊME normalisation que les gymnases (jamais un second repli).
     *
     * @param list<array{id: string, name: string}> $pool
     *
     * @return array{0: string, 1: string|null, 2: string|null}
     */
    private function poolVerdict(string $label, array $pool): array
    {
        $key = $this->normalizer->normalize($label);
        if ('' === $key) {
            return ['aCreer', null, null];
        }

        $exact = [];
        $fuzzy = [];
        foreach ($pool as $candidate) {
            $normalized = $this->normalizer->normalize($candidate['name']);
            if ($normalized === $key) {
                $exact[$candidate['id']] = true;
            }
            if ($normalized === $key || $this->normalizer->fuzzyMatches($candidate['name'], $label)) {
                $fuzzy[$candidate['id']] = true;
            }
        }

        $exactId = 1 === \count($exact) ? array_key_first($exact) : null;

        return $this->verdict($exactId, array_keys($fuzzy));
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
     * Les clés de case (gymnase+jour+heure) d'une liste de créneaux ou de mutualisations.
     * `onlySplit` ne retient que les créneaux déclarés divisibles (`equipes_en_parallele >= 2`),
     * qui AUTORISENT plusieurs équipes sur la même case ; appliqué aux mutualisations, il prend
     * toute case nommée (une mutualisation déclare le partage).
     *
     * @param list<mixed> $entries
     *
     * @return list<string>
     */
    private function caseKeysOf(array $entries, bool $onlySplit): array
    {
        $keys = [];
        foreach ($entries as $entry) {
            if (!\is_array($entry)) {
                continue;
            }
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
        [$statut, $cibleId] = $this->poolVerdict($name, $this->teamPool());

        return \in_array($statut, ['apparie', 'suggere'], true) ? $cibleId : null;
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
     * Les chaînes non vides d'une valeur brute (ignore le reste) — pour lire une liste de noms
     * d'équipes sans dépendre de sa validité de forme.
     *
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
     * @return list<array{id: string, name: string}>
     */
    private function teamPool(): array
    {
        if (null === $this->teamPool) {
            $this->teamPool = array_map(
                static fn (Team $team): array => ['id' => $team->getId(), 'name' => $team->getName()],
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

    /**
     * @return list<Coach>
     */
    private function activeCoaches(): array
    {
        return array_values(array_filter(
            $this->entityManager->getRepository(Coach::class)->findAll(),
            static fn (Coach $coach): bool => $coach->getIsActive(),
        ));
    }
}
