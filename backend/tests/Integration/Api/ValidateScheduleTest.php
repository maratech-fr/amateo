<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Coach;
use App\Entity\CoachWish;
use App\Entity\CoachWishCampaign;
use App\Entity\CoachWishMutualization;
use App\Entity\CoachWishToken;
use App\Entity\Schedule;
use App\Entity\Season;
use App\Entity\SolverMetric;
use App\Entity\User;
use App\Entity\Venue;
use App\Entity\VenuePeriodOverride;
use App\Entity\VenueTrainingSlot;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\ScheduleStatus;
use App\Enum\SeasonStatus;
use App\Enum\VenuePeriodMode;
use App\Service\SchedulePlanProvisioner;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * §7.1 planning lifecycle — ADR-0002 inv. 1: validating a COMPLETED version makes
 * the plan POINT at it. There is no VALIDATED status: "validated" is derived from
 * the pointer alone. Choosing also DELETES the sibling versions of the same scope
 * (never the overlays); a sibling still generating blocks the choice (409). Only
 * within the caller's own club, and only when completed.
 */
#[Group('phase1')]
#[Group('integration')]
final class ValidateScheduleTest extends WebTestCase
{
    use ChoosesPlanVersionTrait;
    use TenantGucTrait;

    private EntityManagerInterface $em;

    private KernelBrowser $client;

    private UserPasswordHasherInterface $hasher;

    public function testValidateMakesThePlanPointAtTheVersion(): void
    {
        [$user, , $season] = $this->seed('VAL1');
        $schedule = $this->createSchedule($season, ScheduleStatus::COMPLETED);

        $this->client->loginUser($user);
        $this->client->request('POST', "/api/schedules/{$schedule->getId()}/validate");

        self::assertResponseIsSuccessful();
        $this->em->clear();
        self::assertSame($schedule->getId(), $this->chosenPlanVersion($season), 'validating = the plan points at this version');
        // The version keeps the solver's verdict: "chosen" is carried by the
        // pointer, never mirrored back onto the status.
        $reloaded = $this->em->getRepository(Schedule::class)->find($schedule->getId());
        self::assertSame(ScheduleStatus::COMPLETED, $reloaded?->getStatus());
    }

    /**
     * NR SA2-stats (§7.1 planning lifecycle) — LA TÉLÉMÉTRIE EST APPEND-ONLY (décision
     * fondateur 2026-07-18) : valider supprime les versions sœurs (inv. 1) mais JAMAIS
     * leurs métriques solveur — l'historique des tentatives est la stat d'usage
     * superadmin. Avant ce lot, purgeArtifacts les emportait : les agrégats se
     * réécrivaient rétroactivement à chaque validation.
     */
    public function testValidationKeepsTheSiblingsSolverTelemetry(): void
    {
        [$user, $club, $season] = $this->seed('VAL11');
        $v1 = $this->createSchedule($season, ScheduleStatus::COMPLETED);
        $v2 = $this->createSchedule($season, ScheduleStatus::COMPLETED);
        // Une tentative de génération par version (comme la prod : une ligne par attempt).
        $m1 = new SolverMetric($v1->getId(), $club->getId(), 'COMPLETED', 1200, null, null, null, 9000, 'v1', null, 'SEASON', 12, 3);
        $m2 = new SolverMetric($v2->getId(), $club->getId(), 'COMPLETED', 900, null, null, null, 9100, 'v1', null, 'SEASON', 12, 3);
        $this->em->persist($m1);
        $this->em->persist($m2);
        $this->em->flush();

        $this->client->loginUser($user);
        $this->client->request('POST', "/api/schedules/{$v2->getId()}/validate");
        self::assertResponseIsSuccessful();

        $this->em->clear();
        $this->scopeGucToClub($club->getId());
        self::assertNull($this->em->getRepository(Schedule::class)->find($v1->getId()), 'la version sœur est supprimée (inv. 1)');
        self::assertNotNull($this->em->getRepository(SolverMetric::class)->find($m1->getId()), 'la métrique de la sœur SURVIT (append-only) même si sa version est morte');
        self::assertNotNull($this->em->getRepository(SolverMetric::class)->find($m2->getId()));
    }

    /**
     * NR SA2-stats — `first_chosen_at` = la PREMIÈRE validation, posée une fois et
     * STABLE : rouvrir puis revalider (même une autre version) ne la déplace pas.
     * C'est la stat « temps de clôture » (création → 1re validation).
     */
    public function testFirstChosenAtIsSetOnceAndSurvivesReopenRevalidate(): void
    {
        [$user, $club, $season] = $this->seed('VAL12');
        $v1 = $this->createSchedule($season, ScheduleStatus::COMPLETED);

        $jwt = self::getContainer()->get(JWTTokenManagerInterface::class);
        $auth = ['HTTP_AUTHORIZATION' => 'Bearer ' . $jwt->create($user)];
        $this->client->request('POST', "/api/schedules/{$v1->getId()}/validate", [], [], $auth);
        self::assertResponseIsSuccessful();

        $this->scopeGucToClub($club->getId());
        $firstChosenAt = $this->em->getConnection()->fetchOne(
            'SELECT first_chosen_at FROM schedule_plan WHERE id = :pid',
            ['pid' => $v1->getSchedulePlanId()],
        );
        self::assertIsString($firstChosenAt, 'la 1re validation pose first_chosen_at');

        // Rouvrir puis revalider une AUTRE version : le pointeur bouge, PAS first_chosen_at.
        $this->client->request('POST', "/api/schedules/{$v1->getId()}/reopen", [], [], $auth);
        self::assertResponseIsSuccessful();
        $v2 = $this->createSchedule($season, ScheduleStatus::COMPLETED);
        $this->client->request('POST', "/api/schedules/{$v2->getId()}/validate", [], [], $auth);
        self::assertResponseIsSuccessful();

        $after = $this->em->getConnection()->fetchOne(
            'SELECT first_chosen_at FROM schedule_plan WHERE id = :pid',
            ['pid' => $v2->getSchedulePlanId()],
        );
        self::assertSame($firstChosenAt, $after, 'first_chosen_at est posé UNE fois — stable à la réouverture/revalidation');
    }

    public function testTheChosenVersionSurfacesOnMe(): void
    {
        [$user, , $season] = $this->seed('VAL5');
        $schedule = $this->createSchedule($season, ScheduleStatus::COMPLETED);

        $this->client->loginUser($user);
        $this->client->request('POST', "/api/schedules/{$schedule->getId()}/validate");
        self::assertResponseIsSuccessful();

        // The frontend reads the whole "is the season settled?" question from
        // /api/me.seasonPlan — the single seam (stateless firewall → Bearer).
        $jwt = self::getContainer()->get(JWTTokenManagerInterface::class);
        $this->client->request('GET', '/api/me', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $jwt->create($user),
        ]);
        self::assertResponseIsSuccessful();
        $me = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame($schedule->getId(), $me['seasonPlan']['chosenScheduleId']);
        self::assertTrue($me['seasonPlan']['hasFinishedVersion']);
    }

    public function testValidateDeletesSiblingSeasonVersionsButNotOverlays(): void
    {
        [$user, , $season] = $this->seed('VAL7');
        $v1 = $this->createSchedule($season, ScheduleStatus::COMPLETED);
        $failed = $this->createSchedule($season, ScheduleStatus::FAILED);
        // Une VRAIE période (et son plan né du geste) : un overlay se rattache à un plan réel.
        // DÉJÀ COMMENCÉE (relatif au présent) : depuis #8 c'est ce qui la met hors de
        // portée de la reprise du socle — « rien du passé, rien de ce qui est en cours ».
        $entry = (new CalendarEntry)
            ->setClubId($season->getClubId())->setSeasonId($season->getId())
            ->setKind(CalendarEntryKind::PERIOD)->setPeriodType(CalendarEntryPeriodType::CLOSURE)->setTitle('Fermeture')
            ->setStartDate(new DateTimeImmutable('-2 months'))->setEndDate(new DateTimeImmutable('-2 months +14 days'));
        $this->em->persist($entry);
        $this->em->flush();
        $overlay = $this->createSchedule($season, ScheduleStatus::COMPLETED, $entry->getId());
        $v2 = $this->createSchedule($season, ScheduleStatus::COMPLETED);

        $this->client->loginUser($user);
        $this->client->request('POST', "/api/schedules/{$v2->getId()}/validate");
        self::assertResponseIsSuccessful();

        $this->em->clear();
        // inv. 1: the plan keeps the ONE version it points at — the losers are
        // deleted, not archived. There is no hidden safety net any more.
        self::assertSame($v2->getId(), $this->chosenPlanVersion($season));
        self::assertNull($this->em->getRepository(Schedule::class)->find($v1->getId()), 'sibling COMPLETED version deleted');
        self::assertNull($this->em->getRepository(Schedule::class)->find($failed->getId()), 'sibling FAILED version deleted');
        self::assertNotNull($this->em->getRepository(Schedule::class)->find($overlay->getId()), 'le planning d\'une période ÉCHUE survit au changement de socle — il a été joué');
    }

    public function testValidateBlockedWhileSiblingIsGenerating(): void
    {
        [$user, , $season] = $this->seed('VAL8');
        $v1 = $this->createSchedule($season, ScheduleStatus::COMPLETED);
        $this->createSchedule($season, ScheduleStatus::GENERATING);

        $this->client->loginUser($user);
        $this->client->request('POST', "/api/schedules/{$v1->getId()}/validate");

        self::assertResponseStatusCodeSame(409);
        $this->em->clear();
        self::assertSame(ScheduleStatus::COMPLETED, $this->em->getRepository(Schedule::class)->find($v1->getId())?->getStatus(), 'nothing committed when a sibling is mid-solve');
    }

    public function testValidatingAnotherVersionWithOverlaysRequiresConfirmation(): void
    {
        // The plan points at V1, with a period overlay built on it; choosing V2
        // MOVES the pointer → the overlay would silently compose over a different
        // base plan (inv. 14). Same destructive idiom as reopen: 409 overlays_exist,
        // then confirmDeleteOverlays deletes the overlay.
        [$user, , $season] = $this->seed('VAL9');
        $v1 = $this->createSchedule($season, ScheduleStatus::COMPLETED);
        $this->choosePlanVersion($v1);
        $entry = (new CalendarEntry)
            ->setClubId($season->getClubId())->setSeasonId($season->getId())
            ->setKind(CalendarEntryKind::PERIOD)->setPeriodType(CalendarEntryPeriodType::HOLIDAY)->setTitle('Vacances')
            ->setStartDate(new DateTimeImmutable('+1 month'))->setEndDate(new DateTimeImmutable('+1 month +14 days'));
        $this->em->persist($entry);
        $this->em->flush();
        $overlay = $this->createSchedule($season, ScheduleStatus::COMPLETED, $entry->getId());
        $this->choosePlanVersion($overlay); // lot D-b : un plan secondaire RÉEL = plan validé
        $v2 = $this->createSchedule($season, ScheduleStatus::COMPLETED);

        // Two sequential authenticated calls → Bearer on each (stateless firewall).
        $jwt = self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
        $auth = ['HTTP_AUTHORIZATION' => 'Bearer ' . $jwt, 'CONTENT_TYPE' => 'application/json'];
        // Without the confirm flag → 409 escalation, nothing mutated.
        $this->client->request('POST', "/api/schedules/{$v2->getId()}/validate", [], [], $auth);
        self::assertResponseStatusCodeSame(409);
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('overlays_exist', $body['code'] ?? null);
        $this->em->clear();
        self::assertSame(ScheduleStatus::COMPLETED, $this->em->getRepository(Schedule::class)->find($v2->getId())?->getStatus(), 'nothing committed on the 409 path');

        // With the confirm flag → overlay deleted, baseline moved, V2 validated.
        $this->client->request('POST', "/api/schedules/{$v2->getId()}/validate", [], [], $auth, json_encode(['confirmDeleteOverlays' => true], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
        $this->em->clear();
        self::assertNull($this->em->getRepository(Schedule::class)->find($overlay->getId()), 'the stale overlay is deleted after explicit confirmation');
        self::assertSame($v2->getId(), $this->chosenPlanVersion($season), 'the pointer moved to V2');
        self::assertNull($this->em->getRepository(Schedule::class)->find($v1->getId()), 'the version it no longer points at is deleted');
    }

    public function testValidatingWithLiveOverlaysAsksEvenWhenThePlanPointsAtNothing(): void
    {
        // Le plan est un espace de travail (pointeur null) MAIS des plans secondaires
        // survivent — cas réel : le socle a été rouvert, ou la donnée vient d'avant la
        // bascule. Choisir une version donne alors aux overlays un autre socle que celui
        // sur lequel ils ont été bâtis. La garde doit s'armer sur « le plan ne pointe pas
        // DÉJÀ cette version », pas sur « le plan pointe quelque chose » — sinon elle
        // saute exactement là où elle est nécessaire, et sans rien dire.
        [$user, , $season] = $this->seed('VAL10');
        $entry = (new CalendarEntry)
            ->setClubId($season->getClubId())->setSeasonId($season->getId())
            ->setKind(CalendarEntryKind::PERIOD)->setPeriodType(CalendarEntryPeriodType::HOLIDAY)->setTitle('Vacances')
            ->setStartDate(new DateTimeImmutable('+1 month'))->setEndDate(new DateTimeImmutable('+1 month +14 days'));
        $this->em->persist($entry);
        $this->em->flush();
        $overlay = $this->createSchedule($season, ScheduleStatus::COMPLETED, $entry->getId());
        $this->choosePlanVersion($overlay); // un plan secondaire VALIDÉ survit, même sans socle pointé (donnée d'avant la bascule / socle rouvert)
        $v1 = $this->createSchedule($season, ScheduleStatus::COMPLETED);

        self::assertNull($this->chosenPlanVersion($season), 'le plan de la SAISON ne pointe rien : c\'est le cas qui désarmait la garde');

        $jwt = self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
        $auth = ['HTTP_AUTHORIZATION' => 'Bearer ' . $jwt, 'CONTENT_TYPE' => 'application/json'];
        $this->client->request('POST', "/api/schedules/{$v1->getId()}/validate", [], [], $auth);

        self::assertResponseStatusCodeSame(409);
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('overlays_exist', $body['code'] ?? null);
        $this->em->clear();
        self::assertNull($this->chosenPlanVersion($season), 'rien n\'est commité sur le 409');
        self::assertNotNull($this->em->getRepository(Schedule::class)->find($overlay->getId()), 'le plan secondaire survit tant que rien n\'est confirmé');
    }

    public function testChoosingAnotherSeasonVersionDestroysTheWholePlanOfAPeriodToCome(): void
    {
        // « Le planning de saison est notre base, donc on supprime TOUS les plannings
        // overlay ou holidays qui sont à venir. Il faudra les recommencer. Je supprime
        // les plannings et donc les versions liées » (décision fondateur 2026-07-24).
        //
        // Le cas qui échappait entièrement à l'ancienne garde : une période ADAPTÉE mais
        // JAMAIS GÉNÉRÉE. Depuis #8 son plan naît du geste et possède aussitôt sa grille
        // (copie du modèle de saison) ; comme il ne pointe aucune version, la garde —
        // keyée sur chosenScheduleId — ne le voyait pas. Elle laissait donc vivre, sans
        // le dire, la copie d'un socle qui n'existe plus, et la période gardait ses
        // réglages en repartant d'une grille périmée.
        [$user, $club, $season] = $this->seed('VAL12');
        $v1 = $this->createSchedule($season, ScheduleStatus::COMPLETED);
        $this->choosePlanVersion($v1);

        $venue = new Venue;
        $venue->setClubId($club->getId());
        $venue->setSeasonId($season->getId());
        $venue->setName('Barros');
        $venue->setCanSplit(false);
        $venue->setSource('manual');
        $this->em->persist($venue);
        $seasonal = $this->slot($club, $season, $venue->getId(), null);
        $this->em->flush();

        $entry = (new CalendarEntry)
            ->setClubId($club->getId())->setSeasonId($season->getId())
            ->setKind(CalendarEntryKind::PERIOD)->setPeriodType(CalendarEntryPeriodType::HOLIDAY)->setTitle('Toussaint')
            ->setStartDate(new DateTimeImmutable('+1 month'))->setEndDate(new DateTimeImmutable('+1 month +7 days'));
        $this->em->persist($entry);
        $this->em->flush();

        // Le geste « Adapter » : le plan naît AVEC sa grille, sans aucune version.
        $planId = self::getContainer()->get(SchedulePlanProvisioner::class)->provisionPeriodPlan($entry->getId());
        self::assertNotNull($planId);
        $mode = new VenuePeriodOverride;
        $mode->setClubId($club->getId());
        $mode->setSeasonId($season->getId());
        $mode->setSchedulePlanId($planId);
        $mode->setVenueId($venue->getId());
        $mode->setMode(VenuePeriodMode::DISABLED);
        $this->em->persist($mode);
        $this->em->flush();
        $this->em->clear();
        self::assertCount(1, $this->em->getRepository(VenueTrainingSlot::class)->findBy(['schedulePlanId' => $planId]), 'la période est née avec sa copie de la grille de saison');

        $v2 = $this->createSchedule($season, ScheduleStatus::COMPLETED);
        $jwt = self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
        $auth = ['HTTP_AUTHORIZATION' => 'Bearer ' . $jwt, 'CONTENT_TYPE' => 'application/json'];

        // Une période SANS version validée est bel et bien annoncée : sinon le
        // gestionnaire confirme une destruction dont il ignore la moitié.
        $this->client->request('POST', "/api/schedules/{$v2->getId()}/validate", [], [], $auth);
        self::assertResponseStatusCodeSame(409);
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('overlays_exist', $body['code'] ?? null);
        self::assertSame(['Toussaint'], array_column($body['overlays'] ?? [], 'title'));

        $this->client->request('POST', "/api/schedules/{$v2->getId()}/validate", [], [], $auth, json_encode(['confirmDeleteOverlays' => true], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();

        $this->em->clear();
        $this->scopeGucToClub($club->getId());
        $provisioner = self::getContainer()->get(SchedulePlanProvisioner::class);
        self::assertNull($provisioner->periodPlanId($entry->getId()), 'le PLAN de la période est détruit, pas seulement ses versions');
        self::assertSame([], $this->em->getRepository(VenueTrainingSlot::class)->findBy(['schedulePlanId' => $planId]), 'sa grille copiée part avec lui');
        self::assertSame([], $this->em->getRepository(VenuePeriodOverride::class)->findBy(['schedulePlanId' => $planId]), 'ses réglages de gymnase aussi');
        // Ce qui SURVIT : le modèle de saison (la base, qu'on vient justement de changer)
        // et l'entrée au calendrier — la période retombe « à traiter », à refaire.
        self::assertNotNull($this->em->getRepository(VenueTrainingSlot::class)->find($seasonal), 'le créneau de SAISON est intact');
        self::assertNotNull($this->em->getRepository(CalendarEntry::class)->find($entry->getId()), 'la période reste au calendrier, à refaire');
    }

    public function testChoosingAnotherSeasonVersionPurgesTheFutureHolidayCollecteAndKillsItsTokens(): void
    {
        // §7.1 planning lifecycle — Q8bis (P2-63) : déplacer le socle emporte AUSSI la collecte
        // de doléances et les doléances des vacances ENTIÈREMENT à venir (campagne → jetons par FK
        // cascade), MÊME pivot que les plannings de période (startDate > today). Une vacance DÉJÀ
        // COMMENCÉE garde sa collecte. Le lien coach purgé devient un 404 BYTE-IDENTIQUE à un
        // jeton inconnu (anti-énumération préservée).
        [$user, $club, $season] = $this->seed('VAL14');
        $v1 = $this->createSchedule($season, ScheduleStatus::COMPLETED);
        $this->choosePlanVersion($v1);

        // Vacances À VENIR, avec un planning de période (overlay) → comptées par la garde.
        $future = (new CalendarEntry)
            ->setClubId($club->getId())->setSeasonId($season->getId())
            ->setKind(CalendarEntryKind::PERIOD)->setPeriodType(CalendarEntryPeriodType::HOLIDAY)->setTitle('Toussaint')
            ->setStartDate(new DateTimeImmutable('+1 month'))->setEndDate(new DateTimeImmutable('+1 month +7 days'));
        $this->em->persist($future);
        $this->em->flush();
        $overlay = $this->createSchedule($season, ScheduleStatus::COMPLETED, $future->getId());
        $this->choosePlanVersion($overlay);

        // Vacances DÉJÀ COMMENCÉES (startDate ≤ today) : leur collecte doit SURVIVRE.
        $started = (new CalendarEntry)
            ->setClubId($club->getId())->setSeasonId($season->getId())
            ->setKind(CalendarEntryKind::PERIOD)->setPeriodType(CalendarEntryPeriodType::HOLIDAY)->setTitle('Vacances en cours')
            ->setStartDate(new DateTimeImmutable('-2 days'))->setEndDate(new DateTimeImmutable('+5 days'));
        $this->em->persist($started);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());
        $futureToken = $this->seedCollecte($club, $season, $future->getId(), 2); // 2 doléances à venir
        $startedToken = $this->seedCollecte($club, $season, $started->getId(), 1); // 1 doléance déjà commencée

        $v2 = $this->createSchedule($season, ScheduleStatus::COMPLETED);
        $jwt = self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
        $auth = ['HTTP_AUTHORIZATION' => 'Bearer ' . $jwt, 'CONTENT_TYPE' => 'application/json'];

        // Sans confirmation → 409 qui ANNONCE les doléances à venir (2), jamais celles déjà
        // commencées. Rien n'est écrit.
        $this->client->request('POST', "/api/schedules/{$v2->getId()}/validate", [], [], $auth);
        self::assertResponseStatusCodeSame(409);
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('overlays_exist', $body['code'] ?? null);
        self::assertSame(2, $body['coachWishCount'] ?? null, 'le 409 annonce les doléances des vacances À VENIR seulement');

        // 404 d'un jeton INCONNU (référence anti-énumération), capturé AVANT la purge.
        $this->client->request('GET', '/api/coach-wishes/public/' . str_repeat('a', 64));
        self::assertResponseStatusCodeSame(404);
        $unknown404 = (string) $this->client->getResponse()->getContent();

        // Avec confirmation → la bascule détruit l'overlay ET purge la collecte à venir.
        $this->client->request('POST', "/api/schedules/{$v2->getId()}/validate", [], [], $auth, json_encode(['confirmDeleteOverlays' => true], \JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();

        $this->em->clear();
        $this->scopeGucToClub($club->getId());
        // Les vacances À VENIR : campagne, doléances, mutualisation ET jetons (FK cascade) partis.
        self::assertCount(0, $this->em->getRepository(CoachWishCampaign::class)->findBy(['calendarEntryId' => $future->getId()]), 'la campagne des vacances à venir est supprimée');
        self::assertCount(0, $this->em->getRepository(CoachWish::class)->findBy(['calendarEntryId' => $future->getId()]), 'les doléances des vacances à venir sont supprimées');
        self::assertCount(0, $this->em->getRepository(CoachWishMutualization::class)->findBy(['calendarEntryId' => $future->getId()]), 'les mutualisations des vacances à venir sont supprimées');
        self::assertCount(0, $this->em->getRepository(CoachWishToken::class)->findBy(['campaignId' => $futureToken['campaignId']]), 'les jetons partent par FK cascade avec la campagne');
        // Les vacances DÉJÀ COMMENCÉES gardent leur collecte.
        self::assertCount(1, $this->em->getRepository(CoachWishCampaign::class)->findBy(['calendarEntryId' => $started->getId()]), 'la collecte d\'une vacance déjà commencée survit');
        self::assertCount(1, $this->em->getRepository(CoachWish::class)->findBy(['calendarEntryId' => $started->getId()]), 'ses doléances survivent');

        // Le lien coach purgé est un 404 BYTE-IDENTIQUE à un jeton inconnu.
        $this->client->request('GET', '/api/coach-wishes/public/' . $futureToken['token']);
        self::assertResponseStatusCodeSame(404);
        self::assertSame($unknown404, (string) $this->client->getResponse()->getContent(), 'le lien d\'un jeton purgé est un 404 identique à un inconnu');
    }

    public function testAPeriodAlreadyUnderWaySurvivesTheSeasonChange(): void
    {
        // « Rien du passé, rien de ce qui est en cours » (décision fondateur 2026-07-16,
        // docs/architecture/adr-0002-pattern-plan.md, décision fermée etat-des-lieux.md §2) :
        // le pivot est la date de DÉBUT,
        // pas celle de fin. Une période COMMENCÉE mais pas finie est déjà annoncée aux
        // coachs et à moitié jouée — la détruire au milieu coûterait plus que de la
        // laisser finir sur l'ancien socle. Le cas se produit dès qu'on ajuste la saison
        // pendant des vacances, ce qui est précisément quand on a le temps de le faire.
        [$user, , $season] = $this->seed('VAL13');
        $v1 = $this->createSchedule($season, ScheduleStatus::COMPLETED);
        $this->choosePlanVersion($v1);
        $entry = (new CalendarEntry)
            ->setClubId($season->getClubId())->setSeasonId($season->getId())
            ->setKind(CalendarEntryKind::PERIOD)->setPeriodType(CalendarEntryPeriodType::HOLIDAY)->setTitle('Toussaint en cours')
            ->setStartDate(new DateTimeImmutable('-2 days'))->setEndDate(new DateTimeImmutable('+5 days'));
        $this->em->persist($entry);
        $this->em->flush();
        $overlay = $this->createSchedule($season, ScheduleStatus::COMPLETED, $entry->getId());
        $this->choosePlanVersion($overlay);
        $planId = $this->em->getRepository(Schedule::class)->find($overlay->getId())?->getSchedulePlanId();

        $v2 = $this->createSchedule($season, ScheduleStatus::COMPLETED);
        $jwt = self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
        // Aucune confirmation demandée : il n'y a rien à détruire, donc rien à annoncer.
        $this->client->request('POST', "/api/schedules/{$v2->getId()}/validate", [], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $jwt, 'CONTENT_TYPE' => 'application/json']);
        self::assertResponseIsSuccessful();

        $this->em->clear();
        self::assertNotNull($this->em->getRepository(Schedule::class)->find($overlay->getId()), 'la période en cours garde son planning');
        self::assertSame($overlay->getId(), self::getContainer()->get(SchedulePlanProvisioner::class)->chosenOfPeriodPlan($entry->getId()), 'et il reste en vigueur');
        self::assertNotNull($planId);
    }

    public function testFirstValidationWithoutOverlaysNeedsNoConfirmation(): void
    {
        // Le pendant du test ci-dessus : sans plan secondaire, la garde ne coûte rien.
        // Sinon on aurait remplacé un trou par une demande de confirmation absurde à
        // la toute première validation d'un club.
        [$user, , $season] = $this->seed('VAL11');
        $v1 = $this->createSchedule($season, ScheduleStatus::COMPLETED);

        $this->client->loginUser($user);
        $this->client->request('POST', "/api/schedules/{$v1->getId()}/validate");

        self::assertResponseIsSuccessful();
        self::assertSame($v1->getId(), $this->chosenPlanVersion($season));
    }

    public function testValidatingAVersionThatVanishedMeanwhileIsRefused(): void
    {
        // LA course qui m'a échappé deux fois. Deux onglets valident V1 et V2 : la
        // première supprime V2 (sa sœur), la seconde arrive avec une entité V2 chargée
        // AVANT le verrou. Si elle ne relit pas la BASE, elle croit V2 COMPLETED,
        // pointe le plan sur une ligne morte (colonne guid nue, aucune FK) et supprime
        // V1 : zéro version, pointeur fantôme, club renvoyé au wizard.
        //
        // On simule l'issue de la course : la version disparaît de la base pendant que
        // la requête la tient encore en mémoire.
        [$user, , $season] = $this->seed('VAL12');
        $v1 = $this->createSchedule($season, ScheduleStatus::COMPLETED);
        $v2 = $this->createSchedule($season, ScheduleStatus::COMPLETED);
        $v2Id = $v2->getId();

        // Suppression HORS ORM : l'identity map garde V2, comme la requête concurrente.
        $this->em->getConnection()->executeStatement('DELETE FROM schedule WHERE id = :id', ['id' => $v2Id]);

        $this->client->loginUser($user);
        $this->client->request('POST', "/api/schedules/{$v2Id}/validate");

        self::assertResponseStatusCodeSame(409, 'une version disparue ne peut pas être choisie');
        $this->em->clear();
        self::assertNull($this->chosenPlanVersion($season), 'le plan ne pointe pas une ligne morte');
        self::assertNotNull($this->em->getRepository(Schedule::class)->find($v1->getId()), 'et la survivante n\'est pas emportée');
    }

    public function testNonCompletedScheduleCannotBeValidated(): void
    {
        [$user, , $season] = $this->seed('VAL2');
        $schedule = $this->createSchedule($season, ScheduleStatus::DRAFT);

        $this->client->loginUser($user);
        $this->client->request('POST', "/api/schedules/{$schedule->getId()}/validate");

        self::assertResponseStatusCodeSame(409);
    }

    public function testForeignScheduleIsNotAccessible(): void
    {
        [$user] = $this->seed('VAL3');
        [, , $otherSeason] = $this->seed('VAL4');
        $foreign = $this->createSchedule($otherSeason, ScheduleStatus::COMPLETED);

        $this->client->loginUser($user);
        $this->client->request('POST', "/api/schedules/{$foreign->getId()}/validate");

        // The controller guard rejects a schedule from another club (the caller's
        // club is resolved from the JWT); RLS is a second line in production.
        self::assertResponseStatusCodeSame(403);
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->hasher = $container->get('security.user_password_hasher');
    }

    /**
     * Sème une campagne de collecte sur une MÈRE de vacances : coach + campagne + jeton +
     * `$wishCount` doléances + 1 mutualisation. Rend l'id de la campagne et le jeton (clair).
     * Le GUC doit être posé sur le club (RLS) par l'appelant.
     *
     * @return array{campaignId: string, token: string}
     */
    private function seedCollecte(Club $club, Season $season, string $motherId, int $wishCount): array
    {
        $coach = (new Coach)->setClubId($club->getId())->setSeasonId($season->getId())->setFirstName('Maxime')->setLastName('Durand');
        $this->em->persist($coach);

        $campaign = (new CoachWishCampaign)->setClubId($club->getId())->setSeasonId($season->getId())
            ->setCalendarEntryId($motherId)->setDeadline(new DateTimeImmutable('+1 year'))
            ->setWeeks(['2099-01-05'])->setTeamIds([]);
        $this->em->persist($campaign);

        $token = (new CoachWishToken)->setCampaignId($campaign->getId())->setCoachId($coach->getId())->setClubId($club->getId());
        $this->em->persist($token);

        for ($i = 0; $i < $wishCount; ++$i) {
            $wish = (new CoachWish)->setCalendarEntryId($motherId)->setTeamId($this->uuid())
                ->setWeekStart(new DateTimeImmutable('2099-01-05 00:00:00'))->setCoachId($coach->getId())->setSlotsWanted(2);
            $wish->setClubId($club->getId());
            $wish->setSeasonId($season->getId());
            $this->em->persist($wish);
        }

        $mut = (new CoachWishMutualization)->setCalendarEntryId($motherId)->setTeamId($this->uuid())
            ->setCoachId($coach->getId())->setPartnerTeamIds([$this->uuid()])->setSharedSlots(1);
        $mut->setClubId($club->getId());
        $mut->setSeasonId($season->getId());
        $this->em->persist($mut);

        $this->em->flush();

        return ['campaignId' => $campaign->getId(), 'token' => $token->getToken()];
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    private function slot(Club $club, Season $season, string $venueId, ?string $schedulePlanId): string
    {
        $slot = new VenueTrainingSlot;
        $slot->setClubId($club->getId());
        $slot->setSeasonId($season->getId());
        $slot->setVenueId($venueId);
        $slot->setDayOfWeek(1);
        $slot->setStartTime(new DateTimeImmutable('18:00'));
        $slot->setDurationMinutes(90);
        $slot->setCapacity(1);
        $slot->setSchedulePlanId($schedulePlanId);
        $this->em->persist($slot);

        return $slot->getId();
    }

    /**
     * @return array{0: User, 1: Club, 2: Season}
     */
    private function seed(string $tag): array
    {
        $uid = uniqid('', true);

        $club = new Club;
        $club->setName('Club ' . $tag);
        $club->setSlug('club-' . $tag . '-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode($tag . strtoupper(substr(md5($uid), 0, 8)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('user-' . $tag . '-' . $uid . '@test.com');
        $user->setFirstName('B');
        $user->setLastName('L3');
        $user->setPasswordHash($this->hasher->hashPassword($user, 'pass'));
        $this->em->persist($user);

        $this->em->flush();

        $this->scopeGucToClub($club->getId());

        $cu = new ClubUser;
        $cu->setClubId($club->getId());
        $cu->setUserId($user->getId());
        $cu->setRole('admin');
        $cu->setIsActive(true);
        $this->em->persist($cu);

        $season = new Season;
        $season->setClubId($club->getId());
        $season->setName('2025-2026');
        $season->setStartDate(new DateTimeImmutable('2025-09-01'));
        $season->setEndDate(new DateTimeImmutable('2026-06-30'));
        $season->setStatus(SeasonStatus::ACTIVE);
        $this->em->persist($season);

        $this->em->flush();

        return [$user, $club, $season];
    }

    private function createSchedule(Season $season, ScheduleStatus $status, ?string $calendarEntryId = null): Schedule
    {
        $schedule = new Schedule;
        $schedule->setClubId($season->getClubId());
        $schedule->setSeasonId($season->getId());
        $schedule->setName('Plan');
        $schedule->setStatus($status);
        // Prod links every version at creation ; sans ça, depuis C4 la validation
        // lèverait sur une version sans plan (periodEntryIdOf). linkSeededSchedule
        // persiste et numérote lui-même — la Schedule ne doit PAS être flushée avant.
        $this->linkSeededSchedule($schedule, $calendarEntryId);

        return $schedule;
    }
}
