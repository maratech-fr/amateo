<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\PriorityTier;
use App\Entity\Schedule;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\Season;
use App\Entity\SharedTrainingBlock;
use App\Entity\SharedTrainingBlockTeam;
use App\Entity\Sport;
use App\Entity\SportCategory;
use App\Entity\Team;
use App\Entity\TeamPeriodOverride;
use App\Entity\User;
use App\Entity\Venue;
use App\Entity\VenuePeriodOverride;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\CalendarEntryStatus;
use App\Enum\LockLevel;
use App\Enum\ScheduleStatus;
use App\Enum\SeasonStatus;
use App\Enum\VenueDayState;
use App\Enum\VenuePeriodMode;
use App\Service\PeriodPlanTranscriber;
use App\Service\SchedulePlanProvisioner;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\ProvisionsPeriodPlanTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * NR BLOQUANT — P2-44 PR-5 « les écarts NOMMÉS vs le socle » (axes §7.1 : generation pipeline +
 * planning lifecycle).
 *
 * PROUVE que `GET /api/schedules/{id}/socle-deviation` NOMME EXACTEMENT les écarts entre la version
 * affichée d'un plan de PÉRIODE de type FERMETURE et la version POINTÉE du socle — falsifié dans les
 * DEUX sens :
 *   - une séance BOUGÉE apparaît en `moved` avec `from`/`to` exacts ;
 *   - une séance INCHANGÉE n'apparaît nulle part ;
 *   - une séance NOUVELLE (période sans contrepartie socle) n'apparaît nulle part ;
 *   - une NON REPLACÉE porte la raison DE LA SÉLECTION : fermer le jour du gymnase dans la sélection
 *     donne `venue_closed`, un gymnase désactivé `venue_disabled`, une réduction `team_reduced` ;
 *     une absence INEXPLIQUÉE par la sélection (suppression manuelle) porte `null`, JAMAIS une raison
 *     fabriquée ;
 *   - la RÉDUCTION laisse en reliquat les DERNIÈRES de la semaine (déterminisme épinglé) ;
 *   - une équipe DÉSACTIVÉE n'a aucun écart (ni déplacée ni non replacée).
 *
 * Puis les REFUS nommés : 422 plan SEASON, 422 période HOLIDAY, 409 version non COMPLETED, 409 socle
 * non pointé, 404 tenant (autre club). Et la LECTURE est OUVERTE au Membre (pas de garde management).
 *
 * BILAN PAR RANG (fermetures de gymnase, PR A) — la même route sert aussi, par ÉQUIPE et par RANG,
 * les comptes que le panneau « Bilan par rang » affiche, tous tirés d'UNE source : les lignes
 * `moved`/`unplaced` (jamais l'override de séances) — `kept + lost + reducedByChoice = socle` par
 * équipe ; `changedSlotIds` = séances de période déplacées ou nouvelles (le filtre de la grille) ;
 * rangs triés poids décroissant puis libellé, sommes HORS pauses ; et la mention « commune avec » tirée
 * des blocs DÉCLARÉS du périmètre (socle : blocs de saison ; période : blocs du plan, équipes en
 * pause exclues), jamais d'une simple co-localisation. Méthodes à scaffold PROPRE : le décor du
 * cœur ci-dessous ne bouge pas.
 */
#[Group('phase1')]
#[Group('security')]
final class SocleDeviationParityTest extends WebTestCase
{
    use ChoosesPlanVersionTrait;
    use ProvisionsPeriodPlanTrait;
    use TenantGucTrait;

    private EntityManagerInterface $em;

    private KernelBrowser $client;

    private UserPasswordHasherInterface $hasher;

    private JWTTokenManagerInterface $jwt;

    private SportCategory $category;

    /** Le cœur : les deux catégories (déplacée / non replacée), falsifiées dans les deux sens. */
    public function testDeviationsAreNamedAgainstThePointedSocle(): void
    {
        [$user, $club, $season] = $this->seedClub('DEV');

        $vHealthy = $this->venue($club, $season, 'Sain');
        $vAlt = $this->venue($club, $season, 'Autre');
        $vDisabled = $this->venue($club, $season, 'Désactivé');
        $vClosed = $this->venue($club, $season, 'Fermé mercredi');

        $teamUnchanged = $this->team($club, $season, 'Unchanged');
        $teamMoved = $this->team($club, $season, 'Moved');
        $teamNew = $this->team($club, $season, 'New');
        $teamNull = $this->team($club, $season, 'UnplacedNull');
        $teamReduced = $this->team($club, $season, 'Reduced');
        $teamDisabled = $this->team($club, $season, 'DisabledVenue');
        $teamClosed = $this->team($club, $season, 'ClosedDay');
        $teamPaused = $this->team($club, $season, 'Paused');

        // La version POINTÉE du socle.
        $socle = $this->socleVersion($club, $season);
        $this->slot($socle, $teamUnchanged, $vHealthy, 1, '18:00');   // inchangée
        $this->slot($socle, $teamMoved, $vHealthy, 2, '18:30');       // déplacée → Jeu 19:00 vAlt
        $this->slot($socle, $teamNull, $vHealthy, 5, '20:00');        // supprimée à la main → non replacée reason null
        $this->slot($socle, $teamReduced, $vHealthy, 1, '18:00');     // conservée
        $this->slot($socle, $teamReduced, $vHealthy, 5, '20:00');     // réduite (dernière) → team_reduced
        $this->slot($socle, $teamDisabled, $vDisabled, 3, '18:00');   // gymnase désactivé → venue_disabled
        $this->slot($socle, $teamClosed, $vClosed, 3, '17:30');       // jour fermé → venue_closed
        $this->slot($socle, $teamPaused, $vHealthy, 1, '16:00');      // équipe en pause → aucun écart
        $this->em->flush();
        $this->choosePlanVersion($socle);

        // La période (FERMETURE) et son plan.
        $entry = $this->period($club, $season, CalendarEntryPeriodType::CLOSURE);
        $planId = $this->planIdOf($entry);

        // Les réglages de la période qui EXPLIQUENT les raisons.
        $this->venueDisabled($club, $season, $planId, $vDisabled);
        $this->venueDayClosed($club, $season, $planId, $vClosed, 3);
        $this->teamOverride($club, $season, $planId, $teamReduced, true, 1);
        $this->teamOverride($club, $season, $planId, $teamPaused, false, null);
        $this->em->flush();

        // La version affichée du plan de période (COMPLETED) — ce que le gestionnaire voit.
        $period = $this->periodVersion($club, $season, $planId, ScheduleStatus::COMPLETED);
        $this->slot($period, $teamUnchanged, $vHealthy, 1, '18:00');            // identique
        $periodMovedSlot = $this->slot($period, $teamMoved, $vAlt, 4, '19:00'); // déplacée
        $this->slot($period, $teamNew, $vHealthy, 1, '17:00');                  // nouvelle
        $this->slot($period, $teamReduced, $vHealthy, 1, '18:00');              // la conservée
        $this->em->flush();

        $body = $this->deviation($user, $club, $season, $period->getId());

        self::assertSame($socle->getId(), $body['socleScheduleId']);

        // ── DÉPLACÉES ───────────────────────────────────────────────────────────────────────────
        $movedByTeam = [];
        foreach ($body['moved'] as $row) {
            $movedByTeam[(string) $row['teamId']] = $row;
        }
        self::assertArrayHasKey($teamMoved->getId(), $movedByTeam, 'la séance bougée apparaît en déplacée');
        $mv = $movedByTeam[$teamMoved->getId()];
        self::assertSame(2, $mv['from']['dayOfWeek']);
        self::assertSame('18:30', $mv['from']['startTime']);
        self::assertSame($vHealthy->getId(), $mv['from']['venueId']);
        self::assertSame(4, $mv['to']['dayOfWeek']);
        self::assertSame('19:00', $mv['to']['startTime']);
        self::assertSame($vAlt->getId(), $mv['to']['venueId']);
        // PR-4 — `to` porte le slotId du créneau de PÉRIODE (celui que la grille affiche) pour
        // que le front marque LA carte déviée ; `from` (socle, non affiché) n'en a pas.
        self::assertSame($periodMovedSlot->getId(), $mv['to']['slotId'], 'le slotId servi = l\'id du slot de PÉRIODE');
        self::assertArrayNotHasKey('slotId', $mv['from'], 'le placement du socle n\'a pas de slotId (il n\'est pas affiché dans la grille)');

        // Falsification : une INCHANGÉE et une NOUVELLE ne sont JAMAIS déplacées.
        self::assertArrayNotHasKey($teamUnchanged->getId(), $movedByTeam, 'une inchangée n\'est pas déplacée');
        self::assertArrayNotHasKey($teamNew->getId(), $movedByTeam, 'une nouvelle n\'est pas déplacée');
        self::assertCount(1, $body['moved'], 'exactement une séance déplacée');

        // ── NON REPLACÉES (raison SERVIE par la sélection) ───────────────────────────────────────
        $reasonByTeam = [];
        foreach ($body['unplaced'] as $row) {
            $reasonByTeam[(string) $row['teamId']] = $row['reason'];
        }
        self::assertArrayHasKey($teamReduced->getId(), $reasonByTeam);
        self::assertSame(PeriodPlanTranscriber::SKIP_TEAM_REDUCED, $reasonByTeam[$teamReduced->getId()]);
        self::assertSame(PeriodPlanTranscriber::SKIP_VENUE_DISABLED, $reasonByTeam[$teamDisabled->getId()] ?? null);
        self::assertSame(PeriodPlanTranscriber::SKIP_VENUE_CLOSED, $reasonByTeam[$teamClosed->getId()] ?? null);
        // Absence INEXPLIQUÉE par la sélection → null, jamais fabriquée.
        self::assertArrayHasKey($teamNull->getId(), $reasonByTeam);
        self::assertNull($reasonByTeam[$teamNull->getId()], 'une suppression manuelle n\'a pas de raison servie');

        // DÉTERMINISME de la réduction : c'est bien la Ven 20:00 (dernière) qui est non replacée.
        $reducedRow = null;
        foreach ($body['unplaced'] as $row) {
            if ($teamReduced->getId() === $row['teamId']) {
                $reducedRow = $row;
            }
        }
        self::assertNotNull($reducedRow);
        self::assertSame(5, $reducedRow['dayOfWeek']);
        self::assertSame('20:00', $reducedRow['startTime']);

        // Falsification : une INCHANGÉE, une NOUVELLE et une équipe EN PAUSE ne sont jamais non replacées.
        self::assertArrayNotHasKey($teamUnchanged->getId(), $reasonByTeam);
        self::assertArrayNotHasKey($teamNew->getId(), $reasonByTeam);
        self::assertArrayNotHasKey($teamPaused->getId(), $reasonByTeam, 'une équipe désactivée n\'a aucun écart');
        self::assertCount(4, $body['unplaced'], 'exactement 4 séances non replacées');
    }

    /** 422 — un plan SEASON (le socle) n'a pas d'écart avec lui-même. */
    public function testSeasonPlanIsRefused(): void
    {
        [$user, $club, $season] = $this->seedClub('SEAS');
        $team = $this->team($club, $season, 'A');
        $vHealthy = $this->venue($club, $season, 'Sain');
        $socle = $this->socleVersion($club, $season);
        $this->slot($socle, $team, $vHealthy, 1, '18:00');
        $this->em->flush();
        $this->choosePlanVersion($socle);

        $this->request($user, $club, $season, $socle->getId());
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
    }

    /** 422 — une reprise de VACANCES n'a pas d'écart nommé (planning tout nouveau, ADR-0004). */
    public function testHolidayPeriodIsRefused(): void
    {
        [$user, $club, $season, $planId, $period] = $this->closureScaffold('HOL', CalendarEntryPeriodType::HOLIDAY, ScheduleStatus::COMPLETED, chooseSocle: true);
        unset($planId);

        $this->request($user, $club, $season, $period->getId());
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
    }

    /** 409 — une version non terminée n'a pas de placement stable à comparer. */
    public function testNonCompletedVersionIsRefused(): void
    {
        [$user, $club, $season, $planId, $period] = $this->closureScaffold('DRAFT', CalendarEntryPeriodType::CLOSURE, ScheduleStatus::DRAFT, chooseSocle: true);
        unset($planId);

        $this->request($user, $club, $season, $period->getId());
        self::assertSame(409, $this->client->getResponse()->getStatusCode());
    }

    /** 409 — socle non pointé (période DÉJÀ commencée, atteignable hors du moment de génération). */
    public function testUnchosenSocleIsRefused(): void
    {
        [$user, $club, $season, $planId, $period] = $this->closureScaffold('NOSOCLE', CalendarEntryPeriodType::CLOSURE, ScheduleStatus::COMPLETED, chooseSocle: false);
        unset($planId);

        $this->request($user, $club, $season, $period->getId());
        self::assertSame(409, $this->client->getResponse()->getStatusCode());
    }

    /**
     * Tenant — la version d'un AUTRE club est refusée : 404 quand la RLS la cache (prod),
     * 403 par la défense club en profondeur (harness de test). Jamais 200.
     */
    public function testOtherClubIsRefused(): void
    {
        [, $club, $season, $planId, $period] = $this->closureScaffold('TEN', CalendarEntryPeriodType::CLOSURE, ScheduleStatus::COMPLETED, chooseSocle: true);
        unset($planId, $club, $season);

        [$stranger, $otherClub, $otherSeason] = $this->seedClub('STRANGER');
        $this->request($stranger, $otherClub, $otherSeason, $period->getId());
        self::assertContains($this->client->getResponse()->getStatusCode(), [403, 404], 'version d\'un autre club → refusée');
    }

    /** La LECTURE est ouverte au Membre : pas de garde management sur une route de lecture. */
    public function testReadIsOpenToMember(): void
    {
        [, $club, $season, $planId, $period] = $this->closureScaffold('MEMBER', CalendarEntryPeriodType::CLOSURE, ScheduleStatus::COMPLETED, chooseSocle: true);
        unset($planId);

        $member = $this->member($club->getId(), 'editor');
        $body = $this->deviation($member, $club, $season, $period->getId());
        self::assertArrayHasKey('moved', $body);
        self::assertArrayHasKey('unplaced', $body);
        // Le bilan par rang se lit aussi depuis `/planning` (Membre compris) : mêmes données servies.
        self::assertArrayHasKey('teams', $body);
        self::assertArrayHasKey('ranks', $body);
        self::assertNotEmpty($body['ranks'], 'le Membre lit le bilan par rang, pas une coquille vide');
    }

    /**
     * Forme servie (D1) et comptes par ÉQUIPE (D3, v3 §B) : une seule source, les lignes
     * moved/unplaced. Chaque équipe du décor porte ses valeurs ATTENDUES explicites (kept = séances
     * appariées + déplacées), l'identité kept + lost + reducedByChoice = socle n'est vérifiée qu'À
     * CÔTÉ (seule, elle serait vraie par définition, v4 m4).
     */
    public function testEveryTeamLineCountsKeptLostAndChosenReductionsFromTheDeviationLines(): void
    {
        [$user, $club, $season] = $this->seedClub('TEAMS');
        $vHealthy = $this->venue($club, $season, 'Sain');
        $vAlt = $this->venue($club, $season, 'Autre');
        $vClosed = $this->venue($club, $season, 'Fermé mercredi');

        $unchanged = $this->team($club, $season, 'Unchanged');
        $moved = $this->team($club, $season, 'Moved');
        $movedSameDay = $this->team($club, $season, 'MovedSameDay');
        $new = $this->team($club, $season, 'New');
        $keptPlusNew = $this->team($club, $season, 'KeptPlusNew');
        $lostNull = $this->team($club, $season, 'LostNull');
        $reduced = $this->team($club, $season, 'Reduced');
        $closed = $this->team($club, $season, 'ClosedDay');
        $paused = $this->team($club, $season, 'Paused');

        $socle = $this->socleVersion($club, $season);
        $this->slot($socle, $unchanged, $vHealthy, 1, '18:00');
        $this->slot($socle, $moved, $vHealthy, 2, '18:30');
        $this->slot($socle, $movedSameDay, $vHealthy, 2, '18:00');
        $this->slot($socle, $keptPlusNew, $vAlt, 1, '18:00');
        $this->slot($socle, $lostNull, $vHealthy, 5, '20:00');
        $this->slot($socle, $reduced, $vHealthy, 1, '18:00');
        $this->slot($socle, $reduced, $vHealthy, 5, '20:00');
        $this->slot($socle, $closed, $vClosed, 3, '17:30');
        $this->slot($socle, $paused, $vHealthy, 1, '16:00');
        $this->em->flush();
        $this->choosePlanVersion($socle);

        $entry = $this->period($club, $season, CalendarEntryPeriodType::CLOSURE);
        $planId = $this->planIdOf($entry);
        $this->venueDayClosed($club, $season, $planId, $vClosed, 3);
        $this->teamOverride($club, $season, $planId, $reduced, true, 1);
        $this->teamOverride($club, $season, $planId, $paused, false, null);
        $this->em->flush();

        $period = $this->periodVersion($club, $season, $planId, ScheduleStatus::COMPLETED);
        $unchangedSlot = $this->slot($period, $unchanged, $vHealthy, 1, '18:00');
        $movedSlot = $this->slot($period, $moved, $vAlt, 4, '19:00');            // Mar → Jeu : jour changé
        $movedSameDaySlot = $this->slot($period, $movedSameDay, $vAlt, 2, '20:00'); // Mar → Mar : jour gardé
        $newSlot = $this->slot($period, $new, $vHealthy, 1, '17:00');            // absente du socle
        $this->slot($period, $keptPlusNew, $vAlt, 1, '18:00');                   // tenue
        $addedSlot = $this->slot($period, $keptPlusNew, $vAlt, 3, '18:00');      // nouvelle
        $reducedKeptSlot = $this->slot($period, $reduced, $vHealthy, 1, '18:00');
        $this->em->flush();

        $body = $this->deviation($user, $club, $season, $period->getId());

        // ── D1 : la forme servie, clés EXACTES (periodSessions et label NE sont PAS servis) ─────
        self::assertSame(['changedSlotIds', 'moved', 'ranks', 'socleScheduleId', 'teams', 'unplaced', 'window'], $this->sortedKeys($body));
        self::assertSame(['endDate' => '2025-10-26', 'startDate' => '2025-10-20'], $this->sortedAssoc($body['window']), 'la fenêtre servie est celle de la période');
        self::assertSame(
            ['changed', 'chosenSessions', 'daysKept', 'keptSessions', 'lost', 'movedSessions', 'newSessions', 'paused', 'reducedByChoice', 'sharedKept', 'sharedWith', 'socleSessions', 'teamId', 'tierLabel', 'tierOrder', 'tierWeight'],
            $this->sortedKeys($this->teamReport($body, $unchanged)),
        );

        // changedSlotIds = séances de PÉRIODE déplacées (moved[].to.slotId) OU nouvelles — la seule
        // source du filtre « Seulement les séances qui ont changé ». Ni tenue, ni conservée.
        $changed = $body['changedSlotIds'];
        sort($changed);
        $expected = [$movedSlot->getId(), $movedSameDaySlot->getId(), $newSlot->getId(), $addedSlot->getId()];
        sort($expected);
        self::assertSame($expected, $changed);
        self::assertNotContains($unchangedSlot->getId(), $body['changedSlotIds'], 'une séance tenue n\'a pas changé');
        self::assertNotContains($reducedKeptSlot->getId(), $body['changedSlotIds'], 'la séance conservée d\'une équipe réduite n\'a pas changé');

        // ── D3 / v3 §B : valeurs attendues explicites, équipe par équipe ───────────────────────
        $this->assertTeamCounts($body, $unchanged, socle: 1, kept: 1, lost: 0, reduced: 0, moved: 0, new: 0, daysKept: true, changed: false);
        $this->assertTeamCounts($body, $moved, socle: 1, kept: 1, lost: 0, reduced: 0, moved: 1, new: 0, daysKept: false, changed: true);
        $this->assertTeamCounts($body, $movedSameDay, socle: 1, kept: 1, lost: 0, reduced: 0, moved: 1, new: 0, daysKept: true, changed: true);
        $this->assertTeamCounts($body, $new, socle: 0, kept: 0, lost: 0, reduced: 0, moved: 0, new: 1, daysKept: null, changed: true);
        // m1 : la séance NOUVELLE n'entre pas dans « jour gardé » (U9 : jour gardé, 1 sur 1, 1 nouvelle).
        $this->assertTeamCounts($body, $keptPlusNew, socle: 1, kept: 1, lost: 0, reduced: 0, moved: 0, new: 1, daysKept: true, changed: true);
        $this->assertTeamCounts($body, $lostNull, socle: 1, kept: 0, lost: 1, reduced: 0, moved: 0, new: 0, daysKept: null, changed: true);
        $this->assertTeamCounts($body, $reduced, socle: 2, kept: 1, lost: 0, reduced: 1, moved: 0, new: 0, daysKept: true, changed: true);
        $this->assertTeamCounts($body, $closed, socle: 1, kept: 0, lost: 1, reduced: 0, moved: 0, new: 0, daysKept: null, changed: true);

        self::assertSame(1, $this->teamReport($body, $reduced)['chosenSessions'], 'la réduction choisie est servie (texte seulement)');
        self::assertNull($this->teamReport($body, $unchanged)['chosenSessions'], 'sans override, aucune réduction choisie');

        // La pause est lue AVANT l'exclusion : l'équipe est dans le bilan, en pause, jamais « changée ».
        $pausedReport = $this->teamReport($body, $paused);
        self::assertTrue($pausedReport['paused']);
        self::assertFalse($pausedReport['changed'], 'une équipe en pause n\'est pas une équipe touchée');
        self::assertNull($pausedReport['daysKept']);

        // Le rang est servi sur chaque ligne d'équipe (tierLabel / tierWeight / tierOrder du Team).
        $tier = $this->em->getRepository(PriorityTier::class)->find(1);
        self::assertInstanceOf(PriorityTier::class, $tier);
        self::assertSame($tier->getLabel(), $this->teamReport($body, $moved)['tierLabel']);
        self::assertSame($tier->getOrToolsWeight(), $this->teamReport($body, $moved)['tierWeight']);
        self::assertSame(0, $this->teamReport($body, $moved)['tierOrder']);

        // ── D5 / v3 §B : le rang somme HORS pauses, l'identité tient, `new` hors du « sur » ─────
        self::assertCount(1, $body['ranks'], 'toutes les équipes du décor sont du même rang');
        $rank = $body['ranks'][0];
        self::assertSame(['chosenReductions', 'keptSessions', 'lost', 'newSessions', 'paused', 'socleSessions', 'teams', 'tierLabel', 'tierWeight', 'unchanged'], $this->sortedKeys($rank));
        self::assertSame($tier->getLabel(), $rank['tierLabel']);
        self::assertSame(9, $rank['teams'], 'les 9 équipes ont au moins une séance (pause comprise)');
        self::assertSame(8, $rank['socleSessions'], 'Σ socle hors pause');
        self::assertSame(5, $rank['keptSessions'], 'Σ tenues hors pause (appariées + déplacées)');
        self::assertSame(2, $rank['lost'], 'Σ perdues (null + venue_closed), jamais la réduction choisie');
        self::assertSame(1, $rank['chosenReductions'], 'Σ séances retirées par choix');
        self::assertSame(2, $rank['newSessions'], 'Σ nouvelles, hors du « sur »');
        self::assertSame(1, $rank['paused']);
        self::assertSame(1, $rank['unchanged'], 'seule Unchanged est sans changement (la pause n\'y compte pas)');
        self::assertSame($rank['socleSessions'], $rank['keptSessions'] + $rank['lost'] + $rank['chosenReductions'], 'identité du rang : « 14 sur 13 » impossible');
    }

    /**
     * Majeur 1 (v3) — une réduction CHOISIE sans autre écart est un changement : l'équipe est
     * touchée, sa séance retirée n'est pas « perdue ».
     */
    public function testAChosenReductionAloneIsAChange(): void
    {
        [$user, $club, $season] = $this->seedClub('CHOSEN');
        $vHealthy = $this->venue($club, $season, 'Sain');
        $team = $this->team($club, $season, 'U13M');

        $socle = $this->socleVersion($club, $season);
        $this->slot($socle, $team, $vHealthy, 1, '18:00');
        $this->slot($socle, $team, $vHealthy, 3, '18:00');
        $this->slot($socle, $team, $vHealthy, 5, '20:00');
        $this->em->flush();
        $this->choosePlanVersion($socle);

        $entry = $this->period($club, $season, CalendarEntryPeriodType::CLOSURE);
        $planId = $this->planIdOf($entry);
        $this->teamOverride($club, $season, $planId, $team, true, 2);
        $this->em->flush();

        $period = $this->periodVersion($club, $season, $planId, ScheduleStatus::COMPLETED);
        $this->slot($period, $team, $vHealthy, 1, '18:00');
        $this->slot($period, $team, $vHealthy, 3, '18:00');
        $this->em->flush();

        $body = $this->deviation($user, $club, $season, $period->getId());

        $this->assertTeamCounts($body, $team, socle: 3, kept: 2, lost: 0, reduced: 1, moved: 0, new: 0, daysKept: true, changed: true);
        self::assertSame(2, $this->teamReport($body, $team)['chosenSessions']);
        self::assertCount(1, $body['unplaced']);
        self::assertSame(PeriodPlanTranscriber::SKIP_TEAM_REDUCED, $body['unplaced'][0]['reason'], 'la ligne de détail est « Séances réduites »');
        self::assertSame(1, $body['ranks'][0]['chosenReductions']);
        self::assertSame(0, $body['ranks'][0]['lost']);
        self::assertSame(0, $body['ranks'][0]['unchanged'], 'l\'équipe réduite est touchée, pas « sans changement »');
    }

    /**
     * Q4 (v3) — « perdue » se lit sur les LIGNES, jamais sur l'override : réduction choisie à 2 +
     * mercredi fermé, le moteur garde Lun et Ven → 1 perdue (Mer, venue_closed), 0 retirée par choix.
     */
    public function testLostIsDerivedFromTheUnplacedLinesNotFromTheOverride(): void
    {
        [$user, $club, $season] = $this->seedClub('LOSTLINES');
        $vHealthy = $this->venue($club, $season, 'Sain');
        $vClosed = $this->venue($club, $season, 'Fermé mercredi');
        $team = $this->team($club, $season, 'U13M');

        $socle = $this->socleVersion($club, $season);
        $this->slot($socle, $team, $vHealthy, 1, '18:00');
        $this->slot($socle, $team, $vClosed, 3, '18:00');
        $this->slot($socle, $team, $vHealthy, 5, '20:00');
        $this->em->flush();
        $this->choosePlanVersion($socle);

        $entry = $this->period($club, $season, CalendarEntryPeriodType::CLOSURE);
        $planId = $this->planIdOf($entry);
        $this->venueDayClosed($club, $season, $planId, $vClosed, 3);
        $this->teamOverride($club, $season, $planId, $team, true, 2);
        $this->em->flush();

        $period = $this->periodVersion($club, $season, $planId, ScheduleStatus::COMPLETED);
        $this->slot($period, $team, $vHealthy, 1, '18:00');
        $this->slot($period, $team, $vHealthy, 5, '20:00');
        $this->em->flush();

        $body = $this->deviation($user, $club, $season, $period->getId());

        self::assertCount(1, $body['unplaced'], 'une seule ligne non replacée');
        self::assertSame(3, $body['unplaced'][0]['dayOfWeek']);
        self::assertSame(PeriodPlanTranscriber::SKIP_VENUE_CLOSED, $body['unplaced'][0]['reason']);
        $this->assertTeamCounts($body, $team, socle: 3, kept: 2, lost: 1, reduced: 0, moved: 0, new: 0, daysKept: true, changed: true);
        self::assertSame(1, $body['ranks'][0]['lost']);
        self::assertSame(0, $body['ranks'][0]['chosenReductions'], 'la réduction choisie n\'est pas dite : la ligne dessous dit « Fermeture du gymnase »');
    }

    /**
     * D5/D6 (v3 §B, v4 m2 b / m5) — rangs triés poids DÉCROISSANT puis libellé croissant à poids égal ;
     * équipes d'un rang triées tierOrder puis nom ; une équipe sans séance d'aucun côté est absente ;
     * un rang tout en pause a ses sommes à zéro.
     */
    public function testRanksSortByWeightThenLabelAndTeamsByTierOrderThenName(): void
    {
        [$user, $club, $season] = $this->seedClub('RANKS');
        $vHealthy = $this->venue($club, $season, 'Sain');
        $top = $this->tier(9701, 'P', 80);
        $tierC = $this->tier(9702, 'C', 20);
        $tierB = $this->tier(9703, 'B', 20);

        $pausedTop = $this->tieredTeam($club, $season, 'SM1', $top, 0);
        $zeta = $this->tieredTeam($club, $season, 'Zeta', $tierC, 1);
        $yankee = $this->tieredTeam($club, $season, 'Yankee', $tierC, 0);
        $alpha = $this->tieredTeam($club, $season, 'Alpha', $tierC, 0);
        $ghost = $this->tieredTeam($club, $season, 'Ghost', $tierC, 0); // aucune séance, nulle part
        $bTeam = $this->tieredTeam($club, $season, 'U9', $tierB, 0);

        $socle = $this->socleVersion($club, $season);
        $this->slot($socle, $pausedTop, $vHealthy, 1, '20:00');
        $this->slot($socle, $zeta, $vHealthy, 2, '18:00');
        $this->slot($socle, $yankee, $vHealthy, 3, '18:00');
        $this->slot($socle, $alpha, $vHealthy, 4, '18:00');
        $this->slot($socle, $bTeam, $vHealthy, 5, '18:00');
        $this->em->flush();
        $this->choosePlanVersion($socle);

        $entry = $this->period($club, $season, CalendarEntryPeriodType::CLOSURE);
        $planId = $this->planIdOf($entry);
        $this->teamOverride($club, $season, $planId, $pausedTop, false, null);
        $this->em->flush();

        $period = $this->periodVersion($club, $season, $planId, ScheduleStatus::COMPLETED);
        $this->slot($period, $zeta, $vHealthy, 2, '18:00');
        $this->slot($period, $yankee, $vHealthy, 3, '18:00');
        $this->slot($period, $alpha, $vHealthy, 4, '18:00');
        $this->slot($period, $bTeam, $vHealthy, 5, '18:00');
        $this->em->flush();

        $body = $this->deviation($user, $club, $season, $period->getId());

        self::assertSame(['P', 'B', 'C'], array_map(static fn (array $r): string => $r['tierLabel'], $body['ranks']), 'poids décroissant, puis libellé croissant à poids égal');

        $rankP = $body['ranks'][0];
        self::assertSame(1, $rankP['teams']);
        self::assertSame(1, $rankP['paused']);
        self::assertSame(0, $rankP['socleSessions'], 'un rang tout en pause n\'additionne aucune séance');
        self::assertSame(0, $rankP['keptSessions']);
        self::assertSame(0, $rankP['unchanged']);

        $rankC = $body['ranks'][2];
        self::assertSame(3, $rankC['teams'], 'une équipe sans séance d\'aucun côté n\'est pas comptée');
        self::assertSame(3, $rankC['unchanged']);
        self::assertSame(80, $rankP['tierWeight']);
        self::assertSame(20, $rankC['tierWeight']);

        $teamIdsOfC = array_values(array_map(
            static fn (array $t): string => $t['teamId'],
            array_filter($body['teams'], static fn (array $t): bool => 'C' === $t['tierLabel']),
        ));
        self::assertSame([$alpha->getId(), $yankee->getId(), $zeta->getId()], $teamIdsOfC, 'tierOrder croissant, puis nom');
        self::assertNotContains($ghost->getId(), array_map(static fn (array $t): string => $t['teamId'], $body['teams']));
    }

    /**
     * v5 §1-2 — une séance est COMMUNE quand une autre séance de la même version tient la même case
     * ET que les deux équipes sont dans un bloc DÉCLARÉ du périmètre. Bloc partiel : U11A a 3 séances
     * dont 1 commune avec U11B → sharedKept 1. Une séance commune NOUVELLE ne compte pas.
     */
    public function testSharedSessionsNameTheirPartnersFromDeclaredGroups(): void
    {
        [$user, $club, $season] = $this->seedClub('SHARED');
        $vHealthy = $this->venue($club, $season, 'Sain');
        $a = $this->team($club, $season, 'U11A');
        $b = $this->team($club, $season, 'U11B');
        $this->block($club, $season, null, [$a, $b]); // déclaré au socle → copié à la naissance du plan

        $socle = $this->socleVersion($club, $season);
        $this->slot($socle, $a, $vHealthy, 1, '18:00');
        $this->slot($socle, $b, $vHealthy, 1, '18:00');
        $this->slot($socle, $a, $vHealthy, 3, '18:00');
        $this->slot($socle, $a, $vHealthy, 5, '20:00');
        $this->em->flush();
        $this->choosePlanVersion($socle);

        $planId = $this->planIdOf($this->period($club, $season, CalendarEntryPeriodType::CLOSURE));
        self::assertCount(2, $this->em->getRepository(SharedTrainingBlockTeam::class)->findBy(['clubId' => $club->getId(), 'schedulePlanId' => $planId]), 'décor : le bloc du socle est copié dans le plan à sa naissance (périmètre période)');
        $period = $this->periodVersion($club, $season, $planId, ScheduleStatus::COMPLETED);
        $this->slot($period, $a, $vHealthy, 1, '18:00');
        $this->slot($period, $b, $vHealthy, 1, '18:00');
        $this->slot($period, $a, $vHealthy, 3, '18:00');
        $this->slot($period, $a, $vHealthy, 5, '20:00');
        $this->slot($period, $a, $vHealthy, 6, '10:00'); // nouvelle, commune…
        $this->slot($period, $b, $vHealthy, 6, '10:00'); // …et jamais comptée
        $this->em->flush();

        $body = $this->deviation($user, $club, $season, $period->getId());

        self::assertSame(1, $this->teamReport($body, $a)['sharedKept'], 'bloc partiel : 1 commune sur 3 tenues');
        self::assertSame([$b->getId()], $this->teamReport($body, $a)['sharedWith'], 'le partenaire est servi par son id');
        self::assertSame(1, $this->teamReport($body, $b)['sharedKept']);
        self::assertSame([$a->getId()], $this->teamReport($body, $b)['sharedWith']);
        self::assertSame(1, $this->teamReport($body, $a)['newSessions'], 'la séance commune nouvelle est une nouvelle, pas une commune tenue');
    }

    /** v5 §2 — une équipe dans {A,B} et {A,C} : A nomme ses DEUX partenaires, B et C seulement A. */
    public function testATeamInTwoBlocksNamesBothPartners(): void
    {
        [$user, $club, $season] = $this->seedClub('TWOBLOCKS');
        $vHealthy = $this->venue($club, $season, 'Sain');
        $vAlt = $this->venue($club, $season, 'Autre');
        $a = $this->team($club, $season, 'U11A');
        $b = $this->team($club, $season, 'U11B');
        $c = $this->team($club, $season, 'U11C');
        $this->block($club, $season, null, [$a, $b]);
        $this->block($club, $season, null, [$a, $c]);

        $socle = $this->socleVersion($club, $season);
        $this->slot($socle, $a, $vHealthy, 1, '18:00');
        $this->slot($socle, $b, $vHealthy, 1, '18:00');
        $this->slot($socle, $a, $vAlt, 3, '18:00');
        $this->slot($socle, $c, $vAlt, 3, '18:00');
        $this->em->flush();
        $this->choosePlanVersion($socle);

        $planId = $this->planIdOf($this->period($club, $season, CalendarEntryPeriodType::CLOSURE));
        $period = $this->periodVersion($club, $season, $planId, ScheduleStatus::COMPLETED);
        $this->slot($period, $a, $vHealthy, 1, '18:00');
        $this->slot($period, $b, $vHealthy, 1, '18:00');
        $this->slot($period, $a, $vAlt, 3, '18:00');
        $this->slot($period, $c, $vAlt, 3, '18:00');
        $this->em->flush();

        $body = $this->deviation($user, $club, $season, $period->getId());

        self::assertSame(2, $this->teamReport($body, $a)['sharedKept']);
        $partners = $this->teamReport($body, $a)['sharedWith'];
        sort($partners);
        $expected = [$b->getId(), $c->getId()];
        sort($expected);
        self::assertSame($expected, $partners, 'union des partenaires des séances communes tenues');
        self::assertSame([$a->getId()], $this->teamReport($body, $b)['sharedWith']);
        self::assertSame([$a->getId()], $this->teamReport($body, $c)['sharedWith']);
    }

    /** v5 §2 — déplacée ENSEMBLE : la ligne déplacée nomme le partenaire au départ ET à l'arrivée. */
    public function testASessionMovedTogetherNamesItsPartnerOnBothEnds(): void
    {
        [$user, $club, $season] = $this->seedClub('MOVEDTOG');
        $vHealthy = $this->venue($club, $season, 'Sain');
        $vAlt = $this->venue($club, $season, 'Autre');
        $a = $this->team($club, $season, 'U11A');
        $b = $this->team($club, $season, 'U11B');
        $this->block($club, $season, null, [$a, $b]);

        $socle = $this->socleVersion($club, $season);
        $this->slot($socle, $a, $vHealthy, 1, '18:00');
        $this->slot($socle, $b, $vHealthy, 1, '18:00');
        $this->em->flush();
        $this->choosePlanVersion($socle);

        $planId = $this->planIdOf($this->period($club, $season, CalendarEntryPeriodType::CLOSURE));
        $period = $this->periodVersion($club, $season, $planId, ScheduleStatus::COMPLETED);
        $this->slot($period, $a, $vAlt, 4, '19:00');
        $this->slot($period, $b, $vAlt, 4, '19:00');
        $this->em->flush();

        $body = $this->deviation($user, $club, $season, $period->getId());

        $movedA = $this->movedRow($body, $a);
        self::assertSame([$b->getId()], $movedA['from']['sharedWith'], 'au socle, la séance était commune avec U11B');
        self::assertSame([$b->getId()], $movedA['to']['sharedWith'], 'dans la période, elle l\'est toujours');
        self::assertSame([$a->getId()], $this->movedRow($body, $b)['to']['sharedWith']);
        self::assertSame(1, $this->teamReport($body, $a)['sharedKept'], 'une déplacée commune est une commune tenue');
    }

    /**
     * v6 majeur 2 — groupe rompu : la séance déplacée SEULE ne porte aucun partenaire à l'arrivée, et
     * AUCUNE mention « plus commune » n'est servie (l'appariement positionnel la rendrait fausse).
     * Une séance restée en place dont le partenaire est parti ne porte rien (v6 d).
     */
    public function testASessionMovedAloneCarriesNoPartnerOnItsNewCase(): void
    {
        [$user, $club, $season] = $this->seedClub('BROKEN');
        $vHealthy = $this->venue($club, $season, 'Sain');
        $vAlt = $this->venue($club, $season, 'Autre');
        $a = $this->team($club, $season, 'U11A');
        $b = $this->team($club, $season, 'U11B');
        $this->block($club, $season, null, [$a, $b]);

        $socle = $this->socleVersion($club, $season);
        $this->slot($socle, $a, $vHealthy, 1, '18:00');
        $this->slot($socle, $b, $vHealthy, 1, '18:00');
        $this->em->flush();
        $this->choosePlanVersion($socle);

        $planId = $this->planIdOf($this->period($club, $season, CalendarEntryPeriodType::CLOSURE));
        $period = $this->periodVersion($club, $season, $planId, ScheduleStatus::COMPLETED);
        $this->slot($period, $a, $vAlt, 4, '19:00');
        $this->slot($period, $b, $vHealthy, 1, '18:00');
        $this->em->flush();

        $body = $this->deviation($user, $club, $season, $period->getId());

        $movedA = $this->movedRow($body, $a);
        self::assertSame([$b->getId()], $movedA['from']['sharedWith']);
        self::assertSame([], $movedA['to']['sharedWith'], 'seule à l\'arrivée : aucun partenaire');
        self::assertArrayNotHasKey('noLongerSharedWith', $movedA);
        self::assertArrayNotHasKey('noLongerSharedWith', $movedA['to']);
        self::assertSame(0, $this->teamReport($body, $a)['sharedKept']);
        self::assertSame(0, $this->teamReport($body, $b)['sharedKept'], 'la séance restée en place ne porte rien');
        self::assertSame([], $this->teamReport($body, $b)['sharedWith']);
    }

    /** v5 §2 — une séance commune PERDUE nomme son partenaire (socle), comptée en perdue, pas en commune. */
    public function testALostSharedSessionNamesItsPartner(): void
    {
        [$user, $club, $season] = $this->seedClub('LOSTSHARED');
        $vHealthy = $this->venue($club, $season, 'Sain');
        $a = $this->team($club, $season, 'U11A');
        $b = $this->team($club, $season, 'U11B');
        $this->block($club, $season, null, [$a, $b]);

        $socle = $this->socleVersion($club, $season);
        $this->slot($socle, $a, $vHealthy, 5, '20:00');
        $this->slot($socle, $b, $vHealthy, 5, '20:00');
        $this->em->flush();
        $this->choosePlanVersion($socle);

        $planId = $this->planIdOf($this->period($club, $season, CalendarEntryPeriodType::CLOSURE));
        $period = $this->periodVersion($club, $season, $planId, ScheduleStatus::COMPLETED);
        $this->slot($period, $b, $vHealthy, 5, '20:00');
        $this->em->flush();

        $body = $this->deviation($user, $club, $season, $period->getId());

        $unplacedA = $this->unplacedRow($body, $a);
        self::assertSame([$b->getId()], $unplacedA['sharedWith']);
        self::assertSame(1, $this->teamReport($body, $a)['lost']);
        self::assertSame(0, $this->teamReport($body, $a)['sharedKept']);
    }

    /**
     * v6 majeur 3 (côté SOCLE) — les partenaires d'une séance du socle se lisent sur TOUTES ses
     * séances : une séance perdue commune avec une équipe EN PAUSE nomme cette équipe.
     */
    public function testALostSessionSharedWithAPausedTeamStillNamesIt(): void
    {
        [$user, $club, $season] = $this->seedClub('LOSTPAUSED');
        $vHealthy = $this->venue($club, $season, 'Sain');
        $a = $this->team($club, $season, 'U15M');
        $paused = $this->team($club, $season, 'U15F');
        $this->block($club, $season, null, [$a, $paused]);

        $socle = $this->socleVersion($club, $season);
        $this->slot($socle, $a, $vHealthy, 5, '20:00');
        $this->slot($socle, $paused, $vHealthy, 5, '20:00');
        $this->em->flush();
        $this->choosePlanVersion($socle);

        $planId = $this->planIdOf($this->period($club, $season, CalendarEntryPeriodType::CLOSURE));
        $this->teamOverride($club, $season, $planId, $paused, false, null);
        $this->em->flush();
        $period = $this->periodVersion($club, $season, $planId, ScheduleStatus::COMPLETED);
        $this->em->flush();

        $body = $this->deviation($user, $club, $season, $period->getId());

        self::assertSame([$paused->getId()], $this->unplacedRow($body, $a)['sharedWith']);
    }

    /**
     * v6 majeur 3 (côté PÉRIODE) — un partenaire EN PAUSE n'est jamais compté : « 1 commune avec
     * U15F » à côté de « U15F : en pause » serait faux. La séance tenue reste seule.
     */
    public function testAPausedPartnerLeavesTheKeptSessionAlone(): void
    {
        [$user, $club, $season] = $this->seedClub('PAUSEDPART');
        $vHealthy = $this->venue($club, $season, 'Sain');
        $a = $this->team($club, $season, 'U15M');
        $paused = $this->team($club, $season, 'U15F');
        $this->block($club, $season, null, [$a, $paused]);

        $socle = $this->socleVersion($club, $season);
        $this->slot($socle, $a, $vHealthy, 1, '18:00');
        $this->slot($socle, $paused, $vHealthy, 1, '18:00');
        $this->em->flush();
        $this->choosePlanVersion($socle);

        $planId = $this->planIdOf($this->period($club, $season, CalendarEntryPeriodType::CLOSURE));
        $this->teamOverride($club, $season, $planId, $paused, false, null);
        $this->em->flush();
        $period = $this->periodVersion($club, $season, $planId, ScheduleStatus::COMPLETED);
        $this->slot($period, $a, $vHealthy, 1, '18:00');
        $this->slot($period, $paused, $vHealthy, 1, '18:00'); // présente dans la version, mais en pause
        $this->em->flush();

        $body = $this->deviation($user, $club, $season, $period->getId());

        self::assertSame(0, $this->teamReport($body, $a)['sharedKept']);
        self::assertSame([], $this->teamReport($body, $a)['sharedWith']);
        self::assertFalse($this->teamReport($body, $a)['changed'], 'changed ne dépend pas de la mention commune');
        self::assertTrue($this->teamReport($body, $paused)['paused']);
    }

    /**
     * v5 §1 — deux équipes SANS bloc déclaré sur la même case (gymnase de capacité 2) ne sont
     * JAMAIS communes, ni tenues (période) ni perdues (socle).
     */
    public function testCoPlacedTeamsWithoutADeclaredBlockAreNotShared(): void
    {
        [$user, $club, $season] = $this->seedClub('NOBLOCK');
        $vHealthy = $this->venue($club, $season, 'Sain');
        $a = $this->team($club, $season, 'U11A');
        $b = $this->team($club, $season, 'U11B');

        $socle = $this->socleVersion($club, $season);
        $this->slot($socle, $a, $vHealthy, 1, '18:00');
        $this->slot($socle, $b, $vHealthy, 1, '18:00');
        $this->slot($socle, $a, $vHealthy, 5, '20:00');
        $this->slot($socle, $b, $vHealthy, 5, '20:00');
        $this->em->flush();
        $this->choosePlanVersion($socle);

        $planId = $this->planIdOf($this->period($club, $season, CalendarEntryPeriodType::CLOSURE));
        $period = $this->periodVersion($club, $season, $planId, ScheduleStatus::COMPLETED);
        $this->slot($period, $a, $vHealthy, 1, '18:00');
        $this->slot($period, $b, $vHealthy, 1, '18:00');
        $this->slot($period, $b, $vHealthy, 5, '20:00');
        $this->em->flush();

        $body = $this->deviation($user, $club, $season, $period->getId());

        self::assertSame(0, $this->teamReport($body, $a)['sharedKept']);
        self::assertSame([], $this->teamReport($body, $a)['sharedWith']);
        self::assertSame([], $this->unplacedRow($body, $a)['sharedWith'], 'co-localisée au socle sans bloc : pas commune');
    }

    /**
     * v5 §1 — le périmètre des blocs est EXPLICITE (club + saison + plan) : un bloc d'une AUTRE
     * saison ou d'un AUTRE plan de période ne rend aucune séance commune (jamais le findBy([]) de
     * PlanHoldingInputs).
     */
    public function testBlocksOfAnotherSeasonOrPlanNeverMakeASessionShared(): void
    {
        [$user, $club, $season] = $this->seedClub('SCOPE');
        $vHealthy = $this->venue($club, $season, 'Sain');
        $a = $this->team($club, $season, 'U11A');
        $b = $this->team($club, $season, 'U11B');

        $socle = $this->socleVersion($club, $season);
        $this->slot($socle, $a, $vHealthy, 1, '18:00');
        $this->slot($socle, $b, $vHealthy, 1, '18:00');
        $this->slot($socle, $a, $vHealthy, 5, '20:00');
        $this->slot($socle, $b, $vHealthy, 5, '20:00');
        $this->em->flush();
        $this->choosePlanVersion($socle);

        $planId = $this->planIdOf($this->period($club, $season, CalendarEntryPeriodType::CLOSURE));

        // (1) un bloc {A,B} d'une AUTRE saison du même club (portée socle de cette saison-là) ;
        $otherSeason = new Season;
        $otherSeason->setClubId($club->getId());
        $otherSeason->setName('2026-2027');
        $otherSeason->setStartDate(new DateTimeImmutable('2026-09-01'));
        $otherSeason->setEndDate(new DateTimeImmutable('2027-06-30'));
        $otherSeason->setStatus(SeasonStatus::DRAFT);
        $otherSeason->setTransitionData([]);
        $this->em->persist($otherSeason);
        $this->em->flush();
        $this->block($club, $otherSeason, null, [$a, $b]);
        // (2) un bloc {A,B} d'un AUTRE plan de période de la même saison.
        $otherEntry = $this->period($club, $season, CalendarEntryPeriodType::CLOSURE, '2025-11-03', '2025-11-09');
        $this->block($club, $season, $this->planIdOf($otherEntry), [$a, $b]);

        $period = $this->periodVersion($club, $season, $planId, ScheduleStatus::COMPLETED);
        $this->slot($period, $a, $vHealthy, 1, '18:00');
        $this->slot($period, $b, $vHealthy, 1, '18:00');
        $this->slot($period, $b, $vHealthy, 5, '20:00');
        $this->em->flush();

        $body = $this->deviation($user, $club, $season, $period->getId());

        self::assertSame(0, $this->teamReport($body, $a)['sharedKept'], 'bloc hors périmètre : pas commune');
        self::assertSame([], $this->teamReport($body, $a)['sharedWith']);
        self::assertSame([], $this->unplacedRow($body, $a)['sharedWith'], 'bloc hors périmètre côté socle : pas commune');
    }

    /**
     * v6 b — une séance SEULE sur sa case n'est commune avec personne, pas même avec sa propre
     * équipe : sameGroup est vrai d'une équipe avec elle-même (CoachDoubleBookingDetector.php:424),
     * seuls S' ≠ S et T' ≠ team(S) l'en protègent. Deux séances d'une même équipe sur la même case
     * sont IMPOSSIBLES : index unique `uniq_schedule_slot_template_case`, ScheduleSlotTemplate.php:17.
     */
    public function testASessionAloneOnItsCaseIsNotSharedWithItsOwnTeam(): void
    {
        [$user, $club, $season] = $this->seedClub('SELF');
        $vHealthy = $this->venue($club, $season, 'Sain');
        $a = $this->team($club, $season, 'U11A');
        $b = $this->team($club, $season, 'U11B');
        $this->block($club, $season, null, [$a, $b]);

        $socle = $this->socleVersion($club, $season);
        $this->slot($socle, $a, $vHealthy, 1, '18:00');
        $this->slot($socle, $a, $vHealthy, 5, '20:00');
        $this->slot($socle, $b, $vHealthy, 4, '18:00');
        $this->em->flush();
        $this->choosePlanVersion($socle);

        $planId = $this->planIdOf($this->period($club, $season, CalendarEntryPeriodType::CLOSURE));
        $period = $this->periodVersion($club, $season, $planId, ScheduleStatus::COMPLETED);
        $this->slot($period, $a, $vHealthy, 1, '18:00');
        $this->slot($period, $b, $vHealthy, 4, '18:00');
        $this->em->flush();

        $body = $this->deviation($user, $club, $season, $period->getId());

        self::assertSame(0, $this->teamReport($body, $a)['sharedKept'], 'seule sur sa case : pas commune');
        self::assertSame([], $this->teamReport($body, $a)['sharedWith'], 'jamais partenaire d\'elle-même');
        self::assertSame([], $this->unplacedRow($body, $a)['sharedWith'], 'la perdue seule au socle ne nomme personne');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->hasher = $container->get(UserPasswordHasherInterface::class);
        $this->jwt = $container->get(JWTTokenManagerInterface::class);
    }

    /**
     * Un club + socle pointé (optionnel) + une période d'un type donné + une version de plan de
     * période d'un statut donné. Le socle porte une séance simple (le comparatif n'est pas l'objet
     * des tests de refus).
     *
     * @return array{0: User, 1: Club, 2: Season, 3: string, 4: Schedule}
     */
    private function closureScaffold(string $tag, CalendarEntryPeriodType $type, ScheduleStatus $status, bool $chooseSocle): array
    {
        [$user, $club, $season] = $this->seedClub($tag);
        $vHealthy = $this->venue($club, $season, 'Sain');
        $team = $this->team($club, $season, 'A');
        $socle = $this->socleVersion($club, $season);
        $this->slot($socle, $team, $vHealthy, 1, '18:00');
        $this->em->flush();
        if ($chooseSocle) {
            $this->choosePlanVersion($socle);
        }

        $entry = $this->period($club, $season, $type);
        $planId = $this->planIdOf($entry);
        $period = $this->periodVersion($club, $season, $planId, $status);
        $this->slot($period, $team, $vHealthy, 1, '18:00');
        $this->em->flush();

        return [$user, $club, $season, $planId, $period];
    }

    /**
     * @return array{socleScheduleId: string, window: array<string, string>, moved: list<array<string, mixed>>, unplaced: list<array<string, mixed>>, changedSlotIds: list<string>, teams: list<array<string, mixed>>, ranks: list<array<string, mixed>>}
     */
    private function deviation(User $user, Club $club, Season $season, string $scheduleId): array
    {
        $this->request($user, $club, $season, $scheduleId);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());

        /* @var array{socleScheduleId: string, window: array<string, string>, moved: list<array<string, mixed>>, unplaced: list<array<string, mixed>>, changedSlotIds: list<string>, teams: list<array<string, mixed>>, ranks: list<array<string, mixed>>} */
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
    }

    private function request(User $user, Club $club, Season $season, string $scheduleId): void
    {
        $this->client->request('GET', '/api/schedules/' . $scheduleId . '/socle-deviation', [], [], $this->headers($user, $club, $season));
    }

    /**
     * @return array<string, string>
     */
    private function headers(User $user, Club $club, Season $season): array
    {
        return [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->jwt->create($user),
            'HTTP_X-Season-Id' => $season->getId(),
            'CONTENT_TYPE' => 'application/json',
        ];
    }

    private function socleVersion(Club $club, Season $season): Schedule
    {
        $planId = $this->seasonPlanIdOf($season);
        $schedule = (new Schedule)
            ->setClubId($club->getId())
            ->setSeasonId($season->getId())
            ->setSchedulePlanId($planId)
            ->setName('Socle')
            ->setStatus(ScheduleStatus::COMPLETED);
        $this->em->persist($schedule);
        self::getContainer()->get(SchedulePlanProvisioner::class)->linkSchedule($schedule);

        return $schedule;
    }

    private function periodVersion(Club $club, Season $season, string $planId, ScheduleStatus $status): Schedule
    {
        $schedule = (new Schedule)
            ->setClubId($club->getId())
            ->setSeasonId($season->getId())
            ->setSchedulePlanId($planId)
            ->setName('Adaptation')
            ->setStatus($status);
        $this->em->persist($schedule);
        self::getContainer()->get(SchedulePlanProvisioner::class)->linkSchedule($schedule);

        return $schedule;
    }

    private function slot(Schedule $schedule, Team $team, Venue $venue, int $day, string $start): ScheduleSlotTemplate
    {
        $slot = (new ScheduleSlotTemplate)
            ->setClubId($schedule->getClubId())
            ->setSeasonId($schedule->getSeasonId())
            ->setScheduleId($schedule->getId())
            ->setTeamId($team->getId())
            ->setVenueId($venue->getId())
            ->setCoachId(null)
            ->setDayOfWeek($day)
            ->setStartTime(new DateTimeImmutable($start))
            ->setDurationMinutes(90)
            ->setLockLevel(LockLevel::NONE);
        $this->em->persist($slot);

        return $slot;
    }

    private function period(Club $club, Season $season, CalendarEntryPeriodType $type, string $start = '2025-10-20', string $end = '2025-10-26'): CalendarEntry
    {
        // Semaine PLEINE (lundi → dimanche) : tous les jours de semaine sont dans la fenêtre.
        $entry = new CalendarEntry;
        $entry->setClubId($club->getId());
        $entry->setSeasonId($season->getId());
        $entry->setKind(CalendarEntryKind::PERIOD);
        $entry->setTitle('Période');
        $entry->setStartDate(new DateTimeImmutable($start));
        $entry->setEndDate(new DateTimeImmutable($end));
        $entry->setIsDisruptive(false);
        $entry->setPeriodType($type);
        $entry->setStatus(CalendarEntryStatus::ACTIVE);
        $this->em->persist($entry);
        $this->em->flush();

        return $entry;
    }

    private function venueDayClosed(Club $club, Season $season, string $planId, Venue $venue, int $day): void
    {
        $override = new VenuePeriodOverride;
        $override->setClubId($club->getId());
        $override->setSeasonId($season->getId());
        $override->setSchedulePlanId($planId);
        $override->setVenueId($venue->getId());
        $override->setMode(null);
        $override->setDayOverrides([$day => VenueDayState::CLOSED->value]);
        $this->em->persist($override);
    }

    private function venueDisabled(Club $club, Season $season, string $planId, Venue $venue): void
    {
        $override = new VenuePeriodOverride;
        $override->setClubId($club->getId());
        $override->setSeasonId($season->getId());
        $override->setSchedulePlanId($planId);
        $override->setVenueId($venue->getId());
        $override->setMode(VenuePeriodMode::DISABLED);
        $this->em->persist($override);
    }

    private function teamOverride(Club $club, Season $season, string $planId, Team $team, bool $active, ?int $sessionsPerWeek): void
    {
        $override = new TeamPeriodOverride;
        $override->setClubId($club->getId());
        $override->setSeasonId($season->getId());
        $override->setSchedulePlanId($planId);
        $override->setTeamId($team->getId());
        $override->setIsActive($active);
        $override->setSessionsPerWeek($sessionsPerWeek);
        $this->em->persist($override);
    }

    private function team(Club $club, Season $season, string $name): Team
    {
        $team = new Team;
        $team->setClubId($club->getId());
        $team->setSeasonId($season->getId());
        $team->setSportCategoryId($this->category->getId());
        $team->setPriorityTierId(1);
        $team->setName($name . '-' . uniqid());
        $team->setSessionsPerWeek(3);
        $team->setIsActive(true);
        $this->em->persist($team);
        $this->em->flush();

        return $team;
    }

    /** Une équipe d'un rang donné, à une position donnée dans ce rang (D6 : tri tierOrder puis nom). */
    private function tieredTeam(Club $club, Season $season, string $name, PriorityTier $tier, int $tierOrder): Team
    {
        $team = $this->team($club, $season, $name);
        $team->setPriorityTierId($tier->getId());
        $team->setTierOrder($tierOrder);
        $this->em->flush();

        return $team;
    }

    /**
     * Un rang DÉDIÉ (table globale, ids hors de ceux des autres tests) : trouvé s'il existe, créé
     * sinon — les assertions lisent label et poids sur l'entité, jamais sur une valeur supposée.
     */
    private function tier(int $id, string $label, int $weight): PriorityTier
    {
        $tier = $this->em->getRepository(PriorityTier::class)->find($id);
        if (!$tier instanceof PriorityTier) {
            $tier = new PriorityTier;
            $tier->setId($id);
            $tier->setLabel($label);
            $tier->setName('Rang ' . $label);
            $tier->setColor('#00AA00');
            $tier->setOrToolsWeight($weight);
            $tier->setDefaultMinSessions(1);
            $this->em->persist($tier);
            $this->em->flush();
        }
        self::assertSame($label, $tier->getLabel(), 'rang dédié réutilisé avec un autre libellé');
        self::assertSame($weight, $tier->getOrToolsWeight(), 'rang dédié réutilisé avec un autre poids');

        return $tier;
    }

    /**
     * Un bloc de mutualisation DÉCLARÉ : `$planId` NULL = bloc de saison (copié dans un plan de
     * FERMETURE à sa naissance — à déclarer AVANT `planIdOf`), non-null = bloc du plan de période.
     *
     * @param list<Team> $teams
     */
    private function block(Club $club, Season $season, ?string $planId, array $teams): void
    {
        $this->scopeGucToClub($club->getId());
        $block = (new SharedTrainingBlock)->setClubId($club->getId())->setSeasonId($season->getId())->setSchedulePlanId($planId)->setCommonSessions(1);
        $this->em->persist($block);
        foreach ($teams as $team) {
            $this->em->persist((new SharedTrainingBlockTeam)->setClubId($club->getId())->setSeasonId($season->getId())->setSchedulePlanId($planId)->setBlockId($block->getId())->setTeamId($team->getId()));
        }
        $this->em->flush();
    }

    /**
     * La ligne de bilan d'une équipe (teams[]) — échoue en la nommant si elle manque.
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function teamReport(array $body, Team $team): array
    {
        self::assertIsArray($body['teams'] ?? null, 'teams[] servi');
        foreach ($body['teams'] as $row) {
            if ($team->getId() === $row['teamId']) {
                return $row;
            }
        }
        self::fail(\sprintf('l\'équipe %s est absente de teams[]', $team->getName()));
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function movedRow(array $body, Team $team): array
    {
        foreach ($body['moved'] as $row) {
            if ($team->getId() === $row['teamId']) {
                return $row;
            }
        }
        self::fail(\sprintf('aucune séance déplacée pour %s', $team->getName()));
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function unplacedRow(array $body, Team $team): array
    {
        foreach ($body['unplaced'] as $row) {
            if ($team->getId() === $row['teamId']) {
                return $row;
            }
        }
        self::fail(\sprintf('aucune séance non replacée pour %s', $team->getName()));
    }

    /**
     * Les comptes d'une équipe, valeurs ATTENDUES explicites, puis l'identité à côté.
     *
     * @param array<string, mixed> $body
     */
    private function assertTeamCounts(array $body, Team $team, int $socle, int $kept, int $lost, int $reduced, int $moved, int $new, ?bool $daysKept, bool $changed): void
    {
        $row = $this->teamReport($body, $team);
        $name = $team->getName();
        self::assertFalse($row['paused'], $name . ' : pas en pause');
        self::assertSame($socle, $row['socleSessions'], $name . ' : socleSessions');
        self::assertSame($kept, $row['keptSessions'], $name . ' : keptSessions (appariées + déplacées)');
        self::assertSame($lost, $row['lost'], $name . ' : lost (lignes unplaced hors team_reduced)');
        self::assertSame($reduced, $row['reducedByChoice'], $name . ' : reducedByChoice (lignes team_reduced)');
        self::assertSame($moved, $row['movedSessions'], $name . ' : movedSessions');
        self::assertSame($new, $row['newSessions'], $name . ' : newSessions');
        self::assertSame($daysKept, $row['daysKept'], $name . ' : daysKept');
        self::assertSame($changed, $row['changed'], $name . ' : changed');
        self::assertSame($row['socleSessions'], $row['keptSessions'] + $row['lost'] + $row['reducedByChoice'], $name . ' : kept + lost + reducedByChoice = socle');
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return list<string>
     */
    private function sortedKeys(array $row): array
    {
        $keys = array_map(strval(...), array_keys($row));
        sort($keys);

        return $keys;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function sortedAssoc(array $row): array
    {
        ksort($row);

        return $row;
    }

    private function venue(Club $club, Season $season, string $name): Venue
    {
        $venue = new Venue;
        $venue->setClubId($club->getId());
        $venue->setSeasonId($season->getId());
        $venue->setName($name);
        $venue->setSource('manual');
        $venue->setCanSplit(false);
        $this->em->persist($venue);
        $this->em->flush();

        return $venue;
    }

    private function member(string $clubId, string $role): User
    {
        $uid = uniqid('', true);
        $user = new User;
        $user->setEmail('m-' . $uid . '@test.com');
        $user->setFirstName('Me');
        $user->setLastName('Mber');
        $user->setPasswordHash($this->hasher->hashPassword($user, 'pass'));
        $this->em->persist($user);
        $this->em->flush();

        $this->scopeGucToClub($clubId);
        $membership = new ClubUser;
        $membership->setClubId($clubId);
        $membership->setUserId($user->getId());
        $membership->setRole($role);
        $membership->setIsActive(true);
        $this->em->persist($membership);
        $this->em->flush();

        return $user;
    }

    /**
     * @return array{0: User, 1: Club, 2: Season}
     */
    private function seedClub(string $tag): array
    {
        $uid = uniqid('', true);

        $club = new Club;
        $club->setName('Club ' . $tag);
        $club->setSlug('dev-' . $tag . '-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('dev-' . $tag . '-' . $uid . '@test.com');
        $user->setFirstName('De');
        $user->setLastName('V');
        $user->setPasswordHash($this->hasher->hashPassword($user, 'pass'));
        $this->em->persist($user);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());

        $membership = new ClubUser;
        $membership->setClubId($club->getId());
        $membership->setUserId($user->getId());
        $membership->setRole('admin');
        $membership->setIsActive(true);
        $this->em->persist($membership);

        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName('2025-2026');
        $season->setStartDate(new DateTimeImmutable('2025-09-01'));
        $season->setEndDate(new DateTimeImmutable('2026-06-30'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $season->setTransitionData([]);
        $this->em->persist($season);

        $sport = new Sport;
        $sport->setName('Basketball');
        $sport->setSlug('dev-' . $uid);
        $sport->setIsActive(true);
        $this->em->persist($sport);
        $this->em->flush();

        $category = new SportCategory;
        $category->setClubId($club->getId());
        $category->setSportId($sport->getId());
        $category->setName('U11');
        $category->setIsCustom(false);
        $category->setSortOrder(0);
        $this->em->persist($category);

        $tier = $this->em->getRepository(PriorityTier::class)->find(1);
        if (!$tier instanceof PriorityTier) {
            $tier = new PriorityTier;
            $tier->setId(1);
            $tier->setLabel('S');
            $tier->setName('Senior');
            $tier->setColor('#FF0000');
            $tier->setOrToolsWeight(100);
            $tier->setDefaultMinSessions(2);
            $this->em->persist($tier);
        }
        $this->em->flush();
        $this->category = $category;

        $this->provisionSeasonPlan($season);

        return [$user, $club, $season];
    }
}
