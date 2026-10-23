<?php

declare(strict_types=1);

namespace App\Service\Fbi;

use App\Entity\FbiCorrection;
use App\Entity\Fixture;
use App\Entity\Venue;
use App\Enum\FbiCorrectionField;
use App\Enum\FixtureHomeAway;
use App\Enum\FixtureReviewState;
use App\Enum\FixtureStatus;
use App\Enum\FixtureUnplacedReason;
use App\Exception\ImportRejectedException;
use App\Service\Basketball\VenueAliasResolver;
use App\Service\Basketball\VenueExternalLabelAttacher;
use App\Service\Basketball\VenueLabelNormalizer;
use App\Service\FbiCorrectionLedger;
use App\Service\FbiFixtureImporter;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Le moteur de réconciliation app⇄fichier des écarts (RMM-4) : détection du
 * périmètre, « prendre le fichier » / « garder l'appli » par champ, les
 * enregistrements d'écart et leur regroupement, plus les utilitaires d'écriture
 * qu'ils partagent (dé-placement, rétrogradation, entrée pendante, rattachement
 * de gymnase confirmé, fuzzy salle, fermeture de correction). Extrait verbatim de
 * {@see FbiFixtureImporter} (lot 5 architecture, BCK-19) : foyer UNIQUE des deux
 * canaux (import xlsx + canal API {@see App\Service\Basketball\FfbbRencontreReconciler}).
 * L'importeur garde des façades publiques de même signature pour les consommateurs
 * ({@see App\Service\Basketball\FfbbRencontreReconciler},
 * {@see App\Controller\ReviewFixtureDeviationController}).
 */
final class FbiDeviationService
{
    public function __construct(
        private readonly VenueLabelNormalizer $labelNormalizer,
        private readonly VenueAliasResolver $venueAliasResolver,
        private readonly FbiCorrectionLedger $ledger,
        private readonly EntityManagerInterface $entityManager,
        private readonly VenueExternalLabelAttacher $venueLabelAttacher,
    ) {}

    /**
     * The reconciliation perimeter (RMM-4 D1): the divergent date/kickoff/venue
     * fields of a HOME fixture already placed, comparing the file to the app —
     * OR null when the fixture is OUT of the perimeter (extérieur, or home but
     * UNPLACED, or the row switches side). Read-only. AWAY never enters (the
     * fondateur invariant): the perimeter requires the fixture to BE home and the
     * row to STAY home.
     *
     * @param array{numero: string, matchDate: DateTimeImmutable, homeAway: FixtureHomeAway, opponentLabel: string, kickoffTime: DateTimeImmutable|null, venueLabel: string|null} $row
     * @param array<string, string>                                                                                                                                               $venueNames venueId → Venue name
     *
     * @return array<string, array{app: string|null, file: string|null}>|null keyed by field (date/kickoff/venue); null = out of perimeter
     */
    public function detectFieldDeviations(Fixture $existing, array $row, array $venueNames): ?array
    {
        $inPerimeter = FixtureHomeAway::HOME === $existing->getHomeAway()
            && FixtureHomeAway::HOME === $row['homeAway']
            && FixtureStatus::UNPLACED !== $existing->getStatus();
        if (!$inPerimeter) {
            return null;
        }

        $fields = [];

        if ($existing->getMatchDate()->format('Y-m-d') !== $row['matchDate']->format('Y-m-d')) {
            $fields['date'] = ['app' => $existing->getMatchDate()->format('Y-m-d'), 'file' => $row['matchDate']->format('Y-m-d')];
        }

        // The 00:00 sentinel is parsed to null upstream (fact F2): a null file
        // kickoff is « not set », never a divergence.
        if ($row['kickoffTime'] instanceof DateTimeImmutable) {
            $current = $existing->getKickoffTime();
            if (!$current instanceof DateTimeImmutable || $current->format('H:i') !== $row['kickoffTime']->format('H:i')) {
                $fields['kickoff'] = ['app' => $current?->format('H:i'), 'file' => $row['kickoffTime']->format('H:i')];
            }
        }

        // Salle (D13): app = the placed Venue's name, file = the free FBI label.
        // A placed home fixture without a venue id, or an unknown venue, cannot be
        // compared → no deviation (degrade safe). Fuzzy: normalized equality OR
        // whole-word containment either way (« Coubertin » ≈ « GYMNASE … COUBERTIN »).
        // Conforme AUSSI quand l'alias confirmé du libellé pointe le gymnase courant :
        // la MÊME clause alias que le chemin non placé ({@see detectUnplacedVenueDeviation}),
        // sans quoi un domicile placé dans le bon gymnase mais que la source nomme par
        // un alias lèverait un FAUX écart à chaque dépôt. L'égalité reste STRICTE sur
        // l'identité : un alias pointant un AUTRE gymnase lève toujours l'écart.
        $venueId = $existing->getVenueId();
        $fileLabel = $row['venueLabel'];
        if (null !== $venueId && null !== $fileLabel && isset($venueNames[$venueId])) {
            $appLabel = $venueNames[$venueId];
            if (!$this->venueMatches($appLabel, $fileLabel)
                && $this->venueAliasResolver->resolveConfirmed($fileLabel) !== $venueId) {
                $fields['venue'] = ['app' => $appLabel, 'file' => $fileLabel];
            }
        }

        return $fields;
    }

    /**
     * L'écart salle d'un domicile NON PLACÉ mais RATTACHÉ à un gymnase (venueId non
     * null) dont la source nomme une AUTRE salle : sinon le libellé serait réécrit en
     * silence sans jamais interroger le gestionnaire ni corriger le gymnase (les 20
     * cas mesurés — venueId JDR, libellé « SALLE RAPHAEL DE BARROS », alias confirmé de
     * Debarros). Complète {@see detectFieldDeviations} (qui, lui, ne couvre QUE le
     * placé) et partage le MÊME moteur d'arbitrage aux deux canaux (import xlsx + API).
     * Lecture seule. Lève ssi :
     *  - HOME des deux côtés · statut UNPLACED ;
     *  - venueId non null ET connu (un gymnase du club) · libellé fichier non null ;
     *  - date fichier == date app (un re-datage passe par le chemin actuel, décision
     *    fondateur : `unplace` y vide déjà le venueId) ;
     *  - divergence RÉELLE : ni le fuzzy nom↔libellé NI l'alias confirmé du libellé ne
     *    pointent le gymnase courant (la clause alias évite un faux écart quand le
     *    gymnase EST celui de l'alias) ;
     *  - le libellé normalisé n'est pas déjà « gardé » (idempotence keep_app, E).
     *
     * @param array{numero: string, matchDate: DateTimeImmutable, homeAway: FixtureHomeAway, opponentLabel: string, kickoffTime: DateTimeImmutable|null, venueLabel: string|null} $row
     * @param array<string, string>                                                                                                                                               $venueNames venueId → Venue name
     *
     * @return array{app: string, file: string}|null null = pas d'écart salle à arbitrer
     */
    public function detectUnplacedVenueDeviation(Fixture $existing, array $row, array $venueNames): ?array
    {
        if (FixtureHomeAway::HOME !== $existing->getHomeAway()
            || FixtureHomeAway::HOME !== $row['homeAway']
            || FixtureStatus::UNPLACED !== $existing->getStatus()) {
            return null;
        }

        $venueId = $existing->getVenueId();
        $fileLabel = $row['venueLabel'];
        if (null === $venueId || null === $fileLabel || !isset($venueNames[$venueId])) {
            return null;
        }

        // Un re-datage n'est pas un écart salle : il suit le chemin actuel (dé-place
        // puis ré-adopte le libellé) — au point d'appel de l'import, `unplace` a déjà
        // vidé le venueId, donc la clause ci-dessus n'y lève plus (décision C).
        if ($existing->getMatchDate()->format('Y-m-d') !== $row['matchDate']->format('Y-m-d')) {
            return null;
        }

        $appLabel = $venueNames[$venueId];
        // Conforme si le fuzzy nom↔libellé matche OU si l'alias confirmé du libellé
        // pointe le gymnase courant (sans la clause alias, un domicile déjà rattaché
        // au bon gymnase par alias lèverait un faux écart).
        if ($this->venueMatches($appLabel, $fileLabel)
            || $this->venueAliasResolver->resolveConfirmed($fileLabel) === $venueId) {
            return null;
        }

        // Idempotence « Garder l'appli » (E) : un libellé déjà mémorisé ne repose pas
        // la question tant que la source le répète.
        if ($this->labelNormalizer->normalize($fileLabel) === $existing->getKeptVenueLabel()) {
            return null;
        }

        return ['app' => $appLabel, 'file' => $fileLabel];
    }

    /**
     * « Prendre le fichier » on one field. Retained semantics (RMM-4):
     * - DATE: la ligue a re-décidé → write the date AND un-place (UNPLACED, venue
     *   cleared) — exactly today's reschedule; the placement is invalidated.
     * - KICKOFF: write the hour IN PLACE (venue kept); a SUBMITTED/VALIDATED
     *   fixture drops to PLACED (D2 — the FBI checkmark was on a wrong hour).
     * - VENUE: chantier A PR 4 — « prendre le fichier » sur un écart de salle ne
     *   laisse JAMAIS un domicile sans gymnase (la promesse protégée). La DÉCISION
     *   « est-ce que ça laisserait venueId null ? » se prend AVANT toute écriture
     *   ({@see applyVenueTakeFile}) : un choix inline (uuid → rattache + pose ;
     *   'NEUTRAL' → terrain neutre) OU l'alias confirmé du libellé reposent une
     *   salle ; faute de cible, on n'écrit RIEN (l'écart reste à trancher).
     *
     * @param array{numero: string, matchDate: DateTimeImmutable, homeAway: FixtureHomeAway, opponentLabel: string, kickoffTime: DateTimeImmutable|null, venueLabel: string|null} $row
     * @param string|null                                                                                                                                                         $venueChoice uuid d'un gymnase choisi inline, la sentinelle {@see FbiFixtureImporter::NEUTRAL}, ou null (pas de choix — suit l'alias confirmé)
     *
     * @return bool vrai = le champ a été appliqué ; faux = non appliqué (salle sans cible : jamais un venueId null silencieux, l'écart reste)
     */
    public function applyFieldTakeFile(Fixture $existing, string $field, array $row, DateTimeImmutable $now, ?string $venueChoice = null): bool
    {
        switch ($field) {
            case 'date':
                $existing->setMatchDate($row['matchDate']);
                $this->unplace($existing, $now);

                return true;
            case 'kickoff':
                if ($row['kickoffTime'] instanceof DateTimeImmutable) {
                    $existing->setKickoffTime($row['kickoffTime']);
                }
                $this->demoteSubmitted($existing, $now, 'kickoff', $row['kickoffTime']?->format('H:i'));

                return true;
            case 'venue':
                return $this->applyVenueTakeFile($existing, $row, $now, $venueChoice);
        }

        return false;
    }

    /**
     * « Garder l'appli » sur l'écart salle d'un domicile NON PLACÉ (E) : on garde le
     * gymnase courant, on adopte le libellé BRUT de la source dans `fbiVenueLabel`, on
     * MÉMORISE ce libellé normalisé dans `keptVenueLabel` (pense-bête d'idempotence :
     * un re-dépôt du même libellé ne repose plus la question) et l'écart salle est
     * retiré. Foyer partagé par le moteur d'import ({@see FbiFixtureImporter::processPerimeterFields}) et
     * l'arbitrage hors dépôt ({@see App\Controller\ReviewFixtureDeviationController}).
     */
    public function applyVenueKeepApp(Fixture $fixture, string $fileLabel): void
    {
        $fixture->setKeptVenueLabel($this->labelNormalizer->normalize($fileLabel));
        $fixture->setFbiVenueLabel($fileLabel);
        $fixture->removePendingDeviation('venue');
    }

    /**
     * @param array{app: string|null, file: string|null} $vals
     * @param string|null                                $status the status the manager saw (defaults to the live one — analyze never mutates)
     *
     * @return array{fixtureId: string, externalRef: string, division: string, teamId: string, status: string, field: string, app: string|null, file: string|null, effect: string}
     */
    public function deviationRecord(Fixture $existing, string $field, array $vals, string $divisionName, string $effect, ?string $status = null): array
    {
        return [
            'fixtureId' => $existing->getId(),
            'externalRef' => (string) $existing->getExternalRef(),
            'division' => $divisionName,
            'teamId' => $existing->getTeamId(),
            'status' => $status ?? $existing->getStatus()->value,
            'field' => $field,
            'app' => $vals['app'],
            'file' => $vals['file'],
            'effect' => $effect,
        ];
    }

    /**
     * Groups flat per-field records into one deviation object per fixture, with a
     * `persisting` flag (any of its fields was pending on the previous deposit).
     * The `status` is captured at detection (before any take_file mutation) — the
     * FIRST record of the fixture wins, so it reflects what the manager saw.
     *
     * @param list<array{fixtureId: string, externalRef: string, division: string, teamId: string, status: string, field: string, app: string|null, file: string|null, effect: string}> $records
     * @param array<string, true>                                                                                                                                                       $persistingSet
     *
     * @return list<array{fixtureId: string, externalRef: string, division: string, teamId: string, status: string, persisting: bool, fields: array<string, array{app: string|null, file: string|null}>}>
     */
    public function groupDeviations(array $records, array $persistingSet): array
    {
        /** @var array<string, array{fixtureId: string, externalRef: string, division: string, teamId: string, status: string, persisting: bool, fields: array<string, array{app: string|null, file: string|null}>}> $byFixture */
        $byFixture = [];
        foreach ($records as $record) {
            $id = $record['fixtureId'];
            if (!isset($byFixture[$id])) {
                $byFixture[$id] = [
                    'fixtureId' => $id,
                    'externalRef' => $record['externalRef'],
                    'division' => $record['division'],
                    'teamId' => $record['teamId'],
                    'status' => $record['status'],
                    'persisting' => false,
                    'fields' => [],
                ];
            }
            $byFixture[$id]['fields'][$record['field']] = ['app' => $record['app'], 'file' => $record['file']];
            if (isset($persistingSet[$id . '|' . $record['field']])) {
                $byFixture[$id]['persisting'] = true;
            }
        }

        return array_values($byFixture);
    }

    /**
     * P4-187a D3 — un domicile sans salle retrouve son gymnase depuis un alias
     * CONFIRMÉ ({@see VenueAliasResolver::resolveConfirmed}). Foyer UNIQUE de la
     * résolution automatique, partagé par l'import xlsx et le canal API : on ne
     * pose QUE le venueId — jamais un setStatus, jamais sur un AWAY, jamais sur une
     * rencontre qui a déjà un gymnase. La rencontre reste UNPLACED et son
     * reviewState intact ; on la rend seulement visible de la collision de gymnase
     * (VENUE_OVERLAP) et de la fermeture (VENUE_UNAVAILABLE), statut indifférent.
     *
     * « Naît avec son gymnase mais jamais placée d'office » : ce chemin de création ne
     * pose donc AUCUN statut placé. L'UNIQUE exception CONSENTIE est un geste séparé,
     * explicite et confirmé — le « validé ligue » en lot ({@see
     * App\Controller\LeagueValidatedFixturesController}) —, jamais l'import lui-même.
     *
     * @return bool vrai = un gymnase a été rattaché (la rencontre a « changé »)
     */
    public function attachConfirmedVenue(Fixture $fixture, ?string $venueLabel): bool
    {
        if (FixtureHomeAway::HOME !== $fixture->getHomeAway() || null !== $fixture->getVenueId()) {
            return false;
        }
        $venueId = $this->venueAliasResolver->resolveConfirmed($venueLabel);
        if (null === $venueId) {
            return false;
        }
        $fixture->setVenueId($venueId);

        return true;
    }

    /**
     * A match ALREADY treated (REVIEWED/OUT_OF_SYNC) whose source silently changed
     * a value out of the perimeter records an « auto-applied » entry per changed
     * field so the manager sees what the league moved. A NEW match (never treated)
     * keeps NEW — there is nothing to be out of sync with.
     *
     * Décision fondateur P4-199 — un EXTÉRIEUR PREND ACTE de la source : la valeur
     * est appliquée d'office (déjà écrite en amont), la trace `autoApplied` allume
     * le bandeau, mais la rencontre RESTE traitée (REVIEWED, jamais OUT_OF_SYNC) —
     * le club ne place pas un extérieur, il n'y a rien à re-arbitrer. Un domicile
     * hors périmètre (UNPLACED) déjà traité, lui, retombe OUT_OF_SYNC.
     *
     * @param array<string, array{app: string|null, file: string|null}> $autoApplied
     * @param 'FBI_XLSX'|'FFBB_API'                                     $channel
     */
    public function recordAutoApplied(Fixture $existing, array $autoApplied, string $channel, DateTimeImmutable $now): void
    {
        if ([] === $autoApplied || FixtureReviewState::NEW === $existing->getReviewState()) {
            return;
        }
        foreach ($autoApplied as $field => $vals) {
            $existing->putPendingDeviation($this->pendingEntry($existing, $field, $vals, $channel, $now, true));
        }
        if (FixtureHomeAway::AWAY === $existing->getHomeAway()) {
            $existing->markReviewed($now);

            return;
        }
        $existing->setReviewState(FixtureReviewState::OUT_OF_SYNC);
    }

    /** Ferme (par le dépôt) l'entrée « à corriger dans FBI » OUVERTE de ce champ, s'il y en a une. */
    public function closeOpenCorrection(Fixture $fixture, string $field, DateTimeImmutable $now): void
    {
        $entry = $this->ledger->findOpen($fixture, FbiCorrectionField::from($field));
        if ($entry instanceof FbiCorrection) {
            $this->ledger->closeBySource($entry, $now);
        }
    }

    /** The league re-decided: the match goes back to « à placer ». */
    public function unplace(Fixture $fixture, DateTimeImmutable $now): void
    {
        $fixture->setStatus(FixtureStatus::UNPLACED, $now);
        $fixture->setVenueId(null);
    }

    /**
     * Build the pending-deviation entry for a field, preserving the ORIGINAL
     * `seenAt` when the écart was already open for that field (« depuis quand »),
     * fresh otherwise.
     *
     * @param array{app: string|null, file: string|null} $vals
     * @param 'FBI_XLSX'|'FFBB_API'                      $channel
     *
     * @return array{field: 'date'|'kickoff'|'venue', appValue: string|null, sourceValue: string|null, channel: 'FBI_XLSX'|'FFBB_API', seenAt: string, autoApplied: bool}
     */
    public function pendingEntry(Fixture $existing, string $field, array $vals, string $channel, DateTimeImmutable $now, bool $autoApplied): array
    {
        \assert('date' === $field || 'kickoff' === $field || 'venue' === $field);
        $previous = $existing->getPendingDeviation($field);

        return [
            'field' => $field,
            'appValue' => $vals['app'],
            'sourceValue' => $vals['file'],
            'channel' => $channel,
            'seenAt' => $previous['seenAt'] ?? $now->format(DateTimeImmutable::ATOM),
            'autoApplied' => $autoApplied,
        ];
    }

    /**
     * « Prendre le fichier » sur l'écart de SALLE — foyer partagé par les trois canaux
     * (import xlsx, canal API, arbitrage hors dépôt). La promesse : un domicile ne perd
     * JAMAIS son gymnase au profit d'un venueId null silencieux. On décide la cible AVANT
     * toute écriture :
     *  - 'NEUTRAL' → terrain neutre (venueId null VOULU, l'exception explicite) ; un libellé
     *    qui désigne un gymnase du club (alias confirmé) est refusé (422) ;
     *  - uuid → le libellé source est rattaché à ce gymnase (MAISON {@see VenueExternalLabelAttacher},
     *    gardes verbatim) et le venueId est posé ; introuvable (tenant/saison) → 404 ;
     *  - pas de choix → l'alias confirmé du libellé repose une salle (les 20 cas) ; faute
     *    d'alias, on n'écrit RIEN et on rend faux (l'écart reste à trancher).
     *
     * @param array{numero: string, matchDate: DateTimeImmutable, homeAway: FixtureHomeAway, opponentLabel: string, kickoffTime: DateTimeImmutable|null, venueLabel: string|null} $row
     */
    private function applyVenueTakeFile(Fixture $existing, array $row, DateTimeImmutable $now, ?string $venueChoice): bool
    {
        $sourceLabel = $row['venueLabel'];

        // Terrain neutre (« pas chez nous ») : venueId null est VOULU. Mais un libellé qui
        // EST un gymnase du club (alias confirmé) n'est pas neutre — on le refuse nommé.
        if (FbiFixtureImporter::NEUTRAL === $venueChoice) {
            if (null !== $this->venueAliasResolver->resolveConfirmed($sourceLabel)) {
                throw ImportRejectedException::unprocessable('Ce libellé désigne un gymnase du club — choisissez ce gymnase plutôt que « terrain neutre ».');
            }
            if (null !== $sourceLabel) {
                $existing->setFbiVenueLabel($sourceLabel);
            }
            $existing->setKeptVenueLabel(null);
            $this->unplace($existing, $now);
            $existing->setUnplacedReason(FixtureUnplacedReason::NEUTRAL_GROUND);

            return true;
        }

        // Choix inline d'un gymnase : on rattache le libellé source à ce gymnase (une seule
        // maison du rattachement) et on POSE le venueId — jamais un dé-placement. Le statut
        // reste inchangé (un non placé reste non placé : apparier ne place pas).
        if (null !== $venueChoice) {
            $venue = $this->ownedVenueOrRefuse($venueChoice, $existing->getClubId(), $existing->getSeasonId());
            if (null !== $sourceLabel) {
                $this->venueLabelAttacher->attach($venue, $sourceLabel);
                $existing->setFbiVenueLabel($sourceLabel);
            }
            $existing->setVenueId($venue->getId()); // réinitialise keptVenueLabel + unplacedReason

            return true;
        }

        // Pas de choix : DÉCISION AVANT TOUTE ÉCRITURE. On ne résout vers une salle QUE si
        // le libellé source a un alias confirmé sur un NON PLACÉ ; sinon « prendre le
        // fichier » laisserait le domicile sans gymnase → on n'écrit RIEN (l'écart reste).
        $wasUnplaced = FixtureStatus::UNPLACED === $existing->getStatus();
        if (!$wasUnplaced || null === $this->venueAliasResolver->resolveConfirmed($sourceLabel)) {
            return false;
        }
        // Cible connue (les 20 cas) : on vide le gymnase erroné puis l'alias confirmé repose
        // le bon (Debarros). Le statut reste UNPLACED.
        if (null !== $sourceLabel) {
            $existing->setFbiVenueLabel($sourceLabel);
        }
        $existing->setKeptVenueLabel(null);
        $this->unplace($existing, $now);
        $this->attachConfirmedVenue($existing, $sourceLabel);

        return true;
    }

    /**
     * Le gymnase d'uuid $venueId choisi inline, s'il appartient AU club ET à la saison de la
     * rencontre arbitrée — sinon un refus 404 nommé AVANT toute mutation (zéro écriture).
     * Durcissement /security-review (patron {@see FbiFixtureImporter::ownedVenueForImport}, A10) :
     * on vérifie club ET saison AU SERVICE, sans se reposer sur les SEULS `tenant_filter`/
     * `season_filter` (défense en profondeur — les alias sont copiés au changement de saison,
     * apparier le dépôt courant à un gymnase d'une autre saison écrirait un alias dans la
     * mauvaise saison). Le cross-CLUB reste en outre masqué par la RLS PostgreSQL à la lecture.
     */
    private function ownedVenueOrRefuse(string $venueId, string $clubId, string $seasonId): Venue
    {
        $venue = $this->entityManager->getRepository(Venue::class)->findOneBy(['id' => $venueId]);
        if (!$venue instanceof Venue
            || $venue->getClubId() !== $clubId
            || $venue->getSeasonId() !== $seasonId) {
            throw new ImportRejectedException('Gymnase introuvable.', Response::HTTP_NOT_FOUND);
        }

        return $venue;
    }

    /**
     * D2: an in-place take_file un-submits a SUBMITTED/VALIDATED fixture to PLACED.
     * La coche FBI portait une mauvaise valeur : la rencontre retombe « à saisir », et
     * on POSE le mémo `fbiEcho` (« FBI affiche `$sourceValue` ») pour l'afficher sur la
     * ligne « à saisir » de la liste FBI. Sans rétrogradation (déjà PLACED/UNPLACED),
     * aucun mémo — il n'y a rien à re-saisir de plus qu'avant.
     */
    private function demoteSubmitted(Fixture $fixture, DateTimeImmutable $now, string $field, ?string $sourceValue): void
    {
        if (FixtureStatus::SUBMITTED === $fixture->getStatus() || FixtureStatus::VALIDATED === $fixture->getStatus()) {
            $fixture->setStatus(FixtureStatus::PLACED, $now);
            if (null !== $sourceValue) {
                $fixture->setFbiEcho(['field' => $field, 'value' => $sourceValue, 'at' => $now->format(DateTimeImmutable::ATOM)]);
            }
        }
    }

    /**
     * Fuzzy salle match (D13): normalized equality OR whole-word containment
     * either direction, reusing the {@see VenueLabelNormalizer::fuzzyMatches} idiom.
     * Degrades to « no deviation » when a side is empty. NAMED fallback if real-world
     * false positives appear: compare the stored fbiVenueLabel (old) to the new one
     * instead of the placed Venue name.
     */
    private function venueMatches(string $appLabel, string $fileLabel): bool
    {
        return $this->labelNormalizer->fuzzyMatches($appLabel, $fileLabel);
    }
}
