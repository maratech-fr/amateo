<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Club;
use App\Entity\Coach;
use App\Entity\Constraint;
use App\Entity\PriorityTier;
use App\Entity\Reservation;
use App\Entity\Season;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\TeamCoach;
use App\Entity\Venue;
use App\Enum\ConstraintFamily;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use App\Enum\SeasonStatus;
use App\Enum\TeamCoachRole;
use App\Service\CoachDoubleBookingDetector;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * NR — P2-9 PR B, axe *constraint semantics* : UN VERROU NE PEUT PAS DÉDOUBLER UN COACH.
 *
 * Un verrou HARD est pré-placé HORS du solveur (ALIGN-07) : sa variable n'existe pas,
 * donc aucune contrainte ne l'atteint et le moteur ne peut PAS refuser deux verrous qui
 * mettent le même coach à deux endroits. Ce n'est pas une préférence bafouée — le
 * gestionnaire n'a jamais choisi que son coach se dédouble. Décision fondateur : le récap
 * BLOQUE la génération ({@see CoachDoubleBookingDetector}).
 *
 * Les trois règles tranchées (fondateur, 2026-07-31), chacune gardée par un cas :
 *  1. chevauchement sur INTERVALLES RÉELS, pas sur l'égalité des heures de début ;
 *  2. gymnases DIFFÉRENTS seulement — même gymnase = mutualisation voulue (`canSplit`) ;
 *  3. coachs `MAIN` seulement — un `ASSISTANT` est optionnel pour le solveur.
 *
 * ⚠ CE QUE CES CAS ÉVITENT (le cadrage P2-9 avertit que trois NR successifs ont été
 * acceptés alors qu'ils passaient pour une AUTRE raison que la règle visée) : chaque cas
 * négatif ne diffère du cas positif QUE par la variable testée — même club, même saison,
 * mêmes équipes, même jour. Un cas qui rougirait pour un motif étranger (données
 * manquantes, mauvaise saison, coach absent) rougirait aussi sur le cas positif, qui sert
 * donc de témoin.
 *
 * PREUVE DE CHUTE (2026-07-31) : la comparaison d'intervalles de
 * `CoachDoubleBookingDetector::collide` neutralisée (`return false`) → le cas 1 rougit
 * seul, les cas négatifs restent verts (ils n'attendent rien). Règle remise → tout vert.
 */
#[Group('phase1')]
#[Group('integration')]
final class CoachDoubleBookingTest extends KernelTestCase
{
    use TenantGucTrait;

    private EntityManagerInterface $em;

    private CoachDoubleBookingDetector $detector;

    /** Cas 1 — deux gymnases, même jour, créneaux qui SE CHEVAUCHENT sans commencer à la même heure. */
    public function testOverlappingReservationsInTwoVenuesBlock(): void
    {
        $ctx = $this->seed();
        // 17h00-18h30 et 17h30-19h00 : heures de début DIFFÉRENTES — un test sur
        // l'égalité des débuts laisserait passer, d'où la règle « intervalles réels ».
        $a = $this->reserve($ctx, $ctx['teamA'], $ctx['venue1'], 2, '17:00', 90);
        $b = $this->reserve($ctx, $ctx['teamB'], $ctx['venue2'], 2, '17:30', 90);

        $conflicts = $this->detector->detect([$a, $b], $ctx['clubId'], $ctx['seasonId']);

        self::assertCount(1, $conflicts, 'un coach MAIN dans deux gymnases sur des créneaux qui se chevauchent est une impossibilité physique');
        self::assertSame($ctx['coachId'], $conflicts[0]['coachId']);
        $message = $this->detector->describeForRecap($conflicts[0]);
        // Le message doit dire QUELLE réservation retirer : sans le gymnase ni l'heure,
        // une erreur bloquante enferme le gestionnaire sans recours.
        self::assertStringContainsString('Gymnase Un', $message);
        self::assertStringContainsString('Gymnase Deux', $message);
        self::assertStringContainsString('mardi', $message);
    }

    /** Cas 2 — MÊME gymnase : c'est la mutualisation (canSplit + capacité), jamais un blocage. */
    public function testSameVenueIsMutualisationNotAConflict(): void
    {
        $ctx = $this->seed();
        $a = $this->reserve($ctx, $ctx['teamA'], $ctx['venue1'], 2, '17:00', 90);
        $b = $this->reserve($ctx, $ctx['teamB'], $ctx['venue1'], 2, '17:00', 90);

        self::assertSame([], $this->detector->detect([$a, $b], $ctx['clubId'], $ctx['seasonId']), 'deux équipes sur le même créneau du même gymnase = mutualisation voulue');
    }

    /** Cas 3 — créneaux ADJACENTS (fin de l'un = début de l'autre) : aucun chevauchement. */
    public function testAdjacentReservationsDoNotBlock(): void
    {
        $ctx = $this->seed();
        $a = $this->reserve($ctx, $ctx['teamA'], $ctx['venue1'], 2, '17:00', 90); // →18h30
        $b = $this->reserve($ctx, $ctx['teamB'], $ctx['venue2'], 2, '18:30', 90);

        self::assertSame([], $this->detector->detect([$a, $b], $ctx['clubId'], $ctx['seasonId']), 'le coach a le temps de traverser : 18h30 n’est pas dans [17h00, 18h30[');
    }

    /** Cas 4 — même chevauchement, mais des JOURS différents. */
    public function testDifferentDaysDoNotBlock(): void
    {
        $ctx = $this->seed();
        $a = $this->reserve($ctx, $ctx['teamA'], $ctx['venue1'], 2, '17:00', 90);
        $b = $this->reserve($ctx, $ctx['teamB'], $ctx['venue2'], 3, '17:00', 90);

        self::assertSame([], $this->detector->detect([$a, $b], $ctx['clubId'], $ctx['seasonId']));
    }

    /** Cas 5 — l'équipe B est coachée par un ASSISTANT : optionnel pour le solveur, ne bloque pas. */
    public function testAssistantCoachDoesNotBlock(): void
    {
        $ctx = $this->seed(secondTeamRole: TeamCoachRole::ASSISTANT);
        $a = $this->reserve($ctx, $ctx['teamA'], $ctx['venue1'], 2, '17:00', 90);
        $b = $this->reserve($ctx, $ctx['teamB'], $ctx['venue2'], 2, '17:00', 90);

        self::assertSame([], $this->detector->detect([$a, $b], $ctx['clubId'], $ctx['seasonId']), 'un ASSISTANT est optionnel (add_coach_at_most_one ne traite que MAIN) — bloquer refuserait une réservation légitime');
    }

    /** Cas 6 — deux coachs MAIN DIFFÉRENTS : personne ne se dédouble. */
    public function testTwoDistinctCoachesDoNotBlock(): void
    {
        $ctx = $this->seed(distinctSecondCoach: true);
        $a = $this->reserve($ctx, $ctx['teamA'], $ctx['venue1'], 2, '17:00', 90);
        $b = $this->reserve($ctx, $ctx['teamB'], $ctx['venue2'], 2, '17:00', 90);

        self::assertSame([], $this->detector->detect([$a, $b], $ctx['clubId'], $ctx['seasonId']));
    }

    // ── PR A (2026-08-06) — coach INDISPONIBLE × créneau réservé en dur ──────────
    // Même doctrine que detect() (coach MAIN, intervalles demi-ouverts), mais un
    // AVERTISSEMENT : le verrou est souverain, la génération passera — on prévient
    // AVANT au lieu de le découvrir en INFO post-solve.

    /** Le cas nominal : indisponible toute la journée du mardi, réservation le mardi. */
    public function testReservationOnCoachUnavailableDayWarns(): void
    {
        $ctx = $this->seed();
        $reservation = $this->reserve($ctx, $ctx['teamA'], $ctx['venue1'], 2, '17:00', 90);
        $constraint = $this->unavailability($ctx, ['unavailableDays' => [2]]);

        $clashes = $this->detector->detectUnavailabilityClashes([$reservation], [$constraint], $ctx['clubId'], $ctx['seasonId']);

        self::assertCount(1, $clashes);
        $message = $this->detector->describeUnavailabilityForRecap($clashes[0]);
        // Le message doit nommer le coach, le jour et la réservation fautive : un
        // avertissement sans recours actionnable n'aide personne.
        self::assertStringContainsString('Maxime', $message);
        self::assertStringContainsString('mardi', $message);
        self::assertStringContainsString('Gymnase Un', $message);
    }

    /** Fenêtre horaire : indisponible à partir de 18h30, la réservation FINIT à 18h30 — pas de chevauchement (demi-ouvert). */
    public function testReservationEndingWhenUnavailabilityStartsDoesNotWarn(): void
    {
        $ctx = $this->seed();
        $reservation = $this->reserve($ctx, $ctx['teamA'], $ctx['venue1'], 2, '17:00', 90); // → 18h30
        $adjacent = $this->unavailability($ctx, ['unavailableDays' => [2], 'fromTime' => '18:30']);

        self::assertSame([], $this->detector->detectUnavailabilityClashes([$reservation], [$adjacent], $ctx['clubId'], $ctx['seasonId']));

        // Témoin : la même fenêtre 30 min plus tôt chevauche, et rougit bien.
        $overlapping = $this->unavailability($ctx, ['unavailableDays' => [2], 'fromTime' => '18:00']);
        self::assertCount(1, $this->detector->detectUnavailabilityClashes([$reservation], [$overlapping], $ctx['clubId'], $ctx['seasonId']));
    }

    /** `availableDays` non vide = liste blanche : tout AUTRE jour est bloqué (miroir du parse moteur). */
    public function testAvailableDaysWhitelistBlocksTheComplement(): void
    {
        $ctx = $this->seed();
        $reservation = $this->reserve($ctx, $ctx['teamA'], $ctx['venue1'], 2, '17:00', 90);
        $constraint = $this->unavailability($ctx, ['availableDays' => [3]]); // dispo mercredi SEULEMENT

        self::assertCount(1, $this->detector->detectUnavailabilityClashes([$reservation], [$constraint], $ctx['clubId'], $ctx['seasonId']));
    }

    /** Une contrainte DÉSACTIVÉE ne bloque rien : le moteur la saute aussi. */
    public function testInactiveUnavailabilityDoesNotWarn(): void
    {
        $ctx = $this->seed();
        $reservation = $this->reserve($ctx, $ctx['teamA'], $ctx['venue1'], 2, '17:00', 90);
        $constraint = $this->unavailability($ctx, ['unavailableDays' => [2]], isActive: false);

        self::assertSame([], $this->detector->detectUnavailabilityClashes([$reservation], [$constraint], $ctx['clubId'], $ctx['seasonId']));
    }

    // ── P4-319 — « UN COACH SUFFIT » : plusieurs coachs principaux par équipe ────────────
    // Avant, UN seul coach principal était résolu par équipe (last-wins) et une
    // indisponibilité suffisait à crier au loup. Désormais le détecteur prend la LISTE des
    // principaux et n'avertit que si TOUS sont bloqués (même règle que le moteur,
    // availability.py). Chaque cas négatif ne diffère du témoin QUE par la variable testée.

    /**
     * NR — une équipe à DEUX coachs principaux dont l'UN est disponible le jour réservé : aucun
     * avertissement (un coach suffit à servir la séance).
     *
     * PREUVE DE CHUTE : remplacer le verdict « tous bloqués » de `detectUnavailabilityClashes`
     * par « au moins un bloqué » (l'ancienne doctrine mono-coach) → ce cas rougit (avertissement
     * fantôme), le témoin ci-dessous reste vert.
     */
    public function testOneAvailableMainCoachAmongTwoDoesNotWarn(): void
    {
        $ctx = $this->seed();
        $second = $this->createCoach($ctx['clubId'], $ctx['seasonId'], 'Lea');
        $this->linkCoach($ctx['clubId'], $ctx['seasonId'], $ctx['teamA'], $second, TeamCoachRole::MAIN);

        $reservation = $this->reserve($ctx, $ctx['teamA'], $ctx['venue1'], 2, '17:00', 90);
        // Maxime n'est disponible que le mercredi → bloqué le mardi ; Lea est disponible le mardi.
        $maximeOff = $this->unavailability($ctx, ['availableDays' => [3]]);
        $leaOnTuesday = $this->unavailabilityFor($ctx, $second, ['availableDays' => [2]]);

        self::assertSame(
            [],
            $this->detector->detectUnavailabilityClashes([$reservation], [$maximeOff, $leaOnTuesday], $ctx['clubId'], $ctx['seasonId']),
            'un principal disponible le mardi suffit : la séance est servable, rien à prévenir',
        );
    }

    /** Témoin — les DEUX principaux indisponibles le mardi : la séance n'a aucun coach → un avertissement. */
    public function testBothMainCoachesUnavailableWarns(): void
    {
        $ctx = $this->seed();
        $second = $this->createCoach($ctx['clubId'], $ctx['seasonId'], 'Lea');
        $this->linkCoach($ctx['clubId'], $ctx['seasonId'], $ctx['teamA'], $second, TeamCoachRole::MAIN);

        $reservation = $this->reserve($ctx, $ctx['teamA'], $ctx['venue1'], 2, '17:00', 90);
        $maximeOff = $this->unavailability($ctx, ['availableDays' => [3]]);
        $leaOff = $this->unavailabilityFor($ctx, $second, ['availableDays' => [3]]);

        self::assertCount(1, $this->detector->detectUnavailabilityClashes([$reservation], [$maximeOff, $leaOff], $ctx['clubId'], $ctx['seasonId']), 'aucun principal disponible le mardi : la séance ne peut pas être coachée');
    }

    /**
     * NR — une SÉANCE COMMUNE (deux équipes co-localisées) met en commun leurs coachs : un seul
     * coach disponible, quelle que soit l'équipe dont il dépend, rend la case servable.
     *
     * teamA (Maxime) et teamB (Nicolas) sur la MÊME case (gymnase 1, mardi 17h). Maxime est bloqué
     * le mardi ; Nicolas est disponible : la séance commune est couverte → aucun avertissement.
     */
    public function testCommonSessionIsServableWhenAnyCoLocatedCoachIsAvailable(): void
    {
        $ctx = $this->seed(distinctSecondCoach: true);
        $a = $this->reserve($ctx, $ctx['teamA'], $ctx['venue1'], 2, '17:00', 90);
        $b = $this->reserve($ctx, $ctx['teamB'], $ctx['venue1'], 2, '17:00', 90);
        $maximeOff = $this->unavailability($ctx, ['unavailableDays' => [2]]); // Maxime (teamA) bloqué mardi

        self::assertSame(
            [],
            $this->detector->detectUnavailabilityClashes([$a, $b], [$maximeOff], $ctx['clubId'], $ctx['seasonId']),
            'Nicolas (teamB) est disponible le mardi : la séance commune est servable malgré l\'absence de Maxime',
        );
    }

    // ── « Tant qu'un coach tient la séance, c'est OK » (fondateur 2026-10-30) ────────────────────
    // Le récap ne bloque plus que l'INTENABLE. Le cas 1 ci-dessus reste le témoin : coach SEUL à
    // deux endroits en même temps, le récap bloque toujours. Parité de la règle pure :
    // CoachDoubleBookingMirrorParityTest::testBackendHoldersForMatchesTheSharedCases.

    /** Le cas réel : Maxime sur deux équipes, Lea tient la seconde. Plus aucun bloqueur. */
    public function testASecondMainCoachHoldingTheSessionDoesNotBlock(): void
    {
        $ctx = $this->seed();
        $this->linkCoach($ctx['clubId'], $ctx['seasonId'], $ctx['teamB'], $this->createCoach($ctx['clubId'], $ctx['seasonId'], 'Lea'), TeamCoachRole::MAIN);
        $a = $this->reserve($ctx, $ctx['teamA'], $ctx['venue1'], 2, '17:00', 90);
        $b = $this->reserve($ctx, $ctx['teamB'], $ctx['venue2'], 2, '17:00', 90);

        self::assertSame([], $this->detector->detect([$a, $b], $ctx['clubId'], $ctx['seasonId']), 'Lea tient la seconde séance : rien d\'intenable');
    }

    /** Deux coachs ne tiennent pas trois séances : une règle jugée paire par paire ne le verrait pas. */
    public function testThreeSessionsForTwoCoachesStillBlock(): void
    {
        $ctx = $this->seed();
        $lea = $this->createCoach($ctx['clubId'], $ctx['seasonId'], 'Lea');
        $this->linkCoach($ctx['clubId'], $ctx['seasonId'], $ctx['teamA'], $lea, TeamCoachRole::MAIN);
        $this->linkCoach($ctx['clubId'], $ctx['seasonId'], $ctx['teamB'], $lea, TeamCoachRole::MAIN);
        $teamC = $this->secondTeamLike($ctx, 'SM3');
        $this->linkCoach($ctx['clubId'], $ctx['seasonId'], $teamC, $ctx['coachId'], TeamCoachRole::MAIN);
        $this->linkCoach($ctx['clubId'], $ctx['seasonId'], $teamC, $lea, TeamCoachRole::MAIN);
        $venue3 = $this->thirdVenue($ctx);
        $a = $this->reserve($ctx, $ctx['teamA'], $ctx['venue1'], 2, '17:00', 90);
        $b = $this->reserve($ctx, $ctx['teamB'], $ctx['venue2'], 2, '17:00', 90);
        $c = $this->reserve($ctx, $teamC, $venue3, 2, '17:00', 90);

        self::assertNotSame([], $this->detector->detect([$a, $b, $c], $ctx['clubId'], $ctx['seasonId']), 'une séance n\'a personne pour la tenir');
    }

    /** Le second coach indisponible ne tient rien : Maxime pris ailleurs, la séance n'a personne. */
    public function testASecondCoachUnavailableDoesNotHoldTheSession(): void
    {
        $ctx = $this->seed();
        $lea = $this->createCoach($ctx['clubId'], $ctx['seasonId'], 'Lea');
        $this->linkCoach($ctx['clubId'], $ctx['seasonId'], $ctx['teamB'], $lea, TeamCoachRole::MAIN);
        $a = $this->reserve($ctx, $ctx['teamA'], $ctx['venue1'], 2, '17:00', 90);
        $b = $this->reserve($ctx, $ctx['teamB'], $ctx['venue2'], 2, '17:00', 90);
        $leaOff = $this->unavailabilityFor($ctx, $lea, ['unavailableDays' => [2]]);

        self::assertNotSame([], $this->detector->detect([$a, $b], $ctx['clubId'], $ctx['seasonId'], [$leaOff]));
    }

    /** L'indisponibilité d'un coach SEUL reste un avertissement : la réservation gagne, rien ne bloque. */
    public function testASoleCoachUnavailableIsNotABlocker(): void
    {
        $ctx = $this->seed(distinctSecondCoach: true);
        $a = $this->reserve($ctx, $ctx['teamA'], $ctx['venue1'], 2, '17:00', 90);
        $b = $this->reserve($ctx, $ctx['teamB'], $ctx['venue2'], 2, '17:00', 90);
        $maximeOff = $this->unavailability($ctx, ['unavailableDays' => [2]]);

        self::assertSame([], $this->detector->detect([$a, $b], $ctx['clubId'], $ctx['seasonId'], [$maximeOff]));
        self::assertCount(1, $this->detector->detectUnavailabilityClashes([$a, $b], [$maximeOff], $ctx['clubId'], $ctx['seasonId']), 'témoin : elle reste signalée en avertissement');
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->detector = self::getContainer()->get(CoachDoubleBookingDetector::class);
    }

    /**
     * Deux équipes, deux gymnases, UN coach MAIN partagé (sauf variante). Le contexte est
     * identique pour tous les cas : seule la variable testée change d'un cas à l'autre.
     *
     * @return array{clubId: string, seasonId: string, teamA: string, teamB: string, venue1: string, venue2: string, coachId: string}
     */
    private function seed(TeamCoachRole $secondTeamRole = TeamCoachRole::MAIN, bool $distinctSecondCoach = false): array
    {
        $suffix = bin2hex(random_bytes(4));

        $club = (new Club)->setName('Club ' . $suffix)->setSlug('cdb-' . $suffix)->setTimezone('Europe/Paris')->setLocale('fr');
        $this->em->persist($club);
        $this->em->flush();
        $clubId = $club->getId();

        $this->scopeGucToClub($clubId);

        $season = (new Season)->setClubId($clubId)->setName('2026-2027')->setStartDate(new DateTimeImmutable('2026-09-01'))->setEndDate(new DateTimeImmutable('2027-06-30'))->setStatus(SeasonStatus::ACTIVE);
        $season->setTransitionData([]);
        $this->em->persist($season);
        $this->em->flush();
        $seasonId = $season->getId();

        $venueIds = [];
        foreach (['Gymnase Un', 'Gymnase Deux'] as $name) {
            $venue = (new Venue)->setClubId($clubId)->setSeasonId($seasonId)->setName($name)->setSource('manual');
            $this->em->persist($venue);
            $this->em->flush();
            $venueIds[] = $venue->getId();
        }

        // Team exige une SportCategory (par club) et un PriorityTier (référentiel global,
        // seedé par les migrations) : sans eux l'INSERT viole des NOT NULL.
        $sport = (new Sport)->setName('Basketball')->setSlug('bball-' . $suffix)->setIsActive(true);
        $this->em->persist($sport);
        $this->em->flush();
        $category = (new SportCategory)->setClubId($clubId)->setSportId($sport->getId())->setName('U11')->setIsCustom(false)->setSortOrder(0);
        $this->em->persist($category);
        $this->em->flush();
        $tier = $this->em->getRepository(PriorityTier::class)->find(1);
        if (!$tier instanceof PriorityTier) {
            // Référentiel GLOBAL (hors club, hors RLS) : présent en dev via les fixtures,
            // pas forcément dans une base de test fraîche — on le crée à la demande.
            $tier = (new PriorityTier)->setId(1)->setLabel('S')->setName('Senior')->setColor('#FF0000')->setOrToolsWeight(100)->setDefaultMinSessions(2);
            $this->em->persist($tier);
            $this->em->flush();
        }

        $teamIds = [];
        foreach (['SM1', 'SM2'] as $name) {
            $team = (new Team)
                ->setClubId($clubId)
                ->setSeasonId($seasonId)
                ->setSportCategoryId($category->getId())
                ->setPriorityTierId($tier->getId())
                ->setName($name)
                ->setSessionsPerWeek(2);
            $this->em->persist($team);
            $this->em->flush();
            $teamIds[] = $team->getId();
        }

        $coachId = $this->createCoach($clubId, $seasonId, 'Maxime');
        $this->linkCoach($clubId, $seasonId, $teamIds[0], $coachId, TeamCoachRole::MAIN);
        $this->linkCoach(
            $clubId,
            $seasonId,
            $teamIds[1],
            $distinctSecondCoach ? $this->createCoach($clubId, $seasonId, 'Nicolas') : $coachId,
            $secondTeamRole,
        );

        return [
            'clubId' => $clubId, 'seasonId' => $seasonId,
            'teamA' => $teamIds[0], 'teamB' => $teamIds[1],
            'venue1' => $venueIds[0], 'venue2' => $venueIds[1],
            'coachId' => $coachId,
        ];
    }

    /**
     * @param array{clubId: string, seasonId: string, coachId: string} $ctx
     * @param array<string, mixed>                                     $config
     */
    private function unavailability(array $ctx, array $config, bool $isActive = true): Constraint
    {
        $constraint = new Constraint;
        $constraint->setClubId($ctx['clubId']);
        $constraint->setSeasonId($ctx['seasonId']);
        $constraint->setName('Maxime indisponible');
        $constraint->setScope(ConstraintScope::COACH);
        $constraint->setScopeTargetId($ctx['coachId']);
        $constraint->setFamily(ConstraintFamily::COACH_AVAILABILITY);
        $constraint->setRuleType(ConstraintRuleType::HARD);
        $constraint->setConfig(['coachId' => $ctx['coachId'], ...$config]);
        $constraint->setIsActive($isActive);
        $this->em->persist($constraint);
        $this->em->flush();

        return $constraint;
    }

    /**
     * Une indisponibilité pour un coach ARBITRAIRE (pas le coach par défaut du contexte) —
     * nécessaire aux cas « un coach suffit » où une équipe a plusieurs principaux.
     *
     * @param array{clubId: string, seasonId: string} $ctx
     * @param array<string, mixed>                    $config
     */
    private function unavailabilityFor(array $ctx, string $coachId, array $config): Constraint
    {
        $constraint = new Constraint;
        $constraint->setClubId($ctx['clubId']);
        $constraint->setSeasonId($ctx['seasonId']);
        $constraint->setName('Indisponibilité');
        $constraint->setScope(ConstraintScope::COACH);
        $constraint->setScopeTargetId($coachId);
        $constraint->setFamily(ConstraintFamily::COACH_AVAILABILITY);
        $constraint->setRuleType(ConstraintRuleType::HARD);
        $constraint->setConfig(['coachId' => $coachId, ...$config]);
        $constraint->setIsActive(true);
        $this->em->persist($constraint);
        $this->em->flush();

        return $constraint;
    }

    /**
     * Une équipe de plus, même catégorie et même rang que l'équipe A.
     *
     * @param array{teamA: string} $ctx
     */
    private function secondTeamLike(array $ctx, string $name): string
    {
        $model = $this->em->getRepository(Team::class)->find($ctx['teamA']);
        self::assertInstanceOf(Team::class, $model);
        $team = (new Team)
            ->setClubId($model->getClubId())
            ->setSeasonId($model->getSeasonId())
            ->setSportCategoryId($model->getSportCategoryId())
            ->setPriorityTierId($model->getPriorityTierId())
            ->setName($name)
            ->setSessionsPerWeek(2);
        $this->em->persist($team);
        $this->em->flush();

        return $team->getId();
    }

    /**
     * @param array{clubId: string, seasonId: string} $ctx
     */
    private function thirdVenue(array $ctx): string
    {
        $venue = (new Venue)->setClubId($ctx['clubId'])->setSeasonId($ctx['seasonId'])->setName('Gymnase Trois')->setSource('manual');
        $this->em->persist($venue);
        $this->em->flush();

        return $venue->getId();
    }

    private function createCoach(string $clubId, string $seasonId, string $firstName): string
    {
        $coach = (new Coach)->setClubId($clubId)->setSeasonId($seasonId)->setFirstName($firstName)->setLastName('Dupont');
        $this->em->persist($coach);
        $this->em->flush();

        return $coach->getId();
    }

    private function linkCoach(string $clubId, string $seasonId, string $teamId, string $coachId, TeamCoachRole $role): void
    {
        $link = (new TeamCoach)->setClubId($clubId)->setSeasonId($seasonId)->setTeamId($teamId)->setCoachId($coachId)->setRole($role);
        $this->em->persist($link);
        $this->em->flush();
    }

    /**
     * @param array{clubId: string, seasonId: string, teamA: string, teamB: string, venue1: string, venue2: string, coachId: string} $ctx
     */
    private function reserve(array $ctx, string $teamId, string $venueId, int $dayOfWeek, string $start, int $minutes): Reservation
    {
        $reservation = (new Reservation)
            ->setClubId($ctx['clubId'])
            ->setSeasonId($ctx['seasonId'])
            ->setTeamId($teamId)
            ->setVenueId($venueId)
            ->setDayOfWeek($dayOfWeek)
            ->setStartTime(new DateTimeImmutable($start))
            ->setDurationMinutes($minutes);
        $this->em->persist($reservation);
        $this->em->flush();

        return $reservation;
    }
}
