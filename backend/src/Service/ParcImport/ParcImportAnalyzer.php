<?php

declare(strict_types=1);

namespace App\Service\ParcImport;

use App\Entity\Coach;
use App\Entity\Reservation;
use App\Entity\Team;
use App\Service\Basketball\VenueAliasResolver;
use App\Service\Basketball\VenueLabelNormalizer;
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
    ) {}

    /**
     * @param array<string, mixed> $parc le parc décodé, déjà validé en FORME
     *
     * @return array{format: string, recap: array<string, array<string, int>>, sections: array<string, list<array<string, mixed>>>}
     */
    public function analyze(array $parc): array
    {
        $sections = [
            'equipes' => $this->analyzeTeams($this->section($parc, 'equipes')),
            'gymnases' => $this->analyzeVenues($this->section($parc, 'gymnases')),
            'creneaux' => $this->analyzeSlots($this->section($parc, 'creneaux')),
            'mutualisations' => $this->analyzeMutualisations($this->section($parc, 'mutualisations')),
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
    private function analyzeTeams(array $teams): array
    {
        $byId = [];
        foreach ($this->activeTeams() as $team) {
            $byId[$team->getId()] = $team;
        }

        $out = [];
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

            $out[] = $this->line($i, $nom, $statut, $cibleId, $raison, $extra);
        }

        return $out;
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
            $out[] = $this->line($i, $libelle, 'aCreer', null, null);
        }

        return $out;
    }

    /**
     * @param list<mixed> $mutualisations
     *
     * @return list<array<string, mixed>>
     */
    private function analyzeMutualisations(array $mutualisations): array
    {
        $out = [];
        foreach ($mutualisations as $i => $entry) {
            $libelle = $this->stringField($entry, 'libelle');
            $out[] = $this->line($i, $libelle, 'aCreer', null, null);
        }

        return $out;
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
