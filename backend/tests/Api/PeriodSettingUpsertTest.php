<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Constraint;
use App\Entity\Season;
use App\Entity\Team;
use App\Entity\User;
use App\Entity\VenueTrainingSlot;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\ConstraintFamily;
use App\Enum\ConstraintRuleType;
use App\Enum\ConstraintScope;
use App\Enum\SeasonStatus;
use App\Tests\ComposesPlanConstraintsTrait;
use App\Tests\ComposesPlanTeamsTrait;
use App\Tests\ComposesPlanVenuesTrait;
use App\Tests\CreatesPeopleAndPlacesTrait;
use App\Tests\ProvisionsPeriodPlanTrait;
use App\Tests\SeasonPlanOf;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Promesse (lot 4, PR 3a, ligne 23 du plan) : un réglage de période se publie comme une mise à jour. Chaque équipe a
 * déjà sa ligne dans le plan ; publier deux fois le réglage d'une équipe pour un plan la met à jour, avec le même code
 * de réponse, sans erreur — l'écran ne sait pas si une ligne « existe », il publie ce que le gestionnaire a réglé.
 * La liste rend une seule ligne pour le couple.
 *
 * Lot 4, PR 3b (ligne 28 du plan) : même règle pour le réglage d'un gymnase ; le republier sans changer son mode ne
 * rejoue pas l'effet de ce mode sur la grille de la période.
 *
 * Lot 4, PR 4 (ligne 34 du plan) : même règle pour la coche d'une règle de saison ; la publier égale au défaut de la
 * nature la retire de la liste des réglages.
 */
#[Group('integration')]
final class PeriodSettingUpsertTest extends WebTestCase
{
    use ComposesPlanConstraintsTrait;
    use ComposesPlanTeamsTrait;
    use ComposesPlanVenuesTrait;
    use CreatesPeopleAndPlacesTrait;
    use ProvisionsPeriodPlanTrait;
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    private User $user;

    private Season $season;

    private string $holiday;

    private string $teamId;

    private string $venueId;

    private string $clubId;

    /**
     * Le gymnase a sa ligne dans les vacances : publier deux fois son réglage la met à jour, même code de réponse, une
     * seule ligne listée. Republier le même mode (une grille ressaisie, « VIERGE ») ne revide pas la grille que le
     * gestionnaire vient d'y saisir.
     */
    public function testPublishingTheSameVenueSettingTwiceUpdatesIt(): void
    {
        $this->client->request('GET', '/api/venue_period_overrides?schedulePlanId=' . $this->holiday, [], [], $this->headers());
        self::assertResponseIsSuccessful();
        self::assertSame([], json_decode((string) $this->client->getResponse()->getContent(), true)['member'] ?? null, 'avant toute publication, aucun réglage de gymnase listé : la ligne au défaut n\'en est pas un');

        $first = $this->publishTo('venue_period_overrides', ['schedulePlanId' => $this->holiday, 'venueId' => $this->venueId, 'mode' => 'BLANK']);
        $handTyped = (new VenueTrainingSlot)->setClubId($this->clubId)->setSeasonId($this->season->getId())->setSchedulePlanId($this->holiday)
            ->setVenueId($this->venueId)->setDayOfWeek(3)->setStartTime(new DateTimeImmutable('10:00'))->setDurationMinutes(90)->setCapacity(1);
        $this->em->persist($handTyped);
        $this->em->flush();
        $second = $this->publishTo('venue_period_overrides', ['schedulePlanId' => $this->holiday, 'venueId' => $this->venueId, 'mode' => 'BLANK', 'dayOverrides' => ['6' => 'CLOSED']]);

        self::assertSame($first, $second, 'la seconde publication répond comme la première, sans erreur');
        self::assertSame(['participates' => true, 'closureLifted' => true, 'dayOverrides' => [6 => 'CLOSED']], $this->venueLineRow($this->holiday, $this->venueId), 'la ligne porte le dernier réglage publié');
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM venue_training_slot WHERE schedule_plan_id = ? AND venue_id = ?', [$this->holiday, $this->venueId]), 'le même mode republié ne revide pas la grille saisie à la main');

        $this->client->request('GET', '/api/venue_period_overrides?schedulePlanId=' . $this->holiday, [], [], $this->headers());
        self::assertResponseIsSuccessful();
        $members = json_decode((string) $this->client->getResponse()->getContent(), true)['member'] ?? [];
        self::assertIsArray($members);
        $mine = array_values(array_filter($members, fn (mixed $m): bool => \is_array($m) && $this->venueId === ($m['venueId'] ?? null)));
        self::assertCount(1, $mine, 'une seule ligne pour le couple (plan, gymnase)');
        self::assertSame(['BLANK', ['6' => 'CLOSED']], [$mine[0]['mode'] ?? null, $mine[0]['dayOverrides'] ?? null]);
    }

    /**
     * Abus : un réglage de gymnase publié sous la saison de la requête ne touche jamais le plan d'une AUTRE saison du
     * club (ici la saison passée, en lecture seule) : refusé, et la ligne de l'autre saison garde sa saison et ses
     * valeurs. Sans cela, la publication écrirait dans une saison en lecture seule et réécrirait la saison de la ligne
     * avec celle de la requête : la ligne sortirait de sa saison (la porte et la lecture d'un plan comptent par saison).
     */
    public function testAVenueSettingNeverReachesThePlanOfAnotherSeason(): void
    {
        $past = (new Season)->setClubId($this->clubId)->setName('2024-2025')
            ->setStartDate(new DateTimeImmutable('2024-09-01'))->setEndDate(new DateTimeImmutable('2025-06-30'))
            ->setStatus(SeasonStatus::ARCHIVED)->setTransitionData([]);
        $this->em->persist($past);
        $this->em->flush();
        SeasonPlanOf::id(self::getContainer(), $past->getId());
        $pastVenue = $this->newVenue($this->clubId, $past->getId(), 'Gymnase Nord');
        $this->em->flush();
        $entry = (new CalendarEntry)->setClubId($this->clubId)->setSeasonId($past->getId())
            ->setKind(CalendarEntryKind::PERIOD)->setPeriodType(CalendarEntryPeriodType::HOLIDAY)->setTitle('Noël passé')
            ->setStartDate(new DateTimeImmutable('2024-12-23'))->setEndDate(new DateTimeImmutable('2024-12-29'));
        $this->em->persist($entry);
        $pastHoliday = $this->planIdOf($entry);
        $before = $this->em->getConnection()->fetchAssociative('SELECT * FROM plan_venue WHERE schedule_plan_id = ? AND venue_id = ?', [$pastHoliday, $pastVenue->getId()]);
        self::assertIsArray($before, 'témoin : le gymnase de la saison passée a sa ligne dans ses vacances');
        self::assertSame($past->getId(), $before['season_id']);

        // La requête porte la saison en cours (`X-Season-Id`), le corps vise le plan de la saison passée.
        $this->client->request('POST', '/api/venue_period_overrides', [], [], $this->headers(), json_encode([
            'schedulePlanId' => $pastHoliday,
            'venueId' => $pastVenue->getId(),
            'mode' => 'DISABLED',
        ], \JSON_THROW_ON_ERROR));

        self::assertContains($this->client->getResponse()->getStatusCode(), [404, 409, 422], 'le plan d\'une autre saison est refusé : ' . $this->client->getResponse()->getContent());
        self::assertSame($before, $this->em->getConnection()->fetchAssociative('SELECT * FROM plan_venue WHERE schedule_plan_id = ? AND venue_id = ?', [$pastHoliday, $pastVenue->getId()]), 'la ligne de la saison passée n\'a pas bougé, sa saison comprise');
    }

    /**
     * Abus, jumeau du cas gymnase pour le réglage d'équipe (lot 4, PR 3a) : publié sous la saison de la requête, il ne
     * touche jamais le plan d'une AUTRE saison du club (la saison passée, en lecture seule) : refusé, et la ligne de
     * l'autre saison garde sa saison et ses valeurs.
     */
    public function testATeamSettingNeverReachesThePlanOfAnotherSeason(): void
    {
        $past = (new Season)->setClubId($this->clubId)->setName('2024-2025')
            ->setStartDate(new DateTimeImmutable('2024-09-01'))->setEndDate(new DateTimeImmutable('2025-06-30'))
            ->setStatus(SeasonStatus::ARCHIVED)->setTransitionData([]);
        $this->em->persist($past);
        $this->em->flush();
        SeasonPlanOf::id(self::getContainer(), $past->getId());
        $pastTeam = (new Team)->setClubId($this->clubId)->setSeasonId($past->getId())->setSportCategoryId('33333333-3333-3333-3333-333333333333')
            ->setPriorityTierId(1)->setName('SM1')->setIsActive(true);
        $this->em->persist($pastTeam);
        $this->em->flush();
        $entry = (new CalendarEntry)->setClubId($this->clubId)->setSeasonId($past->getId())
            ->setKind(CalendarEntryKind::PERIOD)->setPeriodType(CalendarEntryPeriodType::HOLIDAY)->setTitle('Noël passé')
            ->setStartDate(new DateTimeImmutable('2024-12-23'))->setEndDate(new DateTimeImmutable('2024-12-29'));
        $this->em->persist($entry);
        $pastHoliday = $this->planIdOf($entry);
        $this->layTeamLines($past->getId());
        $before = $this->em->getConnection()->fetchAssociative('SELECT * FROM plan_team WHERE schedule_plan_id = ? AND team_id = ?', [$pastHoliday, $pastTeam->getId()]);
        self::assertIsArray($before, 'témoin : l\'équipe de la saison passée a sa ligne dans ses vacances');
        self::assertSame($past->getId(), $before['season_id']);

        // La requête porte la saison en cours (`X-Season-Id`), le corps vise le plan de la saison passée.
        $this->client->request('POST', '/api/team_period_overrides', [], [], $this->headers(), json_encode([
            'schedulePlanId' => $pastHoliday,
            'teamId' => $pastTeam->getId(),
            'isActive' => false,
            'sessionsPerWeek' => 1,
        ], \JSON_THROW_ON_ERROR));

        self::assertContains($this->client->getResponse()->getStatusCode(), [404, 409, 422], 'le plan d\'une autre saison est refusé : ' . $this->client->getResponse()->getContent());
        self::assertSame($before, $this->em->getConnection()->fetchAssociative('SELECT * FROM plan_team WHERE schedule_plan_id = ? AND team_id = ?', [$pastHoliday, $pastTeam->getId()]), 'la ligne de la saison passée n\'a pas bougé, sa saison comprise');
    }

    /**
     * Lot 4, PR 4 (ligne 34 du plan) : la règle de club a sa ligne dans les vacances (cochée, le défaut). Publier deux
     * fois sa décoche la met à jour, même code de réponse, une seule ligne listée ; publier sa coche la remet au défaut
     * de la nature, et elle quitte la liste des réglages.
     */
    public function testPublishingTheSameRuleSettingTwiceUpdatesIt(): void
    {
        $ruleId = $this->clubRule();
        $this->client->request('GET', '/api/constraint_period_overrides?schedulePlanId=' . $this->holiday, [], [], $this->headers());
        self::assertResponseIsSuccessful();
        self::assertSame([], json_decode((string) $this->client->getResponse()->getContent(), true)['member'] ?? null, 'avant toute publication, aucune règle listée : la ligne au défaut n\'est pas un réglage');

        $first = $this->publishTo('constraint_period_overrides', ['schedulePlanId' => $this->holiday, 'constraintId' => $ruleId, 'isActive' => false]);
        $second = $this->publishTo('constraint_period_overrides', ['schedulePlanId' => $this->holiday, 'constraintId' => $ruleId, 'isActive' => false]);

        self::assertSame($first, $second, 'la seconde publication répond comme la première, sans erreur');
        self::assertFalse($this->ruleLineRow($this->holiday, $ruleId), 'la ligne porte la décoche publiée');
        $mine = $this->listedRuleSettings($ruleId);
        self::assertCount(1, $mine, 'une seule ligne pour le couple (plan, règle)');
        self::assertFalse($mine[0]['isActive'] ?? null);

        $this->publishTo('constraint_period_overrides', ['schedulePlanId' => $this->holiday, 'constraintId' => $ruleId, 'isActive' => true]);
        self::assertTrue($this->ruleLineRow($this->holiday, $ruleId), 'la coche publiée remet la ligne au défaut des vacances pour une règle de club');
        self::assertSame([], $this->listedRuleSettings($ruleId), 'au défaut, la règle quitte la liste des réglages');
        self::assertSame(1, $this->ruleLineCountOf($ruleId), 'toujours une seule ligne pour la règle');
    }

    /**
     * Abus, jumeau des cas équipe et gymnase pour la coche d'une règle (lot 4, PR 4) : publiée sous la saison de la
     * requête, elle ne touche jamais le plan d'une AUTRE saison du club (la saison passée, en lecture seule) : refusée, et
     * la ligne de l'autre saison garde sa saison et sa coche.
     */
    public function testARuleSettingNeverReachesThePlanOfAnotherSeason(): void
    {
        $past = (new Season)->setClubId($this->clubId)->setName('2024-2025')
            ->setStartDate(new DateTimeImmutable('2024-09-01'))->setEndDate(new DateTimeImmutable('2025-06-30'))
            ->setStatus(SeasonStatus::ARCHIVED)->setTransitionData([]);
        $this->em->persist($past);
        $this->em->flush();
        SeasonPlanOf::id(self::getContainer(), $past->getId());
        $pastRule = (new Constraint)->setClubId($this->clubId)->setSeasonId($past->getId())->setName('Pas le dimanche')
            ->setScope(ConstraintScope::CLUB)->setFamily(ConstraintFamily::DAY)->setRuleType(ConstraintRuleType::HARD)->setConfig(['forbiddenDays' => [7]]);
        $this->em->persist($pastRule);
        $entry = (new CalendarEntry)->setClubId($this->clubId)->setSeasonId($past->getId())
            ->setKind(CalendarEntryKind::PERIOD)->setPeriodType(CalendarEntryPeriodType::HOLIDAY)->setTitle('Noël passé')
            ->setStartDate(new DateTimeImmutable('2024-12-23'))->setEndDate(new DateTimeImmutable('2024-12-29'));
        $this->em->persist($entry);
        $pastHoliday = $this->planIdOf($entry);
        $this->layRuleLines($past->getId());
        $before = $this->em->getConnection()->fetchAssociative('SELECT * FROM plan_constraint WHERE schedule_plan_id = ? AND constraint_id = ?', [$pastHoliday, $pastRule->getId()]);
        self::assertIsArray($before, 'témoin : la règle de la saison passée a sa ligne dans ses vacances');
        self::assertSame($past->getId(), $before['season_id']);

        // La requête porte la saison en cours (`X-Season-Id`), le corps vise le plan de la saison passée.
        $this->client->request('POST', '/api/constraint_period_overrides', [], [], $this->headers(), json_encode([
            'schedulePlanId' => $pastHoliday,
            'constraintId' => $pastRule->getId(),
            'isActive' => false,
        ], \JSON_THROW_ON_ERROR));

        self::assertContains($this->client->getResponse()->getStatusCode(), [404, 409, 422], 'le plan d\'une autre saison est refusé : ' . $this->client->getResponse()->getContent());
        self::assertSame($before, $this->em->getConnection()->fetchAssociative('SELECT * FROM plan_constraint WHERE schedule_plan_id = ? AND constraint_id = ?', [$pastHoliday, $pastRule->getId()]), 'la ligne de la saison passée n\'a pas bougé, sa saison comprise');
    }

    public function testPublishingTheSameTeamSettingTwiceUpdatesIt(): void
    {
        // Une ligne égale au socle, sans origine, n'est pas un réglage : la liste ne la rend pas (§5 du plan).
        $this->client->request('GET', '/api/team_period_overrides?schedulePlanId=' . $this->holiday, [], [], $this->headers());
        self::assertResponseIsSuccessful();
        self::assertSame([], json_decode((string) $this->client->getResponse()->getContent(), true)['member'] ?? null, 'avant toute publication, aucun réglage listé');

        $first = $this->publish(['schedulePlanId' => $this->holiday, 'teamId' => $this->teamId, 'isActive' => true, 'sessionsPerWeek' => 1]);
        $second = $this->publish(['schedulePlanId' => $this->holiday, 'teamId' => $this->teamId, 'isActive' => true, 'sessionsPerWeek' => 1, 'maxSessionsPerDay' => 2]);

        self::assertSame($first, $second, 'la seconde publication répond comme la première, sans erreur');
        self::assertSame(['participates' => true, 'sessionsPerWeek' => 1, 'maxSessionsPerDay' => 2, 'source' => null], $this->lineRow($this->holiday, $this->teamId), 'la ligne porte le dernier réglage publié');

        $this->client->request('GET', '/api/team_period_overrides?schedulePlanId=' . $this->holiday, [], [], $this->headers());
        self::assertResponseIsSuccessful();
        $members = json_decode((string) $this->client->getResponse()->getContent(), true)['member'] ?? [];
        self::assertIsArray($members);
        $mine = array_values(array_filter($members, fn (mixed $m): bool => \is_array($m) && $this->teamId === ($m['teamId'] ?? null)));
        self::assertCount(1, $mine, 'une seule ligne pour le couple (plan, équipe)');
        self::assertSame([1, 2], [$mine[0]['sessionsPerWeek'] ?? null, $mine[0]['maxSessionsPerDay'] ?? null]);
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $uid = uniqid('', true);
        $hasher = self::getContainer()->get('security.user_password_hasher');
        $club = (new Club)->setName('Publication ' . $uid)->setSlug('publication-' . $uid)->setTimezone('Europe/Paris')->setLocale('fr')->setOnboardingCompleted(true);
        $this->em->persist($club);
        $this->user = (new User)->setEmail('publication' . $uid . '@test.com')->setFirstName('P')->setLastName('Ublication');
        $this->user->setPasswordHash($hasher->hashPassword($this->user, 'pass'));
        $this->em->persist($this->user);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());
        $this->em->persist((new ClubUser)->setClubId($club->getId())->setUserId($this->user->getId())->setRole('admin')->setIsActive(true));
        $this->season = (new Season)->setClubId($club->getId())->setName('2025-2026')
            ->setStartDate(new DateTimeImmutable('2025-09-01'))->setEndDate(new DateTimeImmutable('2026-06-30'))
            ->setStatus(SeasonStatus::ACTIVE)->setTransitionData([]);
        $this->em->persist($this->season);
        $this->em->flush();
        SeasonPlanOf::id(self::getContainer(), $this->season->getId());
        $team = (new Team)->setClubId($club->getId())->setSeasonId($this->season->getId())->setSportCategoryId('33333333-3333-3333-3333-333333333333')
            ->setPriorityTierId(1)->setName('SM1')->setIsActive(true);
        $this->em->persist($team);
        $this->em->flush();
        $this->teamId = $team->getId();
        $this->setSocleTeam($this->season->getId(), $this->teamId, 2);
        $this->clubId = $club->getId();
        $venue = $this->newVenue($club->getId(), $this->season->getId(), 'Gymnase Nord');
        $this->em->flush();
        $this->venueId = $venue->getId();
        $entry = (new CalendarEntry)->setClubId($club->getId())->setSeasonId($this->season->getId())
            ->setKind(CalendarEntryKind::PERIOD)->setPeriodType(CalendarEntryPeriodType::HOLIDAY)->setTitle('Noël')
            ->setStartDate(new DateTimeImmutable('2025-12-22'))->setEndDate(new DateTimeImmutable('2025-12-28'));
        $this->em->persist($entry);
        $this->holiday = $this->planIdOf($entry);
        $this->layTeamLines($this->season->getId());
    }

    /** Une règle de saison de tout le club, créée par le geste du gestionnaire ; rend son identifiant. */
    private function clubRule(): string
    {
        $this->client->request('POST', '/api/constraints', [], [], $this->headers(), json_encode([
            'name' => 'Toutes les équipes · pas le dimanche',
            'scope' => 'CLUB',
            'family' => 'DAY',
            'ruleType' => 'HARD',
            'config' => ['forbiddenDays' => [7]],
            'isActive' => true,
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201, (string) $this->client->getResponse()->getContent());
        $id = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR)['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    /** @return list<array<string, mixed>> les réglages de règle des vacances listés pour cette règle */
    private function listedRuleSettings(string $ruleId): array
    {
        $this->client->request('GET', '/api/constraint_period_overrides?schedulePlanId=' . $this->holiday, [], [], $this->headers());
        self::assertResponseIsSuccessful();
        $members = json_decode((string) $this->client->getResponse()->getContent(), true)['member'] ?? [];
        self::assertIsArray($members);

        return array_values(array_filter($members, static fn (mixed $m): bool => \is_array($m) && $ruleId === ($m['constraintId'] ?? null)));
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return int le code de réponse
     */
    private function publish(array $body): int
    {
        return $this->publishTo('team_period_overrides', $body);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return int le code de réponse
     */
    private function publishTo(string $route, array $body): int
    {
        $this->client->request('POST', '/api/' . $route, [], [], $this->headers(), json_encode($body, \JSON_THROW_ON_ERROR));
        $code = $this->client->getResponse()->getStatusCode();
        self::assertContains($code, [200, 201], 'la publication réussit : ' . $this->client->getResponse()->getContent());

        return $code;
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return [
            'HTTP_AUTHORIZATION' => 'Bearer ' . self::getContainer()->get(JWTTokenManagerInterface::class)->create($this->user),
            'HTTP_X-Season-Id' => $this->season->getId(),
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/ld+json',
        ];
    }
}
