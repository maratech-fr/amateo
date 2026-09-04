<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Club;
use App\Entity\ClubUser;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\SeasonStatus;
use App\Service\SchedulePlanProvisioner;
use App\Service\SeasonResolver;
use App\Tests\TenantGucTrait;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * NR — P4-172, axe §7.1 *planning lifecycle* (ADR-0002 inv. 4 « une seule planification par
 * fenêtre »), volet CONCURRENCE.
 *
 * POURQUOI ce test existe : la garde d'unicité de fenêtre ({@see PeriodWindowUniquenessGuard})
 * comparait des fenêtres du CLUB entier alors que le seul verrou pris autour d'elle était par
 * ENTRÉE ({@see SchedulePlanProvisioner::lockPlanScope}). Deux écrivains simultanés sur deux
 * entrées DIFFÉRENTES du même club prenaient donc deux verrous DISJOINTS, passaient chacun la
 * garde avant que l'autre commite, et créaient deux plans gouvernant la même semaine — ce que le
 * 409 `window_already_planned` interdit en séquentiel. Le correctif ajoute un verrou consultatif
 * transactionnel au grain club+saison ({@see SchedulePlanProvisioner::lockClubWindows}), pris
 * AVANT le verrou d'entrée dans les trois chemins qui écrivent une fenêtre (POST /schedule_plans,
 * POST d'une entrée-semaine porteuse de plan, PUT de re-datage).
 *
 * SIMULER deux connexions VRAIMENT concurrentes est impraticable sous DAMA (chaque test tourne
 * dans UNE transaction sur UNE connexion, et un `pg_advisory_xact_lock` y est ré-entrant). On
 * prouve donc la SÉRIALISATION de manière déterministe : une SECONDE connexion (session Postgres
 * distincte) tient la clé consultative du club+saison ; le chemin d'écriture, qui prend désormais
 * cette même clé, ne peut plus avancer (il attend — `statement_timeout` transforme l'attente en
 * échec observable) tant que le concurrent la tient. Un verrou par entrée n'aurait rien contendu.
 *
 * PREUVE DE CHUTE (faite en retirant les trois appels `lockClubWindows`) : la seconde connexion
 * tient la clé, mais l'« Adapter » du correctif retiré ne la touche pas → le POST rend 201 (deux
 * écrivains créent chacun leur plan sur la même semaine). Assertions rouges. Correctif remis :
 * l'« Adapter » attend puis échoue sous le concurrent, et repasse quand la clé se libère.
 *
 * L'ordre des verrous (club AVANT entrée, uniforme sur les trois chemins → pas d'ABBA) n'est pas
 * testable par comportement sans concurrence réelle ; il est garanti par lecture du code (les
 * trois sites prennent `lockClubWindows` puis `lockPlanScope`) et par les commentaires de chaque
 * site.
 */
#[Group('phase1')]
#[Group('integration')]
final class PeriodWindowRaceTest extends WebTestCase
{
    use TenantGucTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    /**
     * LE cœur — le grain du verrou est bien club+saison : un concurrent qui tient la clé d'un
     * club+saison sérialise `lockClubWindows` sur CE club+saison (il attend → échoue), mais pas
     * sur une AUTRE saison ni un AUTRE club (il passe sans attendre). Prouve à la fois que le
     * verrou existe, qu'il est pris sur la bonne clé, et qu'il n'est ni trop large ni trop étroit.
     */
    public function testLockClubWindowsSerializesAtTheClubSeasonGrain(): void
    {
        $provisioner = self::getContainer()->get(SchedulePlanProvisioner::class);
        \assert($provisioner instanceof SchedulePlanProvisioner);
        $conn = $this->em->getConnection();

        $club = $this->randomId();
        $season = $this->randomId();
        $otherSeason = $this->randomId();
        $otherClub = $this->randomId();

        $blocker = DriverManager::getConnection($conn->getParams());

        try {
            // Une session Postgres distincte tient la clé du club+saison (verrou de SESSION, tenu
            // jusqu'au unlock explicite — pas un xact lock qui filerait au premier commit).
            $blocker->executeStatement(
                'SELECT pg_advisory_lock(hashtext(:k))',
                ['k' => $this->windowsKey($club, $season)],
            );
            // L'attente devient un échec OBSERVABLE (sinon le test pendrait) : le SELECT bloqué
            // dépasse le budget et Postgres l'annule. Session-level, actif sur les trois sous-cas.
            $conn->executeStatement('SET statement_timeout = \'700ms\'');

            self::assertTrue(
                $this->clubWindowsLockBlocks($provisioner, $conn, $club, $season),
                'même club+saison : le chemin d\'écriture attend le concurrent (sérialisé)',
            );
            self::assertFalse(
                $this->clubWindowsLockBlocks($provisioner, $conn, $club, $otherSeason),
                'autre saison du même club : clé différente, aucun blocage',
            );
            self::assertFalse(
                $this->clubWindowsLockBlocks($provisioner, $conn, $otherClub, $season),
                'autre club, même saison : clé différente, aucun blocage',
            );
        } finally {
            $conn->executeStatement('RESET statement_timeout');
            $blocker->executeStatement(
                'SELECT pg_advisory_unlock(hashtext(:k))',
                ['k' => $this->windowsKey($club, $season)],
            );
            $blocker->close();
        }
    }

    /**
     * Le chemin d'écriture RÉEL (« Adapter » = POST /schedule_plans) est sérialisé par un
     * concurrent qui tient la clé du club+saison : le geste ne peut pas créer son plan tant que
     * le concurrent la tient (il attend → 5xx sous `statement_timeout`), puis il passe (201) dès
     * qu'elle se libère. Sans le verrou club+saison, ce POST rendait 201 malgré le concurrent
     * (deux plans sur la même semaine) — c'est la preuve de chute.
     */
    public function testAdapterGestureIsSerializedByAConcurrentHolderOfTheClubWindowsLock(): void
    {
        [$user, $club, $season] = $this->createClubWithSeason();
        $entry = $this->postPeriodDated($user, 'closure', 'Gymnase indisponible', '2026-10-19', '2026-10-25');

        $conn = $this->em->getConnection();
        $blocker = DriverManager::getConnection($conn->getParams());

        try {
            $blocker->executeStatement(
                'SELECT pg_advisory_lock(hashtext(:k))',
                ['k' => $this->windowsKey($club->getId(), $season->getId())],
            );
            $conn->executeStatement('SET statement_timeout = \'1200ms\'');

            // Concurrent en place : « Adapter » attend la clé puis échoue (jamais 201).
            $status = $this->adaptStatus($user, $entry);
            self::assertNotSame(201, $status, 'un concurrent tient la clé club+saison : « Adapter » ne peut pas créer son plan');
            self::assertGreaterThanOrEqual(500, $status, 'l\'attente contendue se solde par une erreur serveur (statement_timeout), pas un 201');
        } finally {
            $conn->executeStatement('RESET statement_timeout');
            $blocker->executeStatement(
                'SELECT pg_advisory_unlock(hashtext(:k))',
                ['k' => $this->windowsKey($club->getId(), $season->getId())],
            );
            $blocker->close();
        }

        // La clé libérée, le MÊME geste passe (201) : le blocage venait bien du concurrent, et la
        // transaction du POST échoué s'est proprement dénouée (rien de créé, rien de figé).
        self::assertSame(201, $this->adaptStatus($user, $entry), 'clé libérée : « Adapter » crée enfin le plan');
    }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * Appelle `lockClubWindows` dans un savepoint dédié : rend true si l'acquisition a été
     * contendue (bloquée → `statement_timeout` → exception DBAL), false si elle a réussi
     * immédiatement. Le savepoint est rejeté dans les deux cas pour dénouer l'état (aborted après
     * un timeout) sans polluer la transaction DAMA du test.
     */
    private function clubWindowsLockBlocks(SchedulePlanProvisioner $provisioner, Connection $conn, string $clubId, string $seasonId): bool
    {
        $conn->beginTransaction();
        try {
            $provisioner->lockClubWindows($clubId, $seasonId);
            $conn->rollBack();

            return false;
        } catch (DbalException) {
            $conn->rollBack();

            return true;
        }
    }

    private function windowsKey(string $clubId, string $seasonId): string
    {
        return 'period-windows:' . $clubId . ':' . $seasonId;
    }

    /** Un identifiant aléatoire — la clé consultative n'est qu'un texte, aucun besoin de vraie ligne. */
    private function randomId(): string
    {
        return bin2hex(random_bytes(16));
    }

    private function adaptStatus(User $user, string $entryId): int
    {
        $this->client->request('POST', '/api/schedule_plans', [], [], $this->authHeaders($user) + [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['calendarEntryId' => $entryId], \JSON_THROW_ON_ERROR));

        return $this->client->getResponse()->getStatusCode();
    }

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
        self::assertResponseStatusCodeSame(201, (string) $this->client->getResponse()->getContent());

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertIsString($payload['id']);

        return $payload['id'];
    }

    /**
     * @return array{0: User, 1: Club, 2: Season}
     */
    private function createClubWithSeason(): array
    {
        $uid = uniqid('', true);
        $hasher = self::getContainer()->get('security.user_password_hasher');

        $club = new Club;
        $club->setName('Club course-fenêtre');
        $club->setSlug('club-course-' . $uid);
        $club->setTimezone('Europe/Paris');
        $club->setLocale('fr');
        $club->setOnboardingCompleted(true);
        $this->em->persist($club);

        $user = new User;
        $user->setEmail('course' . $uid . '@test.com');
        $user->setFirstName('Co');
        $user->setLastName('Urse');
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
