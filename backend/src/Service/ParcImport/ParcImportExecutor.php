<?php

declare(strict_types=1);

namespace App\Service\ParcImport;

use App\Controller\GroupReservationController;
use App\Entity\Club;
use App\Entity\PriorityTier;
use App\Entity\Reservation;
use App\Entity\Season;
use App\Entity\SharedTrainingBlock;
use App\Entity\SharedTrainingBlockTeam;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\Venue;
use App\Entity\VenueTrainingSlot;
use App\Enum\Gender;
use App\Service\Basketball\TeamCreationSuggester;
use App\Service\Basketball\VenueAliasResolver;
use App\Service\Basketball\VenueLabelNormalizer;
use App\Service\PlanEntitlements;
use App\Service\ReservationGroupOccupancy;
use App\Service\SoloReservationBudget;
use App\Service\SportCategoryResolver;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Lot correctif import IA (« G2 ») PR1 — l'IMPORT RÉEL transactionnel du parc, RECALÉ sur `parc-v2`.
 * L'aperçu (dry-run) est le métier de {@see ParcImportAnalyzer} ; ICI c'est l'ÉCRITURE : le parc
 * parc-v2 (une ligne par CASE) est DÉRIVÉ en sections par {@see ParcCaseDeriver} (contrat de
 * décisions INCHANGÉ), le gestionnaire a tranché l'aperçu, et {@see import} bascule les lignes
 * cochées en base en UNE SEULE transaction TOUT-OU-RIEN.
 *
 * Ordre d'écriture : gymnases → équipes → créneaux → blocs → réservations. Un gymnase naît SANS
 * adresse ; son `canSplit` est DÉRIVÉ (vrai si un créneau du parc y demande ≥ 2 équipes en
 * parallèle). La DURÉE d'une case est TRANSPORTÉE au créneau ; un bloc mutualisé porte le `groupe`
 * de la case en LABEL. Les COACHS ne sont plus extraits (D11) : une vieille section `coachs` d'un
 * ancien chatbot est IGNORÉE en silence (le dériveur ne la lit pas). Les gardes EXISTANTES sont
 * REJOUÉES depuis leurs maisons — jamais recopiées :
 *  - cap payant : {@see PlanEntitlements} (refus AVANT tout persist, tout-ou-rien) ;
 *  - occupation d'une réservation : {@see ReservationGroupOccupancy} ;
 *  - Σ des séances communes d'un bloc : {@see SoloReservationBudget} ;
 *  - chevauchement / capacité d'un créneau : même règle que {@see VenueTrainingSlotStateProcessor}.
 * Une ligne cochée invalide au moment de l'écriture → {@see UnprocessableEntityHttpException}
 * nommant la ligne (section + rang), et la transaction est annulée (rien écrit).
 *
 * `cibleId` apparie à une entité EXISTANTE, RE-VÉRIFIÉE tenant côté serveur (même club ET même
 * saison, patron A1/A10). Idempotence : une entité déjà présente est RÉUTILISÉE, jamais recréée.
 * Noms hostiles : tout `=`/`+`/`-`/`@` et toute tabulation ou retour-ligne de TÊTE sont retirés
 * avant écriture (le nom stocké ne déclenche jamais une formule à l'ouverture de l'export XLSX).
 *
 * Service PUR de contexte HTTP : il reçoit le club et la saison en paramètres (le NR bloquant
 * {@see ParcImportReservationSemanticsGateTest} l'appelle hors requête).
 */
final class ParcImportExecutor
{
    private const string LABEL_EQUIPES = 'équipe';

    private const string LABEL_GYMNASES = 'gymnase';

    private const string LABEL_CRENEAUX = 'créneau';

    private const string LABEL_MUTUALISATIONS = 'séance commune';

    private const string LABEL_RESERVATIONS = 'réservation';

    private const int DEFAULT_SESSIONS_PER_WEEK = 2;

    private const int DEFAULT_RESERVATION_DURATION = 90;

    private const int DEFAULT_SLOT_DURATION = 90;

    private const int DEFAULT_GROUP_RESERVATION_DURATION = 90;

    /** Catégorie « repère » d'une équipe importée sans catégorie — jamais un rattachement muet. */
    private const string UNFILED_CATEGORY = 'À classer';

    /** E3 — jour ISO (1-7) → nom en toutes lettres, pour NOMMER une ligne dans un message serveur. */
    private const array DAY_NAMES = [1 => 'lundi', 2 => 'mardi', 3 => 'mercredi', 4 => 'jeudi', 5 => 'vendredi', 6 => 'samedi', 7 => 'dimanche'];

    /** @var array<string, string> nom normalisé → venueId (créés dans ce run OU résolus) */
    private array $venueByName = [];

    /** @var array<string, string> nom normalisé → teamId */
    private array $teamByName = [];

    /**
     * PR A (D3) — teamId par rang de ligne « equipes » importée (créée OU appariée dans ce run) : résout
     * les références `import:<index>` d'une composition corrigée de séance commune (`corrections.equipes`).
     *
     * @var array<int, string>
     */
    private array $teamIdByEquipesIndex = [];

    /**
     * PR A (D4) — les refus de RÉSOLUTION (équipe/gymnase introuvable d'une ligne cochée) collectés en
     * UNE passe, toutes sections, puis levés AGRÉGÉS en fin de transaction (rien n'est écrit). Les gardes
     * d'ÉCRITURE (chevauchement, capacité, Σ séances, cap) restent fail-fast.
     *
     * @var list<string>
     */
    private array $resolutionErrors = [];

    /**
     * Les équipes actives du club AVANT l'import (snapshot pris à l'entrée). L'ambiguïté d'un libellé
     * (D5) se juge sur CE pool, PAS sur le pool vivant : sans ça, deux équipes-sœurs créées DANS le
     * même run (« U11M1 »/« U11M3 », distance 1) rendraient la seconde faussement « ambiguë ».
     *
     * @var list<array{id: string, nom: string}>
     */
    private array $preImportTeamPool = [];

    /**
     * Les gymnases AMBIGUS (≥ 2 candidats dans le seuil) par index de ligne « gymnases », jugés sur
     * l'état d'AVANT l'import (même parti pris que {@see $preImportTeamPool} pour les équipes) : sans
     * ça, un gymnase créé plus tôt dans le run rendrait une ligne suivante faussement « ambiguë ».
     *
     * @var array<int, bool>
     */
    private array $venueAmbiguousByIndex = [];

    private ?string $sportId = null;

    /** @var array<int, true>|null rangs valides (PriorityTier), chargés une fois pour valider un corrections.tierId. */
    private ?array $validTierIds = null;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly VenueAliasResolver $venueResolver,
        private readonly VenueLabelNormalizer $normalizer,
        private readonly ReservationGroupOccupancy $reservationGroupOccupancy,
        private readonly SoloReservationBudget $soloReservationBudget,
        private readonly PlanEntitlements $planEntitlements,
        private readonly SportCategoryResolver $sportCategoryResolver,
        private readonly GroupReservationController $groupReservationWriter,
        private readonly ParcCaseDeriver $deriver,
        private readonly TeamLabelMatcher $matcher,
        private readonly TeamCreationSuggester $suggester,
    ) {}

    /**
     * @param array<string, mixed>       $parc      le parc-v2 décodé, déjà validé en FORME et en bornes
     * @param list<array<string, mixed>> $decisions les choix de l'aperçu
     *
     * @return array{recap: array<string, array<string, int>>}
     */
    public function import(Club $club, string $seasonId, array $parc, array $decisions): array
    {
        $this->venueByName = [];
        $this->teamByName = [];
        $this->teamIdByEquipesIndex = [];
        $this->resolutionErrors = [];
        $this->sportId = null;
        // Snapshot AVANT toute écriture : l'ambiguïté se mesure sur l'état d'avant l'import (comme
        // l'analyzer), jamais sur les équipes créées au fil de ce run.
        $this->preImportTeamPool = $this->activeTeamPool();

        $derived = $this->deriver->derive($parc);
        $index = $this->indexDecisions($decisions);
        // PR A lot 2 (E1) — AVANT toute écriture : une séance commune dont la composition est redéfinie
        // (`corrections.equipes`) recompte les `seances` des équipes ajoutées/retirées, pour que les
        // équipes CRÉÉES naissent avec le bon nombre (1 indiv + N communes) et que la garde de budget
        // individuel ne refuse pas leur réservation. Une valeur `corrections.seances` posée à la main
        // l'emporte toujours ({@see sessionsOf}).
        $this->applyCompositionSessions($derived, $index);
        // Ambiguïté gymnase jugée AVANT toute écriture (rien n'est encore persisté ici) : snapshot de
        // l'état d'avant l'import, comme le pool d'équipes.
        $this->venueAmbiguousByIndex = $this->snapshotVenueAmbiguity($derived);

        /** @var array{recap: array<string, array<string, int>>} $result */
        $result = $this->entityManager->wrapInTransaction(function () use ($club, $seasonId, $derived, $index): array {
            // Cap payant AVANT tout persist (tout-ou-rien) : jamais « les premières passent ».
            $this->assertTeamCapNotExceeded($derived, $index, $club, $seasonId);

            $recap = [
                'gymnases' => $this->importVenues($derived, $index, $club, $seasonId),
                'equipes' => $this->importTeams($derived, $index, $club, $seasonId),
                'creneaux' => $this->importSlots($derived, $index, $club, $seasonId),
                'mutualisations' => $this->importBlocks($derived, $index, $club, $seasonId),
                'reservations' => $this->importReservations($derived, $index, $club, $seasonId),
            ];

            // D4 — refus de résolution AGRÉGÉ : si des lignes cochées ont cité une entité introuvable,
            // on lève la liste d'un coup (la transaction est annulée, rien n'est écrit).
            if ([] !== $this->resolutionErrors) {
                throw new ParcImportResolutionException($this->resolutionErrors);
            }

            return ['recap' => $recap];
        });

        return $result;
    }

    // ───────────────────────────── Sections ─────────────────────────────

    /**
     * @param array<string, mixed>                                                                                                                      $derived
     * @param array<string, array<int, array{coche: bool, creer: bool, cibleId: string|null, venueId: string|null, corrections: array<string, mixed>}>> $index
     *
     * @return array<string, int>
     */
    private function importVenues(array $derived, array $index, Club $club, string $seasonId): array
    {
        $recap = $this->emptyRecap();
        foreach ($this->lines($derived, 'gymnases') as $i => $entry) {
            $decision = $index['gymnases'][$i] ?? null;
            if (null === $decision || !$decision['coche']) {
                ++$recap['ignores'];

                continue;
            }
            $nom = $this->neutralize($this->stringField($entry, 'nom'));

            if (null !== $decision['cibleId']) {
                $venue = $this->ownedVenue($decision['cibleId'], $club, $seasonId, 'gymnases', $i);
                $this->venueByName[$this->normalizer->normalize($nom)] = $venue->getId();
                ++$recap['apparies'];

                continue;
            }

            $existing = $this->resolveExistingVenueId($nom);
            if (null !== $existing) {
                $this->venueByName[$this->normalizer->normalize($nom)] = $existing;
                ++$recap['apparies'];

                continue;
            }

            // Symétrie gymnase du refus d'ambiguïté équipe (3a) : un libellé qui tombe dans le seuil
            // flou de ≥ 2 gymnases, coché SANS cible NI création explicite, ne doit jamais créer un
            // doublon en silence (422 nommant la ligne). « Créer » explicite (`creer`) lève le refus.
            if (true !== $decision['creer'] && ($this->venueAmbiguousByIndex[$i] ?? false)) {
                $this->fail('gymnases', $i, 'appariement ambigu — choisissez le gymnase à apparier dans l\'aperçu, ou corrigez le nom.');
            }

            $venue = (new Venue)
                ->setClubId($club->getId())
                ->setSeasonId($seasonId)
                ->setName($nom)
                ->setSource('manual')
                ->setIsActive(true)
                ->setCanSplit($this->venueCanSplit($derived, $nom));
            $this->entityManager->persist($venue);
            $this->entityManager->flush();
            $this->venueByName[$this->normalizer->normalize($nom)] = $venue->getId();
            ++$recap['crees'];
        }

        return $recap;
    }

    /**
     * @param array<string, mixed>                                                                                                                      $derived
     * @param array<string, array<int, array{coche: bool, creer: bool, cibleId: string|null, venueId: string|null, corrections: array<string, mixed>}>> $index
     *
     * @return array<string, int>
     */
    private function importTeams(array $derived, array $index, Club $club, string $seasonId): array
    {
        $recap = $this->emptyRecap();
        foreach ($this->lines($derived, 'equipes') as $i => $entry) {
            $decision = $index['equipes'][$i] ?? null;
            if (null === $decision || !$decision['coche']) {
                ++$recap['ignores'];

                continue;
            }
            $nom = $this->neutralize($this->stringField($entry, 'nom'));

            $existingId = $decision['cibleId'] ?? $this->resolveExistingTeamId($nom);

            // PR1 (D5) — un libellé AMBIGU (≥ 2 candidats dans le seuil) coché SANS cible choisie ne
            // doit JAMAIS créer un doublon en silence : refus 422 nommant la ligne, rien écrit. Tranché
            // (le gestionnaire a choisi un candidat, `cibleId`), il passe par l'appariement ci-dessous.
            // « Créer » explicite (`creer`) ASSUME la création : il lève le refus d'ambiguïté (3a).
            if (null === $existingId && null === $decision['cibleId'] && true !== $decision['creer'] && $this->labelIsAmbiguous($nom)) {
                $this->fail('equipes', $i, 'appariement ambigu — choisissez l\'équipe à apparier dans l\'aperçu, ou corrigez le nom.');
            }

            if (null !== $existingId) {
                $team = $this->ownedTeam($existingId, $club, $seasonId, 'equipes', $i);
                $this->teamByName[$this->normalizer->normalize($nom)] = $team->getId();
                $this->teamIdByEquipesIndex[$i] = $team->getId();
                // Équipe appariée : SEUL le nombre de séances peut bouger, et seulement si la mise à
                // jour a été acceptée (corrections.seances). Jamais le genre, la catégorie, le niveau.
                $sessions = $decision['corrections']['seances'] ?? null;
                if (\is_int($sessions) && $sessions >= 1 && $sessions !== $team->getSessionsPerWeek()) {
                    $team->setSessionsPerWeek($sessions);
                    $this->entityManager->flush();
                    ++$recap['misAJour'];
                } else {
                    ++$recap['apparies'];
                }

                continue;
            }

            // Refonte PR2 (D9) — une équipe CRÉÉE honore corrections.{tierId, genre, ctc, ctcPartner} ;
            // les défauts viennent de la règle FBI unique ({@see TeamCreationSuggester}, plus de rang 5
            // en dur). Le genre est OBLIGATOIRE : une création sans genre connu (ni dérivé ni corrigé)
            // refuse l'import (422 nommant la ligne) ; un tierId/genre forgé → 422 — tout AVANT persist.
            $corrections = $decision['corrections'];
            $gender = $this->createdTeamGender($corrections, $entry, $i);
            $tierId = $this->createdTeamTierId($corrections, $nom, $this->stringField($entry, 'categorie'), $i);
            $isCtc = true === ($corrections['ctc'] ?? null);
            $ctcPartner = $this->createdTeamCtcPartner($corrections, $isCtc, $i);

            $team = (new Team)
                ->setClubId($club->getId())
                ->setSeasonId($seasonId)
                ->setSportCategoryId($this->categoryIdForCreatedTeam($entry, $club))
                ->setPriorityTierId($tierId)
                ->setName($nom)
                ->setSessionsPerWeek($this->sessionsOf($entry, $decision))
                ->setIsActive(true)
                ->setGender($gender)
                ->setIsCtc($isCtc)
                ->setCtcPartner($ctcPartner);
            $this->entityManager->persist($team);
            $this->entityManager->flush();
            $this->teamByName[$this->normalizer->normalize($nom)] = $team->getId();
            $this->teamIdByEquipesIndex[$i] = $team->getId();
            ++$recap['crees'];
        }

        return $recap;
    }

    /**
     * @param array<string, mixed>                                                                                                                      $derived
     * @param array<string, array<int, array{coche: bool, creer: bool, cibleId: string|null, venueId: string|null, corrections: array<string, mixed>}>> $index
     *
     * @return array<string, int>
     */
    private function importSlots(array $derived, array $index, Club $club, string $seasonId): array
    {
        $recap = $this->emptyRecap();
        foreach ($this->lines($derived, 'creneaux') as $i => $entry) {
            $decision = $index['creneaux'][$i] ?? null;
            if (null === $decision || !$decision['coche']) {
                ++$recap['ignores'];

                continue;
            }
            $corrections = $decision['corrections'];

            $venueId = null !== $decision['cibleId']
                ? $this->ownedVenue($decision['cibleId'], $club, $seasonId, 'creneaux', $i)->getId()
                : $this->venueIdForName($this->stringField($entry, 'gymnase'));
            if (null === $venueId) {
                // D4 — résolution introuvable AGRÉGÉE (pas une garde d'écriture) : on collecte et on saute.
                $this->collectResolution('creneaux', $entry, \sprintf('le gymnase « %s » est introuvable.', $this->stringField($entry, 'gymnase')));
                ++$recap['ignores'];

                continue;
            }

            $day = $this->intCorrectionOr($corrections, 'jour', $entry, 'jour');
            $start = $this->timeCorrectionOr($corrections, 'debut', $entry, 'debut');
            $duration = $this->intCorrectionOr($corrections, 'duree', $entry, 'duree') ?? self::DEFAULT_SLOT_DURATION;
            $capacity = $this->parallelOf($entry);

            if (null === $day || null === $start) {
                $this->fail('creneaux', $i, 'Jour ou heure de ce créneau illisible.');
            }

            // Idempotence : un créneau identique (gymnase + jour + heure) existe déjà → réutilisé.
            if ($this->slotExists($venueId, $day, $start)) {
                ++$recap['apparies'];

                continue;
            }

            // Capacité (rejeu de VenueTrainingSlotStateProcessor::validateCapacityForVenue) :
            // une capacité ≥ 2 n'est permise que sur un gymnase divisible.
            if ($capacity >= 2) {
                $venue = $this->entityManager->find(Venue::class, $venueId);
                if ($venue instanceof Venue && !$venue->getCanSplit()) {
                    $this->fail('creneaux', $i, 'Un créneau ne peut accueillir 2 équipes ou plus que si le gymnase est déclaré divisible.');
                }
            }

            // Chevauchement (rejeu de VenueTrainingSlotStateProcessor::validateNoOverlap) : deux
            // créneaux du MÊME gymnase le MÊME jour ne peuvent jamais partager un horaire.
            if ($this->slotOverlaps($venueId, $day, $start, $duration)) {
                $this->fail('creneaux', $i, 'Ce créneau en chevauche un autre du même gymnase ce jour-là.');
            }

            $slot = (new VenueTrainingSlot)
                ->setClubId($club->getId())
                ->setSeasonId($seasonId)
                ->setVenueId($venueId)
                ->setDayOfWeek($day)
                ->setStartTime(new DateTimeImmutable($start))
                ->setDurationMinutes($duration)
                ->setCapacity(max(1, $capacity));
            $this->entityManager->persist($slot);
            $this->entityManager->flush();
            ++$recap['crees'];
        }

        return $recap;
    }

    /**
     * @param array<string, mixed>                                                                                                                      $derived
     * @param array<string, array<int, array{coche: bool, creer: bool, cibleId: string|null, venueId: string|null, corrections: array<string, mixed>}>> $index
     *
     * @return array<string, int>
     */
    private function importBlocks(array $derived, array $index, Club $club, string $seasonId): array
    {
        $recap = $this->emptyRecap();
        foreach ($this->lines($derived, 'mutualisations') as $i => $entry) {
            $decision = $index['mutualisations'][$i] ?? null;
            if (null === $decision || !$decision['coche']) {
                ++$recap['ignores'];

                continue;
            }
            if (!\is_array($entry)) {
                ++$recap['ignores'];

                continue;
            }

            // D3 — composition REDÉFINIE à la main (`corrections.equipes`, liste ≥ 2 de `uuid |
            // import:<index>`) : elle REMPLACE les équipes lues de la case (incident ALAP — « SM shooting »
            // était le NOM du groupe, pas une équipe). Abus (< 2, id forgé, import hors bornes) → 422
            // fail-fast nommant la ligne. Sinon, résolution par NOM, avec collecte D4 des introuvables.
            $correctedEquipes = $decision['corrections']['equipes'] ?? null;
            if (\is_array($correctedEquipes)) {
                $teamIds = $this->resolveCorrectedEquipes($correctedEquipes, $club, $seasonId, $i);
            } else {
                $teamIds = [];
                $missing = false;
                foreach ($this->stringList($entry['equipes'] ?? null) as $name) {
                    $id = $this->teamIdForName($name);
                    if (null === $id) {
                        $this->collectResolution('mutualisations', $entry, \sprintf('l\'équipe « %s » est introuvable.', $name));
                        $missing = true;
                    } else {
                        $teamIds[] = $id;
                    }
                }
                if ($missing) {
                    ++$recap['ignores'];

                    continue;
                }
                $teamIds = $this->sortedUnique($teamIds);
            }
            if ([] === $teamIds) {
                ++$recap['ignores'];

                continue;
            }
            $commonSessions = \is_int($entry['seances_communes'] ?? null) ? $entry['seances_communes'] : 1;
            // D8 — le libellé CORRIGÉ par le gestionnaire (corrections.groupe) l'emporte sur le groupe
            // DÉRIVÉ de la case ; borné à la longueur max de la colonne, comme les autres corrections.
            $label = $this->blockLabel($decision['corrections'], $entry);

            $block = $this->existingBlockForTeams($club->getId(), $seasonId, $teamIds);
            if ($block instanceof SharedTrainingBlock) {
                // Idempotence : un bloc du socle portant exactement ces équipes existe déjà → réutilisé.
                ++$recap['apparies'];
            } else {
                // Garde Σ séances communes ≤ séances d'un membre, rejouée depuis sa maison unique,
                // AVANT toute écriture (un dépassement refuse l'import, rien n'est écrit).
                foreach ($this->soloReservationBudget->forTeamsWithBlockSubstituted($teamIds, $club->getId(), $seasonId, null, null, $commonSessions) as $budget) {
                    if ($budget->block > $budget->effective) {
                        $this->fail('mutualisations', $i, 'Les séances communes de cette mutualisation dépassent les séances hebdomadaires d\'une des équipes.');
                    }
                }

                $block = (new SharedTrainingBlock)
                    ->setClubId($club->getId())
                    ->setSeasonId($seasonId)
                    ->setSchedulePlanId(null)
                    ->setCommonSessions($commonSessions)
                    ->setLabel($label);
                $this->entityManager->persist($block);
                foreach ($teamIds as $teamId) {
                    $member = (new SharedTrainingBlockTeam)
                        ->setClubId($club->getId())
                        ->setSeasonId($seasonId)
                        ->setSchedulePlanId(null)
                        ->setBlockId($block->getId())
                        ->setTeamId($teamId);
                    $this->entityManager->persist($member);
                }
                $this->entityManager->flush();
                ++$recap['crees'];
            }

            // P4-363 — le gymnase de la réservation de groupe vient du `venueId` CHOISI (sélecteur de
            // la ligne), JAMAIS du `cibleId` : pour une mutualisation, `cibleId` désigne le BLOC apparié
            // (déjà retrouvé par les équipes via `existingBlockForTeams`), pas un gymnase.
            $this->poseGroupReservation($block, $teamIds, $entry, $decision['venueId'], $decision['corrections'], $club, $seasonId, $i);
        }

        return $recap;
    }

    /**
     * Pose la réservation de groupe du bloc sur sa case, via {@see GroupReservationController}.
     *
     * @param list<string>         $teamIds
     * @param array<string, mixed> $corrections
     */
    private function poseGroupReservation(SharedTrainingBlock $block, array $teamIds, mixed $entry, ?string $chosenVenueId, array $corrections, Club $club, string $seasonId, int $index): void
    {
        // Gymnase CHOISI (`venueId`), re-vérifié tenant côté serveur ; à défaut, résolution par le NOM
        // de la case (comportement d'avant le sélecteur de gymnase de la ligne).
        $venueId = null !== $chosenVenueId
            ? $this->ownedVenue($chosenVenueId, $club, $seasonId, 'mutualisations', $index)->getId()
            : $this->venueIdForName($this->stringField($entry, 'gymnase'));
        $day = $this->intCorrectionOr($corrections, 'jour', $entry, 'jour');
        $start = $this->timeCorrectionOr($corrections, 'debut', $entry, 'debut');
        // Case non résolue (gymnase/jour/heure) : le bloc est déclaré, sa réservation reste à poser à
        // la main. Retours SÉPARÉS (et non un `||` que Rector réécrirait en in_array, perdant le
        // narrowing non-null dont ont besoin les appels ci-dessous).
        if (null === $venueId) {
            return;
        }
        if (null === $day) {
            return;
        }
        if (null === $start) {
            return;
        }
        $duration = $this->intCorrectionOr($corrections, 'duree', $entry, 'duree') ?? self::DEFAULT_GROUP_RESERVATION_DURATION;

        if ($this->allMembersReservedOnCase($teamIds, $venueId, $day, $start)) {
            // Déjà posée (rejeu) : aucun doublon. Mais ce retour saute le plafond de la garde ci-dessous :
            // un groupe à 1 séance sur des équipes déjà réservées ensemble sur deux cases est refusé ici
            // (décision fondateur 2026-10-30, l'import ne retire jamais une réservation en silence).
            $reservedSessions = $this->reservationGroupOccupancy->groupCompleteCaseCount(array_fill_keys($teamIds, true), null);
            if ($reservedSessions > $block->getCommonSessions()) {
                $this->fail('mutualisations', $index, ReservationGroupOccupancy::belowReservedSessionsMessage($reservedSessions));
            }

            return;
        }

        try {
            $this->reservationGroupOccupancy->assertBlockReservationAllowed($block, $teamIds, $venueId, $day, new DateTimeImmutable($start), null);
            $this->groupReservationWriter->writeBlockReservationsAtomically(
                $club->getId(),
                $seasonId,
                $teamIds,
                $venueId,
                $day,
                new DateTimeImmutable($start),
                $duration,
                null,
            );
        } catch (UnprocessableEntityHttpException $exception) {
            $this->fail('mutualisations', $index, $exception->getMessage());
        }
    }

    /**
     * @param list<string> $teamIds
     */
    private function allMembersReservedOnCase(array $teamIds, string $venueId, int $day, string $startHhmm): bool
    {
        foreach ($teamIds as $teamId) {
            if (!$this->reservationExists($teamId, $venueId, $day, $startHhmm)) {
                return false;
            }
        }

        return [] !== $teamIds;
    }

    /**
     * @param array<string, mixed>                                                                                                                      $derived
     * @param array<string, array<int, array{coche: bool, creer: bool, cibleId: string|null, venueId: string|null, corrections: array<string, mixed>}>> $index
     *
     * @return array<string, int>
     */
    private function importReservations(array $derived, array $index, Club $club, string $seasonId): array
    {
        $recap = $this->emptyRecap();
        foreach ($this->lines($derived, 'reservations') as $i => $entry) {
            $decision = $index['reservations'][$i] ?? null;
            if (null === $decision || !$decision['coche']) {
                ++$recap['ignores'];

                continue;
            }
            $corrections = $decision['corrections'];

            $teamId = $this->teamIdForName($this->stringField($entry, 'equipe'));
            if (null === $teamId) {
                // D4 — résolution introuvable AGRÉGÉE (pas une garde d'écriture) : on collecte et on saute.
                $this->collectResolution('reservations', $entry, \sprintf('l\'équipe « %s » est introuvable.', $this->stringField($entry, 'equipe')));
                ++$recap['ignores'];

                continue;
            }

            $venueId = null !== $decision['cibleId']
                ? $this->ownedVenue($decision['cibleId'], $club, $seasonId, 'reservations', $i)->getId()
                : $this->venueIdForName($this->stringField($entry, 'gymnase'));
            if (null === $venueId) {
                $this->collectResolution('reservations', $entry, \sprintf('le gymnase « %s » est introuvable.', $this->stringField($entry, 'gymnase')));
                ++$recap['ignores'];

                continue;
            }

            $day = $this->intCorrectionOr($corrections, 'jour', $entry, 'jour');
            $start = $this->timeCorrectionOr($corrections, 'debut', $entry, 'debut');
            $duration = $this->intCorrectionOr($corrections, 'duree', $entry, 'duree') ?? self::DEFAULT_RESERVATION_DURATION;
            if (null === $day || null === $start) {
                $this->fail('reservations', $i, 'Jour ou heure de cette réservation illisible.');
            }

            // Idempotence : une réservation socle identique (équipe + gymnase + jour + heure) existe
            // déjà → réutilisée, jamais recréée.
            if ($this->reservationExists($teamId, $venueId, $day, $start)) {
                ++$recap['apparies'];

                continue;
            }

            // Occupation / capacité / budget solo, rejoués depuis leur maison unique. Une violation
            // devient un 422 nommant la ligne (la garde lève UnprocessableEntityHttpException).
            try {
                $this->reservationGroupOccupancy->assertIndividualReservationAllowed(
                    $teamId,
                    $venueId,
                    $day,
                    new DateTimeImmutable($start),
                    null,
                    $club->getId(),
                    $seasonId,
                    'import',
                );
            } catch (UnprocessableEntityHttpException $exception) {
                $this->fail('reservations', $i, $exception->getMessage());
            }

            $reservation = (new Reservation)
                ->setClubId($club->getId())
                ->setSeasonId($seasonId)
                ->setSchedulePlanId(null)
                ->setTeamId($teamId)
                ->setVenueId($venueId)
                ->setDayOfWeek($day)
                ->setStartTime(new DateTimeImmutable($start))
                ->setDurationMinutes($duration);
            $this->entityManager->persist($reservation);
            $this->entityManager->flush();
            ++$recap['crees'];
        }

        return $recap;
    }

    // ───────────────────────────── Cap payant ─────────────────────────────

    /**
     * @param array<string, mixed>                                                                                                                      $derived
     * @param array<string, array<int, array{coche: bool, creer: bool, cibleId: string|null, venueId: string|null, corrections: array<string, mixed>}>> $index
     */
    private function assertTeamCapNotExceeded(array $derived, array $index, Club $club, string $seasonId): void
    {
        $season = $this->entityManager->find(Season::class, $seasonId);
        if (!$season instanceof Season) {
            return; // sans saison résolue, le cap ne s'applique pas (parité analyzer).
        }
        $remaining = $this->planEntitlements->remainingTeamSlots($club, $season);
        if (null === $remaining) {
            return; // offre illimitée.
        }

        $toCreate = 0;
        foreach ($this->lines($derived, 'equipes') as $i => $entry) {
            $decision = $index['equipes'][$i] ?? null;
            if (null === $decision || !$decision['coche']) {
                continue;
            }
            $nom = $this->neutralize($this->stringField($entry, 'nom'));
            if (null !== ($decision['cibleId'] ?? $this->resolveExistingTeamId($nom))) {
                continue; // appariée : ne consomme aucune place neuve.
            }
            ++$toCreate;
        }

        if ($toCreate > $remaining) {
            $message = $this->planEntitlements->teamCapExceededMessage($club, $season, $toCreate)
                ?? 'Le nombre d\'équipes à créer dépasse la limite de votre offre.';

            throw new UnprocessableEntityHttpException($message);
        }
    }

    // ───────────────────────────── Résolution ─────────────────────────────

    private function venueIdForName(string $name): ?string
    {
        $key = $this->normalizer->normalize($name);
        if ('' === $key) {
            return null;
        }
        if (isset($this->venueByName[$key])) {
            return $this->venueByName[$key];
        }
        $resolved = $this->resolveExistingVenueId($name);
        if (null !== $resolved) {
            $this->venueByName[$key] = $resolved;
        }

        return $resolved;
    }

    private function teamIdForName(string $name): ?string
    {
        $key = $this->normalizer->normalize($name);
        if ('' === $key) {
            return null;
        }
        if (isset($this->teamByName[$key])) {
            return $this->teamByName[$key];
        }
        $resolved = $this->resolveExistingTeamId($name);
        if (null !== $resolved) {
            $this->teamByName[$key] = $resolved;
        }

        return $resolved;
    }

    private function resolveExistingVenueId(string $name): ?string
    {
        $exact = $this->venueResolver->exactMatch($name);
        if (null !== $exact) {
            return $exact;
        }
        $candidates = $this->venueResolver->candidates($name);

        return 1 === \count($candidates) ? $candidates[0] : null;
    }

    /**
     * Résolution AUTOMATIQUE d'une équipe (sans décision humaine) : via {@see TeamLabelMatcher},
     * SEUL un appariement EXACT (canonique) la résout. Un candidat FLOU (« suggere ») ne s'applique
     * JAMAIS d'office (cadrage G2 D5 « suggéré, à confirmer ») — sinon deux équipes distinctes
     * écrites de façon proche (« U15M 1 » / « U15 M2 », distance 1) se fondraient à l'import. Une
     * suggestion que le gestionnaire a confirmée arrive par le `cibleId` de la décision, pas ici.
     */
    private function resolveExistingTeamId(string $name): ?string
    {
        $verdict = $this->matcher->match($name, $this->activeTeamPool());

        return 'apparie' === $verdict['statut'] ? $verdict['cibleId'] : null;
    }

    /**
     * Vrai si le libellé tombe dans le seuil flou de DEUX équipes ou plus du club (verdict `ambigu`
     * de {@see TeamLabelMatcher}) : le serveur ne peut alors ni l'apparier d'office ni le créer en
     * silence — le gestionnaire doit trancher dans l'aperçu (PR1 D5).
     */
    private function labelIsAmbiguous(string $name): bool
    {
        return 'ambigu' === $this->matcher->match($name, $this->preImportTeamPool)['statut'];
    }

    /**
     * Pour chaque ligne « gymnases » DÉRIVÉE, mémorise si son nom est ambigu (≥ 2 candidats flous, et
     * aucun appariement exact) d'après l'état d'AVANT l'import — même verdict que {@see ParcImportAnalyzer::verdict}.
     * Calculé hors transaction, donc jamais contaminé par un gymnase créé au fil du run.
     *
     * @param array<string, mixed> $derived
     *
     * @return array<int, bool>
     */
    private function snapshotVenueAmbiguity(array $derived): array
    {
        $out = [];
        foreach ($this->lines($derived, 'gymnases') as $i => $entry) {
            $nom = $this->neutralize($this->stringField($entry, 'nom'));
            if ('' === $nom || null !== $this->venueResolver->exactMatch($nom)) {
                $out[$i] = false;

                continue;
            }
            $out[$i] = \count(array_values(array_unique($this->venueResolver->candidates($nom)))) >= 2;
        }

        return $out;
    }

    private function ownedVenue(string $venueId, Club $club, string $seasonId, string $section, int $index): Venue
    {
        $venue = $this->entityManager->getRepository(Venue::class)->find($venueId);
        if (!$venue instanceof Venue || $venue->getClubId() !== $club->getId() || $venue->getSeasonId() !== $seasonId) {
            $this->fail($section, $index, 'Gymnase introuvable pour cet appariement.');
        }

        return $venue;
    }

    private function ownedTeam(string $teamId, Club $club, string $seasonId, string $section, int $index): Team
    {
        $team = $this->entityManager->getRepository(Team::class)->find($teamId);
        if (!$team instanceof Team || $team->getClubId() !== $club->getId() || $team->getSeasonId() !== $seasonId) {
            $this->fail($section, $index, 'Équipe introuvable pour cet appariement.');
        }

        return $team;
    }

    /**
     * PR A (D3) — résout une composition de séance commune REDÉFINIE (`corrections.equipes`) : une liste
     * de ≥ 2 entrées, chacune un UUID d'équipe EXISTANTE (re-vérifiée tenant par {@see ownedTeam} — la
     * garde du NR §7.1) OU `import:<index>` vers une ligne « equipes » gardée de ce run. Toute forme
     * invalide (< 2, id forgé, `import:<index>` hors bornes ou non gardée) → 422 fail-fast nommant la
     * ligne, rien écrit — c'est un abus du canal, jamais une résolution agrégée.
     *
     * @param array<array-key, mixed> $entries
     *
     * @return list<string>
     */
    private function resolveCorrectedEquipes(array $entries, Club $club, string $seasonId, int $index): array
    {
        $ids = [];
        foreach ($entries as $entry) {
            if (!\is_string($entry) || '' === trim($entry)) {
                $this->fail('mutualisations', $index, 'une équipe de la composition est invalide.');
            }
            $entry = trim($entry);
            if (str_starts_with($entry, 'import:')) {
                $n = substr($entry, 7);
                if ('' === $n || 1 !== preg_match('/^\d+$/', $n) || !isset($this->teamIdByEquipesIndex[(int) $n])) {
                    $this->fail('mutualisations', $index, 'une équipe créée référencée par cette séance commune est introuvable.');
                }
                $ids[] = $this->teamIdByEquipesIndex[(int) $n];
            } else {
                $ids[] = $this->ownedTeam($entry, $club, $seasonId, 'mutualisations', $index)->getId();
            }
        }
        $ids = $this->sortedUnique($ids);
        if (\count($ids) < 2) {
            $this->fail('mutualisations', $index, 'une séance commune doit regrouper au moins deux équipes.');
        }

        return $ids;
    }

    /**
     * PR A lot 2 (E1) — AVANT l'écriture : si une séance commune redéfinit sa composition
     * (`corrections.equipes`), substitue les équipes LUES par la composition REDÉFINIE (en NOMS,
     * `import:<index>` → nom de la ligne Équipes dérivée, UUID → nom de l'équipe du club) puis recompte
     * `seances`/`seances_communes` ({@see ParcCaseDeriver::recomputeDerivedSessions}). Ainsi une équipe
     * ajoutée à la séance commune NAÎT avec le bon nombre de séances (1 indiv + N communes), et la garde
     * de budget individuel ne refuse plus sa réservation. Une composition < 2 résolues est laissée telle
     * quelle (l'abus est refusé plus loin par {@see resolveCorrectedEquipes}).
     *
     * @param array<string, mixed>                                                                                                                      $derived
     * @param array<string, array<int, array{coche: bool, creer: bool, cibleId: string|null, venueId: string|null, corrections: array<string, mixed>}>> $index
     */
    private function applyCompositionSessions(array &$derived, array $index): void
    {
        $mutualisations = $derived['mutualisations'] ?? null;
        if (!\is_array($mutualisations)) {
            return;
        }
        $equipesLines = \is_array($derived['equipes'] ?? null) ? $derived['equipes'] : [];
        $changed = false;
        foreach ($index['mutualisations'] ?? [] as $i => $decision) {
            if (!isset($mutualisations[$i]) || !\is_array($mutualisations[$i])) {
                continue;
            }
            $equipes = $decision['corrections']['equipes'] ?? null;
            if (!\is_array($equipes)) {
                continue;
            }
            $names = $this->compositionNames($equipes, $equipesLines);
            if (\count($names) < 2) {
                continue;
            }
            $mutualisations[$i]['equipes'] = $names;
            $changed = true;
        }
        if ($changed) {
            $derived['mutualisations'] = $mutualisations;
            /** @var array{gymnases: list<array{nom: string}>, equipes: list<array{nom: string, genre: ?string, categorie: string, seances: int}>, creneaux: list<array{gymnase: string, jour: int, debut: string, duree: int, equipes_en_parallele: int}>, reservations: list<array{equipe: string, gymnase: string, jour: int, debut: string, duree: int}>, mutualisations: list<array{equipes: list<string>, groupe: string|null, gymnase: string, jour: int, debut: string, duree: int, seances_communes: int}>, alertes: list<array{type: string, message: string}>} $typed */
            $typed = $derived;
            $this->deriver->recomputeDerivedSessions($typed);
            $derived = $typed;
        }
    }

    /**
     * Résout une composition `corrections.equipes` en NOMS : `import:<index>` → nom de la ligne Équipes
     * dérivée, UUID → nom de l'équipe du club (RLS tenant ; une équipe étrangère ne charge pas). Les
     * entrées non résolues sont omises. Sert UNIQUEMENT au recompte des séances (le membership réel du
     * bloc se résout en IDS par {@see resolveCorrectedEquipes}).
     *
     * @param array<array-key, mixed> $entries
     * @param array<array-key, mixed> $equipesLines
     *
     * @return list<string>
     */
    private function compositionNames(array $entries, array $equipesLines): array
    {
        $names = [];
        foreach ($entries as $entry) {
            if (!\is_string($entry) || '' === trim($entry)) {
                continue;
            }
            $entry = trim($entry);
            if (str_starts_with($entry, 'import:')) {
                $n = substr($entry, 7);
                if (1 === preg_match('/^\d+$/', $n) && isset($equipesLines[(int) $n])) {
                    $nom = $this->stringField($equipesLines[(int) $n], 'nom');
                    if ('' !== $nom) {
                        $names[] = $nom;
                    }
                }

                continue;
            }
            $team = $this->entityManager->find(Team::class, $entry);
            if ($team instanceof Team) {
                $names[] = $team->getName();
            }
        }

        return $names;
    }

    /**
     * D4 + E3 — collecte un refus de RÉSOLUTION (introuvable), nommant la LIGNE (type + case + gymnase),
     * JAMAIS « n°X ». Agrégé et levé en fin de transaction par {@see import}.
     */
    private function collectResolution(string $section, mixed $entry, string $detail): void
    {
        $this->resolutionErrors[] = \sprintf('%s : %s', $this->lineName($section, $entry), $detail);
    }

    /**
     * E3 (essai fondateur 30/10) — NOMME une ligne dans un message serveur, jamais « n°X » :
     * « Séance commune SF1 + SM1 + SM2 · lundi 20:30 · Léon Jouhaux », « Réservation U13M1 · mardi
     * 18:00 · Léon Jouhaux », « Créneau <gymnase> · <jour> <HH:MM> », « Équipe <nom> », « Gymnase <nom> ».
     * Heure au format HH:MM (telle que lue). Jour en toutes lettres (foyer {@see self::DAY_NAMES}).
     */
    private function lineName(string $section, mixed $entry): string
    {
        $e = \is_array($entry) ? $entry : [];
        $when = $this->whenSuffix($e);
        $venue = $this->stringField($e, 'gymnase');
        $venueSuffix = '' !== $venue ? ' · ' . $venue : '';

        return match ($section) {
            'mutualisations' => 'Séance commune ' . implode(' + ', $this->stringList($e['equipes'] ?? null)) . $when . $venueSuffix,
            'reservations' => 'Réservation ' . $this->stringField($e, 'equipe') . $when . $venueSuffix,
            'creneaux' => trim('Créneau ' . $venue . $when),
            'equipes' => trim('Équipe ' . $this->stringField($e, 'nom')),
            'gymnases' => trim('Gymnase ' . $this->stringField($e, 'nom')),
            default => $this->sectionLabel($section),
        };
    }

    /**
     * « · lundi 20:30 » depuis `jour`/`debut` d'une case (vide si illisibles). Heure brute (déjà HH:MM).
     *
     * @param array<string, mixed> $entry
     */
    private function whenSuffix(array $entry): string
    {
        $jour = \is_int($entry['jour'] ?? null) ? $entry['jour'] : null;
        $debut = $this->stringField($entry, 'debut');
        $day = null !== $jour ? (self::DAY_NAMES[$jour] ?? '') : '';
        $parts = array_values(array_filter([$day, $debut], static fn (string $p): bool => '' !== $p));

        return [] === $parts ? '' : ' · ' . implode(' ', $parts);
    }

    // ───────────────────────────── Idempotence ─────────────────────────────

    private function slotExists(string $venueId, int $day, string $startHhmm): bool
    {
        /** @var list<VenueTrainingSlot> $rows */
        $rows = $this->entityManager->getRepository(VenueTrainingSlot::class)->findBy([
            'venueId' => $venueId,
            'dayOfWeek' => $day,
            'schedulePlanId' => null,
        ]);
        foreach ($rows as $slot) {
            if ($slot->getStartTime()->format('H:i') === $startHhmm) {
                return true;
            }
        }

        return false;
    }

    private function slotOverlaps(string $venueId, int $day, string $startHhmm, int $duration): bool
    {
        $start = $this->minutesOf($startHhmm);
        if (null === $start) {
            return false;
        }
        $end = $start + $duration;

        /** @var list<VenueTrainingSlot> $rows */
        $rows = $this->entityManager->getRepository(VenueTrainingSlot::class)->findBy([
            'venueId' => $venueId,
            'dayOfWeek' => $day,
            'schedulePlanId' => null,
        ]);
        foreach ($rows as $slot) {
            $otherStart = (int) $slot->getStartTime()->format('H') * 60 + (int) $slot->getStartTime()->format('i');
            $otherEnd = $otherStart + $slot->getDurationMinutes();
            if ($start < $otherEnd && $otherStart < $end) {
                return true;
            }
        }

        return false;
    }

    private function reservationExists(string $teamId, string $venueId, int $day, string $startHhmm): bool
    {
        /** @var list<Reservation> $rows */
        $rows = $this->entityManager->getRepository(Reservation::class)->findBy([
            'teamId' => $teamId,
            'venueId' => $venueId,
            'dayOfWeek' => $day,
            'schedulePlanId' => null,
        ]);
        foreach ($rows as $reservation) {
            if ($reservation->getStartTime()->format('H:i') === $startHhmm) {
                return true;
            }
        }

        return false;
    }

    /**
     * Le bloc du socle portant EXACTEMENT cet ensemble d'équipes, ou null — pour l'apparier plutôt
     * que d'en créer un second (idempotence des mutualisations).
     *
     * @param list<string> $teamIds déjà triés et dédupliqués
     */
    private function existingBlockForTeams(string $clubId, string $seasonId, array $teamIds): ?SharedTrainingBlock
    {
        $blocks = $this->entityManager->getRepository(SharedTrainingBlock::class)
            ->findBy(['clubId' => $clubId, 'seasonId' => $seasonId, 'schedulePlanId' => null]);
        $memberRepository = $this->entityManager->getRepository(SharedTrainingBlockTeam::class);

        foreach ($blocks as $block) {
            $members = [];
            foreach ($memberRepository->findBy(['blockId' => $block->getId()]) as $member) {
                $members[] = $member->getTeamId();
            }
            if ($this->sortedUnique($members) === $teamIds) {
                return $block;
            }
        }

        return null;
    }

    // ───────────────────────────── Dérivations ─────────────────────────────

    /**
     * `canSplit` dérivé : vrai si un créneau du parc vise ce gymnase (par nom) avec ≥ 2 équipes
     * en parallèle. Un gymnase créé naît donc divisible pile quand un créneau l'exige.
     *
     * @param array<string, mixed> $derived
     */
    private function venueCanSplit(array $derived, string $venueName): bool
    {
        $target = $this->normalizer->normalize($venueName);
        foreach ($this->lines($derived, 'creneaux') as $slot) {
            if (!\is_array($slot)) {
                continue;
            }
            if ($this->normalizer->normalize($this->stringField($slot, 'gymnase')) === $target
                && $this->parallelOf($slot) >= 2) {
                return true;
            }
        }

        return false;
    }

    /**
     * La catégorie d'une équipe CRÉÉE. Si le libellé porte une catégorie déduite, elle est appariée
     * par NOM NORMALISÉ à une catégorie EXISTANTE du club, sinon créée (maison partagée
     * {@see SportCategoryResolver::findOrCreate}). Sans catégorie, l'équipe est classée « À classer ».
     */
    private function categoryIdForCreatedTeam(mixed $entry, Club $club): string
    {
        $categorie = \is_array($entry) ? ($entry['categorie'] ?? null) : null;
        if (\is_string($categorie) && '' !== trim($categorie) && self::UNFILED_CATEGORY !== trim($categorie)) {
            $name = trim($categorie);
            $matched = $this->matchCategoryByNormalizedName($name, $club->getId());

            return $matched ?? $this->sportCategoryResolver->findOrCreate($name, $club->getId(), $this->defaultSportId($club))->getId();
        }

        return $this->sportCategoryResolver->findOrCreate(self::UNFILED_CATEGORY, $club->getId(), $this->defaultSportId($club))->getId();
    }

    private function matchCategoryByNormalizedName(string $name, string $clubId): ?string
    {
        $key = $this->normalizer->normalize($name);
        if ('' === $key) {
            return null;
        }
        foreach ($this->entityManager->getRepository(SportCategory::class)->findBy(['clubId' => $clubId]) as $category) {
            if ($this->normalizer->normalize($category->getName()) === $key) {
                return $category->getId();
            }
        }

        return null;
    }

    /**
     * Le sport auquel rattacher une catégorie créée : celui du club s'il en porte un, sinon le
     * sport actif existant, sinon le basket par défaut créé une fois. Mémorisé pour le run.
     */
    private function defaultSportId(Club $club): string
    {
        if (null !== $this->sportId) {
            return $this->sportId;
        }
        $clubSportId = $club->getSportId();
        if (null !== $clubSportId && '' !== $clubSportId) {
            return $this->sportId = $clubSportId;
        }
        $sportRepository = $this->entityManager->getRepository(Sport::class);
        $active = $sportRepository->findOneBy(['isActive' => true]);
        if ($active instanceof Sport) {
            return $this->sportId = $active->getId();
        }
        $basketball = $sportRepository->findOneBy(['slug' => 'basketball']);
        if (!$basketball instanceof Sport) {
            $basketball = (new Sport)->setName('Basketball')->setSlug('basketball')->setIsActive(true);
            $this->entityManager->persist($basketball);
            $this->entityManager->flush();
        }

        return $this->sportId = $basketball->getId();
    }

    /**
     * @param array{coche: bool, creer: bool, cibleId: string|null, venueId: string|null, corrections: array<string, mixed>} $decision
     */
    private function sessionsOf(mixed $entry, array $decision): int
    {
        $corrected = $decision['corrections']['seances'] ?? null;
        if (\is_int($corrected) && $corrected >= 1) {
            return $corrected;
        }
        $seances = \is_array($entry) ? ($entry['seances'] ?? null) : null;

        return \is_int($seances) && $seances >= 1 ? $seances : self::DEFAULT_SESSIONS_PER_WEEK;
    }

    private function genderOf(mixed $entry): ?Gender
    {
        $genre = \is_array($entry) ? ($entry['genre'] ?? null) : null;

        return \is_string($genre) && null !== Gender::tryFrom($genre) ? Gender::from($genre) : null;
    }

    /**
     * Le GENRE d'une équipe créée (refonte PR2) : `corrections.genre` s'il est fourni (hors enum → 422
     * AVANT persist), sinon le genre DÉRIVÉ du nom. Le genre est OBLIGATOIRE : ni corrigé ni dérivé →
     * refus 422 nommant la ligne équipe (jamais une équipe sans genre créée en silence — « plus MIXTE »).
     *
     * @param array<string, mixed> $corrections
     */
    private function createdTeamGender(array $corrections, mixed $entry, int $index): Gender
    {
        $raw = $corrections['genre'] ?? null;
        if (\is_string($raw) && '' !== $raw) {
            $gender = Gender::tryFrom($raw);
            if (!$gender instanceof Gender) {
                $this->fail('equipes', $index, \sprintf('genre inconnu (%s).', $raw));
            }

            return $gender;
        }

        $derived = $this->genderOf($entry);
        if (!$derived instanceof Gender) {
            $this->fail('equipes', $index, 'le genre est obligatoire pour créer une équipe — choisissez-le dans l\'aperçu.');
        }

        return $derived;
    }

    /**
     * Le RANG d'une équipe créée (refonte PR2) : `corrections.tierId` s'il est fourni (inconnu, ou de
     * type aberrant — flottant / négatif / chaîne non numérique → 422 AVANT persist), sinon la
     * SUGGESTION de la règle FBI unique ({@see TeamCreationSuggester}) — plus aucun rang 5 en dur.
     *
     * @param array<string, mixed> $corrections
     */
    private function createdTeamTierId(array $corrections, string $name, string $category, int $index): int
    {
        $raw = $corrections['tierId'] ?? null;
        // Absente → la règle FBI unique tranche (cas normal). FOURNIE mais pas un id de rang entier non
        // négatif (flottant, négatif, chaîne non numérique) → 422 AVANT persist : une valeur forgée est
        // REJETÉE, jamais repliée en silence sur une suggestion (l'équipe naîtrait avec un rang deviné).
        if (null === $raw) {
            return $this->suggester->suggest($name, $category, '')['tierId'];
        }
        if (!\is_int($raw) && (!\is_string($raw) || !ctype_digit($raw))) {
            $this->fail('equipes', $index, 'le rang choisi n\'est pas valide.');
        }
        $tierId = (int) $raw;
        if (!$this->tierExists($tierId)) {
            $this->fail('equipes', $index, \sprintf('rang inconnu (%d).', $tierId));
        }

        return $tierId;
    }

    /**
     * Le club partenaire d'une entente (CTC) d'une équipe CRÉÉE : `corrections.ctcPartner` (trim, non
     * vide) quand l'entente est cochée, sinon null. Borné à {@see Team::CTC_PARTNER_MAX_LENGTH} comme
     * la colonne — un partenaire plus long refuse l'import (422 nommant la ligne) AVANT persist, jamais
     * une troncature serveur (PostgreSQL 22001 → 500).
     *
     * @param array<string, mixed> $corrections
     */
    private function createdTeamCtcPartner(array $corrections, bool $isCtc, int $index): ?string
    {
        $raw = $corrections['ctcPartner'] ?? null;
        if (!$isCtc || !\is_string($raw) || '' === trim($raw)) {
            return null;
        }
        $partner = trim($raw);
        if (mb_strlen($partner) > Team::CTC_PARTNER_MAX_LENGTH) {
            $this->fail('equipes', $index, \sprintf('le club partenaire de l\'entente dépasse %d caractères.', Team::CTC_PARTNER_MAX_LENGTH));
        }

        return $partner;
    }

    private function tierExists(int $id): bool
    {
        if (null === $this->validTierIds) {
            $this->validTierIds = [];
            foreach ($this->entityManager->getRepository(PriorityTier::class)->findAll() as $tier) {
                $this->validTierIds[$tier->getId()] = true;
            }
        }

        return isset($this->validTierIds[$id]);
    }

    private function parallelOf(mixed $slot): int
    {
        $parallel = \is_array($slot) ? ($slot['equipes_en_parallele'] ?? null) : null;

        return \is_int($parallel) && $parallel >= 1 ? $parallel : 1;
    }

    // ───────────────────────────── Helpers ─────────────────────────────

    /**
     * @param list<array<string, mixed>> $decisions
     *
     * @return array<string, array<int, array{coche: bool, creer: bool, cibleId: string|null, venueId: string|null, corrections: array<string, mixed>}>>
     */
    private function indexDecisions(array $decisions): array
    {
        $index = [];
        foreach ($decisions as $decision) {
            $section = $decision['section'] ?? null;
            $position = $decision['index'] ?? null;
            if (!\is_string($section) || !\is_int($position)) {
                continue;
            }
            $cibleId = $decision['cibleId'] ?? null;
            $venueId = $decision['venueId'] ?? null;
            $corrections = $decision['corrections'] ?? null;
            $index[$section][$position] = [
                'coche' => true === ($decision['coche'] ?? null),
                // Signal de création EXPLICITE (3a) : le gestionnaire a choisi « Créer « X » » sur une
                // ligne AMBIGUË — il assume la création et lève le refus d'ambiguïté (équipe ET gymnase).
                'creer' => true === ($decision['creer'] ?? null),
                'cibleId' => \is_string($cibleId) && '' !== $cibleId ? $cibleId : null,
                // P4-363 — canal DISTINCT du `cibleId` : le gymnase CHOISI de la réservation de groupe
                // d'une mutualisation (sélecteur de gymnase de la ligne). Narrowé comme `cibleId`
                // (chaîne non vide, sinon null → résolution par le NOM de la case).
                'venueId' => \is_string($venueId) && '' !== $venueId ? $venueId : null,
                'corrections' => \is_array($corrections) ? $corrections : [],
            ];
        }

        return $index;
    }

    /**
     * @param array<string, mixed> $derived
     *
     * @return array<int, mixed>
     */
    private function lines(array $derived, string $section): array
    {
        $value = $derived[$section] ?? null;

        return \is_array($value) && array_is_list($value) ? $value : [];
    }

    /**
     * Libellé d'un bloc mutualisé : la correction `groupe` (trim, non vide) l'emporte sur le `groupe`
     * DÉRIVÉ de la case ; borné à {@see SharedTrainingBlock::LABEL_MAX_LENGTH} comme la colonne.
     *
     * @param array<string, mixed> $corrections
     */
    private function blockLabel(array $corrections, mixed $entry): ?string
    {
        $corrected = $corrections['groupe'] ?? null;
        if (\is_string($corrected) && '' !== trim($corrected)) {
            return $this->boundLabel(trim($corrected));
        }
        $raw = \is_array($entry) ? ($entry['groupe'] ?? null) : null;

        return \is_string($raw) && '' !== trim($raw) ? $this->boundLabel(trim($raw)) : null;
    }

    private function boundLabel(string $label): string
    {
        return mb_substr($label, 0, SharedTrainingBlock::LABEL_MAX_LENGTH);
    }

    /**
     * @param array<string, mixed> $corrections
     */
    private function intCorrectionOr(array $corrections, string $correctionKey, mixed $entry, string $entryKey): ?int
    {
        $corrected = $corrections[$correctionKey] ?? null;
        if (\is_int($corrected)) {
            return $corrected;
        }
        $raw = \is_array($entry) ? ($entry[$entryKey] ?? null) : null;

        return \is_int($raw) ? $raw : null;
    }

    /**
     * @param array<string, mixed> $corrections
     */
    private function timeCorrectionOr(array $corrections, string $correctionKey, mixed $entry, string $entryKey): ?string
    {
        $corrected = $corrections[$correctionKey] ?? null;
        if (\is_string($corrected) && null !== $this->minutesOf($corrected)) {
            return $corrected;
        }
        $raw = \is_array($entry) ? ($entry[$entryKey] ?? null) : null;

        return \is_string($raw) && null !== $this->minutesOf($raw) ? $raw : null;
    }

    private function minutesOf(string $hhmm): ?int
    {
        if (1 !== preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $hhmm, $m)) {
            return null;
        }

        return (int) $m[1] * 60 + (int) $m[2];
    }

    /**
     * Retire en tête tout déclencheur de formule (`=`/`+`/`-`/`@`) et toute tabulation ou
     * retour-ligne : le nom stocké ne peut jamais devenir une formule vivante à l'ouverture de
     * l'export XLSX (couplé au StringValueBinder de SpreadsheetGenerator).
     */
    private function neutralize(string $name): string
    {
        return (string) preg_replace('/^[=+\-@\t\r\n]+/', '', $name);
    }

    private function stringField(mixed $entry, string $field): string
    {
        if (\is_array($entry) && \is_string($entry[$field] ?? null)) {
            return $entry[$field];
        }

        return '';
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
    private function activeTeamPool(): array
    {
        $pool = [];
        foreach ($this->entityManager->getRepository(Team::class)->findAll() as $team) {
            if ($team->getIsActive()) {
                $pool[] = ['id' => $team->getId(), 'nom' => $team->getName()];
            }
        }

        return $pool;
    }

    /**
     * @return array<string, int>
     */
    private function emptyRecap(): array
    {
        return ['crees' => 0, 'apparies' => 0, 'misAJour' => 0, 'ignores' => 0];
    }

    /**
     * E3 — une faute fail-fast NOMME sa section (jamais « n°X ») ; le corps du message nomme l'entité
     * en cause. `$index` n'est plus dans le texte (gardé pour la signature stable des appelants).
     */
    private function fail(string $section, int $index, string $message): never
    {
        unset($index);
        throw new UnprocessableEntityHttpException(\sprintf('%s : %s', $this->sectionLabel($section), $message));
    }

    private function sectionLabel(string $section): string
    {
        return match ($section) {
            'equipes' => self::LABEL_EQUIPES,
            'gymnases' => self::LABEL_GYMNASES,
            'creneaux' => self::LABEL_CRENEAUX,
            'mutualisations' => self::LABEL_MUTUALISATIONS,
            'reservations' => self::LABEL_RESERVATIONS,
            default => $section,
        };
    }
}
