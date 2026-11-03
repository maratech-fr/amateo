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
 * `MemberReadsAreSideEffectFreeTest`) : un club né par l'inscription réelle, semé par l'API
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
