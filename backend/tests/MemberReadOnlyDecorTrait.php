<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\ClubUser;
use App\Entity\ScheduleSlotTemplate;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\LockLevel;
use App\Service\TenantConnectionContext;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Décor commun des NR « un Membre lit tout, ne modifie rien » (`MemberWriteSweepTest`,
 * `MemberReadsAreSideEffectFreeTest`, `AccessDeclarationSweepTest`) : un club né par l'inscription réelle, semé par l'API
 * gestionnaire, un Membre actif, et les trois témoins d'effet — empreinte des tables du club,
 * messages partis par les transports mémoire, seaux de débit remis à zéro par `reset()` ciblé
 * (jamais FLUSHALL).
 *
 * Le test qui l'utilise déclare `private KernelBrowser $client` et utilise
 * `ChoosesPlanVersionTrait`, `StartsFreshBrowserSession` et `VerifiesRegistration`.
 */
trait MemberReadOnlyDecorTrait
{
    /** Transports mémoire du test : un message qui y passe est un effet de bord. */
    private const array IN_MEMORY_TRANSPORTS = ['mailer_in_memory', 'travel_in_memory', 'placement_in_memory', 'ffbb_in_memory', 'reset_in_memory'];

    /** @var array<string, string> ids réels du décor, par nom de placeholder */
    private array $ids = [];

    private string $clubId = '';

    private string $managerUserId = '';

    private string $managerToken = '';

    private string $memberToken = '';

    private string $memberUserId = '';

    /** Inscription réelle (club né, gestionnaire actif) ; pose `clubId`, `managerUserId`, `managerToken`. */
    private function registerManager(string $prefix): string
    {
        $ip = \sprintf('10.%d.%d.%d', random_int(1, 254), random_int(0, 254), random_int(1, 254));
        $suffix = $prefix . substr(md5(uniqid('', true)), 0, 6);
        $this->client->request('POST', '/api/register', [], [], [
            'CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $ip,
        ], json_encode([
            'email' => $suffix . '@test.fr', 'password' => 'Password123!',
            'firstName' => 'Marc', 'lastName' => 'Gestionnaire', 'ara' => strtoupper($suffix),
            'club_name' => 'Club ' . $prefix, 'consent' => true,
        ], \JSON_THROW_ON_ERROR));
        $token = $this->verifyRegistration($this->client, $suffix . '@test.fr');
        self::assertNotSame('', $token);
        $this->startFreshBrowserSession($this->client);
        $me = $this->api($token, 'GET', '/api/me');
        $this->clubId = (string) $me['club']['id'];
        $this->managerUserId = (string) $me['id'];
        $this->managerToken = $token;
        $this->ids['club'] = $this->clubId;

        return $token;
    }

    /** Un Membre ACTIF du club ; pose `memberToken`, `memberUserId` et l'id `membership`. */
    private function addActiveMember(): void
    {
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $hasher = $container->get(UserPasswordHasherInterface::class);
        \assert($hasher instanceof UserPasswordHasherInterface);

        $user = new User;
        $user->setEmail('membre' . substr(md5(uniqid('', true)), 0, 8) . '@test.fr');
        $user->setFirstName('Paul');
        $user->setLastName('Membre');
        $user->setPasswordHash($hasher->hashPassword($user, 'Password123!'));
        $em->persist($user);
        $membership = new ClubUser;
        $membership->setClubId($this->clubId);
        $membership->setUserId($user->getId());
        $membership->setRole('member');
        $membership->setIsActive(true);
        $em->persist($membership);
        $em->flush();

        $this->memberToken = $container->get(JWTTokenManagerInterface::class)->create($user);
        $this->memberUserId = $user->getId();
        $this->ids['membership'] = $membership->getId();
    }

    /**
     * Pointage (SQL autorisé par la charte) : valider un plan de saison exige une génération moteur ;
     * le décor pointe donc une version du plan SEASON (patron `ChoosesPlanVersionTrait`, comme les
     * tests du module matchs) et y pose une séance — ce qui ouvre les rencontres et donne des ids
     * réels aux retouches de planning. Exige `ids['team']` et `ids['venue']`.
     */
    private function pointValidatedSeasonPlan(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        self::getContainer()->get(TenantConnectionContext::class)->setClubId($this->clubId);
        $season = $em->getRepository(Season::class)->findOneBy(['clubId' => $this->clubId], ['startDate' => 'DESC']);
        self::assertInstanceOf(Season::class, $season);
        $this->ids['season'] = $season->getId();
        $schedule = $this->settleSeasonPlan($season);
        $this->ids['schedule'] = $schedule->getId();
        $this->ids['schedule_plan'] = (string) $schedule->getSchedulePlanId();
        $session = (new ScheduleSlotTemplate)->setClubId($this->clubId)->setSeasonId($season->getId())->setScheduleId($schedule->getId())
            ->setTeamId($this->ids['team'])->setVenueId($this->ids['venue'])->setDayOfWeek(1)
            ->setStartTime(new DateTimeImmutable('18:00'))->setDurationMinutes(90)->setLockLevel(LockLevel::NONE);
        $em->persist($session);
        $em->flush();
        $this->ids['schedule_slot'] = $session->getId();
    }

    /**
     * Le décor des balayages (`MemberWriteSweepTest`, `AccessDeclarationSweepTest`) : un club né par
     * l'inscription, plan de saison pointé, et une ligne réelle par ressource éditable, créée par
     * l'API gestionnaire — une route d'item jouée sur un id bidon tomberait en 404 au provider, AVANT
     * la garde : elle ne prouverait rien. Pose les ids sous les noms des placeholders des tables.
     * Le Membre n'est pas ajouté ici (`addActiveMember`).
     */
    private function seedSweepClub(string $prefix): string
    {
        $token = $this->registerManager($prefix);
        $dummy = '11111111-1111-4111-8111-111111111111';

        $categoryId = $this->api($token, 'GET', '/api/sport_categories')['member'][0]['id'];
        $this->ids['category'] = $categoryId;
        $this->ids['team'] = $this->create($token, '/api/teams', ['name' => 'SW1', 'sportCategoryId' => $categoryId, 'priorityTierId' => 1]);
        $this->ids['team2'] = $this->create($token, '/api/teams', ['name' => 'SW2', 'sportCategoryId' => $categoryId, 'priorityTierId' => 1]);
        $this->ids['venue'] = $this->create($token, '/api/venues', ['name' => 'Gym SW', 'source' => 'manual']);
        $this->ids['slot'] = $this->create($token, '/api/venue_training_slots', ['venueId' => $this->ids['venue'], 'dayOfWeek' => 1, 'startTime' => '18:00', 'durationMinutes' => 90, 'capacity' => 1]);
        $this->ids['coach'] = $this->create($token, '/api/coaches', ['firstName' => 'Jean', 'isEmployee' => true]);
        $this->ids['constraint'] = $this->create($token, '/api/constraints', ['name' => 'SW', 'scope' => 'CLUB', 'family' => 'DAY', 'ruleType' => 'HARD', 'config' => ['forbiddenDays' => [6]], 'isActive' => true, 'sortOrder' => 1]);
        $this->ids['draft_schedule'] = $this->create($token, '/api/schedules', ['name' => 'Planning SW', 'status' => 'DRAFT']);
        $this->pointValidatedSeasonPlan();
        $this->ids['dummy'] = $dummy;
        $this->ids['match_date'] = new DateTimeImmutable('+10 days')->format('Y-m-d');
        $this->ids['fixture'] = $this->create($token, '/api/fixtures', [
            'teamId' => $this->ids['team'], 'matchDate' => $this->ids['match_date'],
            'homeAway' => 'HOME', 'opponentLabel' => 'Amical SW',
        ]);

        $monday = new DateTimeImmutable('monday next week +4 weeks');
        $this->ids['venue2'] = $this->create($token, '/api/venues', ['name' => 'Gym SW 2', 'source' => 'manual']);
        $this->ids['venue3'] = $this->create($token, '/api/venues', ['name' => 'Gym SW 3', 'source' => 'manual']);
        $this->ids['team3'] = $this->create($token, '/api/teams', ['name' => 'SW3', 'sportCategoryId' => $this->ids['category'], 'priorityTierId' => 1]);
        $this->ids['sport'] = (string) $this->api($token, 'GET', '/api/sport_categories/' . $this->ids['category'])['sportId'];
        $this->ids['team_link'] = $this->create($token, '/api/team_links', ['teamAId' => $this->ids['team'], 'teamBId' => $this->ids['team2'], 'linkType' => 'NOT_SIMULTANEOUS']);
        $this->ids['team_match_habit'] = $this->create($token, '/api/team_match_habits', ['teamId' => $this->ids['team'], 'dayOfWeek' => 7, 'kickoffTime' => '17:30']);
        $this->ids['team_coach'] = $this->create($token, '/api/team_coaches', ['teamId' => $this->ids['team'], 'coachId' => $this->ids['coach'], 'role' => 'MAIN']);
        $this->ids['coach_player_membership'] = $this->create($token, '/api/coach_player_memberships', ['coachId' => $this->ids['coach'], 'teamId' => $this->ids['team2'], 'isActive' => true]);
        $this->ids['venue_match_window'] = $this->create($token, '/api/venue_match_windows', ['venueId' => $this->ids['venue'], 'openingDate' => $monday->modify('+5 days')->format('Y-m-d'), 'startTime' => '14:00', 'endTime' => '20:00', 'label' => 'Tournoi SW']);
        $this->ids['venue_travel_time'] = $this->create($token, '/api/venue_travel_times', ['venueAId' => $this->ids['venue'], 'venueBId' => $this->ids['venue2'], 'drivingMinutes' => 15]);
        $this->ids['venue_occupancy'] = $this->create($token, '/api/venue_occupancies', ['venueId' => $this->ids['venue'], 'dayOfWeek' => 2, 'startTime' => '14:00', 'durationMinutes' => 60, 'label' => 'École']);
        $this->ids['reservation'] = $this->create($token, '/api/reservations', ['teamId' => $this->ids['team'], 'venueId' => $this->ids['venue'], 'dayOfWeek' => 2, 'startTime' => '18:00', 'durationMinutes' => 90]);
        $this->ids['shared_training_block'] = $this->create($token, '/api/shared_training_blocks', ['teamIds' => [$this->ids['team'], $this->ids['team2']], 'commonSessions' => 1, 'schedulePlanId' => null, 'label' => 'Groupe SW']);
        $this->ids['club_league_window'] = $this->create($token, '/api/club_league_windows', ['category' => 'Seniors', 'level' => 'REGIONAL', 'gender' => null, 'dayOfWeek' => 6, 'kickoffMin' => '14:00', 'kickoffMax' => '18:00']);
        $this->ids['match_constraint'] = $this->create($token, '/api/match_constraints', ['ruleType' => 'HARD', 'daysOfWeek' => [6], 'kickoffMax' => '21:00']);
        $this->ids['calendar_entry'] = $this->create($token, '/api/calendar_entries', ['kind' => 'event', 'title' => 'Événement SW', 'startDate' => $monday->format('Y-m-d'), 'endDate' => $monday->format('Y-m-d')]);

        // Une période et son plan : les surcharges de période en dépendent.
        $this->ids['period'] = $this->create($token, '/api/calendar_entries', ['kind' => 'period', 'periodType' => 'closure', 'title' => 'Fermeture SW', 'startDate' => $monday->format('Y-m-d'), 'endDate' => $monday->modify('+6 days')->format('Y-m-d')]);
        $this->ids['period_plan'] = $this->create($token, '/api/schedule_plans', ['calendarEntryId' => $this->ids['period']]);
        $this->ids['team_period_override'] = $this->create($token, '/api/team_period_overrides', ['schedulePlanId' => $this->ids['period_plan'], 'teamId' => $this->ids['team']]);
        $this->ids['venue_period_override'] = $this->create($token, '/api/venue_period_overrides', ['schedulePlanId' => $this->ids['period_plan'], 'venueId' => $this->ids['venue'], 'mode' => 'BLANK']);
        $this->ids['constraint_period_override'] = $this->create($token, '/api/constraint_period_overrides', ['schedulePlanId' => $this->ids['period_plan'], 'constraintId' => $this->ids['constraint'], 'isActive' => false]);
        // Les doléances ne concernent que les vacances : une seconde période, de type vacances.
        $this->ids['holiday'] = $this->create($token, '/api/calendar_entries', ['kind' => 'period', 'periodType' => 'holiday', 'title' => 'Vacances SW', 'startDate' => $monday->modify('+14 days')->format('Y-m-d'), 'endDate' => $monday->modify('+20 days')->format('Y-m-d')]);
        $holidayMonday = $monday->modify('+14 days')->format('Y-m-d');
        $this->ids['holiday_monday'] = $holidayMonday;
        $this->ids['campaign_deadline'] = $monday->modify('+7 days')->format('Y-m-d');
        $this->ids['unplanned_period'] = $this->create($token, '/api/calendar_entries', ['kind' => 'period', 'periodType' => 'custom', 'title' => 'Période SW sans plan', 'startDate' => $monday->modify('+28 days')->format('Y-m-d'), 'endDate' => $monday->modify('+30 days')->format('Y-m-d')]);
        $this->ids['coach_wish'] = $this->create($token, '/api/coach_wishes', ['calendarEntryId' => $this->ids['holiday'], 'weekStart' => $holidayMonday, 'teamId' => $this->ids['team'], 'coachId' => $this->ids['coach'], 'slotsWanted' => 1]);
        $this->ids['coach_wish_mutualization'] = $this->create($token, '/api/coach_wish_mutualizations', ['calendarEntryId' => $this->ids['holiday'], 'teamId' => $this->ids['team'], 'coachId' => $this->ids['coach'], 'partnerTeamIds' => [$this->ids['team2']], 'sharedSlots' => 1, 'done' => false]);
        $this->ids['holiday_plan'] = $this->create($token, '/api/schedule_plans', ['calendarEntryId' => $this->ids['holiday']]);
        $this->ids['coach_wish_campaign'] = $this->create($token, '/api/coach_wish_campaigns', ['calendarEntryId' => $this->ids['holiday'], 'deadline' => $monday->modify('+7 days')->format('Y-m-d'), 'weeks' => [$holidayMonday], 'teamIds' => [$this->ids['team']]]);
        $this->ids['rule_key'] = 'coachRestDay';
        $this->ids['season'] ??= $dummy;

        return $token;
    }

    /** @param array<string, mixed> $payload */
    private function create(string $token, string $url, array $payload): string
    {
        $body = $this->api($token, 'POST', $url, $payload);
        self::assertResponseStatusCodeSame(201, 'décor : ' . $url . ' ' . mb_substr((string) $this->client->getResponse()->getContent(), 0, 300));

        return (string) $body['id'];
    }

    /**
     * @param array<string, mixed>|null $payload
     *
     * @return array<string, mixed>
     */
    private function api(string $token, string $method, string $url, ?array $payload = null): array
    {
        if ('' !== $this->managerUserId) {
            // Le décor dépasse la borne `api` de test (30/min) : seau du gestionnaire remis à zéro.
            $this->resetBucket('limiter.api', 'api-user-' . $this->managerUserId);
        }
        $this->startFreshBrowserSession($this->client);
        $this->client->request($method, $url, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'CONTENT_TYPE' => 'application/ld+json',
        ], null === $payload ? null : json_encode($payload, \JSON_THROW_ON_ERROR));
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true);

        return \is_array($decoded) ? $decoded : [];
    }

    /** Une requête du Membre, seau `api` remis à zéro avant (la borne de test est 30/min). */
    private function asMember(string $method, string $url, ?string $body = null): void
    {
        $this->resetBucket('limiter.api', 'api-user-' . $this->memberUserId);
        $this->startFreshBrowserSession($this->client);
        $this->client->request($method, $url, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->memberToken,
            'CONTENT_TYPE' => 'PATCH' === $method && !str_starts_with($url, '/api/club/') && !str_starts_with($url, '/api/me')
                ? 'application/merge-patch+json' : 'application/json',
        ], $body);
    }

    /** Remet à zéro UNE clé d'un limiteur (DEL ciblé — jamais FLUSHALL). */
    private function resetBucket(string $limiterService, string $key): void
    {
        $limiter = self::getContainer()->get($limiterService);
        \assert($limiter instanceof RateLimiterFactory);
        $limiter->create($key)->reset();
    }

    /**
     * Empreinte des tables du club : pour chaque table portant `club_id`, `count(*)` et, si elle a
     * `updated_at`, son max ; plus la ligne `club` elle-même. Lue sous la GUC du club (RLS active).
     *
     * @return array<string, string>
     */
    private function fingerprint(): array
    {
        $container = self::getContainer();
        $container->get(TenantConnectionContext::class)->setClubId($this->clubId);
        $conn = $container->get(EntityManagerInterface::class)->getConnection();
        \assert($conn instanceof Connection);
        $tables = $conn->fetchAllAssociative(
            'SELECT c.table_name, bool_or(u.column_name IS NOT NULL) AS has_updated
               FROM information_schema.columns c
               LEFT JOIN information_schema.columns u
                 ON u.table_schema = c.table_schema AND u.table_name = c.table_name AND u.column_name = \'updated_at\'
              WHERE c.table_schema = \'public\' AND c.column_name = \'club_id\'
              GROUP BY c.table_name ORDER BY c.table_name',
        );
        $print = [];
        foreach ($tables as $t) {
            $name = (string) $t['table_name'];
            $select = $t['has_updated'] ? 'count(*)::text || \'@\' || coalesce(max(updated_at)::text, \'-\')' : 'count(*)::text';
            $print[$name] = (string) $conn->fetchOne(\sprintf('SELECT %s FROM "%s" WHERE club_id = ?', $select, $name), [$this->clubId]);
        }
        $club = json_decode((string) $conn->fetchOne('SELECT row_to_json(c)::text FROM club c WHERE id = ?', [$this->clubId]), true);
        \assert(\is_array($club));
        foreach ($club as $column => $value) {
            if ('last_activity_at' === $column) {
                // Signal d'activité posé par `LoginSuccessListener` à toute authentification d'un
                // membre actif (supervision, sauvegardes) : une trace de connexion, pas une donnée
                // du club qu'un Membre modifierait.
                continue;
            }
            $print['club.' . $column] = json_encode($value, \JSON_THROW_ON_ERROR);
        }

        return $print;
    }

    /**
     * Les messages partis par les transports mémoire PENDANT la dernière requête : le client
     * redémarre le noyau avant chaque requête, les transports de ce conteneur ne voient qu'elle.
     *
     * @return list<string>
     */
    private function messagesSentByLastRequest(): array
    {
        $sent = [];
        foreach (self::IN_MEMORY_TRANSPORTS as $name) {
            $transport = self::getContainer()->get('messenger.transport.' . $name);
            \assert($transport instanceof InMemoryTransport);
            foreach ($transport->getSent() as $envelope) {
                $sent[] = $name . ':' . $envelope->getMessage()::class;
            }
        }

        return $sent;
    }

    private function fill(string $template): string
    {
        return (string) preg_replace_callback('/\{(\w+)\}/', fn (array $m): string => $this->ids[$m[1]] ?? '11111111-1111-4111-8111-111111111111', $template);
    }
}
