<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\CalendarEntry;
use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Schedule;
use App\Entity\Season;
use App\Entity\Team;
use App\Entity\User;
use App\Entity\Venue;
use App\Entity\VenueTrainingSlot;
use App\Enum\CalendarEntryKind;
use App\Enum\CalendarEntryPeriodType;
use App\Enum\ScheduleStatus;
use App\Enum\SeasonStatus;
use App\Service\SchedulePlanProvisioner;
use App\Service\SeasonResolver;
use App\Tests\ChoosesPlanVersionTrait;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * La « Dernière activité » d'un club que lit la console superadmin est une trace VRAIE : posée
 * quand un utilisateur actif du club ouvre l'app (au plus une fois par jour calendaire
 * Europe/Paris et par utilisateur) ou quand une génération est mise en file, à l'horloge RÉELLE ;
 * jamais par l'adresse exclue (`APP_ACTIVITY_EXCLUDED_EMAILS`) qui ne fait que se connecter.
 *
 * NR d'axe §7.1 (generation pipeline : la trace vit DANS la transaction des trois guichets qui
 * lancent un solve) — bloquant (step `Blocking suite` de `ci.yml` + `docs/testing/blocking-tests.md`).
 *
 * Décor : `clearGuc()` avant chaque requête — en prod le listener d'authentification tourne AVANT
 * que le tenant soit posé (firewall, priorité 8 > 7) ; un GUC resté d'un amorçage scoperait
 * `club_user` et cacherait la trace multi-clubs (f).
 * Les assertions de date sont en fenêtre glissante (`>= NOW() - 1 minute`), jamais
 * `::date = CURRENT_DATE` : un run entre minuit Paris et minuit UTC mentirait.
 *
 * Falsification : commenter l'`UPDATE club` de `LoginSuccessListener` → (a)(b)(c1)(f)(e, volet
 * connexion) rougissent ; retirer l'appel `markGeneration` d'un guichet → son cas (k1/k2/k3)
 * rougit ; revenir au throttle « 24 h glissantes » → (c1) rougit ; comparer le jour UTC → (c2)
 * rougit ; marquer avant les gardes → (k0) rougit.
 *
 * L'abus « un JWT club lit `GET /api/admin/clubs` » n'est PAS rejoué ici : il est déjà prouvé au
 * même niveau par `SuperAdminAccessTest` (`/api/admin/clubs` → 401).
 */
#[Group('phase1')]
#[Group('integration')]
final class ClubActivityTraceTest extends WebTestCase
{
    use ChoosesPlanVersionTrait;
    use TenantGucTrait;

    /** Adresses de `.env.test` (`APP_ACTIVITY_EXCLUDED_EMAILS`) : la 1ʳᵉ y est en casse mixte, la 2ᵉ précédée d'une espace. */
    private const string EXCLUDED = 'activity-excluded@example.test';
    private const string SECOND_EXCLUDED = 'second-excluded@example.test';

    /** Minuit Paris d'aujourd'hui, exprimé dans le repère d'écriture de `app_user.last_login_at` (UTC sans fuseau). */
    private const string PARIS_MIDNIGHT_UTC = '((date_trunc(\'day\', NOW() AT TIME ZONE \'Europe/Paris\') AT TIME ZONE \'Europe/Paris\') AT TIME ZONE \'UTC\')';

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    private Club $club;

    private Season $season;

    /** (a) Un gestionnaire qui ouvre l'app marque son club, et sa propre trace RGPD avance. */
    public function testAManagerOpeningTheAppMarksTheClub(): void
    {
        $user = $this->member($this->club, 'admin');

        $this->me($user);

        self::assertTrue($this->clubMarkedNow($this->club->getId()), 'le club du gestionnaire est marqué dans la minute');
        self::assertTrue($this->loginTracedNow($user), 'last_login_at posé');
    }

    /** (b) Un MEMBRE actif (pas seulement un gestionnaire) marque aussi son club. */
    public function testAnActiveMemberOpeningTheAppMarksTheClub(): void
    {
        $user = $this->member($this->club, 'member');

        $this->me($user);

        self::assertTrue($this->clubMarkedNow($this->club->getId()), 'un Membre actif est un utilisateur du club');
    }

    /**
     * (c1) Le throttle compte en JOURS CALENDAIRES Europe/Paris : une trace d'hier 23:59 (Paris) ne
     * dispense pas d'écrire aujourd'hui, même si elle a moins de 24 h. Rouge sur l'ancien throttle
     * « 24 h glissantes ».
     */
    public function testATraceFromYesterdayParisIsRewrittenEvenWithinTwentyFourHours(): void
    {
        $user = $this->member($this->club, 'admin');
        $this->setLastLoginAt($user, self::PARIS_MIDNIGHT_UTC . ' - INTERVAL \'1 minute\'');

        $this->me($user);

        self::assertTrue($this->clubMarkedNow($this->club->getId()), 'hier 23:59 Paris ≠ aujourd\'hui : la trace du jour est posée');
        self::assertTrue($this->loginTracedNow($user), 'last_login_at réécrit');
    }

    /**
     * (c2) Une trace posée à minuit Paris AUJOURD'HUI dispense d'écrire : même jour Paris, zéro
     * écriture. Rouge sur un throttle au jour UTC (minuit Paris = la veille en UTC).
     */
    public function testATraceFromParisMidnightTodayIsKept(): void
    {
        $user = $this->member($this->club, 'admin');
        $this->setLastLoginAt($user, self::PARIS_MIDNIGHT_UTC);

        $this->me($user);

        self::assertNull($this->clubActivity($this->club->getId()), 'même jour Paris : le club n\'est pas réécrit');
        self::assertFalse($this->loginTracedNow($user), 'last_login_at inchangé (minuit Paris)');
    }

    /** (d) Une adhésion inactive (en attente, désactivée) ne fait pas d'un compte un utilisateur du club. */
    public function testAnInactiveMembershipDoesNotMarkTheClub(): void
    {
        $user = $this->member($this->club, 'member', active: false);

        $this->me($user);

        self::assertNull($this->clubActivity($this->club->getId()), 'adhésion inactive : club non marqué');
    }

    /**
     * (e) Club DÉMO daté 2020 : ouvrir l'app comme lancer une génération marque à la date RÉELLE,
     * jamais à la date simulée (la console mesure l'activité réelle, pas le décor de démo).
     */
    public function testADemoClubWithASimulatedClockIsMarkedAtTheRealDate(): void
    {
        $demo = $this->bootClub(demo: true);
        $user = $this->member($demo, 'admin');

        $this->me($user);
        self::assertTrue($this->clubMarkedNow($demo->getId()), 'connexion : date réelle, pas 2020');

        // Volet génération : la trace de connexion du jour est posée (le listener se tait), le club est remis à zéro.
        $this->resetClubActivity($demo->getId());
        $this->post($user, '/api/schedules/' . $this->seasonVersion($demo, ScheduleStatus::DRAFT)->getId() . '/generate');

        self::assertResponseStatusCodeSame(202, (string) $this->client->getResponse()->getContent());
        self::assertTrue($this->clubMarkedNow($demo->getId()), 'génération : date réelle, pas la date simulée 2020 (valeur lue : ' . var_export($this->clubActivity($demo->getId()), true) . ')');
    }

    /** (f) Un utilisateur de deux clubs marque ses deux clubs (un throttle par utilisateur, pas par club). */
    public function testAUserOfTwoClubsMarksBoth(): void
    {
        $other = $this->bootClub(demo: false);
        $user = $this->member($this->club, 'admin');
        $this->join($user, $other, 'member');

        $this->me($user);

        self::assertTrue($this->clubMarkedNow($this->club->getId()), 'premier club marqué');
        self::assertTrue($this->clubMarkedNow($other->getId()), 'second club marqué aussi');
    }

    /** (h) L'adresse exclue (casse mixte dans la config) ouvre l'app : le club ne bouge pas, sa trace RGPD avance. */
    public function testTheExcludedAddressDoesNotMarkTheClubButStillTracesItsLogin(): void
    {
        $user = $this->member($this->club, 'member', email: self::EXCLUDED);

        $this->me($user);

        self::assertNull($this->clubActivity($this->club->getId()), 'adresse exclue : club inchangé');
        self::assertTrue($this->loginTracedNow($user), 'last_login_at avance toujours (rétention RGPD)');
    }

    /** (i) La seconde adresse de la liste, précédée d'une espace dans la config, est exclue aussi (trim). */
    public function testTheSecondExcludedAddressIsTrimmed(): void
    {
        $user = $this->member($this->club, 'member', email: self::SECOND_EXCLUDED);

        $this->me($user);

        self::assertNull($this->clubActivity($this->club->getId()), 'la liste est normalisée (espaces retirés)');
        self::assertTrue($this->loginTracedNow($user), 'last_login_at avance');
    }

    /** (k1) Une génération lancée par l'adresse exclue COMPTE : « Générer » marque le club. */
    public function testAGenerateByTheExcludedAddressMarksTheClub(): void
    {
        $user = $this->excludedManagerAlreadyTracedToday();

        $this->post($user, '/api/schedules/' . $this->seasonVersion($this->club, ScheduleStatus::DRAFT)->getId() . '/generate');

        self::assertResponseStatusCodeSame(202, (string) $this->client->getResponse()->getContent());
        self::assertTrue($this->clubMarkedNow($this->club->getId()), '« Générer » marque le club, compte exclu compris');
    }

    /** (k2) « Régénérer » (V+1 d'une version de saison terminée) marque le club. */
    public function testARegenerateByTheExcludedAddressMarksTheClub(): void
    {
        $user = $this->excludedManagerAlreadyTracedToday();

        $this->post($user, '/api/schedules/' . $this->seasonVersion($this->club, ScheduleStatus::COMPLETED)->getId() . '/regenerate');

        self::assertResponseStatusCodeSame(202, (string) $this->client->getResponse()->getContent());
        self::assertTrue($this->clubMarkedNow($this->club->getId()), '« Régénérer » marque le club');
    }

    /** (k3) « Combler » la version chargée d'un plan de période marque le club. */
    public function testAFillByTheExcludedAddressMarksTheClub(): void
    {
        $user = $this->excludedManagerAlreadyTracedToday();
        $this->choosePlanVersion((new Schedule)->setClubId($this->club->getId())->setSeasonId($this->season->getId())->setName('Socle')->setStatus(ScheduleStatus::COMPLETED));
        $source = $this->periodVersion($this->period());
        $this->resetClubActivity($this->club->getId());

        $this->post($user, '/api/schedules/' . $source->getId() . '/fill');

        self::assertResponseStatusCodeSame(202, (string) $this->client->getResponse()->getContent());
        self::assertTrue($this->clubMarkedNow($this->club->getId()), '« Combler » marque le club');
    }

    /** (k0) Une génération REFUSÉE (Régénérer un brouillon → 409) ne marque jamais : la trace vit après les gardes. */
    public function testARefusedGenerationDoesNotMarkTheClub(): void
    {
        $user = $this->excludedManagerAlreadyTracedToday();

        $this->post($user, '/api/schedules/' . $this->seasonVersion($this->club, ScheduleStatus::DRAFT)->getId() . '/regenerate');

        self::assertResponseStatusCodeSame(409);
        self::assertNull($this->clubActivity($this->club->getId()), 'refusé : club inchangé');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->club = $this->bootClub(demo: false);
        $season = $this->em->getRepository(Season::class)->findOneBy(['clubId' => $this->club->getId()]);
        self::assertInstanceOf(Season::class, $season);
        $this->season = $season;
    }

    /**
     * Club jetable (rollback DAMA) : saison active + plan SEASON, une équipe, un gymnase, un créneau —
     * de quoi passer les gardes d'un guichet de génération. Démo = horloge simulée 2020-01-15.
     */
    private function bootClub(bool $demo): Club
    {
        $uid = uniqid('', true);
        // CHECK `simulated_today IS NULL OR is_demo` : is_demo posé dans le même INSERT.
        $club = (new Club)->setName('Trace ' . $uid)->setSlug('trace-' . $uid)
            ->setTimezone('Europe/Paris')->setLocale('fr')->setOnboardingCompleted(true);
        if ($demo) {
            $club->setIsDemo(true)->setSimulatedToday(new DateTimeImmutable('2020-01-15'));
        }
        $this->em->persist($club);
        $this->em->flush();

        $this->scopeGucToClub($club->getId());
        $year = SeasonResolver::seasonYear(new DateTimeImmutable('today'));
        $season = (new Season)->setClubId($club->getId())->setName((string) $year)
            ->setStartDate(new DateTimeImmutable($year . '-08-01'))->setEndDate(new DateTimeImmutable(($year + 1) . '-07-15'))
            ->setStatus(SeasonStatus::ACTIVE);
        $season->setTransitionData([]);
        $this->em->persist($season);
        $this->em->flush();
        $this->provisionSeasonPlan($season);

        $this->em->persist((new Team)->setClubId($club->getId())->setSeasonId($season->getId())->setName('U15')
            ->setSportCategoryId('cccccccc-cccc-4ccc-8ccc-cccccccccccc')->setPriorityTierId(1)->setSessionsPerWeek(1));
        $venue = (new Venue)->setClubId($club->getId())->setSeasonId($season->getId())->setName('G')->setCanSplit(false)->setSource('manual');
        $this->em->persist($venue);
        $this->em->flush();
        $this->em->persist((new VenueTrainingSlot)->setClubId($club->getId())->setSeasonId($season->getId())->setVenueId($venue->getId())
            ->setDayOfWeek(2)->setStartTime(new DateTimeImmutable('19:00'))->setDurationMinutes(90)->setCapacity(1));
        $this->em->flush();

        return $club;
    }

    private function member(Club $club, string $role, bool $active = true, ?string $email = null): User
    {
        $hasher = self::getContainer()->get('security.user_password_hasher');
        $user = (new User)->setEmail($email ?? 'trace' . uniqid('', true) . '@test.com')->setFirstName('T')->setLastName('R');
        $user->setPasswordHash($hasher->hashPassword($user, 'Password123!'));
        $this->em->persist($user);
        $this->em->flush();
        $this->join($user, $club, $role, $active);

        return $user;
    }

    private function join(User $user, Club $club, string $role, bool $active = true): void
    {
        $this->scopeGucToClub($club->getId());
        $this->em->persist((new ClubUser)->setClubId($club->getId())->setUserId($user->getId())->setRole($role)->setIsActive($active));
        $this->em->flush();
    }

    /** Gestionnaire à l'adresse exclue, dont la trace de connexion du jour est déjà posée : seul un guichet de génération peut marquer le club. */
    private function excludedManagerAlreadyTracedToday(): User
    {
        $user = $this->member($this->club, 'admin', email: self::EXCLUDED);
        $this->setLastLoginAt($user, 'NOW() AT TIME ZONE \'UTC\'');

        return $user;
    }

    private function seasonVersion(Club $club, ScheduleStatus $status): Schedule
    {
        $this->scopeGucToClub($club->getId());
        $season = $this->em->getRepository(Season::class)->findOneBy(['clubId' => $club->getId()]);
        self::assertInstanceOf(Season::class, $season);
        $schedule = (new Schedule)->setClubId($club->getId())->setSeasonId($season->getId())->setName('V')->setStatus($status);
        $this->linkSeededSchedule($schedule);
        $this->em->flush();

        return $schedule;
    }

    private function period(): CalendarEntry
    {
        $this->scopeGucToClub($this->club->getId());
        $entry = (new CalendarEntry)->setClubId($this->club->getId())->setSeasonId($this->season->getId())
            ->setKind(CalendarEntryKind::PERIOD)->setPeriodType(CalendarEntryPeriodType::CLOSURE)
            ->setTitle('Fermeture')->setStartDate(new DateTimeImmutable('+30 days'))->setEndDate(new DateTimeImmutable('+40 days'));
        $this->em->persist($entry);
        $this->em->flush();
        self::getContainer()->get(SchedulePlanProvisioner::class)->provisionPeriodPlan($entry->getId());

        return $entry;
    }

    private function periodVersion(CalendarEntry $entry): Schedule
    {
        $schedule = (new Schedule)->setClubId($this->club->getId())->setSeasonId($this->season->getId())->setName('P')->setStatus(ScheduleStatus::COMPLETED);
        $this->linkSeededSchedule($schedule, $entry->getId());
        $this->em->flush();

        return $schedule;
    }

    private function me(User $user): void
    {
        $this->request($user, 'GET', '/api/me');
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
    }

    private function post(User $user, string $uri): void
    {
        $this->request($user, 'POST', $uri);
    }

    /** Requête comme en prod : aucun tenant posé avant le firewall, entités relues depuis la base. */
    private function request(User $user, string $method, string $uri): void
    {
        $token = self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
        $this->em->clear();
        $this->clearGuc();
        $this->client->request($method, $uri, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], '{}');
    }

    /** @param string $sql expression SQL d'un instant UTC sans fuseau (repère de `last_login_at`) */
    private function setLastLoginAt(User $user, string $sql): void
    {
        $this->connection()->executeStatement('UPDATE app_user SET last_login_at = ' . $sql . ' WHERE id = :id', ['id' => $user->getId()]);
    }

    private function resetClubActivity(string $clubId): void
    {
        $this->connection()->executeStatement('UPDATE club SET last_activity_at = NULL WHERE id = :id', ['id' => $clubId]);
    }

    private function clubActivity(string $clubId): ?string
    {
        $value = $this->connection()->fetchOne('SELECT last_activity_at FROM club WHERE id = :id', ['id' => $clubId]);

        return \is_string($value) ? $value : null;
    }

    private function clubMarkedNow(string $clubId): bool
    {
        return 1 === (int) $this->connection()->fetchOne(
            'SELECT COUNT(*) FROM club WHERE id = :id AND last_activity_at >= NOW() - INTERVAL \'1 minute\'',
            ['id' => $clubId],
        );
    }

    private function loginTracedNow(User $user): bool
    {
        return 1 === (int) $this->connection()->fetchOne(
            'SELECT COUNT(*) FROM app_user WHERE id = :id AND last_login_at >= (NOW() AT TIME ZONE \'UTC\') - INTERVAL \'1 minute\'',
            ['id' => $user->getId()],
        );
    }

    private function connection(): Connection
    {
        return $this->em->getConnection();
    }
}
