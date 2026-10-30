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
use App\Service\Basketball\TeamCreationSuggester;
use App\Service\Basketball\VenueAliasResolver;
use App\Service\Basketball\VenueLabelNormalizer;
use App\Service\PlanEntitlements;
use App\Service\SoloBudget;
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
 *  - `ambigu`   : plusieurs candidats (≥ 2) — décoché, cibleId null, PORTE ses candidats `{id, nom}`
 *                 pour le sélecteur « À trancher… » de la ligne (PR1 D5) ;
 *  - `decoche`  : idempotence (déjà présent) ou cap d'offre dépassé — décoché, avec raison ;
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
        private readonly TeamCreationSuggester $suggester,
    ) {}

    /**
     * @param array<string, mixed>       $parc      le parc parc-v2 décodé, déjà validé en FORME
     * @param string                     $seasonId  la saison résolue ('' si aucune)
     * @param list<array<string, mixed>> $decisions PR1 — les corrections de l'aperçu (revalidation) :
     *                                              l'analyse REJOUE en appliquant jour/heure/durée d'un
     *                                              créneau/réservation/mutualisation, si bien qu'une
     *                                              alerte de chevauchement peut tomber ou naître
     *
     * @return array{format: string, fiabilite: array{taux: float, anomalies: int, bandeau: bool}, recap: array<string, array<string, int>>, sections: array<string, list<array<string, mixed>>>, alertes: list<array{type: string, message: string}>}
     */
    public function analyze(array $parc, Club $club, string $seasonId, array $decisions = []): array
    {
        $derived = $this->deriver->derive($parc);
        if ([] !== $decisions) {
            $this->applyTimeCorrections($derived, $decisions);
            // PR A (D3) — rejeu de la COMPOSITION corrigée d'une séance commune (`corrections.equipes`) :
            // on substitue les équipes lues par la composition redéfinie (UUID / `import:<index>`), ce qui
            // reconstruit le libellé et recompte `seances_communes` sur le nouvel ensemble.
            $this->applyCompositionCorrections($derived, $decisions);
        }
        $season = '' !== $seasonId ? $this->entityManager->find(Season::class, $seasonId) : null;

        // PR A (incident ALAP) — carte de résolution Équipes depuis la section dérivée + les décisions :
        // une équipe gardée est résoluble (connue), une équipe décochée est IGNORÉE. Une séance commune /
        // réservation qui cite une équipe ignorée (ou introuvable des cases) sort en alerte décochée.
        [$ignoredTeamKeys, $knownTeamKeys] = $this->teamResolutionSets($derived['equipes'], $decisions);

        $sections = [
            'gymnases' => $this->analyzeVenues($derived['gymnases']),
            'equipes' => $this->analyzeTeams($derived['equipes'], $club, $season),
            'creneaux' => $this->analyzeSlots($derived['creneaux']),
            'mutualisations' => $this->analyzeMutualisations($derived['mutualisations'], $club->getId(), $seasonId, $ignoredTeamKeys, $knownTeamKeys),
            'reservations' => $this->analyzeReservations($derived['reservations'], $derived['creneaux'], $derived['mutualisations'], $ignoredTeamKeys, $knownTeamKeys, $club->getId(), $seasonId),
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
     * PR1 (revalidation) — applique les corrections de l'aperçu (jour / heure / durée) aux sections
     * TEMPORELLES dérivées, par index de décision, AVANT l'analyse : le rejeu voit les cases
     * corrigées, si bien qu'une alerte de chevauchement peut TOMBER (correction qui sépare deux
     * créneaux) ou NAÎTRE (correction qui les superpose). Ne touche QUE jour / debut / duree (les
     * seuls champs simples d'une case) ; une correction de libellé de groupe reste pour l'import.
     *
     * @param array{gymnases: list<array{nom: string}>, equipes: list<array{nom: string, genre: ?string, categorie: string, seances: int}>, creneaux: list<array{gymnase: string, jour: int, debut: string, duree: int, equipes_en_parallele: int}>, reservations: list<array{equipe: string, gymnase: string, jour: int, debut: string, duree: int}>, mutualisations: list<array{equipes: list<string>, groupe: string|null, gymnase: string, jour: int, debut: string, duree: int, seances_communes: int}>, alertes: list<array{type: string, message: string}>} $derived
     * @param list<array<string, mixed>>                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                           $decisions
     */
    private function applyTimeCorrections(array &$derived, array $decisions): void
    {
        foreach ($decisions as $decision) {
            $section = $decision['section'] ?? null;
            $index = $decision['index'] ?? null;
            $corrections = $decision['corrections'] ?? null;
            if (!\is_int($index) || !\is_array($corrections)) {
                continue;
            }
            $jour = \is_int($corrections['jour'] ?? null) ? $corrections['jour'] : null;
            $duree = \is_int($corrections['duree'] ?? null) && $corrections['duree'] >= 1 ? $corrections['duree'] : null;
            $debut = \is_string($corrections['debut'] ?? null) && null !== $this->minutesOfTime($corrections['debut']) ? $corrections['debut'] : null;

            if ('creneaux' === $section && isset($derived['creneaux'][$index])) {
                $slot = $derived['creneaux'][$index];
                $slot['jour'] = $jour ?? $slot['jour'];
                $slot['debut'] = $debut ?? $slot['debut'];
                $slot['duree'] = $duree ?? $slot['duree'];
                $derived['creneaux'][$index] = $slot;
            } elseif ('reservations' === $section && isset($derived['reservations'][$index])) {
                $reservation = $derived['reservations'][$index];
                $reservation['jour'] = $jour ?? $reservation['jour'];
                $reservation['debut'] = $debut ?? $reservation['debut'];
                $reservation['duree'] = $duree ?? $reservation['duree'];
                $derived['reservations'][$index] = $reservation;
            } elseif ('mutualisations' === $section && isset($derived['mutualisations'][$index])) {
                $mutualisation = $derived['mutualisations'][$index];
                $mutualisation['jour'] = $jour ?? $mutualisation['jour'];
                $mutualisation['debut'] = $debut ?? $mutualisation['debut'];
                $mutualisation['duree'] = $duree ?? $mutualisation['duree'];
                $derived['mutualisations'][$index] = $mutualisation;
            }
        }
    }

    /**
     * PR A (D3) — rejoue `corrections.equipes` d'une décision de séance commune : substitue les équipes
     * LUES par la composition REDÉFINIE (liste ≥ 2 d'UUID d'équipe du club, ou `import:<index>` vers une
     * ligne Équipes dérivée), puis RECOMPTE `seances_communes` sur les nouveaux ensembles (nombre de
     * séances communes partageant exactement le même ensemble d'équipes). Une composition < 2 résolues
     * est ignorée côté analyse (le front bloque déjà l'import).
     *
     * @param array{gymnases: list<array{nom: string}>, equipes: list<array{nom: string, genre: ?string, categorie: string, seances: int}>, creneaux: list<array{gymnase: string, jour: int, debut: string, duree: int, equipes_en_parallele: int}>, reservations: list<array{equipe: string, gymnase: string, jour: int, debut: string, duree: int}>, mutualisations: list<array{equipes: list<string>, groupe: string|null, gymnase: string, jour: int, debut: string, duree: int, seances_communes: int}>, alertes: list<array{type: string, message: string}>} $derived
     * @param list<array<string, mixed>>                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                           $decisions
     */
    private function applyCompositionCorrections(array &$derived, array $decisions): void
    {
        $changed = false;
        foreach ($decisions as $decision) {
            if ('mutualisations' !== ($decision['section'] ?? null)) {
                continue;
            }
            $index = $decision['index'] ?? null;
            $corrections = $decision['corrections'] ?? null;
            if (!\is_int($index) || !\is_array($corrections) || !isset($derived['mutualisations'][$index])) {
                continue;
            }
            $equipes = $corrections['equipes'] ?? null;
            if (!\is_array($equipes)) {
                continue;
            }
            $names = $this->resolveCompositionNames($equipes, $derived['equipes']);
            if (\count($names) < 2) {
                continue;
            }
            $mutualisation = $derived['mutualisations'][$index];
            $mutualisation['equipes'] = $names;
            $derived['mutualisations'][$index] = $mutualisation;
            $changed = true;
        }
        if ($changed) {
            // E1 — recompte seances (équipes ajoutées/retirées) ET seances_communes depuis la nouvelle composition.
            $this->deriver->recomputeDerivedSessions($derived);
        }
    }

    /**
     * Résout une composition corrigée (`corrections.equipes`) en NOMS d'équipe : un UUID → le nom de
     * l'équipe du club (RLS garantit le tenant, une équipe étrangère ne charge pas) ; `import:<index>` →
     * le nom de la ligne Équipes dérivée visée (créée dans ce run). Les entrées non résolues sont omises.
     *
     * @param array<array-key, mixed>                                                   $entries
     * @param list<array{nom: string, genre: ?string, categorie: string, seances: int}> $derivedTeams
     *
     * @return list<string>
     */
    private function resolveCompositionNames(array $entries, array $derivedTeams): array
    {
        $names = [];
        foreach ($entries as $entry) {
            if (!\is_string($entry) || '' === trim($entry)) {
                continue;
            }
            $entry = trim($entry);
            if (str_starts_with($entry, 'import:')) {
                $n = substr($entry, 7);
                if (1 === preg_match('/^\d+$/', $n) && isset($derivedTeams[(int) $n])) {
                    $names[] = $this->stringField($derivedTeams[(int) $n], 'nom');
                }

                continue;
            }
            $team = $this->entityManager->find(Team::class, $entry);
            if ($team instanceof Team) {
                $names[] = $team->getName();
            }
        }

        return array_values(array_filter($names, static fn (string $nom): bool => '' !== $nom));
    }

    /**
     * PR A (D1) — les deux ensembles de clés d'équipe pour juger une citation de séance commune /
     * réservation : `ignored` = les équipes dérivées dont la décision Équipes est décochée ; `known` =
     * toutes les équipes dérivées (une citation hors de `known` est introuvable des cases). Clé = la même
     * identité que le dériveur (`teamKey`), pour croiser noms de case ET lignes Équipes sans re-dériver.
     *
     * @param list<array{nom: string, genre: ?string, categorie: string, seances: int}> $teams
     * @param list<array<string, mixed>>                                                $decisions
     *
     * @return array{0: array<string, true>, 1: array<string, true>} [ignored, known]
     */
    private function teamResolutionSets(array $teams, array $decisions): array
    {
        $byIndex = [];
        foreach ($decisions as $decision) {
            if ('equipes' === ($decision['section'] ?? null) && \is_int($decision['index'] ?? null)) {
                $byIndex[$decision['index']] = $decision;
            }
        }

        $ignored = [];
        $known = [];
        foreach ($teams as $i => $team) {
            $key = $this->teamKey($this->stringField($team, 'nom'));
            if ('' === $key) {
                continue;
            }
            $known[$key] = true;
            $decision = $byIndex[$i] ?? null;
            if (null !== $decision && false === ($decision['coche'] ?? null)) {
                $ignored[$key] = true;
            }
        }

        return [$ignored, $known];
    }

    /**
     * Les équipes citées (par nom) qui sont IGNORÉES (décochées) ou introuvables des cases — celles qui
     * bloquent une séance commune / réservation (D1).
     *
     * @param list<string>        $names
     * @param array<string, true> $ignored
     * @param array<string, true> $known
     *
     * @return list<string>
     */
    private function blockingTeamsAmong(array $names, array $ignored, array $known): array
    {
        $out = [];
        foreach ($names as $name) {
            $key = $this->teamKey($name);
            if ('' === $key) {
                continue;
            }
            if (isset($ignored[$key]) || !isset($known[$key])) {
                $out[] = $name;
            }
        }

        return $out;
    }

    /**
     * Clé d'IDENTITÉ d'une équipe, identique à {@see ParcCaseDeriver::teamKey} : casse/accents repliés,
     * tout caractère non alphanumérique retiré (« U15 M2 » ≡ « U15M2 »), marqueur de genre conservé.
     */
    private function teamKey(string $name): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
        $lower = mb_strtolower(false === $ascii ? $name : $ascii, 'UTF-8');

        return (string) preg_replace('/[^a-z0-9]+/', '', $lower);
    }

    /**
     * @param list<string> $names
     */
    private function quoteList(array $names): string
    {
        return implode(', ', array_map(static fn (string $n): string => '« ' . $n . ' »', $names));
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
        // D8 / P4-366 — le taux ne porte QUE sur les lignes qui avaient quelque chose à apparier (un
        // existant visé). Les lignes « à créer » n'entrent pas au dénominateur : sinon un club VIERGE
        // (rien à apparier, tout « à créer ») affiche un taux 0 trompeur et lèverait le bandeau « peu
        // fiable » alors qu'il n'y a pas de LECTURE incertaine. Le bandeau de taux ne se pose donc que
        // s'il y a des lignes appariables (`$total > 0`) ; les anomalies, elles, le lèvent toujours.
        $appariable = array_filter(
            array_merge($sections['equipes'], $sections['gymnases']),
            static fn (array $line): bool => 'aCreer' !== ($line['statut'] ?? null),
        );
        $total = \count($appariable);
        $matched = \count(array_filter(
            $appariable,
            static fn (array $line): bool => \in_array($line['statut'] ?? null, ['apparie', 'suggere'], true),
        ));
        $taux = $total > 0 ? round($matched / $total, 4) : 1.0;
        $anomalies = \count($alertes);

        return ['taux' => $taux, 'anomalies' => $anomalies, 'bandeau' => ($total > 0 && $taux < 0.5) || $anomalies >= 3];
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
            [$statut, $cibleId, $raison, $candidats] = $this->verdict($this->venueResolver->exactMatch($nom), $this->venueResolver->candidates($nom));
            $out[] = $this->line($i, $nom, $statut, $cibleId, $raison, ['candidats' => $candidats]);
        }

        return $out;
    }

    /**
     * @param list<array{nom: string, genre: ?string, categorie: string, seances: int}> $teams
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
            [$statut, $cibleId, $raison, $candidats] = $this->teamVerdict($nom);

            // Retours fondateur 2026-10-26 — le nombre de séances LU (nombre de cases dérivé) est servi
            // sur CHAQUE ligne équipe : il amorce le champ « Séances » éditable de l'aperçu (ce qui SERA
            // importé). Une ligne APPARIÉE porte en plus `seancesActuelles` (les séances actuelles de
            // l'équipe visée) pour l'indication « actuellement N » et la base de comparaison du champ.
            $extra = ['candidats' => $candidats, 'seances' => $entry['seances']];
            if ('apparie' === $statut && null !== $cibleId && isset($byId[$cibleId])) {
                // P4-366 — le champ « Séances » éditable + `seancesActuelles` suffisent à l'écran : le
                // champ `miseAJour` (ancienne case « 2 → 1 ») est retiré.
                $extra['seancesActuelles'] = $byId[$cibleId]->getSessionsPerWeek();
            }
            // Refonte PR2 (D9) — CHAQUE ligne équipe (quel que soit son statut d'appariement : apparié /
            // suggéré / ambigu / à créer) porte les SUGGESTIONS de la règle FBI unique
            // ({@see TeamCreationSuggester}) : rang (id résolu par label), genre (?string, null si aucun
            // motif clair — « plus MIXTE ») et entente. Elles pré-remplissent les Select Rang + Genre et
            // la case Entente DÈS que le gestionnaire choisit « Créer » sur CETTE ligne — depuis n'importe
            // quel statut, car le front se câble sur `suggestedTierId` ; les servir sur la seule ligne
            // « aCreer » n'ouvrait aucun Select Genre sur une création depuis un autre statut, et
            // l'executor refusait alors la création sans genre (422). Le genre reste tranché par le
            // gestionnaire (obligatoire).
            $suggestion = $this->suggester->suggest($nom, $this->stringField($entry, 'categorie'), '');
            $extra['suggestedTierId'] = $suggestion['tierId'];
            $extra['suggestedGender'] = $suggestion['gender']?->value;
            $extra['suggestedCtc'] = $suggestion['ctc'];

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
     * suggéré / à créer tels quels ; une AMBIGUÏTÉ (≥ 2 candidats dans le seuil) ressort `ambigu`
     * (décoché, à trancher) PORTANT ses candidats — PR1 (D5), au lieu du « decoche » muet d'avant.
     *
     * @return array{0: string, 1: string|null, 2: string|null, 3: list<array{id: string, nom: string}>} [statut, cibleId, raison, candidats]
     */
    private function teamVerdict(string $label): array
    {
        $verdict = $this->matcher->match($label, $this->teamPool());
        if ('ambigu' === $verdict['statut']) {
            return ['ambigu', null, 'Plusieurs correspondances possibles — appariement ambigu, à choisir à la main.', $verdict['candidats']];
        }

        return [$verdict['statut'], $verdict['cibleId'], null, $verdict['candidats']];
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
     * @param array<string, true>                                                                                                                   $ignoredTeamKeys
     * @param array<string, true>                                                                                                                   $knownTeamKeys
     *
     * @return list<array<string, mixed>>
     */
    private function analyzeMutualisations(array $mutualisations, string $clubId, string $seasonId, array $ignoredTeamKeys, array $knownTeamKeys): array
    {
        $existingBlocks = '' !== $seasonId ? $this->socleBlocks($clubId, $seasonId) : [];

        $out = [];
        foreach ($mutualisations as $i => $entry) {
            $libelle = $this->mutualisationLabel($entry);
            $groupe = $entry['groupe'];
            // P4-363 — gymnase RÉSOLU depuis le NOM de la case (null si à créer) : amorce le sélecteur
            // de gymnase de la ligne côté front, SANS jamais confondre ce gymnase avec le `cibleId`
            // (qui, pour une mutualisation, désigne le BLOC apparié). `position` = le créneau de SA case
            // dérivée (la grille place la case EXCLUSIVEMENT d'après elle, jamais `payload.cases[index]`,
            // car l'index d'une mutualisation est son rang dans la liste dérivée, pas celui de la case).
            $position = $this->positionOf($entry);
            $teamNames = $this->stringList($entry['equipes']);
            $commonSessions = $entry['seances_communes'];
            // D3 — la ligne expose sa COMPOSITION (`equipes` : {id résolu|null, nom}) pour pré-remplir le
            // sélecteur multiple de l'étape Séances communes, et `seancesCommunes` (recalculé si la
            // composition a été redéfinie). Le `libelle` reflète déjà la composition (corrigée ou lue).
            $composition = array_map(
                fn (string $nom): array => ['id' => $this->resolveTeamId($nom), 'nom' => $nom],
                $teamNames,
            );
            $extra = [
                'groupe' => $groupe,
                'gymnaseId' => $position['gymnaseId'],
                'position' => $position,
                'equipes' => $composition,
                'seancesCommunes' => $commonSessions,
            ];

            // PR A (D1/D13) — une équipe citée IGNORÉE à l'étape Équipes (décision décochée) ou
            // introuvable des cases fait passer la séance commune en alerte DÉCOCHÉE, nommant l'équipe,
            // avec un code machine (`alerteCode`) que la grille lit pour bloquer l'import.
            $blockingTeams = $this->blockingTeamsAmong($teamNames, $ignoredTeamKeys, $knownTeamKeys);
            if ([] !== $blockingTeams) {
                $out[] = $this->line(
                    $i,
                    $libelle,
                    'alerte',
                    null,
                    \sprintf('Cette séance commune cite %s, ignorée(s) ou introuvable(s) à l\'étape Équipes — à revoir avant d\'importer.', $this->quoteList($blockingTeams)),
                    $extra + ['alerteCode' => 'equipe_ignoree'],
                );

                continue;
            }

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
                $out[] = $this->line($i, $libelle, 'alerte', null, 'Les séances communes de ce groupe dépassent les séances hebdomadaires d\'une des équipes — à ajuster à la main.', $extra);

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
     * @param array<string, true>                                                                                                                   $ignoredTeamKeys
     * @param array<string, true>                                                                                                                   $knownTeamKeys
     *
     * @return list<array<string, mixed>>
     */
    private function analyzeReservations(array $reservations, array $slots, array $mutualisations, array $ignoredTeamKeys, array $knownTeamKeys, string $clubId, string $seasonId): array
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
            // `position` = le créneau de SA case dérivée (la grille place la case EXCLUSIVEMENT d'après
            // elle) : l'index d'une réservation est son rang dans la liste dérivée, PAS celui de la case
            // du parc — une case à 2 équipes sur terrains séparés donne 2 réservations et décale tout.
            $extra = ['position' => $this->positionOf($entry)];
            $case = $ctx['caseKey'];

            // PR A (D1) — une réservation qui cite une équipe IGNORÉE à l'étape Équipes (décochée) ou
            // introuvable des cases passe en alerte DÉCOCHÉE, nommant l'équipe + un code machine.
            $equipeName = $this->stringField($entry, 'equipe');
            if ([] !== $this->blockingTeamsAmong([$equipeName], $ignoredTeamKeys, $knownTeamKeys)) {
                $out[] = $this->line(
                    $i,
                    $libelle,
                    'alerte',
                    null,
                    \sprintf('Cette réservation cite « %s », ignorée ou introuvable à l\'étape Équipes — à revoir avant d\'importer.', $equipeName),
                    $extra + ['alerteCode' => 'equipe_ignoree'],
                );

                continue;
            }

            // PR A lot 2 (E2) — garde de budget individuel jouée DÈS l'analyse : une équipe dont tout le
            // temps de séances est déjà pris par ses groupes (bloc socle) ne peut pas poser de réservation
            // INDIVIDUELLE → alerte DÉCOCHÉE, code machine dédié `budget_groupe` (la grille bloque Importer).
            if ('' !== $seasonId) {
                $teamId = $this->resolveTeamId($equipeName);
                if (null !== $teamId) {
                    $budget = $this->soloReservationBudget->forTeam($teamId, $clubId, $seasonId, null);
                    if ($budget instanceof SoloBudget && $budget->inBlock && 0 === $budget->residual) {
                        $out[] = $this->line(
                            $i,
                            $libelle,
                            'alerte',
                            null,
                            \sprintf('L\'équipe « %s » a tout son temps de séances pris par ses groupes — elle ne peut pas poser de créneau individuel. Augmentez son nombre de séances dans sa fiche.', $equipeName),
                            $extra + ['alerteCode' => 'budget_groupe'],
                        );

                        continue;
                    }
                }
            }

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
                    $extra,
                );

                continue;
            }

            if ($this->reservationAlreadyExists($ctx)) {
                $out[] = $this->line($i, $libelle, 'decoche', null, 'Réservation déjà présente dans le planning.', $extra);

                continue;
            }

            $out[] = $this->line($i, $libelle, 'aCreer', null, null, $extra);
        }

        return $out;
    }

    /**
     * Le verdict d'appariement commun aux gymnases : exact unique → apparié ; un candidat flou →
     * suggéré ; deux ou plus → `ambigu` (décoché, à trancher) + la liste des candidats ; aucun → à
     * créer. PR1 (D5) : l'ambiguïté PORTE désormais ses candidats (`{id, nom}`) pour le sélecteur de
     * la ligne, au lieu de tomber en « decoche » muet.
     *
     * @param list<string> $candidates
     *
     * @return array{0: string, 1: string|null, 2: string|null, 3: list<array{id: string, nom: string}>}
     */
    private function verdict(?string $exact, array $candidates): array
    {
        if (null !== $exact) {
            return ['apparie', $exact, null, $this->venueCandidats([$exact])];
        }

        $candidates = array_values(array_unique($candidates));
        if (1 === \count($candidates)) {
            return ['suggere', $candidates[0], null, $this->venueCandidats($candidates)];
        }
        if (\count($candidates) >= 2) {
            return ['ambigu', null, 'Plusieurs correspondances possibles — appariement ambigu, à choisir à la main.', $this->venueCandidats($candidates)];
        }

        return ['aCreer', null, null, []];
    }

    /**
     * Les candidats gymnase (`{id, nom}`) d'un ensemble d'ids résolus — pour le groupe « Candidats
     * possibles » du sélecteur de la ligne. Un id qui ne charge pas (course tenant) est ignoré.
     *
     * @param list<string> $ids
     *
     * @return list<array{id: string, nom: string}>
     */
    private function venueCandidats(array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            $venue = $this->entityManager->find(Venue::class, $id);
            if ($venue instanceof Venue) {
                $out[] = ['id' => $id, 'nom' => $venue->getName()];
            }
        }

        return $out;
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

    /**
     * La POSITION d'une réservation/mutualisation = le créneau de SA case dérivée (gymnase · jour ·
     * heure · durée) + le gymnase RÉSOLU (null si à créer). Le front place la case de la grille et
     * amorce l'éditeur EXCLUSIVEMENT d'après elle, jamais `payload.cases[index]` : l'index d'une ligne
     * de ces deux sections est son rang dans la liste DÉRIVÉE, décalé du rang des cases du parc.
     *
     * @param array<string, mixed> $entry
     *
     * @return array{gymnase: string, jour: int, debut: string, duree: int, gymnaseId: string|null}
     */
    private function positionOf(array $entry): array
    {
        $gymnase = $this->stringField($entry, 'gymnase');

        return [
            'gymnase' => $gymnase,
            'jour' => \is_int($entry['jour'] ?? null) ? $entry['jour'] : 0,
            'debut' => $this->stringField($entry, 'debut'),
            'duree' => \is_int($entry['duree'] ?? null) ? $entry['duree'] : 0,
            'gymnaseId' => $this->resolveVenueId($gymnase),
        ];
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
        $recap = ['total' => \count($lines), 'apparie' => 0, 'suggere' => 0, 'aCreer' => 0, 'ambigu' => 0, 'decoche' => 0, 'alerte' => 0];
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
