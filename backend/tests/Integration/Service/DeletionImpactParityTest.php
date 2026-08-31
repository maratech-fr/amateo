<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Deletion\CascadePlan;
use App\Deletion\DeletionImpactCounter;
use App\Entity\Club;
use App\Entity\Coach;
use App\Entity\Fixture;
use App\Entity\MatchSlotRotation;
use App\Entity\MatchSlotRotationTeam;
use App\Entity\Reservation;
use App\Entity\Schedule;
use App\Entity\SchedulePlan;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\Season;
use App\Entity\SharedTrainingBlock;
use App\Entity\SharedTrainingBlockTeam;
use App\Entity\Team;
use App\Entity\TeamCoach;
use App\Entity\Venue;
use App\Entity\VenueTrainingSlot;
use App\Entity\VenueTravelTime;
use App\Enum\FixtureHomeAway;
use App\Enum\FixtureStatus;
use App\Enum\FixtureUnplacedReason;
use App\Enum\LockLevel;
use App\Enum\SchedulePlanType;
use App\Enum\ScheduleStatus;
use App\Enum\SeasonStatus;
use App\Enum\TeamCoachRole;
use App\Service\EntityCascadeDeleter;
use App\Service\FixtureVenueLossMarker;
use App\Service\OrphanedFixtureFinder;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use ReflectionClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * NR BLOQUANT — CE QU'ON ANNONCE == CE QU'ON DÉTRUIT (axes §7.1 : planning lifecycle,
 * périmètre engagé).
 *
 * P3-16. Avant ce lot, la modale de suppression comptait **côté front, depuis le cache
 * react-query** : elle annonçait 2 familles quand supprimer un gymnase en emportait 7 et en
 * dépointait 3 — dont les séances de TOUS les plannings (celui en vigueur compris) et la
 * salle de matchs déjà déclarés à la fédération (DOC-2). Le gestionnaire confirmait une
 * destruction qu'on ne lui avait jamais montrée.
 *
 * L'invariant gardé ici est la **maison unique** : `CascadePlan` est la seule liste, exécutée
 * par `EntityCascadeDeleter` et comptée par `DeletionImpactCounter`. Trois verrous :
 *
 *   1. **aucune étape muette par accident** — toute étape sans libellé doit figurer dans la
 *      liste FERMÉE ci-dessous, nommément. Ajouter une destruction sans son annonce rougit ;
 *      donner un libellé à une étape déclarée muette rougit aussi (falsifié dans les deux sens) ;
 *   2. **aucune destruction hors du plan** — les trois purges ne contiennent aucun DQL en
 *      propre : elles délèguent. Sans ce verrou, on pourrait re-glisser un `delete()` dans
 *      `EntityCascadeDeleter` et rouvrir exactement la dérive qu'on vient de fermer ;
 *   3. **l'annoncé se vérifie en base** — sur un gymnase réel, chaque ligne annoncée
 *      correspond à ce qui disparaît vraiment, et ce qui SURVIT (le match, dépointé) survit.
 */
#[Group('phase1')]
#[Group('integration')]
final class DeletionImpactParityTest extends KernelTestCase
{
    use ChoosesPlanVersionTrait;
    use TenantGucTrait;

    /**
     * Les étapes délibérément SILENCIEUSES, par leur classe d'entité et leur champ.
     *
     * Un diagnostic est un constat du solveur sur une génération passée : il ne survit pas à
     * son sujet, et l'annoncer n'aiderait personne à décider — c'est du bruit dans une modale
     * qui doit se lire en trois secondes. Toute autre étape muette est un OUBLI.
     */
    private const SILENT_STEPS = ['ScheduleDiagnostic.teamId', 'ScheduleDiagnostic.venueId', 'ScheduleDiagnostic.coachId'];

    private EntityManagerInterface $em;

    public function testEveryDestructionIsAnnounced(): void
    {
        $silent = [];
        foreach (['team' => CascadePlan::forTeam(), 'venue' => CascadePlan::forVenue(), 'coach' => CascadePlan::forCoach(), 'slot' => CascadePlan::forSlot()] as $kind => $steps) {
            self::assertNotSame([], $steps, "le plan « {$kind} » ne peut pas être vide");
            foreach ($steps as $step) {
                if (null === $step->label()) {
                    $silent[] = $this->describe($step);
                }
            }
        }

        sort($silent);
        $expected = self::SILENT_STEPS;
        sort($expected);
        // Égalité STRICTE : une étape muette non listée est un oubli d'annonce ; une étape
        // listée qui a gagné un libellé doit sortir de la liste. Les deux sens rougissent.
        self::assertSame($expected, $silent, 'toute étape sans libellé doit être déclarée muette nommément');
    }

    public function testNoDestructionEscapesThePlan(): void
    {
        $source = file_get_contents(new ReflectionClass(EntityCascadeDeleter::class)->getFileName() ?: '');
        self::assertIsString($source);

        foreach (['purgeChildrenOfTeam', 'purgeChildrenOfVenue', 'purgeChildrenOfCoach', 'purgeChildrenOfSlot'] as $method) {
            $body = $this->methodBody($source, $method);
            self::assertStringContainsString('CascadePlan::', $body, "{$method} doit déléguer au plan");
            // Le DQL en propre est précisément ce qui rouvrirait la dérive : une destruction
            // que le compteur ne voit pas.
            self::assertStringNotContainsString('createQueryBuilder', $body, "{$method} ne doit contenir aucun DQL propre — tout passe par le plan");
        }
    }

    public function testTheAnnouncedImpactMatchesWhatTheDeleteActuallyDoes(): void
    {
        [$club, $season] = $this->seed();
        $venue = $this->venue($club, $season, 'Matéo');
        $other = $this->venue($club, $season, 'Debarros');
        $team = $this->team($club, $season, forcedVenueId: $venue->getId());
        $schedule = $this->schedule($club, $season);

        $this->em->persist((new VenueTrainingSlot)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setVenueId($venue->getId())->setDayOfWeek(1)->setStartTime(new DateTimeImmutable('18:00'))->setDurationMinutes(90)->setCapacity(1));
        $this->em->persist((new Reservation)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setTeamId($team->getId())->setVenueId($venue->getId())->setDayOfWeek(1)->setStartTime(new DateTimeImmutable('18:00'))->setDurationMinutes(90));
        $this->em->persist((new ScheduleSlotTemplate)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setScheduleId($schedule->getId())->setTeamId($team->getId())->setVenueId($venue->getId())
            ->setDayOfWeek(1)->setStartTime(new DateTimeImmutable('18:00'))->setDurationMinutes(90));
        // DOC-2 : un match DÉJÀ DÉCLARÉ à la fédération, posé dans ce gymnase.
        $declared = (new Fixture)->setClubId($club->getId())->setSeasonId($season->getId())->setTeamId($team->getId())
            ->setMatchDate(new DateTimeImmutable('2026-01-10'))->setHomeAway(FixtureHomeAway::HOME)->setOpponentLabel('Adversaire')
            ->setStatus(FixtureStatus::SUBMITTED)->setVenueId($venue->getId());
        $this->em->persist($declared);
        // Un créneau du même club dans un AUTRE gymnase : il ne doit ni être compté ni partir.
        $this->em->persist((new VenueTrainingSlot)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setVenueId($other->getId())->setDayOfWeek(2)->setStartTime(new DateTimeImmutable('19:00'))->setDurationMinutes(90)->setCapacity(1));
        $this->em->flush();

        $impact = self::getContainer()->get(DeletionImpactCounter::class)->forVenue($venue);
        $announced = [];
        foreach ($impact->lines as $line) {
            $announced[$line['key']] = $line['count'];
        }

        self::assertSame(1, $announced['venue_slot'] ?? 0, 'le créneau de disponibilité est annoncé');
        self::assertSame(1, $announced['venue_reservation'] ?? 0, 'la réservation est annoncée');
        self::assertSame(1, $announced['venue_slot_template'] ?? 0, 'la séance placée est annoncée');
        self::assertSame(1, $announced['venue_forced_team'] ?? 0, 'l\'équipe qui perd son gymnase imposé est annoncée');
        self::assertSame(1, $announced['venue_fixture'] ?? 0, 'le match qui perd sa salle est annoncé');
        self::assertSame(1, $impact->declaredFixtures, 'DOC-2 : le match DÉJÀ DÉCLARÉ est compté à part');
        self::assertFalse($impact->blocked, 'un gymnase ne se refuse pas : la décision fondateur laisse le geste passer');

        self::getContainer()->get(EntityCascadeDeleter::class)->purgeChildrenOfVenue($venue);
        $this->em->flush();
        $this->em->clear();

        // Ce qui était annoncé a bien disparu…
        self::assertSame(0, $this->countBy(VenueTrainingSlot::class, 'venueId', $venue->getId()));
        self::assertSame(0, $this->countBy(Reservation::class, 'venueId', $venue->getId()));
        self::assertSame(0, $this->countBy(ScheduleSlotTemplate::class, 'venueId', $venue->getId()));
        self::assertNull($this->em->getRepository(Team::class)->find($team->getId())?->getForcedVenueId());
        // …et ce qui SURVIT survit : le match reste, sans sa salle (il redevient « à placer »,
        // donc récupérable — c'est ce qui justifie d'avertir plutôt que de refuser).
        $reloaded = $this->em->getRepository(Fixture::class)->find($declared->getId());
        self::assertNotNull($reloaded, 'un match déclaré ne disparaît pas avec le gymnase');
        self::assertNull($reloaded->getVenueId());
        // P2-52 — la MÊME bascule que la gâchette validation : « à placer » + raison persistante.
        self::assertSame(FixtureStatus::UNPLACED, $reloaded->getStatus(), 'le match redevient « à placer »');
        self::assertSame(FixtureUnplacedReason::VENUE_LOST, $reloaded->getUnplacedReason(), 'la raison venue_lost est posée');
        // La frontière du gymnase tient : l'autre salle est intacte.
        self::assertSame(1, $this->countBy(VenueTrainingSlot::class, 'venueId', $other->getId()));
    }

    public function testASlotAnnouncesItsPinsAndNeverCrossesItsOwnLayer(): void
    {
        [$club, $season] = $this->seed();
        $venue = $this->venue($club, $season, 'Matéo');
        $team = $this->team($club, $season, forcedVenueId: $venue->getId());
        $schedule = $this->schedule($club, $season);
        $at = new DateTimeImmutable('18:00');

        $slot = (new VenueTrainingSlot)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setVenueId($venue->getId())->setDayOfWeek(1)->setStartTime($at)->setDurationMinutes(90)->setCapacity(1);
        $this->em->persist($slot);
        // Les DEUX faces d'un même épinglage : la réservation ET le verrou HARD qu'elle a
        // matérialisé. C'est le second que l'écran n'annonçait pas.
        $this->em->persist((new Reservation)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setTeamId($team->getId())->setVenueId($venue->getId())->setDayOfWeek(1)->setStartTime($at)->setDurationMinutes(90));
        $this->em->persist((new ScheduleSlotTemplate)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setScheduleId($schedule->getId())->setTeamId($team->getId())->setVenueId($venue->getId())
            ->setDayOfWeek(1)->setStartTime($at)->setDurationMinutes(90)->setLockLevel(LockLevel::HARD));
        // Un placement que le SOLVEUR a choisi au même horaire : c'est un RÉSULTAT, il
        // appartient à sa version — ni annoncé, ni détruit.
        $solverPick = (new ScheduleSlotTemplate)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setScheduleId($schedule->getId())->setTeamId($team->getId())->setVenueId($venue->getId())
            ->setDayOfWeek(1)->setStartTime($at)->setDurationMinutes(90)->setLockLevel(LockLevel::NONE);
        $this->em->persist($solverPick);
        // Une réservation d'une PÉRIODE au même triplet : le socle ne doit JAMAIS l'emporter
        // (invariant fondateur n°1 — une période ne modifie pas le planning principal, et
        // réciproquement).
        $periodPlan = (new SchedulePlan)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setType(SchedulePlanType::CLOSURE)->setName('Reprise')
            ->setStartDate(new DateTimeImmutable('2026-02-16'))->setEndDate(new DateTimeImmutable('2026-02-28'));
        $this->em->persist($periodPlan);
        $this->em->flush();
        $periodReservation = (new Reservation)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setTeamId($team->getId())->setVenueId($venue->getId())->setDayOfWeek(1)->setStartTime($at)->setDurationMinutes(90)
            ->setSchedulePlanId($periodPlan->getId());
        $this->em->persist($periodReservation);
        $this->em->flush();

        $impact = self::getContainer()->get(DeletionImpactCounter::class)->forSlot($slot);
        $announced = [];
        foreach ($impact->lines as $line) {
            $announced[$line['key']] = $line['count'];
        }

        self::assertSame(1, $announced['slot_reservation'] ?? 0, 'la réservation de SA couche est annoncée, pas celle de la période');
        self::assertSame(1, $announced['slot_hard_lock'] ?? 0, 'le verrou HARD est annoncé — c’est ce que l’écran taisait');

        self::getContainer()->get(EntityCascadeDeleter::class)->purgeChildrenOfSlot($slot);
        $this->em->flush();
        $this->em->clear();

        // L'annoncé a disparu…
        self::assertSame(0, $this->countBaseReservations($venue->getId()), 'la réservation de SOCLE est partie');
        self::assertSame(0, $this->countHardLocks($venue->getId()));
        // …et rien d'autre : le placement du solveur et la réservation de la PÉRIODE survivent.
        self::assertNotNull($this->em->getRepository(ScheduleSlotTemplate::class)->find($solverPick->getId()), 'un placement SOFT/NONE est un résultat, jamais un épinglage');
        self::assertNotNull($this->em->getRepository(Reservation::class)->find($periodReservation->getId()), 'la réservation d’une PÉRIODE ne part pas avec un créneau de saison');
    }

    /**
     * P2-51 — la cascade d'une ÉQUIPE membre de BLOCS, EXÉCUTÉE en base : supprimer une équipe TUE
     * le bloc entier (décision fondateur, D12), pas seulement sa ligne. L'annonce dit TOUT ce qui
     * tombe et RIEN de plus : `team_shared_block` = les blocs où elle FIGURE (pas les autres) →
     * tous détruits, avec toutes leurs lignes (survivants compris) ; un bloc sans elle ne bouge pas.
     */
    public function testDeletingATeamKillsEveryBlockItBelongsToEntirely(): void
    {
        [$club, $season] = $this->seed();
        $venue = $this->venue($club, $season, 'Matéo');
        $doomed = $this->team($club, $season, forcedVenueId: $venue->getId());
        $mate = $this->team($club, $season, forcedVenueId: $venue->getId());
        $third = $this->team($club, $season, forcedVenueId: $venue->getId());
        $stranger = $this->team($club, $season, forcedVenueId: $venue->getId());

        // Un duo et un trio où l'équipe supprimée FIGURE ; un bloc étranger où elle n'est pas.
        $duo = $this->sharedBlock($club, $season, [$doomed, $mate]);
        $trio = $this->sharedBlock($club, $season, [$doomed, $mate, $third]);
        $untouched = $this->sharedBlock($club, $season, [$mate, $stranger]);
        $this->em->flush();

        $impact = self::getContainer()->get(DeletionImpactCounter::class)->forTeam($doomed);
        $announced = [];
        foreach ($impact->lines as $line) {
            $announced[$line['key']] = $line['count'];
        }
        // Le compte annoncé == exactement ce qui sera détruit (les 2 blocs où elle figure, pas le 3e).
        self::assertSame(2, $announced['team_shared_block'] ?? 0, 'les 2 blocs où elle figure sont annoncés, pas le 3e');

        self::getContainer()->get(EntityCascadeDeleter::class)->purgeChildrenOfTeam($doomed);
        $this->em->flush();
        $this->em->clear();

        // Le duo ET le trio sont partis — bloc ET toutes leurs lignes, survivants compris.
        self::assertNull($this->em->getRepository(SharedTrainingBlock::class)->find($duo), 'un duo amputé meurt entier');
        self::assertSame(0, $this->countBy(SharedTrainingBlockTeam::class, 'blockId', $duo), 'ses lignes partent avec lui, celle du survivant comprise');
        self::assertNull($this->em->getRepository(SharedTrainingBlock::class)->find($trio), 'un trio amputé meurt entier : pas de seuil de survie');
        self::assertSame(0, $this->countBy(SharedTrainingBlockTeam::class, 'blockId', $trio), 'toutes ses lignes partent');

        // Le bloc étranger n'a pas bougé d'un pouce.
        self::assertNotNull($this->em->getRepository(SharedTrainingBlock::class)->find($untouched));
        self::assertSame(2, $this->countBy(SharedTrainingBlockTeam::class, 'blockId', $untouched), 'un bloc sans elle ne perd rien');
        self::assertSame(0, $this->countBy(SharedTrainingBlockTeam::class, 'teamId', $doomed->getId()));
    }

    /**
     * AUD-BCK-15 — la cascade d'un COACH, exécutée en base.
     *
     * Son plan mêle deux gestes que la structure seule ne distingue pas : ce qui est
     * SUPPRIMÉ (le lien équipe-coach) et ce qui est DÉTACHÉ (la séance placée garde son
     * créneau, elle perd juste son coach ; la doléance survit à son auteur). Confondre les
     * deux détruirait un planning au lieu d'en retirer un nom.
     */
    public function testDeletingACoachDetachesRatherThanDestroysWhatMustSurvive(): void
    {
        [$club, $season] = $this->seed();
        $venue = $this->venue($club, $season, 'Matéo');
        $team = $this->team($club, $season, forcedVenueId: $venue->getId());
        $schedule = $this->schedule($club, $season);

        $coach = (new Coach)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setFirstName('Alex')->setLastName('Martin')->setIsActive(true);
        $this->em->persist($coach);
        $this->em->flush();

        $this->em->persist((new TeamCoach)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setTeamId($team->getId())->setCoachId($coach->getId())->setRole(TeamCoachRole::MAIN)->setIsRequired(true));
        $placed = (new ScheduleSlotTemplate)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setScheduleId($schedule->getId())->setTeamId($team->getId())->setVenueId($venue->getId())
            ->setDayOfWeek(1)->setStartTime(new DateTimeImmutable('18:00'))->setDurationMinutes(90)
            ->setCoachId($coach->getId());
        $this->em->persist($placed);
        $this->em->flush();

        $impact = self::getContainer()->get(DeletionImpactCounter::class)->forCoach($coach);
        $announced = [];
        foreach ($impact->lines as $line) {
            $announced[$line['key']] = $line['count'];
        }
        self::assertSame(1, $announced['coach_team'] ?? 0, 'le lien équipe-coach est annoncé');
        self::assertSame(1, $announced['coach_slot'] ?? 0, 'la séance qui perdra son coach est annoncée');

        self::getContainer()->get(EntityCascadeDeleter::class)->purgeChildrenOfCoach($coach);
        $this->em->flush();
        $this->em->clear();

        // SUPPRIMÉ : le lien n'a pas de sens sans son coach.
        self::assertSame(0, $this->countBy(TeamCoach::class, 'coachId', $coach->getId()));

        // DÉTACHÉ : la séance reste EXACTEMENT où elle est, sans son coach. C'est toute la
        // différence — supprimer ici trouerait le planning d'un club pour un départ de coach.
        $reloaded = $this->em->getRepository(ScheduleSlotTemplate::class)->find($placed->getId());
        self::assertNotNull($reloaded, 'une séance placée ne disparaît pas avec son coach');
        self::assertNull($reloaded->getCoachId(), 'elle perd son coach, pas son créneau');
        self::assertSame(1, $reloaded->getDayOfWeek());
        self::assertSame($venue->getId(), $reloaded->getVenueId());
    }

    /**
     * RMM-5 (P2-49) — la cascade d'une ÉQUIPE sur les créneaux de match partagés : elle quitte
     * ses rotations ; celles qui tombent SOUS 2 membres sont SUPPRIMÉES (annoncées), les autres
     * survivent en la perdant. Ce qui est annoncé (les créneaux < 2 détruits) == ce qui tombe ;
     * un créneau qui SURVIT n'est pas annoncé — il n'est pas détruit, l'équipe s'en va, c'est tout.
     *
     * Falsifiable : compter le trio survivant rougirait (assertSame 1) ; ne pas tuer le duo aussi.
     */
    public function testDeletingATeamPrunesUndersizedRotationsAndKeepsSurvivors(): void
    {
        [$club, $season] = $this->seed();
        $venue = $this->venue($club, $season, 'Coubertin');
        $doomed = $this->team($club, $season, forcedVenueId: $venue->getId());
        $mate = $this->team($club, $season, forcedVenueId: $venue->getId());
        $third = $this->team($club, $season, forcedVenueId: $venue->getId());

        // Un DUO où doomed figure (→ tombe à 1, MEURT), un TRIO (→ survit à 2), et un créneau
        // SANS doomed (intact). Créneaux distincts par (jour, heure) — l'unicité l'exige.
        $duo = $this->rotation($club, $season, $venue, day: 6, at: '20:30', teams: [$doomed, $mate]);
        $trio = $this->rotation($club, $season, $venue, day: 7, at: '18:00', teams: [$doomed, $mate, $third]);
        $untouched = $this->rotation($club, $season, $venue, day: 6, at: '18:00', teams: [$mate, $third]);
        $this->em->flush();

        $impact = self::getContainer()->get(DeletionImpactCounter::class)->forTeam($doomed);
        $announced = [];
        foreach ($impact->lines as $line) {
            $announced[$line['key']] = $line['count'];
        }
        self::assertSame(1, $announced['team_match_slot_rotation'] ?? 0, 'seul le créneau qui tombe < 2 est annoncé, pas le trio qui survit');

        self::getContainer()->get(EntityCascadeDeleter::class)->purgeChildrenOfTeam($doomed);
        $this->em->flush();
        $this->em->clear();

        // Le duo amputé est mort, lignes comprises.
        self::assertNull($this->em->getRepository(MatchSlotRotation::class)->find($duo));
        self::assertSame(0, $this->countBy(MatchSlotRotationTeam::class, 'rotationId', $duo));
        // Le trio survit — mais doomed n'y figure plus (membre purgé), mate + third restent.
        self::assertNotNull($this->em->getRepository(MatchSlotRotation::class)->find($trio));
        self::assertSame(2, $this->countBy(MatchSlotRotationTeam::class, 'rotationId', $trio));
        // Le créneau sans doomed n'a pas bougé.
        self::assertNotNull($this->em->getRepository(MatchSlotRotation::class)->find($untouched));
        self::assertSame(2, $this->countBy(MatchSlotRotationTeam::class, 'rotationId', $untouched));
        // doomed ne figure plus dans aucun créneau.
        self::assertSame(0, $this->countBy(MatchSlotRotationTeam::class, 'teamId', $doomed->getId()));
    }

    /**
     * RMM-5 — la cascade d'un GYMNASE : la rotation EST le créneau (venue_id NOT NULL), donc
     * supprimer le gymnase SUPPRIME ses créneaux partagés, parent ET lignes membres. Annoncé,
     * et strictement borné à ce gymnase (un créneau d'un AUTRE gymnase survit).
     */
    public function testDeletingAVenueAnnouncesAndDeletesItsTravelTimes(): void
    {
        [$club, $season] = $this->seed();
        $venue = $this->venue($club, $season, 'Coubertin');
        $other = $this->venue($club, $season, 'Debarros');
        $third = $this->venue($club, $season, 'Gerland');

        $this->travelTime($club, $season, $venue, $other);
        $survivor = $this->travelTime($club, $season, $other, $third);
        $this->em->flush();

        $impact = self::getContainer()->get(DeletionImpactCounter::class)->forVenue($venue);
        $announced = 0;
        foreach ($impact->lines as $line) {
            if (\in_array($line['key'], ['venue_travel_time_a', 'venue_travel_time_b'], true)) {
                $announced += $line['count'];
            }
        }
        self::assertSame(1, $announced, 'le barème de trajet du gymnase est annoncé (le couple avec l\'autre salle)');

        self::getContainer()->get(EntityCascadeDeleter::class)->purgeChildrenOfVenue($venue);
        $this->em->flush();
        $this->em->clear();

        // Annoncé == détruit : la matrice du gymnase supprimé est partie…
        self::assertSame(0, $this->countBy(VenueTravelTime::class, 'venueAId', $venue->getId()) + $this->countBy(VenueTravelTime::class, 'venueBId', $venue->getId()));
        // …et le couple des DEUX autres salles survit intact.
        self::assertNotNull($this->em->getRepository(VenueTravelTime::class)->find($survivor));
    }

    public function testDeletingAVenueDeletesItsSharedSlots(): void
    {
        [$club, $season] = $this->seed();
        $venue = $this->venue($club, $season, 'Coubertin');
        $other = $this->venue($club, $season, 'Debarros');
        $a = $this->team($club, $season, forcedVenueId: $venue->getId());
        $b = $this->team($club, $season, forcedVenueId: $venue->getId());

        $doomedRotation = $this->rotation($club, $season, $venue, day: 6, at: '20:30', teams: [$a, $b]);
        $otherRotation = $this->rotation($club, $season, $other, day: 6, at: '20:30', teams: [$a, $b]);
        $this->em->flush();

        $impact = self::getContainer()->get(DeletionImpactCounter::class)->forVenue($venue);
        $announced = [];
        foreach ($impact->lines as $line) {
            $announced[$line['key']] = $line['count'];
        }
        self::assertSame(1, $announced['venue_match_slot_rotation'] ?? 0, 'le créneau partagé du gymnase est annoncé');

        self::getContainer()->get(EntityCascadeDeleter::class)->purgeChildrenOfVenue($venue);
        $this->em->flush();
        $this->em->clear();

        // Le créneau du gymnase supprimé est parti, lignes comprises…
        self::assertNull($this->em->getRepository(MatchSlotRotation::class)->find($doomedRotation));
        self::assertSame(0, $this->countBy(MatchSlotRotationTeam::class, 'rotationId', $doomedRotation));
        // …et celui de l'AUTRE gymnase survit intact.
        self::assertNotNull($this->em->getRepository(MatchSlotRotation::class)->find($otherRotation));
        self::assertSame(2, $this->countBy(MatchSlotRotationTeam::class, 'rotationId', $otherRotation));
    }

    /**
     * P2-52 (RMM-10) — le VOLET VALIDATION de la parité annoncé==dépointé. Un match DÉCLARÉ posé
     * sur un gymnase DISPARU (pointeur pendouillant, laissé par une exploration) : la route
     * `validate-impact` annonce {1, 1} (MÊME prédicat `OrphanedFixtureFinder`), et la gâchette de
     * validation dépointe EXACTEMENT lui — « à placer », raison venue_lost, HEURE conservée. Un
     * match sur un gymnase RÉEL (témoin) n'est ni annoncé ni touché (falsifié dans les deux sens).
     */
    public function testValidationImpactAnnouncesTheOrphanAndValidationUnplacesExactlyIt(): void
    {
        [$club, $season] = $this->seed();
        $team = $this->team($club, $season, forcedVenueId: '11111111-1111-4111-8111-111111111111');
        $realVenue = $this->venue($club, $season, 'Coubertin');
        // Le pointeur pendouillant : un gymnase qui n'existe pas (aucune ligne venue ne le porte).
        $ghostVenueId = '22222222-2222-4222-8222-222222222222';

        $orphan = (new Fixture)->setClubId($club->getId())->setSeasonId($season->getId())->setTeamId($team->getId())
            ->setMatchDate(new DateTimeImmutable('2026-01-10'))->setHomeAway(FixtureHomeAway::HOME)->setOpponentLabel('Adv')
            ->setStatus(FixtureStatus::SUBMITTED)->setVenueId($ghostVenueId)->setKickoffTime(new DateTimeImmutable('15:30'));
        $this->em->persist($orphan);
        // Témoin : un match posé sur un VRAI gymnase — le prédicat ne doit pas le voir.
        $sound = (new Fixture)->setClubId($club->getId())->setSeasonId($season->getId())->setTeamId($team->getId())
            ->setMatchDate(new DateTimeImmutable('2026-01-17'))->setHomeAway(FixtureHomeAway::HOME)->setOpponentLabel('Adv2')
            ->setStatus(FixtureStatus::PLACED)->setVenueId($realVenue->getId())->setKickoffTime(new DateTimeImmutable('16:00'));
        $this->em->persist($sound);
        $this->em->flush();

        $finder = self::getContainer()->get(OrphanedFixtureFinder::class);
        $impact = $finder->impact($club->getId(), $season->getId());
        self::assertSame(1, $impact['orphanedFixtures'], 'le match au gymnase disparu est annoncé');
        self::assertSame(1, $impact['declaredOrphanedFixtures'], 'et il est déjà déclaré à la fédération');

        // La gâchette de validation : dépointer EXACTEMENT les orphelins nommés par le prédicat.
        $ids = array_map(static fn (Fixture $f): string => $f->getId(), $finder->orphanedFixtures($club->getId(), $season->getId()));
        self::getContainer()->get(FixtureVenueLossMarker::class)->mark($ids);
        $this->em->clear();

        $reloadedOrphan = $this->em->getRepository(Fixture::class)->find($orphan->getId());
        self::assertNotNull($reloadedOrphan);
        self::assertNull($reloadedOrphan->getVenueId(), 'dépointé');
        self::assertSame(FixtureStatus::UNPLACED, $reloadedOrphan->getStatus());
        self::assertSame(FixtureUnplacedReason::VENUE_LOST, $reloadedOrphan->getUnplacedReason());
        self::assertSame('15:30', $reloadedOrphan->getKickoffTime()?->format('H:i'), 'l\'heure est conservée en repère');

        // Le témoin n'a pas bougé d'un pixel.
        $reloadedSound = $this->em->getRepository(Fixture::class)->find($sound->getId());
        self::assertNotNull($reloadedSound);
        self::assertSame($realVenue->getId(), $reloadedSound->getVenueId(), 'un match au vrai gymnase n\'est pas touché');
        self::assertSame(FixtureStatus::PLACED, $reloadedSound->getStatus());
        self::assertNull($reloadedSound->getUnplacedReason());
    }

    /**
     * P2-52 — l'AUTRE sens : quand tous les gymnases existent, la route annonce {0, 0} ET la
     * validation ne touche RIEN (byte-identique à hier). C'est la garantie « aucun bruit
     * préventif » (verbatim fondateur) prouvée en base.
     */
    public function testValidationImpactIsZeroWhenEveryVenueExistsAndValidationTouchesNothing(): void
    {
        [$club, $season] = $this->seed();
        $team = $this->team($club, $season, forcedVenueId: '11111111-1111-4111-8111-111111111111');
        $venue = $this->venue($club, $season, 'Coubertin');
        $fixture = (new Fixture)->setClubId($club->getId())->setSeasonId($season->getId())->setTeamId($team->getId())
            ->setMatchDate(new DateTimeImmutable('2026-01-10'))->setHomeAway(FixtureHomeAway::HOME)->setOpponentLabel('Adv')
            ->setStatus(FixtureStatus::SUBMITTED)->setVenueId($venue->getId())->setKickoffTime(new DateTimeImmutable('15:30'));
        $this->em->persist($fixture);
        $this->em->flush();

        $finder = self::getContainer()->get(OrphanedFixtureFinder::class);
        $impact = $finder->impact($club->getId(), $season->getId());
        self::assertSame(0, $impact['orphanedFixtures'], 'aucun gymnase disparu : rien à annoncer');
        self::assertSame(0, $impact['declaredOrphanedFixtures']);

        $ids = array_map(static fn (Fixture $f): string => $f->getId(), $finder->orphanedFixtures($club->getId(), $season->getId()));
        self::assertSame([], $ids);
        self::getContainer()->get(FixtureVenueLossMarker::class)->mark($ids);
        $this->em->clear();

        $reloaded = $this->em->getRepository(Fixture::class)->find($fixture->getId());
        self::assertNotNull($reloaded);
        self::assertSame($venue->getId(), $reloaded->getVenueId(), 'rien dépointé');
        self::assertSame(FixtureStatus::SUBMITTED, $reloaded->getStatus(), 'byte-identique : le statut ne bouge pas');
        self::assertNull($reloaded->getUnplacedReason());
    }

    /**
     * P2-52 — LES DEUX GÂCHETTES MÈNENT AU MÊME ÉTAT FINAL. Un match dépointé par la SUPPRESSION
     * de son gymnase et un match dépointé par la VALIDATION (pointeur pendouillant) atterrissent
     * dans un état byte-identique : « à placer », raison venue_lost, heure conservée. Le foyer
     * unique (`FixtureVenueLossMarker`) le garantit — ce test le prouve.
     */
    public function testBothTriggersReachTheSameFinalStateOnAVenueLostMatch(): void
    {
        [$club, $season] = $this->seed();
        $team = $this->team($club, $season, forcedVenueId: '11111111-1111-4111-8111-111111111111');

        // Chemin A — suppression de gymnase : le match posé dessus est marqué par le step.
        $venueA = $this->venue($club, $season, 'Matéo');
        $fixtureA = (new Fixture)->setClubId($club->getId())->setSeasonId($season->getId())->setTeamId($team->getId())
            ->setMatchDate(new DateTimeImmutable('2026-01-10'))->setHomeAway(FixtureHomeAway::HOME)->setOpponentLabel('Adv')
            ->setStatus(FixtureStatus::SUBMITTED)->setVenueId($venueA->getId())->setKickoffTime(new DateTimeImmutable('15:30'));
        $this->em->persist($fixtureA);
        // Chemin B — validation : le gymnase a déjà disparu (pointeur pendouillant).
        $fixtureB = (new Fixture)->setClubId($club->getId())->setSeasonId($season->getId())->setTeamId($team->getId())
            ->setMatchDate(new DateTimeImmutable('2026-01-10'))->setHomeAway(FixtureHomeAway::HOME)->setOpponentLabel('Adv')
            ->setStatus(FixtureStatus::SUBMITTED)->setVenueId('33333333-3333-4333-8333-333333333333')->setKickoffTime(new DateTimeImmutable('15:30'));
        $this->em->persist($fixtureB);
        $this->em->flush();

        // A : le geste de suppression de gymnase.
        self::getContainer()->get(EntityCascadeDeleter::class)->purgeChildrenOfVenue($venueA);
        $this->em->flush();
        // B : la gâchette de validation (le prédicat ne voit plus A — son venueId est déjà NULL).
        $finder = self::getContainer()->get(OrphanedFixtureFinder::class);
        $ids = array_map(static fn (Fixture $f): string => $f->getId(), $finder->orphanedFixtures($club->getId(), $season->getId()));
        self::assertSame([$fixtureB->getId()], $ids, 'seul B est orphelin — A a déjà été dépointé par le step');
        self::getContainer()->get(FixtureVenueLossMarker::class)->mark($ids);
        $this->em->clear();

        $reloadedA = $this->em->getRepository(Fixture::class)->find($fixtureA->getId());
        $reloadedB = $this->em->getRepository(Fixture::class)->find($fixtureB->getId());
        self::assertNotNull($reloadedA);
        self::assertNotNull($reloadedB);
        $stateOf = static fn (Fixture $f): array => [
            $f->getVenueId(),
            $f->getStatus(),
            $f->getUnplacedReason(),
            $f->getKickoffTime()?->format('H:i'),
        ];
        self::assertSame([null, FixtureStatus::UNPLACED, FixtureUnplacedReason::VENUE_LOST, '15:30'], $stateOf($reloadedA), 'suppression gymnase : état attendu');
        self::assertSame($stateOf($reloadedA), $stateOf($reloadedB), 'les deux gâchettes mènent au MÊME état final');
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    private function describe(object $step): string
    {
        $reflection = new ReflectionClass($step);
        $class = $reflection->hasProperty('entityClass') ? (string) $reflection->getProperty('entityClass')->getValue($step) : $reflection->getShortName();
        $field = $reflection->hasProperty('field') ? (string) $reflection->getProperty('field')->getValue($step) : '?';
        $short = str_contains($class, '\\') ? substr((string) strrchr($class, '\\'), 1) : $class;

        return $short . '.' . $field;
    }

    private function methodBody(string $source, string $method): string
    {
        $start = strpos($source, 'function ' . $method . '(');
        self::assertIsInt($start, "méthode {$method} introuvable");
        $open = strpos($source, '{', $start);
        self::assertIsInt($open);
        $depth = 0;
        for ($i = $open; $i < \strlen($source); ++$i) {
            $depth += '{' === $source[$i] ? 1 : ('}' === $source[$i] ? -1 : 0);
            if (0 === $depth) {
                return substr($source, $open, $i - $open + 1);
            }
        }
        self::fail("corps de {$method} non borné");
    }

    /** Les réservations de SOCLE (hors période) encore posées sur ce gymnase. */
    private function countBaseReservations(string $venueId): int
    {
        return (int) $this->em->createQueryBuilder()->select('COUNT(e.id)')->from(Reservation::class, 'e')
            ->where('e.venueId = :v')->andWhere('e.schedulePlanId IS NULL')
            ->setParameter('v', $venueId)
            ->getQuery()->getSingleScalarResult();
    }

    /** Les verrous HARD encore posés sur ce gymnase, toutes versions confondues. */
    private function countHardLocks(string $venueId): int
    {
        return (int) $this->em->createQueryBuilder()->select('COUNT(e.id)')->from(ScheduleSlotTemplate::class, 'e')
            ->where('e.venueId = :v')->andWhere('e.lockLevel = :hard')
            ->setParameter('v', $venueId)->setParameter('hard', LockLevel::HARD)
            ->getQuery()->getSingleScalarResult();
    }

    /** @param class-string $class */
    private function countBy(string $class, string $field, string $value): int
    {
        return (int) $this->em->createQueryBuilder()->select('COUNT(e.id)')->from($class, 'e')
            ->where(\sprintf('e.%s = :v', $field))->setParameter('v', $value)->getQuery()->getSingleScalarResult();
    }

    /** @return array{0: Club, 1: Season} */
    private function seed(): array
    {
        $uid = uniqid('', true);
        $club = (new Club)->setName('C ' . $uid)->setSlug('c-' . $uid)->setTimezone('Europe/Paris')->setLocale('fr')
            ->setOnboardingCompleted(true)->setFfbbClubCode('PSI' . strtoupper(substr(md5($uid), 0, 9)));
        $this->em->persist($club);
        $this->em->flush();
        $this->scopeGucToClub($club->getId());

        $season = (new Season)->setClubId($club->getId())->setName('2025-2026')
            ->setStartDate(new DateTimeImmutable('2025-09-01'))->setEndDate(new DateTimeImmutable('2026-06-30'))->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($season);
        $this->em->flush();
        $this->provisionSeasonPlan($season);

        return [$club, $season];
    }

    private function venue(Club $club, Season $season, string $name): Venue
    {
        $venue = (new Venue)->setClubId($club->getId())->setSeasonId($season->getId())->setName($name)->setSource('manual');
        $this->em->persist($venue);
        $this->em->flush();

        return $venue;
    }

    /** A travel-time bracket between two venues (normalized couple) and its id. */
    private function travelTime(Club $club, Season $season, Venue $x, Venue $y): string
    {
        [$lo, $hi] = strcasecmp($x->getId(), $y->getId()) > 0 ? [$y->getId(), $x->getId()] : [$x->getId(), $y->getId()];
        $travel = (new VenueTravelTime)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setVenueAId($lo)->setVenueBId($hi)->setDrivingMinutes(12);
        $this->em->persist($travel);
        $this->em->flush();

        return $travel->getId();
    }

    private function team(Club $club, Season $season, string $forcedVenueId): Team
    {
        // Catégorie littérale : ces entités ne portent AUCUNE clé étrangère (chaque lien est
        // une simple colonne guid — c'est précisément pourquoi la cascade existe), donc rien
        // n'exige qu'elle existe. Même idiome qu'`OrphanPinGuardTest`.
        $team = (new Team)->setClubId($club->getId())->setSeasonId($season->getId())->setName('U13 F1')
            ->setSportCategoryId('99999999-9999-4999-8999-999999999999')->setPriorityTierId(1)->setSessionsPerWeek(1)
            ->setForcedVenueId($forcedVenueId);
        $this->em->persist($team);
        $this->em->flush();

        return $team;
    }

    /** Une réservation de socle et son id (pour la retrouver après le clear de l'EM). */
    private function reservation(Club $club, Season $season, Team $team, Venue $venue, int $day, string $at): string
    {
        $reservation = (new Reservation)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setTeamId($team->getId())->setVenueId($venue->getId())->setDayOfWeek($day)
            ->setStartTime(new DateTimeImmutable($at))->setDurationMinutes(90);
        $this->em->persist($reservation);

        return $reservation->getId();
    }

    /** Un bloc de mutualisation et ses membres. @param list<Team> $teams */
    private function sharedBlock(Club $club, Season $season, array $teams): string
    {
        $block = (new SharedTrainingBlock)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setCommonSessions(1);
        $this->em->persist($block);
        $this->em->flush();

        foreach ($teams as $team) {
            $this->em->persist((new SharedTrainingBlockTeam)->setClubId($club->getId())->setSeasonId($season->getId())
                ->setBlockId($block->getId())->setTeamId($team->getId()));
        }
        $this->em->flush();

        return $block->getId();
    }

    /** Un créneau de match partagé (rotation A/B) et ses membres ordonnés. @param list<Team> $teams */
    private function rotation(Club $club, Season $season, Venue $venue, int $day, string $at, array $teams): string
    {
        $rotation = (new MatchSlotRotation)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setVenueId($venue->getId())->setDayOfWeek($day)->setKickoffTime(new DateTimeImmutable($at));
        $this->em->persist($rotation);
        $this->em->flush();

        foreach (array_values($teams) as $position => $team) {
            $this->em->persist((new MatchSlotRotationTeam)->setClubId($club->getId())->setSeasonId($season->getId())
                ->setRotationId($rotation->getId())->setTeamId($team->getId())->setPosition($position));
        }
        $this->em->flush();

        return $rotation->getId();
    }

    private function schedule(Club $club, Season $season): Schedule
    {
        // Une version appartient TOUJOURS à un plan (ADR-0002) : on rattache au plan SEASON.
        $plan = $this->em->getRepository(SchedulePlan::class)->findOneBy(['seasonId' => $season->getId(), 'type' => SchedulePlanType::SEASON]);
        self::assertNotNull($plan, 'la saison naît avec son plan SEASON');
        $schedule = (new Schedule)->setClubId($club->getId())->setSeasonId($season->getId())->setName('V1')
            ->setSchedulePlanId($plan->getId())->setStatus(ScheduleStatus::COMPLETED);
        $this->em->persist($schedule);
        $this->em->flush();

        return $schedule;
    }
}
