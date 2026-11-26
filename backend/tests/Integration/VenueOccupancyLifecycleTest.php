<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Schedule;
use App\Entity\Season;
use App\Entity\User;
use App\Entity\Venue;
use App\Entity\VenueTrainingSlot;
use App\Enum\ScheduleStatus;
use App\Enum\SeasonStatus;
use App\Plans\PlanStates;
use App\Service\PeriodPlanSiblingCopier;
use App\Service\SchedulePlanProvisioner;
use App\Service\SeasonDataPurger;
use App\Service\SeasonResolver;
use App\Service\SeasonTransitionService;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\CreatesPeopleAndPlacesTrait;
use App\Tests\SeasonPlanOf;
use App\Tests\Support\SubscribesClub;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * NR BLOQUANT — la vie d'une occupation suit celle de la grille du gymnase (axe §7.1 *planning
 * lifecycle*, plan PR6 §6 N2).
 *
 * Une occupation nomme un moment où le gymnase n'est pas au club. Elle vit dans la grille, comme
 * un créneau : la saison porte les siennes, chaque plan de période sa COPIE prise à la naissance
 * (ADR-0002, grille possédée par la période). D'où les promesses gardées ici, un test par geste :
 * naissance du plan (copie fidèle), « Reprendre » et « Vider » (limités au gymnase visé), copie
 * vers un plan frère (exactement les occupations du plan source), suppression du plan (cascade,
 * saison intacte), valider puis rouvrir le planning de saison (saison intacte), charger une version
 * (AUCUNE occupation supprimée : l'occupation est libre, hors de la copie d'une version), passage de
 * saison (la saison se copie, la période non), purge de saison, suppression d'un gymnase (et son
 * compte d'impact). Depuis le lot 5 (PR 3), « Charger » ne supprime plus aucun gymnase : une
 * occupation « dormante » (gymnase absent) n'a plus de geste qui la fasse naître.
 *
 * Préparation par l'API là où le geste existe (pose, déplacement, naissance du plan, « Reprendre »,
 * « Vider », validation, suppression, « Charger ») ; services pour les gestes sans route directe dans
 * un test (copie sœur, copie d'une version, passage de saison, purge) ; SQL pour le pointage.
 *
 * Falsifications à jouer (elles doivent rougir le cas nommé) :
 *  - retirer `VenueOccupancy::class` de `OverlayManager::purgePlanAnchoredSettings` → le plan
 *    frère porte en plus la copie de saison née avec lui : {@see testSiblingCopyCarriesExactlyTheSourcePlanOccupancies} rougit ;
 *  - déclarer `VenueOccupancy` froide du plan (la copie d'une version la porterait et « Charger »
 *    la remettrait) → {@see testLoadingAVersionDeletesNoOccupancy} rougit.
 */
#[Group('phase1')]
#[Group('integration')]
final class VenueOccupancyLifecycleTest extends WebTestCase
{
    use ChoosesPlanVersionTrait;
    use CreatesPeopleAndPlacesTrait;
    use SubscribesClub;
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    private User $user;

    private Club $club;

    private Season $season;

    /** Le plan de période naît avec une copie fidèle de chaque occupation de saison (tous les champs, sauf l'identifiant et l'ancre). */
    public function testAPeriodPlanIsBornWithAFaithfulCopyOfTheSeasonOccupancies(): void
    {
        $this->seedClub();
        $venue = $this->venue('Gymnase Barros');
        $this->postOccupancy($venue, 2, '14:00', 135, 'École');
        $this->postOccupancy($venue, 5, '09:00', 60, 'Mairie');

        [, $planId] = $this->periodPlan('Fermeture', 21);

        $season = $this->rows('schedule_plan_id IN (SELECT id FROM schedule_plan WHERE type = \'SEASON\')');
        $copy = $this->rows('schedule_plan_id = \'' . $planId . '\'');
        self::assertCount(2, $season);
        self::assertSame($season, $copy, 'la période porte la copie exacte (club, saison, gymnase, jour, début, durée, motif) de chaque occupation de saison');
        self::assertSame([], array_intersect($this->ids(null), $this->ids($planId)), 'la copie a ses propres identifiants : la modifier ne touche pas la saison');
    }

    /** « Reprendre la grille » ramène les occupations de saison du SEUL gymnase visé ; l'autre gymnase de la période ne bouge pas. */
    public function testResetFromSeasonOnlyTouchesTheTargetedVenue(): void
    {
        $this->seedClub();
        $target = $this->venue('Gymnase A');
        $other = $this->venue('Gymnase B');
        $this->postOccupancy($target, 2, '14:00', 135, 'École');
        $this->postOccupancy($other, 2, '14:00', 135, 'École');
        [, $planId] = $this->periodPlan('Fermeture', 21);

        foreach ([$target, $other] as $venue) {
            $this->putOccupancy($this->onlyId($venue, $planId), $venue, 5, '13:45', 120, 'École');
        }

        $this->gridAction('reset-grid', $planId, $target);

        self::assertSame([[2, '14:00', 135, 'École']], $this->shape($target, $planId), '« Reprendre » ramène l\'occupation de saison du gymnase visé');
        self::assertSame([[5, '13:45', 120, 'École']], $this->shape($other, $planId), '« Reprendre » ne touche pas l\'autre gymnase de la période');
    }

    /** « Vider la grille » retire les occupations de la période du SEUL gymnase visé ; la saison et l'autre gymnase les gardent. */
    public function testClearOnlyTouchesTheTargetedVenueOfThePeriod(): void
    {
        $this->seedClub();
        $target = $this->venue('Gymnase A');
        $other = $this->venue('Gymnase B');
        $this->postOccupancy($target, 2, '14:00', 135, 'École');
        $this->postOccupancy($other, 2, '14:00', 135, 'École');
        [, $planId] = $this->periodPlan('Fermeture', 21);

        $this->gridAction('clear-grid', $planId, $target);

        self::assertSame([], $this->shape($target, $planId), '« Vider » retire les occupations de la période pour ce gymnase');
        self::assertSame([[2, '14:00', 135, 'École']], $this->shape($other, $planId), '« Vider » ne touche pas l\'autre gymnase de la période');
        self::assertSame([[2, '14:00', 135, 'École']], $this->shape($target, null), '« Vider » ne touche pas la saison');
    }

    /**
     * Copie vers un plan frère : la saison porte A, le plan source porte A déplacée et B ; le plan
     * frère porte exactement ces deux-là — jamais en plus la copie de saison née avec lui.
     */
    public function testSiblingCopyCarriesExactlyTheSourcePlanOccupancies(): void
    {
        $this->seedClub();
        $venue = $this->venue('Gymnase Barros');
        $this->postOccupancy($venue, 2, '14:00', 135, 'École');

        [, $sourcePlanId] = $this->periodPlan('Barros en travaux (milieu)', 21);
        $this->putOccupancy($this->onlyId($venue, $sourcePlanId), $venue, 5, '13:45', 120, 'École');
        $this->postOccupancy($venue, 3, '10:00', 60, 'Mairie', $sourcePlanId);
        $this->pointAVersionOf($sourcePlanId);

        [$targetEntryId, $targetPlanId] = $this->periodPlan('Barros en travaux (après)', 35);
        self::assertSame([[2, '14:00', 135, 'École']], $this->shape($venue, $targetPlanId), 'décor : le plan frère naît avec la copie de saison');

        self::getContainer()->get(PeriodPlanSiblingCopier::class)->copyInto($sourcePlanId, $targetEntryId);

        self::assertSame(
            [[3, '10:00', 60, 'Mairie'], [5, '13:45', 120, 'École']],
            $this->shape($venue, $targetPlanId),
            'le plan frère porte exactement les occupations du plan source (A déplacée et B), sans la copie de saison',
        );
        self::assertSame([[2, '14:00', 135, 'École']], $this->shape($venue, null), 'la saison garde A à sa place');
    }

    /** Supprimer une période emporte les occupations de son plan (cascade) ; celles de la saison restent. */
    public function testDeletingAPeriodTakesItsPlanOccupanciesAndSparesTheSeason(): void
    {
        $this->seedClub();
        $venue = $this->venue('Gymnase Barros');
        $this->postOccupancy($venue, 2, '14:00', 135, 'École');
        [$entryId, $planId] = $this->periodPlan('Fermeture', 21);
        self::assertCount(1, $this->rows('schedule_plan_id = \'' . $planId . '\''), 'décor : la période porte sa copie');

        $this->client->request('DELETE', '/api/calendar_entries/' . $entryId, [], [], $this->headers());
        self::assertResponseIsSuccessful();

        self::assertSame([], $this->rows('schedule_plan_id = \'' . $planId . '\''), 'les occupations du plan supprimé partent avec lui');
        self::assertSame([[2, '14:00', 135, 'École']], $this->shape($venue, null), 'celles de la saison restent');
    }

    /** Valider puis rouvrir le planning de saison laisse les occupations de saison telles quelles. */
    public function testValidatingThenReopeningTheSeasonPlanKeepsSeasonOccupancies(): void
    {
        $this->seedClub();
        $venue = $this->venue('Gymnase Barros');
        $this->postOccupancy($venue, 2, '14:00', 135, 'École');
        $this->periodPlan('Fermeture à venir', 21);
        $before = $this->rowsWithIds('schedule_plan_id IN (SELECT id FROM schedule_plan WHERE type = \'SEASON\')');

        $version = $this->seasonVersion();
        $this->em->flush();
        foreach (['validate', 'reopen'] as $gesture) {
            $this->client->request('POST', '/api/schedules/' . $version->getId() . '/' . $gesture, [], [], $this->headers() + ['CONTENT_TYPE' => 'application/json'], json_encode(['confirmDeleteOverlays' => true], \JSON_THROW_ON_ERROR));
            self::assertResponseIsSuccessful(\sprintf('décor : « %s » du planning de saison doit réussir', $gesture));
        }

        self::assertSame($before, $this->rowsWithIds('schedule_plan_id IN (SELECT id FROM schedule_plan WHERE type = \'SEASON\')'), 'valider puis rouvrir le planning de saison ne touche aucune occupation de saison');
    }

    /**
     * Charger une version ne supprime aucune occupation : ni celles d'un gymnase créé après la
     * version (saison et période), ni celle qu'un créneau revenu avec la version recouvre.
     */
    public function testLoadingAVersionDeletesNoOccupancy(): void
    {
        $this->seedClub();
        $kept = $this->venue('Gymnase gardé');
        $slotId = $this->seasonSlot($kept, 2, '14:00');
        $photo = $this->photo();

        // Après la photo : le créneau part, une occupation prend sa place ; un gymnase naît avec la
        // sienne, copiée dans une période.
        $this->scopeGucToClub($this->club->getId());
        $this->em->getConnection()->executeStatement('DELETE FROM venue_training_slot WHERE id = :id', ['id' => $slotId]);
        $this->postOccupancy($kept, 2, '14:00', 90, 'École');
        $absent = $this->venue('Gymnase absent de la version');
        $this->postOccupancy($absent, 4, '10:00', 60, 'Mairie');
        $this->periodPlan('Fermeture', 21);
        $before = $this->rowsWithIds('TRUE');
        self::assertCount(4, $before, 'décor : deux occupations de saison et leurs deux copies de période');

        $this->load($photo);

        self::assertSame($before, $this->rowsWithIds('TRUE'), 'charger une version ne supprime ni ne modifie aucune occupation');
        $this->scopeGucToClub($this->club->getId());
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM venue_training_slot WHERE id = :id', ['id' => $slotId]), 'décor : le créneau est revenu sous l\'occupation');
    }

    /**
     * Le passage de saison copie les occupations de saison vers les gymnases copiés, jamais celles des périodes
     * (ni la copie de saison née avec le plan de période, ni une occupation posée sur la période seule).
     */
    public function testSeasonTransitionCopiesSeasonOccupanciesOnly(): void
    {
        $this->seedClub();
        $kept = $this->venue('Gymnase gardé');
        $this->postOccupancy($kept, 2, '14:00', 135, 'École');
        [, $planId] = $this->periodPlan('Fermeture', 21);
        $this->postOccupancy($kept, 3, '09:00', 60, 'Brocante', $planId);
        self::assertCount(3, $this->rows('TRUE'), 'décor : l\'occupation de saison, sa copie de période et celle posée sur la période');

        $this->scopeGucToClub($this->club->getId());
        $season = $this->em->getRepository(Season::class)->find($this->season->getId());
        self::assertInstanceOf(Season::class, $season);
        // Le 15 mai de la saison en cours : la saison suivante ne se prépare qu'à partir du 1er mai.
        $inWindow = new DateTimeImmutable(\sprintf('%d-05-15', SeasonResolver::seasonYear(new DateTimeImmutable('today')) + 1));
        $target = self::getContainer()->get(SeasonTransitionService::class)->transition($season, $inWindow);

        $this->scopeGucToClub($this->club->getId());
        $copiedVenue = (string) $this->em->getConnection()->fetchOne('SELECT id FROM venue WHERE season_id = :s AND name = :n', ['s' => $target->getId(), 'n' => $kept->getName()]);
        self::assertNotSame('', $copiedVenue, 'décor : le gymnase présent est copié vers la saison suivante');
        self::assertSame(
            [[$copiedVenue, 2, '14:00', 135, 'École', SeasonPlanOf::id(self::getContainer(), $target->getId())]],
            array_map(static fn (array $r): array => [$r['venue_id'], (int) $r['day_of_week'], $r['start'], (int) $r['duration_minutes'], $r['label'], $r['schedule_plan_id']], $this->rawRows('season_id = \'' . $target->getId() . '\'')),
            'seule l\'occupation de saison d\'un gymnase copié passe à la saison suivante (ni la copie de période, ni celle posée sur la période)',
        );
    }

    /** La purge d'une saison retire toutes ses occupations, de saison comme de période. */
    public function testPurgingASeasonRemovesItsOccupancies(): void
    {
        $this->seedClub();
        $venue = $this->venue('Gymnase Barros');
        $this->postOccupancy($venue, 2, '14:00', 135, 'École');
        $this->periodPlan('Fermeture', 21);
        self::assertCount(2, $this->rows('TRUE'), 'décor : une occupation de saison et sa copie de période');

        self::getContainer()->get(SeasonDataPurger::class)->purge($this->club->getId(), $this->season->getId());

        self::assertSame([], $this->rows('TRUE'), 'la purge de saison ne laisse aucune occupation');
    }

    /**
     * Supprimer un gymnase emporte ses occupations, et la confirmation les annonce (ligne d'impact
     * `venue_occupancy`, « occupation » / « occupations ») ; l'autre gymnase garde les siennes.
     */
    public function testDeletingAVenueTakesItsOccupanciesAndAnnouncesThem(): void
    {
        $this->seedClub();
        $venue = $this->venue('Gymnase supprimé');
        $other = $this->venue('Gymnase gardé');
        $this->postOccupancy($venue, 2, '14:00', 135, 'École');
        $this->postOccupancy($venue, 4, '10:00', 60, 'Mairie');
        $this->postOccupancy($other, 2, '14:00', 135, 'École');

        $this->client->request('GET', '/api/venues/' . $venue->getId() . '/deletion-impact', [], [], $this->headers());
        self::assertResponseIsSuccessful();
        $impact = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($impact);
        $line = null;
        foreach (\is_array($impact['lines'] ?? null) ? $impact['lines'] : [] as $candidate) {
            if (\is_array($candidate) && 'venue_occupancy' === ($candidate['key'] ?? null)) {
                $line = $candidate;
            }
        }
        self::assertSame(['key' => 'venue_occupancy', 'count' => 2, 'one' => 'occupation', 'many' => 'occupations'], $line, 'la confirmation annonce les occupations que la suppression emporte');

        $this->client->request('DELETE', '/api/venues/' . $venue->getId(), [], [], $this->headers());
        self::assertResponseIsSuccessful();

        self::assertSame([], $this->rows('venue_id = \'' . $venue->getId() . '\''), 'les occupations du gymnase supprimé partent avec lui');
        self::assertCount(1, $this->rows('venue_id = \'' . $other->getId() . '\''), 'l\'autre gymnase garde les siennes');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    private function seedClub(): void
    {
        $uid = uniqid('', true);
        $club = (new Club)->setName('Club occupations')->setSlug('occ-life-' . $uid)
            ->setTimezone('Europe/Paris')->setLocale('fr')->setOnboardingCompleted(true)
            ->setFfbbClubCode('OCC' . strtoupper(substr(md5($uid), 0, 10)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('occ-life-' . $uid . '@test.com');
        $user->setFirstName('Oc');
        $user->setLastName('Cupation');
        $user->setPasswordHash(self::getContainer()->get('security.user_password_hasher')->hashPassword($user, 'pass'));
        $this->em->persist($user);
        $this->em->flush();
        // Le passage de saison exige la saison suivante réglée : elle l'est (le gate a ses tests).
        $this->subscribeClub($club->getId(), SeasonResolver::seasonYear(new DateTimeImmutable('today')) + 1, 'beta');

        $this->scopeGucToClub($club->getId());
        $this->em->persist((new ClubUser)->setClubId($club->getId())->setUserId($user->getId())->setRole('admin')->setIsActive(true));

        // La saison EN COURS (le passage de saison ne bascule que celle-là ; les périodes sont à venir).
        $year = SeasonResolver::seasonYear(new DateTimeImmutable('today'));
        $season = (new Season)->setClubId($club->getId())->setName((string) $year)
            ->setStartDate(new DateTimeImmutable($year . '-08-01'))->setEndDate(new DateTimeImmutable(($year + 1) . '-07-15'))
            ->setStatus(SeasonStatus::ACTIVE)->setTransitionData([]);
        $this->em->persist($season);
        $this->em->flush();
        $this->provisionSeasonPlan($season);

        [$this->user, $this->club, $this->season] = [$user, $club, $season];
    }

    /** Un gymnase de la saison, avec un créneau lundi 18:00 (sa grille n'est jamais vide). */
    private function venue(string $name): Venue
    {
        $this->scopeGucToClub($this->club->getId());
        $venue = $this->newVenue($this->club->getId(), $this->season->getId(), $name);
        $this->em->persist($venue);
        $this->em->flush();
        $this->seasonSlot($venue, 1, '18:00');

        return $venue;
    }

    private function seasonSlot(Venue $venue, int $day, string $start): string
    {
        $this->scopeGucToClub($this->club->getId());
        $slot = (new VenueTrainingSlot)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setVenueId($venue->getId())->setDayOfWeek($day)->setStartTime(new DateTimeImmutable($start))
            ->setDurationMinutes(90)->setCapacity(1)->setSchedulePlanId(SeasonPlanOf::id(self::getContainer(), $this->season->getId()));
        $this->em->persist($slot);
        $this->em->flush();

        return $slot->getId();
    }

    /** Pose une occupation par la route réelle (saison si `$planId` est nul) et rend son identifiant. */
    private function postOccupancy(Venue $venue, int $day, string $start, int $duration, string $label, ?string $planId = null): string
    {
        $body = ['venueId' => $venue->getId(), 'dayOfWeek' => $day, 'startTime' => $start, 'durationMinutes' => $duration, 'label' => $label];
        if (null !== $planId) {
            $body['schedulePlanId'] = $planId;
        }
        $this->client->request('POST', '/api/venue_occupancies', [], [], $this->headers() + ['CONTENT_TYPE' => 'application/json'], json_encode($body, \JSON_THROW_ON_ERROR));
        self::assertSame(201, $this->client->getResponse()->getStatusCode(), 'décor : la pose de l\'occupation doit réussir — ' . $this->client->getResponse()->getContent());

        return (string) json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR)['id'];
    }

    private function putOccupancy(string $id, Venue $venue, int $day, string $start, int $duration, string $label): void
    {
        $body = ['venueId' => $venue->getId(), 'dayOfWeek' => $day, 'startTime' => $start, 'durationMinutes' => $duration, 'label' => $label];
        $this->client->request('PUT', '/api/venue_occupancies/' . $id, [], [], $this->headers() + ['CONTENT_TYPE' => 'application/json'], json_encode($body, \JSON_THROW_ON_ERROR));
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), 'décor : le déplacement de l\'occupation doit réussir — ' . $this->client->getResponse()->getContent());
    }

    /**
     * Une période de fermeture à venir (dans `$offsetDays` jours, cinq jours) et son plan, nés du
     * geste réel (`POST /api/calendar_entries` puis `POST /api/schedule_plans`).
     *
     * @return array{0: string, 1: string} [identifiant de l'entrée, identifiant du plan]
     */
    private function periodPlan(string $title, int $offsetDays): array
    {
        $monday = new DateTimeImmutable(\sprintf('next monday +%d days', $offsetDays));
        $this->client->request('POST', '/api/calendar_entries', [], [], $this->headers() + ['CONTENT_TYPE' => 'application/json'], json_encode([
            'kind' => 'period', 'periodType' => 'closure', 'title' => $title,
            'startDate' => $monday->format('Y-m-d'), 'endDate' => $monday->modify('+4 days')->format('Y-m-d'),
        ], \JSON_THROW_ON_ERROR));
        self::assertSame(201, $this->client->getResponse()->getStatusCode(), 'décor : la période doit naître — ' . $this->client->getResponse()->getContent());
        $entryId = (string) json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR)['id'];

        $this->client->request('POST', '/api/schedule_plans', [], [], $this->headers() + ['CONTENT_TYPE' => 'application/json'], json_encode(['calendarEntryId' => $entryId], \JSON_THROW_ON_ERROR));
        self::assertSame(201, $this->client->getResponse()->getStatusCode(), 'décor : le plan de période doit naître — ' . $this->client->getResponse()->getContent());

        $planId = self::getContainer()->get(SchedulePlanProvisioner::class)->periodPlanId($entryId);
        self::assertIsString($planId);

        return [$entryId, $planId];
    }

    /** « Reprendre la grille » (`reset-grid`) ou « Vider la grille » (`clear-grid`) par la route réelle. */
    private function gridAction(string $action, string $planId, Venue $venue): void
    {
        $this->client->request('POST', '/api/venue_period_overrides/' . $action, [], [], $this->headers() + ['CONTENT_TYPE' => 'application/json'], json_encode(['schedulePlanId' => $planId, 'venueId' => $venue->getId()], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful(\sprintf('décor : « %s » doit réussir', $action));
    }

    /** Le plan source d'une copie sœur pointe une version (sinon la copie naît vide). */
    private function pointAVersionOf(string $planId): void
    {
        $this->scopeGucToClub($this->club->getId());
        $version = (new Schedule)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setSchedulePlanId($planId)->setName('V1')->setStatus(ScheduleStatus::COMPLETED);
        $this->em->persist($version);
        self::getContainer()->get(SchedulePlanProvisioner::class)->linkSchedule($version);
        $this->em->flush();
        $this->choosePlanVersion($version);
    }

    private function seasonVersion(): Schedule
    {
        $this->scopeGucToClub($this->club->getId());
        $version = (new Schedule)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setSchedulePlanId(self::getContainer()->get(SchedulePlanProvisioner::class)->ensureSeasonPlan($this->season)->getId())
            ->setName('Socle')->setStatus(ScheduleStatus::COMPLETED);
        $this->em->persist($version);
        self::getContainer()->get(SchedulePlanProvisioner::class)->linkSchedule($version);

        return $version;
    }

    /** La copie du froid du plan de saison, posée sur une version neuve de saison (le geste « charger » la relira). */
    private function photo(): Schedule
    {
        $version = $this->seasonVersion();
        $this->em->flush();
        $states = self::getContainer()->get(PlanStates::class);
        $states->store($version, $states->capture($version->getSchedulePlanId()));

        return $version;
    }

    /** « Charger » une version, par la route : l'état de travail du plan de saison est remis d'après sa copie. */
    private function load(Schedule $version): void
    {
        $this->client->request('POST', '/api/schedules/' . $version->getId() . '/load', [], [], $this->headers() + ['CONTENT_TYPE' => 'application/json'], '{}');
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        $this->em->clear();
    }

    /** L'identifiant de l'unique occupation listée pour un gymnase dans une couche. */
    private function onlyId(Venue $venue, ?string $planId): string
    {
        $ids = $this->listIds($venue->getId(), $planId);
        self::assertCount(1, $ids, 'décor : une seule occupation attendue dans cette couche');

        return $ids[0];
    }

    /**
     * Les identifiants listés par `GET /api/venue_occupancies` (saison si `$planId` est nul).
     *
     * @return list<string>
     */
    private function listIds(string $venueId, ?string $planId): array
    {
        $query = '?venueId=' . $venueId . (null === $planId ? '' : '&schedulePlanId=' . $planId);
        $this->client->request('GET', '/api/venue_occupancies' . $query, [], [], $this->headers() + ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        $items = \is_array($body) ? ($body['member'] ?? $body) : [];
        $ids = [];
        foreach (\is_array($items) ? $items : [] as $item) {
            if (\is_array($item) && \is_string($item['id'] ?? null)) {
                $ids[] = $item['id'];
            }
        }

        return $ids;
    }

    /**
     * Les occupations d'un gymnase dans une couche, en base : [jour, début, durée, motif], triées.
     *
     * @return list<array{int, string, int, string}>
     */
    private function shape(Venue $venue, ?string $planId): array
    {
        $where = 'venue_id = \'' . $venue->getId() . '\' AND ' . (null === $planId ? 'schedule_plan_id IN (SELECT id FROM schedule_plan WHERE type = \'SEASON\')' : 'schedule_plan_id = \'' . $planId . '\'');

        return array_map(static fn (array $r): array => [(int) $r['day_of_week'], $r['start'], (int) $r['duration_minutes'], $r['label']], $this->rawRows($where));
    }

    /**
     * Tous les champs métier d'une occupation (sans identifiant ni ancre), triés.
     *
     * @return list<array<string, mixed>>
     */
    private function rows(string $where): array
    {
        return array_map(static function (array $r): array {
            unset($r['id'], $r['schedule_plan_id']);

            return $r;
        }, $this->rawRows($where));
    }

    /**
     * Toutes les colonnes d'une occupation, identifiant et ancre compris (pour « rien n'a bougé »).
     *
     * @return list<array<string, mixed>>
     */
    private function rowsWithIds(string $where): array
    {
        return $this->rawRows($where);
    }

    /** @return list<array<string, mixed>> */
    private function rawRows(string $where): array
    {
        $this->scopeGucToClub($this->club->getId());

        return $this->em->getConnection()->fetchAllAssociative(
            'SELECT id, club_id, season_id, venue_id, day_of_week, to_char(start_time, \'HH24:MI\') AS start, duration_minutes, label, schedule_plan_id'
            . ' FROM venue_occupancy WHERE club_id = :club AND (' . $where . ')'
            . ' ORDER BY season_id, venue_id, day_of_week, start_time, label, id',
            ['club' => $this->club->getId()],
        );
    }

    /** @return list<string> les identifiants des occupations d'une couche (saison si `$planId` est nul), en base */
    private function ids(?string $planId): array
    {
        return array_map(static fn (array $r): string => (string) $r['id'], $this->rawRows(null === $planId ? 'schedule_plan_id IN (SELECT id FROM schedule_plan WHERE type = \'SEASON\')' : 'schedule_plan_id = \'' . $planId . '\''));
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'HTTP_AUTHORIZATION' => 'Bearer ' . self::getContainer()->get(JWTTokenManagerInterface::class)->create($this->user),
            'HTTP_X-Season-Id' => $this->season->getId(),
        ];
    }
}
