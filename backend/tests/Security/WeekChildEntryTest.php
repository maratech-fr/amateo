<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Constraint;
use App\Entity\Schedule;
use App\Entity\SchedulePlan;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\ConstraintFamily;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use App\Enum\SchedulePlanType;
use App\Enum\ScheduleStatus;
use App\Enum\SeasonStatus;
use App\Service\SeasonResolver;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * NR — P2-5 E1 (fondateur 2026-07-18), axe *planning lifecycle* : PLANS DE PÉRIODE
 * À LA SEMAINE. Une semaine cochée = une CalendarEntry ENFANT (`parentEntryId`)
 * qui naît avec SON plan par le rail existant (1 entrée = 1 plan, ADR-0002 intact).
 *
 * Ce que ce test verrouille (amendé 2026-07-24 — le plan naît du geste d'ADAPTER) :
 *  1. POST d'un enfant ⇒ son plan naît, fenêtre = la semaine, type hérité ; la mère,
 *     elle, n'a JAMAIS de plan tant que personne n'a cliqué Adapter ;
 *  2. un enfant hérite du TYPE de sa mère (422 sinon) ;
 *  3. un seul niveau : un enfant ne se découpe pas (422) ;
 *  4. exclusivité bloc/semaines : mère déjà générée d'un bloc → pas de découpage (422) ;
 *     mère découpée → son plan-bloc a été SUPPRIMÉ par la découpe, générer dessus
 *     répond 422 (plan inconnu) ;
 *  5. la découpe emporte le plan-bloc ET ses réglages ancrés (décision fondateur
 *     2026-07-24), idempotente au 2ᵉ enfant ;
 *  6. supprimer la MÈRE emporte ses enfants ET leurs plans (cascade complète) ;
 *  7. les contraintes datées d'une semaine se lisent sur sa MÈRE (héritage).
 */
#[Group('phase1')]
#[Group('integration')]
final class WeekChildEntryTest extends WebTestCase
{
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    public function testPostingAWeekChildBirthsItsOwnPlanOnTheWeekWindow(): void
    {
        [$user, $club] = $this->createClubWithSeason();
        $motherId = $this->postPeriod($user, 'closure', 'Barros en travaux', '2026-11-12', '2026-11-18');

        $childId = $this->postWeekChild($user, $motherId, 'closure', 'Barros en travaux — semaine du 9 nov', '2026-11-09', '2026-11-15');

        $childPlan = $this->planOf($club->getId(), $childId);
        self::assertInstanceOf(SchedulePlan::class, $childPlan, 'la semaine enfant naît avec SON plan (rail 1 entrée = 1 plan)');
        self::assertSame(SchedulePlanType::CLOSURE, $childPlan->getType());
        self::assertSame('2026-11-09', $childPlan->getStartDate()->format('Y-m-d'), 'la fenêtre du plan est la SEMAINE');
        self::assertSame('2026-11-15', $childPlan->getEndDate()->format('Y-m-d'));
        // Amendement 2026-07-24 : la mère jamais adaptée n'a JAMAIS eu de plan — la
        // découpe est le geste, il porte sur les semaines, pas sur le bloc.
        self::assertNull($this->planOf($club->getId(), $motherId), 'la mère matérialisée sans geste ne porte pas de plan');
    }

    /**
     * Décision fondateur 2026-07-24 : la découpe emporte le plan-bloc de la mère
     * (chemin « d'un bloc » commencé — 0 version — puis abandonné pour les semaines)
     * ET ses réglages ancrés. Chaque semaine repart de la structure saison.
     * Idempotent : le 2ᵉ enfant ne trouve plus rien à supprimer, sans erreur.
     */
    public function testSplittingDeletesTheMotherBlockPlanAndItsAnchoredSettings(): void
    {
        [$user, $club] = $this->createClubWithSeason();
        $motherId = $this->postPeriod($user, 'holiday', 'Vacances bloc puis semaines', '2026-11-09', '2026-11-22');
        $motherPlanId = $this->adaptPeriod($user, $motherId);

        // Un réglage ancré au plan-bloc (le gestionnaire avait commencé le chemin bloc).
        $this->client->request('POST', '/api/team_period_overrides', [], [], $this->authHeaders($user) + [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'schedulePlanId' => $motherPlanId,
            'teamId' => 'dddddddd-dddd-4ddd-8ddd-dddddddddddd',
            'isActive' => false,
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);

        $child1 = $this->postWeekChild($user, $motherId, 'holiday', 'Semaine 1', '2026-11-09', '2026-11-15');

        $this->scopeGucToClub($club->getId());
        $this->em->clear();
        self::assertNull($this->planOf($club->getId(), $motherId), 'la découpe supprime le plan-bloc de la mère');
        self::assertInstanceOf(SchedulePlan::class, $this->planOf($club->getId(), $child1), 'le plan de la semaine est né');
        self::assertSame(
            0,
            (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM team_period_override WHERE schedule_plan_id = :pid', ['pid' => $motherPlanId]),
            'les réglages ancrés au plan-bloc partent avec lui',
        );

        // Idempotence : le 2ᵉ enfant ne re-déclenche rien et ne casse rien.
        $this->postWeekChild($user, $motherId, 'holiday', 'Semaine 2', '2026-11-16', '2026-11-22');
        self::assertNull($this->planOf($club->getId(), $motherId));
    }

    public function testAWeekChildInheritsItsMotherPeriodType(): void
    {
        [$user] = $this->createClubWithSeason();
        $motherId = $this->postPeriod($user, 'closure', 'Travaux', '2026-11-12', '2026-11-18');

        $this->postWeekChildExpecting(422, $user, $motherId, 'holiday', 'Mauvais type', '2026-11-09', '2026-11-15');
    }

    /**
     * NR (décision fondateur 2026-09-04), axe *planning lifecycle* : une semaine-enfant de
     * VACANCES n'est valide que si la vacance (fenêtre de la mère) couvre TOUT son lundi→vendredi
     * ; sinon c'est une semaine de saison, qui se planifie en fermeture/overlay, jamais en reprise.
     * Le week-end ne compte pas ; un jour hors saison compte comme couvert. La règle vaut pour une
     * mère HOLIDAY seulement — les enfants FERMETURE gardent leur enveloppe (semaines partielles
     * admises). Miroir MÉCANIQUE du front `holidayCoversWorkweek` (HolidayWorkweekMirrorParityTest).
     */
    public function testAHolidayWeekChildMustBeFullyInHolidayMondayToFriday(): void
    {
        [$user] = $this->createClubWithSeason();
        // Vacances du lun 17/08 au lun 31/08 (comme l'été BCCL borné) — saison 01/08 → 15/07.
        $motherId = $this->postPeriod($user, 'holiday', 'Vacances d’été', '2026-08-17', '2026-08-31');

        // La semaine du 24 (lun 24 → dim 30) est entièrement de vacances (lun→ven ⊂ 17→31) → 201.
        $this->postWeekChild($user, $motherId, 'holiday', 'Semaine du 24 août', '2026-08-24', '2026-08-30');

        // La semaine du 31/08 (lun 31 → dim 06/09) n'a que son lundi en vacances ; mar→ven sont en
        // saison → semaine de saison, REFUSÉE en reprise avec un message parlant (pas d'identifiant).
        $this->client->request('POST', '/api/calendar_entries', [], [], $this->authHeaders($user) + [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'kind' => 'period',
            'title' => 'Semaine du 31 août',
            'startDate' => '2026-08-31',
            'endDate' => '2026-09-06',
            'periodType' => 'holiday',
            'parentEntryId' => $motherId,
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('pas entièrement en vacances', (string) $this->client->getResponse()->getContent());
    }

    /**
     * Corollaire de la règle ci-dessus : une FERMETURE garde son enveloppe — une semaine que la
     * fermeture ne couvre pas entièrement lun→ven reste une semaine-enfant valide (201). La règle
     * « de vacances » ne s'applique donc PAS aux mères closure.
     */
    public function testAClosureWeekChildIsAcceptedEvenWhenOnlyPartiallyCovered(): void
    {
        [$user] = $this->createClubWithSeason();
        // Fermeture mer 11/11 → mer 18/11 : ses semaines-enfants pleines lun→dim ne sont couvertes
        // que partiellement par la fermeture, et restent valides (contraste avec les vacances).
        $motherId = $this->postPeriod($user, 'closure', 'Barros en travaux', '2026-11-11', '2026-11-18');

        // Semaine du 09/11 (lun 09 → dim 15) : la fermeture ne la couvre que du mercredi → toujours 201.
        $this->postWeekChild($user, $motherId, 'closure', 'Semaine du 9 nov', '2026-11-09', '2026-11-15');
    }

    /**
     * NR (décision fondateur 2026-09-05), axe *planning lifecycle* : une indisponibilité (mère
     * CLOSURE) se découpe en DÉBUT (semaine entamée de tête), MILIEU (semaines pleines contiguës) et
     * FIN (semaine entamée de queue). Chaque bout est un enfant valide. Mère mer 11/11 → mar 24/11 :
     * début = semaine du 09/11, milieu = semaine du 16/11, fin = semaine du 23/11.
     */
    public function testAClosureSplitsIntoStartMiddleEndSegments(): void
    {
        [$user] = $this->createClubWithSeason();
        $motherId = $this->postPeriod($user, 'closure', 'Matéo en travaux', '2026-11-11', '2026-11-24');

        $this->postWeekChild($user, $motherId, 'closure', 'Début', '2026-11-09', '2026-11-15');
        $this->postWeekChild($user, $motherId, 'closure', 'Milieu', '2026-11-16', '2026-11-22');
        $this->postWeekChild($user, $motherId, 'closure', 'Fin', '2026-11-23', '2026-11-29');
    }

    /**
     * NR — le MILIEU est UN SEUL plan : jamais une semaine complète isolée, jamais un run tronqué.
     * Mère lun 09/11 → dim 29/11 (3 semaines pleines contiguës = un seul milieu). Une semaine
     * complète isolée (09/11→15/11) et un run non maximal (09/11→22/11) sont refusés ; seul le
     * milieu ENTIER (09/11→29/11) est accepté.
     */
    public function testAClosureMiddleIsOneMaximalPlanNoIsolatedFullWeek(): void
    {
        [$user] = $this->createClubWithSeason();
        $motherId = $this->postPeriod($user, 'closure', 'Travaux longs', '2026-11-09', '2026-11-29');

        // Une semaine complète isolée du milieu → 422 (message parlant, pas d'identifiant interne).
        $this->client->request('POST', '/api/calendar_entries', [], [], $this->authHeaders($user) + [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'kind' => 'period', 'title' => 'Semaine isolée', 'startDate' => '2026-11-09', 'endDate' => '2026-11-15',
            'periodType' => 'closure', 'parentEntryId' => $motherId,
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('un seul plan', (string) $this->client->getResponse()->getContent());

        // Un run non maximal (2 des 3 semaines pleines) → 422.
        $this->postWeekChildExpecting(422, $user, $motherId, 'closure', 'Run tronqué', '2026-11-09', '2026-11-22');

        // Le milieu ENTIER → 201.
        $this->postWeekChild($user, $motherId, 'closure', 'Milieu entier', '2026-11-09', '2026-11-29');
    }

    /**
     * NR — un enfant ne MÉLANGE jamais partiel et complet. Mère mer 11/11 → dim 29/11 : début =
     * semaine du 09/11 (entamée), milieu = semaines du 16/11 au 29/11. Un enfant qui recouvre le
     * début + une semaine du milieu (09/11→22/11) est refusé ; début seul et milieu seul passent.
     */
    public function testAClosureChildNeverMixesPartialAndFullWeeks(): void
    {
        [$user] = $this->createClubWithSeason();
        $motherId = $this->postPeriod($user, 'closure', 'Travaux mélange', '2026-11-11', '2026-11-29');

        // Début (partiel) + une semaine du milieu → mélange → 422.
        $this->postWeekChildExpecting(422, $user, $motherId, 'closure', 'Mélange', '2026-11-09', '2026-11-22');

        // Le début seul et le milieu seul sont des segments valides (201).
        $this->postWeekChild($user, $motherId, 'closure', 'Début seul', '2026-11-09', '2026-11-15');
        $this->postWeekChild($user, $motherId, 'closure', 'Milieu seul', '2026-11-16', '2026-11-29');
    }

    /**
     * NR — un TROU de vacances (lun→ven couvert) au milieu d'une fermeture coupe le milieu en DEUX
     * runs, chacun un plan. Mère lun 09/11 → dim 29/11 ; des vacances gouvernent la semaine du 16/11.
     * Le milieu ENTIER (09/11→29/11) est refusé ; les deux runs (09/11→15/11 et 23/11→29/11) passent.
     */
    public function testAClosureHolidayHoleYieldsTwoMiddleRuns(): void
    {
        [$user] = $this->createClubWithSeason();
        $motherId = $this->postPeriod($user, 'closure', 'Travaux à trou', '2026-11-09', '2026-11-29');
        // Une vacance racine couvre la semaine du 16/11 (lun→ven) : elle sort de l'offre fermeture.
        $this->postPeriod($user, 'holiday', 'Vacances au milieu', '2026-11-16', '2026-11-22');

        // Le milieu ENTIER n'existe plus (le trou l'a coupé) → 422.
        $this->postWeekChildExpecting(422, $user, $motherId, 'closure', 'Milieu entier', '2026-11-09', '2026-11-29');

        // Les deux runs de part et d'autre du trou → 201 chacun.
        $this->postWeekChild($user, $motherId, 'closure', 'Run 1', '2026-11-09', '2026-11-15');
        $this->postWeekChild($user, $motherId, 'closure', 'Run 2', '2026-11-23', '2026-11-29');
    }

    public function testAWeekChildCannotItselfBeSplit(): void
    {
        [$user] = $this->createClubWithSeason();
        $motherId = $this->postPeriod($user, 'closure', 'Travaux', '2026-11-12', '2026-11-18');
        $childId = $this->postWeekChild($user, $motherId, 'closure', 'Semaine 1', '2026-11-09', '2026-11-15');

        $this->postWeekChildExpecting(422, $user, $childId, 'closure', 'Petit-enfant interdit', '2026-11-09', '2026-11-15');
    }

    public function testAChildWindowMustTouchTheMotherAndNotOverlapASibling(): void
    {
        [$user] = $this->createClubWithSeason();
        $motherId = $this->postPeriod($user, 'closure', 'Travaux', '2026-11-12', '2026-11-18');
        $this->postWeekChild($user, $motherId, 'closure', 'Semaine 1', '2026-11-09', '2026-11-15');

        // Hors de la fenêtre mère → 422 (elle hériterait les datées sans raison).
        $this->postWeekChildExpecting(422, $user, $motherId, 'closure', 'Hors mère', '2026-12-07', '2026-12-13');
        // Chevauche la semaine 1 (même partiellement) → 422, pas seulement le même lundi :
        // un SEGMENT de 2 semaines lun→dim qui recoupe la semaine 1 (P2-41).
        $this->postWeekChildExpecting(422, $user, $motherId, 'closure', 'Chevauche', '2026-11-09', '2026-11-22');
        // Un segment qui DÉBORDE les semaines couvrant la mère → 422 : il hériterait le
        // venue_closed date-blind hors de sa portée (borne d'enveloppe, P2-41).
        $this->postWeekChildExpecting(422, $user, $motherId, 'closure', 'Déborde', '2026-11-16', '2027-01-10');
    }

    /**
     * P2-41 — un segment couvre des semaines calendaires ENTIÈRES : début un lundi, fin
     * un dimanche (hors clamp saison). Des bornes qui ne tombent pas lun/dim → 422 nommé.
     */
    public function testASegmentMustStartOnMondayAndEndOnSunday(): void
    {
        [$user] = $this->createClubWithSeason();
        // Mère de 3 semaines : enveloppe lun 09/11 → dim 29/11, largement dans la saison.
        $motherId = $this->postPeriod($user, 'closure', 'Travaux longs', '2026-11-09', '2026-11-29');

        // Début un mardi (10/11) → 422 (ni lundi ni clamp saison).
        $this->postWeekChildExpecting(422, $user, $motherId, 'closure', 'Début mardi', '2026-11-10', '2026-11-15');
        // Fin un samedi (21/11) → 422 (ni dimanche ni clamp saison).
        $this->postWeekChildExpecting(422, $user, $motherId, 'closure', 'Fin samedi', '2026-11-09', '2026-11-21');
    }

    public function testABlockGeneratedMotherRefusesWeekSplitting(): void
    {
        [$user, $club, $season] = $this->createClubWithSeason();
        // Fenêtre lun→dim ALIGNÉE (un seul segment « milieu ») : « d'un bloc » reste permis sur une
        // fermeture qui ne se décompose qu'en UN segment (décision fondateur 2026-09-05).
        $motherId = $this->postPeriod($user, 'closure', 'Travaux déjà adaptés', '2026-11-09', '2026-11-15');
        $this->adaptPeriod($user, $motherId); // le plan-bloc naît du geste (amendement 2026-07-24)
        $motherPlan = $this->planOf($club->getId(), $motherId);
        self::assertInstanceOf(SchedulePlan::class, $motherPlan);

        // Une version « bloc » pend au plan de la mère.
        $this->scopeGucToClub($club->getId());
        $version = new Schedule;
        $version->setClubId($club->getId());
        $version->setSeasonId($season->getId());
        $version->setSchedulePlanId($motherPlan->getId());
        $version->setName('V1 bloc');
        $version->setStatus(ScheduleStatus::COMPLETED);
        $this->em->persist($version);
        $this->em->flush();

        $this->postWeekChildExpecting(422, $user, $motherId, 'closure', 'Découpage refusé', '2026-11-09', '2026-11-15');
    }

    /**
     * Exclusivité bloc/semaines, versant génération : depuis l'amendement 2026-07-24
     * la DÉCOUPE SUPPRIME le plan-bloc — générer « un bloc » sur une mère découpée
     * échoue donc en 422 (plan inconnu) dès la résolution du plan. La garde 409
     * « découpée en semaines » de ScheduleStateProcessor reste dans le code pour la
     * course concurrentielle (découpe pendant un POST /schedules en vol), injouable
     * en test HTTP séquentiel.
     */
    public function testASplitMotherRefusesBlockGeneration(): void
    {
        [$user, $club] = $this->createClubWithSeason();
        // Fenêtre lun→dim ALIGNÉE (un seul segment) : « d'un bloc » permis, puis découpé.
        $motherId = $this->postPeriod($user, 'closure', 'Travaux découpés', '2026-11-09', '2026-11-15');
        $motherPlanId = $this->adaptPeriod($user, $motherId); // chemin bloc commencé…
        $this->postWeekChild($user, $motherId, 'closure', 'Semaine 1', '2026-11-09', '2026-11-15'); // …puis découpé
        self::assertNull($this->planOf($club->getId(), $motherId), 'la découpe a supprimé le plan-bloc');

        $this->client->request('POST', '/api/schedules', [], [], $this->authHeaders($user) + [
            'CONTENT_TYPE' => 'application/ld+json',
        ], json_encode([
            'name' => 'V1 bloc interdite',
            'status' => 'DRAFT',
            'schedulePlanId' => $motherPlanId,
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Unknown schedule plan', (string) $this->client->getResponse()->getContent());
    }

    public function testDeletingTheMotherCascadesToItsWeekChildren(): void
    {
        [$user, $club] = $this->createClubWithSeason();
        $motherId = $this->postPeriod($user, 'closure', 'Travaux', '2026-11-12', '2026-11-18');
        $child1 = $this->postWeekChild($user, $motherId, 'closure', 'Semaine 1', '2026-11-09', '2026-11-15');
        $child2 = $this->postWeekChild($user, $motherId, 'closure', 'Semaine 2', '2026-11-16', '2026-11-22');

        $this->client->request('DELETE', '/api/calendar_entries/' . $motherId, [], [], $this->authHeaders($user));
        self::assertResponseStatusCodeSame(204);

        $this->scopeGucToClub($club->getId());
        $this->em->clear();
        foreach ([$motherId, $child1, $child2] as $goneId) {
            self::assertNull($this->em->getConnection()->fetchOne('SELECT 1 FROM calendar_entry WHERE id = :id', ['id' => $goneId]) ?: null, 'l’entrée est supprimée');
            self::assertNull($this->em->getRepository(SchedulePlan::class)->findOneBy(['calendarEntryId' => $goneId]), 'son plan aussi');
        }
    }

    public function testAWeekChildReadsItsMotherDatedConstraints(): void
    {
        [$user, $club, $season] = $this->createClubWithSeason();
        $motherId = $this->postPeriod($user, 'closure', 'Barros fermé', '2026-11-12', '2026-11-18');
        $childId = $this->postWeekChild($user, $motherId, 'closure', 'Semaine 1', '2026-11-09', '2026-11-15');

        // Le FAIT (venue_closed) vit sur la MÈRE — patron du cockpit (useCreateVenueClosure).
        $this->scopeGucToClub($club->getId());
        $venueId = '77777777-7777-4777-8777-777777777777';
        $dated = new Constraint;
        $dated->setClubId($club->getId());
        $dated->setSeasonId($season->getId());
        $dated->setName('Barros fermé');
        $dated->setScope(ConstraintScope::FACILITY);
        $dated->setFamily(ConstraintFamily::FACILITY);
        $dated->setRuleType(ConstraintRuleType::HARD);
        $dated->setScopeTargetId($venueId);
        $dated->setConfig(['type' => 'venue_closed']);
        $dated->setCalendarEntryId($motherId);
        $this->em->persist($dated);
        $this->em->flush();

        // Les impacts d'une SEMAINE se calculent avec les datées de sa mère : le
        // gymnase fermé remonte dans les venueIds de l'enfant.
        $this->client->request('GET', '/api/calendar-entries/' . $childId . '/conflicts', [], [], $this->authHeaders($user));
        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertContains($venueId, $payload['venueIds'] ?? [], 'la semaine hérite les datées de sa mère');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    /** Le geste « Adapter » : POST /api/schedule_plans — rend l'id du plan (201). */
    private function adaptPeriod(User $user, string $entryId): string
    {
        $this->client->request('POST', '/api/schedule_plans', [], [], $this->authHeaders($user) + [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['calendarEntryId' => $entryId], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertIsString($payload['id']);

        return $payload['id'];
    }

    private function postPeriod(User $user, string $periodType, string $title, string $start, string $end): string
    {
        return $this->postEntry($user, [
            'kind' => 'period',
            'title' => $title,
            'startDate' => $start,
            'endDate' => $end,
            'periodType' => $periodType,
        ]);
    }

    private function postWeekChild(User $user, string $parentId, string $periodType, string $title, string $start, string $end): string
    {
        return $this->postEntry($user, [
            'kind' => 'period',
            'title' => $title,
            'startDate' => $start,
            'endDate' => $end,
            'periodType' => $periodType,
            'parentEntryId' => $parentId,
        ]);
    }

    private function postWeekChildExpecting(int $status, User $user, string $parentId, string $periodType, string $title, string $start, string $end): void
    {
        $this->client->request('POST', '/api/calendar_entries', [], [], $this->authHeaders($user) + [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'kind' => 'period',
            'title' => $title,
            'startDate' => $start,
            'endDate' => $end,
            'periodType' => $periodType,
            'parentEntryId' => $parentId,
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame($status);
    }

    /** @param array<string, mixed> $body */
    private function postEntry(User $user, array $body): string
    {
        $this->client->request('POST', '/api/calendar_entries', [], [], $this->authHeaders($user) + [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode($body, \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertIsString($payload['id']);

        return $payload['id'];
    }

    private function planOf(string $clubId, string $calendarEntryId): ?SchedulePlan
    {
        $this->scopeGucToClub($clubId);
        $this->em->clear();

        return $this->em->getRepository(SchedulePlan::class)->findOneBy(['calendarEntryId' => $calendarEntryId]);
    }

    /**
     * @return array{0: User, 1: Club, 2: Season}
     */
    private function createClubWithSeason(): array
    {
        $uid = uniqid('', true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $club = new Club;
        $club->setName('Club semaines');
        $club->setSlug('club-semaines-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode('WKC' . strtoupper(substr(md5($uid), 0, 10)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('semaines' . $uid . '@test.com');
        $user->setFirstName('Week');
        $user->setLastName('Child');
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
