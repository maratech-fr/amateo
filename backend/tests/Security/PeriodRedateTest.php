<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\CalendarEntry;
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
use App\Enum\ScheduleStatus;
use App\Enum\SeasonStatus;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * NR — D3 v1 (décision fondateur 2026-09-04), axe *planning lifecycle* : RE-DATER UNE RACINE
 * DE FERMETURE À PLAN, « d'un bloc », dans les deux sens, sans perdre le plan.
 *
 * Le plan de période est un gabarit hebdo SANS dates + une fenêtre ; re-dater = déplacer deux
 * dates, rien n'orpheline. Ce test verrouille :
 *  a/b. étendre ET rétrécir une racine CLOSURE à plan → 200 ; le plan et ses versions SURVIVENT,
 *       la fenêtre du `schedule_plan` se resynchronise, la contrainte `venue_closed` née du même
 *       geste (config == ancienne fenêtre) suit, une fermeture saisie plus finement reste intacte ;
 *  c.   étendre sur une fenêtre qu'un AUTRE plan gouverne → 409 NOMMANT ce plan ;
 *  d.   une racine HOLIDAY (liée au référentiel) garde sa fenêtre GELÉE (422) ;
 *  e.   une mère découpée en semaines garde sa fenêtre GELÉE (422) ;
 *  f.   une semaine-enfant garde sa fenêtre GELÉE (422) ;
 *  g.   le suffixe de fenêtre du titre se recale (convention « — … »), un titre libre reste intact ;
 *  h.   les versions COMPLETED sont marquées à régénérer (`resourcesChangedSinceGeneration`) ;
 *  i.   changer le type ou le kind reste refusé (422), dates ou pas ;
 *  j.   le champ servi `redatable` reflète EXACTEMENT « racine de fermeture à plan » (vrai après
 *       adaptation, faux sans plan / pour une vacance / pour une mère découpée) ;
 *  k.   fin avant début → 422 (même maison qu'à la création, CalendarEntryInput::validateShape) ;
 *  l.   fenêtre re-datée hors de la saison → 422 parlant, la période reste intacte.
 */
#[Group('phase1')]
#[Group('integration')]
final class PeriodRedateTest extends WebTestCase
{
    use TenantGucTrait;

    private const string VENUE_ID = '77777777-7777-4777-8777-777777777777';

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    public function testExtendingAClosureRootMovesPlanConstraintAndKeepsVersions(): void
    {
        [$user, $club, $season] = $this->createClubWithSeason();
        $entryId = $this->postPeriod($user, 'closure', 'Barros en travaux', '2026-05-04', '2026-05-10');
        $planId = $this->adaptPeriod($user, $entryId);
        $versionId = $this->seedCompletedVersion($club, $season, $planId);
        // Le venue_closed né du geste (config == fenêtre de l'entrée) ET une fermeture plus fine.
        $pairedId = $this->seedDatedClosure($club, $season, $entryId, 'Barros fermé', '2026-05-04', '2026-05-10');
        $fineId = $this->seedDatedClosure($club, $season, $entryId, 'Barros fermé le mardi', '2026-05-05', '2026-05-06');

        // Étendre la fin (le début ne bouge pas).
        $this->put($user, $entryId, ['kind' => 'period', 'periodType' => 'closure', 'title' => 'Barros en travaux', 'startDate' => '2026-05-04', 'endDate' => '2026-05-17']);
        self::assertResponseIsSuccessful();

        $this->scopeGucToClub($club->getId());
        $this->em->clear();

        // Le plan et sa version survivent, et la fenêtre du plan a suivi.
        $plan = $this->em->getRepository(SchedulePlan::class)->find($planId);
        self::assertInstanceOf(SchedulePlan::class, $plan, 'le plan survit à un re-datage');
        self::assertSame('2026-05-04', $plan->getStartDate()->format('Y-m-d'));
        self::assertSame('2026-05-17', $plan->getEndDate()->format('Y-m-d'), 'la fenêtre du plan se resynchronise');
        $version = $this->em->getRepository(Schedule::class)->find($versionId);
        self::assertInstanceOf(Schedule::class, $version, 'la version survit');
        self::assertTrue($version->isResourcesChangedSinceGeneration(), 'la version est marquée à régénérer (h)');

        // La contrainte appariée suit ; la fine reste intacte.
        $paired = $this->em->getRepository(Constraint::class)->find($pairedId);
        self::assertInstanceOf(Constraint::class, $paired);
        self::assertSame(['type' => 'venue_closed', 'startDate' => '2026-05-04', 'endDate' => '2026-05-17'], $paired->getConfig(), 'le venue_closed apparié suit la fenêtre');
        $fine = $this->em->getRepository(Constraint::class)->find($fineId);
        self::assertInstanceOf(Constraint::class, $fine);
        self::assertSame(['type' => 'venue_closed', 'startDate' => '2026-05-05', 'endDate' => '2026-05-06'], $fine->getConfig(), 'une fermeture saisie plus finement reste intouchée');
    }

    public function testShrinkingAClosureRootMovesPlanAndConstraint(): void
    {
        [$user, $club, $season] = $this->createClubWithSeason();
        $entryId = $this->postPeriod($user, 'closure', 'Barros en travaux', '2026-05-04', '2026-05-17');
        $planId = $this->adaptPeriod($user, $entryId);
        $pairedId = $this->seedDatedClosure($club, $season, $entryId, 'Barros fermé', '2026-05-04', '2026-05-17');

        // Rétrécir (le début ne bouge pas, la fin recule).
        $this->put($user, $entryId, ['kind' => 'period', 'periodType' => 'closure', 'title' => 'Barros en travaux', 'startDate' => '2026-05-04', 'endDate' => '2026-05-10']);
        self::assertResponseIsSuccessful();

        $this->scopeGucToClub($club->getId());
        $this->em->clear();
        $plan = $this->em->getRepository(SchedulePlan::class)->find($planId);
        self::assertInstanceOf(SchedulePlan::class, $plan);
        self::assertSame('2026-05-10', $plan->getEndDate()->format('Y-m-d'), 'la fenêtre du plan rétrécit avec l’entrée');
        $paired = $this->em->getRepository(Constraint::class)->find($pairedId);
        self::assertInstanceOf(Constraint::class, $paired);
        self::assertSame('2026-05-10', $paired->getConfig()['endDate'], 'le venue_closed apparié rétrécit lui aussi');
    }

    public function testExtendingOntoAWindowAnotherPlanGovernsIsRefused(): void
    {
        [$user, $club, $season] = $this->createClubWithSeason();
        $entryA = $this->postPeriod($user, 'closure', 'Barros en travaux', '2026-05-04', '2026-05-10');
        $this->adaptPeriod($user, $entryA);
        // Une AUTRE période à plan, plus loin dans la saison.
        $entryB = $this->postPeriod($user, 'closure', 'Colombier fermé', '2026-05-18', '2026-05-24');
        $this->adaptPeriod($user, $entryB);

        // Étendre A jusqu'à recouvrir B → 409 nommant B.
        $this->put($user, $entryA, ['kind' => 'period', 'periodType' => 'closure', 'title' => 'Barros en travaux', 'startDate' => '2026-05-04', 'endDate' => '2026-05-24']);
        self::assertResponseStatusCodeSame(409);
        // Le corps JSON échappe l'accent (é) — on cherche sur l'ASCII (piège error-copy.md).
        self::assertStringContainsString('Colombier', (string) $this->client->getResponse()->getContent(), 'le 409 nomme le plan gouvernant');

        // A n'a pas bougé.
        $this->scopeGucToClub($club->getId());
        $this->em->clear();
        $a = $this->em->getRepository(CalendarEntry::class)->find($entryA);
        self::assertInstanceOf(CalendarEntry::class, $a);
        self::assertSame('2026-05-10', $a->getEndDate()->format('Y-m-d'), 'le refus laisse la période intacte');
        // Garder $season référencé pour l'analyse statique (setup partagé).
        self::assertNotNull($season->getId());
    }

    public function testHolidayRootKeepsItsWindowFrozen(): void
    {
        [$user, $club, $season] = $this->createClubWithSeason();
        $entryId = $this->postPeriod($user, 'holiday', 'Vacances de printemps', '2026-05-04', '2026-05-10');
        $this->adaptPeriod($user, $entryId);

        $this->put($user, $entryId, ['kind' => 'period', 'periodType' => 'holiday', 'title' => 'Vacances de printemps', 'startDate' => '2026-05-04', 'endDate' => '2026-05-17']);
        self::assertResponseStatusCodeSame(422, 'une racine de vacances garde sa fenêtre gelée');
        self::assertStringContainsString('figés', (string) $this->client->getResponse()->getContent());
        self::assertNotNull($club->getId());
        self::assertNotNull($season->getId());
    }

    public function testSplitMotherKeepsItsWindowFrozen(): void
    {
        [$user] = $this->createClubWithSeason();
        $motherId = $this->postPeriod($user, 'closure', 'Barros en travaux', '2026-05-04', '2026-05-17');
        // La découper en une semaine-enfant → la mère devient une mère découpée (gel d'identité).
        $this->postWeekChild($user, $motherId, 'closure', 'Semaine du 4 mai', '2026-05-04', '2026-05-10');

        $this->put($user, $motherId, ['kind' => 'period', 'periodType' => 'closure', 'title' => 'Barros en travaux', 'startDate' => '2026-05-04', 'endDate' => '2026-05-24']);
        self::assertResponseStatusCodeSame(422, 'une mère découpée garde sa fenêtre gelée');
        self::assertStringContainsString('figés', (string) $this->client->getResponse()->getContent());
    }

    public function testWeekChildKeepsItsWindowFrozen(): void
    {
        [$user] = $this->createClubWithSeason();
        $motherId = $this->postPeriod($user, 'closure', 'Barros en travaux', '2026-05-04', '2026-05-17');
        $childId = $this->postWeekChild($user, $motherId, 'closure', 'Semaine du 4 mai', '2026-05-04', '2026-05-10');

        $this->put($user, $childId, ['kind' => 'period', 'periodType' => 'closure', 'title' => 'Semaine du 4 mai', 'startDate' => '2026-05-11', 'endDate' => '2026-05-17']);
        self::assertResponseStatusCodeSame(422, 'une semaine-enfant garde sa fenêtre gelée');
        self::assertStringContainsString('figés', (string) $this->client->getResponse()->getContent());
    }

    public function testTitleWindowSuffixIsRecaledButAFreeTitleIsUntouched(): void
    {
        [$user, $club] = $this->createClubWithSeason();

        // Titre PORTANT le suffixe de fenêtre (convention) : le plan naît nommé du titre.
        $suffixedTitle = 'Barros en travaux — Semaine du 4 mai 2026';
        $entryId = $this->postPeriod($user, 'closure', $suffixedTitle, '2026-05-04', '2026-05-10');
        $planId = $this->adaptPeriod($user, $entryId);

        // Re-dater d'une semaine (le client renvoie le MÊME titre, encore suffixé de l'ancienne fenêtre).
        $this->put($user, $entryId, ['kind' => 'period', 'periodType' => 'closure', 'title' => $suffixedTitle, 'startDate' => '2026-05-11', 'endDate' => '2026-05-17']);
        self::assertResponseIsSuccessful();

        $this->scopeGucToClub($club->getId());
        $this->em->clear();
        $entry = $this->em->getRepository(CalendarEntry::class)->find($entryId);
        self::assertInstanceOf(CalendarEntry::class, $entry);
        self::assertSame('Barros en travaux — Semaine du 11 mai 2026', $entry->getTitle(), 'le suffixe de fenêtre du titre se recale');
        $plan = $this->em->getRepository(SchedulePlan::class)->find($planId);
        self::assertInstanceOf(SchedulePlan::class, $plan);
        self::assertSame('Barros en travaux — Semaine du 11 mai 2026', $plan->getName(), 'le nom du plan, encore égal au titre, suit');

        // Titre LIBRE (sans suffixe) : intouché par le re-datage. Fenêtre distincte du premier
        // (re-daté en 11→17 mai) pour ne pas retomber sur la garde d'unicité de fenêtre.
        $freeId = $this->postPeriod($user, 'closure', 'Gym en travaux', '2026-05-04', '2026-05-10');
        $freePlanId = $this->adaptPeriod($user, $freeId);
        $this->put($user, $freeId, ['kind' => 'period', 'periodType' => 'closure', 'title' => 'Gym en travaux', 'startDate' => '2026-05-25', 'endDate' => '2026-05-31']);
        self::assertResponseIsSuccessful();

        $this->scopeGucToClub($club->getId());
        $this->em->clear();
        $free = $this->em->getRepository(CalendarEntry::class)->find($freeId);
        self::assertInstanceOf(CalendarEntry::class, $free);
        self::assertSame('Gym en travaux', $free->getTitle(), 'un titre sans suffixe de fenêtre reste souverain');
        $freePlan = $this->em->getRepository(SchedulePlan::class)->find($freePlanId);
        self::assertInstanceOf(SchedulePlan::class, $freePlan);
        self::assertSame('Gym en travaux', $freePlan->getName());
    }

    public function testChangingKindOrTypeStaysRefused(): void
    {
        [$user] = $this->createClubWithSeason();
        $entryId = $this->postPeriod($user, 'closure', 'Barros en travaux', '2026-05-04', '2026-05-10');
        $this->adaptPeriod($user, $entryId);

        // Changer le type (dates inchangées) → 422.
        $this->put($user, $entryId, ['kind' => 'period', 'periodType' => 'holiday', 'title' => 'Barros en travaux', 'startDate' => '2026-05-04', 'endDate' => '2026-05-10']);
        self::assertResponseStatusCodeSame(422, 'changer le type reste refusé');
        self::assertStringContainsString('type', (string) $this->client->getResponse()->getContent());

        // Convertir en événement → 422.
        $this->put($user, $entryId, ['kind' => 'event', 'title' => 'Barros en travaux', 'startDate' => '2026-05-04', 'endDate' => '2026-05-10']);
        self::assertResponseStatusCodeSame(422, 'convertir en événement reste refusé');
    }

    public function testRedatableFieldReflectsExactlyAClosureRootWithAPlan(): void
    {
        [$user] = $this->createClubWithSeason();

        // Une racine de fermeture SANS plan : le simple fait déclaré — pas encore re-datable.
        $noPlanId = $this->postPeriod($user, 'closure', 'Barros en travaux', '2026-05-04', '2026-05-10');
        self::assertFalse($this->getEntry($user, $noPlanId)['redatable'], 'une fermeture sans plan n’est pas re-datable');

        // La même, une fois adaptée (elle porte un plan) : re-datable.
        $this->adaptPeriod($user, $noPlanId);
        self::assertTrue($this->getEntry($user, $noPlanId)['redatable'], 'une racine de fermeture à plan est re-datable');

        // Une racine de vacances à plan (liée au référentiel) : fenêtre gelée, jamais re-datable.
        $holidayId = $this->postPeriod($user, 'holiday', 'Vacances de printemps', '2026-04-13', '2026-04-19');
        $this->adaptPeriod($user, $holidayId);
        self::assertFalse($this->getEntry($user, $holidayId)['redatable'], 'une racine de vacances n’est pas re-datable');

        // Une mère découpée en semaines : gelée par ses enfants, plus re-datable.
        $motherId = $this->postPeriod($user, 'closure', 'Colombier fermé', '2026-05-18', '2026-05-31');
        $this->postWeekChild($user, $motherId, 'closure', 'Semaine du 18 mai', '2026-05-18', '2026-05-24');
        self::assertFalse($this->getEntry($user, $motherId)['redatable'], 'une mère découpée n’est pas re-datable');
    }

    public function testRedatingWithEndBeforeStartIsRejected(): void
    {
        [$user] = $this->createClubWithSeason();
        $entryId = $this->postPeriod($user, 'closure', 'Barros en travaux', '2026-05-04', '2026-05-10');
        $this->adaptPeriod($user, $entryId);

        // Fin AVANT début → 422 (refusé aussi à la création, même maison : CalendarEntryInput::validateShape).
        $this->put($user, $entryId, ['kind' => 'period', 'periodType' => 'closure', 'title' => 'Barros en travaux', 'startDate' => '2026-05-17', 'endDate' => '2026-05-04']);
        self::assertResponseStatusCodeSame(422, 'fin avant début reste refusé au re-datage');
    }

    public function testRedatingOutsideTheSeasonWindowIsRejected(): void
    {
        [$user, $club] = $this->createClubWithSeason();
        // Saison : 2025-09-01 → 2026-06-30 (createClubWithSeason).
        $entryId = $this->postPeriod($user, 'closure', 'Barros en travaux', '2026-05-04', '2026-05-10');
        $this->adaptPeriod($user, $entryId);

        // Étendre la fin APRÈS la fin de saison → 422 parlant.
        $this->put($user, $entryId, ['kind' => 'period', 'periodType' => 'closure', 'title' => 'Barros en travaux', 'startDate' => '2026-05-04', 'endDate' => '2026-07-15']);
        self::assertResponseStatusCodeSame(422, 'une fenêtre hors saison est refusée');
        self::assertStringContainsString('saison', (string) $this->client->getResponse()->getContent());

        // La période n'a pas bougé.
        $this->scopeGucToClub($club->getId());
        $this->em->clear();
        $entry = $this->em->getRepository(CalendarEntry::class)->find($entryId);
        self::assertInstanceOf(CalendarEntry::class, $entry);
        self::assertSame('2026-05-10', $entry->getEndDate()->format('Y-m-d'), 'le refus laisse la période intacte');
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

    private function seedCompletedVersion(Club $club, Season $season, string $planId): string
    {
        $this->scopeGucToClub($club->getId());
        $version = new Schedule;
        $version->setClubId($club->getId());
        $version->setSeasonId($season->getId());
        $version->setSchedulePlanId($planId);
        $version->setName('V1');
        $version->setVersionNumber(1);
        $version->setStatus(ScheduleStatus::COMPLETED);
        $this->em->persist($version);
        $this->em->flush();

        return $version->getId();
    }

    /** @return string l'id de la contrainte datée `venue_closed` accrochée à l'entrée */
    private function seedDatedClosure(Club $club, Season $season, string $entryId, string $name, string $start, string $end): string
    {
        $this->scopeGucToClub($club->getId());
        $constraint = new Constraint;
        $constraint->setClubId($club->getId());
        $constraint->setSeasonId($season->getId());
        $constraint->setName($name);
        $constraint->setScope(ConstraintScope::FACILITY);
        $constraint->setFamily(ConstraintFamily::FACILITY);
        $constraint->setRuleType(ConstraintRuleType::HARD);
        $constraint->setScopeTargetId(self::VENUE_ID);
        $constraint->setConfig(['type' => 'venue_closed', 'startDate' => $start, 'endDate' => $end]);
        $constraint->setCalendarEntryId($entryId);
        $this->em->persist($constraint);
        $this->em->flush();

        return $constraint->getId();
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

    /** @param array<string, mixed> $body */
    private function put(User $user, string $entryId, array $body): void
    {
        $this->client->request('PUT', '/api/calendar_entries/' . $entryId, [], [], $this->authHeaders($user) + [
            'CONTENT_TYPE' => 'application/ld+json',
        ], json_encode($body, \JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> le corps JSON de GET /api/calendar_entries/{id} */
    private function getEntry(User $user, string $entryId): array
    {
        $this->client->request('GET', '/api/calendar_entries/' . $entryId, [], [], $this->authHeaders($user));
        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        return $payload;
    }

    /**
     * @return array{0: User, 1: Club, 2: Season}
     */
    private function createClubWithSeason(): array
    {
        $uid = uniqid('', true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $club = new Club;
        $club->setName('Club re-datage');
        $club->setSlug('club-redate-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $club->setFfbbClubCode('RDT' . strtoupper(substr(md5($uid), 0, 10)));
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('redate' . $uid . '@test.com');
        $user->setFirstName('Re');
        $user->setLastName('Date');
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

        // Saison fixe 2025-2026 (indépendante de l'horloge) — les fenêtres de mai 2026 y vivent.
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

    /**
     * @return array{HTTP_AUTHORIZATION: string}
     */
    private function authHeaders(User $user): array
    {
        // Membre d'un seul club : le résolveur de tenant le trouve depuis le JWT (patron
        // WeekChildEntryTest) — aucun header X-Club-Id (le front n'en envoie pas non plus).
        $token = self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);

        return ['HTTP_AUTHORIZATION' => 'Bearer ' . $token];
    }
}
