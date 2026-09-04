<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Constraint;
use App\Entity\ImplicitRuleSetting;
use App\Entity\Schedule;
use App\Entity\SchedulePlan;
use App\Entity\Season;
use App\Entity\SharedTrainingBlock;
use App\Entity\SharedTrainingBlockTeam;
use App\Entity\User;
use App\Entity\Venue;
use App\Entity\VenueTrainingSlot;
use App\Enum\ConstraintFamily;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use App\Enum\ImplicitRuleIntensity;
use App\Enum\ImplicitRuleKey;
use App\Enum\SchedulePlanType;
use App\Enum\ScheduleStatus;
use App\Enum\SeasonStatus;
use App\Service\ScheduleConstraintBuilder;
use App\Service\SeasonResolver;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * NR — ADR-0002 (amendé 2026-07-24), axe *planning lifecycle* : LE PLAN NAÎT DU
 * GESTE D'ADAPTER.
 *
 * Décision fondateur (2026-07-24, durcit celle du 2026-07-17) : un plan naît
 * UNIQUEMENT d'un geste EXPLICITE d'adaptation — jamais de la simple existence
 * d'une période. Les gestes : POST /api/schedule_plans {calendarEntryId} (« Adapter »
 * un bloc ou une fermeture), et cocher une semaine au picker (l'entrée-SEMAINE naît
 * avec son plan — couvert par WeekChildEntryTest). Matérialiser une vacance ou
 * signaler une indisponibilité ne crée RIEN : l'entrée est un ancrage, le radar lit
 * l'impact par les contraintes datées, sans plan.
 *
 * Ce que ce test verrouille :
 *  1. créer une période closure/holiday ⇒ AUCUN plan (matérialiser ≠ adapter) ;
 *  2. le geste (POST /schedule_plans) ⇒ le plan existe AVANT toute génération,
 *     type suivant la période ; rejoué ⇒ toujours UN SEUL plan (idempotence) ;
 *  3. inv. 9 — cutoff/mutualisation : jamais de plan, le geste y répond 422 ;
 *  4. un PUT ne crée JAMAIS de plan (promotion comprise) — anti-résurrection ;
 *  5. l'identité d'une période à plan OU à semaines-enfants est GELÉE (422) ;
 *  6. une mère découpée ne reporte jamais de plan-bloc (422) tant que ses semaines
 *     existent — les supprimer rouvre le geste (symétrie fondateur) ;
 *  7. SEC-07 : le geste est réservé au management (403 sinon).
 */
#[Group('phase1')]
#[Group('integration')]
final class PeriodPlanBirthTest extends WebTestCase
{
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    public function testCreatingAHolidayPeriodDoesNotBirthAPlan(): void
    {
        [$user, $club] = $this->createClubWithSeason();

        $entryId = $this->postPeriod($user, 'holiday', 'Vacances de Toussaint');

        // Amendement 2026-07-24 : matérialiser la vacance = un ANCRAGE, pas une
        // adaptation. Aucun plan tant que le gestionnaire n'a pas cliqué Adapter.
        self::assertNull($this->planOf($club->getId(), $entryId), 'Matérialiser une période ne crée pas de plan.');
    }

    public function testAdaptGestureBirthsTheHolidayPlanBeforeAnyGeneration(): void
    {
        [$user, $club] = $this->createClubWithSeason();
        $entryId = $this->postPeriod($user, 'holiday', 'Vacances de Toussaint');

        $this->adaptPeriod($user, $entryId);

        // Le cœur : le plan est là alors qu'AUCUN schedule n'existe.
        $plan = $this->planOf($club->getId(), $entryId);
        self::assertInstanceOf(SchedulePlan::class, $plan, 'Le geste « Adapter » doit créer le plan de la période.');
        self::assertSame(SchedulePlanType::HOLIDAY, $plan->getType());
        self::assertFalse($plan->isTeamSelectionInitialized(), 'Un plan neuf n’est pas encore configuré (garde de seed).');
        self::assertSame(0, $this->scheduleCount($club->getId()), 'Aucune génération ne doit être nécessaire.');
    }

    /**
     * NR bien-être PAR PÉRIODE — inv. 5 : NAÎTRE = PORTER SES 4 LIGNES COPIÉES.
     *
     * À la naissance du plan, les 4 règles bien-être sont MATÉRIALISÉES (copie totale, jamais
     * sparse) : la valeur de la portée SAISON s'il y a une déviation, sinon le défaut. Copie
     * totale = un plan « tout au défaut » garde ses 4 lignes, donc reste distinguable d'un plan
     * legacy sans copie (dont la lecture retombe sur la saison).
     */
    public function testTheAdaptGestureCopiesTheFourWellbeingRulesOntoThePlan(): void
    {
        [$user, $club, $season] = $this->createClubWithSeason();

        // Une règle SAISON déviée du défaut AVANT le geste : la copie doit la refléter.
        $this->scopeGucToClub($club->getId());
        $seasonSetting = (new ImplicitRuleSetting)
            ->setClubId($club->getId())
            ->setSeasonId($season->getId())
            ->setRuleKey(ImplicitRuleKey::COACH_REST_DAY)
            ->setIntensity(ImplicitRuleIntensity::PREFERRED)
            ->setParams(['minRestDays' => 3]);
        $this->em->persist($seasonSetting);
        $this->em->flush();

        $entryId = $this->postPeriod($user, 'holiday', 'Vacances');
        $planId = $this->adaptPeriod($user, $entryId);

        $this->scopeGucToClub($club->getId());
        $this->em->clear();
        $copies = $this->em->getRepository(ImplicitRuleSetting::class)->findBy(['schedulePlanId' => $planId]);
        // P2-42 — CINQ règles depuis l'arrivée de `maxConsecutiveDays`. La copie reste TOTALE
        // (toutes les clés, pas seulement celles que le club a réglées) : c'est ce qui rend la
        // période indépendante de la saison. Ce qui change, c'est l'intensité de naissance —
        // la règle opt-in naît OFF, vérifié juste en dessous : sans quoi une règle que le club
        // n'a jamais demandée s'imposerait à toutes ses périodes.
        self::assertCount(5, $copies, 'un plan naît avec SA copie des 5 règles bien-être (copie totale)');

        $byKey = [];
        foreach ($copies as $copy) {
            $byKey[$copy->getRuleKey()->value] = $copy;
        }
        $keys = array_keys($byKey);
        sort($keys);
        self::assertSame(['ageAscending', 'coachRestDay', 'maxConsecutiveDays', 'maxConsecutiveSessions', 'salarieDistribution'], $keys);

        // ⚑ P2-42 — la règle OPT-IN naît OFF, jamais HARD. Le matérialiseur posait un seul
        // défaut pour toutes les clés : une règle que le club n'avait pas demandée en saison
        // s'imposait alors à chacune de ses périodes. Falsification : rendre le défaut unique
        // (HARD pour tout le monde) rougit ici.
        self::assertSame(
            ImplicitRuleIntensity::OFF,
            $byKey['maxConsecutiveDays']->getIntensity(),
            'une règle opt-in non réglée en saison ne s\'allume pas toute seule sur une période',
        );

        // La déviation de saison est COPIÉE ; une règle au défaut copie HARD / params null.
        self::assertSame(ImplicitRuleIntensity::PREFERRED, $byKey['coachRestDay']->getIntensity());
        self::assertSame(['minRestDays' => 3], $byKey['coachRestDay']->getParams());
        self::assertSame(ImplicitRuleIntensity::HARD, $byKey['ageAscending']->getIntensity());
        self::assertNull($byKey['ageAscending']->getParams());
    }

    public function testAdaptGestureBirthsAClosurePlan(): void
    {
        [$user, $club] = $this->createClubWithSeason();
        // Fenêtre lun→dim ALIGNÉE (deux semaines pleines = un seul segment « milieu ») : « d'un
        // bloc » reste permis sur une fermeture qui ne se décompose qu'en UN segment (fondateur 2026-09-05).
        $entryId = $this->postPeriodDated($user, 'closure', 'Gymnase en travaux', '2026-10-19', '2026-11-01');
        self::assertNull($this->planOf($club->getId(), $entryId), 'Signaler une indisponibilité ne crée pas de plan.');

        $this->adaptPeriod($user, $entryId);

        $plan = $this->planOf($club->getId(), $entryId);
        self::assertInstanceOf(SchedulePlan::class, $plan);
        self::assertSame(SchedulePlanType::CLOSURE, $plan->getType());
    }

    /**
     * NR (décision fondateur 2026-09-05), axe *planning lifecycle* : le geste « d'un bloc » (plan
     * sur la racine) n'est permis pour une FERMETURE que si sa fenêtre se décompose en UN SEUL
     * segment. Une fermeture qui a une semaine ENTAMÉE (ici lun 19/10 → lun 02/11 : deux semaines
     * pleines + une entame de queue = milieu + fin) doit être adaptée par début·milieu·fin — le
     * bloc est refusé (422, message parlant, sans identifiant interne). Le cas « 1 segment accepté »
     * est gardé par {@see testAdaptGestureBirthsAClosurePlan}.
     */
    public function testAdaptDunBlocIsRefusedWhenAClosureHasAStartedWeek(): void
    {
        [$user, $club] = $this->createClubWithSeason();
        // lun 19/10 → lun 02/11 : le milieu (19/10→01/11) + une entame de queue (02/11) = 2 segments.
        $entryId = $this->postPeriodDated($user, 'closure', 'Gymnase en travaux prolongés', '2026-10-19', '2026-11-02');

        $this->adaptPeriodExpecting(422, $user, $entryId);
        self::assertStringContainsString('semaine entamée', (string) $this->client->getResponse()->getContent());
        self::assertNull($this->planOf($club->getId(), $entryId), 'aucun plan-bloc n’est né sur une fermeture à semaine entamée');
    }

    /**
     * NR — D10 affinée (décision fondateur 2026-09-02), axe *planning lifecycle* : un plan de
     * FERMETURE (CLOSURE) NAÎT avec une COPIE des blocs de mutualisation du socle. Le gestionnaire
     * qui adapte une fermeture doit VOIR ce que le club mutualise habituellement, hérité tel quel.
     *
     * Copie VERBATIM : mêmes équipes membres, mêmes séances communes, ids NOUVEAUX,
     * schedulePlanId = le plan. Falsification : ôter la garde CLOSURE de l'appelant OU ne pas
     * appeler la copie rougit ici (0 bloc au lieu de 2).
     */
    public function testAdaptGestureOnAClosureCopiesTheSocleSharedBlocks(): void
    {
        [$user, $club, $season] = $this->createClubWithSeason();

        $teamA = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        $teamB = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
        $teamC = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';
        $socleId = $this->createSocleBlock($club, $season, [$teamA, $teamB], 2);
        $this->createSocleBlock($club, $season, [$teamC], 1);

        // Fenêtre lun→dim ALIGNÉE (deux semaines pleines = un seul segment « milieu ») : « d'un
        // bloc » reste permis sur une fermeture qui ne se décompose qu'en UN segment (fondateur 2026-09-05).
        $entryId = $this->postPeriodDated($user, 'closure', 'Gymnase en travaux', '2026-10-19', '2026-11-01');
        $planId = $this->adaptPeriod($user, $entryId);

        $copies = $this->planBlocks($club->getId(), $planId);
        self::assertCount(2, $copies, 'une fermeture hérite des deux blocs de mutualisation du socle');

        $sessionsByTeams = [];
        foreach ($copies as $copy) {
            self::assertSame($planId, $copy->getSchedulePlanId(), 'la copie pend au plan de période');
            self::assertNotSame($socleId, $copy->getId(), 'la copie a un id NOUVEAU, jamais celui du bloc socle');
            $sessionsByTeams[implode(',', $this->blockTeamIds($copy->getId()))] = $copy->getCommonSessions();
        }
        // Mêmes équipes + mêmes séances communes que le socle, verbatim.
        self::assertArrayHasKey($teamA . ',' . $teamB, $sessionsByTeams, 'le bloc à 2 équipes est copié');
        self::assertSame(2, $sessionsByTeams[$teamA . ',' . $teamB], 'commonSessions copié verbatim (2)');
        self::assertArrayHasKey($teamC, $sessionsByTeams, 'le bloc à 1 équipe est copié');
        self::assertSame(1, $sessionsByTeams[$teamC], 'commonSessions copié verbatim (1)');

        // Les membres portent la dénormalisation club/saison/plan.
        $this->scopeGucToClub($club->getId());
        $memberCopies = $this->em->getRepository(SharedTrainingBlockTeam::class)->findBy(['schedulePlanId' => $planId]);
        self::assertCount(3, $memberCopies, 'chaque membre du socle est copié (2 + 1)');
        foreach ($memberCopies as $member) {
            self::assertSame($club->getId(), $member->getClubId());
            self::assertSame($season->getId(), $member->getSeasonId());
        }
    }

    /**
     * NR — D10 affinée : un plan de VACANCES (HOLIDAY) ne copie AUCUN bloc du socle (la mission
     * de la fermeture — retoucher la mutualisation d'un incident — ne vaut pas pour des vacances,
     * où toutes les équipes sont de toute façon en pause).
     */
    public function testAdaptGestureOnAHolidayCopiesNoSharedBlock(): void
    {
        [$user, $club, $season] = $this->createClubWithSeason();
        $this->createSocleBlock($club, $season, ['aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'], 1);

        $entryId = $this->postPeriod($user, 'holiday', 'Vacances');
        $planId = $this->adaptPeriod($user, $entryId);

        self::assertCount(0, $this->planBlocks($club->getId(), $planId), 'un plan de vacances ne copie AUCUN bloc du socle');
    }

    /**
     * NR — la copie est un INSTANTANÉ : elle se découple du socle dès la naissance, dans les deux
     * sens. Muter le socle après coup ne réécrit pas la copie ; muter la copie ne réécrit pas le
     * socle. (Patron « la période possède sa grille ».).
     */
    public function testTheClosureCopyIsASnapshotDecoupledFromTheSocle(): void
    {
        [$user, $club, $season] = $this->createClubWithSeason();
        $teamA = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        $teamB = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
        $socleId = $this->createSocleBlock($club, $season, [$teamA, $teamB], 2);

        // Fenêtre lun→dim ALIGNÉE (deux semaines pleines = un seul segment « milieu ») : « d'un
        // bloc » reste permis sur une fermeture qui ne se décompose qu'en UN segment (fondateur 2026-09-05).
        $entryId = $this->postPeriodDated($user, 'closure', 'Gymnase en travaux', '2026-10-19', '2026-11-01');
        $planId = $this->adaptPeriod($user, $entryId);

        $copies = $this->planBlocks($club->getId(), $planId);
        self::assertCount(1, $copies);
        $copyId = $copies[0]->getId();

        // (a) Muter le SOCLE après la naissance : commonSessions changé + un membre retiré.
        $this->scopeGucToClub($club->getId());
        $this->em->clear();
        $socle = $this->em->getRepository(SharedTrainingBlock::class)->find($socleId);
        self::assertInstanceOf(SharedTrainingBlock::class, $socle);
        $socle->setCommonSessions(9);
        foreach ($this->em->getRepository(SharedTrainingBlockTeam::class)->findBy(['blockId' => $socleId, 'teamId' => $teamB]) as $row) {
            $this->em->remove($row);
        }
        $this->em->flush();

        $copies = $this->planBlocks($club->getId(), $planId);
        self::assertCount(1, $copies, 'la copie survit à la mutation du socle');
        self::assertSame(2, $copies[0]->getCommonSessions(), 'la copie ignore le commonSessions changé du socle');
        self::assertSame([$teamA, $teamB], $this->blockTeamIds($copies[0]->getId()), 'la copie garde ses deux membres malgré le retrait côté socle');

        // (b) Muter la COPIE ne touche pas le socle.
        $this->scopeGucToClub($club->getId());
        $this->em->clear();
        $copy = $this->em->getRepository(SharedTrainingBlock::class)->find($copyId);
        self::assertInstanceOf(SharedTrainingBlock::class, $copy);
        $copy->setCommonSessions(4);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());
        $this->em->clear();
        $socleAfter = $this->em->getRepository(SharedTrainingBlock::class)->find($socleId);
        self::assertInstanceOf(SharedTrainingBlock::class, $socleAfter);
        self::assertSame(9, $socleAfter->getCommonSessions(), 'muter la copie ne réécrit pas le socle');
    }

    /**
     * NR — le geste rejoué (POST /schedule_plans deux fois) rend le MÊME plan (idempotence
     * d'ensurePeriodPlanId) : il ne re-copie donc pas les blocs. Étend le contrat d'idempotence
     * du plan à ses blocs hérités.
     */
    public function testTheClosureGestureReplayedDoesNotDuplicateTheCopiedBlocks(): void
    {
        [$user, $club, $season] = $this->createClubWithSeason();
        $this->createSocleBlock($club, $season, ['aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'], 1);

        // Fenêtre lun→dim ALIGNÉE (deux semaines pleines = un seul segment « milieu ») : « d'un
        // bloc » reste permis sur une fermeture qui ne se décompose qu'en UN segment (fondateur 2026-09-05).
        $entryId = $this->postPeriodDated($user, 'closure', 'Gymnase en travaux', '2026-10-19', '2026-11-01');
        $firstPlanId = $this->adaptPeriod($user, $entryId);
        $secondPlanId = $this->adaptPeriod($user, $entryId, 201);
        self::assertSame($firstPlanId, $secondPlanId, 'le geste rejoué rend le même plan');

        self::assertCount(1, $this->planBlocks($club->getId(), $firstPlanId), 'le geste rejoué ne re-copie pas les blocs hérités');
    }

    /**
     * NR — une semaine-enfant d'une mère CLOSURE hérite du type de sa mère et naît AVEC son plan :
     * elle hérite donc, elle aussi, les blocs du socle. Une semaine-enfant HOLIDAY, non.
     */
    public function testWeekChildInheritsSocleBlocksForClosureButNotForHoliday(): void
    {
        [$user, $club, $season] = $this->createClubWithSeason();
        $this->createSocleBlock($club, $season, ['aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'], 1);

        // Mère CLOSURE (non adaptée : le geste = cocher la semaine) → l'enfant est CLOSURE.
        $closureMother = $this->postPeriodDated($user, 'closure', 'Gymnase en travaux', '2026-10-19', '2026-11-08');
        // Le MILIEU ENTIER (fondateur 2026-09-05 : les semaines pleines d'une fermeture forment
        // un seul plan) — une semaine complète isolée serait désormais refusée.
        $closureChild = $this->postWeekChild($user, $closureMother, '2026-10-19', '2026-11-08', 'closure');
        $closureChildPlan = $this->planOf($club->getId(), $closureChild);
        self::assertInstanceOf(SchedulePlan::class, $closureChildPlan);
        self::assertSame(SchedulePlanType::CLOSURE, $closureChildPlan->getType());
        self::assertCount(1, $this->planBlocks($club->getId(), $closureChildPlan->getId()), 'la semaine-enfant d’une fermeture hérite les blocs du socle');

        // Mère HOLIDAY → l'enfant est HOLIDAY → aucune copie. Fenêtre disjointe de la précédente.
        $holidayMother = $this->postPeriodDated($user, 'holiday', 'Vacances de Noël', '2026-12-19', '2027-01-04');
        $holidayChild = $this->postWeekChild($user, $holidayMother, '2026-12-21', '2026-12-27');
        $holidayChildPlan = $this->planOf($club->getId(), $holidayChild);
        self::assertInstanceOf(SchedulePlan::class, $holidayChildPlan);
        self::assertCount(0, $this->planBlocks($club->getId(), $holidayChildPlan->getId()), 'la semaine-enfant de vacances ne copie aucun bloc');
    }

    /**
     * NR — supprimer la période emporte ses blocs copiés (cascade existante
     * OverlayManager::purgePlanAnchoredSettings) ; le socle survit.
     */
    public function testDeletingAClosurePeriodRemovesItsCopiedBlocks(): void
    {
        [$user, $club, $season] = $this->createClubWithSeason();
        $this->createSocleBlock($club, $season, ['aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb'], 1);

        // Fenêtre lun→dim ALIGNÉE (deux semaines pleines = un seul segment « milieu ») : « d'un
        // bloc » reste permis sur une fermeture qui ne se décompose qu'en UN segment (fondateur 2026-09-05).
        $entryId = $this->postPeriodDated($user, 'closure', 'Gymnase en travaux', '2026-10-19', '2026-11-01');
        $planId = $this->adaptPeriod($user, $entryId);
        self::assertCount(1, $this->planBlocks($club->getId(), $planId));

        $this->client->request('DELETE', '/api/calendar_entries/' . $entryId, [], [], $this->authHeaders($user));
        self::assertResponseStatusCodeSame(204);

        self::assertCount(0, $this->planBlocks($club->getId(), $planId), 'supprimer la période emporte ses blocs copiés');

        // Le socle, lui, est intact.
        $this->scopeGucToClub($club->getId());
        self::assertCount(
            1,
            $this->em->getRepository(SharedTrainingBlock::class)->findBy(['clubId' => $club->getId(), 'schedulePlanId' => null]),
            'le socle survit à la suppression de la période',
        );
    }

    /**
     * Invariant 9 — « Périodes sans plan » : cutoff/mutualisation restent des rappels
     * calendrier. Le geste Adapter y répond 422 : leur créer un plan donnerait un
     * espace de travail fantôme, jamais générable, dans le sélecteur.
     */
    public function testNonGeneratingPeriodsCarryNoPlanAndRefuseTheGesture(): void
    {
        [$user, $club] = $this->createClubWithSeason();

        foreach (['cutoff', 'mutualisation'] as $periodType) {
            $entryId = $this->postPeriod($user, $periodType, 'Rappel ' . $periodType);
            self::assertNull(
                $this->planOf($club->getId(), $entryId),
                \sprintf('Une période « %s » ne porte pas de plan (inv. 9).', $periodType),
            );
            $this->adaptPeriodExpecting(422, $user, $entryId);
        }
    }

    /**
     * Un PUT ne crée JAMAIS de plan (amendement 2026-07-24) : « promouvoir » un rappel
     * en période génératrice n'est pas un geste d'adaptation — et ce scénario n'existe
     * pas dans l'UI (ruling fondateur : « une coupure ne devient pas des vacances »).
     * Le plan naîtra du clic Adapter, la porte POST existe désormais pour ça.
     */
    public function testPromotingANonGeneratingPeriodDoesNotBirthAPlan(): void
    {
        [$user, $club] = $this->createClubWithSeason();
        $entryId = $this->postPeriod($user, 'cutoff', 'Rappel à promouvoir');
        self::assertNull($this->planOf($club->getId(), $entryId));

        $this->putPeriod($user, $entryId, ['periodType' => 'holiday', 'title' => 'Rappel à promouvoir']);

        self::assertNull($this->planOf($club->getId(), $entryId), 'Un PUT ne mint jamais de plan (anti-résurrection).');
    }

    /**
     * Un plan par période — garanti par uniq_schedule_plan_calendar_entry ET par
     * l'idempotence de provisionPeriodPlan : le geste rejoué rend le MÊME plan,
     * et un PUT ultérieur n'en fait pas naître un second.
     */
    public function testTheGestureReplayedDoesNotDuplicateThePlan(): void
    {
        [$user, $club] = $this->createClubWithSeason();
        $entryId = $this->postPeriod($user, 'holiday', 'Vacances');

        $firstPlanId = $this->adaptPeriod($user, $entryId);
        $secondPlanId = $this->adaptPeriod($user, $entryId, 201);
        self::assertSame($firstPlanId, $secondPlanId, 'Le geste rejoué rend le même plan.');

        $this->putPeriod($user, $entryId, ['periodType' => 'holiday', 'title' => 'Vacances renommées']);

        $this->scopeGucToClub($club->getId());
        $this->em->clear();
        $plans = $this->em->getRepository(SchedulePlan::class)->findBy(['calendarEntryId' => $entryId]);
        self::assertCount(1, $plans, 'Ni le geste rejoué ni un PUT ne dupliquent le plan.');
    }

    /**
     * NR — une mère DÉCOUPÉE ne reporte jamais de plan-bloc : 422 tant que des
     * semaines-enfants existent. État réversible, pas verrou définitif (symétrie
     * fondateur : on ne bascule jamais semaines↔bloc automatiquement — supprimer
     * toutes les semaines rouvre le geste bloc).
     */
    public function testAdaptGestureOnASplitMotherIsRefusedUntilChildrenAreDeleted(): void
    {
        [$user, $club] = $this->createClubWithSeason();
        $motherId = $this->postPeriod($user, 'holiday', 'Vacances découpées');
        $childId = $this->postWeekChild($user, $motherId, '2026-10-19', '2026-10-25');

        $this->adaptPeriodExpecting(422, $user, $motherId);
        self::assertNull($this->planOf($club->getId(), $motherId), 'Pas de plan-bloc sur une mère découpée.');

        // Symétrie : la semaine supprimée (cascade : son plan part avec), le geste
        // bloc redevient légitime.
        $this->client->request('DELETE', '/api/calendar_entries/' . $childId, [], [], $this->authHeaders($user));
        self::assertResponseStatusCodeSame(204);

        $this->adaptPeriod($user, $motherId);
        $plan = $this->planOf($club->getId(), $motherId);
        self::assertInstanceOf(SchedulePlan::class, $plan, 'Semaines supprimées → le geste bloc rouvre.');
        self::assertSame(SchedulePlanType::HOLIDAY, $plan->getType());
    }

    /**
     * NR anti-résurrection — un PUT sur une mère découpée (sans plan) : le titre reste
     * éditable, mais l'identité est gelée par ses SEMAINES et aucun plan ne re-naît.
     */
    public function testPutOnASplitMotherDoesNotResurrectAPlanAndFreezesIdentity(): void
    {
        [$user, $club] = $this->createClubWithSeason();
        $motherId = $this->postPeriod($user, 'holiday', 'Vacances découpées');
        $this->postWeekChild($user, $motherId, '2026-10-19', '2026-10-25');
        self::assertNull($this->planOf($club->getId(), $motherId));

        // Titre : libre — et toujours aucun plan après.
        $this->putPeriod($user, $motherId, ['periodType' => 'holiday', 'title' => 'Titre changé']);
        self::assertNull($this->planOf($club->getId(), $motherId), 'Un PUT ne re-mint pas le plan-bloc d’une mère découpée.');

        // Dates : gelées par les enfants (la couverture bougerait sous les semaines).
        $this->putPeriodExpecting(422, $user, $motherId, [
            'periodType' => 'holiday',
            'title' => 'Titre changé',
            'startDate' => '2027-02-15',
            'endDate' => '2027-02-22',
        ]);
    }

    /** SEC-07 — le geste Adapter est une écriture cockpit : membre non-management → 403. */
    public function testAdaptGestureRequiresManagementRole(): void
    {
        [$user, $club] = $this->createClubWithSeason();
        $entryId = $this->postPeriod($user, 'holiday', 'Vacances');

        $coach = $this->addMember($club, 'coach');
        $this->client->request('POST', '/api/schedule_plans', [], [], $this->authHeaders($coach) + [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['calendarEntryId' => $entryId], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(403);

        self::assertNull($this->planOf($club->getId(), $entryId));
    }

    /**
     * NR — une période qui porte un plan a une IDENTITÉ GELÉE : rétrograder est refusé
     * (422), jamais silencieusement destructeur.
     *
     * Le geste n'existe pas dans l'UI (le front n'expose que POST et DELETE sur les
     * périodes) ; cette garde protège le chemin API direct. Elle rend inatteignables les
     * deux défauts des rounds 1-2 : le plan détruit sous ses versions, et sa fenêtre
     * périmée. Corriger un type ou des dates = supprimer la période et la recréer, ce que
     * l'UI impose déjà.
     */
    public function testDemotingAPeriodThatHasAPlanIsRefused(): void
    {
        [$user, $club] = $this->createClubWithSeason();
        $entryId = $this->postPeriod($user, 'holiday', 'Vacances');
        $this->adaptPeriod($user, $entryId);
        $plan = $this->planOf($club->getId(), $entryId);
        self::assertInstanceOf(SchedulePlan::class, $plan);

        $this->putPeriodExpecting(422, $user, $entryId, ['periodType' => 'cutoff', 'title' => 'Vacances']);

        $survivor = $this->planOf($club->getId(), $entryId);
        self::assertInstanceOf(SchedulePlan::class, $survivor, 'le plan survit au refus');
        self::assertSame($plan->getId(), $survivor->getId());
    }

    /**
     * NR — corollaire : la fenêtre d'une période qui porte un plan est gelée elle aussi.
     * C'est ce qui rend impossible le « plan aux dates périmées » du round 1, sans aucune
     * machinerie de synchronisation.
     */
    public function testEditingTheDatesOfAPeriodThatHasAPlanIsRefused(): void
    {
        [$user, $club] = $this->createClubWithSeason();
        $entryId = $this->postPeriod($user, 'holiday', 'Vacances');
        $this->adaptPeriod($user, $entryId);

        $this->putPeriodExpecting(422, $user, $entryId, [
            'periodType' => 'holiday',
            'title' => 'Vacances',
            'startDate' => '2027-02-15',
            'endDate' => '2027-02-22',
        ]);

        $plan = $this->planOf($club->getId(), $entryId);
        self::assertInstanceOf(SchedulePlan::class, $plan);
        self::assertSame('2026-10-19', $plan->getStartDate()->format('Y-m-d'), 'la fenêtre du plan ne peut pas diverger : elle est gelée avec celle de sa période');
    }

    /**
     * NR — le NOM ne se synchronise PAS (inv. 12 : il appartient au plan, seul son
     * renommage l'écrit). Un second écrivain le rendrait non durable.
     */
    public function testRenamingThePeriodDoesNotOverwriteThePlanName(): void
    {
        [$user, $club] = $this->createClubWithSeason();
        $entryId = $this->postPeriod($user, 'holiday', 'Nom de naissance');
        $this->adaptPeriod($user, $entryId);

        // Le nom de NAISSANCE du plan est le TITRE de la période au moment du geste (décision
        // fondateur 2026-08-23) : il n'est plus « distinct du titre » par un gabarit serveur, mais
        // GELÉ à la naissance — on le capture, puis on prouve qu'un titre CHANGÉ ensuite ne le
        // réécrit pas (inv. 12).
        $bornPlan = $this->planOf($club->getId(), $entryId);
        self::assertInstanceOf(SchedulePlan::class, $bornPlan);
        $birthName = $bornPlan->getName();

        $this->putPeriod($user, $entryId, ['periodType' => 'holiday', 'title' => 'Titre changé']);

        $plan = $this->planOf($club->getId(), $entryId);
        self::assertInstanceOf(SchedulePlan::class, $plan);
        self::assertSame($birthName, $plan->getName(), 'le nom du plan ne suit pas le titre de la période (inv. 12)');
        self::assertStringNotContainsString('Titre changé', $plan->getName());
    }

    /**
     * NR — LE test qui manquait au round 1 : rétrograder une période qui porte une
     * VERSION doit être REFUSÉ (422), jamais détruire son plan.
     *
     * La version est volontairement PENDING et `overlayScheduleId` volontairement null :
     * c'est l'état exact que produit la suppression de la version active quand la seule
     * sœur restante n'est pas COMPLETED (OverlayManager ne promeut que du COMPLETED).
     * C'est par cette brèche que la garde d'identité, keyée sur `overlayScheduleId`,
     * laissait passer la rétrogradation — et que le plan généré partait en silence.
     */
    public function testDemotingAPeriodThatCarriesAVersionIsRefused(): void
    {
        [$user, $club, $season] = $this->createClubWithSeason();
        $entryId = $this->postPeriod($user, 'holiday', 'Vacances déjà générées');
        $this->adaptPeriod($user, $entryId);
        $plan = $this->planOf($club->getId(), $entryId);
        self::assertInstanceOf(SchedulePlan::class, $plan);
        $planId = $plan->getId();

        // Une version PENDING pend au plan, sans pointeur actif sur l'entrée.
        $this->scopeGucToClub($club->getId());
        $version = new Schedule;
        $version->setClubId($club->getId());
        $version->setSeasonId($season->getId());
        $version->setSchedulePlanId($planId);
        $version->setName('V1');
        $version->setStatus(ScheduleStatus::PENDING);
        $this->em->persist($version);
        $this->em->flush();

        $this->client->request('PUT', '/api/calendar_entries/' . $entryId, [], [], $this->authHeaders($user) + [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'kind' => 'period',
            'periodType' => 'cutoff',
            'title' => 'Vacances déjà générées',
            'startDate' => '2026-10-19',
            'endDate' => '2026-11-02',
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);

        $survivor = $this->planOf($club->getId(), $entryId);
        self::assertInstanceOf(SchedulePlan::class, $survivor, 'le plan qui porte une version ne doit pas être détruit');
        self::assertSame($planId, $survivor->getId());
    }

    /**
     * NR lot C2 — inv. 5 : LES RÉGLAGES PENDENT AU PLAN. Deux plans d'une même saison ne
     * voient jamais les réglages l'un de l'autre.
     *
     * Aujourd'hui la relation période↔plan est 1:1 (uniq_schedule_plan_calendar_entry), donc
     * ce test passerait aussi avec l'ancienne ancre — ce n'est PAS une redondance : il fixe
     * le contrat que le découpage hebdomadaire (types-de-planning E1) exigera, quand deux
     * plans partageront le MÊME déclencheur et que `calendarEntryId` ne saura plus les
     * distinguer. Il garde aussi le cloisonnement contre une régression du filtre.
     */
    public function testPeriodSettingsHangOffThePlanNotTheCalendarEntry(): void
    {
        [$user, $club] = $this->createClubWithSeason();
        $entryA = $this->postPeriod($user, 'holiday', 'Toussaint');
        // Fenêtres DISJOINTES depuis P2-38 PR2 : deux plans ne gouvernent jamais les mêmes
        // dates. Le sujet du test reste le cloisonnement des réglages PAR PLAN.
        $entryB = $this->postPeriodDated($user, 'closure', 'Gymnase en travaux', '2026-12-07', '2026-12-13');
        $this->adaptPeriod($user, $entryA);
        $this->adaptPeriod($user, $entryB);
        $planA = $this->planOf($club->getId(), $entryA);
        $planB = $this->planOf($club->getId(), $entryB);
        self::assertInstanceOf(SchedulePlan::class, $planA);
        self::assertInstanceOf(SchedulePlan::class, $planB);

        // teamId opaque : le sujet est le cloisonnement par plan, pas l'équipe (l'API ne
        // valide pas son existence — même parti pris que TeamPeriodOverrideApiTest).
        $teamId = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';
        $this->client->request('POST', '/api/team_period_overrides', [], [], $this->authHeaders($user) + [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'schedulePlanId' => $planA->getId(),
            'teamId' => $teamId,
            'isActive' => false,
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);

        // Le réglage revient par SON plan…
        self::assertCount(1, $this->overridesOf($user, $planA->getId()), 'le réglage se relit par le plan qui le porte');
        // …et reste invisible à l'autre.
        self::assertCount(0, $this->overridesOf($user, $planB->getId()), 'un plan ne voit jamais les réglages d’un autre');
    }

    /**
     * NR lot C3 — les CALQUES aussi pendent au plan, et leur nullité garde son sens.
     *
     * Ancre nullable : NULL = la structure PARTAGÉE (inv. 6 — créneau saisonnier,
     * réservation de base), non-NULL = propre à ce plan. C'est plus dangereux que les
     * jumeaux de C2 : une ancre mélangée ne casse rien, elle fait passer une ligne de
     * PÉRIODE pour une ligne de BASE — le socle hériterait d'un gymnase prêté pour une
     * semaine de vacances, et le planning serait plausible mais faux.
     */
    public function testPeriodLayersHangOffThePlanAndNullStillMeansShared(): void
    {
        [$user, $club, $season] = $this->createClubWithSeason();
        $entryId = $this->postPeriod($user, 'holiday', 'Vacances');
        $planId = $this->adaptPeriod($user, $entryId);

        $this->scopeGucToClub($club->getId());
        // Un vrai gymnase : le payload de base sérialise les créneaux PAR gymnase,
        // un venueId fantôme rendrait l'assertion vide (donc verte à tort).
        $venue = new Venue;
        $venue->setClubId($club->getId());
        $venue->setSeasonId($season->getId());
        $venue->setName('Gymnase du NR');
        $venue->setSource('manual');
        $this->em->persist($venue);
        $this->em->flush();
        $venueId = $venue->getId();

        // Un créneau SAISONNIER (ancre nulle) et un créneau PRÊTÉ à ce plan.
        foreach ([null, $planId] as $anchor) {
            $slot = new VenueTrainingSlot;
            $slot->setClubId($club->getId());
            $slot->setSeasonId($season->getId());
            $slot->setVenueId($venueId);
            $slot->setDayOfWeek(null === $anchor ? 1 : 2);
            $slot->setStartTime(new DateTimeImmutable('18:00'));
            $slot->setDurationMinutes(90);
            $slot->setCapacity(1);
            $slot->setSchedulePlanId($anchor);
            $this->em->persist($slot);
        }
        $this->em->flush();
        $this->em->clear();
        $this->scopeGucToClub($club->getId());

        // On sort par le CHEMIN DE PROD (P4-21) : relire les deux lignes via
        // l'EntityManager ne prouvait que le mapping Doctrine — `ScheduleConstraintBuilder`
        // pouvait cesser de filtrer sur l'ancre, le test restait vert alors que le
        // socle héritait d'un gymnase prêté pour une semaine de vacances.
        // Le payload de BASE est la vraie assertion : il ne voit que l'ancre nulle.
        $builder = self::getContainer()->get(ScheduleConstraintBuilder::class);
        self::assertInstanceOf(ScheduleConstraintBuilder::class, $builder);
        // `buildForClubSeason` sert d'abord le pool `cache.schedule` : les fixtures
        // ci-dessus sont écrites hors requête, donc sans invalidation. Sans ce purge,
        // un hit rendrait l'assertion creuse — verte quoi qu'il arrive. Ne pas
        // dépendre du fait que l'env de test mappe ce pool sur un adaptateur array.
        self::getContainer()->get('cache.schedule')->deleteItem(ScheduleConstraintBuilder::cacheKey($club->getId(), $season->getId()));
        $payload = $builder->buildForClubSeason($club->getId(), $season->getId());

        $slots = [];
        foreach ($payload['venues'] ?? [] as $venue) {
            if (($venue['id'] ?? null) === $venueId) {
                $slots = $venue['trainingSlots'] ?? [];
            }
        }
        $days = array_map(static fn (array $slot): int => (int) $slot['dayOfWeek'], $slots);
        sort($days);
        self::assertSame(
            [1],
            $days,
            'le payload de base ne doit porter QUE le créneau saisonnier (jour 1) : le créneau prêté au plan de période (jour 2) n’appartient pas au socle (inv. 6)',
        );
    }

    /**
     * NR lot C3 — les contraintes DATÉES, elles, NE bougent PAS : elles restent sur la
     * CalendarEntry, et le RADAR doit pouvoir les lire AVANT tout plan.
     *
     * RENFORCÉ par l'amendement 2026-07-24 : une closure vit désormais SANS plan tant
     * que personne ne clique Adapter — le radar n'a QUE la contrainte datée pour
     * annoncer « cette fermeture gêne 3 séances », et c'est ce qui déclenche le geste.
     *
     * On teste le COMPORTEMENT (la contrainte se relit par son entrée), pas la forme de la
     * classe : un method_exists ne dirait rien de ce qui compte.
     */
    public function testDatedConstraintsStayReadableByTheirCalendarEntry(): void
    {
        [$user, $club, $season] = $this->createClubWithSeason();
        $entryId = $this->postPeriod($user, 'closure', 'Barros fermé');
        // Volontairement AUCUN adaptPeriod : la lecture doit marcher sans plan.

        $this->scopeGucToClub($club->getId());
        $dated = new Constraint;
        $dated->setClubId($club->getId());
        $dated->setSeasonId($season->getId());
        $dated->setName('Barros fermé');
        $dated->setScope(ConstraintScope::FACILITY);
        $dated->setFamily(ConstraintFamily::FACILITY);
        $dated->setRuleType(ConstraintRuleType::HARD);
        $dated->setConfig([]);
        $dated->setCalendarEntryId($entryId); // le FAIT, pas la réponse
        $this->em->persist($dated);
        $this->em->flush();
        $this->em->clear();
        $this->scopeGucToClub($club->getId());

        // Le radar part du déclencheur, et il doit trouver — c'est ce qui déclenche le geste.
        self::assertCount(
            1,
            $this->em->getRepository(Constraint::class)->findBy(['calendarEntryId' => $entryId]),
            'la contrainte datée se relit par SON entrée : sans ça le radar ne peut plus annoncer l’impact d’une fermeture',
        );
    }

    /** Le geste « Adapter » : POST /api/schedule_plans — rend l'id du plan (201). */
    /**
     * NR P2-38 PR2 — UNE SEULE PLANIFICATION PAR FENÊTRE (règle fondateur 2026-08-18 :
     * « un overlay d'incident ne touche jamais une semaine de vacances »).
     *
     * Deux plans de période ne doivent jamais gouverner les mêmes dates : le second geste est
     * refusé en 409, en NOMMANT le plan déjà en place et en donnant l'entrée où aller. Rien
     * n'est supprimé ni rétréci — le geste destructif reste au gestionnaire.
     */
    public function testAdaptIsRefusedWhenAnotherPlanAlreadyGovernsTheWindow(): void
    {
        [$user] = $this->createClubWithSeason();
        $vacances = $this->postPeriodDated($user, 'holiday', 'Vacances de Toussaint', '2026-10-19', '2026-11-02');
        $this->adaptPeriod($user, $vacances);

        // Un incident qui MORD sur la fenêtre déjà planifiée (recouvrement PARTIEL, pas une
        // inclusion : la garde doit voir les deux).
        $incident = $this->postPeriodDated($user, 'closure', 'Gymnase indisponible', '2026-10-26', '2026-11-10');
        $this->adaptPeriodExpecting(409, $user, $incident);

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertSame('window_already_planned', $payload['code'] ?? null, 'le refus porte son code machine');
        self::assertSame($vacances, $payload['entryId'] ?? null, 'le front doit pouvoir NAVIGUER vers le planning en place');
        self::assertIsString($payload['error'] ?? null);
        self::assertStringContainsString('Vacances de Toussaint', $payload['error'], 'le message NOMME le planning existant');
    }

    /** Le refus vaut dans les DEUX sens : une semaine ne naît pas dans une fenêtre déjà adaptée. */
    public function testWeekChildIsRefusedInsideAWindowAlreadyPlannedByAnotherPeriod(): void
    {
        [$user] = $this->createClubWithSeason();
        // Fenêtre lun→dim ALIGNÉE (un seul segment) : « d'un bloc » permis (fondateur 2026-09-05) ;
        // elle recoupe la fenêtre testée pour déclencher le refus « une seule planification ».
        $incident = $this->postPeriodDated($user, 'closure', 'Gymnase indisponible', '2026-10-26', '2026-11-01');
        $this->adaptPeriod($user, $incident);

        $mere = $this->postPeriodDated($user, 'holiday', 'Vacances de Toussaint', '2026-10-19', '2026-11-02');
        $this->client->request('POST', '/api/calendar_entries', [], [], $this->authHeaders($user) + [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'kind' => 'period',
            'title' => 'Semaine du 26 octobre',
            'startDate' => '2026-10-26',
            'endDate' => '2026-11-01',
            'periodType' => 'holiday',
            'parentEntryId' => $mere,
        ], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(409);
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertSame('window_already_planned', $payload['code'] ?? null);
        self::assertSame($incident, $payload['entryId'] ?? null);
    }

    /**
     * TÉMOIN 1 — le chevauchement LÉGITIME : une semaine vit forcément DANS sa mère. Une garde
     * trop large casserait le découpage en semaines, qui existe et sert.
     */
    public function testAWeekInsideItsOwnMotherIsNeverRefused(): void
    {
        [$user, $club] = $this->createClubWithSeason();
        $mere = $this->postPeriodDated($user, 'holiday', 'Vacances de Toussaint', '2026-10-19', '2026-11-02');
        $this->adaptPeriod($user, $mere); // plan-bloc : la découpe le supprimera

        $semaine = $this->postWeekChild($user, $mere, '2026-10-19', '2026-10-25');

        self::assertInstanceOf(SchedulePlan::class, $this->planOf($club->getId(), $semaine), 'la semaine naît AVEC son plan');
        self::assertNull($this->planOf($club->getId(), $mere), 'la découpe emporte le plan-bloc de la mère');
    }

    /** TÉMOIN 2 — deux périodes qui ne se recoupent pas s'adaptent toutes les deux. */
    public function testTwoDisjointPeriodsBothGetTheirPlan(): void
    {
        [$user, $club] = $this->createClubWithSeason();
        $octobre = $this->postPeriodDated($user, 'holiday', 'Vacances de Toussaint', '2026-10-19', '2026-11-02');
        $decembre = $this->postPeriodDated($user, 'holiday', 'Vacances de Noël', '2026-12-19', '2027-01-04');

        $this->adaptPeriod($user, $octobre);
        $this->adaptPeriod($user, $decembre);

        self::assertInstanceOf(SchedulePlan::class, $this->planOf($club->getId(), $octobre));
        self::assertInstanceOf(SchedulePlan::class, $this->planOf($club->getId(), $decembre));
    }

    /**
     * TÉMOIN 3 — le FAIT reste libre. Déclarer une indisponibilité PAR-DESSUS une période déjà
     * planifiée doit passer : c'est le PLAN d'adaptation qu'on borne, jamais la vérité sur le
     * gymnase (et depuis P2-38 PR1, cette fermeture s'applique quand même au plan qui recoupe
     * ses dates).
     */
    public function testDeclaringAClosureOverAPlannedWindowStaysFree(): void
    {
        [$user, $club] = $this->createClubWithSeason();
        $vacances = $this->postPeriodDated($user, 'holiday', 'Vacances de Toussaint', '2026-10-19', '2026-11-02');
        $this->adaptPeriod($user, $vacances);

        $incident = $this->postPeriodDated($user, 'closure', 'Gymnase indisponible', '2026-10-26', '2026-11-10');

        self::assertNull($this->planOf($club->getId(), $incident), 'déclarer n’adapte pas — et surtout, déclarer n’est jamais refusé');
    }

    /**
     * NR — P2-41 SEGMENTS : un enfant dont la fenêtre couvre PLUSIEURS semaines
     * contiguës naît comme n'importe quelle semaine — un enfant, son plan, jamais un
     * nouveau concept. Ici 3 semaines pleines (lun→dim) : le plan naît sur le SEGMENT.
     */
    public function testAThreeWeekSegmentBirthsItsOwnPlanOnTheSegmentWindow(): void
    {
        [$user, $club] = $this->createClubWithSeason();
        $motherId = $this->postPeriodDated($user, 'holiday', 'Vacances longues', '2026-10-19', '2026-11-08');

        $segmentId = $this->postWeekChild($user, $motherId, '2026-10-19', '2026-11-08');

        $plan = $this->planOf($club->getId(), $segmentId);
        self::assertInstanceOf(SchedulePlan::class, $plan, 'le segment naît avec SON plan (rail 1 entrée = 1 plan)');
        self::assertSame(SchedulePlanType::HOLIDAY, $plan->getType());
        self::assertSame('2026-10-19', $plan->getStartDate()->format('Y-m-d'), 'la fenêtre du plan est le SEGMENT entier');
        self::assertSame('2026-11-08', $plan->getEndDate()->format('Y-m-d'));
    }

    /** NR — P2-41 : une semaine simple (segment de taille 1) reste acceptée — non-régression. */
    public function testASingleWeekSegmentStillBirthsItsPlan(): void
    {
        [$user, $club] = $this->createClubWithSeason();
        $motherId = $this->postPeriodDated($user, 'holiday', 'Vacances', '2026-10-19', '2026-11-02');

        $weekId = $this->postWeekChild($user, $motherId, '2026-10-19', '2026-10-25');

        self::assertInstanceOf(SchedulePlan::class, $this->planOf($club->getId(), $weekId), 'la semaine simple naît toujours avec son plan');
    }

    /**
     * NR — P2-41 : deux segments FRÈRES qui se chevauchent → 422. La garde
     * anti-chevauchement existante (par recouvrement de fenêtres) vaut pour des blocs,
     * pas seulement pour le même lundi.
     */
    public function testTwoOverlappingSegmentsOfTheSameMotherAreRefused(): void
    {
        [$user] = $this->createClubWithSeason();
        $motherId = $this->postPeriodDated($user, 'holiday', 'Vacances longues', '2026-10-19', '2026-11-08');

        $this->postWeekChild($user, $motherId, '2026-10-19', '2026-11-01'); // seg1 : 2 semaines
        // seg2 recoupe seg1 sur la semaine du 26 (les deux sont lun→dim).
        $this->postWeekChildExpecting(422, $user, $motherId, '2026-10-26', '2026-11-08');
    }

    /**
     * NR — P2-41 : un segment qui DÉBORDE les semaines couvrant la mère → 422. Borne
     * NOUVELLE (le plafond ≤7 j bornait les dégâts avant P2-41 ; sans elle, un segment
     * hériterait les datées de la mère hors de sa portée).
     */
    public function testASegmentOverflowingTheMotherWeeksIsRefused(): void
    {
        [$user] = $this->createClubWithSeason();
        // Mère de 3 semaines (envelope lun 19/10 → dim 08/11) ; le segment vise 4 semaines.
        $motherId = $this->postPeriodDated($user, 'holiday', 'Vacances longues', '2026-10-19', '2026-11-08');

        $this->postWeekChildExpecting(422, $user, $motherId, '2026-10-19', '2026-11-15');
    }

    /**
     * NR — P2-41 : le refus 409 window_already_planned vaut aussi pour un SEGMENT — sens
     * 1, un segment ne naît pas dans une fenêtre qu'un plan de période ÉTRANGER gouverne.
     */
    public function testASegmentIsRefusedInsideAWindowAlreadyPlannedByAnotherPeriod(): void
    {
        [$user] = $this->createClubWithSeason();
        // Fenêtre lun→dim ALIGNÉE (un seul segment) : « d'un bloc » permis (fondateur 2026-09-05) ;
        // elle recoupe la fenêtre testée pour déclencher le refus « une seule planification ».
        $incident = $this->postPeriodDated($user, 'closure', 'Gymnase indisponible', '2026-10-26', '2026-11-01');
        $this->adaptPeriod($user, $incident);

        $mere = $this->postPeriodDated($user, 'holiday', 'Vacances de Toussaint', '2026-10-19', '2026-11-08');
        // Segment de 3 semaines qui MORD sur la fenêtre déjà planifiée par l'incident.
        $this->client->request('POST', '/api/calendar_entries', [], [], $this->authHeaders($user) + [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'kind' => 'period',
            'title' => 'Segment 3 semaines',
            'startDate' => '2026-10-19',
            'endDate' => '2026-11-08',
            'periodType' => 'holiday',
            'parentEntryId' => $mere,
        ], \JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(409);
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertSame('window_already_planned', $payload['code'] ?? null);
        self::assertSame($incident, $payload['entryId'] ?? null);
    }

    /**
     * NR — P2-41 : sens 2 — une AUTRE période ne s'adapte pas par-dessus un segment déjà
     * en place. Le 409 nomme le segment et donne son entrée.
     */
    public function testAnotherPeriodAdaptIsRefusedWhenASegmentAlreadyGovernsTheWindow(): void
    {
        [$user, $club] = $this->createClubWithSeason();
        $mere = $this->postPeriodDated($user, 'holiday', 'Vacances de Toussaint', '2026-10-19', '2026-11-08');
        $segmentId = $this->postWeekChild($user, $mere, '2026-10-19', '2026-11-08');
        self::assertInstanceOf(SchedulePlan::class, $this->planOf($club->getId(), $segmentId), 'le segment gouverne sa fenêtre');

        $incident = $this->postPeriodDated($user, 'closure', 'Gymnase indisponible', '2026-10-26', '2026-11-10');
        $this->adaptPeriodExpecting(409, $user, $incident);

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertSame('window_already_planned', $payload['code'] ?? null);
        self::assertSame($segmentId, $payload['entryId'] ?? null, 'le refus pointe vers le segment déjà en place');
    }

    /**
     * NR — P2-41 : un segment dont une borne est CLAMPÉE à la saison (donc pas un
     * lundi/dimanche exact) reste valide — même règle que la semaine simple de bord.
     * Saison 08/01 → 15/07 ; la fin du segment tombe le dernier jour de saison (un jeudi).
     */
    public function testASegmentClampedToTheSeasonEdgeIsAccepted(): void
    {
        [$user, $club] = $this->createClubWithSeason();
        $motherId = $this->postPeriodDated($user, 'holiday', 'Fin de saison', '2027-07-05', '2027-07-15');

        // 05/07 lundi → 15/07 (dernier jour de saison, jeudi) : borne haute clampée.
        $segmentId = $this->postWeekChild($user, $motherId, '2027-07-05', '2027-07-15');

        $plan = $this->planOf($club->getId(), $segmentId);
        self::assertInstanceOf(SchedulePlan::class, $plan, 'un segment clampé au bord de saison naît normalement');
        self::assertSame('2027-07-15', $plan->getEndDate()->format('Y-m-d'), 'la fenêtre s’arrête au dernier jour de saison');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    private function adaptPeriod(User $user, string $entryId, int $expected = 201): string
    {
        $this->client->request('POST', '/api/schedule_plans', [], [], $this->authHeaders($user) + [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['calendarEntryId' => $entryId], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame($expected);

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertIsString($payload['id']);

        return $payload['id'];
    }

    private function adaptPeriodExpecting(int $status, User $user, string $entryId): void
    {
        $this->client->request('POST', '/api/schedule_plans', [], [], $this->authHeaders($user) + [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['calendarEntryId' => $entryId], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame($status);
    }

    /** POST d'une entrée-SEMAINE (P2-5 E1) — elle naît AVEC son plan (le geste = cocher). */
    private function postWeekChild(User $user, string $motherId, string $start, string $end, string $periodType = 'holiday'): string
    {
        $this->client->request('POST', '/api/calendar_entries', [], [], $this->authHeaders($user) + [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'kind' => 'period',
            'title' => 'Semaine du ' . $start,
            'startDate' => $start,
            'endDate' => $end,
            'periodType' => $periodType,
            'parentEntryId' => $motherId,
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertIsString($payload['id']);

        return $payload['id'];
    }

    /** POST d'un enfant-segment attendu en échec (422 chevauchement/débordement/bornes, ou 409 fenêtre). */
    private function postWeekChildExpecting(int $status, User $user, string $motherId, string $start, string $end): void
    {
        $this->client->request('POST', '/api/calendar_entries', [], [], $this->authHeaders($user) + [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'kind' => 'period',
            'title' => 'Segment ' . $start,
            'startDate' => $start,
            'endDate' => $end,
            'periodType' => 'holiday',
            'parentEntryId' => $motherId,
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame($status);
    }

    private function addMember(Club $club, string $role): User
    {
        $uid = uniqid('', true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $member = new User;
        $member->setEmail('member' . $uid . '@test.com');
        $member->setFirstName('Non');
        $member->setLastName('Manager');
        $member->setPasswordHash($hasher->hashPassword($member, 'pass'));
        $this->em->persist($member);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());
        $membership = new ClubUser;
        $membership->setClubId($club->getId());
        $membership->setUserId($member->getId());
        $membership->setRole($role);
        $membership->setIsActive(true);
        $this->em->persist($membership);
        $this->em->flush();

        return $member;
    }

    /** @return array<int, mixed> */
    private function overridesOf(User $user, string $schedulePlanId): array
    {
        $this->client->request('GET', '/api/team_period_overrides?schedulePlanId=' . $schedulePlanId, [], [], $this->authHeaders($user));
        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        $items = $payload['member'] ?? $payload['hydra:member'] ?? $payload;
        self::assertIsArray($items);

        return array_values($items);
    }

    /** Une période aux dates CHOISIES — la garde d'unicité de fenêtre se teste sur des bornes. */
    private function postPeriodDated(User $user, string $periodType, string $title, string $start, string $end): string
    {
        $this->client->request('POST', '/api/calendar_entries', [], [], $this->authHeaders($user) + [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'kind' => 'period',
            'title' => $title,
            'startDate' => $start,
            'endDate' => $end,
            'periodType' => $periodType,
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertIsString($payload['id']);

        return $payload['id'];
    }

    private function postPeriod(User $user, string $periodType, string $title): string
    {
        $this->client->request('POST', '/api/calendar_entries', [], [], $this->authHeaders($user) + [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'kind' => 'period',
            'title' => $title,
            'startDate' => '2026-10-19',
            'endDate' => '2026-11-02',
            'periodType' => $periodType,
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertIsString($payload['id']);

        return $payload['id'];
    }

    /** @param array<string, mixed> $changes */
    private function putPeriod(User $user, string $entryId, array $changes): void
    {
        $this->putPeriodExpecting(null, $user, $entryId, $changes);
    }

    /**
     * PUT = remplacement complet : kind/startDate/endDate sont NotBlank, on renvoie donc
     * l'enveloppe entière et `$changes` n'en surcharge que la partie utile (union `+` :
     * la GAUCHE gagne).
     *
     * @param array<string, mixed> $changes
     */
    private function putPeriodExpecting(?int $status, User $user, string $entryId, array $changes): void
    {
        $this->client->request('PUT', '/api/calendar_entries/' . $entryId, [], [], $this->authHeaders($user) + [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode($changes + [
            'kind' => 'period',
            'startDate' => '2026-10-19',
            'endDate' => '2026-11-02',
        ], \JSON_THROW_ON_ERROR));

        if (null === $status) {
            self::assertResponseIsSuccessful();

            return;
        }
        self::assertResponseStatusCodeSame($status);
    }

    /**
     * Pose un bloc de mutualisation SOCLE (schedulePlanId NULL) pour le club/saison, avec ses
     * équipes membres. teamIds opaques : le sujet est la COPIE, pas l'existence de l'équipe.
     *
     * @param list<string> $teamIds
     *
     * @return string l'id du bloc socle
     */
    private function createSocleBlock(Club $club, Season $season, array $teamIds, int $commonSessions = 1): string
    {
        $this->scopeGucToClub($club->getId());
        $block = (new SharedTrainingBlock)
            ->setClubId($club->getId())
            ->setSeasonId($season->getId())
            ->setSchedulePlanId(null)
            ->setCommonSessions($commonSessions);
        $this->em->persist($block);
        foreach ($teamIds as $teamId) {
            $member = (new SharedTrainingBlockTeam)
                ->setClubId($club->getId())
                ->setSeasonId($season->getId())
                ->setSchedulePlanId(null)
                ->setBlockId($block->getId())
                ->setTeamId($teamId);
            $this->em->persist($member);
        }
        $this->em->flush();

        return $block->getId();
    }

    /**
     * Les blocs de mutualisation ancrés à un plan de période. clear() : la requête HTTP a écrit
     * dans son propre UnitOfWork, on relit depuis la base.
     *
     * @return list<SharedTrainingBlock>
     */
    private function planBlocks(string $clubId, string $planId): array
    {
        $this->scopeGucToClub($clubId);
        $this->em->clear();

        return array_values($this->em->getRepository(SharedTrainingBlock::class)->findBy(['schedulePlanId' => $planId]));
    }

    /**
     * Les teamIds d'un bloc, triés — pour comparer un contenu VERBATIM sans dépendre de l'ordre.
     *
     * @return list<string>
     */
    private function blockTeamIds(string $blockId): array
    {
        $rows = $this->em->getRepository(SharedTrainingBlockTeam::class)->findBy(['blockId' => $blockId]);
        $ids = array_map(static fn (SharedTrainingBlockTeam $row): string => $row->getTeamId(), $rows);
        sort($ids);

        return array_values($ids);
    }

    private function planOf(string $clubId, string $calendarEntryId): ?SchedulePlan
    {
        $this->scopeGucToClub($clubId);
        // clear(): la requête HTTP a son propre EntityManager — sans ça on relirait
        // un UnitOfWork qui ignore les écritures faites côté serveur.
        $this->em->clear();

        return $this->em->getRepository(SchedulePlan::class)->findOneBy(['calendarEntryId' => $calendarEntryId]);
    }

    private function scheduleCount(string $clubId): int
    {
        $this->scopeGucToClub($clubId);

        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM schedule WHERE club_id = :cid',
            ['cid' => $clubId],
        );
    }

    /**
     * @return array{0: User, 1: Club, 2: Season}
     */
    private function createClubWithSeason(): array
    {
        $uid = uniqid('', true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $club = new Club;
        $club->setName('Club plan-au-geste');
        $club->setSlug('club-plan-geste-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode('PLG' . strtoupper(substr(md5($uid), 0, 10)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('plangeste' . $uid . '@test.com');
        $user->setFirstName('Plan');
        $user->setLastName('Geste');
        $user->setPasswordHash($hasher->hashPassword($user, 'pass'));
        $this->em->persist($user);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());

        $membership = new ClubUser;
        $membership->setClubId($club->getId());
        $membership->setUserId($user->getId());
        $membership->setRole('admin');
        $membership->setIsActive(true);
        $this->em->persist($membership);

        $year = SeasonResolver::seasonYear(new DateTimeImmutable('today'));
        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName((string) $year);
        $season->setStartDate(new DateTimeImmutable($year . '-08-01'));
        $season->setEndDate(new DateTimeImmutable(($year + 1) . '-07-15'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $season->setTransitionData([]);
        $this->em->persist($season);
        $this->em->flush();

        return [$user, $club, $season];
    }

    /**
     * @return array{HTTP_AUTHORIZATION: string}
     */
    private function authHeaders(User $user): array
    {
        $token = self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);

        return ['HTTP_AUTHORIZATION' => 'Bearer ' . $token];
    }
}
