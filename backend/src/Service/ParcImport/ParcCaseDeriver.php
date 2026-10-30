<?php

declare(strict_types=1);

namespace App\Service\ParcImport;

use App\Service\Basketball\VenueLabelNormalizer;

/**
 * Lot correctif import IA (« G2 ») PR1 — service PUR qui DÉRIVE, d'un parc `parc-v2` (une ligne par
 * CASE), la structure PAR SECTION que l'aperçu et l'import savent déjà traiter : gymnases, équipes
 * (genre/catégorie déduits du nom par {@see TeamNameInterpreter}, séances = nombre de cases),
 * créneaux (un par case, durée transportée), réservations (une par équipe des cases NON mutualisées)
 * et mutualisations (une par case multi-équipes sans terrains séparés, étiquetée par son `groupe`).
 *
 * C'est le FOYER UNIQUE de la dérivation : l'analyzer et l'executor l'appellent tous deux, donc le
 * contrat de décisions `{section, index}` désigne LES MÊMES lignes des deux côtés. Il rend aussi les
 * ALERTES transverses (D9) — pures fonctions des cases : une équipe à deux endroits au même moment,
 * une heure implausible, un gymnase déclaré sans aucune case.
 *
 * Service PUR : aucune DB, aucun appariement aux entités du club (l'analyzer/l'executor s'en chargent
 * ensuite sur la structure dérivée).
 */
final class ParcCaseDeriver
{
    private const int DEFAULT_DURATION = 90;

    /** Repère d'un libellé non classé — réexporté depuis {@see TeamNameInterpreter} pour la détection de groupe. */
    private const string UNFILED = TeamNameInterpreter::UNFILED;

    /** Heure de début la plus matinale plausible (minutes) et les bornes de fin (D9). */
    private const int MIN_START_MINUTES = 7 * 60;

    private const int MAX_START_MINUTES = 22 * 60;

    private const int MAX_END_MINUTES = 23 * 60 + 30;

    public function __construct(
        private readonly VenueLabelNormalizer $normalizer,
        private readonly TeamNameInterpreter $interpreter,
    ) {}

    /**
     * @param array<string, mixed> $parc le parc-v2 décodé, déjà validé en FORME
     *
     * @return array{
     *     gymnases: list<array{nom: string}>,
     *     equipes: list<array{nom: string, genre: ?string, categorie: string, seances: int}>,
     *     creneaux: list<array{gymnase: string, jour: int, debut: string, duree: int, equipes_en_parallele: int}>,
     *     reservations: list<array{equipe: string, gymnase: string, jour: int, debut: string, duree: int}>,
     *     mutualisations: list<array{equipes: list<string>, groupe: string|null, gymnase: string, jour: int, debut: string, duree: int, seances_communes: int}>,
     *     alertes: list<array{type: string, message: string}>,
     * }
     */
    public function derive(array $parc): array
    {
        $cases = $this->cases($parc);
        $declaredGyms = $this->declaredGymNames($parc);

        $info = array_map($this->describeCase(...), $cases);

        return [
            'gymnases' => $this->deriveGymnases($declaredGyms, $info),
            'equipes' => $this->deriveTeams($info),
            'creneaux' => $this->deriveSlots($info),
            'reservations' => $this->deriveReservations($info),
            'mutualisations' => $this->deriveMutualisations($info),
            'alertes' => $this->deriveAlertes($declaredGyms, $info),
        ];
    }

    /**
     * PR A lot 2 (E1) — RECOMPTE `equipes[*].seances` et `mutualisations[*].seances_communes` depuis la
     * structure dérivée COURANTE (après qu'une composition de séance commune a été redéfinie par
     * `corrections.equipes` côté analyzer/executor). Pur : `seances` d'une équipe = ses réservations
     * individuelles + les séances communes dont elle est membre ; `seances_communes` = nombre de séances
     * communes partageant exactement le même ensemble d'équipes. Appelé SEULEMENT après une correction
     * de composition (jamais sur la dérivation brute, qui a déjà ces comptes justes).
     *
     * @param array{gymnases: list<array{nom: string}>, equipes: list<array{nom: string, genre: ?string, categorie: string, seances: int}>, creneaux: list<array{gymnase: string, jour: int, debut: string, duree: int, equipes_en_parallele: int}>, reservations: list<array{equipe: string, gymnase: string, jour: int, debut: string, duree: int}>, mutualisations: list<array{equipes: list<string>, groupe: string|null, gymnase: string, jour: int, debut: string, duree: int, seances_communes: int}>, alertes: list<array{type: string, message: string}>} $derived
     */
    public function recomputeDerivedSessions(array &$derived): void
    {
        // seances par équipe (clé d'identité) : réservations individuelles + appartenances aux séances communes.
        $sessionsByKey = [];
        foreach ($derived['reservations'] as $reservation) {
            $key = $this->teamKey($reservation['equipe']);
            if ('' !== $key) {
                $sessionsByKey[$key] = ($sessionsByKey[$key] ?? 0) + 1;
            }
        }
        foreach ($derived['mutualisations'] as $mutualisation) {
            foreach ($this->stringList($mutualisation['equipes']) as $name) {
                $key = $this->teamKey($name);
                if ('' !== $key) {
                    $sessionsByKey[$key] = ($sessionsByKey[$key] ?? 0) + 1;
                }
            }
        }
        foreach ($derived['equipes'] as $i => $team) {
            $derived['equipes'][$i]['seances'] = $sessionsByKey[$this->teamKey($team['nom'])] ?? 0;
        }

        // seances_communes par ensemble d'équipes.
        $countBySet = [];
        foreach ($derived['mutualisations'] as $mutualisation) {
            $set = $this->teamSetKey($this->stringList($mutualisation['equipes']));
            $countBySet[$set] = ($countBySet[$set] ?? 0) + 1;
        }
        foreach ($derived['mutualisations'] as $i => $mutualisation) {
            $derived['mutualisations'][$i]['seances_communes'] = $countBySet[$this->teamSetKey($this->stringList($mutualisation['equipes']))] ?? 1;
        }
    }

    /**
     * @param array<string, mixed> $parc
     *
     * @return list<array<string, mixed>>
     */
    private function cases(array $parc): array
    {
        $value = $parc['cases'] ?? null;
        if (!\is_array($value) || !array_is_list($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_array'));
    }

    /**
     * @param array<string, mixed> $parc
     *
     * @return list<string>
     */
    private function declaredGymNames(array $parc): array
    {
        $value = $parc['gymnases'] ?? null;
        if (!\is_array($value) || !array_is_list($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $entry) {
            if (\is_array($entry) && \is_string($entry['nom'] ?? null) && '' !== trim($entry['nom'])) {
                $out[] = trim($entry['nom']);
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $case
     *
     * @return array{gymnase: string, jour: int|null, debut: string, duree: int, separes: bool, teams: list<string>, groupe: string|null, mutual: bool}
     */
    private function describeCase(array $case): array
    {
        $gymnase = \is_string($case['gymnase'] ?? null) ? trim($case['gymnase']) : '';
        $jour = \is_int($case['jour'] ?? null) ? $case['jour'] : null;
        $debut = \is_string($case['debut'] ?? null) ? $case['debut'] : '';
        $duree = \is_int($case['duree'] ?? null) && $case['duree'] >= 1 ? $case['duree'] : self::DEFAULT_DURATION;
        $separes = true === ($case['terrains_separes'] ?? null);

        [$groupe, $teams] = $this->groupeAndTeams($case);
        $mutual = \count($teams) >= 2 && !$separes;

        return [
            'gymnase' => $gymnase,
            'jour' => $jour,
            'debut' => $debut,
            'duree' => $duree,
            'separes' => $separes,
            'teams' => $teams,
            'groupe' => $groupe,
            'mutual' => $mutual,
        ];
    }

    /**
     * Le `groupe` d'une case et ses VRAIES équipes. Un `groupe` EXPLICITE l'emporte (toutes les
     * `equipes` sont alors des équipes). Sinon, dans une case multi-équipes, un UNIQUE libellé
     * non-équipe (acronyme court tout en capitales, non reconnu comme équipe — « CEC ») est pris pour
     * le groupe, les autres restant les équipes. Toute autre configuration : aucun groupe.
     *
     * @param array<string, mixed> $case
     *
     * @return array{0: string|null, 1: list<string>}
     */
    private function groupeAndTeams(array $case): array
    {
        $equipes = $this->stringList($case['equipes'] ?? null);
        $explicit = \is_string($case['groupe'] ?? null) && '' !== trim($case['groupe']) ? trim($case['groupe']) : null;
        if (null !== $explicit) {
            return [$explicit, $equipes];
        }

        if (\count($equipes) >= 2) {
            $teams = [];
            $oddballs = [];
            foreach ($equipes as $label) {
                if ($this->looksLikeGroupLabel($label)) {
                    $oddballs[] = $label;
                } else {
                    $teams[] = $label;
                }
            }
            if ([] !== $teams && 1 === \count($oddballs)) {
                return [$oddballs[0], $teams];
            }
        }

        return [null, $equipes];
    }

    private function looksLikeGroupLabel(string $label): bool
    {
        $trimmed = trim($label);

        return self::UNFILED === $this->interpreter->interpret($label)['categorie']
            && 1 === preg_match('/^[A-Z]{2,5}$/', $trimmed);
    }

    /**
     * @param list<string>                                                                                                                                   $declaredGyms
     * @param list<array{gymnase: string, jour: int|null, debut: string, duree: int, separes: bool, teams: list<string>, groupe: string|null, mutual: bool}> $info
     *
     * @return list<array{nom: string}>
     */
    private function deriveGymnases(array $declaredGyms, array $info): array
    {
        $seen = [];
        $out = [];
        foreach ($declaredGyms as $name) {
            $key = $this->normalizer->normalize($name);
            if ('' === $key || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = ['nom' => $name];
        }
        foreach ($info as $case) {
            $key = $this->normalizer->normalize($case['gymnase']);
            if ('' === $key || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = ['nom' => $case['gymnase']];
        }

        return $out;
    }

    /**
     * @param list<array{gymnase: string, jour: int|null, debut: string, duree: int, separes: bool, teams: list<string>, groupe: string|null, mutual: bool}> $info
     *
     * @return list<array{nom: string, genre: ?string, categorie: string, seances: int}>
     */
    private function deriveTeams(array $info): array
    {
        $order = [];
        $firstName = [];
        $counts = [];
        foreach ($info as $case) {
            foreach ($case['teams'] as $name) {
                $key = $this->teamKey($name);
                if ('' === $key) {
                    continue;
                }
                if (!isset($firstName[$key])) {
                    $firstName[$key] = $name;
                    $counts[$key] = 0;
                    $order[] = $key;
                }
                ++$counts[$key];
            }
        }

        $out = [];
        foreach ($order as $key) {
            $name = $firstName[$key];
            $interpreted = $this->interpreter->interpret($name);
            $out[] = [
                'nom' => $name,
                'genre' => $interpreted['genre'],
                'categorie' => $interpreted['categorie'],
                'seances' => $counts[$key],
            ];
        }

        return $out;
    }

    /**
     * @param list<array{gymnase: string, jour: int|null, debut: string, duree: int, separes: bool, teams: list<string>, groupe: string|null, mutual: bool}> $info
     *
     * @return list<array{gymnase: string, jour: int, debut: string, duree: int, equipes_en_parallele: int}>
     */
    private function deriveSlots(array $info): array
    {
        $out = [];
        foreach ($info as $case) {
            if (null === $case['jour']) {
                continue;
            }
            $parallel = $case['separes'] ? max(1, \count($case['teams'])) : 1;
            $out[] = [
                'gymnase' => $case['gymnase'],
                'jour' => $case['jour'],
                'debut' => $case['debut'],
                'duree' => $case['duree'],
                'equipes_en_parallele' => $parallel,
            ];
        }

        return $out;
    }

    /**
     * Une réservation individuelle par équipe des cases NON mutualisées (les membres d'une case
     * mutualisée reçoivent leur réservation par le bloc, à l'import).
     *
     * @param list<array{gymnase: string, jour: int|null, debut: string, duree: int, separes: bool, teams: list<string>, groupe: string|null, mutual: bool}> $info
     *
     * @return list<array{equipe: string, gymnase: string, jour: int, debut: string, duree: int}>
     */
    private function deriveReservations(array $info): array
    {
        $out = [];
        foreach ($info as $case) {
            if ($case['mutual'] || null === $case['jour']) {
                continue;
            }
            foreach ($case['teams'] as $name) {
                $out[] = [
                    'equipe' => $name,
                    'gymnase' => $case['gymnase'],
                    'jour' => $case['jour'],
                    'debut' => $case['debut'],
                    'duree' => $case['duree'],
                ];
            }
        }

        return $out;
    }

    /**
     * @param list<array{gymnase: string, jour: int|null, debut: string, duree: int, separes: bool, teams: list<string>, groupe: string|null, mutual: bool}> $info
     *
     * @return list<array{equipes: list<string>, groupe: string|null, gymnase: string, jour: int, debut: string, duree: int, seances_communes: int}>
     */
    private function deriveMutualisations(array $info): array
    {
        // Un même groupe (ensemble d'équipes) peut mutualiser sur PLUSIEURS cases (séances communes
        // récurrentes) : `seances_communes` = le nombre de cases portant exactement cet ensemble, pour
        // que les N réservations de bloc tiennent sous le plafond du bloc (ReservationGroupOccupancy).
        $countBySet = [];
        foreach ($info as $case) {
            if (!$case['mutual'] || null === $case['jour']) {
                continue;
            }
            $key = $this->teamSetKey($case['teams']);
            $countBySet[$key] = ($countBySet[$key] ?? 0) + 1;
        }

        $out = [];
        foreach ($info as $case) {
            if (!$case['mutual'] || null === $case['jour']) {
                continue;
            }
            $out[] = [
                'equipes' => $case['teams'],
                'groupe' => $case['groupe'],
                'gymnase' => $case['gymnase'],
                'jour' => $case['jour'],
                'debut' => $case['debut'],
                'duree' => $case['duree'],
                'seances_communes' => $countBySet[$this->teamSetKey($case['teams'])] ?? 1,
            ];
        }

        return $out;
    }

    /**
     * @param list<string> $teams
     */
    private function teamSetKey(array $teams): string
    {
        $keys = array_values(array_unique(array_map($this->teamKey(...), $teams)));
        sort($keys);

        return implode('|', $keys);
    }

    /**
     * Clé d'IDENTITÉ d'une équipe pour le dériveur : casse/accents repliés, tout caractère non
     * alphanumérique RETIRÉ (« U15 M2 » ≡ « U15M2 », pures variantes d'espacement), mais le marqueur
     * de genre CONSERVÉ (« U15M1 » ≠ « U15F1 »). Plus serrée que la normalisation de gymnase (qui
     * garde les espaces comme séparateurs) : le chatbot écrit souvent la MÊME équipe de deux façons.
     */
    private function teamKey(string $name): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
        $lower = mb_strtolower(false === $ascii ? $name : $ascii, 'UTF-8');

        return (string) preg_replace('/[^a-z0-9]+/', '', $lower);
    }

    /**
     * @param list<string>                                                                                                                                   $declaredGyms
     * @param list<array{gymnase: string, jour: int|null, debut: string, duree: int, separes: bool, teams: list<string>, groupe: string|null, mutual: bool}> $info
     *
     * @return list<array{type: string, message: string}>
     */
    private function deriveAlertes(array $declaredGyms, array $info): array
    {
        return array_merge(
            $this->dualPlaceAlertes($info),
            $this->implausibleHourAlertes($info),
            $this->venueWithoutCaseAlertes($declaredGyms, $info),
        );
    }

    /**
     * @param list<array{gymnase: string, jour: int|null, debut: string, duree: int, separes: bool, teams: list<string>, groupe: string|null, mutual: bool}> $info
     *
     * @return list<array{type: string, message: string}>
     */
    private function dualPlaceAlertes(array $info): array
    {
        /** @var array<string, array{name: string, venues: array<string, true>}> $byMoment */
        $byMoment = [];
        foreach ($info as $case) {
            if (null === $case['jour'] || '' === $case['debut']) {
                continue;
            }
            $venueKey = $this->normalizer->normalize($case['gymnase']);
            foreach ($case['teams'] as $name) {
                $teamKey = $this->teamKey($name);
                if ('' === $teamKey) {
                    continue;
                }
                $moment = $teamKey . '|' . $case['jour'] . '|' . $case['debut'];
                if (!isset($byMoment[$moment])) {
                    $byMoment[$moment] = ['name' => $name, 'venues' => []];
                }
                $byMoment[$moment]['venues'][$venueKey] = true;
            }
        }

        $out = [];
        foreach ($byMoment as $moment) {
            if (\count($moment['venues']) >= 2) {
                $out[] = [
                    'type' => 'equipe_deux_endroits',
                    'message' => \sprintf('L\'équipe « %s » est placée dans deux gymnases au même moment — à trancher à la main.', $moment['name']),
                ];
            }
        }

        return $out;
    }

    /**
     * @param list<array{gymnase: string, jour: int|null, debut: string, duree: int, separes: bool, teams: list<string>, groupe: string|null, mutual: bool}> $info
     *
     * @return list<array{type: string, message: string}>
     */
    private function implausibleHourAlertes(array $info): array
    {
        $out = [];
        foreach ($info as $case) {
            $start = $this->minutesOfTime($case['debut']);
            if (null === $start) {
                continue;
            }
            $end = $start + $case['duree'];
            if ($start < self::MIN_START_MINUTES || $start > self::MAX_START_MINUTES || $end > self::MAX_END_MINUTES) {
                $out[] = [
                    'type' => 'heure_implausible',
                    'message' => \sprintf('Un créneau commence à %s (gymnase « %s ») — horaire inhabituel à vérifier.', $case['debut'], $case['gymnase']),
                ];
            }
        }

        return $out;
    }

    /**
     * @param list<string>                                                                                                                                   $declaredGyms
     * @param list<array{gymnase: string, jour: int|null, debut: string, duree: int, separes: bool, teams: list<string>, groupe: string|null, mutual: bool}> $info
     *
     * @return list<array{type: string, message: string}>
     */
    private function venueWithoutCaseAlertes(array $declaredGyms, array $info): array
    {
        $used = [];
        foreach ($info as $case) {
            $key = $this->normalizer->normalize($case['gymnase']);
            if ('' !== $key) {
                $used[$key] = true;
            }
        }

        $out = [];
        $seen = [];
        foreach ($declaredGyms as $name) {
            $key = $this->normalizer->normalize($name);
            if ('' === $key || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            if (!isset($used[$key])) {
                $out[] = [
                    'type' => 'gymnase_sans_case',
                    'message' => \sprintf('Le gymnase « %s » est déclaré mais n\'a aucune case — à utiliser ou à retirer.', $name),
                ];
            }
        }

        return $out;
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
                $out[] = trim($item);
            }
        }

        return $out;
    }

    private function minutesOfTime(string $hhmm): ?int
    {
        if (1 !== preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $hhmm, $m)) {
            return null;
        }

        return (int) $m[1] * 60 + (int) $m[2];
    }
}
